#!/usr/bin/env python3
"""Narrow forced-SSH lifecycle for guarded full-disk relay clones.

Install generation_guard.py and its exact ExecCondition on the ORIGINAL before
creating any snapshot. Merely installing the drop-ins never stops/restarts a
running service. A clone's DMI UUID does not match the copied root identity, so
the guarded writers cannot auto-start. SSH/network/recovery are never guarded.

Only fixed inspect/fence/adopt_clone/activate/reconcile JSON operations exist.
Every request is fresh HMAC authenticated in addition to SSH authentication;
no command, unit, executable, metadata URL or filesystem path comes from it.
fence durably journals intent and fenced=True BEFORE stopping any writer.
Docker must have exactly the locally reviewed full container IDs; explicit
container stop precedes daemon stop, including live-restore installations.
adopt_clone requires exact fixed Hetzner instance metadata + a different DMI;
it writes a new identity still fenced. activate requires that durable adoption
and a fresh WHM attestation of the old fence proof, then starts only reviewed
units/containers. reconcile is observation-only, never an implicit retry.

Config /etc/servernet/relay-lifecycle.json (root 0600)::
 {schema:"servernet.relay.lifecycle.v1",
  identity_file:"/etc/servernet/relay-generation.json",
  controller_key_file:"/etc/servernet/relay-lifecycle.key",
  work_dir:"/var/lib/servernet-relay-lifecycle",guard_sha256:HEX64,
  docker_containers:[FULL_64_HEX_ID,...]}

Signed request common keys: schema=servernet.relay.lifecycle.request.v1, op,
request_id/nonce (32 hex), service_id/provider_id (decimal strings), generation,
config_hash, issued_at/expires_at (max90s), signature. Mutations/reconcile also
need intent_id (40 hex). adopt_clone additionally binds old_provider_id,
old_generation,old_config_hash,old_machine_uuid_sha256. activate additionally
binds old_provider_id,old_generation,old_fence_proof_digest,old_fence_proof_at.
Signature = HMAC-SHA256(key, b'SN-RELAY-LIFECYCLE/1\\n'+canonical(unsigned)).
Response never claims global consistency, successful migration or safe deletion.

Fixed metadata GET is documented at https://docs.hetzner.cloud/reference/cloud:
http://169.254.169.254/hetzner/v1/metadata/instance-id . No EC2-compatible route,
proxy environment, redirect, arbitrary URL or provider API credential is used.
"""
from __future__ import annotations

import contextlib
import fcntl
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import sqlite3
import stat
import sys
import time
import urllib.error
import urllib.request

try:
    from . import generation_guard as guard
    from .agent import _bounded_process
except ImportError:
    import generation_guard as guard
    from agent import _bounded_process


SCHEMA = 'servernet.relay.lifecycle.v1'
REQUEST_SCHEMA = 'servernet.relay.lifecycle.request.v1'
RESULT_SCHEMA = 'servernet.relay.lifecycle.result.v1'
DEFAULT_CONFIG = '/etc/servernet/relay-lifecycle.json'
GUARD_PROGRAM = '/usr/local/lib/servernet-relay-recovery/generation_guard.py'
DROPIN_NAME = '50-servernet-generation-guard.conf'
CONDITION = '+/usr/bin/python3 '+GUARD_PROGRAM+' --config '+guard.DEFAULT_CONFIG
DROPIN = '[Service]\nExecCondition='+CONDITION+'\n'
METADATA_URL = 'http://169.254.169.254/hetzner/v1/metadata/instance-id'
OPS = frozenset({'inspect', 'fence', 'adopt_clone', 'activate', 'reconcile', 'quarantine_inspect'})
MUTATIONS = frozenset({'fence', 'adopt_clone', 'activate'})
HEX64 = re.compile(r'\A[a-f0-9]{64}\Z')
HEX40 = re.compile(r'\A[a-f0-9]{40}\Z')
HEX32 = re.compile(r'\A[a-f0-9]{32}\Z')
NUMBER = re.compile(r'\A[1-9][0-9]{0,19}\Z')
MAX_BODY = 16384


class LifecycleError(ValueError):
    pass


def canonical(value: dict) -> bytes:
    try:
        body = json.dumps(value, sort_keys=True, separators=(',', ':'),
                          ensure_ascii=True, allow_nan=False).encode('ascii')
    except (TypeError, ValueError):
        raise LifecycleError('invalid_json') from None
    if len(body) > 65536:
        raise LifecycleError('message_size')
    return body


def decode(raw: bytes) -> dict:
    if not isinstance(raw, bytes) or len(raw) > MAX_BODY:
        raise LifecycleError('request_size')
    def pairs(items):
        obj = {}
        for key, value in items:
            if key in obj: raise LifecycleError('duplicate_json_key')
            obj[key] = value
        return obj
    try:
        obj = json.loads(raw, object_pairs_hook=pairs,
                         parse_constant=lambda value: (_ for _ in ()).throw(ValueError()))
    except (ValueError, RecursionError, UnicodeError):
        raise LifecycleError('request_json') from None
    if not isinstance(obj, dict): raise LifecycleError('request_json')
    return obj


