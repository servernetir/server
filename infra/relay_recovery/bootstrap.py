"""Private, immutable per-CREATE cloud-init and SSH host-key preparation.

No provider/VM calls occur here. A distinct ed25519 host identity is generated
once by fixed ssh-keygen argv, persisted before a provider POST and used only
for an isolated generic trial. A restored snapshot preserves the independently
pinned source SSH/SFTP identity, and receives no private host key in user-data.
Returned artifacts contain no
private key/user-data bytes; callers explicitly read a private payload only for
the authenticated provider request and must never log it.

The restored profile's machine-ID helper follows cloud-init's documented clean
--machine-id + reboot procedure. It is not a claim that changing a file changes
running PID1's identity. Readiness remains false until a separately observed
second boot has a new machine ID. The helper never releases the business fence.
Sources: https://docs.cloud-init.io/en/24.4/reference/cli.html
and https://docs.cloud-init.io/en/24.4/reference/modules.html#ssh
"""
from __future__ import annotations

import base64
import contextlib
from dataclasses import dataclass
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import stat
import struct
import subprocess

from . import core
from .protocol import canonical_json

KEYGEN = '/usr/bin/ssh-keygen'
MAX_USER_DATA = 32*1024
HASH = re.compile(r'\A[0-9a-f]{64}\Z')
INTENT = re.compile(r'\A[0-9a-f]{40}\Z')
MACHINE_ID_UNIT = 'servernet-relay-recovery-machine-id.service'
MACHINE_ID_UNIT_CONTENT = '''[Unit]
Description=Verify restored relay machine identity under quarantine
Wants=cloud-init.target
After=cloud-init.target

[Service]
Type=oneshot
User=root
UMask=0077
ExecStart=/usr/bin/python3 /usr/local/lib/servernet-relay-recovery/machine_id_recovery.py
TimeoutStartSec=60
Restart=no

[Install]
WantedBy=cloud-final.service
'''


class BootstrapError(core.RecoveryError):
    pass


@dataclass(frozen=True)
class BootstrapArtifact:
    intent_id: str
    profile: str
    user_data_path: str
    user_data_sha256: str
    public_host_key: str
    public_host_fingerprint: str
    machine_id_receipt_required: bool
    known_hosts_path: str | None = None
    host_key_origin: str = 'trial'

    def __repr__(self):
        return (f'BootstrapArtifact(intent_id={self.intent_id!r}, profile={self.profile!r}, '
                f'user_data_sha256={self.user_data_sha256!r}, '
                f'machine_id_receipt_required={self.machine_id_receipt_required!r})')


def _private(path: Path, *, directory=False):
    info = path.lstat()
    expected = stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)
    if not expected or path.is_symlink() or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        raise BootstrapError('private_bootstrap_path_required')


def _read(path: Path, maximum=65536) -> bytes:
    _private(path)
    fd = os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    with os.fdopen(fd,'rb') as stream: value = stream.read(maximum+1)
    if len(value) > maximum: raise BootstrapError('bootstrap_file_size')
    return value


def _directory(path: Path):
    if not path.exists():
        path.mkdir(mode=0o700)
        fd=os.open(path.parent,os.O_DIRECTORY)
        try: os.fsync(fd)
        finally: os.close(fd)
    _private(path,directory=True)


def _immutable(path: Path, raw: bytes):
    if path.exists() or path.is_symlink():
        if _read(path,max(MAX_USER_DATA,len(raw))) != raw:
            raise BootstrapError('immutable_bootstrap_conflict')
        return
    temp = path.with_name('.pending-'+secrets.token_hex(12))
    fd = os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(raw); stream.flush(); os.fsync(stream.fileno())
        # Atomic no-replace publication; a racing writer cannot overwrite state.
        os.link(temp,path)
        parent = os.open(path.parent,os.O_DIRECTORY)
        try: os.fsync(parent)
        finally: os.close(parent)
    finally:
        if temp.exists(): temp.unlink()


def _digest(value) -> str:
    return hashlib.sha256(canonical_json(value)).hexdigest()


