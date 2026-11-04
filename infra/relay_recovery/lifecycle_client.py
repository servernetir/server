"""WHM's pinned SSH client for explicit, journaled relay lifecycle operations.

No I/O on import; no provider API; no shell; no mutation CLI. A trusted local
workflow authorizer must return literal True for the exact fresh permit before
first dispatch. A generic core PROMOTE or JSON approval flag is insufficient.
Every mutation is durably DISPATCHED before SSH. Timeout/crash/missing remote
intent never cause a blind resend. Fresh signed read-only reconciliation may
resolve its scoped outcome. An explicitly repeated adoption alone may proceed
after the original request expires and two fresh quarantined-old-identity +
journal-absence observations prove it never started; fresh workflow authority
is required again. Every dispatch remains in an append-only local audit table.

Config schema servernet.relay.lifecycle-client.v1: service_id, provider_id,
generation, config_hash, source_host (literal public IP), ssh_key_file,
known_hosts_file, expected_host_key_sha256, controller_key_file, state_dir;
optional machine_uuid_sha256 (known physical DMI SHA256), ssh_port=22,
timeout_seconds=60 (<=80). All local files/state belong to the effective WHM
UID and are private; no root requirement on WHM. SSH root account on relay is
restricted to lifecycle_cli.py. The copied snapshot SSH host key is therefore
also bound to exact fresh provider/generation/config/DMI evidence.

Permit: schema=servernet.relay.lifecycle.permit.v1, workflow_id/intent_id UUIDs,
kind=FENCE/ADOPT_CLONE/ACTIVATE, service_id, target={provider_id,generation,
config_hash}, params (exact operation lineage fields), created_at/expires_at
with <=30s lifetime. The caller's private committed workflow owns this permit.
API methods: inspect(), fence(permit), adopt_clone(permit), activate(permit),
reconcile(intent_id). Results contain fixed public fields and scoped proofs,
never HMAC/private keys, SSH output/error strings or command lines.
"""
from __future__ import annotations

import argparse
import contextlib
import fcntl
import hashlib
import io
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import stat
import sys
import time
import uuid

try:
    from . import backup_client as backup, lifecycle_side as side
except ImportError:
    import backup_client as backup
    import lifecycle_side as side

SCHEMA = 'servernet.relay.lifecycle-client.v1'
PERMIT_SCHEMA = 'servernet.relay.lifecycle.permit.v1'
KINDS = {'FENCE': 'fence', 'ADOPT_CLONE': 'adopt_clone', 'ACTIVATE': 'activate'}
PARAMS = {'fence': set(), 'adopt_clone': {'old_provider_id', 'old_generation', 'old_config_hash', 'old_machine_uuid_sha256'},
          'activate': {'old_provider_id', 'old_generation', 'old_fence_proof_digest', 'old_fence_proof_at'}}
MAX_RESULT = 65536


class LifecycleClientError(ValueError):
    """Fixed non-secret error codes only."""


def _uuid(value):
    try:
        if str(uuid.UUID(value)) != value: raise ValueError()
        return value
    except (ValueError, TypeError, AttributeError):
        raise LifecycleClientError('workflow_uuid_required') from None


def wire_intent_id(value):
    return hashlib.sha256(('SN-LIFECYCLE\n'+_uuid(value)).encode('ascii')).hexdigest()[:40]


def _copy(value):
    return json.loads(side.canonical(value))


def _target(value):
    if (not isinstance(value, dict) or set(value) != {'provider_id', 'generation', 'config_hash'}
            or not isinstance(value['provider_id'], str) or not side.NUMBER.fullmatch(value['provider_id'])
            or type(value['generation']) is not int or not 1 <= value['generation'] <= 2**63-1
            or not isinstance(value['config_hash'], str) or not side.HEX64.fullmatch(value['config_hash'])):
        raise LifecycleClientError('target_scope')
    return dict(value)


def _private_file(value, *, uid, allow_test_paths):
    path = backup.trusted_file(value, uid=uid, allow_test_paths=allow_test_paths)
    if path.stat().st_nlink != 1: raise LifecycleClientError('private_hardlink')
    return path


