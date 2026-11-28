"""Local read-only candidate admission fixtures. No real provider calls."""
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
from relay_recovery.candidate_admission import inspect_candidate


class CandidateAdmissionTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.config = {'state_dir': str(self.root), 'policy': {'enabled': False}}
        self.state = {'active': {'provider_id': '40', 'generation': 1},
                      'phase': 'HEALTHY', 'candidate': None}
        self.core = self.root / 'controller.sqlite3'
        with sqlite3.connect(self.core) as db:
            db.executescript('CREATE TABLE state (id INTEGER PRIMARY KEY, body TEXT);'
                             'CREATE TABLE actions (id TEXT PRIMARY KEY,kind TEXT,body TEXT,receipt TEXT);')
            db.execute('INSERT INTO state VALUES(1,?)', (json.dumps(self.state),))
        self.core.chmod(0o600)
        self.provider = self.root / 'provider-effects.sqlite3'
        with sqlite3.connect(self.provider) as db:
            db.execute('CREATE TABLE resources(id TEXT PRIMARY KEY,body TEXT)')
        self.provider.chmod(0o600)

    def _update(self):
        with sqlite3.connect(self.core) as db:
            db.execute('UPDATE state SET body=? WHERE id=1', (json.dumps(self.state),))

    def _record(self, generation=2):
        with sqlite3.connect(self.provider) as db:
            db.execute('INSERT INTO resources VALUES(?,?)', ('51', json.dumps(
                {'provider_id': '51', 'generation': generation, 'labels': {'sn-intent': 'a'*40}})))

    def _digests(self):
        return [hashlib.sha256(p.read_bytes()).hexdigest() for p in (self.core, self.provider)]

    def test_manual_purchased_resource_is_not_a_controller_candidate(self):
        before = self._digests()
        report = inspect_candidate(self.config, '51')
        self.assertIn('CANDIDATE_NOT_REGISTERED', report['blockers'])
        self.assertIn('COMMITTED_CREATE_PROVENANCE_MISSING', report['blockers'])
        self.assertFalse(report['authorizes_effects'])
        self.assertEqual(before, self._digests())

    def test_matching_ledger_without_create_receipt_stays_blocked(self):
        self.state.update(candidate={'provider_id': '51', 'generation': 2}, phase='VALIDATING')
        self._update(); self._record()
        report = inspect_candidate(self.config, '51')
        self.assertTrue(report['provider_record_scope_matches'])
        self.assertIn('COMMITTED_CREATE_PROVENANCE_MISSING', report['blockers'])
        self.assertFalse(report['authorizes_effects'])

    def test_local_records_never_claim_live_acceptance(self):
        self.state.update(candidate={'provider_id': '51', 'generation': 2}, phase='VALIDATING')
        self._update(); self._record()
        for section in ('policy', 'production_driver', 'restore_workflow'):
            self.config[section] = {'enabled': True}
        with sqlite3.connect(self.core) as db:
            db.execute('INSERT INTO actions VALUES(?,?,?,?)', ('a'*40, 'CREATE', '{}', '{}'))
        before = self._digests()
        report = inspect_candidate(self.config, '51')
        self.assertTrue(report['local_create_receipt_exists'])
        self.assertFalse(report['live_acceptance_verified'])
        self.assertFalse(report['authorizes_effects'])
        self.assertEqual(before, self._digests())

    def test_wrong_id_and_active_source_rejected_as_candidates(self):
        self.state['candidate'] = {'provider_id': '51', 'generation': 2}
        self._update()
        report = inspect_candidate(self.config, '40')
        self.assertIn('CANDIDATE_ID_MISMATCH', report['blockers'])
        self.assertIn('EXPECTED_CANDIDATE_IS_ACTIVE_SOURCE', report['blockers'])
        with self.assertRaises(ValueError):
            inspect_candidate(self.config, '../40')

    def test_missing_provider_ledger_is_not_created(self):
        self.provider.unlink()
        report = inspect_candidate(self.config, '51')
        self.assertIn('PROVIDER_RECORD_MISSING', report['blockers'])
        self.assertFalse(self.provider.exists())

    def test_provider_ledger_symlink_is_refused(self):
        self.provider.unlink()
        self.provider.symlink_to(self.core)
        with self.assertRaises(ValueError):
            inspect_candidate(self.config, '51')
