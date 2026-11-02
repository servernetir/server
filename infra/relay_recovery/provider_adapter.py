"""Durable, narrowly scoped Hetzner effects for the WHM recovery worker.

No I/O is performed on import. Credentials are obtained only through a pinned
private local PHP CLI bridge; never from incoming reports or environment. A
committed core intent is required before every lifecycle effect. Reconciliation
is read-only, including when residual IPs still cost money: a new core permit
must authorize their deletion. collect/cutover are separate integration layers.
"""
from __future__ import annotations

import contextlib
import dataclasses
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import stat
import time
from typing import Callable

from . import core, hetzner
from .agent import _bounded_process
from .gateway import private_file


class ProviderEffectError(core.RecoveryError):
    """Only fixed codes; raw bridge, SQL and provider errors are not reflected."""


def _directory(path: Path) -> Path:
    info = path.lstat()
    if (not stat.S_ISDIR(info.st_mode) or path.is_symlink() or info.st_uid != os.geteuid()
            or stat.S_IMODE(info.st_mode) & 0o077):
        raise ProviderEffectError('private_state_required')
    return path


def _pinned(path: str, digest: str, *, executable: bool = False) -> Path:
    p = Path(path)
    if not p.is_absolute() or not re.fullmatch(r'[0-9a-f]{64}', digest or ''):
        raise ProviderEffectError('invalid_local_pin')
    info = p.lstat()
    if (not stat.S_ISREG(info.st_mode) or p.is_symlink() or info.st_uid not in (0, os.geteuid())
            or stat.S_IMODE(info.st_mode) & 0o022 or (executable and not os.access(p, os.X_OK))):
        raise ProviderEffectError('unsafe_local_pin')
    if hashlib.sha256(p.read_bytes()).hexdigest() != digest:
        raise ProviderEffectError('local_pin_mismatch')
    return p


class CredentialBridge:
    """Fixed argv/private pipe. stdout containing tokens never reaches logs."""
    def __init__(self, config: dict, runner: Callable = _bounded_process):
        self.entry = config.get('bridge')
        self.runner = runner

    def request(self, operation: str) -> dict:
        if operation not in {'describe', 'credentials'} or not isinstance(self.entry, dict):
            raise ProviderEffectError('bridge_not_configured')
        try:
            php = _pinned(self.entry['php'], self.entry['php_sha256'], executable=True)
            bridge = _pinned(self.entry['path'], self.entry['sha256'])
            config = Path(self.entry['config'])
            private_file(config)
            code, output = self.runner([str(php), str(bridge), str(config)],
                                       json.dumps({'op': operation}).encode(), timeout=20)
            result = json.loads(output)
            if code != 0 or not isinstance(result, dict) or result.get('ok') is not True:
                raise ProviderEffectError('bridge_failed')
            return result
        except Exception:
            raise ProviderEffectError('bridge_failed') from None


