"""Exact-ID, durable Hetzner disk snapshot actions for a future workflow.

This module does not install a workflow, infer authority from PROMOTE, retry an
unknown write, power off a VM forcibly, or claim application restore success.
The caller must durably commit a new PREPARE_* / DELETE_SNAPSHOT workflow intent
and provide a local authorization callback that verifies that current intent.

API contracts verified 2026-11-02 against the official API/vendor schema:
https://docs.hetzner.cloud/reference/cloud
https://docs.hetzner.cloud/changelog#2026-01-16-user-data-server-rebuild
https://pkg.go.dev/github.com/hetznercloud/hcloud-go/v2/hcloud/schema
Snapshots exclude Volumes; attached Volumes are therefore a blocker here.
"""
from __future__ import annotations

import contextlib
import dataclasses
from datetime import datetime
from decimal import Decimal, InvalidOperation
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import sqlite3
import time
import uuid

from . import core, hetzner
from .gateway import private_file
from .provider_adapter import ProviderEffects, _directory


KINDS = frozenset({'PREPARE_SHUTDOWN', 'PREPARE_SNAPSHOT', 'PREPARE_REBUILD', 'DELETE_SNAPSHOT'})
COMMANDS = {'PREPARE_SHUTDOWN': 'shutdown_server', 'PREPARE_SNAPSHOT': 'create_image',
            'PREPARE_REBUILD': 'rebuild_server'}
MANAGER = 'relay-recovery-snapshot-v1'
POLL_SECONDS = 5


class SnapshotError(core.RecoveryError):
    """Fixed, non-secret failure codes only."""


def _uuid(value):
    try:
        parsed = uuid.UUID(value)
        if str(parsed) != value:
            raise ValueError()
        return value
    except (ValueError, TypeError, AttributeError):
        raise SnapshotError('workflow_uuid_required') from None


def _number(value):
    if isinstance(value, bool):
        raise SnapshotError('invalid_disk_size')
    try:
        n = Decimal(str(value))
    except InvalidOperation:
        raise SnapshotError('invalid_disk_size') from None
    if not n.is_finite() or n <= 0:
        raise SnapshotError('invalid_disk_size')
    return n


def _timestamp(value):
    try:
        stamp = datetime.fromisoformat(value.replace('Z', '+00:00'))
        if stamp.tzinfo is None:
            raise ValueError()
        return stamp.timestamp()
    except (TypeError, ValueError, AttributeError):
        raise SnapshotError('action_timestamp_invalid') from None


class SnapshotStore:
    def __init__(self, root):
        self.root = _directory(Path(root))
        self.path, self.lock = self.root/'snapshot-effects.sqlite3', self.root/'snapshot-effects.lock'
        with self.transaction() as db:
            db.executescript('''
                CREATE TABLE IF NOT EXISTS operations(
                  id TEXT PRIMARY KEY, workflow_key TEXT UNIQUE NOT NULL, body TEXT NOT NULL,
                  phase TEXT NOT NULL, claimed_at INTEGER, action_id TEXT, image_id TEXT,
                  result TEXT, last_poll INTEGER);
                CREATE TABLE IF NOT EXISTS images(
                  id TEXT PRIMARY KEY, snapshot_intent TEXT UNIQUE NOT NULL, body TEXT NOT NULL,
                  deleted INTEGER NOT NULL DEFAULT 0);
            ''')
        os.chmod(self.path, 0o600)

    @contextlib.contextmanager
    def transaction(self):
        if self.lock.exists() or self.lock.is_symlink(): private_file(self.lock)
        fd = os.open(self.lock, os.O_CREAT | os.O_RDWR | getattr(os, 'O_NOFOLLOW', 0), 0o600)
        db = None
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            if self.path.exists() or self.path.is_symlink(): private_file(self.path)
            mask = os.umask(0o077)
            try: db = sqlite3.connect(self.path, timeout=0, isolation_level=None)
            finally: os.umask(mask)
            db.row_factory = sqlite3.Row
            db.execute('PRAGMA synchronous=FULL')
            db.execute('BEGIN IMMEDIATE')
            yield db
            if db.in_transaction: db.commit()
        except Exception:
            if db is not None and db.in_transaction: db.rollback()
            raise
        finally:
            if db is not None: db.close()
            os.close(fd)

    def operation(self, operation_id):
        _uuid(operation_id)
        with self.transaction() as db:
            row = db.execute('SELECT * FROM operations WHERE id=?', (operation_id,)).fetchone()
        if row is None: raise SnapshotError('snapshot_intent_missing')
        return dict(row)


