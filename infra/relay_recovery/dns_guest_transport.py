#!/usr/bin/env python3
"""Pinned PVE-to-DNS-guest data-only transport for the default DNS probe.

No key creation, source install, refresh files, host-key enrollment or remote
command selectors arrive in jobs. The existing root SSH identity is a private
local selector, never read as bytes here. Guest ownership semantics and actual
authoritative measurements use dns_consumer_probe's single implementation.
"""
from __future__ import annotations
import argparse
import base64
import hashlib
import hmac
import os
from pathlib import Path
import re
import shlex
import stat
import struct
import sys
import time

try:
    from . import dns_consumer_probe as dns
    from .agent import _bounded_process
    from .protocol import ProtocolError, canonical_json, decode_json, private_file
except ImportError:
    import dns_consumer_probe as dns
    from agent import _bounded_process
    from protocol import ProtocolError, canonical_json, decode_json, private_file

SCHEMA='servernet.relay.dns-guest-transport.v1'
PATH=re.compile(r'\A/[A-Za-z0-9/_.-]{1,240}\Z')


def _path(value):
    if not isinstance(value,str) or not PATH.fullmatch(value) or '..' in Path(value).parts:
        raise ProtocolError('dns_transport_path')
    return Path(value)


def _pin(reference, *, root_owned=True, private=False, executable=False):
    if not isinstance(reference,dict) or set(reference)!={'path','sha256'}:
        raise ProtocolError('dns_transport_pin')
    path=_path(reference['path']);dns._hash(reference['sha256'],'dns_transport_pin')
    for item in (path,*path.parents):
        info=item.lstat()
        if item.is_symlink() or (item==path and not stat.S_ISREG(info.st_mode)) or (item!=path and not stat.S_ISDIR(info.st_mode)):
            raise ProtocolError('dns_transport_file')
        if root_owned and (info.st_uid!=0 or info.st_mode&0o022):
            raise ProtocolError('dns_transport_file')
    before=path.lstat()
    if before.st_nlink!=1 or not 1<=before.st_size<=16*1024*1024:
        raise ProtocolError('dns_transport_file')
    if private and stat.S_IMODE(before.st_mode)!=0o600:
        raise ProtocolError('dns_transport_file')
    if executable and not before.st_mode&0o111:
        raise ProtocolError('dns_transport_executable')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    try:
        if dns._identity(os.fstat(fd))!=dns._identity(before):raise ProtocolError('dns_transport_changed')
        raw=bytearray()
        while len(raw)<=16*1024*1024:
            part=os.read(fd,65536)
            if not part:break
            raw.extend(part)
        if (len(raw)!=before.st_size or dns._identity(os.fstat(fd))!=dns._identity(before)
                or dns._identity(path.lstat())!=dns._identity(before)
                or not hmac.compare_digest(hashlib.sha256(raw).hexdigest(),reference['sha256'])):
            raise ProtocolError('dns_transport_changed')
        return bytes(raw)
    finally:os.close(fd)


def _host_key(raw, host, port, fingerprint):
    expected=host if port==22 else '['+host+']:'+str(port)
    rows=[line.strip() for line in raw.splitlines() if line.strip() and not line.lstrip().startswith(b'#')]
    if len(rows)!=1:raise ProtocolError('dns_transport_host_key')
    fields=rows[0].split()
    if len(fields)!=3 or fields[0]!=expected.encode() or fields[1]!=b'ssh-ed25519':
        raise ProtocolError('dns_transport_host_key')
    try:blob=base64.b64decode(fields[2],validate=True)
    except ValueError:raise ProtocolError('dns_transport_host_key') from None
    if (len(blob)!=51 or blob[:4]!=struct.pack('!I',11) or blob[4:15]!=b'ssh-ed25519'
            or blob[15:19]!=struct.pack('!I',32) or base64.b64encode(blob)!=fields[2]):
        raise ProtocolError('dns_transport_host_key')
    observed='SHA256:'+base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip('=')
    if observed!=fingerprint:raise ProtocolError('dns_transport_host_key')


