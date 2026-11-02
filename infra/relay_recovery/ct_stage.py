#!/usr/bin/env python3
"""Private CT113 staging and reviewed endpoint CAS; no cloud lifecycle calls.

This root-only helper is invoked by a fixed PVE wrapper after ``pct push`` of a
0600 nonce job. It never reads commands, file paths or service names from JSON
network context. The fixed stage service opens ONLY 127.0.0.1:10829; it never
changes tun119, table119 or guest/default routes. Host identity is pinned before
SSH starts, using a canonical public key authenticated by the controller job.

Local config schema servernet.relay.ct-stage.config.v1: agent_id, state_dir,
job_dir, ssh_key_file, ssh_port (default22), unit_dir (default/etc/systemd/system),
restored_host_key_sha256 (reviewed SOURCE pin), optional registry_file,
registry_sha256, old_fence_file, rollback_enabled:false. All paths are private
root-owned files/directories with non-writable root-owned ancestors.

Registry schema servernet.relay.ct-registry.v1: old_identity,old_ipv4,
old_config_hash,source_host_key_sha256,allowed_units:[...],files:[...]. An entry
has path,sha256,format=json|text_tokens,unit; json adds pointers (arrays of exact
dict keys/list indexes), text_tokens adds token_count. Only exact IPv4 tokens or
exact JSON string values equal to old_ipv4 are changed. Empty/unknown registries
are blocked. Each old file is backed up and fsynced before the first replacement.
Every path is CAS checked again immediately before its atomic replacement.

An apply also needs a fresh root0600 fence receipt selected by LOCAL config:
schema servernet.relay.ct-fence.v1,verified,received_at,old_identity/old_ipv4/
old_config_hash,target,restore_epoch/hash,source_fenced:true,provider_off:true.
The authenticated job must be a restored profile and pin expected_registry_hash.
Apply restarts only registry-approved units and verifies the actual global SSH
SOCKS127.0.0.1:10819 path. Missing proofs never produce an applied success.

Each mutation is journaled before any effect. Unknown outcomes do not retry.
Reconcile reads the original immutable job, even after its TTL, and performs
zero writes/restarts. It checks files, the actual socket/process and real egress;
file bytes alone do not prove loaded network configuration. A known partial CAS
is acknowledged as failed and still needs separately authorized repair; unknown
hashes or in-flight jobs remain unresolved. Rollback is disabled by default;
if explicitly enabled, it restores ONLY recorded backups after exact current CAS.
It does not unfence or modify either relay server.
"""
from __future__ import annotations

import argparse
import base64
from concurrent.futures import ThreadPoolExecutor
import contextlib
import fcntl
import hashlib
import hmac
import ipaddress
import json
import os
from pathlib import Path
import re
import shlex
import sqlite3
import stat
import struct
import sys
import time

try:
    from . import agent
    from .protocol import (ProtocolError, HEX64, IDENTIFIER, NONCE, SCHEMA,
                           canonical_json, decode_json, private_file)
except ImportError:
    import agent
    from protocol import (ProtocolError, HEX64, IDENTIFIER, NONCE, SCHEMA,
                          canonical_json, decode_json, private_file)

CONFIG_SCHEMA='servernet.relay.ct-stage.config.v1'
JOB_SCHEMA='servernet.relay.ct-job.v1'
REGISTRY_SCHEMA='servernet.relay.ct-registry.v1'
FENCE_SCHEMA='servernet.relay.ct-fence.v1'
RECIPE_SCHEMA='servernet.relay.iran-recipe.v1'
SCOPE=('incident_id','generation','revision','config_hash')
SERVICE='sn-relay-candidate-probe.service'
STAGE_SOCKS='127.0.0.1:10829'
GLOBAL_SOCKS='127.0.0.1:10819'
MAX_FILE=1024*1024
ACTIONS=frozenset({'stage','apply','rollback','accept','reconcile'})
MUTATIONS=frozenset({'stage','apply','rollback'})
UNIT=re.compile(r'\A[a-zA-Z0-9][a-zA-Z0-9_.@:-]{0,119}\.service\Z')
PID=re.compile(r'\bpid=([1-9][0-9]{0,9})\b')
PARAMS=frozenset({'stage_profile','ssh_host_public_key','ssh_host_key_sha256','restore_epoch','restore_hash',
    'old_identity','old_ipv4','old_config_hash','expected_registry_hash','resource_identity','resource_config_hash','critical_consumers'})


def _hash(value):
    if not isinstance(value,str) or not HEX64.fullmatch(value):raise ProtocolError('stage_hash')
    return value


def _int(value,lo=1,hi=2**53-1):
    if type(value)is not int or not lo<=value<=hi:raise ProtocolError('stage_integer')
    return value


def _ip(value):
    try:address=ipaddress.ip_address(value)
    except (TypeError,ValueError):raise ProtocolError('stage_ipv4') from None
    if not isinstance(value,str) or address.version!=4 or not address.is_global or str(address)!=value:
        raise ProtocolError('stage_ipv4')
    return value


