"""Bounded retention of acknowledged recovery *history*, never cloud state.

Only the fixed gateway/collector history databases in the private controller
directory are writable. The core journal is read-only and its worker/flock is
held to prevent a new incident or effect racing pruning. Provider records,
core actions/attempts/budget ledgers, manifests on disk, agent jobs/outbox and
backup artifacts are never changed by this module.

Retention is at least seven days. All unconsumed inbox messages and their
receipt/manifest references survive; current on-disk manifests, each agent's
latest 32 revisions and the last 24 hours also survive. Collector history is
pruned only during a fresh, idle core state and after fresh agent reports show
less than a full six-receipt outbox batch. A full/unknown remote backlog is a
hold, not permission to discard unseen receipts. Remote agent databases need
their own independently verified retention protocol.
"""
from __future__ import annotations

import argparse
import contextlib
import fcntl
import hashlib
import json
import math
import os
from pathlib import Path
import re
import shutil
import sqlite3
import stat
import sys
import time


SCHEMA = 'servernet.relay.maintenance.v1'
DAY = 86400
MAX_JSON = 262144
MAX_DATABASE = 256 * 1024 * 1024
CORE_MAX_AGE = 3600
AGENT_MAX_AGE = 90
AGENT = re.compile(r'\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,95}\Z')
HEX64 = re.compile(r'\A[a-f0-9]{64}\Z')
TABLES = {
    'main': {
        'latest': ('agent_id', 'received_at', 'body'),
        'inbox': ('agent_id', 'report_hash', 'received_at', 'body_sha256', 'body', 'consumed_at'),
    },
    'evidence': {
        'sequence': ('agent', 'revision'),
        'wire_generation': ('agent', 'generation', 'config_hash'),
        'manifests': ('agent', 'revision', 'issued_at', 'body'),
        'receipts': ('hash', 'received_at', 'body'),
        'ingested_reports': ('agent', 'report_hash', 'received_at', 'body_sha256', 'status'),
    },
}


class MaintenanceError(Exception):
    """A fixed diagnostic code, with no source data or secret in the message."""


def _json(raw: str | bytes) -> dict:
    if not isinstance(raw, (str, bytes)) or len(raw.encode() if isinstance(raw, str) else raw) > MAX_JSON:
        raise MaintenanceError('HISTORY_JSON_UNSAFE')
    try:
        value = json.loads(raw)
    except (ValueError, RecursionError):
        raise MaintenanceError('HISTORY_JSON_UNSAFE') from None
    if not isinstance(value, dict):
        raise MaintenanceError('HISTORY_JSON_UNSAFE')
    return value


def _json_file(path: Path) -> dict:
    if path.stat().st_size > MAX_JSON:
        raise MaintenanceError('HISTORY_JSON_UNSAFE')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as source:
        return _json(source.read(MAX_JSON+1))


def _private(path: Path, *, directory=False, allow_test_paths=False) -> Path:
    if not path.is_absolute() or '..' in path.parts:
        raise MaintenanceError('PRIVATE_PATH_REQUIRED')
    uid = os.geteuid()
    for index, item in enumerate((path, *path.parents)):
        info = item.lstat()
        wanted_dir = directory or index > 0
        if item.is_symlink() or (not stat.S_ISDIR(info.st_mode) if wanted_dir else not stat.S_ISREG(info.st_mode)):
            raise MaintenanceError('PRIVATE_PATH_REQUIRED')
        if info.st_uid not in ({uid} if index == 0 else {0, uid}):
            raise MaintenanceError('PRIVATE_PATH_OWNER')
        forbidden = 0o077 if index == 0 else 0o022
        if info.st_mode & forbidden:
            if not (index > 0 and allow_test_paths and item == Path('/tmp')
                    and info.st_mode & stat.S_ISVTX):
                raise MaintenanceError('PRIVATE_PATH_MODE')
        if index == 0 and not directory and info.st_size > MAX_DATABASE:
            raise MaintenanceError('DATABASE_TOO_LARGE')
    return path


