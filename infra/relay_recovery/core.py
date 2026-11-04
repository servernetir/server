"""Fail-closed, provider-neutral relay recovery planner. Python 3.12 stdlib only.

There are no network calls, subprocesses, cloud credentials or Laravel mutations
in this module. Adapters must authenticate receipts before setting verified=True.

API::

    journal = Journal('/private/recovery.sqlite3')
    journal.initialize(active_identity, now)
    engine = Engine(Config(enabled=False, owner_id='1', service_id='20',
                           project_id='project'), journal)
    state = engine.observe(evidence, now)
    intent = engine.plan(state, evidence, now)  # pure; no effects or writes
    engine.commit_intent(intent, evidence, now) # durable BEFORE provider call
    # execute exactly intent.kind/payload, then:
    engine.record_effect(intent.id, receipt, now)

Private installation/workflow APIs (never expose through an agent endpoint)::

    intent = engine.claim_installation_trial(local_authorization, evidence, now)
    # The returned CREATE is ALREADY committed; execute/reconcile as usual.
    engine.begin_restore(committed_local_workflow_intent, now) # BEFORE fence/rebuild
    engine.record_restore_epoch(committed_local_restore_receipt, now)

claim_installation_trial requires a caller-authenticated local owner file. It
does not arm disabled runtime recovery and bypasses only the outage wait and
full restore/cutover readiness for one actual installation restore drill. It
uses the identical retained CREATE budget, inventory and attempt reservation.
The unique authorization is consumed atomically with the CREATE, never reused.
No trial authorization grants promotion or retirement permission.

record_restore_epoch accepts only a committed local workflow receipt for the
exact existing candidate/source and a matching prior begin_restore HOLD. A
failed restore remains held; no automatic abort/unfence/delete is authorized.
It clears candidate observation history. After
that, health and critical receipts for this resource must carry the exact
restore_epoch/restore_hash and be received after the rebuild. Receipt adapters
must derive these from authenticated post-restore observations, not add them to
old reports. Resources without a restore epoch retain the original protocol.

All times are integer UTC seconds. The controller supplies receipt received_at,
not a remote timestamp. Evidence freshness is rechecked when claiming an intent.
A backwards/implausible clock becomes CLOCK_UNSAFE until a verified local clock
receipt is observed. Probe gaps never count as healthy or failing observation.

Identity = provider/project_id/provider_id/generation. active_identity also has
config_hash. Evidence receipts share verified=True, received_at, incident_id,
generation, config_hash; health adds agent_id/direct_control/host_healthy/
internet_healthy/tunnel_ok/probe_ok. Candidate health is candidate_health.
critical_receipts is a list with consumer/ok. backup adds durable/checkpoint;
fencing adds old_generation/verified_fenced; ttl adds known/expires_at;
active_refs adds complete/count. cleanup adds identity/no_actual_writes/
never_promoted. Full evidence examples live in tests/test_core.py.

Retirement backups additionally supply covers_promotion_checkpoint equal to the
saved promotion checkpoint. The current checkpoint may have advanced; adapters
verify actual coverage, never lexical ordering of arbitrary checkpoint strings.
All RECONCILE_* intents are read-only. A read-only deletion reconciliation may
report present + delete_not_pending=True after proving no deletion is in flight;
only a subsequent new DELETE/RETIRE intent with fresh gates allows another write.

Effect receipts are bounded immutable JSON with intent_id, kind, outcome,
verified=True, received_at. Resource effects include exact identity. CREATE
outcomes: created/found/unknown/absent/failed. Ambiguous absence does not permit a
new buy: only definitive_rejection=True clears it. Promotion requires promoted
and all_checks_passed=True. Deletion requires deleted, absence_verified=True,
and dependent_resources_absent=True. Only exact recorded identities are affected.

The active logical service is never generically terminated. Generations are
tracked here separately from the 1:1 Laravel CloudInstance record. Root adapters
own bootstrap, atomic cutover, durable data/fencing and exact provider absence.
"""

from __future__ import annotations

import contextlib
import dataclasses
import datetime as dt
import fcntl
import hashlib
import json
import os
import re
from pathlib import Path
import sqlite3
import uuid
from typing import Any, Mapping


class RecoveryError(Exception):
    """A local invariant was violated; no external action is authorized."""


class Busy(RecoveryError):
    pass


class StaleIntent(RecoveryError):
    pass


def canonical(value: Any) -> str:
    try:
        result = json.dumps(value, sort_keys=True, separators=(',', ':'),
                            ensure_ascii=True, allow_nan=False)
    except (TypeError, ValueError) as exc:
        raise RecoveryError('Non-canonical JSON') from exc
    if len(result.encode()) > 131072:
        raise RecoveryError('Receipt or state too large')
    return result


def digest(value: Any) -> str:
    return hashlib.sha256(canonical(value).encode()).hexdigest()


def timestamp(value: Any) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        raise RecoveryError('Invalid timestamp')
    return value


def _sha256(value: Any) -> bool:
    return isinstance(value, str) and len(value) == 64 and all(c in '0123456789abcdef' for c in value)


def _local_id(value: Any) -> bool:
    return (isinstance(value, str) and 1 <= len(value) <= 96 and value.isascii()
            and value[0].isalnum() and all(c.isalnum() or c in '_.:-' for c in value))


def _workflow_id(value: Any) -> bool:
    try:
        return isinstance(value, str) and str(uuid.UUID(value)) == value
    except (ValueError, AttributeError):
        return False


def identity(value: Mapping[str, Any]) -> dict[str, Any]:
    out = {k: value.get(k) for k in ('provider', 'project_id', 'provider_id', 'generation')}
    if (not all(isinstance(out[k], str) and 0 < len(out[k]) <= 160
                for k in ('provider', 'project_id', 'provider_id'))
            or isinstance(out['generation'], bool)
            or not isinstance(out['generation'], int) or out['generation'] < 1):
        raise RecoveryError('Invalid resource identity')
    return out


def same_identity(left: Mapping[str, Any], right: Mapping[str, Any]) -> bool:
    try:
        return identity(left) == identity(right)
    except (RecoveryError, AttributeError):
        return False


def _exact_identity(value: Any, resource: Mapping[str, Any]) -> bool:
    return isinstance(value, dict) and canonical(value) == canonical(identity(resource))