def public_key(value: str) -> str:
    """Only bare ed25519 public keys, never caller-supplied options/commands."""
    if not isinstance(value,str) or len(value) > 1024 or '\n' in value or '\r' in value:
        raise BootstrapError('invalid_bootstrap_public_key')
    parts = value.strip().split()
    if len(parts) not in (2,3) or parts[0] != 'ssh-ed25519':
        raise BootstrapError('invalid_bootstrap_public_key')
    try:
        blob = base64.b64decode(parts[1],validate=True)
        if len(blob) != 51 or struct.unpack('!I',blob[:4])[0] != 11 or blob[4:15] != b'ssh-ed25519' or struct.unpack('!I',blob[15:19])[0] != 32:
            raise ValueError()
    except (ValueError,struct.error):
        raise BootstrapError('invalid_bootstrap_public_key') from None
    return 'ssh-ed25519 '+parts[1]


def _key_file(path: str) -> str:
    p = Path(path)
    if not p.is_absolute(): raise BootstrapError('public_key_absolute_path')
    info = p.lstat()
    if not stat.S_ISREG(info.st_mode) or p.is_symlink() or info.st_uid not in (0,os.geteuid()) or info.st_mode & 0o022 or info.st_size > 1024:
        raise BootstrapError('public_key_file_untrusted')
    for parent in p.parents:
        if parent.is_symlink() or not parent.is_dir(): raise BootstrapError('public_key_parent')
    return public_key(p.read_text().strip())


def _sources(values) -> list[str]:
    if not isinstance(values,list) or not 1 <= len(values) <= 4:
        raise BootstrapError('explicit_ssh_source_addresses_required')
    out = []
    for value in values:
        try: address = ipaddress.ip_address(value)
        except (ValueError,TypeError): raise BootstrapError('invalid_ssh_source_address') from None
        if not address.is_global or str(address) != value:
            raise BootstrapError('invalid_ssh_source_address')
        if value in out: raise BootstrapError('duplicate_ssh_source_address')
        out.append(value)
    return sorted(out)


def _run(argv: list[str]) -> tuple[int,bytes]:
    try:
        result = subprocess.run(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,timeout=10,check=False,
            env={'PATH':'/usr/bin:/bin','LANG':'C.UTF-8'})
        if len(result.stdout) > 4096: raise BootstrapError('keygen_output_size')
        return result.returncode,result.stdout
    except (OSError,subprocess.TimeoutExpired):
        raise BootstrapError('keygen_unavailable') from None