def load_config(path, expected_sha256, *, agent_id, root_owned=True):
    raw=dns._read_private(path,root_owned=root_owned)
    dns._hash(expected_sha256,'dns_transport_config_pin')
    if hashlib.sha256(raw).hexdigest()!=expected_sha256:raise ProtocolError('dns_transport_config_pin')
    value=decode_json(raw)
    if (raw!=canonical_json(value) or set(value)!={'schema','agent_id','native_enrollment','ssh','guest'}
            or value.get('schema')!=SCHEMA or value.get('agent_id')!=agent_id):
        raise ProtocolError('dns_transport_config')
    reference=value['native_enrollment']
    if not isinstance(reference,dict) or set(reference)!={'path','sha256'}:raise ProtocolError('dns_transport_config')
    enrollment=dns.load_handoff_config(reference['path'],reference['sha256'],agent_id=agent_id,root_owned=root_owned)
    ssh=value['ssh'];guest=value['guest']
    if (not isinstance(ssh,dict) or set(ssh)!={'executable','port','identity_file','known_hosts','host_key_sha256'}
            or not isinstance(guest,dict) or set(guest)!={'python','script','protocol','config'}):
        raise ProtocolError('dns_transport_config')
    dns._integer(ssh['port'],1,65535,'dns_transport_port')
    _pin(ssh['executable'],root_owned=root_owned,executable=True)
    key=private_file(_path(ssh['identity_file']),root_owned=root_owned)
    info=key.lstat()
    if stat.S_IMODE(info.st_mode)!=0o600 or info.st_nlink!=1:raise ProtocolError('dns_transport_identity')
    known=_pin(ssh['known_hosts'],root_owned=root_owned)
    if len(known)>65536:raise ProtocolError('dns_transport_host_key')
    _host_key(known,enrollment['secondary']['ipv4'],ssh['port'],ssh['host_key_sha256'])
    for ref in guest.values():
        if not isinstance(ref,dict) or set(ref)!={'path','sha256'}:raise ProtocolError('dns_guest_pin')
        _path(ref['path']);dns._hash(ref['sha256'],'dns_guest_pin')
    if (Path(guest['script']['path']).parent!=Path(guest['protocol']['path']).parent
            or Path(guest['script']['path']).name!='dns_consumer_probe.py'
            or Path(guest['protocol']['path']).name!='protocol.py'
            or guest['config']['sha256']!=reference['sha256']):
        raise ProtocolError('dns_guest_pin')
    return dict(value,enrollment=enrollment,key_identity=list(dns._identity(info)))


def remote_command(config):
    """All code/paths/pins originate in the private pinned transport, not jobs."""
    guest=config['guest']
    literal=canonical_json(dict(guest=guest,agent_id=config['agent_id'])).decode()
    code='''import hashlib,json,os,stat,sys,types
v=json.loads(REVIEWED_LOCAL_DATA)
def read(ref):
 from pathlib import Path
 p=Path(ref['path'])
 for q in (p,*p.parents):
  s=q.lstat()
  if q.is_symlink() or s.st_uid!=0 or s.st_mode&0o022 or (q==p and not stat.S_ISREG(s.st_mode)) or (q!=p and not stat.S_ISDIR(s.st_mode)):raise ValueError('guest_pin')
 s=p.lstat()
 if s.st_nlink!=1 or not 0<s.st_size<=16777216:raise ValueError('guest_pin')
 fd=os.open(p,os.O_RDONLY|os.O_NOFOLLOW)
 try:
  a=os.fstat(fd)
  with os.fdopen(fd,'rb',closefd=False) as f:b=f.read(16777217)
  z=os.fstat(fd)
  ids=lambda i:(i.st_dev,i.st_ino,i.st_uid,i.st_gid,i.st_mode,i.st_nlink,i.st_size,i.st_mtime_ns,i.st_ctime_ns)
  if ids(a)!=ids(s) or ids(z)!=ids(s) or ids(p.lstat())!=ids(s) or len(b)!=s.st_size or hashlib.sha256(b).hexdigest()!=ref['sha256']:raise ValueError('guest_pin')
  return b
 finally:os.close(fd)
g=v['guest']
if os.path.realpath(sys.executable)!=g['python']['path']:raise ValueError('guest_interpreter')
read(g['python']);read(g['config']);p=read(g['protocol']);s=read(g['script'])
m=types.ModuleType('protocol');m.__file__=g['protocol']['path'];m.__package__='';sys.modules['protocol']=m
exec(compile(p,m.__file__,'exec'),m.__dict__)
sys.argv=[g['script']['path'],'--handoff-config',g['config']['path'],'--config-sha256',g['config']['sha256'],'--agent-id',v['agent_id']]
exec(compile(s,g['script']['path'],'exec'),{'__name__':'__main__','__file__':g['script']['path'],'__package__':''})
'''.replace('REVIEWED_LOCAL_DATA',repr(literal),1)
    return shlex.join([guest['python']['path'],'-E','-S','-B','-c',code])


