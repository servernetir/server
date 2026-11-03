"""Behavioral recovery tests. No real API, credentials, subprocess or network."""
from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor
import copy
import dataclasses
import importlib.util
from pathlib import Path
import sqlite3
import sys
import tempfile
import threading
import unittest
import uuid

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
        self.cfg = core.Config(enabled=True, owner_id='1', service_id='7001', project_id='p',
            outage_seconds=180, suspect_seconds=60, recover_seconds=60,
            candidate_seconds=60, observe_seconds=120, receipt_max_age=30,
            observation_max_gap=30, intent_ttl=15, candidate_deadline=3600)
        self.j = core.Journal(self.path)
        self.j.initialize(dict(provider='hetzner', project_id='p', provider_id='old',
                               generation=1, config_hash='cfg-1'), self.now)
        self.e = core.Engine(self.cfg, self.j)

    def state(self):
        return self.e.snapshot()

    def receipt(self, resource=None, **kw):
        s = self.state()
        r = resource or s['active']
        value = dict(verified=True, received_at=self.now, incident_id=s['incident_id'],
                     generation=r['generation'], config_hash=r['config_hash'])
        if 'restore_epoch' in r:
            value.update(restore_epoch=r['restore_epoch'],restore_hash=r['restore_hash'],observed_at=self.now)
        value.update(kw)
        return value

    def health(self, ok=False, resource=None, **kw):
        return self.receipt(resource, agent_id='ir-agent', direct_control=True,
                            host_healthy=True, internet_healthy=True,
                            tunnel_ok=ok, probe_ok=ok, **kw)

    def option(self, country='DE', location='fsn1', price=5_000_000, **kw):
        d = dict(verified=True, received_at=self.now, country=country,
                 location=location, available=True, compatible=True,
                 provider='hetzner', project_id='p', owner_id='1', service_id='7001',
                 currency='EUR',
                 architecture='x86', config_hash='cfg-2', server_type='cx23', image='ubuntu-x86',
                 monthly_gross_micro=price, trial_max_gross_micro=100_000)
        d.update(kw)
        return d

    def evidence(self, ok=False, **kw):
        s = self.state()
        d = dict(health=self.health(ok),
            preflight=dict(verified=True, received_at=self.now, owner_id='1', service_id='7001',
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
        evidence = self.evidence(candidate_health=self.health(True, c),
            critical_receipts=[self.receipt(c, consumer=name, ok=True) for name in self.cfg.critical_consumers],
            backup=self.receipt(c, checkpoint='cp-2', durable=True, restore_verified=True),
            fencing=self.receipt(c, old_generation=(s['retiring'] or s['active'])['generation'], verified_fenced=True))
        if 'restore_epoch' in c:
            start=c['restore_recorded_at']
            evidence['restore_ready']=self.receipt(c,phase='PRE_CUTOVER_READY',pre_cutover_ready=True,
                identity=core.identity(c),source_identity=core.identity(s['retiring'] or s['active']),
                workflow_id=c['restore_workflow_id'],snapshot_id=c['restore_snapshot_id'],
                global_cutover_complete=False,migration_complete=False,acceptance_started_at=start,
                acceptance_last_observed_at=self.now,acceptance_seconds=self.now-start)
        return evidence

    def restore_candidate(self,**changes):
        proof=self.restore_receipt(**changes);self.begin_restore_for(proof)
        self.e.record_restore_epoch(proof,self.now)
        return proof

    def accept_restore(self):
        for _ in range(40):
            self.now+=30;self.observe(self.promotion_evidence())

    def promote(self):
        self.candidate()
        self.restore_candidate();self.accept_restore()
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

    def test_restored_candidate_never_deletes_on_expiry_or_forged_fresh_cleanup(self):
        self.candidate();self.restore_candidate()
        for expire in (False,True):
            if expire:
                self.now+=self.cfg.candidate_deadline;self.observe(self.evidence())
            c=self.state()['candidate']
            evidence=self.evidence(cleanup=self.receipt(c,identity=core.identity(c),
                no_actual_writes=True,never_promoted=True))
            if not expire:evidence['candidate_rejected']=self.receipt(c,rejected=True)
            with self.subTest(expired=expire):
                intent=self.plan(evidence)
                self.assertEqual(('HOLD','CANDIDATE_DATA_UNSAFE'),(intent.kind,intent.reason))
        self.assertIsNotNone(self.state()['candidate'])
        self.assertEqual(self.state()['candidate']['restore_epoch'],1)

    def test_restore_cleanup_guard_preserves_pending_readonly_deletion_reconciliation(self):
        self.candidate();c=self.state()['candidate']
        evidence=self.evidence(candidate_rejected=self.receipt(c,rejected=True),
            cleanup=self.receipt(c,identity=core.identity(c),no_actual_writes=True,never_promoted=True))
        intent=self.plan(evidence);self.e.commit_intent(intent,evidence,self.now)
        # Model a retained historical pending record; public restore APIs refuse
        # to cross this pending action, so no new write is being authorized here.
        with self.j.transaction()as db:
            state=self.j._read(db);state['candidate']['restore_epoch']=1;self.j._write(db,state)
        reconcile=self.plan(evidence)
        self.assertEqual('RECONCILE_DELETE_CANDIDATE',reconcile.kind)
        self.assertTrue(reconcile.payload['read_only'])

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

    def test_network_healthy_trial_with_all_legacy_receipts_cannot_promote_before_restore(self):
        self.candidate()
        for _ in range(3):
            self.now+=30;self.observe(self.promotion_evidence())
        evidence=self.promotion_evidence()
        self.assertTrue(all(r['ok']for r in evidence['critical_receipts']))
        self.assertTrue(evidence['backup']['restore_verified'])
        self.assertEqual('HOLD',self.plan(evidence).kind)
        # A report alone cannot introduce the committed restore metadata.
        c=self.state()['candidate']
        evidence['restore_ready']=self.receipt(c,restore_epoch=1,restore_hash='d'*64,
            workflow_id=str(uuid.uuid4()),snapshot_id='123',phase='PRE_CUTOVER_READY',pre_cutover_ready=True)
        self.assertEqual('HOLD',self.plan(evidence).kind)

    def test_restore_acceptance_cannot_be_shortened_by_candidate_policy(self):
        self.candidate();self.restore_candidate()
        for _ in range(39):
            self.now+=30;self.observe(self.promotion_evidence())
        self.now+=29;self.observe(self.promotion_evidence())
        self.assertEqual(1199,self.promotion_evidence()['restore_ready']['acceptance_seconds'])
        self.assertEqual('HOLD',self.plan(self.promotion_evidence()).kind)
        self.now+=1;self.observe(self.promotion_evidence())
        self.assertEqual('PROMOTE',self.plan(self.promotion_evidence()).kind)

    def test_restore_ready_requires_fresh_exact_workflow_epoch_snapshot_and_source(self):
        self.candidate();self.restore_candidate();self.accept_restore()
        good=self.promotion_evidence();self.assertEqual('PROMOTE',self.plan(good).kind)
        variants=[{'verified':False},{'received_at':self.now-31},{'phase':'POST_RESTORE_VALIDATION'},
            {'pre_cutover_ready':False},{'global_cutover_complete':True},{'migration_complete':True},
            {'workflow_id':str(uuid.uuid4())},{'snapshot_id':'other'},{'restore_epoch':True},
            {'restore_hash':'e'*64},{'generation':True},{'acceptance_seconds':True},
            {'acceptance_seconds':1199},{'acceptance_started_at':self.state()['candidate']['restore_recorded_at']-1},
            {'acceptance_last_observed_at':self.now-1},
            {'source_identity':{**good['restore_ready']['source_identity'],'provider_id':'foreign'}},
            {'identity':{**good['restore_ready']['identity'],'provider_id':'foreign'}}]
        for change in variants:
            evidence=copy.deepcopy(good);evidence['restore_ready'].update(change)
            with self.subTest(change=change):self.assertEqual('HOLD',self.plan(evidence).kind)
        evidence=copy.deepcopy(good);evidence.pop('restore_ready')
        self.assertEqual('HOLD',self.plan(evidence).kind)

    def test_restore_ready_requires_twenty_minutes_of_actual_observation(self):
        self.candidate();self.restore_candidate();self.accept_restore()
        evidence=self.promotion_evidence();r=evidence['restore_ready']
        r.update(observed_at=self.now-1,acceptance_last_observed_at=self.now-1)
        self.assertEqual(1200,r['acceptance_seconds'])
        self.assertEqual('HOLD',self.plan(evidence).kind)

    def test_restore_ready_old_observation_cannot_be_rewrapped_with_fresh_receive_time(self):
        self.candidate();self.restore_candidate();self.accept_restore()
        self.now+=31;self.observe(self.promotion_evidence())
        evidence=self.promotion_evidence();r=evidence['restore_ready']
        r.update(observed_at=self.now-31,acceptance_last_observed_at=self.now-31)
        self.assertEqual(self.now,r['received_at'])
        self.assertGreaterEqual(r['observed_at']-r['acceptance_started_at'],1200)
        self.assertEqual('HOLD',self.plan(evidence).kind)

    def test_restore_ready_is_bound_into_promotion_intent_and_rechecked_at_claim(self):
        self.candidate();self.restore_candidate();self.accept_restore()
        evidence=self.promotion_evidence();intent=self.plan(evidence)
        self.assertEqual(intent.payload['gate_hash'],core.digest({k:evidence[k]for k in
            ('candidate_health','critical_receipts','backup','fencing','restore_ready')}))
        evidence['restore_ready']['phase']='POST_RESTORE_VALIDATION'
        with self.assertRaises(core.StaleIntent):self.e.commit_intent(intent,evidence,self.now)
        self.assertEqual('VALIDATING',self.state()['phase'])

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
        self.restore_candidate();self.accept_restore()
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
        self.assertEqual(self.cfg.service_id, '7001')

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

    def installation_authorization(self, **changes):
        authorization = dict(verified=True, id='local-installation-2026', purpose='installation_restore_drill',
            owner_id='1', service_id='7001', project_id='p', active_identity=core.identity(self.state()['active']),
            active_config_hash=self.state()['active']['config_hash'], issued_at=self.now - 5,
            expires_at=self.now + 300, max_one_candidate=True)
        authorization.update(changes)
        return authorization

    def installation_evidence(self):
        ev = self.evidence(True)
        ev['preflight']['ready'] = False
        ev['preflight']['checks'].update(restore_current=False, safe_boot_guard_ready=True)
        ev['safe_boot_guard'] = dict(verified=True, received_at=self.now,
            active_identity=core.identity(self.state()['active']), guard_hash='b' * 64,
            safe_boot_guard_ready=True, fail_closed=True)
        return ev

    def ledger_rows(self):
        with sqlite3.connect(self.path) as db:
            return db.execute('SELECT * FROM attempts ORDER BY id').fetchall()

    def restore_receipt(self, **changes):
        candidate = self.state()['candidate']
        proof = self.receipt(candidate, identity=core.identity(candidate), source_identity=core.identity(self.state()['active']),
            workflow_id=str(uuid.uuid4()), workflow_committed=True, snapshot_id='123456789',
            restore_epoch=1, restore_hash='d' * 64, snapshot_available=True, source_fenced=True,
            boot_verified=True, quarantine_verified=True, machine_id_sha256='e' * 64, boot_id_sha256='f' * 64)
        proof.update(changes)
        return proof

    def begin_restore_for(self, proof):
        begin = self.receipt(self.state()['candidate'], identity=proof['identity'], source_identity=proof['source_identity'],
            workflow_id=proof['workflow_id'], workflow_committed=True, purpose='candidate_restore',
            expected_restore_epoch=proof['restore_epoch'])
        self.e.begin_restore(begin, self.now)
        return begin

    def epoch_evidence(self, *, critical=True):
        ev = self.promotion_evidence()
        candidate = self.state()['candidate']
        epoch = dict(restore_epoch=candidate['restore_epoch'], restore_hash=candidate['restore_hash'], observed_at=self.now)
        ev['candidate_health'].update(epoch)
        if critical:
            for receipt in ev['critical_receipts']:
                receipt.update(epoch)
        else:
            for receipt in ev['critical_receipts']:
                for field in ('restore_epoch','restore_hash','observed_at'):receipt.pop(field,None)
        return ev

    def test_local_installation_claim_is_one_committed_create_without_false_outage(self):
        self.observe(self.evidence(True))
        self.assertIsNone(self.state()['incident_id'])
        self.assertEqual(self.plan(self.installation_evidence()).reason, 'HEALTHY')
        authorization = self.installation_authorization()
        intent = self.e.claim_installation_trial(authorization, self.installation_evidence(), self.now)
        self.assertEqual(intent.kind, 'CREATE')
        self.assertEqual(intent.payload['purpose'], 'installation_restore_drill')
        self.assertEqual(intent.payload['installation_authorization_id'], authorization['id'])
        self.assertEqual(self.state()['phase'], 'CREATE_UNKNOWN')
        self.assertEqual(self.state()['pending_create'], intent.id)
        self.assertEqual(self.state()['health']['quality'], 'ok')
        self.assertEqual(len(self.ledger_rows()), 1)
        with sqlite3.connect(self.path) as db:
            self.assertEqual(db.execute('SELECT intent_id FROM installation_trials').fetchone()[0], intent.id)
            self.assertIsNone(db.execute('SELECT receipt FROM actions WHERE id=?', (intent.id,)).fetchone()[0])
        reconcile = self.plan(self.installation_evidence())
        self.assertEqual(reconcile.kind, 'RECONCILE_CREATE')
        self.assertTrue(reconcile.payload['read_only'])

    def test_installation_authorization_cannot_cross_owner_bundle_or_expiry(self):
        auth = self.installation_authorization()
        invalid = [dict(verified=False), dict(owner_id='2'), dict(service_id='20'), dict(project_id='other'),
            dict(active_identity={**auth['active_identity'], 'provider_id': 'foreign'}),
            dict(active_identity={**auth['active_identity'], 'generation': True}),
            dict(active_config_hash='different-bundle'), dict(purpose='purchase'), dict(max_one_candidate=False),
            dict(expires_at=self.now), dict(issued_at=self.now + 1), dict(id='../../bad')]
        initial = self.state()
        for change in invalid:
            with self.subTest(change=change), self.assertRaises(core.RecoveryError):
                self.e.claim_installation_trial({**auth, **change}, self.installation_evidence(), self.now)
        self.assertEqual(self.state(), initial)
        self.assertFalse(self.ledger_rows())

    def test_installation_purchase_permit_never_outlives_local_authorization(self):
        auth = {**self.installation_authorization(), 'expires_at': self.now + 2}
        intent = self.e.claim_installation_trial(auth, self.installation_evidence(), self.now)
        self.assertEqual(intent.expires_at, self.now + 1)

    def test_installation_requires_actual_guard_and_all_nonrestore_preflight(self):
        required = ('owner_bound', 'independent_control', 'provider_access', 'budget_authorized',
                    'registry_complete', 'fencing_enforced', 'safe_boot_guard_ready')
        for name in required:
            ev = self.installation_evidence(); ev['preflight']['checks'][name] = False
            with self.subTest(check=name), self.assertRaises(core.RecoveryError):
                self.e.claim_installation_trial(self.installation_authorization(), ev, self.now)
        for change in [dict(safe_boot_guard_ready=False), dict(fail_closed=False), dict(guard_hash='unhashed'),
                       dict(received_at=self.now - 31), dict(active_identity={**core.identity(self.state()['active']), 'provider_id': 'foreign'})]:
            ev = self.installation_evidence(); ev['safe_boot_guard'].update(change)
            with self.subTest(guard=change), self.assertRaises(core.RecoveryError):
                self.e.claim_installation_trial(self.installation_authorization(), ev, self.now)
        self.assertFalse(self.ledger_rows())

    def test_installation_authorization_is_consumed_after_crash_restart_and_provider_failure(self):
        auth = self.installation_authorization()
        intent = self.e.claim_installation_trial(auth, self.installation_evidence(), self.now)
        self.e = core.Engine(self.cfg, core.Journal(self.path))
        with self.assertRaises(core.RecoveryError):
            self.e.claim_installation_trial(auth, self.installation_evidence(), self.now)
        self.assertEqual(self.plan().kind, 'RECONCILE_CREATE')
        self.e.record_effect(intent.id, self.effect(intent, 'failed', definitive_rejection=True), self.now)
        self.observe(self.evidence(True))
        self.assertIsNone(self.state()['incident_id'])
        with self.assertRaises(core.RecoveryError):
            self.e.claim_installation_trial(auth, self.installation_evidence(), self.now)
        self.assertEqual(len(self.ledger_rows()), 1)

    def test_installation_shared_ledger_survives_disabled_scheduler_restart(self):
        auth = self.installation_authorization()
        intent = self.e.claim_installation_trial(auth, self.installation_evidence(), self.now)
        disabled = core.Engine(dataclasses.replace(self.cfg, enabled=False), core.Journal(self.path))
        self.assertEqual(disabled.plan(disabled.snapshot(), self.installation_evidence(), self.now).reason, 'DISABLED')
        self.assertEqual(len(self.ledger_rows()), 1)
        self.e = core.Engine(self.cfg, core.Journal(self.path))
        self.assertEqual(self.plan().kind, 'RECONCILE_CREATE')
        self.assertEqual(self.state()['pending_create'], intent.id)
        with self.assertRaises(core.RecoveryError):
            self.e.claim_installation_trial({**auth, 'id': 'different-id'}, self.installation_evidence(), self.now)

    def test_installation_never_resets_existing_incident_or_normal_runtime_restore_gate(self):
        self.advance(180)
        incident = self.state()['incident_id']
        with self.assertRaises(core.RecoveryError):
            self.e.claim_installation_trial(self.installation_authorization(), self.installation_evidence(), self.now)
        self.assertEqual(self.state()['incident_id'], incident)
        ev = self.installation_evidence(); ev['health'] = self.health(False)
        self.assertEqual(self.plan(ev).reason, 'PREFLIGHT_INCOMPLETE')
        self.assertFalse(self.ledger_rows())

    def test_installation_cost_attempt_vm_and_quote_limits_match_runtime(self):
        for case in ('attempts', 'rolling_cost', 'month_cost', 'max_vms', 'monthly_quote', 'trial_quote'):
            with self.subTest(case=case):
                path = Path(self.tmp.name) / (case + '.sqlite3')
                journal = core.Journal(path)
                journal.initialize(self.state()['active'], self.now)
                engine = core.Engine(self.cfg, journal)
                ev = self.installation_evidence()
                with journal.transaction() as db:
                    if case == 'attempts':
                        for n in range(5):
                            db.execute('INSERT INTO attempts VALUES(?,?,?,?,?,?)', (str(n), 'other', self.now, 10_000, 'DE', 'fsn1'))
                    elif case in ('rolling_cost', 'month_cost'):
                        db.execute('INSERT INTO attempts VALUES(?,?,?,?,?,?)',
                            ('spent', 'other', self.now if case == 'rolling_cost' else self.now - 90000,
                             1_000_000 if case == 'rolling_cost' else 3_000_000, 'DE', 'fsn1'))
                if case == 'max_vms': ev['inventory']['managed_vm_count'] = 2
                if case == 'monthly_quote': ev['options'][0]['monthly_gross_micro'] = 13_000_000
                if case == 'trial_quote': ev['options'][0]['trial_max_gross_micro'] = 1_000_001
                with self.assertRaises(core.RecoveryError):
                    engine.claim_installation_trial(self.installation_authorization(), ev, self.now)
                self.assertEqual(engine.snapshot()['phase'], 'HEALTHY')
                with sqlite3.connect(path) as db:
                    self.assertEqual(db.execute('SELECT COUNT(*) FROM installation_trials').fetchone()[0], 0)
                    self.assertEqual(db.execute('SELECT COUNT(*) FROM actions').fetchone()[0], 0)

    def test_installation_concurrent_claim_commits_at_most_one_candidate(self):
        auth, ev = self.installation_authorization(), self.installation_evidence()
        def claim(_):
            try:
                return self.e.claim_installation_trial(auth, ev, self.now).id
            except core.RecoveryError:
                return None
        with ThreadPoolExecutor(max_workers=8) as pool:
            claimed = list(pool.map(claim, range(8)))
        self.assertEqual(sum(value is not None for value in claimed), 1)
        self.assertEqual(len(self.ledger_rows()), 1)

    def test_installation_failed_candidate_cleanup_keeps_old_source_and_cost_ledger(self):
        intent = self.e.claim_installation_trial(self.installation_authorization(), self.installation_evidence(), self.now)
        old = copy.deepcopy(self.state()['active'])
        candidate = dict(provider='hetzner', project_id='p', provider_id='trial', generation=2)
        self.e.record_effect(intent.id, self.effect(intent, 'created', identity=candidate, create_intent_id=intent.id), self.now)
        ev = self.evidence(True, candidate_rejected=self.receipt(self.state()['candidate'], rejected=True))
        self.assertEqual(self.plan(ev).kind, 'HOLD')
        ev['cleanup'] = self.receipt(self.state()['candidate'], identity=candidate, no_actual_writes=True, never_promoted=True)
        cleanup = self.plan(ev)
        self.assertEqual(cleanup.kind, 'DELETE_CANDIDATE')
        self.assertEqual(cleanup.payload['identity'], candidate)
        self.e.commit_intent(cleanup, ev, self.now)
        self.e.record_effect(cleanup.id, self.effect(cleanup, 'deleted', identity=candidate,
            absence_verified=True, dependent_resources_absent=True), self.now)
        self.assertEqual(self.state()['active'], old)
        self.assertIsNone(self.state()['candidate'])
        self.assertIsNone(self.state()['retiring'])
        self.assertEqual(len(self.ledger_rows()), 1)

    def test_restore_epoch_invalidates_full_candidate_timer_and_old_critical_evidence(self):
        self.candidate()
        self.restore_candidate();self.accept_restore()
        self.assertEqual(self.plan(self.promotion_evidence()).kind, 'PROMOTE')
        before, ledger = copy.deepcopy(self.state()), self.ledger_rows()
        old_evidence=self.promotion_evidence()
        proof = self.restore_receipt(restore_epoch=2,restore_hash='e'*64)
        self.begin_restore_for(proof)
        self.e.record_restore_epoch(proof, self.now)
        state = self.state()
        self.assertEqual(state['candidate_health'], {})
        self.assertEqual(state['active'], before['active'])
        self.assertEqual(state['candidate']['created_at'], before['candidate']['created_at'])
        self.assertEqual(core.identity(state['candidate']), core.identity(before['candidate']))
        self.assertEqual(self.ledger_rows(), ledger)
        self.observe(old_evidence)
        self.assertEqual(self.state()['candidate_health']['quality'], 'unknown')
        for _ in range(3):
            self.now += 30; self.observe(self.epoch_evidence(critical=False))
        self.assertEqual(self.plan(self.epoch_evidence(critical=False)).kind, 'HOLD')
        self.assertEqual(self.plan(self.epoch_evidence()).kind, 'HOLD')
        for _ in range(37):
            self.now+=30;self.observe(self.epoch_evidence())
        self.assertEqual(self.plan(self.epoch_evidence()).kind, 'PROMOTE')

    def test_restore_epoch_rejects_cross_source_candidate_uncommitted_and_stale_receipts(self):
        self.candidate()
        proof = self.restore_receipt()
        self.begin_restore_for(proof)
        invalid = [dict(verified=False), dict(workflow_committed=False), dict(snapshot_id='snapshot-123'),
            dict(restore_hash='bad'), dict(restore_epoch=True), dict(restore_epoch=2**53),
            dict(snapshot_id='00123'), dict(workflow_id='not-uuid'),
            dict(received_at=self.now - 31), dict(config_hash='wrong'), dict(incident_id='other'),
            dict(identity={**proof['identity'], 'provider_id': 'foreign'}),
            dict(source_identity={**proof['source_identity'], 'provider_id': 'foreign'}),
            dict(source_identity={**proof['source_identity'], 'generation': True})]
        before = self.state()
        for change in invalid:
            with self.subTest(change=change), self.assertRaises(core.RecoveryError):
                self.e.record_restore_epoch({**proof, **change}, self.now)
        self.assertEqual(self.state(), before)

    def test_restore_epoch_receipt_is_immutable_and_restart_idempotent(self):
        self.candidate()
        proof = self.restore_receipt()
        self.begin_restore_for(proof)
        self.e.record_restore_epoch(proof, self.now)
        before = self.state()
        self.e = core.Engine(self.cfg, core.Journal(self.path))
        self.e.record_restore_epoch(proof, self.now)
        self.assertEqual(self.state(), before)
        with self.assertRaises(core.RecoveryError):
            self.e.record_restore_epoch({**proof, 'restore_hash': 'e' * 64}, self.now)
        with self.assertRaises(core.RecoveryError):
            self.e.record_restore_epoch({**proof, 'workflow_id': str(uuid.uuid4())}, self.now)
        second = {**proof, 'workflow_id': str(uuid.uuid4()), 'restore_epoch': 2, 'restore_hash': 'e' * 64}
        self.begin_restore_for(second)
        self.e.record_restore_epoch(second, self.now)
        self.assertEqual(self.state()['candidate']['restore_epoch'], 2)

    def test_restore_epoch_requires_future_observation_and_exact_hash(self):
        self.candidate()
        proof = self.restore_receipt(); self.begin_restore_for(proof)
        self.e.record_restore_epoch(proof, self.now)
        ev = self.epoch_evidence()
        for change in [dict(restore_epoch=0), dict(restore_hash='e' * 64), dict(observed_at=self.now - 1),
                       dict(received_at=self.now - 1), dict(observed_at=self.now + 6)]:
            health = {**ev['candidate_health'], **change}
            with self.subTest(change=change):
                self.assertEqual(self.e._quality(health, self.state()['candidate'], self.now), 'unknown')

    def test_restore_epoch_cannot_cross_an_unfinished_core_action(self):
        self.candidate()
        self.restore_candidate();self.accept_restore()
        proof = self.restore_receipt(restore_epoch=2,restore_hash='e'*64)
        promote = self.plan(self.promotion_evidence())
        self.e.commit_intent(promote, self.promotion_evidence(), self.now)
        with self.assertRaises(core.RecoveryError):
            self.e.record_restore_epoch(proof, self.now)
        self.assertEqual(self.state()['phase'], 'CUTOVER')

    def test_begin_restore_blocks_old_promotion_cleanup_and_expiry_before_external_rebuild(self):
        self.candidate()
        self.restore_candidate();self.accept_restore()
        planned = self.plan(self.promotion_evidence())
        self.assertEqual(planned.kind, 'PROMOTE')
        before, ledger = copy.deepcopy(self.state()), self.ledger_rows()
        begin = self.begin_restore_for(self.restore_receipt(restore_epoch=2,restore_hash='e'*64))
        self.assertEqual(self.state()['candidate_health'], {})
        self.assertEqual(self.plan(self.promotion_evidence()).reason, 'RESTORE_IN_PROGRESS')
        with self.assertRaises(core.StaleIntent):
            self.e.commit_intent(planned, self.promotion_evidence(), self.now)
        self.now += self.cfg.candidate_deadline
        ev = self.promotion_evidence()
        ev.update(candidate_rejected=self.receipt(self.state()['candidate'], rejected=True),
            cleanup=self.receipt(self.state()['candidate'], identity=core.identity(self.state()['candidate']),
                                 no_actual_writes=True, never_promoted=True))
        self.observe(ev)
        self.assertEqual(self.plan(ev).reason, 'RESTORE_IN_PROGRESS')
        self.assertEqual(self.state()['candidate_health']['quality'], 'unknown')
        self.assertEqual(self.state()['candidate']['restore_pending']['workflow_id'], begin['workflow_id'])
        self.assertEqual(self.state()['active'], before['active'])
        self.assertEqual(self.state()['candidate']['created_at'], before['candidate']['created_at'])
        self.assertEqual(self.ledger_rows(), ledger)

    def test_begin_restore_is_restart_idempotent_and_conflicts_cannot_replace_hold(self):
        self.candidate()
        proof = self.restore_receipt(); begin = self.begin_restore_for(proof)
        before = self.state()
        self.e = core.Engine(self.cfg, core.Journal(self.path))
        self.e.begin_restore(begin, self.now)
        self.assertEqual(self.state(), before)
        for change in (dict(expected_restore_epoch=2), dict(workflow_id=str(uuid.uuid4()))):
            with self.subTest(change=change), self.assertRaises(core.RecoveryError):
                self.e.begin_restore({**begin, **change}, self.now)
        self.assertEqual(self.state(), before)

    def test_restore_completion_without_prior_begin_or_actual_boot_proof_is_rejected(self):
        self.candidate()
        proof = self.restore_receipt()
        with self.assertRaises(core.RecoveryError):
            self.e.record_restore_epoch(proof, self.now)
        self.begin_restore_for(proof)
        for change in [dict(workflow_id=str(uuid.uuid4())), dict(restore_epoch=2), dict(snapshot_available=False),
                       dict(source_fenced=False), dict(boot_verified=False), dict(quarantine_verified=False),
                       dict(machine_id_sha256='wrong'), dict(boot_id_sha256='wrong'), dict(received_at=self.now - 1)]:
            with self.subTest(change=change), self.assertRaises(core.RecoveryError):
                self.e.record_restore_epoch({**proof, **change}, self.now)
        self.assertEqual(self.plan(self.promotion_evidence()).reason, 'RESTORE_IN_PROGRESS')

    def test_begin_restore_requires_exact_fresh_committed_source_and_candidate_scope(self):
        self.candidate()
        proof = self.restore_receipt()
        begin = self.receipt(self.state()['candidate'], identity=proof['identity'], source_identity=proof['source_identity'],
            workflow_id=proof['workflow_id'], workflow_committed=True, purpose='candidate_restore', expected_restore_epoch=1)
        before = self.state()
        for change in [dict(workflow_committed=False), dict(expected_restore_epoch=True), dict(purpose='delete'),
                       dict(workflow_id='not-uuid'), dict(received_at=self.now - 31), dict(config_hash='other'),
                       dict(identity={**proof['identity'], 'provider_id': 'foreign'}),
                       dict(source_identity={**proof['source_identity'], 'provider_id': 'foreign'})]:
            with self.subTest(change=change), self.assertRaises(core.RecoveryError):
                self.e.begin_restore({**begin, **change}, self.now)
        self.assertEqual(self.state(), before)


if __name__ == '__main__':
    unittest.main()