# This standalone stdlib program is copied into cloud-init only for a restored
# candidate. No guest command/path/URL comes from a network response.
MACHINE_ID_HELPER = r'''#!/usr/bin/env python3
import hashlib,json,os,re,stat,subprocess,sys,urllib.request
from pathlib import Path
CONFIG=Path('/etc/servernet/relay-bootstrap.json')
GUARD=Path('/etc/servernet/relay-generation.json')
MARKERS=Path('/var/lib/servernet-relay-bootstrap')
MACHINE=Path('/etc/machine-id')
BOOT=Path('/proc/sys/kernel/random/boot_id')
DMI=Path('/sys/class/dmi/id/product_uuid')
CLEAN_HOOKS=Path('/etc/cloud/clean.d')
URL='http://169.254.169.254/hetzner/v1/metadata/instance-id'
HEX=re.compile(r'^[0-9a-f]{32}$')
UUID=re.compile(r'^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$')
def machine_valid(value):return bool(HEX.fullmatch(value)) and value!='0'*32
def private(path,directory=False):
    s=path.lstat()
    if path.is_symlink() or s.st_uid!=0 or s.st_mode&0o077 or not (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode)):
        raise ValueError('private_state')
def read_json(path):
    private(path)
    if path.stat().st_size>65536: raise ValueError('state_size')
    return json.loads(path.read_text())
def save(path,value):
    temp=path.with_name(path.name+'.tmp')
    fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(value,f,sort_keys=True,separators=(',',':'));f.flush();os.fsync(f.fileno())
        os.replace(temp,path)
        d=os.open(path.parent,os.O_DIRECTORY)
        try:os.fsync(d)
        finally:os.close(d)
    finally:
        if temp.exists():temp.unlink()
def directory(path):
    if not path.exists():
        path.mkdir(mode=0o700)
        d=os.open(path.parent,os.O_DIRECTORY)
        try:os.fsync(d)
        finally:os.close(d)
    private(path,True)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):raise ValueError('metadata_redirect')
def metadata_id():
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
    with opener.open(URL,timeout=2) as response:
        raw=response.read(65).decode('ascii').strip()
    if not re.fullmatch(r'[1-9][0-9]{0,19}',raw):raise ValueError('metadata_identity')
    return raw
def command(argv,timeout=10):
    return subprocess.run(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=timeout,check=False)
def completed(c,marker,boot,machine):
    return {'ready':True,'phase':'complete','intent_id':c['intent_id'],
        'candidate_provider_id':c['candidate_provider_id'],'generation':c['generation'],
        'config_hash':c['config_hash'],'boot_id':boot,'previous_boot_id':marker['boot_id'],
        'machine_id_sha256':hashlib.sha256(machine.encode()).hexdigest(),
        'previous_machine_id_sha256':hashlib.sha256(marker['old_machine_id'].encode()).hexdigest()}
def audit_clean_hooks(c):
    expected=c.get('clean_hook_sha256',{})
    if not isinstance(expected,dict):raise ValueError('clean_hook_inventory')
    found={}
    if CLEAN_HOOKS.exists() or CLEAN_HOOKS.is_symlink():
        s=CLEAN_HOOKS.lstat()
        if CLEAN_HOOKS.is_symlink() or not stat.S_ISDIR(s.st_mode) or s.st_uid!=0 or s.st_mode&0o022:raise ValueError('clean_hook_directory')
        for p in CLEAN_HOOKS.iterdir():
            s=p.lstat()
            if p.is_symlink():raise ValueError('clean_hook_symlink')
            if not s.st_mode&0o111:continue
            if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o022 or s.st_size>65536:raise ValueError('clean_hook_untrusted')
            found[p.name]=hashlib.sha256(p.read_bytes()).hexdigest()
    if found!=expected:raise ValueError('clean_hook_inventory')
def step():
    c=read_json(CONFIG);g=read_json(GUARD)
    if c.get('schema')!='servernet.relay.bootstrap.guest.v1' or c.get('profile')!='restored':raise ValueError('restore_profile')
    if not re.fullmatch(r'[0-9a-f]{40}',c.get('intent_id','')):raise ValueError('restore_intent')
    expected=c.get('candidate_provider_id');source=c.get('source_identity',{})
    if not expected or expected==source.get('provider_id') or metadata_id()!=expected:raise ValueError('not_candidate')
    dmi=DMI.read_text().strip().lower()
    if not UUID.fullmatch(dmi) or set(dmi.replace('-','')) in ({'0'},{'f'}):raise ValueError('dmi')
    dmi_hash=hashlib.sha256(dmi.encode()).hexdigest()
    if (g.get('schema')!='servernet.relay.generation.v1' or g.get('fenced') is not True
        or g.get('service_id')!=c.get('service_id') or g.get('provider_id')!=source.get('provider_id') or g.get('generation')!=source.get('generation')
        or g.get('config_hash')!=source.get('config_hash') or not re.fullmatch(r'[0-9a-f]{64}',g.get('machine_uuid_sha256',''))
        or g['machine_uuid_sha256']==dmi_hash):raise ValueError('inherited_fence_required')
    units=g.get('units')
    if not isinstance(units,list) or not 1<=len(units)<=128 or len(set(units))!=len(units):raise ValueError('writer_inventory')
    for unit in units:
        if not isinstance(unit,str) or not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_.@:-]{0,119}\.service',unit):raise ValueError('writer_unit')
        result=command(['/usr/bin/systemctl','is-active',unit])
        if result.stdout.strip() not in (b'inactive',b'failed') or result.returncode not in (3,):raise ValueError('business_not_blocked')
    boot=BOOT.read_text().strip().lower();machine=MACHINE.read_text().strip().lower()
    if not UUID.fullmatch(boot) or boot.replace('-','')=='0'*32:raise ValueError('boot_identity')
    directory(MARKERS)
    current=MARKERS/c['intent_id'];directory(current)
    path=current/'machine-id.json'
    scope=hashlib.sha256(json.dumps(c,sort_keys=True,separators=(',',':')).encode()).hexdigest()
    marker=read_json(path) if path.exists() else None
    if marker:
        if (marker.get('schema')!='servernet.relay.machine-id.marker.v1' or marker.get('scope')!=scope
            or any(marker.get(k)!=c.get(k) for k in ('service_id','intent_id','candidate_provider_id','generation','config_hash'))):raise ValueError('machine_scope_conflict')
        if marker.get('phase')=='complete':
            if machine!=marker.get('new_machine_id') or not machine_valid(machine):raise ValueError('machine_identity_changed')
            if boot==marker.get('boot_id'):raise ValueError('machine_original_boot')
            # A later ordinary reboot under the same inherited quarantine may
            # refresh current boot proof; it never cleans or queues a reboot.
            if marker.get('new_boot_id')!=boot:
                marker['new_boot_id']=boot;save(path,marker)
            return completed(c,marker,boot,machine)
        if marker.get('phase')=='reboot_requested':
            if boot==marker.get('boot_id'):return {'ready':False,'phase':'awaiting_reboot'}
            if not machine_valid(machine) or machine==marker.get('old_machine_id'):raise ValueError('machine_not_regenerated')
            marker.update(phase='complete',new_machine_id=machine,new_boot_id=boot);save(path,marker)
            return completed(c,marker,boot,machine)
        raise ValueError('machine_cleanup_requires_reconciliation')
    if not machine_valid(machine):raise ValueError('initial_machine_identity')
    audit_clean_hooks(c)
    marker={'schema':'servernet.relay.machine-id.marker.v1','scope':scope,
        **{k:c[k] for k in ('service_id','intent_id','candidate_provider_id','generation','config_hash')},
        'phase':'cleanup_started','boot_id':boot,'old_machine_id':machine}
    save(path,marker)
    result=command(['/usr/bin/cloud-init','clean','--machine-id'],30)
    if result.returncode!=0 or MACHINE.read_text().strip()!='uninitialized':raise ValueError('machine_clean_failed')
    marker['phase']='reboot_requested';save(path,marker)
    result=command(['/usr/bin/systemctl','reboot'])
    if result.returncode!=0:raise ValueError('machine_reboot_not_queued')
    return {'ready':False,'phase':'awaiting_reboot'}
def main():
    os.umask(0o077)
    try:
        result=step();print(json.dumps({'schema':'servernet.relay.machine-id.v1',**result},separators=(',',':')))
        return 0
    except Exception:
        print('{"schema":"servernet.relay.machine-id.v1","ready":false,"phase":"blocked"}')
        return 1
if __name__=='__main__':sys.exit(main())
'''


