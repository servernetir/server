"""Offline driver gates; all external hooks/provider transport are fake."""
import copy
import dataclasses
import hashlib
import importlib.util
import json
from pathlib import Path
import sqlite3
import sys
import unittest

INFRA=Path(__file__).resolve().parents[2];sys.path.insert(0,str(INFRA))
from relay_recovery import core
from relay_recovery.production_driver import ProductionDriver,ProductionHooks,OBSERVATION

SPEC=importlib.util.spec_from_file_location('production_restore_fixture',Path(__file__).with_name('test_restore_workflow.py'))
fixture=importlib.util.module_from_spec(SPEC);sys.modules[SPEC.name]=fixture;SPEC.loader.exec_module(fixture)


class Collector:
    def __init__(self,t):self.t=t;self.bad=False
    def collect(self,payload):
        f=self.t.f;s=f.state();candidate=s.get('candidate');target=candidate or s['active']
        r=f.scoped(target,observed_at=f.now)
        if 'restore_epoch'in target:r.update(restore_epoch=target['restore_epoch'],restore_hash=target['restore_hash'])
        health={**r,'agent_id':'ir-agent','direct_control':True,'host_healthy':True,'internet_healthy':True,
                'tunnel_ok':not self.bad,'probe_ok':not self.bad}
        evidence=f.evidence()
        if candidate:evidence['candidate_health']=health
        else:evidence['health']=health
        evidence['critical_receipts']=[{**r,'consumer':c,'ok':not self.bad}for c in f.policy.critical_consumers]
        evidence['backup']={**r,'checkpoint':'cp-2','durable':True,'restore_verified':True,'covers_promotion_checkpoint':'cp-2'}
        evidence['fencing']={**r,'old_generation':(s.get('retiring')or s['active'])['generation'],'verified_fenced':True}
        evidence['ttl']={**r,'known':True,'expires_at':f.now}
        evidence['active_refs']={**r,'complete':True,'count':0}
        if self.t.cleanup and candidate:
            evidence['cleanup']={**r,'identity':core.identity(candidate),'no_actual_writes':True,'never_promoted':True}
        return {'schema':'servernet.relay.evidence.v1','evidence':evidence}