@dataclasses.dataclass(frozen=True)
class Config:
    enabled: bool = False
    owner_id: str = ''
    service_id: str = ''
    project_id: str = ''
    budget_currency: str = 'EUR'
    agent_id: str = 'ir-agent'
    outage_seconds: int = 10800
    suspect_seconds: int = 600
    recover_seconds: int = 600
    candidate_seconds: int = 1200
    observe_seconds: int = 3600
    receipt_max_age: int = 90
    data_max_age: int = 300
    observation_max_gap: int = 120
    intent_ttl: int = 30
    candidate_deadline: int = 2700
    max_attempts_per_incident_24h: int = 5
    max_attempts_24h: int = 5
    max_promotions_24h: int = 2
    max_vms: int = 2
    max_monthly_eu_micro: int = 12_000_000
    max_monthly_us_micro: int = 25_000_000
    max_trial_24h_micro: int = 1_000_000
    max_trial_month_micro: int = 3_000_000
    max_clock_step: int = 86400
    critical_consumers: tuple[str, ...] = ('proxmox', 'vm109', 'vpn', 'dns', 'storage')
    required_preflight: tuple[str, ...] = ('owner_bound', 'independent_control',
        'restore_current', 'fencing_enforced', 'registry_complete', 'provider_access',
        'budget_authorized')

    def validate(self) -> None:
        if not isinstance(self.enabled, bool):
            raise RecoveryError('enabled must be boolean')
        if self.enabled and not all((self.owner_id, self.service_id, self.project_id)):
            raise RecoveryError('Explicit owner/service/project required')
        if self.budget_currency not in ('EUR', 'USD'):
            raise RecoveryError('Explicit supported budget currency required')
        for f in dataclasses.fields(self):
            v = getattr(self, f.name)
            if isinstance(v, int) and not isinstance(v, bool) and v <= 0:
                raise RecoveryError('Nonpositive policy limit: ' + f.name)
        if self.max_vms > 2 or self.max_attempts_per_incident_24h > 5 or self.max_attempts_24h > 5:
            raise RecoveryError('Hard concurrency/trial bound exceeded')
        if self.max_promotions_24h > 2:
            raise RecoveryError('Hard promotion bound exceeded')
        if not self.critical_consumers or len(set(self.critical_consumers)) != len(self.critical_consumers):
            raise RecoveryError('Explicit unique critical consumers required')


@dataclasses.dataclass(frozen=True)
class Intent:
    id: str
    kind: str
    incident_id: str | None
    generation: int
    expected_revision: int
    payload: dict[str, Any]
    created_at: int
    expires_at: int
    reason: str = ''
    retry_at: int | None = None

    def to_dict(self) -> dict[str, Any]:
        return dataclasses.asdict(self)


class Journal:
    """Local durable ledger. Single host, restrictive files, no network locks.

    flock plus BEGIN IMMEDIATE serializes intent claims across processes. Read
    snapshots can be stale; every mutation rechecks their revision under lock.
    """
    def __init__(self, path: str | os.PathLike[str]):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.lock_path = self.path.with_suffix(self.path.suffix + '.lock')
        with self.transaction() as db:
            db.executescript('''
                CREATE TABLE IF NOT EXISTS state (id INTEGER PRIMARY KEY CHECK(id=1), body TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS actions (
                  id TEXT PRIMARY KEY, kind TEXT NOT NULL, incident TEXT,
                  created_at INTEGER NOT NULL, body TEXT NOT NULL, receipt TEXT);
                CREATE TABLE IF NOT EXISTS attempts (
                  id TEXT PRIMARY KEY, incident TEXT NOT NULL, created_at INTEGER NOT NULL,
                  trial_micro INTEGER NOT NULL, country TEXT NOT NULL, location TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS promotions (id TEXT PRIMARY KEY, created_at INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS installation_trials (
                  id TEXT PRIMARY KEY, created_at INTEGER NOT NULL,
                  authorization TEXT NOT NULL, intent_id TEXT NOT NULL UNIQUE);
                CREATE TABLE IF NOT EXISTS restore_epochs (
                  workflow_id TEXT PRIMARY KEY, created_at INTEGER NOT NULL, receipt TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS restore_begins (
                  workflow_id TEXT PRIMARY KEY, created_at INTEGER NOT NULL, receipt TEXT NOT NULL);
            ''')
        os.chmod(self.path, 0o600)

    @contextlib.contextmanager
    def transaction(self):
        fd = os.open(self.lock_path, os.O_CREAT | os.O_RDWR, 0o600)
        db = None
        try:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError as exc:
                raise Busy('Recovery journal is busy') from exc
            db = sqlite3.connect(self.path, timeout=0, isolation_level=None)
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

    @staticmethod
    def _read(db: sqlite3.Connection) -> dict[str, Any]:
        row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
        if row is None:
            raise RecoveryError('Journal is not initialized')
        return json.loads(row['body'])

    @staticmethod
    def _write(db: sqlite3.Connection, state: dict[str, Any]) -> None:
        state['revision'] += 1
        db.execute('UPDATE state SET body=? WHERE id=1', (canonical(state),))

    def initialize(self, active: Mapping[str, Any], now: int) -> None:
        now = timestamp(now)
        resource = dict(identity(active), config_hash=active.get('config_hash'))
        if not isinstance(resource['config_hash'], str) or not resource['config_hash']:
            raise RecoveryError('Initial configuration hash required')
        with self.transaction() as db:
            row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
            if row is not None:
                if not same_identity(json.loads(row['body'])['active'], resource):
                    raise RecoveryError('Cannot replace initialized active identity')
                return
            state = dict(revision=0, phase='HEALTHY', active=resource,
                         candidate=None, retiring=None, incident_id=None,
                         pending_create=None, pending_action=None,
                         last_now=now, clock_unsafe=False, health={}, candidate_health={},
                         last_promotion_at=None, backup_checkpoint=None)
            db.execute('INSERT INTO state VALUES(1,?)', (canonical(state),))

    def snapshot(self) -> dict[str, Any]:
        db = sqlite3.connect(self.path)
        db.row_factory = sqlite3.Row
        try:
            return self._read(db)
        finally:
            db.close()