class BootstrapStore:
    def __init__(self, config: dict, *, runner=_run):
        self.config, self.runner = config, runner
        root = Path(config['state_dir']); _private(root,directory=True)
        if not root.is_absolute():raise BootstrapError('absolute_bootstrap_state_directory')
        self.root = root/'bootstrap'
        _directory(self.root)
        entry = config.get('bootstrap',{})
        controller = _key_file(entry['controller_public_key_file'])
        iran = _key_file(entry['iran_probe_public_key_file'])
        if controller == iran: raise BootstrapError('distinct_operator_public_keys_required')
        source_public_path=Path(entry['source_public_host_key_file'])
        source_known_path=Path(entry['source_known_hosts_file'])
        if not source_public_path.is_absolute() or not source_known_path.is_absolute():
            raise BootstrapError('source_host_pin_absolute_paths')
        self.source_public=public_key(_read(source_public_path,1024).decode().strip())
        source_known=_read(source_known_path,4096)
        try:
            lines=source_known.decode('ascii').strip().splitlines()
            host,keytext=lines[0].split(maxsplit=1)
            source_ip=ipaddress.ip_address(host)
        except (ValueError,IndexError,UnicodeDecodeError):
            raise BootstrapError('source_host_pin_format') from None
        if (len(lines)!=1 or source_ip.version!=4 or not source_ip.is_global or str(source_ip)!=host
                or public_key(keytext)!=self.source_public):
            raise BootstrapError('source_host_pin_mismatch')
        self.source_pin_ip=host
        self.source_pin_sha256=hashlib.sha256(source_known).hexdigest()
        self.clean_hooks=entry.get('clean_hook_sha256',{})
        if (not isinstance(self.clean_hooks,dict) or len(self.clean_hooks)>16
                or any(not isinstance(name,str) or not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_.-]{0,95}',name)
                       or not isinstance(digest,str) or not HASH.fullmatch(digest)
                       for name,digest in self.clean_hooks.items())):
            raise BootstrapError('clean_hook_allowlist')
        self.authorizations = []
        for key,addresses in ((controller,_sources(entry['controller_source_ips'])),
                              (iran,_sources(entry['iran_probe_source_ips']))):
            self.authorizations.append('from="'+','.join(addresses)+'",no-agent-forwarding,no-port-forwarding,no-X11-forwarding,no-pty '+key)

    @contextlib.contextmanager
    def _locked(self, intent_id):
        if not isinstance(intent_id,str) or not INTENT.fullmatch(intent_id):
            raise BootstrapError('invalid_create_intent_id')
        directory = self.root/intent_id
        _directory(directory)
        fd = os.open(directory/'lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
        try:
            _private(directory/'lock')
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
            yield directory
        finally: os.close(fd)

    def _scope(self, intent: dict, source: dict) -> dict:
        policy = self.config.get('policy',{})
        source_id = core.identity(source)
        if (intent.get('kind')!='CREATE' or source_id['provider']!='hetzner'
                or source_id['project_id']!=policy.get('project_id')
                or not re.fullmatch(r'[1-9][0-9]{0,19}',source_id['provider_id'])
                or not isinstance(source.get('config_hash'),str) or not HASH.fullmatch(source['config_hash'])):
            raise BootstrapError('bootstrap_source_scope')
        payload=intent.get('payload',{});option=payload.get('option',{})
        source_ip=source.get('ipv4')
        binding=self.config.get('binding',{})
        if source_ip is None and binding.get('provider_id')==source_id['provider_id']:
            source_ip=binding.get('ipv4')
        if source_ip!=self.source_pin_ip:
            raise BootstrapError('source_host_pin_resource_ip')
        if (any(option.get(k)!=policy.get(k) for k in ('owner_id','service_id','project_id'))
                or option.get('provider')!='hetzner' or option.get('architecture')!='x86'
                or payload.get('next_generation')!=source_id['generation']+1
                or not re.fullmatch(r'[1-9][0-9]{0,19}',str(option.get('image','')))
                or not isinstance(option.get('config_hash'),str) or not HASH.fullmatch(option['config_hash'])
                or option.get('location') not in ('fsn1','nbg1','hel1','ash','hil')):
            raise BootstrapError('bootstrap_create_scope')
        return {'schema':'servernet.relay.bootstrap.scope.v1','intent_id':intent['id'],
            'owner_id':policy['owner_id'],'service_id':policy['service_id'],
            'source_identity':{**source_id,'config_hash':source['config_hash']},
            'generation':payload['next_generation'],'image':str(option['image']),
            'location':option['location'],'config_hash':option['config_hash'],
            'source_ipv4':source_ip,'source_public_host_key':self.source_public,
            'source_known_hosts_sha256':self.source_pin_sha256,
            'clean_hook_sha256':self.clean_hooks,
            'authorizations':self.authorizations}

    def _keys(self, directory: Path) -> tuple[str,str]:
        private,public = directory/'host_ed25519',directory/'host_ed25519.pub'
        if not private.exists():
            if public.exists() or private.is_symlink(): raise BootstrapError('partial_host_key_untrusted')
            info=Path(KEYGEN).lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_mode&0o022:
                raise BootstrapError('keygen_binary_untrusted')
            code,_=self.runner([KEYGEN,'-q','-t','ed25519','-N','','-C','servernet-relay-host','-f',str(private)])
            if code!=0: raise BootstrapError('host_key_generation_failed')
        raw=_read(private,4096).decode('ascii')
        if not raw.startswith('-----BEGIN OPENSSH PRIVATE KEY-----\n') or not raw.endswith('-----END OPENSSH PRIVATE KEY-----\n'):
            raise BootstrapError('private_host_key_format')
        code,derived=self.runner([KEYGEN,'-y','-f',str(private)])
        if code!=0: raise BootstrapError('host_key_validation_failed')
        key=public_key(derived.decode('ascii').strip())
        if public.exists():
            # ssh-keygen creates the non-secret .pub with mode0644. Tighten only
            # this generated public file inside our private intent directory.
            info=public.lstat()
            if public.is_symlink() or not stat.S_ISREG(info.st_mode) or info.st_uid!=os.geteuid() or info.st_mode&0o022:
                raise BootstrapError('public_host_key_untrusted')
            if public_key(public.read_text().strip())!=key: raise BootstrapError('host_keypair_mismatch')
            os.chmod(public,0o600)
        else: _immutable(public,(key+'\n').encode())
        # Explicit durability of ssh-keygen's output before returning an artifact
        # to any provider caller; no assumption about ssh-keygen's own fsyncs.
        for path in (private,public):
            fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
            try: os.fsync(fd)
            finally: os.close(fd)
        fd=os.open(directory,os.O_DIRECTORY)
        try: os.fsync(fd)
        finally: os.close(fd)
        return raw,key

    def _document(self, scope: dict, private: str, public: str, profile: str, binding=None) -> bytes:
        guest={'schema':'servernet.relay.bootstrap.guest.v1','profile':profile,
               'intent_id':scope['intent_id'],'service_id':scope['service_id'],'source_identity':scope['source_identity'],
               'generation':scope['generation'],'config_hash':scope['config_hash']}
        if binding: guest['candidate_provider_id']=binding['identity']['provider_id']
        document={'disable_root':False,'ssh_pwauth':False,'allow_public_ssh_keys':False,
            'ssh_deletekeys':profile=='trial',
            'ssh_quiet_keygen':True,'ssh_publish_hostkeys':{'enabled':False},
            'ssh':{'emit_keys_to_console':False},
            'users':[{'name':'root','lock_passwd':True,'ssh_authorized_keys':scope['authorizations']}],
            'bootcmd':[['/usr/bin/install','-d','-m','0700','/root/.ssh']],
            'write_files':[
                {'path':'/root/.ssh/authorized_keys','owner':'root:root','permissions':'0600',
                 'content':'\n'.join(scope['authorizations'])+'\n'},
                {'path':'/etc/ssh/sshd_config.d/00-servernet-relay-bootstrap.conf','owner':'root:root','permissions':'0600',
                 'content':'PasswordAuthentication no\nKbdInteractiveAuthentication no\nPermitRootLogin prohibit-password\nPubkeyAuthentication yes\nAuthenticationMethods publickey\nAuthorizedKeysFile .ssh/authorized_keys\nAuthorizedKeysCommand none\nTrustedUserCAKeys none\nHostbasedAuthentication no\nGSSAPIAuthentication no\n'},
                {'path':'/etc/servernet/relay-bootstrap.json','owner':'root:root','permissions':'0600',
                 'content':canonical_json(guest).decode()}]}
        if profile=='trial':
            document['ssh_keys']={'ed25519_private':private,'ed25519_public':public}
        if profile=='restored':
            if not binding: raise BootstrapError('restored_candidate_binding_required')
            # Preserve snapshot SSH/SFTP policy, account credentials and its
            # already enrolled forced-command controller/IR keys exactly.
            # disable_root:false only prevents cloud-init's default root-key
            # disabling; no keys are imported and no account is rewritten.
            document.pop('ssh_pwauth')
            document.pop('users')
            document.pop('bootcmd')
            # No account/password/SSH module or inherited per-boot/per-instance
            # business provisioner is replayed after clearing cloud-init state.
            # Metadata/network discovery occurs before these module stages.
            document['cloud_init_modules']=['write_files']
            document['cloud_config_modules']=['runcmd']
            document['cloud_final_modules']=['scripts_user']
            guest['clean_hook_sha256']=scope['clean_hook_sha256']
            document['write_files']=[entry for entry in document['write_files']
                                     if entry['path']=='/etc/servernet/relay-bootstrap.json']
            document['write_files'][0]['content']=canonical_json(guest).decode()
            document['write_files'].append({'path':'/usr/local/lib/servernet-relay-recovery/machine_id_recovery.py',
                'owner':'root:root','permissions':'0700','content':MACHINE_ID_HELPER})
            document['write_files'].append({'path':'/etc/systemd/system/'+MACHINE_ID_UNIT,
                'owner':'root:root','permissions':'0600','content':MACHINE_ID_UNIT_CONTENT})
            # Never clean cloud-init from its currently running final module.
            # A service's Wants relation adds no ordering; upstream recommends
            # After/Wants=cloud-init.target for work after cloud-init finishes.
            # WantedBy=cloud-final.service is intentionally a service rather
            # than a target to avoid an implicit ordering cycle. Nonblocking
            # start avoids waiting for this very runcmd to finish. The durable
            # enablement invokes the helper again on the following boot.
            document['runcmd']=[['/usr/bin/systemctl','daemon-reload'],
                ['/usr/bin/systemctl','enable',MACHINE_ID_UNIT],
                ['/usr/bin/systemctl','--no-block','start',MACHINE_ID_UNIT]]
        raw=b'#cloud-config\n'+canonical_json(document)+b'\n'
        if len(raw)>MAX_USER_DATA: raise BootstrapError('bootstrap_userdata_too_large')
        return raw

    def prepare(self, intent: dict, source_identity: dict) -> BootstrapArtifact:
        scope=self._scope(intent,source_identity)
        with self._locked(intent['id']) as directory:
            _immutable(directory/'scope.json',canonical_json(scope))
            private,public=self._keys(directory)
            self._save_profile(directory,scope,private,public,'trial')
            return self._artifact(directory,'trial')

    def _save_profile(self,directory,scope,private,public,profile,binding=None):
        raw=self._document(scope,private,public,profile,binding)
        _immutable(directory/(profile+'.yaml'),raw)
        public_blob=base64.b64decode(public.split()[1])
        metadata={'schema':'servernet.relay.bootstrap.artifact.v1','intent_id':scope['intent_id'],
            **{k:scope[k] for k in ('owner_id','service_id','source_identity','generation','image','location','config_hash')},
            'profile':profile,'scope_hash':_digest(scope),'user_data_sha256':hashlib.sha256(raw).hexdigest(),
            'public_host_key':public,'public_host_fingerprint':'SHA256:'+base64.b64encode(hashlib.sha256(public_blob).digest()).decode().rstrip('='),
            'host_key_origin':'source' if profile=='restored' else 'trial',
            'machine_id_receipt_required':profile=='restored','machine_id_ready':False}
        _immutable(directory/(profile+'.json'),canonical_json(metadata))

    def bind_candidate(self,intent_id: str,candidate_identity: dict,ipv4: str) -> BootstrapArtifact:
        candidate=core.identity(candidate_identity)
        try: address=ipaddress.ip_address(ipv4)
        except (ValueError,TypeError): raise BootstrapError('candidate_public_ipv4') from None
        if address.version!=4 or not address.is_global or str(address)!=ipv4:
            raise BootstrapError('candidate_public_ipv4')
        with self._locked(intent_id) as directory:
            scope=json.loads(_read(directory/'scope.json'))
            if (candidate['provider']!='hetzner' or candidate['project_id']!=scope['source_identity']['project_id']
                    or candidate['generation']!=scope['generation']
                    or not re.fullmatch(r'[1-9][0-9]{0,19}',candidate['provider_id'])
                    or candidate['provider_id']==scope['source_identity']['provider_id']):
                raise BootstrapError('candidate_binding_scope')
            trial=json.loads(_read(directory/'trial.json'));public=trial['public_host_key']
            binding={'schema':'servernet.relay.bootstrap.binding.v1','identity':candidate,
                **{k:scope[k] for k in ('owner_id','service_id','source_identity','generation','image','location','config_hash')},
                'intent_id':intent_id,'ipv4':ipv4,'scope_hash':_digest(scope),
                'trial_user_data_sha256':trial['user_data_sha256'],'public_host_key':public,
                'known_hosts_line':ipv4+' '+public}
            _immutable(directory/'candidate.json',canonical_json(binding))
            _immutable(directory/'known_hosts',(binding['known_hosts_line']+'\n').encode())
            return self._artifact(directory,'trial')

    def profile(self,intent_id: str,profile='trial') -> BootstrapArtifact:
        if profile not in ('trial','restored'): raise BootstrapError('bootstrap_profile')
        with self._locked(intent_id) as directory:
            scope=json.loads(_read(directory/'scope.json'))
            if scope['authorizations']!=self.authorizations: raise BootstrapError('bootstrap_authorizations_changed')
            if scope['clean_hook_sha256']!=self.clean_hooks:raise BootstrapError('bootstrap_clean_hooks_changed')
            if (scope['source_public_host_key']!=self.source_public or scope['source_ipv4']!=self.source_pin_ip
                    or scope['source_known_hosts_sha256']!=self.source_pin_sha256):
                raise BootstrapError('bootstrap_source_pin_changed')
            private,public=self._keys(directory)
            binding=json.loads(_read(directory/'candidate.json')) if profile=='restored' else None
            if binding:
                self._validate_binding(directory,scope,binding,public)
            if profile=='restored':
                public=scope['source_public_host_key']
                _immutable(directory/'known_hosts-restored',(binding['ipv4']+' '+public+'\n').encode())
            self._save_profile(directory,scope,private,public,profile,binding)
            return self._artifact(directory,profile)

    def _artifact(self,directory,profile):
        metadata=json.loads(_read(directory/(profile+'.json')))
        scope=json.loads(_read(directory/'scope.json'))
        if (metadata.get('schema')!='servernet.relay.bootstrap.artifact.v1'
                or metadata.get('intent_id')!=directory.name or metadata.get('profile')!=profile
                or metadata.get('scope_hash')!=_digest(scope)
                or any(metadata.get(k)!=scope.get(k) for k in ('owner_id','service_id','source_identity','generation','image','location','config_hash'))
                or metadata.get('host_key_origin')!=('source' if profile=='restored' else 'trial')
                or metadata.get('machine_id_receipt_required') is not (profile=='restored')
                or metadata.get('machine_id_ready') is not False):
            raise BootstrapError('bootstrap_artifact_scope')
        public=public_key(metadata['public_host_key'])
        expected_fingerprint='SHA256:'+base64.b64encode(hashlib.sha256(base64.b64decode(public.split()[1])).digest()).decode().rstrip('=')
        if metadata.get('public_host_fingerprint')!=expected_fingerprint:
            raise BootstrapError('bootstrap_public_fingerprint')
        raw=_read(directory/(profile+'.yaml'),MAX_USER_DATA)
        if hashlib.sha256(raw).hexdigest()!=metadata['user_data_sha256']:
            raise BootstrapError('bootstrap_userdata_hash_mismatch')
        document=json.loads(raw.removeprefix(b'#cloud-config\n'))
        trial_public=public_key(_read(directory/'host_ed25519.pub').decode().strip())
        if (not raw.startswith(b'#cloud-config\n')
                or (profile=='trial' and (trial_public!=public
                    or document.get('ssh_keys',{}).get('ed25519_public')!=public
                    or document.get('ssh_keys',{}).get('ed25519_private')!=_read(directory/'host_ed25519',4096).decode()))
                or (profile=='restored' and (public!=scope['source_public_host_key']
                    or 'ssh_keys' in document or document.get('ssh_deletekeys') is not False
                    or b'-----BEGIN OPENSSH PRIVATE KEY-----' in raw))):
            raise BootstrapError('bootstrap_host_identity_mismatch')
        if (directory/'candidate.json').exists():
            self._validate_binding(directory,scope,json.loads(_read(directory/'candidate.json')),trial_public)
        known_hosts=directory/('known_hosts-restored' if profile=='restored' else 'known_hosts')
        if profile=='restored':
            binding=json.loads(_read(directory/'candidate.json'))
            if _read(known_hosts)!=(binding['ipv4']+' '+public+'\n').encode():
                raise BootstrapError('restored_host_pin_mismatch')
        return BootstrapArtifact(metadata['intent_id'],profile,str(directory/(profile+'.yaml')),
            metadata['user_data_sha256'],metadata['public_host_key'],metadata['public_host_fingerprint'],
            metadata['machine_id_receipt_required'],str(known_hosts) if known_hosts.exists() else None,
            metadata['host_key_origin'])

    def _validate_binding(self,directory,scope,binding,public):
        candidate=core.identity(binding.get('identity',{}))
        try: address=ipaddress.ip_address(binding.get('ipv4'))
        except (ValueError,TypeError): raise BootstrapError('candidate_binding_scope') from None
        line=str(address)+' '+public
        if (binding.get('schema')!='servernet.relay.bootstrap.binding.v1'
                or binding.get('scope_hash')!=_digest(scope) or address.version!=4 or not address.is_global
                or binding.get('intent_id')!=scope['intent_id'] or binding.get('public_host_key')!=public
                or any(binding.get(k)!=scope.get(k) for k in ('owner_id','service_id','source_identity','generation','image','location','config_hash'))
                or binding.get('trial_user_data_sha256')!=json.loads(_read(directory/'trial.json'))['user_data_sha256']
                or str(address)!=binding.get('ipv4') or candidate['provider']!='hetzner'
                or candidate['project_id']!=scope['source_identity']['project_id']
                or candidate['generation']!=scope['generation']
                or not re.fullmatch(r'[1-9][0-9]{0,19}',candidate['provider_id'])
                or candidate['provider_id']==scope['source_identity']['provider_id']
                or binding.get('known_hosts_line')!=line
                or _read(directory/'known_hosts')!=(line+'\n').encode()):
            raise BootstrapError('candidate_binding_scope')

    def read_user_data(self,artifact: BootstrapArtifact) -> str:
        with self._locked(artifact.intent_id) as directory:
            current=self._artifact(directory,artifact.profile)
            if current!=artifact: raise BootstrapError('bootstrap_artifact_changed')
            return _read(Path(current.user_data_path),MAX_USER_DATA).decode()
