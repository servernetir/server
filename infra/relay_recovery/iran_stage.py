#!/usr/bin/env python3
"""Private PVE wrapper for the fixed CT113 staging/endpoint helper.

This is an IranAdapter recipe, not a cloud lifecycle or global DNS transaction.
stdin is the already authenticated root agent context. Only the fixed CT113
helper can change its locally reviewed endpoint registry. Unknown PVE-host
endpoint files are NOT implicitly covered: apply requires an explicit private
ct_registry_covers_ir_consumers=true installation decision. Otherwise HOLD.

Official Proxmox pct CLI synopsis documents pct push with --perms/--user/--group;
it does not advertise the qm-only --pass-stdin option for pct exec:
https://github.com/proxmox/pve-docs/blob/master/generated/pct.1-synopsis.adoc
We push an immutable root0600 nonce/SHA-bound job, then invoke one fixed helper
without stdin. Reconciliation reuses the ORIGINAL file and never pushes or
re-dispatches a mutation. It only records local control-plane audit receipts.

Root0600 config schema servernet.relay.iran-stage.config.v1::
  {schema,agent_id,state_dir,pct_sha256,ct_job_dir,ct_wrapper,ct_config,
   ct_pins:[{path,sha256}],ssh_port:22,ct_registry_covers_ir_consumers:false}

pct is fixed /usr/sbin/pct. Remote ct_wrapper and ct_config must both appear in
ct_pins along with the reviewed CT code and all imported dependencies. No path,
command, unit name, key path or executable comes from a network request. The CT
job contains only validated data (public host/pin/profile and approved CAS IDs).

Every mutation persists PREPARED -> PUSHED -> DISPATCHED before the CT call.
Mutations require the original absolute controller ``params.expires_at``;
delivery never grants a new lease. The CT envelope is capped by that deadline
and the local25-second transport limit. Legacy jobs can only reconcile.
An explicitly leased fresh accept preserves the same original read deadline;
ordinary accept delivery retains its existing idempotent behavior.
After ambiguous failure, only read-only reconcile is allowed. A crash before
DISPATCHED proves no endpoint mutation was invoked. Once dispatched, missing
job files, bad pins or unresolved CT state remain unknown. Error stdout uses a
distinct schema so IranAdapter rejects it and the agent retains its pending
mutation; it never mistakes an unknown write for a completed failed job.
"""
from __future__ import annotations

import argparse
import contextlib
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import stat
import sys
import time
import fcntl

try:
    from . import agent, ct_stage
    from .protocol import HEX64, IDENTIFIER, ProtocolError, canonical_json, decode_json, private_file
except ImportError:
    import agent
    import ct_stage
    from protocol import HEX64, IDENTIFIER, ProtocolError, canonical_json, decode_json, private_file


SCHEMA = 'servernet.relay.iran-stage.config.v1'
JOB_SCHEMA = 'servernet.relay.ct-job.v1'
RECIPE_SCHEMA = 'servernet.relay.iran-recipe.v1'
ERROR_SCHEMA = 'servernet.relay.iran-stage.error.v1'
PCT = '/usr/sbin/pct'
ACTIONS = frozenset({'stage', 'apply', 'rollback', 'accept', 'reconcile'})
MUTATING = frozenset({'stage', 'apply', 'rollback'})
SCOPE = ('incident_id', 'generation', 'revision', 'config_hash')
SAFE_PATH = re.compile(r'\A/[A-Za-z0-9_./-]{1,220}\Z')


class StageError(ProtocolError):
    pass


class OutcomeUnknown(StageError):
    pass


def _path(value) -> Path:
    if not isinstance(value, str) or not SAFE_PATH.fullmatch(value) or '..' in Path(value).parts:
        raise StageError('stage_fixed_path')
    return Path(value)


def _directory(value, *, root_owned=True, create=False) -> Path:
    path = _path(str(value))
    if create:
        for parent in path.parents:
            info = parent.lstat()
            if (parent.is_symlink() or not stat.S_ISDIR(info.st_mode)
                    or root_owned and (info.st_uid != 0 or info.st_mode & 0o022)):
                raise StageError('stage_private_parent')
        path.mkdir(mode=0o700, exist_ok=True)
    for current in (path, *path.parents):
        info = current.lstat()
        if (current.is_symlink() or not stat.S_ISDIR(info.st_mode)
                or root_owned and (info.st_uid != 0 or info.st_mode & 0o022)):
            raise StageError('stage_private_directory')
    if path.stat().st_mode & 0o077:
        raise StageError('stage_private_directory')
    return path