class ProviderStore:
    """Independent private ledger written before any provider mutation."""
    def __init__(self, state_dir: Path):
        self.root = _directory(state_dir)
        self.path, self.lock = self.root/'provider-effects.sqlite3', self.root/'provider-effects.lock'
        for path in (self.path, self.lock):
            if path.exists() or path.is_symlink():
                private_file(path)
        with self.transaction() as db:
            db.executescript('''
              CREATE TABLE IF NOT EXISTS creates(id TEXT PRIMARY KEY, spec TEXT NOT NULL, phase TEXT NOT NULL, resource TEXT);
              CREATE TABLE IF NOT EXISTS resources(id TEXT PRIMARY KEY, body TEXT NOT NULL);
              CREATE TABLE IF NOT EXISTS adoptions(id TEXT PRIMARY KEY, plan TEXT NOT NULL, phase TEXT NOT NULL);
            ''')
        os.chmod(self.path, 0o600)

    @contextlib.contextmanager
    def transaction(self):
        flags = os.O_CREAT | os.O_RDWR | getattr(os, 'O_NOFOLLOW', 0)
        fd = os.open(self.lock, flags, 0o600)
        db = None
        try:
            if os.fstat(fd).st_mode & 0o077:
                raise ProviderEffectError('unsafe_provider_lock')
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            if self.path.exists() or self.path.is_symlink():
                private_file(self.path)
            old = os.umask(0o077)
            try:
                db = sqlite3.connect(self.path, timeout=0, isolation_level=None)
            finally:
                os.umask(old)
            db.row_factory = sqlite3.Row
            db.execute('PRAGMA synchronous=FULL')
            db.execute('BEGIN IMMEDIATE')
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

    def read_resource(self, provider_id: str) -> hetzner.ManagedServer:
        with self.transaction() as db:
            row = db.execute('SELECT body FROM resources WHERE id=?', (provider_id,)).fetchone()
        if row is None:
            raise ProviderEffectError('resource_not_durably_adopted')
        value = json.loads(row['body'])
        value['primary_ip_ids'] = tuple(value['primary_ip_ids'])
        return hetzner.ManagedServer(**value)

    def save_resource(self, resource: hetzner.ManagedServer) -> None:
        body = core.canonical(dataclasses.asdict(resource))
        with self.transaction() as db:
            old = db.execute('SELECT body FROM resources WHERE id=?', (resource.provider_id,)).fetchone()
            if old is not None:
                prior = json.loads(old['body'])
                for key in ('provider_id', 'name', 'labels', 'generation', 'primary_ip_ids', 'ipv4'):
                    if core.canonical(prior[key]) != core.canonical(json.loads(body)[key]):
                        raise ProviderEffectError('immutable_resource_identity_conflict')
                if prior.get('has_writes') and not resource.has_writes:
                    raise ProviderEffectError('cannot_erase_write_history')
            db.execute('INSERT INTO resources VALUES(?,?) ON CONFLICT(id) DO UPDATE SET body=excluded.body',
                       (resource.provider_id, body))


