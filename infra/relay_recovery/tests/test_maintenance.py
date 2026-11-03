"""History retention tests using private disposable SQLite only; no network."""
import copy
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch

INFRA = Path(__file__).resolve().parents[2]
if str(INFRA) not in sys.path:
    sys.path.insert(0, str(INFRA))
from relay_recovery import maintenance as m


class MaintenanceTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name); self.root.chmod(0o700)
        self.now = 1_800_000_000
        self.old = self.now-8*m.DAY
        self.config = {'state_dir': str(self.root), 'agents': {'ir-agent': {'enabled': True}}}
        self.core = {'phase': 'HEALTHY', 'clock_unsafe': False, 'last_now': self.now,
                     'incident_id': None, 'pending_create': None, 'pending_action': None,
                     'candidate': None, 'retiring': None, 'active': {'provider_id': '40'}}
        with self.db('controller.sqlite3') as db:
            db.executescript('CREATE TABLE state(id INTEGER PRIMARY KEY,body TEXT);'
                             'CREATE TABLE actions(id TEXT PRIMARY KEY,body TEXT);'
                             'CREATE TABLE attempts(id TEXT PRIMARY KEY,cost INTEGER);')
            db.execute('INSERT INTO state VALUES(1,?)', (json.dumps(self.core),))
            db.execute("INSERT INTO actions VALUES('pending-ledger-history','immutable-budget-data')")
            db.execute("INSERT INTO attempts VALUES('old-attempt',170000)")
        with self.db('agent-reports.sqlite3') as db:
            db.executescript('''CREATE TABLE latest(agent_id TEXT PRIMARY KEY,received_at INTEGER,body TEXT);
                CREATE TABLE inbox(agent_id TEXT NOT NULL,report_hash TEXT NOT NULL,received_at INTEGER NOT NULL,
                    body_sha256 TEXT NOT NULL,body TEXT NOT NULL,consumed_at INTEGER,PRIMARY KEY(agent_id,report_hash));''')
        with self.db('controller-evidence.sqlite3') as db:
            db.executescript('''CREATE TABLE sequence(agent TEXT PRIMARY KEY,revision INTEGER NOT NULL);
                CREATE TABLE wire_generation(agent TEXT PRIMARY KEY,generation INTEGER NOT NULL,config_hash TEXT NOT NULL);
                CREATE TABLE manifests(agent TEXT NOT NULL,revision INTEGER NOT NULL,issued_at INTEGER NOT NULL,body TEXT NOT NULL,PRIMARY KEY(agent,revision));
                CREATE TABLE receipts(hash TEXT PRIMARY KEY,received_at INTEGER NOT NULL,body TEXT NOT NULL);
                CREATE TABLE ingested_reports(agent TEXT NOT NULL,report_hash TEXT NOT NULL,received_at INTEGER NOT NULL,body_sha256 TEXT NOT NULL,status TEXT NOT NULL,PRIMARY KEY(agent,report_hash));''')
            db.execute("INSERT INTO sequence VALUES('ir-agent',40)")
            db.execute("INSERT INTO wire_generation VALUES('ir-agent',1,?)", ('a'*64,))
            for revision in range(1, 41):
                db.execute('INSERT INTO manifests VALUES(?,?,?,?)',
                           ('ir-agent', revision, self.old+revision,
                            json.dumps({'agent_id': 'ir-agent', 'revision': revision, 'jobs': []})))
        self.current = self.root/'manifest-ir-agent.json'
        self.current.write_text(json.dumps({'agent_id': 'ir-agent', 'revision': 1})); self.current.chmod(0o600)
        self.latest([])
        self.receipt('drop', 5, self.old)
        self.receipt('recent', 4, self.now)
        protected = {'job_id': 'old-queued', 'revision': 3, 'observed_at': self.old}
        protected_hash = m._canonical_hash(protected)
        self.receipt(protected_hash, 3, self.old, receipt=protected)
        self.inbox('c'*64, {'agent_id': 'ir-agent', 'jobs': []}, self.old, self.old)
        self.inbox('d'*64, {'agent_id': 'ir-agent', 'jobs': [protected]}, self.old, None)
        self.inbox('e'*64, {'agent_id': 'ir-agent', 'jobs': []}, self.now-1, self.now)
        with self.db('controller-evidence.sqlite3') as db:
            for digest in ('c'*64, 'd'*64):
                db.execute('INSERT INTO ingested_reports VALUES(?,?,?,?,?)',
                           ('ir-agent', digest, self.old, 'f'*64, 'accepted'))
        for name in ('provider-effects.sqlite3', 'agent.sqlite3'):
            path = self.root/name; path.write_bytes(b'do-not-touch-other-ledgers'); path.chmod(0o600)
        self.backup = self.root/'snapshot-kept'; self.backup.mkdir(mode=0o700)
        (self.backup/'snapshot.tar.gz').write_bytes(b'do-not-delete-artifacts')
        self.core_hash = self.hash(self.root/'controller.sqlite3')

    @staticmethod
    def hash(path):
        return hashlib.sha256(path.read_bytes()).hexdigest()

    def db(self, name):
        # sqlite connection context commits/rolls back; addCleanup also closes it.
        path = self.root/name
        fd = os.open(path, os.O_CREAT | os.O_RDWR, 0o600); os.close(fd)
        db = sqlite3.connect(path); self.addCleanup(db.close)
        return db

    def latest(self, jobs, age=0):
        env = {'agent_id': 'ir-agent', 'received_at': self.now-age,
               'report': {'agent_id': 'ir-agent', 'observed_at': self.now-age, 'jobs': jobs}}
        with self.db('agent-reports.sqlite3') as db:
            db.execute('INSERT OR REPLACE INTO latest VALUES(?,?,?)', ('ir-agent', self.now-age, json.dumps(env)))

    def receipt(self, digest, revision, received, receipt=None):
        child = receipt or {'revision': revision, 'job_id': digest, 'observed_at': received}
        body = {'agent_id': 'ir-agent', 'receipt': child}
        with self.db('controller-evidence.sqlite3') as db:
            db.execute('INSERT INTO receipts VALUES(?,?,?)', (digest, received, json.dumps(body)))

    def inbox(self, digest, report, received, consumed):
        env = {'agent_id': 'ir-agent', 'received_at': received, 'report': report}
        with self.db('agent-reports.sqlite3') as db:
            db.execute('INSERT INTO inbox VALUES(?,?,?,?,?,?)',
                       ('ir-agent', digest, received, 'b'*64, json.dumps(env), consumed))

    def run_maintenance(self, config=None, **kwargs):
        return m.prune_readonly_history(config or self.config, self.now,
                                       allow_test_paths=True, **kwargs)

    def preserved_scope(self):
        self.assertEqual(self.core_hash, self.hash(self.root/'controller.sqlite3'))
        self.assertEqual(b'do-not-touch-other-ledgers', (self.root/'provider-effects.sqlite3').read_bytes())
        self.assertEqual(b'do-not-touch-other-ledgers', (self.root/'agent.sqlite3').read_bytes())
        self.assertEqual(b'do-not-delete-artifacts', (self.backup/'snapshot.tar.gz').read_bytes())
        with self.db('controller-evidence.sqlite3') as db:
            self.assertEqual([('ir-agent', 40)], db.execute('SELECT * FROM sequence').fetchall())
            self.assertEqual([('ir-agent', 1, 'a'*64)], db.execute('SELECT * FROM wire_generation').fetchall())

    def test_old_consumed_history_pruned_and_all_references_retained_without_network(self):
        with patch('socket.socket', side_effect=AssertionError('network forbidden')):
            result = self.run_maintenance()
        self.assertTrue(result['ok'], result)
        self.assertEqual({'inbox': 1, 'receipts': 1, 'manifests': 5, 'ingested_reports': 1}, result['deleted'])
        with self.db('agent-reports.sqlite3') as db:
            self.assertEqual([('d'*64, None), ('e'*64, self.now)],
                             db.execute('SELECT report_hash,consumed_at FROM inbox ORDER BY report_hash').fetchall())
        with self.db('controller-evidence.sqlite3') as db:
            revisions = {row[0] for row in db.execute('SELECT revision FROM manifests')}
            self.assertEqual({1, 3, 4, *range(9, 41)}, revisions)
            self.assertIsNotNone(db.execute('SELECT 1 FROM ingested_reports WHERE report_hash=?', ('d'*64,)).fetchone())
        self.preserved_scope()
        self.assertTrue(self.current.exists())

    def test_pending_incident_effect_candidate_or_retirement_holds_every_prune(self):
        for patch_state in ({'incident_id': 'current'}, {'pending_create': 'pending'}, {'pending_action': 'pending'},
                            {'candidate': {'provider_id': 'new'}}, {'retiring': {'provider_id': 'old'}},
                            {'phase': 'CREATE_UNKNOWN'}, {'clock_unsafe': True}):
            state = {**self.core, **patch_state}
            with self.db('controller.sqlite3') as db:
                db.execute('UPDATE state SET body=?', (json.dumps(state),))
            result = self.run_maintenance()
            self.assertFalse(result['ok'])
            self.assertEqual('CORE_NOT_IDLE_OR_CLOCK_UNSAFE', result['reason'])
            self.assertTrue(all(v == 0 for v in result['deleted'].values()))

    def test_full_unknown_or_stale_agent_outbox_defers_retention(self):
        for jobs, age in (([{'revision': n+1} for n in range(6)], 0), ([], 91)):
            self.latest(jobs, age)
            result = self.run_maintenance()
            self.assertFalse(result['ok'])
            self.assertEqual('AGENT_DRAIN_NOT_PROVEN', result['reason'])
        self.preserved_scope()

    def test_stale_or_future_core_clock_cannot_prune_by_wall_jump(self):
        for value in (self.now-3601, self.now+1):
            with self.db('controller.sqlite3') as db:
                db.execute('UPDATE state SET body=?', (json.dumps({**self.core, 'last_now': value}),))
            self.assertFalse(self.run_maintenance()['ok'])

    def test_minimum_retention_and_batch_ceiling_are_enforced(self):
        for policy in ({'history_retention_days': 6}, {'history_retention_days': True}, {'history_batch_rows': 10001},
                       {'history_max_seconds': 11}, {'vacuum_min_free_bytes': 1}):
            result = self.run_maintenance({**self.config, 'maintenance': policy})
            self.assertEqual('MAINTENANCE_POLICY_UNSAFE', result['reason'])
        self.preserved_scope()

    def test_worker_flock_makes_retention_a_nonblocking_hold(self):
        fd = os.open(self.root/'worker.lock', os.O_CREAT | os.O_RDWR, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.run_maintenance()
            self.assertEqual('WORKER_OR_JOURNAL_BUSY', result['reason'])
        finally: os.close(fd)
        self.preserved_scope()

    def test_unexpected_trigger_or_foreign_key_is_not_executed(self):
        with self.db('agent-reports.sqlite3') as db:
            db.execute('CREATE TRIGGER destructive BEFORE DELETE ON inbox BEGIN DELETE FROM latest; END')
        result = self.run_maintenance()
        self.assertEqual('HISTORY_SCHEMA_UNSAFE', result['reason'])
        with self.db('agent-reports.sqlite3') as db:
            self.assertEqual(1, db.execute('SELECT COUNT(*) FROM latest').fetchone()[0])
            db.execute('DROP TRIGGER destructive')
        with self.db('controller-evidence.sqlite3') as db:
            db.execute('DROP TABLE receipts')
            db.execute('CREATE TABLE receipts(hash TEXT PRIMARY KEY,received_at INTEGER NOT NULL,body TEXT NOT NULL,FOREIGN KEY(hash) REFERENCES sequence(agent))')
        self.assertEqual('HISTORY_SCHEMA_UNSAFE', self.run_maintenance()['reason'])

    def test_symlink_database_and_permissive_private_directory_are_refused(self):
        path = self.root/'controller-evidence.sqlite3'
        path.rename(self.root/'actual-history')
        path.symlink_to(self.root/'actual-history')
        self.assertFalse(self.run_maintenance()['ok'])
        path.unlink(); (self.root/'actual-history').rename(path)
        self.root.chmod(0o755)
        self.assertEqual('PRIVATE_PATH_MODE', self.run_maintenance()['reason'])

    def test_sqlite_writer_conflict_holds_without_partial_history_deletion(self):
        locked = self.db('agent-reports.sqlite3')
        locked.execute('BEGIN IMMEDIATE')
        try:
            result = self.run_maintenance()
            self.assertFalse(result['ok'])
            self.assertTrue(all(v == 0 for v in result['deleted'].values()))
        finally: locked.rollback()
        self.preserved_scope()

    def test_deadline_rolls_back_before_any_deletion(self):
        counter = {'calls': 0}
        def clock():
            counter['calls'] += 1
            return 10.0 if counter['calls'] == 1 else 14.0
        result = self.run_maintenance(monotonic=clock)
        self.assertEqual('MAINTENANCE_DEADLINE', result['reason'])
        self.assertTrue(all(v == 0 for v in result['deleted'].values()))
        self.preserved_scope()

    def test_batch_limits_leave_remaining_audit_manifests_intact(self):
        result = self.run_maintenance({**self.config, 'maintenance': {'history_batch_rows': 1}})
        self.assertTrue(result['ok'], result)
        self.assertTrue(all(v <= 1 for v in result['deleted'].values()))
        self.preserved_scope()

    def test_old_mutation_manifest_kept_when_remote_started_job_has_no_receipt(self):
        with self.db('controller-evidence.sqlite3') as db:
            db.execute('UPDATE manifests SET body=? WHERE agent=? AND revision=5',
                       (json.dumps({'agent_id': 'ir-agent', 'revision': 5,
                                    'jobs': [{'id': 'unseen-apply', 'op': 'apply'}]}), 'ir-agent'))
        result = self.run_maintenance()
        self.assertTrue(result['ok'], result)
        with self.db('controller-evidence.sqlite3') as db:
            self.assertIsNotNone(db.execute('SELECT 1 FROM manifests WHERE agent=? AND revision=5', ('ir-agent',)).fetchone())
        self.preserved_scope()

    def test_vacuum_is_skipped_when_main_disk_reserve_would_be_reduced(self):
        with self.db('controller-evidence.sqlite3') as db:
            for n in range(100):
                self.receipt(f'bulk-{n}', 5, self.old)
                db.execute('UPDATE receipts SET body=? WHERE hash=?',
                           (json.dumps({'agent_id': 'ir-agent', 'receipt': {'revision': 5}, 'padding': 'x'*5000}), f'bulk-{n}'))
                db.commit()
        result = self.run_maintenance(disk_usage=lambda root: shutil._ntuple_diskusage(100, 100, 0))
        self.assertTrue(result['ok'], result)
        self.assertEqual('space_reserved', result['vacuum']['controller-evidence.sqlite3'])
        self.preserved_scope()


if __name__ == '__main__':
    unittest.main()