class Engine:
    EFFECTS = frozenset({'CREATE', 'RECONCILE_CREATE', 'PROMOTE',
                         'RECONCILE_PROMOTION', 'DELETE_CANDIDATE',
                         'RECONCILE_DELETE_CANDIDATE', 'RETIRE', 'RECONCILE_RETIRE'})

    def __init__(self, config: Config, journal: Journal):
        config.validate()
        self.config = config
        self.journal = journal
        with journal.transaction() as db:
            state = journal._read(db)
            currency = state.get('budget_currency')
            if currency is None:
                state['budget_currency'] = config.budget_currency
                journal._write(db, state)
            elif currency != config.budget_currency:
                raise RecoveryError('Cannot reinterpret retained ledger currency')

    def snapshot(self) -> dict[str, Any]:
        return self.journal.snapshot()

    def _fresh(self, receipt: Any, now: int, age: int | None = None) -> bool:
        return (isinstance(receipt, dict) and receipt.get('verified') is True
                and isinstance(receipt.get('received_at'), int)
                and not isinstance(receipt.get('received_at'), bool)
                and 0 <= now - receipt['received_at'] <= (age or self.config.receipt_max_age))

    def _scope(self, receipt: Any, state: Mapping[str, Any], resource: Mapping[str, Any],
               now: int, age: int | None = None) -> bool:
        return (self._fresh(receipt, now, age)
                and receipt.get('incident_id') == state['incident_id']
                and receipt.get('generation') == resource['generation']
                and receipt.get('config_hash') == resource['config_hash'])

    def _quality(self, receipt: Any, resource: Mapping[str, Any], now: int) -> str:
        if not (self._fresh(receipt, now)
                and self._restore_bound(receipt, resource)
                and receipt.get('agent_id') == self.config.agent_id
                and receipt.get('generation') == resource['generation']
                and receipt.get('config_hash') == resource['config_hash']
                and all(receipt.get(k) is True for k in
                        ('direct_control', 'host_healthy', 'internet_healthy'))):
            return 'unknown'
        if receipt.get('tunnel_ok') is True and receipt.get('probe_ok') is True:
            return 'ok'
        if receipt.get('tunnel_ok') is False or receipt.get('probe_ok') is False:
            return 'bad'
        return 'unknown'

    @staticmethod
    def _restore_bound(receipt: Mapping[str, Any], resource: Mapping[str, Any]) -> bool:
        if resource.get('restore_pending') is not None:
            return False
        if 'restore_epoch' not in resource:
            return True
        return (type(receipt.get('restore_epoch')) is int
                and receipt['restore_epoch'] == resource['restore_epoch']
                and receipt.get('restore_hash') == resource['restore_hash']
                and type(receipt.get('received_at')) is int
                and receipt['received_at'] >= resource['restore_recorded_at']
                and type(receipt.get('observed_at')) is int
                and resource['restore_recorded_at'] <= receipt['observed_at'] <= receipt['received_at'] + 5)

    def _advance_health(self, h: dict[str, Any], quality: str, now: int) -> dict[str, Any]:
        h = dict(h)
        dt = now - h.get('last_at', now)
        continuous = 0 <= dt <= self.config.observation_max_gap
        prev = h.get('quality')
        history = [list(x) for x in h.get('history', [])]
        if history and history[-1][0] == now:
            history[-1] = [now, quality]
        else:
            history.append([now, quality])
        cutoff = now - self.config.outage_seconds
        while len(history) > 1 and history[1][0] <= cutoff:
            history.pop(0)
        # Require actual recent observation coverage. Three hours of failures
        # last week must not authorize a buy after a ten-minute fresh failure.
        bad_seconds = 0
        for left, right in zip(history, history[1:]):
            width = right[0] - left[0]
            if left[1] == 'bad' and 0 < width <= self.config.observation_max_gap:
                bad_seconds += max(0, right[0] - max(left[0], cutoff))
        h['history'] = history
        if quality == 'bad':
            h.setdefault('failure_started_at', now)
            h['bad_seconds'] = bad_seconds
            h['bad_run_since'] = h.get('bad_run_since', now) if continuous and prev == 'bad' else now
            h.pop('recovery_since', None)
            h.pop('healthy_since', None)
        elif quality == 'ok':
            h['recovery_since'] = h.get('recovery_since', now) if continuous and prev == 'ok' else now
            h['healthy_since'] = h.get('healthy_since', now) if continuous and prev == 'ok' else now
            h.pop('bad_run_since', None)
            if now - h['recovery_since'] >= self.config.recover_seconds:
                h.pop('failure_started_at', None)
                h['bad_seconds'] = 0
        else:
            # Missing coverage never advances the outage or observation timers.
            for k in ('healthy_since', 'recovery_since', 'bad_run_since'):
                h.pop(k, None)
        h.update(last_at=now, quality=quality)
        return h

    def observe(self, evidence: Mapping[str, Any], now: int) -> dict[str, Any]:
        now = timestamp(now)
        canonical(dict(evidence))
        with self.journal.transaction() as db:
            s = self.journal._read(db)
            delta = now - s['last_now']
            if delta < 0 or delta > self.config.max_clock_step:
                s['clock_unsafe'] = True
            clock = evidence.get('clock')
            if (self._fresh(clock, now) and clock.get('local_clock_verified') is True
                    and now >= s['last_now']):
                s['clock_unsafe'] = False
            if s['clock_unsafe']:
                self.journal._write(db, s)
                return s
            s['last_now'] = now
            s['health'] = self._advance_health(s['health'], self._quality(evidence.get('health'), s['active'], now), now)
            h = s['health']
            if 'failure_started_at' in h and s['incident_id'] is None:
                s['incident_id'] = 'relay-' + digest([identity(s['active']), h['failure_started_at']])[:24]
            if (s['phase'] == 'HEALTHY' and s['candidate'] is None and s['retiring'] is None
                    and h.get('quality') == 'ok' and 'failure_started_at' not in h):
                s['incident_id'] = None
            if s['candidate'] is not None:
                s['candidate_health'] = self._advance_health(
                    s['candidate_health'], self._quality(evidence.get('candidate_health'), s['candidate'], now), now)
            self.journal._write(db, s)
            return s

    def _intent(self, s: Mapping[str, Any], kind: str, payload: dict[str, Any], now: int,
                reason: str = '', retry_at: int | None = None) -> Intent:
        gen = s['candidate']['generation'] if s.get('candidate') else s['active']['generation']
        body = dict(kind=kind, incident_id=s['incident_id'], generation=gen,
                    expected_revision=s['revision'], payload=payload)
        return Intent(digest(body)[:40], kind, s['incident_id'], gen, s['revision'],
                      payload, now, now + self.config.intent_ttl, reason, retry_at)

    def _hold(self, s: Mapping[str, Any], reason: str, now: int, delay: int = 30) -> Intent:
        return self._intent(s, 'HOLD', {}, now, reason, now + delay)

    def _stats(self, db: sqlite3.Connection, s: Mapping[str, Any], now: int) -> dict[str, Any]:
        current = dt.datetime.fromtimestamp(now, tz=dt.timezone.utc)
        month_start = int(current.replace(day=1, hour=0, minute=0, second=0, microsecond=0).timestamp())
        next_month = (current.replace(year=current.year + 1, month=1, day=1, hour=0, minute=0, second=0, microsecond=0)
                      if current.month == 12 else current.replace(month=current.month + 1, day=1, hour=0, minute=0, second=0, microsecond=0))
        rows = db.execute('SELECT * FROM attempts WHERE incident=? OR created_at>=?',
                          (s['incident_id'], min(month_start, now - 86400))).fetchall()
        return dict(incident=[dict(r) for r in rows if r['incident'] == s['incident_id']],
                    incident_24h=[dict(r) for r in rows if r['incident'] == s['incident_id'] and r['created_at'] > now - 86400],
                    rolling=[dict(r) for r in rows if r['created_at'] > now - 86400],
                    month=[dict(r) for r in rows if r['created_at'] >= month_start],
                    next_month=int(next_month.timestamp()),
                    promotion_times=[r[0] for r in db.execute('SELECT created_at FROM promotions WHERE created_at>?',
                                          (now - 86400,)).fetchall()])

    def _options(self, s: Mapping[str, Any], evidence: Mapping[str, Any], now: int,
                 stats: Mapping[str, Any]) -> list[dict[str, Any]]:
        out = []
        tried = {(r['country'], r['location']) for r in stats['incident_24h']}
        for o in evidence.get('options', []):
            if not isinstance(o, dict) or not self._fresh(o, now, self.config.data_max_age):
                continue
            cc = o.get('country')
            allowed = {'DE': {'fsn1', 'nbg1'}, 'FI': {'hel1'}, 'US': {'ash', 'hil'}}
            if cc not in allowed or o.get('location') not in allowed[cc] or (cc, o['location']) in tried:
                continue
            if (o.get('available') is not True or o.get('compatible') is not True
                    or o.get('provider') != 'hetzner' or o.get('project_id') != self.config.project_id
                    or o.get('owner_id') != self.config.owner_id or o.get('service_id') != self.config.service_id
                    or o.get('currency') != self.config.budget_currency
                    or o.get('architecture') != 'x86' or not o.get('config_hash')):
                continue
            if not all(isinstance(o.get(k), int) and not isinstance(o[k], bool) and o[k] > 0
                       for k in ('monthly_gross_micro', 'trial_max_gross_micro')):
                continue
            cap = self.config.max_monthly_us_micro if cc == 'US' else self.config.max_monthly_eu_micro
            spent = sum(r['trial_micro'] for r in stats['rolling'])
            month_spent = sum(r['trial_micro'] for r in stats['month'])
            if (o['monthly_gross_micro'] > cap
                    or spent + o['trial_max_gross_micro'] > self.config.max_trial_24h_micro
                    or month_spent + o['trial_max_gross_micro'] > self.config.max_trial_month_micro):
                continue
            if not all(isinstance(o.get(k), str) and o[k] for k in ('server_type', 'image')):
                continue
            out.append(dict(o))
        return sorted(out, key=lambda o: ({'DE': 0, 'FI': 1, 'US': 2}[o['country']],
                                         o['monthly_gross_micro'], o['location'], o['server_type']))

    def _all_critical(self, s: Mapping[str, Any], resource: Mapping[str, Any],
                      evidence: Mapping[str, Any], now: int) -> bool:
        rows = evidence.get('critical_receipts', [])
        if not isinstance(rows, list):
            return False
        for name in self.config.critical_consumers:
            matched = [r for r in rows if isinstance(r, dict) and r.get('consumer') == name]
            # A stale successful receipt must not mask a newer failure. The
            # adapter sends one current receipt per required consumer.
            if (len(matched) != 1 or matched[0].get('ok') is not True
                    or not self._scope(matched[0], s, resource, now)
                    or not self._restore_bound(matched[0], resource)):
                return False
        return True

    def _data_gate(self, s: Mapping[str, Any], resource: Mapping[str, Any],
                   evidence: Mapping[str, Any], now: int) -> bool:
        backup, fence = evidence.get('backup'), evidence.get('fencing')
        return (self._scope(backup, s, resource, now, self.config.data_max_age)
                and backup.get('durable') is True and backup.get('restore_verified') is True
                and isinstance(backup.get('checkpoint'), str) and bool(backup['checkpoint'])
                and self._scope(fence, s, resource, now)
                and fence.get('old_generation') == (s['retiring'] or s['active'])['generation']
                and fence.get('verified_fenced') is True)

    def _preflight_gate(self, evidence: Mapping[str, Any], now: int) -> bool:
        r = evidence.get('preflight')
        return (self._fresh(r, now, self.config.data_max_age)
                and r.get('ready') is True and r.get('owner_id') == self.config.owner_id
                and r.get('service_id') == self.config.service_id
                and r.get('project_id') == self.config.project_id
                and isinstance(r.get('checks'), dict)
                and all(r['checks'].get(k) is True for k in self.config.required_preflight))

    def _restore_ready_gate(self, s: Mapping[str, Any], resource: Mapping[str, Any],
                            evidence: Mapping[str, Any], now: int) -> bool:
        """Mandatory local workflow acceptance; network-only trials never promote.

        The collector supplies a fresh PRE_CUTOVER_READY receipt from the exact
        committed restore workflow. This is readiness to begin global cutover,
        not a claim that cutover already happened. Its twenty-minute observed
        acceptance window cannot be shortened by candidate_seconds/config.
        """
        r = evidence.get('restore_ready')
        epoch = resource.get('restore_epoch'); recorded = resource.get('restore_recorded_at')
        if (type(epoch) is not int or epoch < 1 or type(recorded) is not int
                or not _sha256(resource.get('restore_hash'))
                or not _workflow_id(resource.get('restore_workflow_id'))
                or not isinstance(resource.get('restore_snapshot_id'), str)
                or not re.fullmatch(r'[1-9][0-9]{0,19}', resource['restore_snapshot_id'])
                or not self._scope(r, s, resource, now) or not self._restore_bound(r, resource)):
            return False
        start, observed, received, duration = (r.get('acceptance_started_at'), r.get('observed_at'),
                                               r.get('received_at'), r.get('acceptance_seconds'))
        return (type(r.get('generation')) is int
                and r.get('phase') == 'PRE_CUTOVER_READY' and r.get('pre_cutover_ready') is True
                and r.get('global_cutover_complete') is False and r.get('migration_complete') is False
                and _exact_identity(r.get('identity'), resource)
                and _exact_identity(r.get('source_identity'), s['active'])
                and r.get('workflow_id') == resource['restore_workflow_id']
                and r.get('snapshot_id') == resource['restore_snapshot_id']
                and type(start) is int and type(observed) is int and type(received) is int and type(duration) is int
                and recorded <= start <= observed <= received + 5
                and 0 <= now - observed <= self.config.receipt_max_age
                and r.get('acceptance_last_observed_at') == observed
                and type(r.get('acceptance_last_observed_at')) is int
                and observed - start >= 1200 and duration == received - start and duration >= 1200)

    def _retire_gate(self, s: Mapping[str, Any], evidence: Mapping[str, Any], now: int) -> bool:
        active = s['active']
        h, ttl, refs = s['health'], evidence.get('ttl'), evidence.get('active_refs')
        return (h.get('quality') == 'ok' and 'healthy_since' in h
                and now - h['healthy_since'] >= self.config.observe_seconds
                and self._quality(evidence.get('health'), active, now) == 'ok'
                and self._scope(evidence.get('health'), s, active, now)
                and self._all_critical(s, active, evidence, now)
                and self._data_gate(s, active, evidence, now)
                and evidence['backup'].get('covers_promotion_checkpoint') == s['backup_checkpoint']
                and self._scope(ttl, s, active, now, self.config.data_max_age)
                and ttl.get('known') is True and isinstance(ttl.get('expires_at'), int)
                and not isinstance(ttl.get('expires_at'), bool) and 0 <= ttl['expires_at'] <= now
                and self._scope(refs, s, active, now)
                and refs.get('complete') is True and refs.get('count') == 0
                and isinstance(refs.get('count'), int) and not isinstance(refs.get('count'), bool))

    def _plan(self, s: Mapping[str, Any], evidence: Mapping[str, Any], now: int,
              db: sqlite3.Connection) -> Intent:
        if not self.config.enabled:
            return self._hold(s, 'DISABLED', now)
        if s['clock_unsafe'] or now < s['last_now'] or now - s['last_now'] > self.config.receipt_max_age:
            return self._hold(s, 'CLOCK_OR_OBSERVATION_UNSAFE', now)
        if s['active']['project_id'] != self.config.project_id or s['active']['provider'] != 'hetzner':
            return self._hold(s, 'ACTIVE_IDENTITY_NOT_ADOPTED', now)
        if s.get('candidate') is not None and s['candidate'].get('restore_pending') is not None:
            return self._hold(s, 'RESTORE_IN_PROGRESS', now)
        phase = s['phase']
        if phase == 'CREATE_UNKNOWN':
            return self._intent(s, 'RECONCILE_CREATE', {'create_intent_id': s['pending_create'], 'read_only': True}, now)
        if phase == 'CUTOVER':
            return self._intent(s, 'RECONCILE_PROMOTION', {'promotion_intent_id': s['pending_action'],
                                 'identity': identity(s['candidate']), 'read_only': True}, now)
        if phase in ('DELETE_CANDIDATE_PENDING', 'RETIRE_PENDING'):
            resource = s['candidate'] if phase == 'DELETE_CANDIDATE_PENDING' else s['retiring']
            return self._intent(s, 'RECONCILE_DELETE_CANDIDATE' if phase == 'DELETE_CANDIDATE_PENDING'
                                else 'RECONCILE_RETIRE', {'identity': identity(resource),
                                'delete_intent_id': s['pending_action'], 'read_only': True}, now)
        if s['retiring'] is not None:
            if self._retire_gate(s, evidence, now):
                gate = {k: evidence[k] for k in ('backup', 'fencing', 'ttl', 'active_refs', 'critical_receipts')}
                return self._intent(s, 'RETIRE', {'identity': identity(s['retiring']),
                    'active_identity': identity(s['active']), 'gate_hash': digest(gate)}, now)
            return self._hold(s, 'RETIRE_GATES_INCOMPLETE', now)
        if s['candidate'] is not None:
            c = s['candidate']
            rejected = evidence.get('candidate_rejected')
            expired = now - c['created_at'] >= self.config.candidate_deadline
            if ((self._scope(rejected, s, c, now) and rejected.get('rejected') is True) or expired):
                # A restored disk contains production state even before global
                # promotion. A cleanup assertion cannot make it a disposable
                # network trial again. Pending deletion reconciliation above
                # remains read only and is intentionally still available.
                if 'restore_epoch' in c:
                    return self._hold(s, 'CANDIDATE_DATA_UNSAFE', now)
                cleanup = evidence.get('cleanup')
                if (self._scope(cleanup, s, c, now) and same_identity(cleanup.get('identity', {}), c)
                        and cleanup.get('no_actual_writes') is True and cleanup.get('never_promoted') is True):
                    return self._intent(s, 'DELETE_CANDIDATE', {'identity': identity(c),
                                         'cleanup_hash': digest(cleanup)}, now)
                return self._hold(s, 'CANDIDATE_DATA_UNSAFE', now)
            h = s['candidate_health']
            if (h.get('quality') == 'ok' and 'healthy_since' in h
                    and now - h['healthy_since'] >= self.config.candidate_seconds
                    and self._quality(evidence.get('candidate_health'), c, now) == 'ok'
                    and self._scope(evidence.get('candidate_health'), s, c, now)
                    and self._preflight_gate(evidence, now)
                    and self._restore_ready_gate(s, c, evidence, now)
                    and self._all_critical(s, c, evidence, now) and self._data_gate(s, c, evidence, now)):
                promotion_times = self._stats(db, s, now)['promotion_times']
                if len(promotion_times) >= self.config.max_promotions_24h:
                    return self._hold(s, 'PROMOTION_BUDGET_BLOCKED', now,
                                      max(1, min(promotion_times) + 86400 - now))
                return self._intent(s, 'PROMOTE', {'identity': identity(c),
                    'old_identity': identity(s['active']), 'checkpoint': evidence['backup']['checkpoint'],
                    'gate_hash': digest({k: evidence[k] for k in ('candidate_health', 'critical_receipts', 'backup', 'fencing', 'restore_ready')})}, now)
            return self._hold(s, 'CANDIDATE_VALIDATION', now)
        h = s['health']
        current_quality = self._quality(evidence.get('health'), s['active'], now)
        if current_quality == 'unknown':
            return self._hold(s, 'IR_UNREACHABLE_OR_STALE', now)
        if current_quality == 'ok' or h.get('quality') == 'ok':
            return self._hold(s, 'HEALTHY' if s['incident_id'] is None else 'RECOVERING', now)
        elapsed = now - h.get('failure_started_at', now)
        last_bad = now - h.get('bad_run_since', now)
        confirmed = (elapsed >= self.config.outage_seconds and last_bad >= self.config.suspect_seconds
                     and h.get('bad_seconds', 0) >= self.config.outage_seconds * .9)
        if not confirmed:
            return self._hold(s, 'SUSPECT', now)
        if not s['incident_id']:
            return self._hold(s, 'MISSING_INCIDENT', now)
        if not self._preflight_gate(evidence, now):
            return self._hold(s, 'PREFLIGHT_INCOMPLETE', now)
        return self._create_choice(s, evidence, now, db)

    def _create_choice(self, s: Mapping[str, Any], evidence: Mapping[str, Any], now: int,
                       db: sqlite3.Connection) -> Intent:
        """Shared cost/concurrency/choice gate, with no outage/readiness bypass."""
        stats = self._stats(db, s, now)
        blocked_until = []
        if len(stats['incident_24h']) >= self.config.max_attempts_per_incident_24h:
            blocked_until.append(min(r['created_at'] for r in stats['incident_24h']) + 86400)
        if len(stats['rolling']) >= self.config.max_attempts_24h:
            blocked_until.append(min(r['created_at'] for r in stats['rolling']) + 86400)
        if len(stats['promotion_times']) >= self.config.max_promotions_24h:
            blocked_until.append(min(stats['promotion_times']) + 86400)
        if sum(r['trial_micro'] for r in stats['rolling']) >= self.config.max_trial_24h_micro:
            blocked_until.append(min(r['created_at'] for r in stats['rolling']) + 86400)
        if sum(r['trial_micro'] for r in stats['month']) >= self.config.max_trial_month_micro:
            blocked_until.append(stats['next_month'])
        if blocked_until:
            return self._hold(s, 'ATTEMPT_BUDGET_BLOCKED', now, max(1, max(blocked_until) - now))
        inventory = evidence.get('inventory')
        if not (self._fresh(inventory, now) and inventory.get('complete') is True
                and inventory.get('project_id') == self.config.project_id
                and isinstance(inventory.get('managed_vm_count'), int)
                and not isinstance(inventory.get('managed_vm_count'), bool)
                and 1 <= inventory['managed_vm_count'] < self.config.max_vms
                and inventory.get('active_identity') == identity(s['active'])):
            return self._hold(s, 'INVENTORY_UNKNOWN_OR_VM_LIMIT', now)
        options = self._options(s, evidence, now, stats)
        if not options:
            return self._hold(s, 'CAPACITY_OR_COST_BLOCKED', now, 1800)
        option = options[0]
        attempt = len(stats['incident']) + 1
        name = 'sn-relay-' + s['incident_id'].removeprefix('relay-')[:16] + '-' + str(attempt)
        return self._intent(s, 'CREATE', dict(option=option, name=name,
                           attempt=attempt, next_generation=s['active']['generation'] + 1), now)

    def plan(self, state: Mapping[str, Any], evidence: Mapping[str, Any], now: int) -> Intent:
        now = timestamp(now)
        canonical(dict(evidence))
        db = sqlite3.connect(self.journal.path)
        db.row_factory = sqlite3.Row
        try:
            current = self.journal._read(db)
            if canonical(dict(state)) != canonical(current):
                return self._hold(current, 'STALE_STATE', now)
            return self._plan(current, evidence, now, db)
        finally:
            db.close()

    def commit_intent(self, intent: Intent, evidence: Mapping[str, Any], now: int) -> None:
        now = timestamp(now)
        if intent.kind not in self.EFFECTS or not (intent.created_at <= now <= intent.expires_at):
            raise StaleIntent('Unclaimable intent')
        with self.journal.transaction() as db:
            s = self.journal._read(db)
            if s['revision'] != intent.expected_revision:
                raise StaleIntent('Snapshot revision changed')
            expected = self._plan(s, evidence, now, db)
            if expected.id != intent.id or expected.kind != intent.kind or expected.payload != intent.payload:
                raise StaleIntent('Intent gates changed')
            self._persist_intent(db, s, intent, now)

    def _persist_intent(self, db: sqlite3.Connection, s: dict, intent: Intent, now: int) -> None:
        """One durable reservation path shared by runtime and private trial."""
        db.execute('INSERT INTO actions(id,kind,incident,created_at,body) VALUES(?,?,?,?,?)',
                   (intent.id, intent.kind, intent.incident_id, now, canonical(intent.to_dict())))
        if intent.kind == 'CREATE':
            o = intent.payload['option']
            db.execute('INSERT INTO attempts VALUES(?,?,?,?,?,?)',
                (intent.id, intent.incident_id, now, o['trial_max_gross_micro'], o['country'], o['location']))
            s.update(phase='CREATE_UNKNOWN', pending_create=intent.id)
        elif intent.kind == 'PROMOTE':
            s.update(phase='CUTOVER', pending_action=intent.id)
        elif intent.kind == 'DELETE_CANDIDATE':
            s.update(phase='DELETE_CANDIDATE_PENDING', pending_action=intent.id)
        elif intent.kind == 'RETIRE':
            s.update(phase='RETIRE_PENDING', pending_action=intent.id)
        self.journal._write(db, s)

    def claim_installation_trial(self, authorization: Mapping[str, Any],
                                 evidence: Mapping[str, Any], now: int) -> Intent:
        """Atomically commit one local-owner installation CREATE, never promote.

        The caller MUST verify a private local owner authorization file and
        locally generated guard/workflow receipts before verified=True. Agent
        reports are not authorizations. Repeated claims fail; recover a crash
        through the already committed intent and ordinary read-only reconcile.
        Config.enabled may remain false throughout installation.

        authorization fields: verified/id/purpose/owner_id/service_id/project_id,
        active_identity (exact four-field identity), active_config_hash,
        issued_at/expires_at/max_one_candidate. Evidence has ordinary preflight,
        inventory/options plus safe_boot_guard={verified,received_at,
        active_identity,guard_hash,safe_boot_guard_ready:true,fail_closed:true}.
        The private maintenance driver needs an enabled operator policy for
        normal strict reconciliation/cleanup; the scheduler can remain disabled
        against the SAME journal and retained ledger.
        """
        now = timestamp(now)
        auth, evidence = dict(authorization), dict(evidence)
        raw = canonical(auth); canonical(evidence)
        with self.journal.transaction() as db:
            s = self.journal._read(db)
            if (not all((self.config.owner_id, self.config.service_id, self.config.project_id))
                    or auth.get('verified') is not True or not _local_id(auth.get('id'))
                    or auth.get('purpose') != 'installation_restore_drill'
                    or auth.get('max_one_candidate') is not True
                    or any(auth.get(k) != getattr(self.config, k) for k in ('owner_id', 'service_id', 'project_id'))
                    or not _exact_identity(auth.get('active_identity'), s['active'])
                    or auth.get('active_config_hash') != s['active']['config_hash']
                    or type(auth.get('issued_at')) is not int or type(auth.get('expires_at')) is not int
                    or not 0 <= auth['issued_at'] <= now < auth['expires_at']):
                raise RecoveryError('Invalid local installation authorization')
            if db.execute('SELECT 1 FROM installation_trials WHERE id=?', (auth['id'],)).fetchone():
                raise RecoveryError('Installation authorization already consumed')
            if (s['clock_unsafe'] or now < s['last_now'] or now - s['last_now'] > self.config.receipt_max_age
                    or s['active']['provider'] != 'hetzner' or s['active']['project_id'] != self.config.project_id
                    or s['phase'] != 'HEALTHY' or s['candidate'] is not None or s['retiring'] is not None
                    or s['incident_id'] is not None
                    or s['pending_create'] is not None or s['pending_action'] is not None
                    or db.execute('SELECT 1 FROM actions WHERE receipt IS NULL LIMIT 1').fetchone()):
                raise RecoveryError('Installation state is not safe')
            preflight, guard = evidence.get('preflight'), evidence.get('safe_boot_guard')
            required = ('owner_bound', 'independent_control', 'provider_access', 'budget_authorized',
                        'registry_complete', 'fencing_enforced', 'safe_boot_guard_ready')
            if (not self._fresh(preflight, now, self.config.data_max_age)
                    or any(preflight.get(k) != getattr(self.config, k) for k in ('owner_id', 'service_id', 'project_id'))
                    or not isinstance(preflight.get('checks'), dict)
                    or not all(preflight['checks'].get(k) is True for k in required)
                    or not self._fresh(guard, now)
                    or not _exact_identity(guard.get('active_identity'), s['active'])
                    or guard.get('safe_boot_guard_ready') is not True or guard.get('fail_closed') is not True
                    or not _sha256(guard.get('guard_hash'))):
                raise RecoveryError('Installation guard/preflight incomplete')
            if s['incident_id'] is None:
                s['incident_id'] = 'install-' + digest([auth['id'], identity(s['active'])])[:24]
            intent = self._create_choice(s, evidence, now, db)
            if intent.kind != 'CREATE':
                raise RecoveryError('Installation trial blocked: ' + intent.reason)
            intent = self._intent(s, 'CREATE', {**intent.payload,
                'installation_authorization_id': auth['id'], 'purpose': auth['purpose'],
                'safe_boot_guard_hash': guard['guard_hash']}, now)
            intent = dataclasses.replace(intent, expires_at=min(intent.expires_at, auth['expires_at'] - 1))
            self._persist_intent(db, s, intent, now)
            db.execute('INSERT INTO installation_trials VALUES(?,?,?,?)', (auth['id'], now, raw, intent.id))
            return intent

    def begin_restore(self, receipt: Mapping[str, Any], now: int) -> None:
        """Commit a private workflow HOLD BEFORE any source fence or VM rebuild.

        Receipt uses fresh candidate scope, exact identity/source_identity,
        canonical workflow_id UUID, workflow_committed:true (local workflow
        intent durably journaled), purpose:candidate_restore, expected_restore_epoch.
        An unresolved begin is never cancelled, unfenced or deleted by core.
        Only a matching completed restore receipt releases this hold.
        """
        now = timestamp(now)
        proof = dict(receipt); raw = canonical(proof)
        workflow = proof.get('workflow_id')
        if not _workflow_id(workflow):
            raise RecoveryError('Invalid local restore workflow')
        with self.journal.transaction() as db:
            prior = db.execute('SELECT receipt FROM restore_begins WHERE workflow_id=?', (workflow,)).fetchone()
            if prior:
                if prior['receipt'] == raw:
                    return
                raise RecoveryError('Immutable restore begin conflict')
            s = self.journal._read(db)
            candidate = s['candidate']
            if (s['clock_unsafe'] or now < s['last_now'] or now - s['last_now'] > self.config.receipt_max_age
                    or not all((self.config.owner_id, self.config.service_id, self.config.project_id))
                    or s['active']['provider'] != 'hetzner' or s['active']['project_id'] != self.config.project_id
                    or s['phase'] != 'VALIDATING' or candidate is None or s['retiring'] is not None
                    or s['pending_create'] is not None or s['pending_action'] is not None
                    or candidate.get('restore_pending') is not None
                    or db.execute('SELECT 1 FROM actions WHERE receipt IS NULL LIMIT 1').fetchone()):
                raise RecoveryError('Restore begin in unsafe state')
            epoch = proof.get('expected_restore_epoch')
            if (not self._scope(proof, s, candidate, now)
                    or not _exact_identity(proof.get('identity'), candidate)
                    or not _exact_identity(proof.get('source_identity'), s['active'])
                    or proof.get('purpose') != 'candidate_restore' or proof.get('workflow_committed') is not True
                    or type(epoch) is not int or not candidate.get('restore_epoch', 0) < epoch <= 2**53 - 1):
                raise RecoveryError('Unproven local restore begin')
            candidate['restore_pending'] = dict(workflow_id=workflow, expected_restore_epoch=epoch,
                                               started_at=now, begin_hash=digest(proof))
            s['candidate_health'] = {}
            s['last_now'] = now
            db.execute('INSERT INTO restore_begins VALUES(?,?,?)', (workflow, now, raw))
            self.journal._write(db, s)

    def record_restore_epoch(self, receipt: Mapping[str, Any], now: int) -> None:
        """Record a caller-verified committed local restore, invalidating health.

        This is not an agent API. No identities, budget rows or billable
        candidate.created_at/deadline are changed. Rebuild duration must fit the
        explicit policy budget; a restore never silently extends that lifetime.

        Receipt fields: verified/received_at/incident_id/generation/config_hash,
        exact identity/source_identity, workflow_id (canonical UUID),
        workflow_committed:true, snapshot_id (canonical positive decimal),
        restore_epoch (increasing positive wire-safe integer), restore_hash
        (SHA-256 of the final snapshot and bootstrap provenance),
        snapshot_available/source_fenced/boot_verified/quarantine_verified:true,
        machine_id_sha256/boot_id_sha256 from the authenticated new-boot proof.
        """
        now = timestamp(now)
        proof = dict(receipt); raw = canonical(proof)
        workflow = proof.get('workflow_id')
        if not _workflow_id(workflow):
            raise RecoveryError('Invalid local restore workflow')
        with self.journal.transaction() as db:
            prior = db.execute('SELECT receipt FROM restore_epochs WHERE workflow_id=?', (workflow,)).fetchone()
            if prior:
                if prior['receipt'] == raw:
                    return
                raise RecoveryError('Immutable restore receipt conflict')
            s = self.journal._read(db)
            candidate = s['candidate']
            if (s['clock_unsafe'] or now < s['last_now'] or now - s['last_now'] > self.config.receipt_max_age
                    or not all((self.config.owner_id, self.config.service_id, self.config.project_id))
                    or s['active']['provider'] != 'hetzner' or s['active']['project_id'] != self.config.project_id
                    or s['phase'] != 'VALIDATING' or candidate is None or s['retiring'] is not None
                    or s['pending_create'] is not None or s['pending_action'] is not None
                    or db.execute('SELECT 1 FROM actions WHERE receipt IS NULL LIMIT 1').fetchone()):
                raise RecoveryError('Restore epoch in unsafe state')
            epoch, snapshot = proof.get('restore_epoch'), proof.get('snapshot_id')
            pending = candidate.get('restore_pending')
            if (not self._scope(proof, s, candidate, now)
                    or not _exact_identity(proof.get('identity'), candidate)
                    or not _exact_identity(proof.get('source_identity'), s['active'])
                    or proof.get('workflow_committed') is not True
                    or not isinstance(pending, dict) or pending.get('workflow_id') != workflow
                    or pending.get('expected_restore_epoch') != epoch
                    or proof['received_at'] < pending['started_at']
                    or type(epoch) is not int or not candidate.get('restore_epoch', 0) < epoch <= 2**53 - 1
                    or not isinstance(snapshot, str) or not snapshot.isascii() or not snapshot.isdigit()
                    or not 1 <= len(snapshot) <= 20 or int(snapshot) < 1 or str(int(snapshot)) != snapshot
                    or not _sha256(proof.get('restore_hash'))
                    or not all(proof.get(k) is True for k in
                        ('snapshot_available', 'source_fenced', 'boot_verified', 'quarantine_verified'))
                    or not _sha256(proof.get('machine_id_sha256')) or not _sha256(proof.get('boot_id_sha256'))):
                raise RecoveryError('Unproven local restore epoch')
            candidate.update(restore_epoch=epoch, restore_hash=proof['restore_hash'],
                restore_recorded_at=now, restore_snapshot_id=snapshot, restore_workflow_id=workflow)
            candidate.pop('restore_pending')
            s['candidate_health'] = {}
            # Critical receipts are evaluated afresh, never retained in core.
            s['last_now'] = now
            db.execute('INSERT INTO restore_epochs VALUES(?,?,?)', (workflow, now, raw))
            self.journal._write(db, s)

    def record_effect(self, intent_id: str, receipt: Mapping[str, Any], now: int) -> None:
        now = timestamp(now)
        raw = canonical(dict(receipt))
        with self.journal.transaction() as db:
            row = db.execute('SELECT * FROM actions WHERE id=?', (intent_id,)).fetchone()
            if row is None:
                raise RecoveryError('Effect without durable intent')
            if row['receipt'] is not None:
                if row['receipt'] == raw:
                    return
                raise RecoveryError('Immutable receipt conflict')
            action = json.loads(row['body'])
            if (receipt.get('intent_id') != intent_id or receipt.get('kind') != row['kind']
                    or not self._fresh(dict(receipt), now)
                    or receipt['received_at'] < row['created_at']):
                raise RecoveryError('Unbound or stale effect receipt')
            s = self.journal._read(db)
            if now < s['last_now'] or s['clock_unsafe']:
                raise RecoveryError('Unsafe clock')
            kind, outcome = row['kind'], receipt.get('outcome')
            if kind in ('CREATE', 'RECONCILE_CREATE'):
                if s['phase'] != 'CREATE_UNKNOWN':
                    raise RecoveryError('Create result in wrong phase')
                create_row = db.execute('SELECT body FROM actions WHERE id=?', (s['pending_create'],)).fetchone()
                create = json.loads(create_row['body'])
                if kind == 'RECONCILE_CREATE' and action['payload']['create_intent_id'] != s['pending_create']:
                    raise RecoveryError('Reconciliation identity mismatch')
                if outcome in ('created', 'found'):
                    r = dict(receipt.get('identity') or {})
                    ident = identity(r)
                    if (ident['provider'] != 'hetzner' or ident['project_id'] != self.config.project_id
                            or ident['generation'] != create['payload']['next_generation']
                            or ident['provider_id'] == s['active']['provider_id']
                            or receipt.get('create_intent_id') != s['pending_create']):
                        raise RecoveryError('Candidate identity mismatch')
                    s['candidate'] = dict(ident, config_hash=create['payload']['option']['config_hash'],
                        created_at=create['created_at'])
                    s.update(phase='VALIDATING', pending_create=None, candidate_health={})
                elif outcome in ('absent', 'failed') and receipt.get('definitive_rejection') is True:
                    s.update(phase='HEALTHY', pending_create=None)
                elif outcome not in ('unknown', 'pending', 'absent', 'failed'):
                    raise RecoveryError('Unknown create outcome')
            elif kind in ('PROMOTE', 'RECONCILE_PROMOTION'):
                if s['phase'] != 'CUTOVER' or s['candidate'] is None:
                    raise RecoveryError('Promotion in wrong phase')
                if not same_identity(receipt.get('identity', {}), s['candidate']):
                    raise RecoveryError('Promotion identity mismatch')
                if outcome == 'promoted' and receipt.get('all_checks_passed') is True:
                    promoted_row = db.execute('SELECT body FROM actions WHERE id=?', (s['pending_action'],)).fetchone()
                    promoted = json.loads(promoted_row['body'])
                    if kind == 'RECONCILE_PROMOTION' and action['payload']['promotion_intent_id'] != s['pending_action']:
                        raise RecoveryError('Promotion reconciliation mismatch')
                    s.update(retiring=s['active'], active=s['candidate'], candidate=None,
                        candidate_health={}, health={}, phase='HEALTHY', last_promotion_at=now,
                        backup_checkpoint=promoted['payload']['checkpoint'], pending_action=None)
                    db.execute('INSERT INTO promotions VALUES(?,?)', (promoted['id'], now))
                elif outcome not in ('unknown', 'pending', 'failed'):
                    raise RecoveryError('Unproven promotion')
            elif kind in ('DELETE_CANDIDATE', 'RECONCILE_DELETE_CANDIDATE', 'RETIRE', 'RECONCILE_RETIRE'):
                candidate = kind in ('DELETE_CANDIDATE', 'RECONCILE_DELETE_CANDIDATE')
                expected_phase = 'DELETE_CANDIDATE_PENDING' if candidate else 'RETIRE_PENDING'
                resource = s['candidate'] if candidate else s['retiring']
                if s['phase'] != expected_phase or resource is None:
                    raise RecoveryError('Deletion in wrong phase')
                if (not same_identity(receipt.get('identity', {}), resource)
                        or same_identity(resource, s['active'])):
                    raise RecoveryError('Deletion identity mismatch')
                if outcome == 'deleted' and receipt.get('absence_verified') is True and receipt.get('dependent_resources_absent') is True:
                    if candidate:
                        s.update(candidate=None, candidate_health={})
                    else:
                        s['retiring'] = None
                    s.update(phase='HEALTHY', pending_action=None)
                elif (kind.startswith('RECONCILE_') and outcome == 'present'
                      and receipt.get('delete_not_pending') is True):
                    # This is only a GET result, not permission to resend DELETE.
                    # Returning to the regular phase forces the next mutation to
                    # earn a new permit from all current health/data/TTL gates.
                    s.update(phase='VALIDATING' if candidate else 'HEALTHY', pending_action=None)
                elif (kind.startswith('RECONCILE_') and outcome == 'cleanup_required'
                      and receipt.get('server_absence_verified') is True
                      and receipt.get('delete_not_pending') is True):
                    # Server GET is absent but a captured, owned IP may still
                    # bill. Read-only reconciliation cannot delete that IP.
                    # Re-enter the normal gates for a new cleanup permit.
                    s.update(phase='VALIDATING' if candidate else 'HEALTHY', pending_action=None)
                elif outcome not in ('unknown', 'pending', 'deleted', 'failed'):
                    raise RecoveryError('Unknown deletion outcome')
            db.execute('UPDATE actions SET receipt=? WHERE id=?', (raw, intent_id))
            s['last_now'] = now
            self.journal._write(db, s)