def load_config(path, *, root_owned=True) -> dict:
    path = private_file(path, root_owned=root_owned)
    if path.stat().st_size > 32768:
        raise StageError('stage_config_size')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as stream:
        cfg = decode_json(stream.read(32769))
    allowed = {'schema', 'agent_id', 'state_dir', 'pct_sha256', 'ct_job_dir', 'ct_wrapper',
               'ct_config', 'ct_pins', 'ct_registry_covers_ir_consumers'}
    allowed.add('ssh_port')
    if (set(cfg) - allowed or cfg.get('schema') != SCHEMA or not isinstance(cfg.get('agent_id'), str)
            or not IDENTIFIER.fullmatch(cfg['agent_id']) or not isinstance(cfg.get('pct_sha256'), str)
            or not HEX64.fullmatch(cfg['pct_sha256'])):
        raise StageError('stage_config')
    for key in ('ct_job_dir', 'ct_wrapper', 'ct_config'):
        cfg[key] = str(_path(cfg.get(key)))
    pins = cfg.get('ct_pins')
    if not isinstance(pins, list) or not 2 <= len(pins) <= 16:
        raise StageError('stage_remote_pins')
    paths = set()
    for pin in pins:
        if (not isinstance(pin, dict) or set(pin) != {'path', 'sha256'}
                or not isinstance(pin.get('sha256'), str) or not HEX64.fullmatch(pin['sha256'])):
            raise StageError('stage_remote_pin')
        path = str(_path(pin['path']))
        if path in paths:
            raise StageError('stage_duplicate_pin')
        paths.add(path)
    if not {cfg['ct_wrapper'], cfg['ct_config']} <= paths:
        raise StageError('stage_required_pins')
    if type(cfg.get('ct_registry_covers_ir_consumers', False)) is not bool:
        raise StageError('stage_inventory_flag')
    if type(cfg.get('ssh_port', 22)) is not int or cfg.get('ssh_port', 22) not in (22, 443):
        raise StageError('stage_ssh_port')
    cfg['ssh_port'] = cfg.get('ssh_port', 22)
    cfg['state_dir'] = str(_directory(cfg.get('state_dir'), root_owned=root_owned, create=True))
    cfg['root_owned'] = root_owned
    return cfg