@contextlib.contextmanager
def _locks(root: Path):
    descriptors = []
    try:
        for name in ('worker.lock', 'controller.sqlite3.lock'):
            fd = os.open(root/name, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
            descriptors.append(fd)
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
                raise MaintenanceError('PRIVATE_LOCK_REQUIRED')
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise MaintenanceError('WORKER_OR_JOURNAL_BUSY') from None
        yield
    finally:
        for fd in reversed(descriptors):
            os.close(fd)


def _idle_core(path: Path, now: int) -> dict:
    db = sqlite3.connect(path.as_uri()+'?mode=ro', uri=True, timeout=0.1)
    try:
        row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
        if row is None:
            raise MaintenanceError('CORE_NOT_INITIALIZED')
        state = _json(row[0])
    finally:
        db.close()
    if (state.get('phase') != 'HEALTHY' or state.get('clock_unsafe') is not False
            or type(state.get('last_now')) is not int
            or not 0 <= now-state['last_now'] <= CORE_MAX_AGE
            or any(key not in state or state[key] is not None for key in
                   ('incident_id', 'pending_create', 'pending_action', 'candidate', 'retiring'))):
        raise MaintenanceError('CORE_NOT_IDLE_OR_CLOCK_UNSAFE')
    return state


def _schema(db: sqlite3.Connection, schema: str) -> None:
    expected = TABLES[schema]
    rows = db.execute(f"SELECT name,type FROM {schema}.sqlite_master WHERE name NOT LIKE 'sqlite_%'").fetchall()
    if {r[0] for r in rows if r[1] == 'table'} != set(expected) or any(r[1] in ('trigger', 'view') for r in rows):
        raise MaintenanceError('HISTORY_SCHEMA_UNSAFE')
    for table, columns in expected.items():
        actual = tuple(r[1] for r in db.execute(f'PRAGMA {schema}.table_info({table})'))
        if actual != columns or db.execute(f'PRAGMA {schema}.foreign_key_list({table})').fetchone():
            raise MaintenanceError('HISTORY_SCHEMA_UNSAFE')
    if db.execute(f'PRAGMA {schema}.journal_mode').fetchone()[0].lower() == 'wal':
        # Atomic multi-file pruning is only used with the existing rollback
        # journal setup. Never rewrite another process's journal-mode policy.
        raise MaintenanceError('HISTORY_WAL_MODE_NOT_SUPPORTED')


def _scope(receipt: dict, default_agent: str | None = None) -> tuple[str, int]:
    agent = receipt.get('agent_id', default_agent)
    revision = receipt.get('revision')
    if not isinstance(agent, str) or not AGENT.fullmatch(agent) or type(revision) is not int or revision < 1:
        raise MaintenanceError('RECEIPT_REFERENCE_UNSAFE')
    return agent, revision


def _canonical_hash(value: dict) -> str:
    raw = json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False, allow_nan=False).encode()
    return hashlib.sha256(raw).hexdigest()


def _protected(db: sqlite3.Connection, root: Path, config: dict, now: int, guard,
               *, allow_test_paths=False) -> tuple[set, set, set]:
    manifests, receipts, reports = set(), set(), set()
    agents = config.get('agents')
    if not isinstance(agents, dict) or not agents:
        raise MaintenanceError('AGENT_DRAIN_NOT_PROVEN')
    for agent, binding in agents.items():
        guard()
        if not isinstance(agent, str) or not AGENT.fullmatch(agent) or not isinstance(binding, dict):
            raise MaintenanceError('AGENT_BINDING_UNSAFE')
        if binding.get('enabled') is not True:
            continue
        row = db.execute('SELECT received_at,body FROM latest WHERE agent_id=?', (agent,)).fetchone()
        if row is None:
            raise MaintenanceError('AGENT_DRAIN_NOT_PROVEN')
        env = _json(row[1]); report = env.get('report', {})
        if (type(row[0]) is not int or not 0 <= now-row[0] <= AGENT_MAX_AGE
                or env.get('agent_id') != agent or env.get('received_at') != row[0]
                or not isinstance(report, dict) or report.get('agent_id') != agent
                or type(report.get('observed_at')) is not int
                or not 0 <= now-report['observed_at'] <= AGENT_MAX_AGE
                or not isinstance(report.get('jobs'), list) or len(report['jobs']) >= 6):
            raise MaintenanceError('AGENT_DRAIN_NOT_PROVEN')
        for receipt in report['jobs']:
            if not isinstance(receipt, dict): raise MaintenanceError('RECEIPT_REFERENCE_UNSAFE')
            manifests.add(_scope(receipt, agent)); receipts.add(_canonical_hash(receipt))
    for path in root.glob('manifest-*.json'):
        guard()
        _private(path, allow_test_paths=allow_test_paths)
        current = _json_file(path)
        agent, rev = _scope(current)
        if path.name != f'manifest-{agent}.json':
            raise MaintenanceError('CURRENT_MANIFEST_UNSAFE')
        manifests.add((agent, rev))
    for (agent,) in db.execute('SELECT DISTINCT agent FROM evidence.manifests'):
        guard()
        # Count independently of earlier references: an old current manifest
        # must not consume one of the last-32 preservation slots.
        for (rev,) in db.execute('SELECT revision FROM evidence.manifests WHERE agent=? ORDER BY revision DESC LIMIT 32', (agent,)):
            manifests.add((agent, rev))
    for agent, report_hash, raw in db.execute('SELECT agent_id,report_hash,body FROM inbox WHERE consumed_at IS NULL'):
        guard()
        env = _json(raw); report = env.get('report')
        if not isinstance(report, dict) or not isinstance(report.get('jobs'), list):
            raise MaintenanceError('UNCONSUMED_REFERENCE_UNSAFE')
        reports.add((agent, report_hash))
        for receipt in report['jobs']:
            if not isinstance(receipt, dict): raise MaintenanceError('UNCONSUMED_REFERENCE_UNSAFE')
            manifests.add(_scope(receipt, agent)); receipts.add(_canonical_hash(receipt))
    return manifests, receipts, reports


