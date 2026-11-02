"""Behavioral recovery tests. No real API, credentials, subprocess or network."""
from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor
import dataclasses
import importlib.util
from pathlib import Path
import sqlite3
import sys
import tempfile
import threading
import unittest

MODULE = Path(__file__).resolve().parents[1] / 'core.py'
spec = importlib.util.spec_from_file_location('relay_recovery_core', MODULE)
core = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = core
spec.loader.exec_module(core)


class RecoveryTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / 'recovery.sqlite3'
        self.now = 1_700_000_000
        self.cfg = core.Config(enabled=True, owner_id='1', service_id='711', project_id='p',
            outage_seconds=180, suspect_seconds=60, recover_seconds=60,
            candidate_seconds=60, observe_seconds=120, receipt_max_age=30,
            observation_max_gap=30, intent_ttl=15, candidate_deadline=300)
        self.j = core.Journal(self.path)
        self.j.initialize(dict(provider='hetzner', project_id='p', provider_id='old',
                               generation=1, config_hash='cfg-1'), self.now)
        self.e = core.Engine(self.cfg, self.j)

    def state(self):
        return self.e.snapshot()

    def receipt(self, resource=None, **kw):
        s = self.state()
        r = resource or s['active']
        return dict(verified=True, received_at=self.now, incident_id=s['incident_id'],
                    generation=r['generation'], config_hash=r['config_hash'], **kw)

    def health(self, ok=False, resource=None, **kw):
        return self.receipt(resource, agent_id='ir-agent', direct_control=True,
                            host_healthy=True, internet_healthy=True,
                            tunnel_ok=ok, probe_ok=ok, **kw)

    def option(self, country='DE', location='fsn1', price=5_000_000, **kw):
        d = dict(verified=True, received_at=self.now, country=country,
                 location=location, available=True, compatible=True,
                 provider='hetzner', project_id='p', owner_id='1', service_id='711',
                 currency='EUR',
                 architecture='x86', config_hash='cfg-2', server_type='cx23', image='ubuntu-x86',
                 monthly_gross_micro=price, trial_max_gross_micro=100_000)
        d.update(kw)
        return d

    def evidence(self, ok=False, **kw):
        s = self.state()
        d = dict(health=self.health(ok),
            preflight=dict(verified=True, received_at=self.now, owner_id='1', service_id='711',
                           project_id='p', ready=True,
                           checks={k: True for k in self.cfg.required_preflight}),
            inventory=dict(verified=True, received_at=self.now, complete=True,
                           managed_vm_count=1, project_id='p', active_identity=core.identity(s['active'])),
            options=[self.option()])
        d.update(kw)
        return d

    def observe(self, evidence=None):
        ev = self.evidence() if evidence is None else evidence
        return self.e.observe(ev, self.now)

    def advance(self, seconds, ok=False, candidate=False):
        self.observe(self.evidence(ok))
        for _ in range(seconds // 30):
            self.now += 30
            ev = self.evidence(ok)
            if candidate:
                ev['candidate_health'] = self.health(True, self.state()['candidate'])
            self.observe(ev)
        return ev

    def plan(self, evidence=None):
        return self.e.plan(self.state(), evidence or self.evidence(), self.now)

    def effect(self, intent, outcome, **kw):
        return dict(intent_id=intent.id, kind=intent.kind, outcome=outcome,
                    verified=True, received_at=self.now, **kw)

    def candidate(self):
        self.advance(180)
        ev = self.evidence()
        intent = self.plan(ev)
        self.assertEqual(intent.kind, 'CREATE')
        self.e.commit_intent(intent, ev, self.now)
        ident = dict(provider='hetzner', project_id='p', provider_id='new', generation=2)
        receipt = self.effect(intent, 'created', identity=ident, create_intent_id=intent.id)
        self.e.record_effect(intent.id, receipt, self.now)
        return intent, ident

    def promotion_evidence(self):
        s = self.state()
        c = s['candidate'] or s['active']
        return self.evidence(candidate_health=self.health(True, c),
            critical_receipts=[self.receipt(c, consumer=name, ok=True) for name in self.cfg.critical_consumers],
            backup=self.receipt(c, checkpoint='cp-2', durable=True, restore_verified=True),
            fencing=self.receipt(c, old_generation=(s['retiring'] or s['active'])['generation'], verified_fenced=True))

    def promote(self):
        self.candidate()
        for _ in range(3):
            self.now += 30
            self.observe(self.promotion_evidence())
        ev = self.promotion_evidence()
        intent = self.plan(ev)
        self.assertEqual(intent.kind, 'PROMOTE')
        self.e.commit_intent(intent, ev, self.now)
        self.e.record_effect(intent.id, self.effect(intent, 'promoted',
                             identity=core.identity(self.state()['candidate']), all_checks_passed=True), self.now)
        return intent

    def retire_ready(self):
        self.promote()
        for _ in range(5):
            self.now += 30
            self.observe(self.evidence(True))
        ev = self.promotion_evidence()
        ev['health'] = self.health(True)
        ev['backup']['covers_promotion_checkpoint'] = 'cp-2'
        ev['ttl'] = self.receipt(known=True, expires_at=self.now)
        ev['active_refs'] = self.receipt(complete=True, count=0)
        return ev

    def test_disabled_is_default_and_no_effect_is_authorized(self):
        engine = core.Engine(dataclasses.replace(self.cfg, enabled=False), self.j)
        self.advance(180)
        intent = engine.plan(self.state(), self.evidence(), self.now)
        self.assertEqual((intent.kind, intent.reason), ('HOLD', 'DISABLED'))
        with self.assertRaises(core.StaleIntent):
            engine.commit_intent(intent, self.evidence(), self.now)

    def test_default_outage_threshold_is_three_hours(self):
        self.assertEqual(core.Config().outage_seconds, 10800)
        self.advance(150)
        self.assertEqual(self.plan().reason, 'SUSPECT')
        self.now += 30
        self.observe()
        self.assertEqual(self.plan().kind, 'CREATE')

    def test_ir_or_control_failure_never_buys(self):
        self.advance(180)
        for field in ('direct_control', 'host_healthy', 'internet_healthy'):
            ev = self.evidence()
            ev['health'][field] = False
            self.observe(ev)
            self.assertEqual(self.plan(ev).reason, 'IR_UNREACHABLE_OR_STALE')

    def test_stale_foreign_or_unverified_health_does_not_authorize_purchase(self):
        self.advance(180)
        for patch in ({'received_at': self.now - 31}, {'verified': False},
                      {'agent_id': 'foreign'}, {'generation': 99}, {'config_hash': 'other'}):
            ev = self.evidence()
            ev['health'].update(patch)
            self.assertEqual(self.plan(ev).kind, 'HOLD')

    def test_ping_only_failure_is_not_part_of_core_tunnel_failure(self):
        ev = self.evidence(True)
        ev['health']['ping_ok'] = False
        self.observe(ev)
        self.assertEqual(self.plan(ev).reason, 'HEALTHY')

    def test_probe_gap_does_not_accumulate_outage(self):
        self.observe()
        self.now += 180
        self.observe()
        self.assertEqual(self.plan().reason, 'SUSPECT')
        self.assertEqual(self.state()['health']['bad_seconds'], 0)

    def test_old_failures_do_not_count_after_long_unknown_gap(self):
        self.advance(180)
        self.assertEqual(self.plan().kind, 'CREATE')
        self.now += 3600
        self.observe()
        self.advance(60)
        self.assertEqual(self.plan().reason, 'SUSPECT')
        self.assertEqual(self.state()['health']['bad_seconds'], 60)

    def test_short_recovery_does_not_reset_incident(self):
        self.advance(120)
        incident = self.state()['incident_id']
        self.now += 30
        self.observe(self.evidence(True))
        self.now += 30
        self.observe()
        self.assertEqual(self.state()['incident_id'], incident)
        self.assertGreaterEqual(self.state()['health']['bad_seconds'], 120)

    def test_sustained_recovery_clears_incident_without_owner(self):
        self.advance(120)
        self.advance(60, ok=True)
        self.assertIsNone(self.state()['incident_id'])
        self.assertEqual(self.plan(self.evidence(True)).reason, 'HEALTHY')

    def test_preflight_must_cover_every_requirement(self):
        self.advance(180)
        ev = self.evidence()
        del ev['preflight']['checks']['restore_current']
        self.assertEqual(self.plan(ev).reason, 'PREFLIGHT_INCOMPLETE')
        self.assertEqual(self.plan().kind, 'CREATE')

    def test_country_priority_then_actual_cost(self):
        self.advance(180)
        ev = self.evidence(options=[self.option('US', 'ash', 1_000_000),
            self.option('FI', 'hel1', 2_000_000), self.option('DE', 'fsn1', 6_000_000),
            self.option('DE', 'nbg1', 5_000_000)])
        intent = self.plan(ev)
        self.assertEqual(intent.payload['option']['location'], 'nbg1')

    def test_incompatible_overpriced_or_stale_options_are_rejected(self):
        self.advance(180)
        for patch in ({'architecture': 'arm'}, {'compatible': False}, {'available': False},
                      {'monthly_gross_micro': 12_000_001}, {'owner_id': 'other'},
                      {'service_id': 'other'}, {'currency': 'USD'}, {'received_at': self.now - 301}):
            ev = self.evidence(options=[self.option(**patch)])
            self.assertEqual(self.plan(ev).reason, 'CAPACITY_OR_COST_BLOCKED')

    def test_unknown_inventory_and_two_vm_limit_block_purchase(self):
        self.advance(180)
        for patch in ({'complete': False}, {'managed_vm_count': 2},
                      {'active_identity': dict(provider='hetzner', project_id='p', provider_id='other', generation=1)}):
            ev = self.evidence()
            ev['inventory'].update(patch)
            self.assertEqual(self.plan(ev).reason, 'INVENTORY_UNKNOWN_OR_VM_LIMIT')

    def test_intent_is_durable_before_network_and_restart_only_reconciles(self):
        self.advance(180)
        ev = self.evidence()
        intent = self.plan(ev)
        self.e.commit_intent(intent, ev, self.now)
        engine = core.Engine(self.cfg, core.Journal(self.path))
        next_intent = engine.plan(engine.snapshot(), ev, self.now)
        self.assertEqual(next_intent.kind, 'RECONCILE_CREATE')
        self.assertEqual(next_intent.payload['create_intent_id'], intent.id)
        db = sqlite3.connect(self.path)
        self.addCleanup(db.close)
        self.assertEqual(db.execute('SELECT COUNT(*) FROM attempts').fetchone()[0], 1)

    def test_parallel_workers_only_claim_one_create(self):
        self.advance(180)
        ev, intent = self.evidence(), self.plan()
        barrier = threading.Barrier(2)
        def claim():
            barrier.wait()
            try:
                self.e.commit_intent(intent, ev, self.now)
                return 'claimed'
            except (core.Busy, core.StaleIntent):
                return 'blocked'
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda _: claim(), range(2)))
        self.assertEqual(sorted(results), ['blocked', 'claimed'])

    def test_old_plan_cannot_commit_after_new_observation(self):
        self.advance(180)
        ev = self.evidence()
        intent = self.plan(ev)
        self.observe(ev)
        with self.assertRaises(core.StaleIntent):
            self.e.commit_intent(intent, ev, self.now)

    def test_changed_gates_cannot_be_claimed(self):
        self.advance(180)
        ev = self.evidence()
        intent = self.plan(ev)
        ev['preflight']['ready'] = False
        with self.assertRaises(core.StaleIntent):
            self.e.commit_intent(intent, ev, self.now)

    def test_current_recovered_health_blocks_create_without_observe(self):
        self.advance(180)
        intent = self.plan()
        self.assertEqual(intent.kind, 'CREATE')
        recovered = self.evidence(True)
        self.assertEqual(self.plan(recovered).reason, 'RECOVERING')
        with self.assertRaises(core.StaleIntent):
            self.e.commit_intent(intent, recovered, self.now)

    def test_effect_without_intent_and_mutable_receipt_are_rejected(self):
        with self.assertRaises(core.RecoveryError):
            self.e.record_effect('missing', {}, self.now)
        intent, ident = self.candidate()
        good = self.effect(intent, 'created', identity=ident, create_intent_id=intent.id)
        self.e.record_effect(intent.id, good, self.now)  # byte-identical replay is idempotent
        good['outcome'] = 'failed'
        with self.assertRaises(core.RecoveryError):
            self.e.record_effect(intent.id, good, self.now)

    def test_empty_list_absence_does_not_clear_unknown_create(self):
        self.advance(180)
        ev, intent = self.evidence(), self.plan()
        self.e.commit_intent(intent, ev, self.now)
        self.e.record_effect(intent.id, self.effect(intent, 'unknown'), self.now)
        reconcile = self.plan()
        self.e.commit_intent(reconcile, ev, self.now)
        self.e.record_effect(reconcile.id, self.effect(reconcile, 'absent'), self.now)
        self.assertEqual(self.plan().kind, 'RECONCILE_CREATE')

    def test_definitive_create_rejection_advances_country_without_duplicate(self):
        self.advance(180)
        ev, intent = self.evidence(), self.plan()
        self.e.commit_intent(intent, ev, self.now)
        self.e.record_effect(intent.id, self.effect(intent, 'failed', definitive_rejection=True), self.now)
        ev['options'] = [self.option(), self.option('FI', 'hel1')]
        next_intent = self.plan(ev)
        self.assertEqual(next_intent.kind, 'CREATE')
        self.assertEqual(next_intent.payload['option']['country'], 'FI')

    def test_bad_candidate_identity_never_adopts_active_resource(self):
        self.advance(180)
        ev, intent = self.evidence(), self.plan()
        self.e.commit_intent(intent, ev, self.now)
        with self.assertRaises(core.RecoveryError):
            self.e.record_effect(intent.id, self.effect(intent, 'created',
                identity=dict(provider='hetzner', project_id='p', provider_id='old', generation=2),
                create_intent_id=intent.id), self.now)
        self.assertEqual(self.plan().kind, 'RECONCILE_CREATE')

    def test_rejected_candidate_needs_exact_fresh_no_write_cleanup_proof(self):
        self.candidate()
        c = self.state()['candidate']
        ev = self.evidence(candidate_rejected=self.receipt(c, rejected=True))
        self.assertEqual(self.plan(ev).reason, 'CANDIDATE_DATA_UNSAFE')
        ev['cleanup'] = self.receipt(c, identity=core.identity(c), no_actual_writes=True, never_promoted=True)
        self.assertEqual(self.plan(ev).kind, 'DELETE_CANDIDATE')
        for field in ('no_actual_writes', 'never_promoted'):
            ev['cleanup'][field] = False
            self.assertEqual(self.plan(ev).reason, 'CANDIDATE_DATA_UNSAFE')
            ev['cleanup'][field] = True
        ev['cleanup']['identity']['provider_id'] = 'unrelated'
        self.assertEqual(self.plan(ev).kind, 'HOLD')

    def test_deletion_ack_without_primary_ip_absence_remains_pending(self):
        self.candidate()
        c = self.state()['candidate']
        ev = self.evidence(candidate_rejected=self.receipt(c, rejected=True),
            cleanup=self.receipt(c, identity=core.identity(c), no_actual_writes=True, never_promoted=True))
        intent = self.plan(ev)
        self.e.commit_intent(intent, ev, self.now)
        self.e.record_effect(intent.id, self.effect(intent, 'deleted', identity=core.identity(c),
                             absence_verified=True, dependent_resources_absent=False), self.now)
        self.assertEqual(self.plan().kind, 'RECONCILE_DELETE_CANDIDATE')
        self.assertIsNotNone(self.state()['candidate'])

    def test_candidate_cannot_promote_on_only_tcp_success(self):
        self.candidate()
        for _ in range(3):
            self.now += 30
            self.observe(self.evidence(candidate_health=self.health(True, self.state()['candidate'])))
        self.assertEqual(self.plan().reason, 'CANDIDATE_VALIDATION')

    def test_complete_validated_candidate_promotes_but_does_not_delete_old(self):
        self.promote()
        self.assertEqual(self.state()['active']['provider_id'], 'new')
        self.assertEqual(self.state()['retiring']['provider_id'], 'old')
        self.assertEqual(self.plan().reason, 'RETIRE_GATES_INCOMPLETE')

    def test_retire_requires_every_scope_fresh_receipt_ttl_and_zero_refs(self):
        good = self.retire_ready()
        self.assertEqual(self.plan(good).kind, 'RETIRE')
        import copy
        variants = []
        v = copy.deepcopy(good); v['critical_receipts'].pop(); variants.append(v)
        v = copy.deepcopy(good); v['critical_receipts'][0]['config_hash'] = 'old'; variants.append(v)
        v = copy.deepcopy(good); v['critical_receipts'][0]['incident_id'] = 'other'; variants.append(v)
        v = copy.deepcopy(good); v['critical_receipts'][0]['generation'] = 1; variants.append(v)
        v = copy.deepcopy(good); v['critical_receipts'][0]['received_at'] -= 31; variants.append(v)
        v = copy.deepcopy(good); v['backup']['covers_promotion_checkpoint'] = 'other'; variants.append(v)
        v = copy.deepcopy(good); v['fencing']['verified_fenced'] = False; variants.append(v)
        v = copy.deepcopy(good); v['ttl']['known'] = False; variants.append(v)
        v = copy.deepcopy(good); v['ttl']['expires_at'] += 1; variants.append(v)
        v = copy.deepcopy(good); v['active_refs']['count'] = 1; variants.append(v)
        v = copy.deepcopy(good); v['active_refs']['complete'] = False; variants.append(v)
        v = copy.deepcopy(good); duplicate = dict(v['critical_receipts'][0]); duplicate['ok'] = False
        v['critical_receipts'].append(duplicate); variants.append(v)
        for v in variants:
            self.assertEqual(self.plan(v).reason, 'RETIRE_GATES_INCOMPLETE')

    def test_fresh_failed_health_blocks_retire_even_without_observe(self):
        ev = self.retire_ready()
        intent = self.plan(ev)
        self.assertEqual(intent.kind, 'RETIRE')
        ev['health']['tunnel_ok'] = False
        self.assertEqual(self.plan(ev).reason, 'RETIRE_GATES_INCOMPLETE')
        with self.assertRaises(core.StaleIntent):
            self.e.commit_intent(intent, ev, self.now)

    def test_fresh_failed_candidate_blocks_promotion_even_without_observe(self):
        self.candidate()
        for _ in range(3):
            self.now += 30
            self.observe(self.promotion_evidence())
        ev = self.promotion_evidence()
        intent = self.plan(ev)
        self.assertEqual(intent.kind, 'PROMOTE')
        ev['candidate_health']['probe_ok'] = False
        self.assertEqual(self.plan(ev).reason, 'CANDIDATE_VALIDATION')
        with self.assertRaises(core.StaleIntent):
            self.e.commit_intent(intent, ev, self.now)

    def test_fresh_backup_may_advance_checkpoint_with_verified_coverage(self):
        ev = self.retire_ready()
        ev['backup']['checkpoint'] = 'cp-3'
        self.assertEqual(self.plan(ev).kind, 'RETIRE')
        del ev['backup']['covers_promotion_checkpoint']
        self.assertEqual(self.plan(ev).reason, 'RETIRE_GATES_INCOMPLETE')

    def test_retire_retry_is_read_only_until_new_fresh_permit(self):
        ev = self.retire_ready()
        intent = self.plan(ev)
        self.e.commit_intent(intent, ev, self.now)
        self.e.record_effect(intent.id, self.effect(intent, 'pending',
                             identity=core.identity(self.state()['retiring'])), self.now)
        ev['health']['probe_ok'] = False
        reconcile = self.plan(ev)
        self.assertEqual(reconcile.kind, 'RECONCILE_RETIRE')
        self.assertIs(reconcile.payload['read_only'], True)
        self.e.commit_intent(reconcile, ev, self.now)
        self.e.record_effect(reconcile.id, self.effect(reconcile, 'present',
            identity=core.identity(self.state()['retiring']), delete_not_pending=True), self.now)
        self.assertEqual(self.plan(ev).reason, 'RETIRE_GATES_INCOMPLETE')
        ev['health']['probe_ok'] = True
        new_permit = self.plan(ev)
        self.assertEqual(new_permit.kind, 'RETIRE')
        self.assertNotEqual(new_permit.id, intent.id)

    def test_orphan_ip_cleanup_requires_new_permit_after_read_only_reconcile(self):
        ev = self.retire_ready()
        intent = self.plan(ev)
        self.e.commit_intent(intent, ev, self.now)
        self.e.record_effect(intent.id, self.effect(intent, 'pending',
            identity=core.identity(self.state()['retiring'])), self.now)
        reconcile = self.plan(ev)
        self.e.commit_intent(reconcile, ev, self.now)
        self.e.record_effect(reconcile.id, self.effect(reconcile, 'cleanup_required',
            identity=core.identity(self.state()['retiring']), server_absence_verified=True,
            delete_not_pending=True), self.now)
        self.assertIsNotNone(self.state()['retiring'])
        self.assertEqual(self.plan(ev).kind, 'RETIRE')
        ev['health']['probe_ok'] = False
        self.assertEqual(self.plan(ev).reason, 'RETIRE_GATES_INCOMPLETE')

    def test_retire_deletes_exact_old_generation_and_keeps_logical_service(self):
        ev = self.retire_ready()
        intent = self.plan(ev)
        self.assertEqual(intent.payload['identity']['provider_id'], 'old')
        self.e.commit_intent(intent, ev, self.now)
        with self.assertRaises(core.RecoveryError):
            self.e.record_effect(intent.id, self.effect(intent, 'deleted',
                identity=core.identity(self.state()['active']), absence_verified=True,
                dependent_resources_absent=True), self.now)
        self.e.record_effect(intent.id, self.effect(intent, 'deleted',
            identity=core.identity(self.state()['retiring']), absence_verified=True,
            dependent_resources_absent=True), self.now)
        self.assertIsNone(self.state()['retiring'])
        self.assertEqual(self.state()['active']['provider_id'], 'new')
        self.assertEqual(self.cfg.service_id, '711')

    def test_backward_clock_blocks_mutations_across_restart(self):
        self.advance(180)
        self.now -= 60
        self.observe()
        restarted = core.Engine(self.cfg, core.Journal(self.path))
        self.assertEqual(restarted.plan(restarted.snapshot(), self.evidence(), self.now).reason,
                         'CLOCK_OR_OBSERVATION_UNSAFE')
        self.now += 60
        ev = self.evidence(clock=dict(verified=True, received_at=self.now, local_clock_verified=True))
        self.observe(ev)
        self.assertFalse(self.state()['clock_unsafe'])

    def test_budget_is_durable_and_not_reset_by_restart(self):
        self.advance(180)
        db = sqlite3.connect(self.path)
        self.addCleanup(db.close)
        incident = self.state()['incident_id']
        for n in range(5):
            db.execute('INSERT INTO attempts VALUES(?,?,?,?,?,?)',
                       (str(n), incident, self.now, 100_000, 'DE', 'fsn1'))
        db.commit()
        restarted = core.Engine(self.cfg, core.Journal(self.path))
        self.assertEqual(restarted.plan(restarted.snapshot(), self.evidence(), self.now).reason,
                         'ATTEMPT_BUDGET_BLOCKED')

    def test_same_incident_resumes_after_real_window_without_resetting_ledger(self):
        self.advance(180)
        incident = self.state()['incident_id']
        db = sqlite3.connect(self.path)
        self.addCleanup(db.close)
        created_at = self.now - 86300
        for n in range(5):
            db.execute('INSERT INTO attempts VALUES(?,?,?,?,?,?)',
                       (str(n), incident, created_at, 100_000, 'DE', 'fsn1'))
        db.commit()
        intent = self.plan()
        self.assertEqual(intent.reason, 'ATTEMPT_BUDGET_BLOCKED')
        self.assertEqual(intent.retry_at, created_at + 86400)
        self.advance(120)
        self.assertEqual(self.state()['incident_id'], incident)
        ev = self.evidence()
        intent = self.plan(ev)
        self.assertEqual(intent.kind, 'CREATE')
        self.assertEqual(intent.payload['attempt'], 6)
        self.e.commit_intent(intent, ev, self.now)
        self.assertEqual(db.execute('SELECT COUNT(*) FROM attempts').fetchone()[0], 6)

    def test_monthly_trial_cost_is_global_and_uses_real_calendar_boundary(self):
        self.advance(180)
        db = sqlite3.connect(self.path)
        self.addCleanup(db.close)
        for n in range(30):
            db.execute('INSERT INTO attempts VALUES(?,?,?,?,?,?)',
                       ('old-' + str(n), 'other-incident', self.now - 172800, 100_000, 'DE', 'fsn1'))
        db.commit()
        intent = self.plan()
        self.assertEqual(intent.reason, 'ATTEMPT_BUDGET_BLOCKED')
        import datetime
        boundary = datetime.datetime.fromtimestamp(intent.retry_at, tz=datetime.timezone.utc)
        self.assertEqual((boundary.month, boundary.day, boundary.hour, boundary.minute), (12, 1, 0, 0))
        self.assertEqual(db.execute('SELECT COUNT(*) FROM attempts').fetchone()[0], 30)

    def test_trial_reservations_cannot_exceed_rolling_cost_limit(self):
        self.advance(180)
        db = sqlite3.connect(self.path)
        self.addCleanup(db.close)
        db.execute('INSERT INTO attempts VALUES(?,?,?,?,?,?)',
                   ('other', 'other-incident', self.now, 950_000, 'DE', 'nbg1'))
        db.commit()
        self.assertEqual(self.plan().reason, 'CAPACITY_OR_COST_BLOCKED')

    def test_hard_policy_bounds_cannot_be_increased(self):
        for change in ({'max_vms': 3}, {'max_attempts_24h': 6}, {'max_promotions_24h': 3}):
            with self.assertRaises(core.RecoveryError):
                core.Engine(dataclasses.replace(self.cfg, **change), self.j)

    def test_journal_currency_cannot_be_reinterpreted_after_restart(self):
        with self.assertRaises(core.RecoveryError):
            core.Engine(dataclasses.replace(self.cfg, budget_currency='USD'), self.j)


if __name__ == '__main__':
    unittest.main()