def _private(path: Path, *, root_owned=True, directory=False, readable=False) -> Path:
    if not path.is_absolute() or '..' in path.parts:
        raise LifecycleError('private_path')
    for index, item in enumerate((path, *path.parents)):
        info = item.lstat()
        kind = stat.S_ISDIR if directory or index else stat.S_ISREG
        if item.is_symlink() or not kind(info.st_mode): raise LifecycleError('private_path')
        if root_owned and info.st_uid != 0: raise LifecycleError('private_owner')
        forbidden = 0o022 if index or readable else 0o077
        if info.st_mode & forbidden and (root_owned or index == 0):
            raise LifecycleError('private_mode')
        if index == 0 and not directory and info.st_nlink != 1:
            raise LifecycleError('private_hardlink')
    return path


def _read(path: Path, limit=65536) -> bytes:
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as source:
        body = source.read(limit+1)
    if len(body) > limit: raise LifecycleError('private_file_size')
    return body


def validate_config(cfg: dict, *, root_owned=True) -> dict:
    if (not isinstance(cfg, dict) or cfg.get('schema') != SCHEMA
            or set(cfg) != {'schema', 'identity_file', 'controller_key_file', 'work_dir', 'guard_sha256', 'docker_containers'}
            or cfg.get('identity_file') != guard.DEFAULT_CONFIG
            or cfg.get('controller_key_file') != '/etc/servernet/relay-lifecycle.key'
            or cfg.get('work_dir') != '/var/lib/servernet-relay-lifecycle'
            or not isinstance(cfg.get('guard_sha256'), str) or not HEX64.fullmatch(cfg['guard_sha256'])):
        raise LifecycleError('config_schema')
    ids = cfg['docker_containers']
    if (not isinstance(ids, list) or len(ids) > 16
            or any(not isinstance(value, str) or not HEX64.fullmatch(value) for value in ids)
            or len(set(ids)) != len(ids)):
        raise LifecycleError('docker_inventory_config')
    return cfg


def load_config(path=DEFAULT_CONFIG) -> dict:
    if str(path) != DEFAULT_CONFIG: raise LifecycleError('config_path')
    _private(Path(path))
    cfg = decode(_read(Path(path), MAX_BODY))
    validate_config(cfg)
    return cfg


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise LifecycleError('metadata_redirect')


def metadata_provider_id() -> str:
    """One bounded GET to the fixed link-local Hetzner metadata service."""
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    try:
        with opener.open(urllib.request.Request(METADATA_URL, method='GET'), timeout=2) as response:
            if response.status != 200: raise LifecycleError('metadata_status')
            body = response.read(33)
        value = body.decode('ascii').strip()
    except (OSError, urllib.error.URLError, UnicodeError):
        raise LifecycleError('metadata_unavailable') from None
    if not NUMBER.fullmatch(value): raise LifecycleError('metadata_identity')
    return value


def sign_request(request: dict, key: bytes) -> dict:
    if not isinstance(key, bytes) or not 32 <= len(key) <= 256:
        raise LifecycleError('key_length')
    unsigned = {key_: value for key_, value in request.items() if key_ != 'signature'}
    return {**unsigned, 'signature': hmac.new(key, b'SN-RELAY-LIFECYCLE/1\n'+canonical(unsigned), hashlib.sha256).hexdigest()}


def validate_request(request: dict, key: bytes, now: int) -> dict:
    common = {'schema', 'op', 'request_id', 'service_id', 'provider_id', 'generation',
              'config_hash', 'issued_at', 'expires_at', 'nonce', 'signature'}
    if not isinstance(request, dict) or request.get('schema') != REQUEST_SCHEMA or request.get('op') not in OPS:
        raise LifecycleError('request_schema')
    op = request['op']; fields = set(common)
    if op != 'inspect': fields.add('intent_id')
    if op in {'adopt_clone', 'quarantine_inspect'}:
        fields |= {'old_provider_id', 'old_generation', 'old_config_hash', 'old_machine_uuid_sha256'}
    if op == 'activate':
        fields |= {'old_provider_id', 'old_generation', 'old_fence_proof_digest', 'old_fence_proof_at'}
    if set(request) != fields: raise LifecycleError('request_fields')
    for name in ('request_id', 'nonce'):
        if not isinstance(request[name], str) or not HEX32.fullmatch(request[name]): raise LifecycleError('request_nonce')
    for name in ('service_id', 'provider_id') + (('old_provider_id',) if op in {'activate', 'adopt_clone', 'quarantine_inspect'} else ()):
        if not isinstance(request[name], str) or not NUMBER.fullmatch(request[name]): raise LifecycleError('request_id')
    for name in ('generation',) + (('old_generation',) if op in {'activate', 'adopt_clone', 'quarantine_inspect'} else ()):
        if type(request[name]) is not int or request[name] < 1: raise LifecycleError('request_generation')
    for name in ('config_hash',) + (('old_config_hash', 'old_machine_uuid_sha256') if op in {'adopt_clone', 'quarantine_inspect'}
                                 else ('old_fence_proof_digest',) if op == 'activate' else ()):
        if not isinstance(request[name], str) or not HEX64.fullmatch(request[name]): raise LifecycleError('request_hash')
    if op != 'inspect' and (not isinstance(request['intent_id'], str) or not HEX40.fullmatch(request['intent_id'])):
        raise LifecycleError('request_intent')
    issued, expiry = request['issued_at'], request['expires_at']
    if (type(now) is not int or now < 0 or type(issued) is not int or issued < 0 or type(expiry) is not int
            or issued > now+5 or expiry <= now or not 0 < expiry-issued <= 90 or now-issued > 90):
        raise LifecycleError('request_expired')
    if op == 'activate' and (type(request['old_fence_proof_at']) is not int
                            or not 0 <= now-request['old_fence_proof_at'] <= 90):
        raise LifecycleError('old_fence_proof_stale')
    signature = request.get('signature')
    if (not isinstance(signature, str) or not HEX64.fullmatch(signature)
            or not hmac.compare_digest(sign_request(request, key)['signature'], signature)):
        raise LifecycleError('request_authentication')
    return request