def _identity(value):
    if not isinstance(value,dict) or set(value)!={'provider','project_id','provider_id','generation'}:
        raise ProtocolError('stage_identity')
    if (value.get('provider')!='hetzner' or not isinstance(value.get('project_id'),str)
            or not IDENTIFIER.fullmatch(value['project_id'])):raise ProtocolError('stage_identity')
    ref=value.get('provider_id')
    if not isinstance(ref,str) or not ref.isascii() or not ref.isdecimal() or not 1<=len(ref)<=20 or int(ref)<1:
        raise ProtocolError('stage_identity')
    _int(value.get('generation'));return value


def public_key(value):
    if not isinstance(value,str) or len(value)>256:raise ProtocolError('stage_host_key')
    parts=value.split()
    if len(parts)!=2 or parts[0]!='ssh-ed25519':raise ProtocolError('stage_host_key')
    try:blob=base64.b64decode(parts[1],validate=True)
    except (ValueError,base64.binascii.Error):raise ProtocolError('stage_host_key') from None
    if (len(blob)!=51 or struct.unpack('!I',blob[:4])[0]!=11 or blob[4:15]!=b'ssh-ed25519'
            or struct.unpack('!I',blob[15:19])[0]!=32 or base64.b64encode(blob).decode()!=parts[1]):
        raise ProtocolError('stage_host_key')
    canonical='ssh-ed25519 '+parts[1]
    if value!=canonical:raise ProtocolError('stage_host_key')
    return canonical


def fingerprint(value):
    blob=base64.b64decode(public_key(value).split()[1],validate=True)
    return 'SHA256:'+base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip('=')


def validate_context(context,agent_id='pve-ir'):
    if (not isinstance(context,dict) or len(canonical_json(context))>16384
            or set(context)-{'schema','agent_id','incident_id','generation','revision','config_hash','jobs_hash','job'}
            or context.get('schema')!=SCHEMA or context.get('agent_id')!=agent_id
            or not isinstance(context.get('incident_id'),str) or not IDENTIFIER.fullmatch(context['incident_id'])):
        raise ProtocolError('stage_context')
    for name in ('generation','revision'):_int(context.get(name))
    for name in ('config_hash','jobs_hash'):_hash(context.get(name))
    job=context.get('job')
    if (not isinstance(job,dict) or set(job)-{'id','op','target','params'} or job.get('op')not in MUTATIONS|{'accept'}
            or not isinstance(job.get('id'),str) or not IDENTIFIER.fullmatch(job['id'])):raise ProtocolError('stage_job')
    target=job.get('target')
    if not isinstance(target,dict) or set(target)!={'host','port','provider_id'}:raise ProtocolError('stage_target')
    _ip(target['host']);_int(target['port'],1,65535)
    if target['port']not in (22,443):raise ProtocolError('stage_target')
    ref=target['provider_id']
    if not isinstance(ref,str) or not ref.isascii() or not ref.isdecimal() or not 1<=len(ref)<=20 or int(ref)<1:
        raise ProtocolError('stage_target')
    params=job.get('params')
    if not isinstance(params,dict) or set(params)-PARAMS:raise ProtocolError('stage_params')
    profile=params.get('stage_profile')
    if profile not in ('trial','restored'):raise ProtocolError('stage_profile')
    key=public_key(params.get('ssh_host_public_key'))
    pin=params.get('ssh_host_key_sha256')
    if not isinstance(pin,str)or not re.fullmatch(r'SHA256:[A-Za-z0-9+/]{43}',pin)or not hmac.compare_digest(fingerprint(key),pin):
        raise ProtocolError('stage_host_fingerprint')
    if profile=='restored':_int(params.get('restore_epoch'));_hash(params.get('restore_hash'))
    elif 'restore_epoch'in params or 'restore_hash'in params:raise ProtocolError('stage_trial_epoch')
    if 'resource_identity'in params:
        _identity(params['resource_identity'])
        if params['resource_identity']['provider_id']!=ref:raise ProtocolError('stage_resource_target')
    if 'resource_config_hash'in params:_hash(params['resource_config_hash'])
    if 'critical_consumers'in params:
        names=params['critical_consumers']
        if (not isinstance(names,list) or not 1<=len(names)<=16
                or any(not isinstance(n,str)or not IDENTIFIER.fullmatch(n)for n in names)
                or len(set(names))!=len(names)):raise ProtocolError('stage_consumers')
    if job['op']in ('apply','rollback'):
        if profile!='restored':raise ProtocolError('stage_apply_profile')
        _identity(params.get('old_identity'));_ip(params.get('old_ipv4'));_hash(params.get('old_config_hash'))
        _hash(params.get('expected_registry_hash'))
        if params['old_identity']['provider_id']==ref or params['old_ipv4']==target['host']:
            raise ProtocolError('stage_old_equals_new')
    return context


def context_key(context):return hashlib.sha256(canonical_json(context)).hexdigest()


def _directory(value,root_owned=True):
    if not isinstance(value,(str,Path))or not Path(value).is_absolute()or '..'in Path(value).parts:
        raise ProtocolError('stage_directory')
    path=Path(value)
    for item in (path,*path.parents):
        info=item.lstat()
        if not stat.S_ISDIR(info.st_mode)or item.is_symlink():raise ProtocolError('stage_directory')
        if root_owned and (info.st_uid!=0 or info.st_mode&0o022):raise ProtocolError('stage_directory_permissions')
    if path.stat().st_mode&0o077:raise ProtocolError('stage_private_directory')
    return path