def prune_readonly_history(config: dict, now: int | None = None, *, allow_test_paths=False,
                           monotonic=time.monotonic, disk_usage=shutil.disk_usage) -> dict:
    """Prune only old acknowledged history; return safe counters/blocker codes.

    No directory or database is inferred from a caller-supplied table/path.
    ``state_dir`` must be the existing, private controller directory. Locks and
    SQLite's short busy timeout make concurrent operation a bounded hold.
    """
    now = int(time.time()) if now is None else now
    result = {'schema': SCHEMA, 'at': now, 'ok': False, 'status': 'held',
              'deleted': {'inbox': 0, 'receipts': 0, 'manifests': 0, 'ingested_reports': 0},
              'vacuum': {}, 'untouched': ['core', 'provider', 'agent_jobs', 'backup_artifacts']}
    db = None
    try:
        if type(now) is not int or now < 0: raise MaintenanceError('CLOCK_UNSAFE')
        settings = config.get('maintenance', {})
        if not isinstance(settings, dict): raise MaintenanceError('MAINTENANCE_POLICY_UNSAFE')
        days = settings.get('history_retention_days', 7)
        batch = settings.get('history_batch_rows', 10000)
        seconds = settings.get('history_max_seconds', 3)
        reserve = settings.get('vacuum_min_free_bytes', 512*1024*1024)
        if (type(days) is not int or not 7 <= days <= 3650
                or type(batch) is not int or not 1 <= batch <= 10000
                or type(seconds) is not int or not 1 <= seconds <= 10
                or type(reserve) is not int or reserve < 128*1024*1024):
            raise MaintenanceError('MAINTENANCE_POLICY_UNSAFE')
        started = monotonic(); deadline = started+seconds
        def guard():
            elapsed = monotonic()
            if not math.isfinite(elapsed) or elapsed < started or elapsed >= deadline:
                raise MaintenanceError('MAINTENANCE_DEADLINE')
        root = _private(Path(config['state_dir']), directory=True, allow_test_paths=allow_test_paths)
        paths = {name: _private(root/name, allow_test_paths=allow_test_paths) for name in
                 ('controller.sqlite3', 'agent-reports.sqlite3', 'controller-evidence.sqlite3')}
        with _locks(root):
            _idle_core(paths['controller.sqlite3'], now)
            for path in paths.values():
                for suffix in ('-journal', '-wal', '-shm'):
                    extra = Path(str(path)+suffix)
                    if extra.exists() or extra.is_symlink():
                        _private(extra, allow_test_paths=allow_test_paths)
            db = sqlite3.connect(paths['agent-reports.sqlite3'].as_uri()+'?mode=rw', uri=True,
                                 timeout=0.1, isolation_level=None)
            db.execute('PRAGMA busy_timeout=100'); db.execute('PRAGMA synchronous=FULL')
            db.execute('PRAGMA temp_store=MEMORY')
            db.execute('ATTACH DATABASE ? AS evidence', (str(paths['controller-evidence.sqlite3']),))
            db.set_progress_handler(lambda: int(monotonic() >= deadline), 10000)
            _schema(db, 'main'); _schema(db, 'evidence')
            db.execute('BEGIN IMMEDIATE')
            guard()
            keep_manifests, keep_receipts, keep_reports = _protected(db, root, config, now, guard,
                                                                   allow_test_paths=allow_test_paths)
            db.execute('CREATE TEMP TABLE keep_manifest(agent TEXT, revision INTEGER, PRIMARY KEY(agent,revision))')
            db.execute('CREATE TEMP TABLE keep_receipt(hash TEXT PRIMARY KEY)')
            db.execute('CREATE TEMP TABLE keep_report(agent TEXT, hash TEXT, PRIMARY KEY(agent,hash))')
            db.executemany('INSERT OR IGNORE INTO keep_manifest VALUES(?,?)', sorted(keep_manifests))
            db.executemany('INSERT OR IGNORE INTO keep_receipt VALUES(?)', [(v,) for v in keep_receipts])
            db.executemany('INSERT OR IGNORE INTO keep_report VALUES(?,?)', sorted(keep_reports))
            cutoff = now-days*DAY
            counts = {}
            counts['inbox'] = db.execute('DELETE FROM inbox WHERE rowid IN '
                '(SELECT rowid FROM inbox WHERE consumed_at IS NOT NULL AND consumed_at<? AND received_at<? '
                'ORDER BY consumed_at,rowid LIMIT ?)', (cutoff, cutoff, batch)).rowcount
            counts['receipts'] = db.execute('DELETE FROM evidence.receipts WHERE rowid IN '
                '(SELECT rowid FROM evidence.receipts r WHERE received_at<? '
                'AND NOT EXISTS(SELECT 1 FROM keep_receipt k WHERE k.hash=r.hash) ORDER BY received_at,rowid LIMIT ?)',
                (cutoff, batch)).rowcount
            # Every retained receipt still keeps the manifest needed to verify it.
            for (raw,) in db.execute('SELECT body FROM evidence.receipts'):
                guard()
                row = _json(raw); child = row.get('receipt')
                if not isinstance(child, dict): raise MaintenanceError('RECEIPT_REFERENCE_UNSAFE')
                ref = _scope(child, row.get('agent_id'))
                db.execute('INSERT OR IGNORE INTO keep_manifest VALUES(?,?)', ref)
            # A remote agent may still be reconciling a started mutation that
            # has not produced an outbox receipt at all. WHM cannot prove its
            # fate from latest heartbeat/core idle state; retain every
            # mutation-bearing (or historically unrecognized) manifest.
            for agent, revision, raw in db.execute('SELECT agent,revision,body FROM evidence.manifests WHERE issued_at<?', (cutoff,)):
                guard()
                jobs = _json(raw).get('jobs')
                if (not isinstance(jobs, list) or any(not isinstance(job, dict)
                        or job.get('op') != 'probe' for job in jobs)):
                    db.execute('INSERT OR IGNORE INTO keep_manifest VALUES(?,?)', (agent, revision))
            counts['manifests'] = db.execute('DELETE FROM evidence.manifests WHERE rowid IN '
                '(SELECT rowid FROM evidence.manifests m WHERE issued_at<? AND issued_at<? '
                'AND NOT EXISTS(SELECT 1 FROM keep_manifest k WHERE k.agent=m.agent AND k.revision=m.revision) '
                'ORDER BY issued_at,rowid LIMIT ?)', (cutoff, now-DAY, batch)).rowcount
            counts['ingested_reports'] = db.execute('DELETE FROM evidence.ingested_reports WHERE rowid IN '
                '(SELECT rowid FROM evidence.ingested_reports r WHERE received_at<? '
                'AND NOT EXISTS(SELECT 1 FROM keep_report k WHERE k.agent=r.agent AND k.hash=r.report_hash) '
                'ORDER BY received_at,rowid LIMIT ?)', (cutoff, batch)).rowcount
            guard(); db.commit()
            result.update(ok=True, status='pruned', deleted=counts)
            for schema, name in (('main', 'agent-reports.sqlite3'), ('evidence', 'controller-evidence.sqlite3')):
                try:
                    guard()
                    pages = db.execute(f'PRAGMA {schema}.page_count').fetchone()[0]
                    free = db.execute(f'PRAGMA {schema}.freelist_count').fetchone()[0]
                    if free < 16 or free*4 < pages:
                        result['vacuum'][name] = 'not_needed'; continue
                    required = paths[name].stat().st_size*2+reserve
                    if disk_usage(root).free < required:
                        result['vacuum'][name] = 'space_reserved'; continue
                    # No SQL transaction is active. SQLite's own bounded lock
                    # acquisition excludes concurrent gateway readers/writers.
                    db.execute(f'VACUUM {schema}')
                    result['vacuum'][name] = 'completed'
                except (MaintenanceError, sqlite3.Error, OSError):
                    result['vacuum'][name] = 'busy_or_deadline'
    except MaintenanceError as error:
        result['reason'] = str(error)
    except (OSError, sqlite3.Error, ValueError, TypeError, KeyError):
        result['reason'] = 'PRIVATE_HISTORY_UNAVAILABLE'
    finally:
        if db is not None:
            if db.in_transaction:
                db.set_progress_handler(None, 0)
                db.rollback()
            db.close()
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', type=Path, required=True)
    args = parser.parse_args(); os.umask(0o077)
    try:
        path = _private(args.config)
        config = _json_file(path)
        result = prune_readonly_history(config)
    except (MaintenanceError, OSError):
        result = {'schema': SCHEMA, 'ok': False, 'status': 'held', 'reason': 'PRIVATE_CONFIG_UNAVAILABLE'}
    print(json.dumps(result, separators=(',', ':'), allow_nan=False))
    return 0 if result['ok'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