class SnapshotAdapter:
    """A reusable provider layer; callers own all application/workflow gates.

    Permit dictionary fields: intent_id/workflow_id canonical UUIDs; kind one of
    KINDS; incident_id valid label; source_identity exact adopted identity;
    config_hash SHA-256; created_at/expires_at integers with <=30s write lifetime.
    Rebuild also needs candidate_identity/candidate_no_writes=True/
    candidate_never_promoted=True. Snapshot needs max_storage_monthly_micro and
    currency; this reserves a conservative full-primary-disk monthly cost.
    authorize(permit) must raise or return False when not currently authorized.
    No callback means all mutations are disabled. Reconciliation needs none.
    """
    def __init__(self, provider: ProviderEffects, *, authorize=None, now=lambda: int(time.time())):
        self.provider, self.authorize, self.now = provider, authorize, now
        self.store = SnapshotStore(provider.root)

    def _permit(self, permit, kind, *, write=True):
        if not isinstance(permit, dict) or permit.get('kind') != kind or kind not in KINDS:
            raise SnapshotError('explicit_workflow_permit_required')
        _uuid(permit.get('intent_id')); _uuid(permit.get('workflow_id'))
        if not isinstance(permit.get('incident_id'), str):
            raise SnapshotError('workflow_incident_required')
        hetzner._label(permit['incident_id'])
        source = core.identity(permit.get('source_identity', {}))
        if source['provider'] != 'hetzner' or source['project_id'] != self.provider.policy.project_id:
            raise SnapshotError('workflow_project_mismatch')
        if not re.fullmatch('[a-f0-9]{64}', permit.get('config_hash') or ''):
            raise SnapshotError('workflow_config_hash_required')
        if write:
            now = self.now()
            start, end = permit.get('created_at'), permit.get('expires_at')
            if not (type(start) is int and type(end) is int and start <= now <= end and 0 < end-start <= 30):
                raise SnapshotError('workflow_permit_expired')
            if not self.provider.policy.enabled or self.authorize is None:
                raise SnapshotError('workflow_writes_disabled')
            if self.authorize(copy_json(permit)) is False:
                raise SnapshotError('workflow_authorization_denied')
            if not start <= self.now() <= end:
                raise SnapshotError('workflow_permit_expired')
        return source

    def _state(self):
        path = self.provider.root/'controller.sqlite3'
        private_file(path)
        with sqlite3.connect(path.as_uri()+'?mode=ro', uri=True) as db:
            row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
        if row is None: raise SnapshotError('controller_scope_missing')
        state = json.loads(row[0])
        if state.get('clock_unsafe') is not False:
            raise SnapshotError('controller_clock_unsafe')
        return state

    def _record(self, scoped):
        record = self.provider.store.read_resource(scoped['provider_id'])
        if self.provider._identity(record) != core.identity(scoped):
            raise SnapshotError('managed_record_scope_mismatch')
        if (record.labels.get('sn-managed') != hetzner.MANAGER
                or record.labels.get('sn-owner') != self.provider.policy.owner_id
                or record.labels.get('sn-service') != self.provider.policy.service_id):
            raise SnapshotError('managed_record_owner_mismatch')
        return record

    def _anchor(self, client):
        state = self._state()
        active = self._record(state['active'])
        self.provider._verified_server(client, active)
        return state

    def _source(self, client, permit):
        state = self._anchor(client)
        if not core.same_identity(state['active'], permit['source_identity']):
            raise SnapshotError('source_is_not_current_active')
        if state['active'].get('config_hash') != permit['config_hash']:
            raise SnapshotError('source_configuration_changed')
        source = self._record(permit['source_identity'])
        raw = self.provider._verified_server(client, source)
        if raw.get('volumes'):
            raise SnapshotError('attached_volumes_are_not_snapshotted')
        if raw.get('server_type', {}).get('architecture') != 'x86':
            raise SnapshotError('source_architecture_not_x86')
        _number(raw.get('primary_disk_size'))
        return state, source, raw

    def _addresses(self, raw):
        result = {}
        for family in ('ipv4','ipv6'):
            value = raw.get('public_net', {}).get(family)
            if value is None: continue
            try:
                address = (str(ipaddress.IPv4Address(value.get('ip'))) if family == 'ipv4'
                           else str(ipaddress.IPv6Network(value.get('ip'), strict=True)))
            except (ValueError, TypeError):
                raise SnapshotError('candidate_public_address_incomplete') from None
            result[family] = {'id': hetzner._identifier(value.get('id')), 'ip': address}
        if 'ipv4' not in result: raise SnapshotError('candidate_public_address_incomplete')
        return result

    def _result(self, row, state, **extra):
        return dict(intent_id=row['id'], kind=json.loads(row['body'])['permit']['kind'], state=state,
                    action_id=row['action_id'], image_id=row['image_id'], observed_at=self.now(),
                    retry_at=self.now()+POLL_SECONDS if state in ('pending', 'unknown') else None, **extra)

    def _new(self, permit, spec, client, target_id):
        """Persist immutable IDs/spec/baseline before the first POST claim."""
        old = None
        try: old = self.store.operation(permit['intent_id'])
        except SnapshotError as exc:
            if str(exc) != 'snapshot_intent_missing': raise
        body = dict(permit=permit, spec=spec)
        if old is not None:
            if core.canonical(json.loads(old['body'])) != core.canonical(body):
                raise SnapshotError('workflow_intent_payload_changed')
            return False
        actions = client._pages('/servers/'+target_id+'/actions', 'actions') if permit['kind'] != 'DELETE_SNAPSHOT' else []
        if any(a.get('status') == 'running' for a in actions):
            raise SnapshotError('server_action_already_running')
        body['spec']['baseline_action_ids'] = [hetzner._identifier(a.get('id')) for a in actions]
        # A new UUID cannot bypass an unresolved previous mutation on this VM.
        workflow_key = ':'.join((permit['workflow_id'],permit['kind'],target_id,permit['intent_id']))
        with self.store.transaction() as db:
            rows = db.execute("SELECT body FROM operations WHERE phase IN ('CLAIMED','KNOWN')").fetchall()
            for row in rows:
                other = json.loads(row['body'])
                if (permit['kind'] != 'DELETE_SNAPSHOT' and other['permit']['kind'] == permit['kind']
                        and other['spec']['target_id'] == target_id):
                    raise SnapshotError('previous_action_outcome_unknown')
            completed = db.execute("SELECT body FROM operations WHERE phase='COMPLETE'").fetchall()
            if any(permit['kind'] != 'DELETE_SNAPSHOT' and json.loads(r['body'])['permit']['kind'] == permit['kind']
                   and json.loads(r['body'])['permit']['workflow_id'] == permit['workflow_id']
                   and json.loads(r['body'])['spec']['target_id'] == target_id for r in completed):
                raise SnapshotError('workflow_step_already_complete')
            try:
                db.execute('INSERT INTO operations(id,workflow_key,body,phase) VALUES(?,?,?,?)',
                           (permit['intent_id'], workflow_key, core.canonical(body), 'PREPARED'))
            except sqlite3.IntegrityError:
                raise SnapshotError('workflow_step_already_exists') from None
        return True

    def _existing(self, permit, **dependencies):
        try: row = self.store.operation(permit['intent_id'])
        except SnapshotError as exc:
            if str(exc) == 'snapshot_intent_missing': return None
            raise
        body = json.loads(row['body'])
        if (core.canonical(body['permit']) != core.canonical(permit)
                or any(body['spec'].get(k) != v for k,v in dependencies.items())):
            raise SnapshotError('workflow_intent_payload_changed')
        return self.reconcile(permit['intent_id'])

    def _claim(self, permit):
        self._permit(permit, permit['kind'])
        with self.store.transaction() as db:
            own = db.execute('SELECT body FROM operations WHERE id=?', (permit['intent_id'],)).fetchone()
            body = json.loads(own['body']); kind, spec = permit['kind'], body['spec']
            if kind in COMMANDS:
                others = db.execute("SELECT id,phase,body FROM operations WHERE phase IN ('CLAIMED','KNOWN','COMPLETE') AND id<>?",
                                    (permit['intent_id'],)).fetchall()
                for row in others:
                    other = json.loads(row['body'])
                    if other['permit']['kind'] != kind or other['spec']['target_id'] != spec['target_id']: continue
                    if (row['phase'] != 'COMPLETE' or other['permit']['workflow_id'] == permit['workflow_id']):
                        raise SnapshotError('previous_action_outcome_unknown' if row['phase'] != 'COMPLETE'
                                            else 'workflow_step_already_complete')
            if kind in ('DELETE_SNAPSHOT','PREPARE_REBUILD'):
                # A restore and deletion of its image cannot acquire claims
                # concurrently, even across different UUIDs/processes.
                other_kind = 'PREPARE_REBUILD' if kind == 'DELETE_SNAPSHOT' else 'DELETE_SNAPSHOT'
                pending = db.execute("SELECT body FROM operations WHERE phase IN ('CLAIMED','KNOWN')").fetchall()
                if any(json.loads(r['body'])['permit']['kind'] == other_kind
                       and json.loads(r['body'])['spec'].get('snapshot_id') == spec['snapshot_id'] for r in pending):
                    raise SnapshotError('snapshot_currently_used_by_restore' if kind == 'DELETE_SNAPSHOT'
                                        else 'snapshot_deletion_outcome_unknown')
            if kind == 'PREPARE_SNAPSHOT':
                # Revalue retained images/reservations at the current tariff;
                # a historic cheap quote cannot hide a storage price increase.
                unit = Decimal(spec['storage_reservation']['gross_per_gb_month'])
                retained = self._storage(db, unit)['reserved_monthly_micro']
                if retained + spec['storage_reservation']['reserved_monthly_micro'] > permit['max_storage_monthly_micro']:
                    raise SnapshotError('aggregate_snapshot_storage_budget_exceeded')
            claimed = db.execute("UPDATE operations SET phase='CLAIMED',claimed_at=? WHERE id=? AND phase='PREPARED'",
                                 (self.now(), permit['intent_id'])).rowcount
        return claimed == 1

    def _storage(self, db, unit=None):
        records, total, captured = [], 0, set()
        for row in db.execute('SELECT body FROM images WHERE deleted=0').fetchall():
            image = json.loads(row['body']); captured.add(image['snapshot_intent_id'])
            if image['currency'] != self.provider.policy.budget_currency:
                raise SnapshotError('retained_snapshot_currency_conflict')
            cost = image.get('monthly_micro', image['reserved_monthly_micro'])
            if unit is not None:
                if image.get('complete'):
                    size = _number(image['image_size'])
                else:
                    operation = db.execute('SELECT body FROM operations WHERE id=?', (image['snapshot_intent_id'],)).fetchone()
                    size = _number(json.loads(operation['body'])['spec']['source_disk_gb'])
                cost = hetzner._micro(unit*size)
            total += cost
            records.append({'image_id':image['id'], 'snapshot_intent_id':image['snapshot_intent_id'],
                            'monthly_micro':cost, 'complete':image.get('complete') is True})
        for row in db.execute("SELECT id,body FROM operations WHERE phase IN ('CLAIMED','KNOWN')").fetchall():
            body = json.loads(row['body'])
            if body['permit']['kind'] != 'PREPARE_SNAPSHOT' or row['id'] in captured: continue
            reservation = body['spec']['storage_reservation']
            if reservation['currency'] != self.provider.policy.budget_currency:
                raise SnapshotError('retained_snapshot_currency_conflict')
            cost = (reservation['reserved_monthly_micro'] if unit is None else
                    hetzner._micro(unit*_number(reservation['reserved_gb'])))
            total += cost
            records.append({'image_id':None, 'snapshot_intent_id':row['id'],'monthly_micro':cost,'complete':False})
        return {'currency':self.provider.policy.budget_currency, 'reserved_monthly_micro':total, 'snapshots':records}

    def storage_inventory(self):
        """Local durable costs, including paid POST outcomes still unknown."""
        with self.store.transaction() as db: return self._storage(db)

    def _capture(self, operation_id, *, action=None, image_id=None):
        with self.store.transaction() as db:
            row = db.execute('SELECT action_id,image_id FROM operations WHERE id=?', (operation_id,)).fetchone()
            action_id = hetzner._identifier(action['id']) if action is not None else row['action_id']
            image_id = hetzner._identifier(image_id) if image_id is not None else row['image_id']
            if ((row['action_id'] is not None and row['action_id'] != action_id)
                    or (row['image_id'] is not None and row['image_id'] != image_id)):
                raise SnapshotError('captured_provider_id_conflict')
            db.execute("UPDATE operations SET phase='KNOWN',action_id=?,image_id=? WHERE id=?",
                       (action_id, image_id, operation_id))

    def _action(self, raw, operation, *, image_id=None):
        body = json.loads(operation['body']); kind, spec = body['permit']['kind'], body['spec']
        target = spec['target_id']
        refs = raw.get('resources')
        if (not isinstance(refs, list) or raw.get('command') != COMMANDS[kind]
                or {str(r.get('id')) for r in refs if r.get('type') == 'server'} != {target}
                or raw.get('status') not in ('running', 'success', 'error')):
            raise SnapshotError('action_resource_scope_mismatch')
        hetzner._identifier(raw.get('id'))
        if _timestamp(raw.get('started')) < operation['claimed_at']:
            raise SnapshotError('action_predates_submission')
        images = {str(r.get('id')) for r in refs if r.get('type') == 'image'}
        if image_id is not None and images and images != {image_id}:
            raise SnapshotError('action_image_scope_mismatch')
        return raw

    def _post(self, permit, client, path, payload):
        # The callback is re-evaluated after all potentially slow provider GETs.
        try:
            _, _, source = self._source(client, permit)
            if permit['kind'] in ('PREPARE_SNAPSHOT','PREPARE_REBUILD') and source.get('status') != 'off':
                raise SnapshotError('source_no_longer_fenced_off')
            if permit['kind'] == 'PREPARE_REBUILD':
                row = self.store.operation(permit['intent_id']); spec = json.loads(row['body'])['spec']
                target = self._record(permit['candidate_identity'])
                current = self.provider._verified_server(client, target)
                if self._addresses(current) != spec['public_net_before']:
                    raise SnapshotError('candidate_public_ips_changed_before_rebuild')
            current_state = self._state()
            if (not core.same_identity(current_state['active'], permit['source_identity'])
                    or current_state['active'].get('config_hash') != permit['config_hash']):
                raise SnapshotError('current_source_workflow_scope_changed')
            if (permit['kind'] == 'PREPARE_REBUILD'
                    and not core.same_identity(current_state.get('candidate') or {}, permit['candidate_identity'])):
                raise SnapshotError('current_candidate_workflow_scope_changed')
            self._permit(permit, permit['kind'])
        except Exception:
            with self.store.transaction() as db:
                db.execute("UPDATE operations SET phase='NOT_SUBMITTED' WHERE id=? AND phase='CLAIMED'", (permit['intent_id'],))
            raise
        try:
            response = client._request('POST', path, payload)
        except hetzner.AdapterError:
            return self._result(self.store.operation(permit['intent_id']), 'unknown')
        row = self.store.operation(permit['intent_id'])
        if response.status != 201:
            if 400 <= response.status < 500 and response.status not in (408,409,429):
                with self.store.transaction() as db:
                    db.execute("UPDATE operations SET phase='FAILED' WHERE id=?", (row['id'],))
                return self._result(row, 'failed', definitive_rejection=True)
            return self._result(row, 'unknown')
        try:
            image = response.body.get('image')
            image_id = hetzner._identifier(image['id']) if isinstance(image, dict) else None
            action = self._action(response.body.get('action') or {}, row, image_id=image_id)
            if permit['kind'] == 'PREPARE_SNAPSHOT' and image_id is not None:
                self._image(image, row, final=False)
            self._capture(row['id'], action=action, image_id=image_id)
        except (SnapshotError, hetzner.AdapterError, KeyError, TypeError):
            return self._result(row, 'unknown')
        # Do not return root_password or unbounded provider/action messages.
        return self._result(self.store.operation(row['id']), 'pending')

    def _image_labels(self, permit):
        return {'sn-managed': MANAGER, 'sn-owner': self.provider.policy.owner_id,
                'sn-service': self.provider.policy.service_id,
                'sn-generation': str(permit['source_identity']['generation']),
                'sn-source': permit['source_identity']['provider_id'], 'sn-incident': permit['incident_id'],
                'sn-workflow': permit['workflow_id'], 'sn-intent': permit['intent_id']}

    def quote_storage(self, primary_disk_gb):
        disk = _number(primary_disk_gb)
        client = self.provider._client()
        p = client._ok(client._request('GET', '/pricing')).get('pricing', {})
        currency = p.get('currency')
        if currency != self.provider.policy.budget_currency or currency not in ('EUR', 'USD'):
            raise SnapshotError('snapshot_currency_mismatch')
        unit = hetzner._unit((p.get('image') or {}).get('price_per_gb_month', {}), hetzner._money(p.get('vat_rate')))
        if unit <= 0: raise SnapshotError('snapshot_tariff_invalid')
        return {'currency': currency, 'gross_per_gb_month': str(unit), 'reserved_gb': str(disk),
                'reserved_monthly_micro': hetzner._micro(unit*disk), 'observed_at': self.now()}

    def begin_shutdown(self, permit):
        self._permit(permit, 'PREPARE_SHUTDOWN')
        existing = self._existing(permit)
        if existing is not None: return existing
        client = self.provider._client()
        _, source, raw = self._source(client, permit)
        spec = {'target_id': source.provider_id, 'source_record': dataclasses.asdict(source),
                'source_disk_gb': str(_number(raw['primary_disk_size']))}
        if not self._new(permit, spec, client, source.provider_id): return self.reconcile(permit['intent_id'])
        if raw.get('status') == 'off':
            return self._complete(self.store.operation(permit['intent_id']), server_off=True, already_off=True)
        if not self._claim(permit): return self.reconcile(permit['intent_id'])
        return self._post(permit, client, '/servers/'+source.provider_id+'/actions/shutdown', None)

    def begin_snapshot(self, permit, shutdown_intent_id):
        self._permit(permit, 'PREPARE_SNAPSHOT')
        existing = self._existing(permit, shutdown_intent_id=shutdown_intent_id)
        if existing is not None: return existing
        shutdown = self.store.operation(shutdown_intent_id)
        prior = json.loads(shutdown['body'])
        if (shutdown['phase'] != 'COMPLETE' or prior['permit']['kind'] != 'PREPARE_SHUTDOWN'
                or prior['permit']['workflow_id'] != permit['workflow_id']
                or prior['permit']['source_identity'] != permit['source_identity']
                or prior['permit']['config_hash'] != permit['config_hash']):
            raise SnapshotError('completed_same_workflow_shutdown_required')
        client = self.provider._client()
        _, source, raw = self._source(client, permit)
        if raw.get('status') != 'off': raise SnapshotError('snapshot_source_not_off')
        quote = self.quote_storage(raw['primary_disk_size'])
        cap = permit.get('max_storage_monthly_micro')
        if (type(cap) is not int or cap <= 0 or quote['reserved_monthly_micro'] > cap
                or permit.get('currency') != quote['currency']):
            raise SnapshotError('snapshot_storage_budget_not_reserved')
        spec = {'target_id': source.provider_id, 'source_record': dataclasses.asdict(source),
                'source_disk_gb': str(_number(raw['primary_disk_size'])), 'storage_reservation': quote,
                'shutdown_intent_id': shutdown_intent_id, 'labels': self._image_labels(permit),
                'description': 'ServerNet relay snapshot '+permit['intent_id']}
        if not self._new(permit, spec, client, source.provider_id): return self.reconcile(permit['intent_id'])
        if not self._claim(permit): return self.reconcile(permit['intent_id'])
        return self._post(permit, client, '/servers/'+source.provider_id+'/actions/create_image',
                          {'type': 'snapshot', 'description': spec['description'], 'labels': spec['labels']})

    def _image(self, image, operation, *, final):
        body = json.loads(operation['body']); permit, spec = body['permit'], body['spec']
        if (not isinstance(image, dict) or image.get('type') != 'snapshot'
                or image.get('labels') != spec['labels'] or image.get('description') != spec['description']
                or str((image.get('created_from') or {}).get('id')) != spec['target_id']
                or image.get('bound_to') is not None or image.get('deleted') is not None):
            raise SnapshotError('snapshot_image_identity_conflict')
        image_id = hetzner._identifier(image.get('id'))
        if operation['image_id'] is not None and image_id != operation['image_id']:
            raise SnapshotError('snapshot_image_id_changed')
        quote = spec['storage_reservation']
        base = {'id': image_id, 'source_identity': permit['source_identity'], 'config_hash': permit['config_hash'],
                'labels': spec['labels'], 'description': spec['description'],
                'snapshot_intent_id': operation['id'], 'captured_at': self.now(), 'complete': False,
                'reserved_monthly_micro': quote['reserved_monthly_micro'], 'currency': quote['currency']}
        with self.store.transaction() as db:
            db.execute('INSERT OR IGNORE INTO images(id,snapshot_intent,body) VALUES(?,?,?)',
                       (image_id, operation['id'], core.canonical(base)))
        if final:
            if (image.get('status') != 'available' or image.get('architecture') != 'x86'
                    or image.get('deprecation') is not None
                    or _number(image.get('disk_size')) < _number(spec['source_disk_gb'])):
                raise SnapshotError('snapshot_image_not_complete_compatible')
            size = _number(image.get('image_size'))
            actual_cost = hetzner._micro(Decimal(quote['gross_per_gb_month'])*size)
            if actual_cost > quote['reserved_monthly_micro']:
                raise SnapshotError('snapshot_exceeds_storage_reservation')
            record = {**base, 'complete': True, 'disk_size': str(_number(image['disk_size'])),
                'image_size': str(size), 'monthly_micro': actual_cost, 'currency': quote['currency'],
                'snapshot_intent_id': operation['id'], 'captured_at': self.now()}
            with self.store.transaction() as db:
                old = db.execute('SELECT body FROM images WHERE id=?', (image_id,)).fetchone()
                if old:
                    previous = json.loads(old['body'])
                    keys = ('id','source_identity','config_hash','labels','description')
                    if previous.get('complete'): keys += ('disk_size','image_size')
                    for key in keys:
                        if previous[key] != record[key]: raise SnapshotError('snapshot_record_immutable_conflict')
                db.execute('UPDATE images SET body=? WHERE id=?', (core.canonical(record), image_id))
            return record
        return image_id

    def begin_rebuild(self, permit, snapshot_intent_id, user_data):
        self._permit(permit, 'PREPARE_REBUILD')
        if not isinstance(user_data, str) or not user_data or len(user_data.encode()) > 32768:
            raise SnapshotError('explicit_bounded_rebuild_user_data_required')
        user_data_hash = hashlib.sha256(user_data.encode()).hexdigest()
        existing = self._existing(permit, snapshot_intent_id=snapshot_intent_id, user_data_sha256=user_data_hash)
        if existing is not None: return existing
        if permit.get('candidate_no_writes') is not True or permit.get('candidate_never_promoted') is not True:
            raise SnapshotError('candidate_no_writes_permit_required')
        snapshot = self.store.operation(snapshot_intent_id)
        snapshot_body = json.loads(snapshot['body'])
        if (snapshot['phase'] != 'COMPLETE' or snapshot_body['permit']['kind'] != 'PREPARE_SNAPSHOT'
                or snapshot_body['permit']['workflow_id'] != permit['workflow_id']
                or snapshot_body['permit']['source_identity'] != permit['source_identity']
                or snapshot_body['permit']['config_hash'] != permit['config_hash']):
            raise SnapshotError('completed_same_workflow_snapshot_required')
        client = self.provider._client()
        state, _, source = self._source(client, permit)
        if source.get('status') != 'off': raise SnapshotError('rebuild_source_not_fenced_off')
        target = core.identity(permit.get('candidate_identity', {}))
        if not core.same_identity(state.get('candidate') or {}, target) or core.same_identity(state['active'], target):
            raise SnapshotError('rebuild_target_is_not_current_candidate')
        candidate = self._record(target)
        if candidate.promoted_at is not None or candidate.has_writes:
            raise SnapshotError('rebuild_target_has_production_writes')
        raw = self.provider._verified_server(client, candidate)
        if raw.get('volumes') or raw.get('private_net'):
            raise SnapshotError('rebuild_target_has_attached_resources')
        image = client._ok(client._request('GET', '/images/'+snapshot['image_id'])).get('image', {})
        image_record = self._image(image, snapshot, final=True)
        if (raw.get('server_type', {}).get('architecture') != 'x86'
                or _number(raw.get('primary_disk_size')) < _number(image_record['disk_size'])):
            raise SnapshotError('candidate_disk_or_architecture_incompatible')
        spec = {'target_id': candidate.provider_id, 'source_record': snapshot_body['spec']['source_record'],
                'candidate_record': dataclasses.asdict(candidate), 'snapshot_intent_id': snapshot_intent_id,
                'snapshot_id': snapshot['image_id'], 'user_data_sha256': user_data_hash,
                'public_net_before': self._addresses(raw)}
        if not self._new(permit, spec, client, candidate.provider_id): return self.reconcile(permit['intent_id'])
        if not self._claim(permit): return self.reconcile(permit['intent_id'])
        return self._post(permit, client, '/servers/'+candidate.provider_id+'/actions/rebuild',
                          {'image': int(snapshot['image_id']), 'user_data': user_data})

    def _complete(self, row, **proof):
        result = self._result(row, 'complete', **proof)
        with self.store.transaction() as db:
            db.execute("UPDATE operations SET phase='COMPLETE',result=? WHERE id=?", (core.canonical(result), row['id']))
        return result

    def reconcile(self, intent_id):
        """One read-only provider poll; the caller schedules retry_at, no sleep."""
        row = self.store.operation(intent_id)
        body = json.loads(row['body']); permit, spec = body['permit'], body['spec']
        self._permit(permit, permit['kind'], write=False)
        if row['phase'] in ('PREPARED','NOT_SUBMITTED'): return self._result(row, 'not_submitted', definitive_no_write=True)
        if row['phase'] == 'FAILED': return self._result(row, 'failed', definitive_rejection=True)
        if row['phase'] == 'COMPLETE': return json.loads(row['result'])
        if row['last_poll'] is not None and self.now()-row['last_poll'] < POLL_SECONDS:
            return self._result(row, 'pending', throttled=True)
        with self.store.transaction() as db:
            db.execute('UPDATE operations SET last_poll=? WHERE id=?', (self.now(), row['id']))
        client = self.provider._client()
        try:
            self._anchor(client)
            if permit['kind'] == 'DELETE_SNAPSHOT':
                reply = client._request('GET', '/images/'+spec['snapshot_id'])
                if reply.status == 404:
                    with self.store.transaction() as db:
                        db.execute('UPDATE images SET deleted=1 WHERE id=?', (spec['snapshot_id'],))
                    return self._complete(row, absence_verified=True)
                client._ok(reply)
                return self._result(row, 'present', delete_not_pending=True)
            action = None
            if row['action_id'] is not None:
                action = client._ok(client._request('GET', '/actions/'+row['action_id'])).get('action', {})
                self._action(action, row, image_id=row['image_id'] or spec.get('snapshot_id'))
            else:
                rows = client._pages('/servers/'+spec['target_id']+'/actions', 'actions')
                actions = [a for a in rows if str(a.get('id')) not in spec['baseline_action_ids']
                           and a.get('command') == COMMANDS[permit['kind']]
                           and _timestamp(a.get('started')) >= row['claimed_at']]
                if len(actions) == 1:
                    action = self._action(actions[0], row, image_id=row['image_id'] or spec.get('snapshot_id'))
                    self._capture(row['id'], action=action)
                    row = self.store.operation(row['id'])
                elif len(actions) > 1:
                    raise SnapshotError('ambiguous_action_matches')
            image_record = None
            if permit['kind'] == 'PREPARE_SNAPSHOT':
                if row['image_id'] is None:
                    images = client._pages('/images', 'images', query={'type': 'snapshot'})
                    matches = [i for i in images if i.get('description') == spec['description']
                               or i.get('labels', {}).get('sn-intent') == row['id']]
                    if len(matches) > 1: raise SnapshotError('ambiguous_snapshot_matches')
                    if matches:
                        image_id = self._image(matches[0], row, final=False)
                        self._capture(row['id'], image_id=image_id)
                        row = self.store.operation(row['id'])
                if row['image_id'] is not None:
                    image = client._ok(client._request('GET', '/images/'+row['image_id'])).get('image', {})
                    self._image(image, row, final=False)
                    if image.get('status') == 'available': image_record = self._image(image, row, final=True)
            if action is None: return self._result(row, 'unknown')
            if action['status'] == 'error':
                with self.store.transaction() as db:
                    db.execute("UPDATE operations SET phase='FAILED' WHERE id=?", (row['id'],))
                return self._result(row, 'failed', provider_action_error=True)
            if action['status'] != 'success': return self._result(row, 'pending')
            if permit['kind'] == 'PREPARE_SNAPSHOT':
                if image_record is None: return self._result(row, 'pending')
                return self._complete(row, snapshot=image_record, image_available=True)
            record = self._record(permit['source_identity'] if permit['kind'] == 'PREPARE_SHUTDOWN'
                                  else permit['candidate_identity'])
            raw = self.provider._verified_server(client, record)
            if permit['kind'] == 'PREPARE_SHUTDOWN':
                if raw.get('status') != 'off': return self._result(row, 'pending', server_off=False)
                return self._complete(row, server_off=True)
            if (self._addresses(raw) != spec['public_net_before']
                    or str((raw.get('image') or {}).get('id')) != spec['snapshot_id']):
                raise SnapshotError('rebuilt_candidate_identity_or_image_changed')
            return self._complete(row, provider_rebuild_complete=True, public_ips_preserved=True,
                                  candidate_identity=permit['candidate_identity'], application_verified=False)
        except (SnapshotError, hetzner.AdapterError, ValueError, KeyError, TypeError):
            return self._result(row, 'unknown', reason='provider_reconciliation_unproven')

    def _coverage(self, permit, record, coverage):
        if (not isinstance(coverage, dict) or coverage.get('verified') is not True
                or type(coverage.get('received_at')) is not int
                or not 0 <= self.now()-coverage['received_at'] <= 30
                or coverage.get('snapshot_id') != record['id']
                or coverage.get('source_identity') != record['source_identity']
                or coverage.get('config_hash') != record['config_hash']
                or coverage.get('durable') is not True or coverage.get('restore_verified') is not True
                or coverage.get('covers_snapshot') is not True or coverage.get('restore_in_use') is not False
                or not isinstance(coverage.get('coverage_id'), str) or not coverage['coverage_id']):
            raise SnapshotError('fresh_external_snapshot_coverage_required')

    def delete_snapshot(self, permit, snapshot_intent_id, coverage):
        self._permit(permit, 'DELETE_SNAPSHOT')
        existing = self._existing(permit, snapshot_intent_id=snapshot_intent_id, coverage_hash=core.digest(coverage))
        if existing is not None: return existing
        snapshot = self.store.operation(snapshot_intent_id)
        if snapshot['image_id'] is None: raise SnapshotError('exact_snapshot_id_not_captured')
        with self.store.transaction() as db:
            row = db.execute('SELECT body FROM images WHERE id=?', (snapshot['image_id'],)).fetchone()
        if row is None: raise SnapshotError('captured_snapshot_record_required')
        record = json.loads(row['body'])
        if (permit['source_identity'] != record['source_identity'] or permit['config_hash'] != record['config_hash']):
            raise SnapshotError('snapshot_delete_permit_scope_changed')
        self._coverage(permit, record, coverage)
        client = self.provider._client()
        self._anchor(client)  # Wrong project token 404 never proves absence.
        spec = {'target_id': record['id'], 'snapshot_id': record['id'], 'snapshot_intent_id': snapshot_intent_id,
                'coverage_hash': core.digest(coverage)}
        if not self._new(permit, spec, client, record['id']): return self.reconcile(permit['intent_id'])
        reply = client._request('GET', '/images/'+record['id'])
        if reply.status != 404:
            image = client._ok(reply).get('image', {})
            self._image(image, snapshot, final=False)
            if (image.get('protection') or {}).get('delete') is True:
                raise SnapshotError('snapshot_delete_protected')
            actions = client._pages('/images/'+record['id']+'/actions', 'actions')
            if any(a.get('status') == 'running' for a in actions):
                raise SnapshotError('snapshot_image_action_still_running')
        with self.store.transaction() as db:
            pending = db.execute("SELECT body FROM operations WHERE phase IN ('CLAIMED','KNOWN')").fetchall()
        if any(json.loads(r['body'])['permit']['kind'] == 'PREPARE_REBUILD'
               and json.loads(r['body'])['spec']['snapshot_id'] == record['id'] for r in pending):
            raise SnapshotError('snapshot_currently_used_by_restore')
        if not self._claim(permit): return self.reconcile(permit['intent_id'])
        self._coverage(permit, record, coverage)
        self._permit(permit, 'DELETE_SNAPSHOT')
        self._coverage(permit, record, coverage)
        if reply.status == 404:
            with self.store.transaction() as db: db.execute('UPDATE images SET deleted=1 WHERE id=?', (record['id'],))
            return self._complete(self.store.operation(permit['intent_id']), absence_verified=True)
        try:
            response = client._request('DELETE', '/images/'+record['id'])
            if response.status not in (200,204,404):
                return self._result(self.store.operation(permit['intent_id']), 'unknown')
        except hetzner.AdapterError:
            return self._result(self.store.operation(permit['intent_id']), 'unknown')
        return self.reconcile(permit['intent_id'])


def copy_json(value):
    return json.loads(core.canonical(value))