def validate_config(config, *, uid=None, allow_test_paths=False):
    uid = os.geteuid() if uid is None else uid
    required = {'schema', 'service_id', 'provider_id', 'generation', 'config_hash', 'source_host',
                'ssh_key_file', 'known_hosts_file', 'expected_host_key_sha256', 'controller_key_file', 'state_dir'}
    if (not isinstance(config, dict) or config.get('schema') != SCHEMA
            or not required <= set(config) or set(config)-required-{'ssh_port', 'timeout_seconds', 'machine_uuid_sha256'}):
        raise LifecycleClientError('config_schema')
    _target({k: config[k] for k in ('provider_id', 'generation', 'config_hash')})
    if not isinstance(config['service_id'], str) or not side.NUMBER.fullmatch(config['service_id']):
        raise LifecycleClientError('service_scope')
    try: host = ipaddress.ip_address(config['source_host'])
    except (TypeError, ValueError): raise LifecycleClientError('source_host') from None
    if not host.is_global or host.compressed != config['source_host']: raise LifecycleClientError('source_host')
    for field, default, maximum in (('ssh_port', 22, 65535), ('timeout_seconds', 60, 80)):
        config.setdefault(field, default)
        if type(config[field]) is not int or not 1 <= config[field] <= maximum:
            raise LifecycleClientError('config_limit')
    machine = config.get('machine_uuid_sha256')
    if machine is not None and (not isinstance(machine, str) or not side.HEX64.fullmatch(machine)):
        raise LifecycleClientError('machine_scope')
    for field in ('ssh_key_file', 'known_hosts_file', 'controller_key_file'):
        _private_file(config[field], uid=uid, allow_test_paths=allow_test_paths)
    backup._host_key(config, uid=uid, allow_test_paths=allow_test_paths)
    backup._directory(config['state_dir'], uid=uid, allow_test_paths=allow_test_paths)
    return config


def load_config(path, *, allow_test_paths=False):
    path = _private_file(str(path), uid=os.geteuid(), allow_test_paths=allow_test_paths)
    value = side.decode(backup._read_private(path, side.MAX_BODY))
    return validate_config(value, allow_test_paths=allow_test_paths)