def _trusted_file(path,root_owned=True,private=False):
    path=Path(path)
    if not path.is_absolute()or '..'in path.parts or any(ord(c)<32 for c in str(path)):raise ProtocolError('stage_file_path')
    info=path.lstat()
    if not stat.S_ISREG(info.st_mode)or path.is_symlink()or info.st_mode&(0o077 if private else 0o022):
        raise ProtocolError('stage_file_permissions')
    if root_owned and info.st_uid!=0:raise ProtocolError('stage_file_owner')
    for parent in path.parents:
        info=parent.lstat()
        if parent.is_symlink()or not stat.S_ISDIR(info.st_mode):raise ProtocolError('stage_parent')
        if root_owned and(info.st_uid!=0 or info.st_mode&0o022):raise ProtocolError('stage_parent_permissions')
    return path


def _read(path,root_owned=True,limit=MAX_FILE,private=True):
    path=_trusted_file(path,root_owned,private)
    if path.stat().st_size>limit:raise ProtocolError('stage_file_size')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    with os.fdopen(fd,'rb')as stream:data=stream.read(limit+1)
    if len(data)>limit:raise ProtocolError('stage_file_size')
    return data


def load_config(path,root_owned=True):
    cfg=decode_json(_read(path,root_owned,65536))
    if (cfg.get('schema')!=CONFIG_SCHEMA or not isinstance(cfg.get('agent_id'),str)
            or not IDENTIFIER.fullmatch(cfg['agent_id'])):raise ProtocolError('stage_config')
    for key in ('state_dir','job_dir'):cfg[key]=str(_directory(cfg.get(key),root_owned))
    unit_dir=Path(cfg.get('unit_dir','/etc/systemd/system'))
    # Unit directories may be 0755; require trusted ancestors, not secrecy.
    for item in (unit_dir,*unit_dir.parents):
        info=item.lstat()
        if (not item.is_absolute()or item.is_symlink()or not stat.S_ISDIR(info.st_mode)
                or (root_owned and(info.st_uid!=0 or info.st_mode&0o022))):raise ProtocolError('stage_unit_directory')
    cfg['unit_dir']=str(unit_dir)
    cfg['ssh_key_file']=str(private_file(cfg.get('ssh_key_file','/root/de_relay_key'),root_owned=root_owned))
    cfg['ssh_port']=_int(cfg.get('ssh_port',22),1,65535)
    if cfg.get('rollback_enabled',False)not in (True,False):raise ProtocolError('stage_config')
    cfg['root_owned']=root_owned
    return cfg


def load_job(config,path,sha,nonce,action,now):
    if action not in ACTIONS or not isinstance(nonce,str)or not NONCE.fullmatch(nonce):raise ProtocolError('stage_action')
    expected=Path(config['job_dir'])/(nonce+'.json')
    if Path(path)!=expected:raise ProtocolError('stage_job_path')
    body=_read(path,config.get('root_owned',True),32768)
    if not hmac.compare_digest(hashlib.sha256(body).hexdigest(),_hash(sha)):raise ProtocolError('stage_job_hash')
    job=decode_json(body)
    if (set(job)!={'schema','nonce','created_at','expires_at','action','context'}or job.get('schema')!=JOB_SCHEMA
            or job.get('nonce')!=nonce or job.get('action')not in MUTATIONS|{'accept'}):raise ProtocolError('stage_envelope')
    created=_int(job.get('created_at'),0);expires=_int(job.get('expires_at'),0)
    if not created<expires<=created+30:raise ProtocolError('stage_envelope_time')
    if action!='reconcile'and not created<=now<expires:raise ProtocolError('stage_envelope_expired')
    if action!='reconcile'and action!=job['action']:raise ProtocolError('stage_action_mismatch')
    context=validate_context(job['context'],config['agent_id'])
    if context['job']['op']!=job['action']:raise ProtocolError('stage_action_mismatch')
    if action=='reconcile'and job['action']not in MUTATIONS:raise ProtocolError('stage_reconcile_original')
    config['_job_expires_at']=expires
    return context


def _atomic(path,data,mode=0o600,uid=None,gid=None):
    path=Path(path);tmp=path.with_name(path.name+'.tmp-'+str(os.getpid()))
    fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,mode)
    try:
        if uid is not None or gid is not None:os.fchown(fd,-1 if uid is None else uid,-1 if gid is None else gid)
        with os.fdopen(fd,'wb')as out:out.write(data);out.flush();os.fsync(out.fileno())
        os.replace(tmp,path)
        directory=os.open(path.parent,os.O_DIRECTORY)
        try:os.fsync(directory)
        finally:os.close(directory)
    finally:
        if tmp.exists():tmp.unlink()


def _mkdir(path):
    path=Path(path)
    if path.exists():return
    _mkdir(path.parent);path.mkdir(mode=0o700)
    fd=os.open(path.parent,os.O_DIRECTORY)
    try:os.fsync(fd)
    finally:os.close(fd)