def _atomic(path: Path, data: bytes) -> None:
    if path.exists() or path.is_symlink():
        raise StageError('stage_immutable_job_exists')
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(data); stream.flush(); os.fsync(stream.fileno())
    directory = os.open(path.parent, os.O_DIRECTORY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


class NodeStage:
    def __init__(self, config, *, runner=agent._bounded_process, now=lambda: int(time.time()),
                 nonce=lambda: secrets.token_hex(16), verify_pct=True):
        self.config, self.runner, self.now, self.nonce = config, runner, now, nonce
        self.root_owned = config.get('root_owned', True)
        self.root = _directory(config['state_dir'], root_owned=self.root_owned)
        self.jobs = _directory(str(self.root/'jobs'), root_owned=self.root_owned, create=True)
        self.db_path, self.lock_path = self.root/'node-stage.sqlite3', self.root/'node-stage.lock'
        if self.db_path.exists() or self.db_path.is_symlink():
            private_file(self.db_path, root_owned=self.root_owned)
        if verify_pct:
            path = private_file(PCT, root_owned=self.root_owned, executable=True)
            if path.stat().st_size > 16*1024*1024 or hashlib.sha256(path.read_bytes()).hexdigest() != config['pct_sha256']:
                raise StageError('stage_pct_pin')
        with self._transaction() as db:
            db.executescript('''
              CREATE TABLE IF NOT EXISTS operations(key TEXT PRIMARY KEY,action TEXT NOT NULL,
                state TEXT NOT NULL,nonce TEXT NOT NULL,sha256 TEXT NOT NULL,body TEXT NOT NULL,result TEXT);
              CREATE TABLE IF NOT EXISTS observations(hash TEXT PRIMARY KEY,operation TEXT NOT NULL,
                action TEXT NOT NULL,received_at INTEGER NOT NULL,body TEXT NOT NULL);
              CREATE TABLE IF NOT EXISTS clock(id INTEGER PRIMARY KEY CHECK(id=1),last_at INTEGER NOT NULL);
            ''')
        os.chmod(self.db_path, 0o600)

    @contextlib.contextmanager
    def _transaction(self):
        if self.lock_path.exists() or self.lock_path.is_symlink():
            private_file(self.lock_path, root_owned=self.root_owned)
        fd = os.open(self.lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        db = None
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            db = sqlite3.connect(self.db_path, timeout=0, isolation_level=None)
            db.row_factory = sqlite3.Row
            db.execute('PRAGMA synchronous=FULL'); db.execute('BEGIN IMMEDIATE')
            yield db
            if db.in_transaction:
                db.commit()
        except Exception:
            if db is not None and db.in_transaction:
                db.rollback()
            raise
        finally:
            if db is not None:
                db.close()
            os.close(fd)

    def _time(self, db=None):
        now = self.now()
        if type(now) is not int or now <= 0:
            raise StageError('stage_clock')
        if db is not None:
            previous = db.execute('SELECT last_at FROM clock WHERE id=1').fetchone()
            if previous and now < previous['last_at']:
                raise StageError('stage_clock_regressed')
            db.execute('INSERT INTO clock VALUES(1,?) ON CONFLICT(id) DO UPDATE SET last_at=excluded.last_at', (now,))
        return now

    def _call(self, argv, timeout):
        try:
            status, raw = self.runner(argv, b'', timeout)
        except (ProtocolError, OSError, TimeoutError):
            raise OutcomeUnknown('stage_transport_unknown') from None
        if type(status) is not int or not isinstance(raw, bytes) or len(raw) > 16384:
            raise OutcomeUnknown('stage_transport_output')
        return status, raw

    def _pins(self):
        paths = [pin['path'] for pin in self.config['ct_pins']]
        # Verify the protected directories BEFORE pct push can open a remote
        # destination. Otherwise a writable/symlinked job directory could turn
        # even transport delivery into an arbitrary root file overwrite.
        examined = {}
        for value in [*paths, self.config['ct_job_dir']]:
            path = _path(value)
            examined[str(path)] = 'directory' if value == self.config['ct_job_dir'] else 'file'
            for parent in path.parents:
                examined.setdefault(str(parent), 'directory')
        if len(examined) > 64:
            raise StageError('stage_remote_paths')
        ordered = list(examined)
        status, metadata = self._call([PCT, 'exec', '113', '--keep-env', '0', '--', '/usr/bin/stat',
            '--printf', '%u:%a:%F:%n\n', '--', *ordered], 1)
        try:
            rows = metadata.decode('ascii').splitlines()
            if status != 0 or len(rows) != len(ordered):
                raise ValueError()
            for expected, line in zip(ordered, rows):
                owner, mode, kind, observed_path = line.split(':', 3)
                numeric_mode = int(mode, 8)
                if (owner != '0' or observed_path != expected or numeric_mode & 0o022
                        or examined[expected] == 'directory' and kind != 'directory'
                        or examined[expected] == 'file' and kind not in {'regular file', 'regular empty file'}
                        or expected in {self.config['ct_config'], self.config['ct_job_dir']} and numeric_mode & 0o077
                        or expected == self.config['ct_wrapper'] and not numeric_mode & 0o111):
                    raise ValueError()
        except (UnicodeError, ValueError):
            raise StageError('stage_remote_permissions') from None
        status, raw = self._call([PCT, 'exec', '113', '--keep-env', '0', '--', '/usr/bin/sha256sum', '--', *paths], 1)
        try:
            rows = raw.decode('ascii').strip().splitlines()
        except UnicodeError:
            raise StageError('stage_ct_pin') from None
        expected = [pin['sha256'] + '  ' + pin['path'] for pin in self.config['ct_pins']]
        if status != 0 or rows != expected:
            raise StageError('stage_ct_pin')

    def _invoke(self, row, action, *, enforce_deadline=False):
        remote = str(_path(self.config['ct_job_dir']) / (row['nonce'] + '.json'))
        argv = [PCT, 'exec', '113', '--keep-env', '0', '--', self.config['ct_wrapper'],
                '--config', self.config['ct_config'], '--job-file', remote,
                '--job-sha256', row['sha256'], '--nonce', row['nonce'], action]
        timeout = 14 if action in MUTATING else 4.5
        if action in MUTATING or enforce_deadline:
            remaining = json.loads(row['body'])['expires_at'] - self._time()
            if remaining <= 0:
                raise StageError('stage_dispatch_expired')
            timeout = min(timeout, remaining)
        status, raw = self._call(argv, timeout)
        result = decode_json(raw)
        context = json.loads(row['body'])['context']
        if (result.get('schema') != RECIPE_SCHEMA or result.get('action') != action
                or type(result.get('ok')) is not bool or result.get('target') != context['job']['target']
                or any(type(result.get(k)) is not type(context[k]) or result.get(k) != context[k] for k in SCOPE)
                or not isinstance(result.get('checks'), dict) or len(canonical_json(result['checks'])) > 4096
                or result.get('read_only') is not (action in {'accept', 'reconcile'})
                or status != 0 and result['ok']):
            raise OutcomeUnknown('stage_ct_receipt')
        flag = {'stage':'staged','apply':'applied','rollback':'rolled_back','accept':'accepted'}.get(action)
        if result['ok'] and (result['checks'].get('generation_verified') is not True
                or flag is not None and result['checks'].get(flag) is not True
                or action == 'reconcile' and result.get('reconciled') is not True):
            raise OutcomeUnknown('stage_ct_outcome_unproven')
        if enforce_deadline and self._time() >= json.loads(row['body'])['expires_at']:
            raise StageError('stage_dispatch_expired')
        return result

    def _base(self, context, action, **fields):
        result = dict(schema=RECIPE_SCHEMA, action=action, read_only=action in {'accept', 'reconcile'},
                    ok=False, checks={}, target=context['job']['target'],
                    **{k: context[k] for k in SCOPE})
        result.update(fields)
        return result

    def _record(self, key, action, result, *, state=None):
        raw = canonical_json(result).decode()
        with self._transaction() as db:
            now = self._time(db)
            digest = hashlib.sha256(raw.encode()).hexdigest()
            db.execute('INSERT OR IGNORE INTO observations VALUES(?,?,?,?,?)', (digest, key, action, now, raw))
            if state:
                prior = db.execute('SELECT result FROM operations WHERE key=?', (key,)).fetchone()
                if prior is None:
                    raise StageError('stage_operation_missing')
                # The first proven terminal outcome is immutable. Further
                # read-only observations are retained separately.
                db.execute('UPDATE operations SET state=?,result=COALESCE(result,?) WHERE key=?', (state, raw, key))

    def _row(self, key):
        with self._transaction() as db:
            row = db.execute('SELECT * FROM operations WHERE key=?', (key,)).fetchone()
            return dict(row) if row else None

    def _reconcile(self, context, key):
        row = self._row(key)
        if row is None:
            return self._base(context, 'reconcile', reconciled=False,
                              checks={'outcome_unknown': True, 'read_only': True})
        if row['state'] in {'PREPARED', 'PUSHED'}:
            result = self._base(context, 'reconcile', reconciled=True,
                                checks={'definitive_no_write': True, 'generation_verified': True, 'read_only': True})
            self._record(key, 'reconcile', result, state='FAILED')
            return result
        self._pins()
        result = self._invoke(row, 'reconcile')
        resolved = result.get('reconciled') is True
        self._record(key, 'reconcile', result, state=('COMPLETE' if result['ok'] else 'FAILED') if resolved else None)
        return result

    def run(self, action, context, *, fresh_accept=False):
        # A composite fresh measurement must never relabel the immutable cached
        # result used by ordinary idempotent recipe delivery. Check under the
        # same whole-call lock as dispatch so concurrent observers cannot race.
        if type(fresh_accept) is not bool or fresh_accept and action != 'accept':
            raise StageError('stage_fresh_accept_scope')
        if action not in ACTIONS:
            raise StageError('stage_action')
        context = ct_stage.validate_context(context, self.config['agent_id'])
        if context['job']['target']['port'] != self.config.get('ssh_port', 22):
            raise StageError('stage_target_port')
        original = context['job']['op']
        if action != 'reconcile' and action != original or action == 'reconcile' and original not in MUTATING:
            raise StageError('stage_action_scope')
        controller_expiry = None
        # Only explicitly leased fresh accepts opt in. Ordinary idempotent
        # accept delivery and the earlier direct producer API stay unchanged.
        leased_accept = fresh_accept and 'expires_at' in context['job']['params']
        if action in MUTATING or leased_accept:
            controller_expiry = context['job']['params'].get('expires_at')
            now = self._time()
            if type(controller_expiry) is not int or not now < controller_expiry <= now+30:
                raise StageError('stage_controller_deadline_required')
        if action == 'apply' and self.config.get('ct_registry_covers_ir_consumers') is not True:
            return self._base(context, action, checks={'node_inventory_ready': False, 'generation_verified': False})
        key = ct_stage.context_key(context)
        # Whole-call lock is separate from the short SQLite ledger lock. It
        # prevents a second agent process from racing one port/registry change.
        guard = self.root/'operation.lock'
        if guard.exists() or guard.is_symlink():
            private_file(guard, root_owned=self.root_owned)
        fd = os.open(guard, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            if action == 'reconcile':
                return self._reconcile(context, key)
            prior = self._row(key)
            if prior:
                if fresh_accept:
                    raise StageError('stage_accept_already_observed')
                if prior['state'] in {'COMPLETE', 'FAILED'} and prior['result']:
                    result = json.loads(prior['result'])
                    result.update(action=action, read_only=action in {'accept', 'reconcile'})
                    return result
                raise OutcomeUnknown('stage_prior_dispatch_unknown')
            if action in MUTATING:
                with self._transaction() as db:
                    unresolved = db.execute("SELECT 1 FROM operations WHERE action IN ('stage','apply','rollback') "
                        "AND state NOT IN ('COMPLETE','FAILED') LIMIT 1").fetchone()
                if unresolved:
                    raise OutcomeUnknown('stage_other_dispatch_unknown')
            self._pins()
            nonce = self.nonce()
            if not isinstance(nonce, str) or not re.fullmatch('[0-9a-f]{32}', nonce):
                raise StageError('stage_nonce')
            with self._transaction() as db:
                now = self._time(db)
                expiry = min(now+25, controller_expiry) if controller_expiry is not None else now+25
                if now >= expiry:
                    raise StageError('stage_dispatch_expired')
                body = canonical_json(dict(schema=JOB_SCHEMA, nonce=nonce, created_at=now,
                    expires_at=expiry, action=action, context=context))
                if len(body) > 16384:
                    raise StageError('stage_job_size')
                sha = hashlib.sha256(body).hexdigest()
                _atomic(self.jobs/(nonce+'.json'), body)
                db.execute('INSERT INTO operations VALUES(?,?,?,?,?,?,NULL)',
                           (key, action, 'PREPARED', nonce, sha, body.decode()))
            row = self._row(key)
            local, remote = str(self.jobs/(nonce+'.json')), str(_path(self.config['ct_job_dir'])/(nonce+'.json'))
            if self._time() >= json.loads(row['body'])['expires_at']:
                raise StageError('stage_dispatch_expired')
            status, _ = self._call([PCT, 'push', '113', local, remote, '--perms', '0600', '--user', '0', '--group', '0'], 1)
            if status != 0:
                raise OutcomeUnknown('stage_push_unknown')
            with self._transaction() as db:
                self._time(db)
                db.execute("UPDATE operations SET state='PUSHED' WHERE key=?", (key,))
            with self._transaction() as db:
                now = self._time(db)
                if now >= json.loads(row['body'])['expires_at']:
                    raise StageError('stage_dispatch_expired')
                db.execute("UPDATE operations SET state='DISPATCHED' WHERE key=?", (key,))
            try:
                result = self._invoke(row, action, enforce_deadline=leased_accept)
            except (ProtocolError, OSError):
                raise OutcomeUnknown('stage_dispatch_unknown') from None
            if action in MUTATING and result['ok'] is not True:
                # A false mutation result can still follow a partial service or
                # file write. Resolve it only through the CT read-only journal.
                raise OutcomeUnknown('stage_dispatch_unresolved')
            self._record(key, action, result, state='COMPLETE')
            return result
        finally:
            os.close(fd)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('action', choices=sorted(ACTIONS))
    args = parser.parse_args(argv)
    try:
        if os.geteuid() != 0:
            raise StageError('stage_root_required')
        raw = sys.stdin.buffer.read(16385)
        if len(raw) > 16384:
            raise StageError('stage_context_size')
        context = decode_json(raw)
        result = NodeStage(load_config(args.config)).run(args.action, context)
        sys.stdout.buffer.write(canonical_json(result))
        return 0 if result['ok'] else 1
    except (ProtocolError, OSError, sqlite3.Error):
        # Distinct error schema deliberately preserves an agent's uncertain
        # mutation instead of acknowledging an unproven completed failure.
        sys.stdout.buffer.write(canonical_json({'schema':ERROR_SCHEMA,'ok':False,'code':'stage_operation_unresolved'}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