class DriverTests(unittest.TestCase):
    def setUp(self):
        self.helper=fixture.RestoreTests();self.helper.setUp();self.addCleanup(self.helper.doCleanups)
        self.f=self.helper.f;self.world=self.helper.world;self.cleanup=False
        self.catalog=dict(schema='servernet.relay.dependency.registry.v1',owner_id='1',service_id='7001',project_id='p',revision=1,
            consumers=list(self.f.policy.critical_consumers),resources=[{'id':'all-registered-dependencies','kind':'test-only-fixture'}])
        registry=self.f.root/'dependencies.json';registry.write_text(core.canonical(self.catalog));registry.chmod(0o600)
        self.config=copy.deepcopy(self.helper.config);self.config['production_driver']={'enabled':True,
            'registry':{'path':str(registry),'sha256':hashlib.sha256(registry.read_bytes()).hexdigest()}}
        self.collector=Collector(self);self.trial_complete=True;self.applies=[];self.reads=[];self.cutover_applied=False
        self.cutover_delay=0;self.cutover_make_bad=False;self.continuations=True;self.cutover_reconcile_delay=0
        self.crm_applied=False;self.crm_complete=True;self.crm_delay=0;self.crm_registry_drift=False;self.crm_proof_panel=True
        self.crm_permit=None;self.crm_context=None
        self.hooks=ProductionHooks(self.registry,self.trial_apply,self.trial_reconcile,self.cutover_apply,
            self.cutover_reconcile,self.cutover_verify,self.retire_verify,self.crm_apply,self.crm_reconcile)
        self.driver=ProductionDriver(self.config,collector=self.collector,provider=self.f.effects,bootstrap=self.helper.bootstrap,
            restore=self.helper.wf,hooks=self.hooks,clock=lambda:self.f.now)

    def observation(self,kind,context,**extra):
        return dict(schema=OBSERVATION,operation=kind,verified=True,read_only=True,received_at=self.f.now,observed_at=self.f.now,
                    context_hash=core.digest(context),**extra)

    def resources(self):return[{'id':r['id'],'verified':True,'verifier':'offline-test','proof_sha256':'a'*64}for r in self.catalog['resources']]
    def registry(self,context):
        return self.observation('registry',context,resource_proofs=self.resources(),discovery_complete=True,headless_control_verified=True,
            continuation_kinds=['trial_stage','external_cutover','crm_promotion']if self.continuations else['trial_stage'])
    def trial_apply(self,p,c,authorize):
        self.assertTrue(authorize(p));self.applies.append(('trial',p['intent_id']));return {'state':'unknown'}
    def trial_reconcile(self,p,c):
        self.reads.append('trial');return self.observation('stage_trial',c,intent_id=p['intent_id'],
            state='complete'if self.trial_complete else'pending',isolation_verified=True,network_verified=True,
            ct_egress_verified=True,live_dependencies_unchanged=True)
    def cutover_apply(self,p,c,authorize):
        self.f.now+=self.cutover_delay
        if self.cutover_make_bad:self.collector.bad=True
        if authorize(p):self.applies.append(('cutover',p['intent_id']));self.cutover_applied=True
        return {'state':'unknown'}
    def cutover_reconcile(self,p,c):
        self.f.now+=self.cutover_reconcile_delay
        self.reads.append('cutover');return self.observation('cutover_dispatch',c,intent_id=p['intent_id'],
            state='complete'if self.cutover_applied else'pending')
    def crm_apply(self,p,c,authorize):
        self.crm_permit=copy.deepcopy(p);self.crm_context=copy.deepcopy(c);self.f.now+=self.crm_delay
        if self.crm_registry_drift:
            path=Path(self.config['production_driver']['registry']['path']);path.write_text('{}')
        if authorize(p):self.applies.append(('crm',p['intent_id']));self.crm_applied=True
        return {'state':'unknown'}
    def crm_reconcile(self,p,c):
        self.reads.append('crm');complete=self.crm_applied and self.crm_complete
        return self.observation('crm_promotion',c,intent_id=p['intent_id'],
            state='complete'if complete else'pending',panel_committed=complete and self.crm_proof_panel)
    def cutover_verify(self,c):
        receipts=self.collector.collect({})['evidence']['critical_receipts']
        return self.observation('cutover',c,parent_intent_id=c['parent_intent_id'],resource_proofs=self.resources(),
            all_registered_resources_verified=self.cutover_applied,no_old_references=True,panel_committed=self.crm_applied,
            global_cutover_complete=True,critical_receipts=receipts)
    def retire_verify(self,c):
        return self.observation('retirement',c,resource_proofs=self.resources(),no_old_references=True,durable_backup_coverage=True)

    def tick(self,seconds=1):self.f.now+=seconds;return self.driver.tick()
    def restored(self):
        self.helper.until('POST_RESTORE_VALIDATION');self.helper.tick()
        for _ in range(40):self.helper.tick(30)
        self.assertEqual('PRE_CUTOVER_READY',self.helper.wf.collect()['phase'])
    def promote_claim(self,cutover=True):
        self.restored()
        original=self.driver._cutover
        if not cutover:self.driver._cutover=lambda e,s,v:self.driver._status('SIMULATED_STOP_AFTER_COMMIT',s)
        for _ in range(5):
            result=self.tick(30)
            if self.f.state()['phase']=='CUTOVER'or self.f.state()['active']['provider_id']=='51':break
        self.driver._cutover=original
        state=self.f.state()
        self.assertEqual('HEALTHY'if state['active']['provider_id']=='51'else'CUTOVER',state['phase'])
        return result

    def test_default_off_and_missing_real_hooks_do_not_call_network_or_fence(self):
        self.config['production_driver']['enabled']=False
        before=len(self.world.calls)
        result=self.driver.tick();self.assertEqual('DRIVER_DISABLED',result['reason']);self.assertFalse(result['effect_armed'])
        self.assertEqual(before,len(self.world.calls))
        self.config['production_driver']['enabled']=True;self.driver.hooks=None
        result=self.driver.tick();self.assertIn('HOLD',result['reason']);self.assertFalse(result['effect_armed'])
        self.assertEqual(before,len(self.world.calls));self.assertEqual([],self.helper.lifecycle_writes)

    def test_missing_crm_continuation_capability_blocks_before_trial_and_source_fence(self):
        self.continuations=False
        self.tick();self.assertEqual([],self.applies);self.assertEqual([],self.helper.lifecycle_writes)
        self.assertEqual('running',self.world.base.servers['40']['status'])

    def test_trial_write_is_journaled_once_then_only_readonly_reconciles(self):
        self.trial_complete=False
        self.tick()
        for _ in range(3):self.tick()
        self.assertEqual(['trial'],[a[0]for a in self.applies]);self.assertEqual(['trial']*3,self.reads)
        self.assertEqual([],self.helper.lifecycle_writes)
        row=self.driver.store.step(self.f.effects.store.read_resource('51').labels['sn-intent'],'TRIAL_STAGE')
        self.assertEqual('DISPATCHED',row['phase'])

    def test_unreachable_trial_deadline_cleanup_waits_for_server_and_ip_absence(self):
        self.trial_complete=False;self.tick();self.cleanup=True
        self.world.base.ip_delete_status=500
        self.tick(5001)
        self.assertNotIn('51',self.world.base.servers);self.assertIsNotNone(self.f.state()['candidate'])
        before=len(self.world.calls);self.tick()
        self.assertTrue(all(m=='GET'for m,_,_ in self.world.calls[before:]))
        self.world.base.ip_delete_status=204;self.tick()
        self.assertIsNone(self.f.state()['candidate']);self.assertNotIn('71',self.world.base.ips)
        self.assertEqual(1,sum(m=='DELETE'and p=='/servers/51'for m,p,_ in self.world.calls))
        self.tick()
        self.assertEqual(1,len(self.applies));self.assertEqual([],self.helper.lifecycle_writes)

    def test_external_then_crm_complete_once_within_original_claim_and_durable_readbacks(self):
        self.promote_claim(cutover=False);parent=self.f.state()['pending_action']
        result=self.tick()
        self.assertEqual('PROMOTION_VERIFIED',result['reason'])
        self.assertEqual(['cutover','crm'],[a[0]for a in self.applies])
        for kind in ('EXTERNAL_CUTOVER','CRM_PROMOTION'):
            step=self.driver.store.step(parent,kind);self.assertEqual('VERIFIED',step['phase'])
        self.tick(31)
        self.assertEqual('51',self.f.state()['active']['provider_id']);self.assertEqual(2,len(self.applies))
        with self.driver.store.transaction()as db:
            self.assertEqual(1,db.execute('SELECT COUNT(*) FROM cutover_acceptance WHERE parent=?',(parent,)).fetchone()[0])

    def test_missing_crm_hooks_block_before_any_trial_or_source_fencing(self):
        before=len(self.world.calls)
        for name in ('crm_apply','crm_reconcile'):
            self.driver.hooks=dataclasses.replace(self.hooks,**{name:None})
            result=self.tick()
            self.assertIn('HOLD',result['reason']);self.assertFalse(result['effect_armed'])
        self.assertEqual([],self.applies);self.assertEqual([],self.helper.lifecycle_writes)
        self.assertEqual(before,len(self.world.calls))

    def test_unknown_crm_outcome_only_reconciles_and_can_confirm_after_parent_expiry(self):
        self.crm_complete=False;self.promote_claim()
        self.assertEqual('CUTOVER',self.f.state()['phase'])
        self.assertEqual(['cutover','crm'],[a[0]for a in self.applies])
        for _ in range(2):self.tick()
        self.assertEqual(['cutover','crm'],[a[0]for a in self.applies])
        self.crm_complete=True;result=self.tick(31)
        self.assertEqual('PROMOTION_VERIFIED',result['reason'])
        self.assertEqual('51',self.f.state()['active']['provider_id'])
        self.assertEqual(['cutover','crm'],[a[0]for a in self.applies])

    def test_expired_parent_between_external_and_crm_never_mints_crm_write(self):
        self.cutover_reconcile_delay=31;self.promote_claim()
        parent=self.f.state()['pending_action']
        self.assertEqual(['cutover'],[a[0]for a in self.applies])
        self.assertIsNone(self.driver.store.step(parent,'CRM_PROMOTION'))
        self.tick();self.assertFalse(self.crm_applied)
        self.assertIsNone(self.driver.store.step(parent,'CRM_PROMOTION'))

    def test_crm_authorizer_rechecks_fresh_parent_and_registry_at_actual_write(self):
        self.crm_delay=31;self.promote_claim()
        self.assertEqual(['cutover'],[a[0]for a in self.applies]);self.assertFalse(self.crm_applied)

    def test_crm_registry_drift_after_dispatch_rejects_the_actual_write(self):
        self.crm_registry_drift=True;self.promote_claim()
        self.assertEqual(['cutover'],[a[0]for a in self.applies]);self.assertFalse(self.crm_applied)

    def test_crm_context_binds_original_gate_checkpoint_and_rejects_forged_permits(self):
        self.crm_complete=False;self.promote_claim();permit=self.crm_permit
        self.assertIsNotNone(permit);self.assertTrue(self.driver.authorize_hook(permit))
        with sqlite3.connect(self.f.root/'controller.sqlite3')as db:
            action=json.loads(db.execute('SELECT body FROM actions WHERE id=?',(permit['parent_intent_id'],)).fetchone()[0])
        self.assertEqual(action['payload']['gate_hash'],self.crm_context['gate_hash'])
        self.assertEqual(action['payload']['checkpoint'],self.crm_context['checkpoint'])
        self.assertLessEqual(permit['expires_at'],action['expires_at'])
        for change in ({'parent_intent_id':'f'*40},{'kind':'UNKNOWN'}, {'schema':'unknown'},
                       {'expires_at':permit['expires_at']+1},{'created_at':True},{'context_hash':'0'*64}):
            with self.subTest(change=change):
                self.assertFalse(self.driver.authorize_hook({**permit,**change}))
        self.f.now=action['expires_at']
        self.assertFalse(self.driver.authorize_hook(permit))

    def test_even_durable_forged_crm_context_cannot_change_original_gate_or_scope(self):
        self.crm_complete=False;self.promote_claim();original=copy.deepcopy(self.crm_context)
        for field,value in (('gate_hash','0'*64),('checkpoint','forged'),('owner_id','other'),
                            ('registry_hash','0'*64),('restore_hash','0'*64)):
            context={**original,field:value};permit={**self.crm_permit,'context_hash':core.digest(context)}
            with self.driver.store.transaction()as db:
                db.execute('UPDATE steps SET body=? WHERE id=?',
                    (core.canonical({'context':context,'permit':permit}),permit['intent_id']))
            with self.subTest(field=field):self.assertFalse(self.driver.authorize_hook(permit))

    def test_crm_cannot_dispatch_before_external_step_is_verified(self):
        self.promote_claim(cutover=False);state=self.f.state();parent=state['pending_action']
        self.assertIsNone(self.driver._hook_step(parent,'CRM_PROMOTION',self.driver._crm_context(state,parent)))
        self.assertIsNone(self.driver.store.step(parent,'CRM_PROMOTION'))
        self.assertFalse(self.crm_applied);self.assertEqual([],self.applies)

    def test_external_success_and_panel_flag_cannot_replace_crm_readback_proof(self):
        self.crm_proof_panel=False;self.promote_claim()
        self.assertTrue(self.cutover_applied);self.assertTrue(self.crm_applied)
        self.assertEqual('CUTOVER',self.f.state()['phase'])
        parent=self.f.state()['pending_action']
        self.assertEqual('DISPATCHED',self.driver.store.step(parent,'CRM_PROMOTION')['phase'])
        with self.driver.store.transaction()as db:
            self.assertEqual(0,db.execute('SELECT COUNT(*) FROM cutover_acceptance').fetchone()[0])

    def test_unknown_hook_kind_cannot_be_committed_or_authorized(self):
        self.driver._catalog();state=self.f.state()
        with self.assertRaisesRegex(Exception,'unknown_hook_kind'):
            self.driver._hook_step('f'*40,'UNKNOWN',self.driver._context(state,'f'*40))
        self.assertFalse(self.driver.authorize_hook({'kind':'UNKNOWN'}))

    def test_expired_parent_before_first_dispatch_never_mints_external_write(self):
        self.promote_claim(cutover=False);parent=self.f.state()['pending_action']
        self.tick(31)
        self.assertEqual([],self.applies);self.assertIsNone(self.driver.store.step(parent,'EXTERNAL_CUTOVER'))
        self.assertEqual('CUTOVER',self.f.state()['phase'])

    def test_with_live_parent_a_revoked_restore_ready_phase_blocks_first_dispatch(self):
        self.promote_claim(cutover=False);parent=self.f.state()['pending_action']
        w=self.helper.wf.store.get();w['phase']='POST_RESTORE_VALIDATION';self.helper.wf.store.save(w)
        self.tick()
        self.assertEqual([],self.applies);self.assertIsNone(self.driver.store.step(parent,'EXTERNAL_CUTOVER'))

    def test_delayed_actual_authorizer_or_new_failure_rejects_external_mutation(self):
        self.cutover_delay=31;self.promote_claim()
        self.assertEqual([],self.applies);self.assertFalse(self.cutover_applied)

    def test_fresh_critical_failure_inside_hook_rejects_first_cutover_mutation(self):
        self.cutover_make_bad=True;self.promote_claim()
        self.assertEqual([],self.applies);self.assertFalse(self.cutover_applied)

    def test_stale_ready_is_refreshed_via_actual_workflow_before_planning(self):
        self.restored();old=self.helper.wf.collect()['restore_ready']['received_at']
        self.tick(91)
        fresh=self.helper.wf.collect()['restore_ready']
        self.assertIsNotNone(fresh);self.assertGreater(fresh['received_at'],old)
        self.assertEqual(fresh['observed_at'],fresh['acceptance_last_observed_at'])

    def test_restored_disk_cannot_be_deleted_by_forged_empty_trial_cleanup(self):
        self.restored();self.cleanup=True;self.tick(5001)
        self.assertIn('51',self.world.base.servers);self.assertIsNotNone(self.f.state()['candidate'])
        self.assertFalse(any(m=='DELETE'and p=='/servers/51'for m,p,_ in self.world.calls))

    def test_retirement_requires_durable_linked_cutover_acceptance(self):
        self.promote_claim();self.tick(31)
        state=self.f.state();self.assertTrue(self.driver._retirement_acceptance(state))
        with self.driver.store.transaction()as db:db.execute('DELETE FROM cutover_acceptance')
        self.assertFalse(self.driver._retirement_acceptance(state))
        for _ in range(5):result=self.tick(30)
        self.assertEqual('DURABLE_CUTOVER_ACCEPTANCE_MISSING',result['reason'])
        self.assertIn('40',self.world.base.servers)


if __name__=='__main__':unittest.main()