class CtStage:
    def __init__(self,config,*,runner=agent._bounded_process,clock=time.time,monotonic=time.monotonic):
        self.config=config;self.root_owned=config.get('root_owned',True)
        self.root=_directory(config['state_dir'],self.root_owned);self.clock=clock;self.monotonic=monotonic
        self.runner=runner;self.path=self.root/'stage.sqlite3';self.lock=self.root/'stage.lock';self.deadline=None

    @contextlib.contextmanager
    def locked(self,read_only=False):
        if read_only:
            # Reconciliation must not create even a lock or empty database.
            private_file(self.lock,root_owned=self.root_owned)
            fd=os.open(self.lock,os.O_RDONLY|os.O_NOFOLLOW)
        else:
            if self.lock.exists()or self.lock.is_symlink():private_file(self.lock,root_owned=self.root_owned)
            fd=os.open(self.lock,os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
        db=None
        try:
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            if self.path.exists()or self.path.is_symlink():private_file(self.path,root_owned=self.root_owned)
            if read_only:
                db=sqlite3.connect(self.path.as_uri()+'?mode=ro',uri=True,timeout=0)
            else:
                mask=os.umask(0o077)
                try:db=sqlite3.connect(self.path,timeout=0,isolation_level=None)
                finally:os.umask(mask)
                db.execute('PRAGMA synchronous=FULL')
                db.execute('CREATE TABLE IF NOT EXISTS operations(id TEXT PRIMARY KEY,action TEXT NOT NULL,context TEXT NOT NULL,phase TEXT NOT NULL,plan TEXT NOT NULL,result TEXT)')
                os.chmod(self.path,0o600)
            db.row_factory=sqlite3.Row;yield db
        finally:
            if db is not None:db.close()
            os.close(fd)

    def _command(self,argv,timeout):
        if self.deadline is not None:
            remaining=self.deadline-self.monotonic()
            if remaining<=0:raise ProtocolError('stage_deadline')
            timeout=min(timeout,remaining)
        if argv[:2]==['/usr/bin/systemctl','restart']or argv[:2]==['/usr/bin/systemctl','daemon-reload']:
            self._write_guard()
        code,raw=self.runner(argv,b'',timeout)
        if type(code)is not int or not isinstance(raw,bytes)or len(raw)>8192:raise ProtocolError('stage_command_output')
        return code,raw

    def _run(self,argv,timeout=1):
        code,raw=self._command(argv,timeout)
        if code!=0:raise ProtocolError('stage_command_failed')
        return raw

    def _write_guard(self):
        expiry=self.config.get('_job_expires_at')
        if type(expiry)is not int or int(self.clock())>=expiry:raise ProtocolError('stage_authorization_expired')
        if self.deadline is not None and self.monotonic()>=self.deadline:raise ProtocolError('stage_deadline')

    def _pin(self,context):
        p=context['job']['params'];profile=p['stage_profile'];epoch=p.get('restore_epoch',0)
        if profile=='restored'and p['ssh_host_key_sha256']!=self.config.get('restored_host_key_sha256'):
            raise ProtocolError('stage_restored_source_pin')
        directory=self.root/'pins'
        return directory/(context['job']['target']['provider_id']+'-'+profile+'-'+str(epoch)+'.known_hosts')

    def _known(self,context):
        host=context['job']['target']['host'];port=self.config['ssh_port']
        address=host if port==22 else '['+host+']:'+str(port)
        return (address+' '+context['job']['params']['ssh_host_public_key']+'\n').encode()

    def _unit(self,context):
        argv=['/usr/bin/ssh','-F','/dev/null','-N','-D',STAGE_SOCKS,'-p',str(self.config['ssh_port']),
            '-i',self.config['ssh_key_file'],'-o','IdentitiesOnly=yes','-o','BatchMode=yes',
            '-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile='+str(self._pin(context)),
            '-o','GlobalKnownHostsFile=/dev/null','-o','ConnectTimeout=3','-o','ExitOnForwardFailure=yes',
            '-o','UpdateHostKeys=no','-o','VerifyHostKeyDNS=no','-o','HostKeyAlgorithms=ssh-ed25519',
            '-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2','root@'+context['job']['target']['host']]
        # Each token is generated from validated IP/port or trusted local paths;
        # systemd quoting is separate from shell quoting (no shell is executed).
        escaped=['"'+a.replace('\\','\\\\').replace('"','\\"').replace('%','%%')+'"'for a in argv]
        return ('[Unit]\nDescription=ServerNet isolated candidate probe\nAfter=network-online.target\n'
                '[Service]\nType=simple\nExecStart='+' '.join(escaped)+'\nRestart=no\n'
                'KillMode=control-group\nTimeoutStopSec=3\n[Install]\nWantedBy=multi-user.target\n').encode()

    def _network(self,target,socks,service,pin,key):
        with ThreadPoolExecutor(max_workers=2)as pool:
            socket=pool.submit(self._run,['/usr/bin/ss','-H','-ltnp','( sport = :'+socks.rsplit(':',1)[1]+' )'],.5)
            main=pool.submit(self._run,['/usr/bin/systemctl','show',service,'--property=MainPID','--value'],.5)
            raw=socket.result();main=main.result().decode('ascii','strict').strip()
        found=[]
        for line in raw.decode('utf-8','strict').splitlines():
            cols=line.split();match=PID.search(line)
            if len(cols)>=6 and cols[0]=='LISTEN'and cols[3]==socks and '("ssh",'in line and match:found.append(match[1])
        if len(found)!=1:return False
        if main!=found[0]:return False
        args=shlex.split(self._run(['/usr/bin/ps','-p',found[0],'-o','args='],.5).decode('utf-8','strict'))
        if not self._ssh_args(args,target,socks,pin):return False
        known=_read(pin,self.root_owned,private=False)
        host=target['host']if self.config['ssh_port']==22 else '['+target['host']+']:'+str(self.config['ssh_port'])
        expected=host+' '+key
        if known!=(expected+'\n').encode():return False
        egress=self._run(['/usr/bin/curl','-q','--socks5-hostname',socks,'-fsS','--max-time','2','https://api.ipify.org'],2.5).decode('ascii','strict').strip()
        return _ip(egress)==target['host']

    def _ssh_args(self,args,target,socks,pin):
        if (not args or Path(args[0]).name!='ssh'or args[-1]!='root@'+target['host']):return False
        options={};values={'-F':[],'-p':[],'-i':[],'-D':[]};no_command=False;i=1
        safe_options={'identitiesonly','batchmode','stricthostkeychecking','userknownhostsfile','globalknownhostsfile',
            'hostkeyalgorithms','connecttimeout','exitonforwardfailure','serveraliveinterval','serveralivecountmax',
            'updatehostkeys','verifyhostkeydns','loglevel','port','identityfile'}
        try:
            while i<len(args)-1:
                token=args[i];i+=1
                if token=='-o'or token.startswith('-o'):
                    if token=='-o':setting=args[i];i+=1
                    else:setting=token[2:]
                    pair=setting.split('=',1)if'='in setting else setting.split(None,1)
                    if len(pair)!=2 or pair[0].lower()not in safe_options:return False
                    name,value=pair[0].lower(),pair[1]
                    if name in options:return False
                    options[name]=value
                elif any(token==flag or token.startswith(flag)for flag in values):
                    flag=next(flag for flag in values if token==flag or token.startswith(flag))
                    if token==flag:value=args[i];i+=1
                    else:value=token[len(flag):]
                    values[flag].append(value)
                elif token.startswith('-')and len(token)>1 and all(c in 'NnT4qv'for c in token[1:]):
                    no_command=no_command or'N'in token[1:]
                else:return False
        except (IndexError,StopIteration):return False
        if not no_command or values['-F']!=['/dev/null']or values['-D']!=[socks]:return False
        ports=values['-p']+([options['port']]if'port'in options else[])
        if len(ports)>1:return False
        if ports:
            if not re.fullmatch('[0-9]{1,5}',ports[0])or int(ports[0])!=self.config['ssh_port']:return False
        elif self.config['ssh_port']!=22:return False
        identities=values['-i']+([options['identityfile']]if'identityfile'in options else[])
        if identities!=[self.config['ssh_key_file']]:return False
        expected={'stricthostkeychecking':'yes','identitiesonly':'yes','hostkeyalgorithms':'ssh-ed25519',
                  'userknownhostsfile':str(pin),'globalknownhostsfile':'/dev/null'}
        return (all(options.get(name)==value for name,value in expected.items())
                and options.get('updatehostkeys','no')=='no'and options.get('verifyhostkeydns','no')=='no')

    def _active(self,units):
        raw=self._run(['/usr/bin/systemctl','is-active',*units],.5)
        return raw.decode('ascii','strict').splitlines()==['active']*len(units)

    def _jobs_clear(self,units):
        raw=self._run(['/usr/bin/systemctl','list-jobs','--no-legend','--plain','--no-pager'],.5).decode('ascii','strict')
        return not any(unit in line.split()for line in raw.splitlines()for unit in units)

    def _verify(self,context,socks,units,service,pin):
        def safe(fn,*args):
            try:return fn(*args)
            except (ProtocolError,OSError,ValueError,UnicodeError):return False
        with ThreadPoolExecutor(max_workers=3)as pool:
            active=pool.submit(safe,self._active,units)
            clear=pool.submit(safe,self._jobs_clear,units)
            path=pool.submit(safe,self._network,context['job']['target'],socks,service,pin,
                             context['job']['params']['ssh_host_public_key'])
            return clear.result(),active.result()and path.result()

    def _base(self,action,context):
        return dict(schema=RECIPE_SCHEMA,action=action,read_only=action in ('accept','reconcile'),ok=False,
                    checks={'generation_verified':False},target=context['job']['target'],**{k:context[k]for k in SCOPE})

    def _stage_plan(self,context,db=None):
        pin=self._pin(context);unit=Path(self.config['unit_dir'])/SERVICE
        if pin.exists()and _read(pin,self.root_owned)!=self._known(context):raise ProtocolError('stage_immutable_pin_conflict')
        desired=hashlib.sha256(self._unit(context)).hexdigest()
        if unit.exists()or unit.is_symlink():
            actual=hashlib.sha256(_read(unit,self.root_owned,private=False)).hexdigest()
            if actual!=desired:
                prior=[]if db is None else db.execute("SELECT plan FROM operations WHERE action='stage'").fetchall()
                if not any(json.loads(row['plan']).get('unit_sha256')==actual for row in prior):
                    raise ProtocolError('stage_unit_not_owned')
        return {'pin':str(pin),'pin_sha256':hashlib.sha256(self._known(context)).hexdigest(),
                'unit':str(unit),'unit_sha256':desired,'units':[SERVICE]}

    def _registry(self,context):
        p=context['job']['params'];path=self.config.get('registry_file');expected=self.config.get('registry_sha256')
        if not path or not expected or expected!=p['expected_registry_hash']:raise ProtocolError('stage_registry_missing')
        raw=_read(path,self.root_owned,65536)
        if hashlib.sha256(raw).hexdigest()!=_hash(expected):raise ProtocolError('stage_registry_hash')
        registry=decode_json(raw)
        if (registry.get('schema')!=REGISTRY_SCHEMA or registry.get('old_identity')!=p['old_identity']
                or registry.get('old_ipv4')!=p['old_ipv4']or registry.get('old_config_hash')!=p['old_config_hash']
                or registry.get('source_host_key_sha256')!=p['ssh_host_key_sha256']):raise ProtocolError('stage_registry_scope')
        units=registry.get('allowed_units');files=registry.get('files')
        if (not isinstance(units,list)or not 1<=len(units)<=8 or len(set(units))!=len(units)
                or any(not isinstance(u,str)or not UNIT.fullmatch(u)or u==SERVICE for u in units)
                or not isinstance(files,list)or not 1<=len(files)<=16):raise ProtocolError('stage_registry_shape')
        if any(u in ('ssh.service','sshd.service','networking.service','systemd-networkd.service','docker.service')for u in units):
            raise ProtocolError('stage_protected_unit')
        if registry.get('verify_unit')not in units or not isinstance(registry.get('known_hosts_file'),str):
            raise ProtocolError('stage_registry_verifier')
        fence_path=self.config.get('old_fence_file')
        if not fence_path:raise ProtocolError('stage_fence_missing')
        fence=decode_json(_read(fence_path,self.root_owned,8192));at=fence.get('received_at')
        if (fence.get('schema')!=FENCE_SCHEMA or fence.get('verified')is not True or type(at)is not int
                or not 0<=int(self.clock())-at<=90 or fence.get('old_identity')!=p['old_identity']
                or fence.get('old_config_hash')!=p['old_config_hash']or fence.get('old_ipv4')!=p['old_ipv4']
                or fence.get('target')!=context['job']['target']or fence.get('restore_epoch')!=p['restore_epoch']
                or fence.get('restore_hash')!=p['restore_hash']or fence.get('source_fenced')is not True
                or fence.get('provider_off')is not True):raise ProtocolError('stage_fence_unverified')
        return registry

    def _apply_plan(self,context):
        registry=self._registry(context);old=context['job']['params']['old_ipv4'];new=context['job']['target']['host']
        plans=[];seen=set()
        for entry in registry['files']:
            if not isinstance(entry,dict)or set(entry)-{'path','sha256','format','unit','pointers','token_count'}:
                raise ProtocolError('stage_registry_entry')
            path=_trusted_file(entry.get('path',''),self.root_owned)
            if str(path)in seen or entry.get('unit')not in registry['allowed_units']:raise ProtocolError('stage_registry_entry')
            seen.add(str(path));before=_read(path,self.root_owned,private=False)
            if hashlib.sha256(before).hexdigest()!=_hash(entry.get('sha256')):raise ProtocolError('stage_cas_mismatch')
            if entry.get('format')=='json':
                value=decode_json(before);pointers=entry.get('pointers')
                if not isinstance(pointers,list)or not 1<=len(pointers)<=32:raise ProtocolError('stage_json_pointers')
                for pointer in pointers:
                    if not isinstance(pointer,list)or not 1<=len(pointer)<=12:raise ProtocolError('stage_json_pointer')
                    node=value
                    try:
                        for part in pointer[:-1]:
                            if not isinstance(part,(str,int))or type(part)is bool:raise KeyError()
                            node=node[part]
                        last=pointer[-1]
                        if not isinstance(last,(str,int))or type(last)is bool or node[last]!=old:raise KeyError()
                        node[last]=new
                    except (KeyError,IndexError,TypeError):raise ProtocolError('stage_json_expected_old')from None
                after=canonical_json(value)+b'\n'
            elif entry.get('format')=='text_tokens':
                count=_int(entry.get('token_count'),1,128)
                try:text=before.decode('utf-8','strict')
                except UnicodeError:raise ProtocolError('stage_text_encoding')from None
                pattern=re.compile(r'(?<![0-9A-Za-z_.:-])'+re.escape(old)+r'(?![0-9A-Za-z_.])')
                after_text,actual=pattern.subn(new,text)
                if actual!=count:raise ProtocolError('stage_text_expected_old')
                after=after_text.encode()
            else:raise ProtocolError('stage_registry_format')
            plans.append({'path':str(path),'before_sha256':entry['sha256'],'after_sha256':hashlib.sha256(after).hexdigest(),
                          'backup':str(self.root/'backups'/context_key(context)/(str(len(plans))+'.bin')),
                          'after':base64.b64encode(after).decode(),'mode':stat.S_IMODE(path.stat().st_mode),
                          'uid':path.stat().st_uid,'gid':path.stat().st_gid,'unit':entry['unit']})
        if set(p['unit']for p in plans)!=set(registry['allowed_units']):raise ProtocolError('stage_unused_registry_unit')
        if sum(len(p['after'])for p in plans)>6*1024*1024:raise ProtocolError('stage_registry_size')
        known_path=registry['known_hosts_file']
        planned=next((base64.b64decode(p['after'])for p in plans if p['path']==known_path),None)
        known=planned if planned is not None else _read(known_path,self.root_owned,private=False)
        if known!=self._known(context):
            raise ProtocolError('stage_global_host_pin_missing')
        return {'files':plans,'units':registry['allowed_units'],'verify_unit':registry['verify_unit'],
                'known_hosts_file':known_path,'verify_target':context['job']['target']}

    @staticmethod
    def _same_restore_scope(left,right):
        keys=('stage_profile','ssh_host_public_key','ssh_host_key_sha256','restore_epoch','restore_hash',
              'old_identity','old_ipv4','old_config_hash','expected_registry_hash')
        return (all(left.get(k)==right.get(k)for k in ('agent_id','incident_id','generation','config_hash'))
                and canonical_json(left['job']['target'])==canonical_json(right['job']['target'])
                and all(canonical_json(left['job']['params'].get(k))==canonical_json(right['job']['params'].get(k))for k in keys))

    def _rollback_plan(self,context,db):
        if self.config.get('rollback_enabled')is not True:raise ProtocolError('stage_rollback_not_authorized')
        self._registry(context)
        source=None;repairs=[]
        for row in db.execute('SELECT * FROM operations ORDER BY rowid DESC').fetchall():
            old=decode_json(row['context'].encode())
            if self._same_restore_scope(context,old):
                if row['phase']not in ('DONE','FAILED'):repairs.append(row['id'])
                if row['action']=='apply'and source is None:source=row
        if source is None:raise ProtocolError('stage_rollback_original_missing')
        original=json.loads(source['plan']);files=[]
        for old in original['files']:
            current=_read(old['path'],self.root_owned,private=False);current_sha=hashlib.sha256(current).hexdigest()
            if current_sha not in (old['before_sha256'],old['after_sha256']):raise ProtocolError('stage_rollback_cas_mismatch')
            before=_read(old['backup'],self.root_owned)
            if hashlib.sha256(before).hexdigest()!=old['before_sha256']:raise ProtocolError('stage_backup_hash')
            files.append({**old,'before_sha256':current_sha,'after_sha256':old['before_sha256'],
                'after':base64.b64encode(before).decode(),
                'backup':str(self.root/'backups'/context_key(context)/(str(len(files))+'.bin'))})
        target={**context['job']['target'],'host':context['job']['params']['old_ipv4'],
                'provider_id':context['job']['params']['old_identity']['provider_id']}
        return {**original,'files':files,'verify_target':target,'repairs':repairs}

    def _verify_plan(self,context,plan):
        verified={**context,'job':{**context['job'],'target':plan.get('verify_target',context['job']['target'])}}
        return self._verify(verified,GLOBAL_SOCKS,plan['units'],plan['verify_unit'],plan['known_hosts_file'])

    def _observe(self,row):
        context=decode_json(row['context'].encode());plan=json.loads(row['plan']);action=row['action']
        if action=='stage':
            static=(_read(plan['pin'],self.root_owned)==self._known(context)
                and hashlib.sha256(_read(plan['unit'],self.root_owned,private=False)).hexdigest()==plan['unit_sha256'])
            if not static:return False,False
            clear,good=self._verify(context,STAGE_SOCKS,[SERVICE],SERVICE,plan['pin'])
            return clear,clear and good
        if action in ('apply','rollback'):
            actual=[hashlib.sha256(_read(p['path'],self.root_owned,private=False)).hexdigest()for p in plan['files']]
            match=all(value==p['after_sha256']for value,p in zip(actual,plan['files']))
            clear,good=self._verify_plan(context,plan)if match else(False,False)
            complete=match and clear and good
            # A dead helper, no pending systemd job and exact recorded old/new
            # hashes prove a finished failed/partial operation. This is an ACK
            # of failure, never an applied success or repair authorization.
            known_files=all(value in (p['before_sha256'],p['after_sha256'])for value,p in zip(actual,plan['files']))
            if known_files and not clear:clear=self._jobs_clear(plan['units'])
            return bool(known_files and clear),complete
        return False,False

    def run(self,action,context):
        context=validate_context(context,self.config['agent_id'])
        if context['job']['target']['port']!=self.config['ssh_port']:raise ProtocolError('stage_ssh_port_mismatch')
        if action not in ACTIONS or (action!='reconcile'and action!=context['job']['op']):raise ProtocolError('stage_action_mismatch')
        result=self._base(action,context);key=context_key(context);self.deadline=self.monotonic()+(4 if action in ('accept','reconcile')else 14)
        with self.locked(read_only=action in ('reconcile','accept'))as db:
            row=db.execute('SELECT * FROM operations WHERE id=?',(key,)).fetchone()
            if action=='reconcile':
                if row is None:return result
                if row['result']is not None:
                    result=decode_json(row['result'].encode());result.update(action='reconcile',read_only=True,reconciled=True)
                    result['checks'].update(read_only=True,historical_outcome=True);return result
                known,good=self._observe(row)
                result.update(reconciled=known,ok=good,checks={'generation_verified':known,'read_only':True,
                    {'stage':'staged','apply':'applied','rollback':'rolled_back'}[row['action']]:good})
                return result
            if action=='accept':
                plan=self._stage_plan(context)
                clear,path=self._verify(context,STAGE_SOCKS,[SERVICE],SERVICE,plan['pin'])
                good=_read(plan['pin'],self.root_owned)==self._known(context)and clear and path
                result.update(ok=good,checks={'accepted':good,'generation_verified':good});return result
            if row is not None:
                if row['result']is not None:return decode_json(row['result'].encode())
                raise ProtocolError('stage_outcome_unknown')
            for pending in db.execute("SELECT * FROM operations WHERE phase NOT IN ('DONE','FAILED')").fetchall():
                if action=='rollback'and pending['action']in ('apply','rollback')and self._same_restore_scope(context,decode_json(pending['context'].encode())):
                    continue
                known,good=self._observe(pending)
                if not known:raise ProtocolError('stage_previous_outcome_unknown')
                db.execute('UPDATE operations SET phase=? WHERE id=?',('DONE'if good else'FAILED',pending['id']))
            self._write_guard()
            if action=='stage':plan=self._stage_plan(context,db)
            elif action=='apply':
                if context['job']['params']['stage_profile']!='restored':raise ProtocolError('stage_apply_profile')
                self._pin(context);plan=self._apply_plan(context)
            else:
                self._pin(context);plan=self._rollback_plan(context,db)
            db.execute('INSERT INTO operations VALUES(?,?,?,?,?,NULL)',(key,action,canonical_json(context).decode(),'INTENT',json.dumps(plan,sort_keys=True)))
            db.commit() # explicit audit boundary; isolation_level=None already autocommits with FULL.
            try:
                if action=='stage':
                    pin=Path(plan['pin']);_mkdir(pin.parent);_directory(pin.parent,self.root_owned)
                    self._write_guard()
                    if not pin.exists():_atomic(pin,self._known(context))
                    elif _read(pin,self.root_owned)!=self._known(context):raise ProtocolError('stage_immutable_pin_conflict')
                    self._write_guard();_atomic(plan['unit'],self._unit(context))
                    db.execute("UPDATE operations SET phase='FILES' WHERE id=?",(key,))
                    self._run(['/usr/bin/systemctl','daemon-reload'],2)
                    self._run(['/usr/bin/systemctl','restart',SERVICE],4)
                    clear,path=self._verify(context,STAGE_SOCKS,[SERVICE],SERVICE,plan['pin']);good=clear and path
                else:
                    for p in plan['files']:
                        directory=Path(p['backup']).parent;_mkdir(directory);_directory(directory,self.root_owned)
                        before=_read(p['path'],self.root_owned,private=False)
                        if hashlib.sha256(before).hexdigest()!=p['before_sha256']:raise ProtocolError('stage_cas_mismatch')
                        self._write_guard();_atomic(p['backup'],before)
                    db.execute("UPDATE operations SET phase='BACKED_UP' WHERE id=?",(key,))
                    for p in plan['files']:
                        if hashlib.sha256(_read(p['path'],self.root_owned,private=False)).hexdigest()!=p['before_sha256']:raise ProtocolError('stage_cas_mismatch')
                        self._write_guard();_atomic(p['path'],base64.b64decode(p['after']),p['mode'],p['uid'],p['gid'])
                    db.execute("UPDATE operations SET phase='FILES' WHERE id=?",(key,))
                    for unit in plan['units']:self._run(['/usr/bin/systemctl','restart',unit],3)
                    clear,path=self._verify_plan(context,plan);good=clear and path
                    if action=='rollback':
                        for operation in plan['repairs']:
                            old=db.execute('SELECT context,action FROM operations WHERE id=?',(operation,)).fetchone()
                            failure=self._base(old['action'],decode_json(old['context'].encode()))
                            failure['checks'].update(repaired_by_rollback=True)
                            db.execute("UPDATE operations SET phase='FAILED',result=? WHERE id=?",(canonical_json(failure).decode(),operation))
                self._write_guard()
                result.update(ok=bool(good),checks={'generation_verified':bool(good),
                    {'stage':'staged','apply':'applied','rollback':'rolled_back'}[action]:bool(good)})
                db.execute('UPDATE operations SET phase=?,result=? WHERE id=?',('DONE'if good else'FAILED',canonical_json(result).decode(),key))
                return result
            except Exception:
                # Keep durable INTENT/FILES without a success receipt. The
                # original operation can only be observed, never blindly retried.
                raise ProtocolError('stage_outcome_unknown')from None


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config',default='/etc/servernet/relay-recovery/ct-stage.json')
    parser.add_argument('--job-file',required=True);parser.add_argument('--job-sha256',required=True);parser.add_argument('--nonce',required=True)
    parser.add_argument('action',choices=sorted(ACTIONS));args=parser.parse_args(argv)
    context={}
    try:
        if os.geteuid()!=0:raise ProtocolError('stage_root_required')
        os.umask(0o077);config=load_config(args.config)
        context=load_job(config,args.job_file,args.job_sha256,args.nonce,args.action,int(time.time()))
        result=CtStage(config).run(args.action,context);sys.stdout.buffer.write(canonical_json(result));return 0 if result['ok']else 1
    except Exception:
        result=dict(schema=RECIPE_SCHEMA,action=args.action,read_only=args.action in ('accept','reconcile'),ok=False,
                    checks={'generation_verified':False},code='stage_blocked_safe')
        for key in SCOPE:
            if key in context:result[key]=context[key]
        if isinstance(context.get('job'),dict):result['target']=context['job'].get('target')
        sys.stdout.buffer.write(canonical_json(result));return 1


if __name__=='__main__':raise SystemExit(main())