class Lifecycle:
    def __init__(self, config: dict, *, runner=_bounded_process, metadata=metadata_provider_id,
                 machine=guard.machine_uuid_hash, clock=time.time, root_owned=True,
                 identity_path=None, work_dir=None, key=None, guard_check=None, docker_disk=None, bootstrap_read=None,
                 monotonic=time.monotonic, wait=time.sleep):
        validate_config(config, root_owned=root_owned)
        self.config = config; self.runner = runner; self.metadata = metadata
        self.machine, self.clock, self.root_owned = machine, clock, root_owned
        # Test-only dependency overrides are Python arguments, never wire/config fields.
        self.identity_path = Path(identity_path or config['identity_file'])
        self.root = Path(work_dir or config['work_dir'])
        _private(self.root, directory=True, root_owned=root_owned)
        self.key = key if key is not None else _read(_private(Path(config['controller_key_file']), root_owned=root_owned), 256)
        if not isinstance(self.key, bytes) or not 32 <= len(self.key) <= 256: raise LifecycleError('key_length')
        self.guard_check = guard_check
        self.docker_disk = docker_disk or self._disk_ids
        self.bootstrap_read = bootstrap_read or self._bootstrap_file
        self.monotonic, self.wait = monotonic, wait
        self.path = self.root/'lifecycle.sqlite3'
        if self.path.is_symlink(): raise LifecycleError('state_symlink')
        fd = os.open(self.path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        os.close(fd); _private(self.path, root_owned=root_owned)
        with self._db() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS replay(nonce TEXT PRIMARY KEY,expires INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS intents(id TEXT PRIMARY KEY,op TEXT NOT NULL,hash TEXT NOT NULL,
                    body TEXT NOT NULL,created_at INTEGER NOT NULL,receipt TEXT);
                CREATE TABLE IF NOT EXISTS adoption(id INTEGER PRIMARY KEY CHECK(id=1),body TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS retired(generation INTEGER NOT NULL,provider_id TEXT NOT NULL,
                    machine_hash TEXT NOT NULL,intent_id TEXT NOT NULL,at INTEGER NOT NULL,
                    PRIMARY KEY(generation,provider_id,machine_hash));
                CREATE TABLE IF NOT EXISTS clock(id INTEGER PRIMARY KEY CHECK(id=1),at INTEGER NOT NULL);''')

    def _db(self):
        db = sqlite3.connect(self.path, timeout=0.1); db.row_factory = sqlite3.Row
        db.execute('PRAGMA synchronous=FULL'); db.execute('PRAGMA busy_timeout=100')
        return db

    def _command(self, argv: list[str], timeout=4) -> tuple[int, bytes]:
        try:
            code, raw = self.runner(argv, b'', timeout)
        except Exception:
            raise LifecycleError('local_command_unavailable') from None
        if type(code) is not int or not isinstance(raw, bytes) or len(raw) > 65536:
            raise LifecycleError('local_command_output')
        return code, raw

    def _units(self, identity: dict) -> tuple[dict, bool]:
        units = guard.validate_units(identity['units'])
        if len(units) > 32: raise LifecycleError('writer_unit_limit')
        names = units if 'docker.service' in units else [*units, 'docker.service']
        code, raw = self._command(['/usr/bin/systemctl', 'show', '--no-pager',
            '--property=Id,Names,LoadState,ActiveState,Job,ExecCondition,ExecConditionEx,NeedDaemonReload,DropInPaths,SuccessExitStatus', *names], 5)
        if code: raise LifecycleError('unit_observation_failed')
        blocks = []
        for block in raw.decode('utf-8', 'strict').strip().split('\n\n'):
            values = {}
            for line in block.splitlines():
                if '=' not in line: raise LifecycleError('unit_observation_shape')
                key, value = line.split('=', 1)
                if key in values: raise LifecycleError('unit_observation_shape')
                values[key] = value
            blocks.append(values)
        parsed = {b.get('Id'): b for b in blocks}
        if len(parsed) != len(names) or any(name not in parsed for name in names):
            raise LifecycleError('unit_identity_unverified')
        if 'docker.service' not in units and parsed['docker.service'].get('LoadState') != 'not-found':
            raise LifecycleError('unregistered_docker_daemon')
        installed = True
        for name in units:
            values = parsed[name]
            if values.get('Id') != name or values.get('LoadState') != 'loaded':
                raise LifecycleError('unit_identity_unverified')
            for alias in values.get('Names', '').split(): guard.validate_units([alias])
            if name not in values.get('Names', '').split(): raise LifecycleError('unit_alias_unverified')
            if values.get('SuccessExitStatus', '').strip() not in ('', '0'):
                installed = False
            if values.get('NeedDaemonReload') != 'no': installed = False
            if self.guard_check is not None:
                installed = bool(self.guard_check(name, values)) and installed
                continue
            dropin = Path('/etc/systemd/system')/(name+'.d')/DROPIN_NAME
            try:
                _private(dropin, root_owned=self.root_owned, readable=True)
                _private(Path(GUARD_PROGRAM), root_owned=self.root_owned, readable=True)
                body = _read(dropin).decode('ascii')
                program_hash = hashlib.sha256(_read(Path(GUARD_PROGRAM), 262144)).hexdigest()
                expected = ('path=/usr/bin/python3 ; argv[]=/usr/bin/python3 '+GUARD_PROGRAM+
                            ' --config '+guard.DEFAULT_CONFIG+' ; ignore_errors=no')
                condition = values.get('ExecCondition', '')
                expected_ex = ('path=/usr/bin/python3 ; argv[]=/usr/bin/python3 '+GUARD_PROGRAM+
                               ' --config '+guard.DEFAULT_CONFIG+' ; flags=privileged ;')
                installed = (installed and body == DROPIN and program_hash == self.config['guard_sha256']
                             and str(dropin) in values.get('DropInPaths', '').split() and expected in condition
                             and expected_ex in values.get('ExecConditionEx', ''))
            except (OSError, UnicodeError, LifecycleError): installed = False
        return {name: parsed[name] for name in units}, installed

    def _disk_ids(self) -> list[str]:
        root = Path('/var/lib/docker/containers')
        if not root.exists(): return []
        _private(root, directory=True, root_owned=self.root_owned, readable=True)
        result = []
        for path in root.iterdir():
            info = path.lstat()
            if path.is_symlink() or not stat.S_ISDIR(info.st_mode) or not HEX64.fullmatch(path.name):
                raise LifecycleError('docker_disk_inventory_unverified')
            result.append(path.name)
        return result

    def _docker(self, identity: dict, units: dict) -> dict:
        expected = set(self.config['docker_containers'])
        if 'docker.service' not in identity['units']:
            if expected: raise LifecycleError('docker_unit_missing')
            return {'registered': False, 'exact_inventory': True, 'running_ids': [], 'daemon_inactive': True, 'no_shims': True}
        disk = self.docker_disk()
        if len(disk) != len(set(disk)) or set(disk) != expected:
            raise LifecycleError('docker_disk_inventory_mismatch')
        inactive = units['docker.service'].get('ActiveState') in ('inactive', 'failed')
        running = []
        if not inactive:
            code, raw = self._command(['/usr/bin/docker', 'container', 'ls', '--all', '--no-trunc', '--format', '{{.ID}}'])
            rows = raw.decode('ascii').splitlines()
            if code or any(not HEX64.fullmatch(row) for row in rows) or len(rows) != len(set(rows)) or set(rows) != expected:
                raise LifecycleError('docker_api_inventory_mismatch')
            if expected:
                code, raw = self._command(['/usr/bin/docker', 'container', 'inspect', '--format', '{{.Id}} {{.State.Running}}', *sorted(expected)])
                status = [line.split() for line in raw.decode('ascii').splitlines()]
                if code or len(status) != len(expected) or {v[0] for v in status if len(v) == 2} != expected:
                    raise LifecycleError('docker_state_unverified')
                if any(v[1] not in ('true', 'false') for v in status): raise LifecycleError('docker_state_unverified')
                running = [v[0] for v in status if v[1] == 'true']
        code, raw = self._command(['/usr/bin/pgrep', '-f', '(^|/)(containerd-shim|docker-containerd-shim)([- ]|$)'])
        no_shims = code == 1 and raw.strip() == b''
        if code not in (0, 1): raise LifecycleError('docker_shim_observation_failed')
        return {'registered': True, 'exact_inventory': True, 'running_ids': running,
                'daemon_inactive': inactive, 'no_shims': no_shims}

    def _bootstrap_file(self, path: str, private=True) -> bytes:
        item = Path(path)
        _private(item, root_owned=self.root_owned, readable=not private)
        return _read(item, MAX_BODY)

    def _bootstrap(self, request: dict) -> dict:
        """Read fixed root-private bootstrap facts; never clean/reboot on reads."""
        result = {'machine_id_ready': False}
        try:
            guest = decode(self.bootstrap_read('/etc/servernet/relay-bootstrap.json'))
            if (guest.get('schema') != 'servernet.relay.bootstrap.guest.v1' or guest.get('profile') != 'restored'
                    or any(guest.get(k) != request[k] for k in ('service_id', 'generation', 'config_hash'))
                    or guest.get('candidate_provider_id') != request['provider_id']
                    or not isinstance(guest.get('intent_id'), str) or not HEX40.fullmatch(guest['intent_id'])):
                return result
            source = guest.get('source_identity', {})
            if (not isinstance(source, dict) or any(source.get(k) != request['old_'+k]
                    for k in ('provider_id', 'generation', 'config_hash'))): return result
            marker = decode(self.bootstrap_read('/var/lib/servernet-relay-bootstrap/'+guest['intent_id']+'/machine-id.json'))
            if (marker.get('schema') != 'servernet.relay.machine-id.marker.v1' or marker.get('phase') != 'complete'
                    or marker.get('scope') != hashlib.sha256(canonical(guest)).hexdigest()
                    or any(marker.get(k) != guest[k] for k in ('service_id', 'intent_id', 'candidate_provider_id', 'generation', 'config_hash'))):
                return result
            machine = self.bootstrap_read('/etc/machine-id', False).decode('ascii').strip().lower()
            boot = self.bootstrap_read('/proc/sys/kernel/random/boot_id', False).decode('ascii').strip().lower()
            old_machine, old_boot = marker.get('old_machine_id'), marker.get('boot_id')
            uuid_pattern = r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}'
            if (not re.fullmatch(r'[0-9a-f]{32}', machine) or not re.fullmatch(uuid_pattern, boot)
                    or not isinstance(old_machine, str) or not re.fullmatch(r'[0-9a-f]{32}', old_machine)
                    or not isinstance(old_boot, str) or not re.fullmatch(uuid_pattern, old_boot)
                    or marker.get('new_machine_id') != machine or marker.get('new_boot_id') != boot
                    or machine == '0'*32 or old_machine == '0'*32
                    or boot.replace('-','') == '0'*32 or old_boot.replace('-','') == '0'*32
                    or machine == old_machine or boot == old_boot): return result
            return {'machine_id_ready': True, 'intent_id': guest['intent_id'],
                'scope': marker['scope'], 'machine_id_sha256': hashlib.sha256(machine.encode()).hexdigest(),
                'previous_machine_id_sha256': hashlib.sha256(old_machine.encode()).hexdigest(),
                'boot_id_sha256': hashlib.sha256(boot.encode()).hexdigest(),
                'previous_boot_id_sha256': hashlib.sha256(old_boot.encode()).hexdigest()}
        except Exception:
            return result

    def _quarantine(self, request: dict, identity: dict) -> dict:
        old = {k: request['old_'+k] for k in ('provider_id', 'generation', 'config_hash', 'machine_uuid_sha256')}
        machine = self.machine()
        if (request['service_id'] != identity['service_id'] or not identity['fenced']
                or any(identity[k] != old[k] for k in old) or request['provider_id'] == old['provider_id']
                or request['generation'] != old['generation']+1 or self.metadata() != request['provider_id']
                or machine == old['machine_uuid_sha256'] or guard.check_guard(identity, machine)):
            raise LifecycleError('clone_quarantine_identity_unverified')
        result = self.inspect(request)
        code, raw = self._command(['/usr/bin/systemctl', 'show', '--no-pager',
            '--property=Id,LoadState,ActiveState', 'docker.socket'])
        socket = dict(line.split('=', 1) for line in raw.decode('ascii').splitlines() if '=' in line)
        socket_inactive = (code == 0 and socket.get('Id') == 'docker.socket'
                           and socket.get('LoadState') in ('loaded', 'not-found')
                           and socket.get('ActiveState') in ('inactive', 'failed'))
        code, raw = self._command(['/usr/bin/pgrep', '-f', '(^|/)(containerd-shim|docker-containerd-shim)([- ]|$)'])
        no_shims = code == 1 and raw.strip() == b''
        quarantine = bool(result['guard_installed'] and result['all_units_inactive']
                          and result['docker']['daemon_inactive'] and not result['docker']['running_ids']
                          and socket_inactive and no_shims)
        with self._db() as db:
            row = db.execute('SELECT op,receipt FROM intents WHERE id=?', (request['intent_id'],)).fetchone()
        result.update({k: request[k] for k in ('provider_id', 'generation', 'config_hash')})
        result.update(ok=quarantine, read_only=True, quarantine_verified=quarantine,
            inherited_identity={**old, 'service_id': identity['service_id'], 'fenced': True},
            guard_denies_current_machine=True, docker_socket_inactive=socket_inactive,
            persistent_fence=False, active_generation_verified=False, bootstrap=self._bootstrap(request),
            recorded_operation=row['op'] if row else None, recorded_completion=bool(row and row['receipt']),
            journal_adoption_absent=bool(quarantine and row is None))
        result['observed_at'] = self._fresh(request)
        return result

    def inspect(self, request: dict) -> dict:
        identity = guard.load_identity(self.identity_path, root_owned=self.root_owned)
        machine = self.machine()
        try: metadata = self.metadata()
        except Exception: metadata = None
        units, installed = self._units(identity)
        docker = self._docker(identity, units)
        physical = (machine == identity['machine_uuid_sha256']
                    and (metadata is None or metadata == identity['provider_id']))
        inactive = all(v.get('ActiveState') in ('inactive', 'failed') for v in units.values())
        persistent = bool(physical and installed and identity['fenced'] and inactive
                          and docker['daemon_inactive'] and not docker['running_ids'] and docker['no_shims'])
        active = bool(physical and installed and not identity['fenced']
                      and all(v.get('ActiveState') == 'active' for v in units.values())
                      and set(docker['running_ids']) == set(self.config['docker_containers']))
        jobs_clear = all('Job' in v and v['Job'].strip() in ('', '0') for v in units.values())
        code, raw = self._command(['/usr/bin/systemctl', 'show', '--no-pager',
            '--property=Id,LoadState,ActiveState,Job', 'docker.socket'])
        socket = dict(line.split('=', 1) for line in raw.decode('ascii').splitlines() if '=' in line)
        jobs_clear = bool(jobs_clear and code == 0 and socket.get('Id') == 'docker.socket'
                          and 'Job' in socket and socket['Job'].strip() in ('', '0'))
        persistent = bool(persistent and jobs_clear)
        active = bool(active and jobs_clear)
        with self._db() as db:
            retired = db.execute('SELECT 1 FROM retired WHERE generation=? AND provider_id=? AND machine_hash=?',
                (identity['generation'],identity['provider_id'],identity['machine_uuid_sha256'])).fetchone() is not None
            adopted = db.execute('SELECT body FROM adoption WHERE id=1').fetchone()
        adopted_from = None
        if adopted:
            record = json.loads(adopted[0])
            if record.get('new_identity') == {k:identity[k] for k in ('provider_id','generation','config_hash','machine_uuid_sha256')}:
                adopted_from = record.get('old_identity')
        inventory = hashlib.sha256(canonical({'units':identity['units'],
            'docker_containers':sorted(self.config['docker_containers']), 'guard_sha256':self.config['guard_sha256']})).hexdigest()
        return {'schema': RESULT_SCHEMA, 'request_id': request['request_id'], 'intent_id': request.get('intent_id'),
                'operation': request['op'], 'service_id': identity['service_id'], 'provider_id': identity['provider_id'],
                'generation': identity['generation'], 'config_hash': identity['config_hash'],
                'machine_uuid_sha256': machine, 'metadata_provider_id': metadata, 'observed_at': int(self.clock()),
                'ok': True, 'physical_identity_verified': bool(physical), 'guard_installed': bool(installed),
                'identity_fenced': identity['fenced'], 'all_units_inactive': inactive,
                'units': {k: v.get('ActiveState') for k, v in units.items()}, 'docker': docker,
                'persistent_fence': persistent, 'active_generation_verified': active, 'global_consistency_proven': False,
                'writer_inventory_sha256':inventory,'systemd_jobs_clear':jobs_clear,'current_generation_retired':retired,
                'activation_adoption_verified':bool(physical and adopted_from is not None),'adopted_from':adopted_from,
                'migration_complete': False}

    def _authenticate(self, request: dict):
        now = int(self.clock()); validate_request(request, self.key, now)
        with self._db() as db:
            db.execute('BEGIN IMMEDIATE')
            prior = db.execute('SELECT at FROM clock WHERE id=1').fetchone()
            if prior and now < prior[0]: raise LifecycleError('clock_regression')
            db.execute('DELETE FROM replay WHERE expires<?', (now,))
            if db.execute('SELECT COUNT(*) FROM replay').fetchone()[0] >= 512:
                raise LifecycleError('replay_capacity')
            try: db.execute('INSERT INTO replay VALUES(?,?)', (request['nonce'], request['expires_at']))
            except sqlite3.IntegrityError: raise LifecycleError('replay') from None
            db.execute('INSERT OR REPLACE INTO clock VALUES(1,?)', (now,))

    def _begin(self, request: dict):
        semantic = {k: v for k, v in request.items() if k not in
                    ('signature', 'nonce', 'issued_at', 'expires_at', 'request_id',
                     'old_fence_proof_at', 'old_fence_proof_digest')}
        encoded = canonical(semantic).decode(); digest = hashlib.sha256(encoded.encode()).hexdigest()
        with self._db() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT op,hash,receipt FROM intents WHERE id=?', (request['intent_id'],)).fetchone()
            if row:
                if row['op'] != request['op'] or row['hash'] != digest: raise LifecycleError('intent_conflict')
                return json.loads(row['receipt']) if row['receipt'] else None
            if db.execute('SELECT COUNT(*) FROM intents').fetchone()[0] >= 10000:
                raise LifecycleError('intent_capacity')
            db.execute('INSERT INTO intents VALUES(?,?,?,?,?,NULL)',
                       (request['intent_id'], request['op'], digest, encoded, int(self.clock())))
        return None

    def _fresh(self, request: dict):
        """Do not begin a write/start using an authorization spent in preflight."""
        now = int(self.clock())
        validate_request(request, self.key, now)
        with self._db() as db:
            db.execute('BEGIN IMMEDIATE')
            prior = db.execute('SELECT at FROM clock WHERE id=1').fetchone()
            if prior and now < prior[0]: raise LifecycleError('clock_regression')
            db.execute('INSERT OR REPLACE INTO clock VALUES(1,?)', (now,))
        return now

    def _finish(self, request: dict, result: dict):
        with self._db() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT receipt FROM intents WHERE id=?', (request['intent_id'],)).fetchone()
            if row is None: raise LifecycleError('intent_missing')
            if row[0] is None:
                db.execute('UPDATE intents SET receipt=? WHERE id=?', (canonical(result).decode(), request['intent_id']))

    def _scope(self, request: dict, identity: dict):
        if any(request[k] != identity[k] for k in ('service_id', 'provider_id', 'generation', 'config_hash')):
            raise LifecycleError('identity_scope')
        if self.machine() != identity['machine_uuid_sha256']: raise LifecycleError('physical_identity_mismatch')
        try: metadata = self.metadata()
        except Exception: metadata = None
        if metadata is not None and metadata != identity['provider_id']: raise LifecycleError('metadata_identity_mismatch')

    def _fence(self, request: dict, identity: dict) -> dict:
        self._scope(request, identity)
        units, installed = self._units(identity)
        if not installed: raise LifecycleError('guard_not_installed')
        docker = self._docker(identity, units)
        self._fresh(request)
        self._begin(request)  # Durable intent BEFORE the persistent flag/effects.
        with self._db() as db:
            db.execute('INSERT OR IGNORE INTO retired VALUES(?,?,?,?,?)',
                       (identity['generation'], identity['provider_id'], identity['machine_uuid_sha256'],
                        request['intent_id'], int(self.clock())))
        guard.atomic_identity(self.identity_path, {**identity, 'fenced': True}, root_owned=self.root_owned)
        if docker['running_ids']:
            code, _ = self._command(['/usr/bin/docker', 'container', 'stop', '--time', '3', *docker['running_ids']], 6)
            if code: raise LifecycleError('container_stop_unknown')
        # Stop writers before Docker; only fixed locally reviewed units are used.
        for name in [v for v in reversed(identity['units']) if v != 'docker.service']:
            code, _ = self._command(['/usr/bin/systemctl', 'stop', '--no-block', name])
            if code: raise LifecycleError('writer_stop_unknown')
        if 'docker.service' in identity['units']:
            code, _ = self._command(['/usr/bin/systemctl', 'stop', '--no-block', 'docker.socket', 'docker.service'])
            if code: raise LifecycleError('docker_stop_unknown')
        result = self.inspect(request)
        result['ok'] = result['persistent_fence']
        result['outcome'] = 'fenced' if result['ok'] else 'pending'
        if result['ok']: self._finish(request, result)
        return result

    def _adopt(self, request: dict, identity: dict) -> dict:
        if request['service_id'] != identity['service_id']: raise LifecycleError('service_scope')
        old = {k: request['old_'+k] for k in ('provider_id', 'generation', 'config_hash', 'machine_uuid_sha256')}
        machine = self.machine()
        if (request['provider_id'] == old['provider_id'] or request['generation'] != old['generation']+1
                or self.metadata() != request['provider_id'] or machine == old['machine_uuid_sha256']):
            raise LifecycleError('clone_identity_unverified')
        initial = all(identity[k] == old[k] for k in old)
        resumed = (all(identity[k] == request[k] for k in ('service_id', 'provider_id', 'generation', 'config_hash'))
                   and identity['machine_uuid_sha256'] == machine and identity['fenced'])
        if not initial and not resumed: raise LifecycleError('clone_source_identity_mismatch')
        if resumed:
            with self._db() as db:
                adoption = db.execute('SELECT body FROM adoption WHERE id=1').fetchone()
                pending = db.execute('SELECT op FROM intents WHERE id=?', (request['intent_id'],)).fetchone()
            if adoption:
                recorded = json.loads(adoption[0])
                if recorded.get('old_identity') != old or recorded.get('new_identity') != {
                        k: identity[k] for k in ('provider_id', 'generation', 'config_hash', 'machine_uuid_sha256')}:
                    raise LifecycleError('clone_adoption_history_conflict')
            elif pending is None or pending['op'] != 'adopt_clone':
                raise LifecycleError('clone_adoption_intent_missing')
        units, installed = self._units(identity)
        docker = self._docker(identity, units)
        if not installed or any(v.get('ActiveState') not in ('inactive', 'failed') for v in units.values()) or not docker['daemon_inactive'] or not docker['no_shims']:
            raise LifecycleError('clone_writers_not_quiet')
        self._fresh(request)
        self._begin(request)
        adopted = {**identity, **{k: request[k] for k in ('provider_id', 'generation', 'config_hash')},
                   'machine_uuid_sha256': machine, 'fenced': True}
        guard.atomic_identity(self.identity_path, adopted, root_owned=self.root_owned)
        record = {'intent_id': request['intent_id'], 'old_identity': old,
                  'new_identity': {k: adopted[k] for k in ('provider_id', 'generation', 'config_hash', 'machine_uuid_sha256')}}
        with self._db() as db:
            db.execute('INSERT OR REPLACE INTO adoption VALUES(1,?)', (canonical(record).decode(),))
        result = self.inspect(request); result.update(ok=True, outcome='adopted_fenced')
        if not result['persistent_fence']: raise LifecycleError('adopt_fence_unverified')
        self._finish(request, result)
        return result

    def _activate(self, request: dict, identity: dict) -> dict:
        self._scope(request, identity)
        with self._db() as db:
            row = db.execute('SELECT body FROM adoption WHERE id=1').fetchone()
            retired = db.execute('SELECT 1 FROM retired WHERE generation=? AND provider_id=? AND machine_hash=?',
                                 (identity['generation'], identity['provider_id'], identity['machine_uuid_sha256'])).fetchone()
        if retired: raise LifecycleError('retired_generation_cannot_activate')
        if not row: raise LifecycleError('clone_adoption_missing')
        adoption = json.loads(row[0])
        if (adoption['new_identity'] != {k: identity[k] for k in ('provider_id', 'generation', 'config_hash', 'machine_uuid_sha256')}
                or adoption['old_identity']['provider_id'] != request['old_provider_id']
                or adoption['old_identity']['generation'] != request['old_generation']):
            raise LifecycleError('activation_adoption_mismatch')
        units, installed = self._units(identity)
        if not installed: raise LifecycleError('guard_not_installed')
        self._docker(identity, units)  # Exact local disk/API inventory before any start.
        self._fresh(request)
        self._begin(request)
        self._fresh(request)
        guard.atomic_identity(self.identity_path, {**identity, 'fenced': False}, root_owned=self.root_owned)
        for name in identity['units']:
            self._fresh(request)
            code, _ = self._command(['/usr/bin/systemctl', 'start', '--no-block', name])
            if code: raise LifecycleError('writer_start_unknown')
        if self.config['docker_containers']:
            # --no-block only queues the daemon start. Readiness is observation,
            # and manual-stop/unless-stopped policies still need explicit start.
            deadline = self.monotonic()+8
            while True:
                self._fresh(request)
                code, raw = self._command(['/usr/bin/docker', 'container', 'ls', '--all', '--no-trunc', '--format', '{{.ID}}'], 2)
                if code == 0:
                    rows = raw.decode('ascii').splitlines()
                    if (any(not HEX64.fullmatch(v) for v in rows) or len(set(rows)) != len(rows)
                            or set(rows) != set(self.config['docker_containers'])):
                        raise LifecycleError('docker_api_inventory_mismatch')
                    break
                if self.monotonic() >= deadline: raise LifecycleError('docker_start_readiness_unknown')
                self.wait(0.1)
            self._fresh(request)
            code, _ = self._command(['/usr/bin/docker', 'container', 'start', *self.config['docker_containers']], 8)
            if code: raise LifecycleError('container_start_unknown')
        result = self.inspect(request)
        active = all(state == 'active' for state in result['units'].values())
        expected = set(self.config['docker_containers'])
        active = active and set(result['docker']['running_ids']) == expected
        active = active and result['active_generation_verified']
        result.update(ok=bool(active), outcome='activated' if active else 'pending',
                      old_fence_proof_digest=request['old_fence_proof_digest'],
                      old_fence_proof_at=request['old_fence_proof_at'])
        if result['ok']: self._finish(request, result)
        return result

    def run(self, request: dict) -> dict:
        lock = os.open(self.root/'mutation.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError: raise LifecycleError('lifecycle_busy') from None
            self._authenticate(request)
            identity = guard.load_identity(self.identity_path, root_owned=self.root_owned)
            if request['op'] == 'quarantine_inspect': return self._quarantine(request, identity)
            if request['op'] == 'adopt_clone': return self._adopt(request, identity)
            self._scope(request, identity)
            if request['op'] == 'fence': return self._fence(request, identity)
            if request['op'] == 'activate': return self._activate(request, identity)
            result = self.inspect(request)
            result['read_only'] = True
            if request['op'] == 'reconcile':
                with self._db() as db:
                    row = db.execute('SELECT op,receipt FROM intents WHERE id=?', (request['intent_id'],)).fetchone()
                    prior_effects = set()
                    for prior in db.execute("SELECT op,body FROM intents WHERE op IN ('fence','activate')"):
                        body = json.loads(prior['body'])
                        if all(body.get(k) == identity[k] for k in ('service_id','provider_id','generation','config_hash')):
                            prior_effects.add(prior['op'])
                result['recorded_operation'] = row['op'] if row else None
                result['recorded_completion'] = bool(row and row['receipt'])
                absent = row is None and result['physical_identity_verified'] and result['guard_installed'] and result['systemd_jobs_clear'] and not result['current_generation_retired']
                result['not_started_for'] = []
                if (absent and 'fence' not in prior_effects and not result['identity_fenced']
                        and all(v in ('active','inactive','failed') for v in result['units'].values())):
                    result['not_started_for'].append('fence')
                if absent and 'activate' not in prior_effects and result['persistent_fence'] and result['activation_adoption_verified']:
                    result['not_started_for'].append('activate')
            result['observed_at'] = self._fresh(request)
            return result
        finally: os.close(lock)


def main(argv=None) -> int:
    argv = sys.argv[1:] if argv is None else argv
    os.umask(0o077)
    try:
        if argv != ['--config', DEFAULT_CONFIG] or os.geteuid() != 0:
            raise LifecycleError('arguments_or_owner')
        request = decode(sys.stdin.buffer.read(MAX_BODY+1))
        result = Lifecycle(load_config()).run(request)
        sys.stdout.buffer.write(canonical(result)+b'\n')
        return 0 if result['ok'] else 2
    except Exception:
        sys.stderr.write('{"ok":false,"error":"lifecycle_precondition_or_outcome_unknown"}\n')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