def ssh_argv(config):
    ssh=config['ssh'];host=config['enrollment']['secondary']['ipv4']
    return [ssh['executable']['path'],'-F','/dev/null','-T','-a','-x',
            '-oBatchMode=yes','-oIdentitiesOnly=yes','-oPreferredAuthentications=publickey',
            '-oPasswordAuthentication=no','-oKbdInteractiveAuthentication=no','-oHostbasedAuthentication=no',
            '-oGSSAPIAuthentication=no','-oStrictHostKeyChecking=yes','-oHostKeyAlgorithms=ssh-ed25519',
            '-oUserKnownHostsFile='+ssh['known_hosts']['path'],'-oGlobalKnownHostsFile=/dev/null',
            '-oVerifyHostKeyDNS=no','-oCheckHostIP=yes','-oUpdateHostKeys=no','-oAddKeysToAgent=no',
            '-oClearAllForwardings=yes','-oForwardAgent=no','-oForwardX11=no','-oPermitLocalCommand=no',
            '-oProxyCommand=none','-oProxyJump=none','-oControlMaster=no','-oControlPath=none',
            '-oConnectTimeout=2','-oConnectionAttempts=1','-oServerAliveInterval=2','-oServerAliveCountMax=1',
            '-i',ssh['identity_file'],'-p',str(ssh['port']),'--','root@'+host,remote_command(config)]


class DnsGuestTransport:
    def __init__(self,path,expected_sha256,*,agent_id,root_owned=True,runner=_bounded_process,now=time.time):
        self.path,self.expected_sha256,self.agent_id=path,expected_sha256,agent_id
        self.root_owned,self.runner,self.now=root_owned,runner,now

    def run(self,context):
        context=dns.validate_context(context,agent_id=self.agent_id)
        result=dict(schema=dns.PROBE_SCHEMA,probe_kind='dns',read_only=True,ok=False,
                    target=dict(context['job']['target']),checks=dict(consumer='dns',probe_ok=False,path_profile='default_path_v1'),
                    **{key:context[key] for key in dns.SCOPE})
        try:
            cfg=load_config(self.path,self.expected_sha256,agent_id=self.agent_id,root_owned=self.root_owned)
            started=self.now()
            handoff=dns.validate_handoff(context,agent_id=self.agent_id,enrollment=cfg['enrollment'],now=started)
            timeout=min(8,handoff['artifact']['expires_at']-started)
            if 'expires_at' in context['job'].get('params',{}):timeout=min(timeout,context['job']['params']['expires_at']-started)
            status,raw=self.runner(ssh_argv(cfg),canonical_json(context),timeout)
            if not isinstance(raw,bytes) or len(raw)>8192:raise ProtocolError('dns_guest_output')
            observed=decode_json(raw);checks=observed.get('checks')
            if (observed.get('schema')!=dns.PROBE_SCHEMA or observed.get('probe_kind')!='dns'
                    or observed.get('read_only') is not True or type(observed.get('ok')) is not bool
                    or observed.get('target')!=context['job']['target'] or type(status) is not int
                    or (status!=0 and observed['ok']) or not isinstance(checks,dict) or len(canonical_json(checks))>2048
                    or checks.get('consumer')!='dns' or checks.get('path_profile')!='default_path_v1'
                    or type(checks.get('probe_ok')) is not bool or checks['probe_ok'] is not observed['ok']
                    or any(type(observed.get(key)) is not type(context[key]) or observed.get(key)!=context[key] for key in dns.SCOPE)):
                raise ProtocolError('dns_guest_receipt')
            if observed['ok'] and (any(checks.get(key) is not True for key in
                ('authoritative_soa_ok','soa_identity_matches','udp_and_tcp_verified','source_bound','network_observation_fresh'))
                or checks.get('execution_origin')!='dns101'
                or checks.get('ownership_observed_at')!=handoff['artifact']['observed_at']
                or checks.get('ownership_expires_at')!=handoff['artifact']['expires_at']
                or checks.get('source_receipt_sha256')!=handoff['source_receipt_sha256']
                or checks.get('ownership_sha256')!=handoff['artifact_sha256']):raise ProtocolError('dns_guest_success')
            if canonical_json(load_config(self.path,self.expected_sha256,agent_id=self.agent_id,root_owned=self.root_owned))!=canonical_json(cfg):
                raise ProtocolError('dns_transport_changed')
            finished=self.now()
            if finished<started:raise ProtocolError('dns_transport_clock')
            dns.validate_handoff(context,agent_id=self.agent_id,enrollment=cfg['enrollment'],now=finished)
            return observed
        except (ProtocolError,OSError,TimeoutError):
            result['checks']['failure']='dns_guest_unproven'
            return result


def main(argv=None):
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config',required=True);parser.add_argument('--config-sha256',required=True)
    parser.add_argument('--agent-id',required=True);args=parser.parse_args(argv)
    try:
        raw=sys.stdin.buffer.read(16385)
        if len(raw)>16384:raise ProtocolError('dns_context_size')
        result=DnsGuestTransport(args.config,args.config_sha256,agent_id=args.agent_id).run(decode_json(raw))
    except (ProtocolError,OSError,TimeoutError):
        result=dict(schema=dns.PROBE_SCHEMA,probe_kind='dns',read_only=True,ok=False,checks=dict(consumer='dns',probe_ok=False))
    sys.stdout.buffer.write(canonical_json(result)+b'\n')
    return 0 if result['ok'] else 1


if __name__=='__main__':raise SystemExit(main())
