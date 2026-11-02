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
from pathlib import Path
import sqlite3
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
            if len(matched) != 1 or matched[0].get('ok') is not True or not self._scope(matched[0], s, resource, now):
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
                    and self._all_critical(s, c, evidence, now) and self._data_gate(s, c, evidence, now)):
                promotion_times = self._stats(db, s, now)['promotion_times']
                if len(promotion_times) >= self.config.max_promotions_24h:
                    return self._hold(s, 'PROMOTION_BUDGET_BLOCKED', now,
                                      max(1, min(promotion_times) + 86400 - now))
                return self._intent(s, 'PROMOTE', {'identity': identity(c),
                    'old_identity': identity(s['active']), 'checkpoint': evidence['backup']['checkpoint'],
                    'gate_hash': digest({k: evidence[k] for k in ('candidate_health', 'critical_receipts', 'backup', 'fencing')})}, now)
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