class LifecycleClient:
    def __init__(self, config, *, authorize=None, transport=backup.ssh_stream,
                 clock=time.time, monotonic=time.monotonic, uid=None, allow_test_paths=False):
        self.uid = os.geteuid() if uid is None else uid
        self.allow_test_paths = allow_test_paths
        self.config = validate_config(_copy(config), uid=self.uid, allow_test_paths=allow_test_paths)
        self.authorize, self.transport = authorize, transport
        self.clock, self.monotonic = clock, monotonic
        self.target = _target({k: self.config[k] for k in ('provider_id', 'generation', 'config_hash')})
        self.root = Path(self.config['state_dir']); self.path = self.root/'lifecycle-client.sqlite3'
        self.key = backup._read_private(_private_file(self.config['controller_key_file'], uid=self.uid,
            allow_test_paths=allow_test_paths), 256)
        if not 32 <= len(self.key) <= 256: raise LifecycleClientError('key_length')
        fd = os.open(self.path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600); os.close(fd)
        _private_file(str(self.path), uid=self.uid, allow_test_paths=allow_test_paths)
        with self._db() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS operations(
                id TEXT PRIMARY KEY,semantic TEXT NOT NULL,phase TEXT NOT NULL,
                wire_intent TEXT NOT NULL UNIQUE,request TEXT,result TEXT,observation TEXT,observed_at INTEGER);
                CREATE TABLE IF NOT EXISTS clocks(id INTEGER PRIMARY KEY CHECK(id=1),at INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS machines(scope TEXT PRIMARY KEY,machine TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS dispatches(id TEXT NOT NULL,attempt INTEGER NOT NULL,
                    request TEXT NOT NULL,at INTEGER NOT NULL,PRIMARY KEY(id,attempt));''')
            if 'preflight' not in {v[1] for v in db.execute('PRAGMA table_info(operations)')}:
                db.execute('ALTER TABLE operations ADD COLUMN preflight TEXT')

    def _db(self):
        db = sqlite3.connect(self.path, timeout=0.1); db.row_factory = sqlite3.Row
        db.execute('PRAGMA synchronous=FULL'); db.execute('PRAGMA busy_timeout=100')
        return db

    @contextlib.contextmanager
    def _lock(self):
        path = self.root/'lifecycle-client.lock'
        fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            _private_file(str(path), uid=self.uid, allow_test_paths=self.allow_test_paths)
            try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError: raise LifecycleClientError('client_busy') from None
            yield
        finally: os.close(fd)

    def _now(self):
        now = int(self.clock())
        if now < 0: raise LifecycleClientError('clock_unsafe')
        with self._db() as db:
            db.execute('BEGIN IMMEDIATE')
            row = db.execute('SELECT at FROM clocks WHERE id=1').fetchone()
            if row and now < row[0]: raise LifecycleClientError('clock_unsafe')
            db.execute('INSERT OR REPLACE INTO clocks VALUES(1,?)', (now,))
        return now

    def _permit(self, permit, operation):
        fields = {'schema', 'workflow_id', 'intent_id', 'kind', 'service_id', 'target', 'params', 'created_at', 'expires_at'}
        if not isinstance(permit, dict) or set(permit) != fields or permit.get('schema') != PERMIT_SCHEMA:
            raise LifecycleClientError('explicit_workflow_permit_required')
        permit = _copy(permit)
        _uuid(permit['workflow_id']); _uuid(permit['intent_id'])
        if KINDS.get(permit['kind']) != operation: raise LifecycleClientError('workflow_operation_scope')
        if permit['service_id'] != self.config['service_id'] or _target(permit['target']) != self.target:
            raise LifecycleClientError('workflow_target_scope')
        if not isinstance(permit['params'], dict) or set(permit['params']) != PARAMS[operation]:
            raise LifecycleClientError('workflow_lineage_scope')
        now = self._now(); created, expiry = permit['created_at'], permit['expires_at']
        if (type(created) is not int or type(expiry) is not int or not created <= now < expiry
                or not 0 < expiry-created <= 30): raise LifecycleClientError('workflow_permit_expired')
        # Reuse the actual wire schema validation for all lineage types/ranges.
        side.validate_request(self._request(operation, permit['params'], permit['intent_id'], now), self.key, now)
        return permit

    def _request(self, operation, params=None, intent_id=None, now=None, expires_at=None):
        now = self._now() if now is None else now
        request = {'schema': side.REQUEST_SCHEMA, 'op': operation, 'request_id': secrets.token_hex(16),
                   'nonce': secrets.token_hex(16), 'service_id': self.config['service_id'], **self.target,
                   'issued_at': now, 'expires_at': min(now+90, expires_at) if expires_at is not None else now+90}
        if operation != 'inspect': request['intent_id'] = wire_intent_id(intent_id)
        request.update(params or {})
        return side.sign_request(request, self.key)

    def _semantic(self, permit):
        # Fresh authorizations can renew expiry, but cannot change the effect.
        semantic = {k: v for k, v in permit.items() if k not in {'created_at', 'expires_at'}}
        if permit['kind'] == 'ACTIVATE':
            semantic['params'] = {k:v for k,v in permit['params'].items()
                                  if k not in {'old_fence_proof_digest','old_fence_proof_at'}}
        return semantic

    def _response(self, raw, request, now):
        value = side.decode(raw.strip())
        required = {'schema', 'request_id', 'intent_id', 'operation', 'service_id', 'provider_id', 'generation',
            'config_hash', 'machine_uuid_sha256', 'metadata_provider_id', 'observed_at', 'ok',
            'physical_identity_verified', 'guard_installed', 'identity_fenced', 'all_units_inactive', 'units',
            'docker', 'persistent_fence', 'active_generation_verified', 'global_consistency_proven', 'migration_complete'}
        quarantine_fields = {'quarantine_verified', 'inherited_identity', 'guard_denies_current_machine',
                             'docker_socket_inactive', 'bootstrap', 'journal_adoption_absent'}
        optional = {'outcome', 'old_fence_proof_digest', 'old_fence_proof_at', 'read_only', 'recorded_operation', 'recorded_completion',
                    'writer_inventory_sha256','systemd_jobs_clear','current_generation_retired','activation_adoption_verified','adopted_from','not_started_for'} | quarantine_fields
        quarantine = request['op'] == 'quarantine_inspect'
        if (not required <= set(value) or set(value)-required-optional or value['schema'] != side.RESULT_SCHEMA
                or any(value[k] != request.get(k) for k in ('request_id', 'intent_id', 'service_id', 'provider_id', 'generation', 'config_hash'))
                or type(value['generation']) is not int or value['operation'] != request['op']):
            raise LifecycleClientError('remote_scope')
        for field in ('ok', 'physical_identity_verified', 'guard_installed', 'identity_fenced', 'all_units_inactive',
                      'persistent_fence', 'active_generation_verified', 'global_consistency_proven', 'migration_complete'):
            if type(value[field]) is not bool: raise LifecycleClientError('remote_boolean')
        if value['global_consistency_proven'] or value['migration_complete']:
            raise LifecycleClientError('remote_excess_claim')
        if (type(value['observed_at']) is not int or not -5 <= now-value['observed_at'] <= 30
                or (not quarantine and not value['physical_identity_verified']) or not isinstance(value['machine_uuid_sha256'], str)
                or not side.HEX64.fullmatch(value['machine_uuid_sha256'])): raise LifecycleClientError('remote_physical_identity')
        metadata = value['metadata_provider_id']; machine = value['machine_uuid_sha256']
        if metadata is not None and metadata != self.target['provider_id']: raise LifecycleClientError('remote_provider_identity')
        scope = side.canonical({'service_id': self.config['service_id'], **self.target}).decode()
        with self._db() as db:
            row = db.execute('SELECT machine FROM machines WHERE scope=?', (scope,)).fetchone()
            expected = self.config.get('machine_uuid_sha256') or (row[0] if row else None)
            if expected is not None and machine != expected: raise LifecycleClientError('remote_machine_identity')
            if expected is None and metadata != self.target['provider_id']: raise LifecycleClientError('remote_machine_unbound')
            db.execute('INSERT OR IGNORE INTO machines VALUES(?,?)', (scope, machine))
        units = value['units']
        if (not isinstance(units, dict) or not units or len(units) > 32
                or any(state not in {'active','inactive','failed','activating','deactivating','reloading','maintenance','refreshing'} for state in units.values())):
            raise LifecycleClientError('remote_units')
        side.guard.validate_units(list(units))
        docker = value['docker']
        if not isinstance(docker, dict) or set(docker) != {'registered', 'exact_inventory', 'running_ids', 'daemon_inactive', 'no_shims'}:
            raise LifecycleClientError('remote_docker')
        if any(type(docker[k]) is not bool for k in ('registered', 'exact_inventory', 'daemon_inactive', 'no_shims')):
            raise LifecycleClientError('remote_docker')
        ids = docker['running_ids']
        if not isinstance(ids, list) or len(ids) > 16 or len(set(ids)) != len(ids) or any(not isinstance(v,str) or not side.HEX64.fullmatch(v) for v in ids):
            raise LifecycleClientError('remote_docker')
        if 'read_only' in value and type(value['read_only']) is not bool: raise LifecycleClientError('remote_boolean')
        if 'recorded_completion' in value and type(value['recorded_completion']) is not bool: raise LifecycleClientError('remote_boolean')
        if value.get('recorded_operation') not in (None, 'fence', 'adopt_clone', 'activate'):
            raise LifecycleClientError('remote_operation')
        if 'outcome' in value and value['outcome'] not in {'fenced','adopted_fenced','activated','pending'}:
            raise LifecycleClientError('remote_outcome')
        if 'old_fence_proof_digest' in value and (not isinstance(value['old_fence_proof_digest'], str)
                or not side.HEX64.fullmatch(value['old_fence_proof_digest'])):
            raise LifecycleClientError('remote_fence_attestation')
        if 'old_fence_proof_at' in value and (type(value['old_fence_proof_at']) is not int
                or not 0 <= value['old_fence_proof_at'] <= now): raise LifecycleClientError('remote_fence_attestation')
        if not quarantine and set(value) & quarantine_fields: raise LifecycleClientError('remote_scope')
        for name in ('systemd_jobs_clear','current_generation_retired','activation_adoption_verified'):
            if name in value and type(value[name]) is not bool: raise LifecycleClientError('remote_boolean')
        if 'writer_inventory_sha256' in value and (not isinstance(value['writer_inventory_sha256'],str)
                or not side.HEX64.fullmatch(value['writer_inventory_sha256'])): raise LifecycleClientError('remote_inventory')
        adopted = value.get('adopted_from')
        if adopted is not None:
            if (not isinstance(adopted,dict) or set(adopted) != {'provider_id','generation','config_hash','machine_uuid_sha256'}
                    or _target({k:adopted[k] for k in ('provider_id','generation','config_hash')}) != {k:adopted[k] for k in ('provider_id','generation','config_hash')}
                    or not isinstance(adopted['machine_uuid_sha256'],str) or not side.HEX64.fullmatch(adopted['machine_uuid_sha256'])):
                raise LifecycleClientError('remote_adoption')
        if 'not_started_for' in value and (not isinstance(value['not_started_for'],list)
                or len(value['not_started_for']) > 2 or len(set(value['not_started_for'])) != len(value['not_started_for'])
                or any(v not in ('fence','activate') for v in value['not_started_for'])):
            raise LifecycleClientError('remote_absence')
        if quarantine:
            old = {k: request['old_'+k] for k in ('provider_id','generation','config_hash','machine_uuid_sha256')}
            old.update(service_id=request['service_id'], fenced=True)
            if (not all(value.get(k) is True for k in ('ok','read_only','quarantine_verified','guard_denies_current_machine','docker_socket_inactive','identity_fenced','all_units_inactive','guard_installed'))
                    or value.get('inherited_identity') != old or value['physical_identity_verified']
                    or value['persistent_fence'] or value['active_generation_verified']
                    or machine == old['machine_uuid_sha256'] or metadata != request['provider_id']
                    or not all(v in {'inactive','failed'} for v in units.values())
                    or not docker['exact_inventory'] or not docker['daemon_inactive'] or not docker['no_shims'] or ids
                    or type(value.get('journal_adoption_absent')) is not bool):
                raise LifecycleClientError('remote_quarantine')
            bootstrap = value.get('bootstrap')
            if not isinstance(bootstrap, dict) or type(bootstrap.get('machine_id_ready')) is not bool:
                raise LifecycleClientError('remote_bootstrap')
            if bootstrap['machine_id_ready']:
                if (set(bootstrap) != {'machine_id_ready','intent_id','scope','machine_id_sha256','previous_machine_id_sha256','boot_id_sha256','previous_boot_id_sha256'}
                        or not isinstance(bootstrap['intent_id'], str) or not side.HEX40.fullmatch(bootstrap['intent_id'])
                        or any(not isinstance(bootstrap[k], str) or not side.HEX64.fullmatch(bootstrap[k]) for k in
                            ('scope','machine_id_sha256','previous_machine_id_sha256','boot_id_sha256','previous_boot_id_sha256'))
                        or bootstrap['machine_id_sha256'] == bootstrap['previous_machine_id_sha256']
                        or bootstrap['boot_id_sha256'] == bootstrap['previous_boot_id_sha256']):
                    raise LifecycleClientError('remote_bootstrap')
            elif set(bootstrap) != {'machine_id_ready'}: raise LifecycleClientError('remote_bootstrap')
        return value

    def _exchange(self, request):
        # Revalidate every local pin before using SSH; no inherited SSH config.
        validate_config(self.config, uid=self.uid, allow_test_paths=self.allow_test_paths)
        body = side.canonical(request)
        if len(body) > 1024: raise LifecycleClientError('request_size')
        started, anchor = self.monotonic(), self._now(); out = io.BytesIO()
        code = self.transport(backup.ssh_argv(self.config), body, out, MAX_RESULT, self.config['timeout_seconds'])
        now = max(self._now(), anchor+int(max(0, self.monotonic()-started)))
        if code != 0: raise LifecycleClientError('ssh_outcome_unknown')
        return self._response(out.getvalue(), request, now)

    @staticmethod
    def _desired(operation, proof, params):
        if not proof['guard_installed'] or not proof['docker']['exact_inventory']: return False
        if operation in {'fence', 'adopt_clone'}:
            good = (proof['persistent_fence'] and proof['identity_fenced'] and proof['all_units_inactive']
                    and all(v in {'inactive', 'failed'} for v in proof['units'].values())
                    and proof['docker']['daemon_inactive'] and proof['docker']['no_shims'] and not proof['docker']['running_ids'])
            if operation == 'adopt_clone':
                good = good and proof['metadata_provider_id'] == proof['provider_id'] and proof['machine_uuid_sha256'] != params['old_machine_uuid_sha256']
            return bool(good)
        return bool(proof['active_generation_verified'] and not proof['identity_fenced']
                    and all(v == 'active' for v in proof['units'].values()))

    def _result(self, operation_id, operation, state, proof=None):
        result = {'state': state, 'intent_id': operation_id, 'wire_intent_id': wire_intent_id(operation_id), 'operation': operation,
                  'requires_reconcile': state in {'unknown', 'pending'}, 'migration_complete': False}
        if proof is not None: result['proof'] = proof
        return result

    @staticmethod
    def _preflight_good(operation, proof, params):
        if (not proof['ok'] or not proof['guard_installed'] or not proof['docker']['exact_inventory']
                or proof.get('systemd_jobs_clear') is not True or proof.get('current_generation_retired') is not False
                or not isinstance(proof.get('writer_inventory_sha256'),str)
                or not side.HEX64.fullmatch(proof['writer_inventory_sha256'])): return False
        if operation == 'fence':
            return bool(not proof['identity_fenced']
                        and all(v in ('active','inactive','failed') for v in proof['units'].values()))
        old = proof.get('adopted_from')
        return bool(proof['persistent_fence'] and proof['identity_fenced'] and proof['all_units_inactive']
                    and all(v in ('inactive','failed') for v in proof['units'].values())
                    and proof['docker']['daemon_inactive'] and proof['docker']['no_shims'] and not proof['docker']['running_ids']
                    and proof.get('activation_adoption_verified') is True and isinstance(old,dict)
                    and old['provider_id'] == params['old_provider_id'] and old['generation'] == params['old_generation'])

    @staticmethod
    def _preflight_hash(proof):
        fields = ('service_id','provider_id','generation','config_hash','machine_uuid_sha256','writer_inventory_sha256',
                  'guard_installed','identity_fenced','all_units_inactive','units','persistent_fence','active_generation_verified',
                  'systemd_jobs_clear','current_generation_retired','activation_adoption_verified','adopted_from')
        value = {k:proof.get(k) for k in fields}
        value['docker'] = {**proof['docker'],'running_ids':sorted(proof['docker']['running_ids'])}
        return hashlib.sha256(side.canonical(value)).hexdigest()

    def _absence(self, operation, proof, row, params):
        if operation not in ('fence','activate') or not row['request'] or not row['preflight']: return False
        dispatched = json.loads(row['request']); baseline = json.loads(row['preflight'])
        return bool(proof.get('read_only') is True and proof.get('recorded_operation') is None
            and operation in proof.get('not_started_for',[]) and self._now() >= dispatched['expires_at']
            and proof['observed_at'] >= dispatched['expires_at'] and self._preflight_good(operation,proof,params)
            and self._preflight_hash(proof) == self._preflight_hash(baseline))

    def inspect(self):
        with self._lock():
            proof = self._exchange(self._request('inspect'))
            return {'state': 'observed', 'operation': 'inspect', 'proof': proof, 'migration_complete': False}

    def quarantine_inspect(self, params, intent_id=None):
        """Fresh read of an inherited, fenced, not-yet-adopted clone only."""
        operation_id = intent_id or str(uuid.uuid4())
        with self._lock():
            request = self._request('quarantine_inspect', _copy(params), operation_id)
            side.validate_request(request, self.key, self._now())
            proof = self._exchange(request)
            return {'state':'observed','operation':'quarantine_inspect','proof':proof,'migration_complete':False}

    def _mutate(self, operation, permit):
        with self._lock():
            permit = self._permit(permit, operation)
            encoded = side.canonical(self._semantic(permit)).decode(); operation_id = permit['intent_id']
            resume_absent = False
            with self._db() as db:
                db.execute('BEGIN IMMEDIATE')
                row = db.execute('SELECT * FROM operations WHERE id=?', (operation_id,)).fetchone()
                if row:
                    if row['semantic'] != encoded: raise LifecycleClientError('operation_scope_changed')
                    resume_absent = row['phase'] == 'NOT_STARTED'
                    if not resume_absent:
                        if row['result']: return json.loads(row['result'])
                        if row['phase'] != 'PREPARED': return self._result(operation_id, operation, 'unknown')
                else:
                    if db.execute('SELECT COUNT(*) FROM operations').fetchone()[0] >= 10000:
                        raise LifecycleClientError('operation_capacity')
                    # Never let a fresh UUID bypass an unresolved same-host effect.
                    if db.execute("SELECT 1 FROM operations WHERE phase!='COMPLETE'").fetchone():
                        raise LifecycleClientError('unresolved_operation_exists')
                    db.execute('INSERT INTO operations(id,semantic,phase,wire_intent) VALUES(?,?,?,?)',
                               (operation_id, encoded, 'PREPARED', wire_intent_id(operation_id)))
            if resume_absent:
                prior = json.loads(row['request']) if row['request'] else None
                if prior is None or self._now() < prior['expires_at']:
                    raise LifecycleClientError('original_authorization_still_live')
                if operation == 'adopt_clone':
                    proof = self._exchange(self._request('quarantine_inspect', permit['params'], operation_id))
                    if (proof['observed_at'] < prior['expires_at'] or proof.get('journal_adoption_absent') is not True
                            or proof.get('recorded_operation') is not None):
                        raise LifecycleClientError('adoption_absence_not_proven')
                else:
                    proof = self._exchange(self._request('reconcile',intent_id=operation_id))
                    if not self._absence(operation,proof,row,permit['params']):
                        raise LifecycleClientError('operation_absence_not_proven')
            if self.authorize is None or self.authorize(_copy(permit)) is not True:
                raise LifecycleClientError('workflow_writes_disabled')
            self._permit(permit,operation)
            if not resume_absent and operation in ('fence','activate'):
                preflight = self._exchange(self._request('inspect'))
                good = self._preflight_good(operation,preflight,permit['params'])
                if operation == 'fence':
                    # Fencing failed/inactive/transitional writers must remain
                    # possible. An unstable baseline simply cannot prove later
                    # absence for a retry; it never prevents the first fence.
                    good = bool(preflight['guard_installed'] and preflight['docker']['exact_inventory']
                        and preflight.get('current_generation_retired') is False
                        and isinstance(preflight.get('writer_inventory_sha256'),str)
                        and side.HEX64.fullmatch(preflight['writer_inventory_sha256']))
                if not good:
                    raise LifecycleClientError('operation_preflight_unproven')
                with self._db() as db:
                    db.execute('UPDATE operations SET preflight=? WHERE id=? AND phase=\'PREPARED\'',
                               (side.canonical(preflight).decode(),operation_id))
                if self.authorize(_copy(permit)) is not True: raise LifecycleClientError('workflow_writes_disabled')
            self._permit(permit, operation)  # Expiry can be spent in authorizer.
            request = self._request(operation, permit['params'], operation_id, expires_at=permit['expires_at'])
            claimed_at = self._now()
            with self._db() as db:
                db.execute('BEGIN IMMEDIATE')
                count = db.execute('SELECT COUNT(*) FROM dispatches WHERE id=?',(operation_id,)).fetchone()[0]
                if count >= 16: raise LifecycleClientError('operation_retry_capacity')
                updated = db.execute("UPDATE operations SET phase='DISPATCHED',request=?,result=NULL WHERE id=? AND phase IN ('PREPARED','NOT_STARTED')",
                           (side.canonical(request).decode(), operation_id))
                if updated.rowcount != 1: raise LifecycleClientError('operation_claim_changed')
                db.execute('INSERT INTO dispatches VALUES(?,?,?,?)',
                           (operation_id,count+1,side.canonical(request).decode(),claimed_at))
            # Every exception after this claim is an unknown external outcome.
            try:
                proof = self._exchange(request)
                complete = proof['ok'] and self._desired(operation, proof, permit['params'])
                if operation == 'activate' and (proof.get('old_fence_proof_digest') != permit['params']['old_fence_proof_digest']
                        or proof.get('old_fence_proof_at') != permit['params']['old_fence_proof_at']):
                    raise LifecycleClientError('remote_fence_attestation')
                result = self._result(operation_id, operation, 'complete' if complete else 'pending', proof)
            except Exception:
                result = self._result(operation_id, operation, 'unknown')
            with self._db() as db:
                db.execute('UPDATE operations SET phase=?,result=? WHERE id=?',
                           ('COMPLETE' if result['state'] == 'complete' else 'UNKNOWN', side.canonical(result).decode(), operation_id))
            return result

    def fence(self, permit): return self._mutate('fence', permit)
    def adopt_clone(self, permit): return self._mutate('adopt_clone', permit)
    def activate(self, permit): return self._mutate('activate', permit)

    def reconcile(self, operation_id):
        _uuid(operation_id)
        with self._lock():
            with self._db() as db:
                row = db.execute('SELECT * FROM operations WHERE id=?', (operation_id,)).fetchone()
            if not row: raise LifecycleClientError('operation_missing')
            permit = json.loads(row['semantic']); operation = KINDS[permit['kind']]
            if permit['service_id'] != self.config['service_id'] or permit['target'] != self.target:
                raise LifecycleClientError('operation_target_scope')
            request = self._request('reconcile', intent_id=operation_id)
            try:
                proof = self._exchange(request)
                matched = proof.get('read_only') is True and proof.get('recorded_operation') == operation
                complete = proof['ok'] and matched and self._desired(operation, proof, permit['params'])
                if operation == 'adopt_clone': complete = complete and proof.get('recorded_completion') is True
                absent = self._absence(operation,proof,row,permit['params'])
                result = self._result(operation_id, operation, 'complete' if complete else ('not_started' if absent else 'unknown'), proof)
            except Exception:
                result = self._result(operation_id, operation, 'unknown')
                if operation == 'adopt_clone':
                    # Normal target scope fails while old identity is unchanged.
                    # Quarantine can prove only current no-write/absent adoption;
                    # wait until every original signed request has expired.
                    try:
                        proof = self._exchange(self._request('quarantine_inspect', permit['params'], operation_id))
                        dispatched = json.loads(row['request']) if row['request'] else None
                        if (dispatched is not None and self._now() >= dispatched['expires_at']
                                and proof['observed_at'] >= dispatched['expires_at']
                                and proof.get('journal_adoption_absent') is True and proof.get('recorded_operation') is None):
                            result = self._result(operation_id, operation, 'not_started', proof)
                    except Exception: pass
            with self._db() as db:
                db.execute('UPDATE operations SET phase=?,result=?,observation=?,observed_at=? WHERE id=?',
                           ('COMPLETE' if result['state'] == 'complete' else ('NOT_STARTED' if result['state'] == 'not_started' else 'UNKNOWN'), side.canonical(result).decode(),
                            side.canonical(result.get('proof', {})).decode(), self._now(), operation_id))
            return result


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', required=True)
    parser.add_argument('--op', choices=('inspect','reconcile'), required=True)
    parser.add_argument('--intent-id')
    args = parser.parse_args(argv); os.umask(0o077)
    try:
        client = LifecycleClient(load_config(args.config))
        result = client.inspect() if args.op == 'inspect' else client.reconcile(args.intent_id)
        sys.stdout.buffer.write(side.canonical(result)+b'\n')
        return 0
    except Exception:
        sys.stderr.write('{"ok":false,"error":"lifecycle_client_precondition_or_outcome_unknown"}\n')
        return 1


if __name__ == '__main__': raise SystemExit(main())