class ProviderEffects:
    ACTIONS = {'CREATE', 'RECONCILE_CREATE', 'DELETE_CANDIDATE',
               'RECONCILE_DELETE_CANDIDATE', 'RETIRE', 'RECONCILE_RETIRE'}

    def __init__(self, config: dict, *, now: Callable[[], int] = lambda: int(time.time()),
                 client_factory: Callable | None = None, bridge: CredentialBridge | None = None):
        self.config, self.now = config, now
        self.root = _directory(Path(config['state_dir']))
        values = dict(config.get('policy', {}))
        if 'critical_consumers' in values:
            values['critical_consumers'] = tuple(values['critical_consumers'])
        self.policy = core.Config(**values)
        self.policy.validate()
        self.binding = config.get('binding', {})
        if (str(self.binding.get('owner_id')) != self.policy.owner_id
                or str(self.binding.get('service_id')) != self.policy.service_id):
            raise ProviderEffectError('owner_binding_mismatch')
        self.settings = config.get('provider', {})
        self.bridge = bridge or CredentialBridge(config)
        self.client_factory = client_factory
        self.store = ProviderStore(self.root)

    def _client(self) -> hetzner.Hetzner:
        if self.client_factory is not None:
            return self.client_factory()
        result = self.bridge.request('credentials')
        token = result.get('hetzner_token')
        if not isinstance(token, str) or not token or any(c.isspace() for c in token):
            raise ProviderEffectError('credentials_unavailable')
        return hetzner.Hetzner(hetzner.HttpTransport(token), now=self.now)

    def quotes(self):
        return self._client().quotes(expected_currency=self.policy.budget_currency,
            predicted_outgoing_bytes=self.settings.get('predicted_outgoing_bytes', 0),
            min_memory_gib=self.settings.get('min_memory_gib', '2'),
            min_disk_gb=self.settings.get('min_disk_gb', 20), monthly_caps_micro={
                'DE': self.policy.max_monthly_eu_micro, 'FI': self.policy.max_monthly_eu_micro,
                'US': self.policy.max_monthly_us_micro})

    def image_compatible(self, image_id: str) -> bool:
        """Read the exact image ID; a configured name is not compatibility proof."""
        client = self._client()
        raw = client._ok(client._request('GET', '/images/' + hetzner._identifier(image_id))).get('image', {})
        return (str(raw.get('id')) == str(image_id) and raw.get('status') == 'available'
                and raw.get('architecture') == 'x86' and raw.get('deprecation') is None)

    def _verified_server(self, client, record):
        raw = client._ok(client._request('GET', '/servers/' + hetzner._identifier(record.provider_id))).get('server', {})
        ids = {hetzner._identifier(v['id']) for family in ('ipv4', 'ipv6')
               if (v := raw.get('public_net', {}).get(family) or {}).get('id') is not None}
        if (str(raw.get('id')) != record.provider_id or raw.get('name') != record.name
                or raw.get('labels') != record.labels or ids != set(record.primary_ip_ids)
                or (raw.get('public_net', {}).get('ipv4') or {}).get('ip') != record.ipv4):
            raise ProviderEffectError('provider_resource_identity_changed')
        return raw

    def probe_target(self, scoped: dict) -> dict:
        """Return an address only from a durably captured ID and current GET."""
        record = self.store.read_resource(scoped['provider_id'])
        if self._identity(record) != core.identity(scoped):
            raise ProviderEffectError('probe_target_scope_mismatch')
        raw = self._verified_server(self._client(), record)
        return {'identity': self._identity(record), 'ipv4': record.ipv4,
                'location_code': (raw.get('location') or {}).get('name')}

    def inventory(self, active_identity: dict) -> dict:
        """All pages plus the adopted active ID anchor the credential project."""
        active = self.store.read_resource(active_identity['provider_id'])
        if self._identity(active) != core.identity(active_identity):
            raise ProviderEffectError('inventory_active_scope_mismatch')
        described = self.bridge.request('describe')
        binding = described.get('binding', {})
        if (described.get('internal_protected') is not True
                or str(binding.get('owner_id')) != self.policy.owner_id
                or str(binding.get('service_id')) != self.policy.service_id
                or str(binding.get('provider_id')) != active.provider_id
                or binding.get('ipv4') != active.ipv4):
            raise ProviderEffectError('inventory_app_binding_changed')
        client = self._client()
        self._verified_server(client, active)
        rows = client._pages('/servers', 'servers')
        managed = [r for r in rows if r.get('labels', {}).get('sn-managed') == hetzner.MANAGER
                   and r.get('labels', {}).get('sn-owner') == self.policy.owner_id
                   and r.get('labels', {}).get('sn-service') == self.policy.service_id]
        resources, seen = [], set()
        for raw in managed:
            provider_id = hetzner._identifier(raw.get('id'))
            if provider_id in seen:
                raise ProviderEffectError('inventory_duplicate_identity')
            seen.add(provider_id)
            record = self.store.read_resource(provider_id)
            self._verified_server(client, record)
            resources.append({'identity': self._identity(record), 'ipv4': record.ipv4})
        if active.provider_id not in seen:
            raise ProviderEffectError('inventory_active_missing')
        return {'complete': True, 'project_id': self.policy.project_id,
                'managed_vm_count': len(managed), 'active_identity': core.identity(active_identity),
                'resources': resources, 'provider_access': True}

    def _committed(self, action: str, payload: dict):
        now = self.now()
        received = payload.get('intent')
        if not isinstance(received, dict) or received.get('kind') != action:
            raise ProviderEffectError('invalid_effect_intent')
        path = self.root/'controller.sqlite3'
        private_file(path)
        db = sqlite3.connect(f'file:{path}?mode=ro', uri=True)
        db.row_factory = sqlite3.Row
        try:
            row = db.execute('SELECT body,receipt FROM actions WHERE id=?', (received.get('id'),)).fetchone()
            state = json.loads(db.execute('SELECT body FROM state WHERE id=1').fetchone()[0])
        finally:
            db.close()
        if row is None or core.canonical(json.loads(row['body'])) != core.canonical(received):
            raise ProviderEffectError('effect_without_exact_committed_intent')
        if row['receipt'] is not None:
            raise ProviderEffectError('effect_already_recorded')
        if not (type(received.get('created_at')) is int and type(received.get('expires_at')) is int
                and received['created_at'] <= now <= received['expires_at']):
            raise ProviderEffectError('effect_intent_expired')
        if state.get('clock_unsafe') or state['active']['project_id'] != self.policy.project_id:
            raise ProviderEffectError('unsafe_journal_scope')
        if action.startswith('RECONCILE_'):
            if received.get('payload', {}).get('read_only') is not True:
                raise ProviderEffectError('reconciliation_must_be_read_only')
        elif not self.policy.enabled:
            raise ProviderEffectError('effects_disabled')
        return received, state

    def _original_action(self, action_id: str) -> dict:
        path = self.root/'controller.sqlite3'
        private_file(path)
        with sqlite3.connect(f'file:{path}?mode=ro', uri=True) as db:
            row = db.execute('SELECT body FROM actions WHERE id=?', (action_id,)).fetchone()
        if row is None:
            raise ProviderEffectError('original_intent_missing')
        return json.loads(row[0])

    def _receipt(self, intent: dict, outcome: str, **extra) -> dict:
        return {'schema': 'servernet.relay.effect.v1', 'receipt': {
            'intent_id': intent['id'], 'kind': intent['kind'], 'outcome': outcome,
            'verified': True, 'received_at': self.now(), **extra}}

    def _identity(self, resource: hetzner.ManagedServer) -> dict:
        return dict(provider='hetzner', project_id=self.policy.project_id,
                    provider_id=resource.provider_id, generation=resource.generation)

    def _creation_spec(self, original: dict) -> hetzner.CandidateIntent:
        option, binding = original['payload']['option'], self.binding
        if (option.get('project_id') != self.policy.project_id or option.get('owner_id') != self.policy.owner_id
                or option.get('service_id') != self.policy.service_id or option.get('provider') != 'hetzner'):
            raise ProviderEffectError('creation_scope_mismatch')
        bootstrap = self.settings.get('bootstrap', {})
        path = _pinned(bootstrap['path'], bootstrap['sha256'])
        content = path.read_text()
        spec = hetzner.CandidateIntent(original['id'], original['incident_id'],
            original['payload']['next_generation'], self.policy.owner_id, self.policy.service_id,
            original['payload']['name'], option['server_type'], option['location'], str(option['image']),
            tuple(str(v) for v in self.settings.get('ssh_key_ids', [])), original['created_at'],
            content, hashlib.sha256(content.encode()).hexdigest())
        spec.validate(self.now())
        return spec

    def _create(self, intent: dict, state: dict) -> dict:
        reconciling = intent['kind'] == 'RECONCILE_CREATE'
        original_id = intent['payload']['create_intent_id'] if reconciling else intent['id']
        if state.get('phase') != 'CREATE_UNKNOWN' or state.get('pending_create') != original_id:
            raise ProviderEffectError('creation_phase_mismatch')
        original = self._original_action(original_id)
        with self.store.transaction() as db:
            row = db.execute('SELECT * FROM creates WHERE id=?', (original_id,)).fetchone()
        if row is None:
            spec = self._creation_spec(original)
            with self.store.transaction() as db:
                db.execute('INSERT OR IGNORE INTO creates VALUES(?,?,?,NULL)',
                           (original_id, core.canonical(dataclasses.asdict(spec)), 'SPEC_SAVED'))
                row = db.execute('SELECT * FROM creates WHERE id=?', (original_id,)).fetchone()
        raw = json.loads(row['spec']); raw['ssh_key_ids'] = tuple(raw['ssh_key_ids'])
        spec, phase = hetzner.CandidateIntent(**raw), row['phase']
        if (spec.intent_id != original_id or spec.generation != original['payload']['next_generation']
                or spec.owner_id != self.policy.owner_id or spec.service_id != self.policy.service_id
                or spec.name != original['payload']['name']
                or (spec.server_type, spec.location, spec.image) != tuple(str(original['payload']['option'][key])
                    for key in ('server_type', 'location', 'image'))):
            raise ProviderEffectError('stored_creation_scope_mismatch')
        client = self._client()
        self._scope_anchor(client, state)
        prior = client.reconcile_candidate(spec)
        if prior.state == 'absent' and reconciling and phase in ('SPEC_SAVED', 'NOT_SUBMITTED'):
            # All writes in this adapter first persist POST_CLAIMED. A crash
            # before that marker is the one provable non-submission case.
            return self._receipt(intent, 'absent', create_intent_id=original_id, definitive_rejection=True)
        if prior.state == 'absent' and not reconciling and phase == 'SPEC_SAVED':
            options = client.quotes(expected_currency=self.policy.budget_currency,
                predicted_outgoing_bytes=self.settings.get('predicted_outgoing_bytes', 0),
                min_memory_gib=self.settings.get('min_memory_gib', '2'), min_disk_gb=self.settings.get('min_disk_gb', 20))
            quotes = [q for q in options if q.server_type == spec.server_type and q.location == spec.location]
            cap = original['payload']['option']['monthly_gross_micro']
            if len(quotes) != 1 or quotes[0].predicted_monthly_micro > cap:
                return self._receipt(intent, 'failed', definitive_rejection=True, create_intent_id=original_id,
                                     reason='price_or_capacity_changed')
            if self.now() > intent['expires_at']:
                raise ProviderEffectError('effect_intent_expired')
            # A concurrent adapter invocation must not independently claim the
            # same purchase, even when both previously read SPEC_SAVED.
            with self.store.transaction() as db:
                claimed = db.execute('UPDATE creates SET phase=? WHERE id=? AND phase=?',
                    ('POST_CLAIMED', original_id, 'SPEC_SAVED')).rowcount == 1

            def authorize_create():
                try:
                    rows = client._pages('/servers', 'servers')
                    managed = [str(r.get('id')) for r in rows
                               if r.get('labels', {}).get('sn-managed') == hetzner.MANAGER
                               and r.get('labels', {}).get('sn-owner') == self.policy.owner_id
                               and r.get('labels', {}).get('sn-service') == self.policy.service_id]
                    if managed != [state['active']['provider_id']]:
                        raise ProviderEffectError('concurrent_managed_capacity_changed')
                    _, current = self._committed('CREATE', {'intent': intent})
                    if (current.get('phase') != 'CREATE_UNKNOWN' or current.get('pending_create') != original_id
                            or not core.same_identity(current['active'], state['active'])):
                        raise ProviderEffectError('creation_scope_changed')
                except Exception:
                    # This callback runs immediately before POST, so failure
                    # proves this claimed attempt has never been submitted.
                    with self.store.transaction() as db:
                        db.execute('UPDATE creates SET phase=? WHERE id=? AND phase=?',
                                   ('NOT_SUBMITTED', original_id, 'POST_CLAIMED'))
                    raise

            result = (client.create_candidate(spec, quotes[0], authorize=authorize_create) if claimed else
                      hetzner.OperationResult('create_unknown', 'submission_already_claimed'))
        else:
            # This path is GET-only, even if the original POST's outcome is absent.
            result = prior if prior.state != 'absent' else hetzner.OperationResult('create_unknown', 'unresolved_submission')
        if result.server is not None and result.state in ('created', 'found'):
            self.store.save_resource(result.server)
            with self.store.transaction() as db:
                db.execute('UPDATE creates SET phase=?,resource=? WHERE id=?',
                           ('CAPTURED', result.server.provider_id, original_id))
            # IP labels are an effect, therefore never applied in reconciliation.
            # A future fresh deletion permit can finish IP tagging first.
            if not reconciling:
                client.tag_captured_ips(result.server)
            return self._receipt(intent, result.state, identity=self._identity(result.server),
                                 create_intent_id=original_id)
        definitive = result.state == 'blocked' and (result.reason.startswith('create_rejected_4')
            or result.reason in {'stale_or_missing_quote', 'quote_spec_mismatch', 'incompatible_image'})
        return self._receipt(intent, 'failed' if definitive else 'unknown',
                             create_intent_id=original_id, definitive_rejection=definitive,
                             reason=result.reason or result.state)

    def _fresh_scope(self, receipt: dict, intent: dict, resource: dict) -> bool:
        return (isinstance(receipt, dict) and receipt.get('verified') is True
                and type(receipt.get('received_at')) is int
                and 0 <= self.now() - receipt['received_at'] <= self.policy.receipt_max_age
                and receipt.get('incident_id') == intent['incident_id']
                and receipt.get('generation') == resource['generation']
                and receipt.get('config_hash') == resource['config_hash'])

    def _resource(self, scoped: dict, state: dict) -> hetzner.ManagedServer:
        record = self.store.read_resource(scoped['provider_id'])
        if self._identity(record) != core.identity(scoped):
            raise ProviderEffectError('stored_resource_scope_mismatch')
        if (record.labels.get('sn-owner') != self.policy.owner_id
                or record.labels.get('sn-service') != self.policy.service_id):
            raise ProviderEffectError('stored_resource_owner_mismatch')
        if core.same_identity(state['active'], scoped) and record.promoted_at is None:
            record = dataclasses.replace(record, promoted_at=state.get('last_promotion_at') or self.now(), has_writes=True)
            self.store.save_resource(record)
        return record

    def _scope_anchor(self, client, state):
        active = self._resource(state['active'], state)
        self._verified_server(client, active)
        return active

    def _reconcile_delete(self, client, intent, state, resource) -> dict:
        self._scope_anchor(client, state)
        target = client._request('GET', '/servers/' + hetzner._identifier(resource.provider_id))
        ident = self._identity(resource)
        if target.status == 404:
            remaining = []
            for ip_id in resource.primary_ip_ids:
                reply = client._request('GET', '/primary_ips/' + hetzner._identifier(ip_id))
                if reply.status == 404:
                    continue
                ip = client._ok(reply).get('primary_ip', {})
                if (str(ip.get('id')) != ip_id or ip.get('labels') != resource.labels
                        or ip.get('assignee_id') is not None or ip.get('assignee_type') not in (None, 'unassigned')):
                    return self._receipt(intent, 'pending', identity=ident, reason='residual_ip_scope_unconfirmed')
                actions = client._pages('/primary_ips/' + ip_id + '/actions', 'actions')
                if any(a.get('status') == 'running' for a in actions):
                    return self._receipt(intent, 'pending', identity=ident)
                remaining.append(ip_id)
            if remaining:
                return self._receipt(intent, 'cleanup_required', identity=ident,
                    server_absence_verified=True, delete_not_pending=True, remaining_primary_ips=remaining)
            return self._receipt(intent, 'deleted', identity=ident,
                                 absence_verified=True, dependent_resources_absent=True)
        raw = client._ok(target).get('server', {})
        if str(raw.get('id')) != resource.provider_id or raw.get('labels') != resource.labels or raw.get('name') != resource.name:
            raise ProviderEffectError('delete_identity_changed')
        actions = client._pages('/servers/' + resource.provider_id + '/actions', 'actions')
        if raw.get('status') == 'deleting' or any(a.get('command') == 'delete_server' and a.get('status') == 'running' for a in actions):
            return self._receipt(intent, 'pending', identity=ident)
        return self._receipt(intent, 'present', identity=ident, delete_not_pending=True)

    def _delete(self, intent: dict, state: dict, evidence: dict):
        candidate = 'CANDIDATE' in intent['kind']
        reconciling = intent['kind'].startswith('RECONCILE_')
        field, phase = ('candidate', 'DELETE_CANDIDATE_PENDING') if candidate else ('retiring', 'RETIRE_PENDING')
        original_id = intent['payload']['delete_intent_id'] if reconciling else intent['id']
        if state.get('phase') != phase or state.get('pending_action') != original_id or state.get(field) is None:
            raise ProviderEffectError('delete_phase_mismatch')
        scoped = state[field]
        if not core.same_identity(intent['payload'].get('identity', {}), scoped) or core.same_identity(scoped, state['active']):
            raise ProviderEffectError('delete_active_or_wrong_generation')
        record = self._resource(scoped, state)
        client = self._client()
        if reconciling:
            try:
                return self._reconcile_delete(client, intent, state, record)
            except Exception:
                return self._receipt(intent, 'unknown', identity=self._identity(record), reason='deletion_reconciliation_unknown')
        engine = None if candidate else core.Engine(self.policy, core.Journal(self.root/'controller.sqlite3'))

        def authorize():
            # HTTP reads/tagging can be slow. Use the original evidence and
            # committed deadline again immediately before every actual DELETE.
            _, current = self._committed(intent['kind'], {'intent': intent})
            if (current.get('phase') != phase or current.get('pending_action') != original_id
                    or not core.same_identity(current.get(field) or {}, scoped)
                    or not core.same_identity(current['active'], state['active'])):
                raise ProviderEffectError('delete_scope_changed')
            if candidate:
                cleanup = evidence.get('cleanup')
                if (not self._fresh_scope(cleanup, intent, scoped) or cleanup.get('never_promoted') is not True
                        or cleanup.get('no_actual_writes') is not True
                        or not core.same_identity(cleanup.get('identity', {}), scoped)
                        or core.digest(cleanup) != intent['payload'].get('cleanup_hash')):
                    raise ProviderEffectError('candidate_cleanup_evidence_changed')
                return core.digest(cleanup)
            if not engine._retire_gate(current, evidence, self.now()):
                raise ProviderEffectError('retirement_gates_changed')
            current_gate = core.digest({k: evidence[k] for k in ('backup', 'fencing', 'ttl', 'active_refs', 'critical_receipts')})
            if current_gate != intent['payload'].get('gate_hash'):
                raise ProviderEffectError('retirement_evidence_changed')
            return current_gate

        gate = authorize()
        self._scope_anchor(client, state)
        # An interrupted CREATE may have captured IDs before their label PUT.
        # A fresh cleanup permit may finish that exact ownership assignment;
        # GET-only reconciliation can never do it. An absent server skips it.
        present = client._request('GET', '/servers/' + record.provider_id)
        if present.status != 404:
            client._ok(present)
            tagged = client.tag_captured_ips(record)
            if tagged.state != 'tagged':
                return self._receipt(intent, 'pending', identity=self._identity(record),
                                     reason='captured_ip_tagging_pending')
        permit = hetzner.DeletePermit('rejected_candidate' if candidate else 'retired_generation',
            record.provider_id, record.generation, self.policy.owner_id, self.policy.service_id,
            str(state['revision']), self.now(), (state['active']['provider_id'],), gate,
            no_writes=candidate)
        result = client.delete_server(record, permit, authorize=authorize)
        return self._receipt(intent, 'deleted' if result.state == 'deleted' else 'pending',
            identity=self._identity(record), absence_verified=result.state == 'deleted',
            dependent_resources_absent=result.state == 'deleted', reason=result.reason,
            remaining_primary_ips=list(result.remaining_ip_ids))

    def execute(self, action: str, payload: dict) -> dict:
        if action not in self.ACTIONS:
            raise ProviderEffectError('unsupported_provider_effect')
        intent, state = self._committed(action, payload)
        if action in ('CREATE', 'RECONCILE_CREATE'):
            return self._create(intent, state)
        return self._delete(intent, state, payload.get('evidence', {}))

    def adoption_plan(self) -> dict:
        """Read-only provider/app inspection; persists a private prepared plan."""
        described = self.bridge.request('describe')
        if described.get('internal_protected') is not True:
            raise ProviderEffectError('application_protection_required')
        live_binding = described.get('binding', {})
        for key in ('owner_id', 'service_id', 'provider_id', 'ipv4'):
            if str(live_binding.get(key)) != str(self.binding.get(key)):
                raise ProviderEffectError('adoption_app_binding_changed')
        active = self.config.get('active', {})
        if (str(active.get('provider_id')) != str(self.binding['provider_id'])
                or active.get('provider') != 'hetzner' or active.get('project_id') != self.policy.project_id):
            raise ProviderEffectError('adoption_seed_mismatch')
        client = self._client()
        reply = client._request('GET', '/servers/' + hetzner._identifier(active['provider_id']))
        server = client._ok(reply).get('server', {})
        ip = (server.get('public_net', {}).get('ipv4') or {})
        if (str(server.get('id')) != str(active['provider_id']) or ip.get('ip') != self.binding['ipv4']
                or not isinstance(server.get('name'), str) or not isinstance(server.get('labels'), dict)):
            raise ProviderEffectError('adoption_provider_binding_changed')
        ids = tuple(hetzner._identifier(v['id']) for family in ('ipv4', 'ipv6')
                    if (v := server.get('public_net', {}).get(family) or {}).get('id') is not None)
        if ip.get('id') is None:
            raise ProviderEffectError('adoption_ip_identity_missing')
        labels = dict(server['labels'])
        desired = {'sn-managed': hetzner.MANAGER, 'sn-owner': self.policy.owner_id,
                   'sn-service': self.policy.service_id, 'sn-generation': str(active['generation']),
                   'sn-incident': 'adoption-v1', 'sn-intent': 'adopt-' + core.digest(active)[:24]}
        if any(key in labels and labels[key] != value for key, value in desired.items()):
            raise ProviderEffectError('adoption_existing_ownership_conflict')
        desired = {**labels, **desired}
        plan = dict(identity=core.identity(active), name=server['name'], ipv4=ip['ip'],
                    primary_ip_ids=ids, before_labels=labels, after_labels=desired,
                    owner_id=self.policy.owner_id, service_id=self.policy.service_id, observed_at=self.now())
        plan_id = core.digest(plan)
        with self.store.transaction() as db:
            db.execute('INSERT OR IGNORE INTO adoptions VALUES(?,?,?)', (plan_id, core.canonical(plan), 'PREPARED'))
        return {'plan_id': plan_id, 'identity': core.identity(active), 'name': server['name'],
                'ipv4': ip['ip'], 'requires_label_write': labels != desired}

    def adopt(self, plan_id: str) -> dict:
        """Explicit setup mutation; never called by runtime evidence or collect."""
        if self.settings.get('adoption_enabled') is not True:
            raise ProviderEffectError('adoption_not_enabled')
        with self.store.transaction() as db:
            row = db.execute('SELECT plan FROM adoptions WHERE id=?', (plan_id,)).fetchone()
        if row is None:
            raise ProviderEffectError('adoption_plan_missing')
        plan = json.loads(row['plan'])
        if core.digest(plan) != plan_id or not 0 <= self.now() - plan['observed_at'] <= 300:
            raise ProviderEffectError('adoption_plan_stale')
        described = self.bridge.request('describe')
        if (described.get('internal_protected') is not True
                or str(described.get('binding', {}).get('provider_id')) != plan['identity']['provider_id']
                or str(described.get('binding', {}).get('owner_id')) != plan['owner_id']
                or str(described.get('binding', {}).get('service_id')) != plan['service_id']):
            raise ProviderEffectError('adoption_app_binding_changed')
        client = self._client()
        server_id = hetzner._identifier(plan['identity']['provider_id'])
        current = client._ok(client._request('GET', '/servers/' + server_id)).get('server', {})
        ip = (current.get('public_net', {}).get('ipv4') or {})
        ids = tuple(hetzner._identifier(v['id']) for family in ('ipv4', 'ipv6')
                    if (v := current.get('public_net', {}).get(family) or {}).get('id') is not None)
        if (str(current.get('id')) != server_id or current.get('name') != plan['name']
                or ip.get('ip') != plan['ipv4'] or set(ids) != set(plan['primary_ip_ids'])
                or current.get('labels') not in (plan['before_labels'], plan['after_labels'])):
            raise ProviderEffectError('adoption_provider_identity_changed')
        with self.store.transaction() as db:
            db.execute('UPDATE adoptions SET phase=? WHERE id=?', ('ADOPTING', plan_id))
        if current['labels'] != plan['after_labels']:
            client._ok(client._request('PUT', '/servers/' + server_id, {'labels': plan['after_labels']}))
        after = client._ok(client._request('GET', '/servers/' + server_id)).get('server', {})
        if after.get('labels') != plan['after_labels'] or str(after.get('id')) != server_id:
            raise ProviderEffectError('adoption_label_write_unconfirmed')
        resource = hetzner.ManagedServer(server_id, plan['name'], plan['after_labels'], plan['identity']['generation'],
            tuple(plan['primary_ip_ids']), plan['ipv4'], self.now(), promoted_at=self.now(), has_writes=True)
        self.store.save_resource(resource)
        tagged = client.tag_captured_ips(resource)
        if tagged.state != 'tagged':
            raise ProviderEffectError('adoption_ip_tagging_pending')
        with self.store.transaction() as db:
            db.execute('UPDATE adoptions SET phase=? WHERE id=?', ('ADOPTED', plan_id))
        return {'adopted': True, 'identity': self._identity(resource), 'plan_id': plan_id}
