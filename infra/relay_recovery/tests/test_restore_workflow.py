"""Cold restore orchestration against real core/provider adapters and fake I/O."""
import copy
import dataclasses
import fcntl
import hashlib
import importlib.util
import json
from pathlib import Path
import sqlite3
import sys
from types import SimpleNamespace
import unittest

INFRA=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(INFRA))
from relay_recovery import core
from relay_recovery.restore_workflow import RestoreWorkflow,READINESS
from relay_recovery.snapshot_adapter import SnapshotAdapter

SPEC=importlib.util.spec_from_file_location('restore_snapshot_fixture',Path(__file__).with_name('test_snapshot_adapter.py'))
fixture=importlib.util.module_from_spec(SPEC);sys.modules[SPEC.name]=fixture;SPEC.loader.exec_module(fixture)


class Bootstrap:
    def __init__(self):self.origin='source';self.calls=[]
    def artifact(self,intent_id,profile):
        return SimpleNamespace(intent_id=intent_id,profile=profile,public_host_fingerprint='SHA256:'+('trial'if profile=='trial'else'source'),
            user_data_sha256=hashlib.sha256(b'#cloud-config\n{}\n').hexdigest(),known_hosts_path='/private/'+profile,
            host_key_origin='trial'if profile=='trial'else self.origin,machine_id_receipt_required=profile=='restored')
    def prepare(self,intent,source):self.calls.append('prepare');return self.artifact(intent['id'],'trial')
    def bind_candidate(self,intent_id,identity,ipv4):self.calls.append('bind');return self.artifact(intent_id,'trial')
    def profile(self,intent_id,profile='trial'):self.calls.append(profile);return self.artifact(intent_id,profile)
    def read_user_data(self,artifact):self.calls.append('read_'+artifact.profile);return '#cloud-config\n{}\n'


class Lifecycle:
    def __init__(self,test,target,authorize):
        self.test,self.target,self.authorize=test,target,authorize;self.records={};self.permits={};self.fenced=False;self.active=False

    def proof(self,fenced=False):
        return dict(ok=True,service_id='7001',provider_id=self.target['provider_id'],generation=self.target['generation'],
            config_hash=self.target['config_hash'],metadata_provider_id=self.target['provider_id'],
            machine_uuid_sha256=('c'if self.target['provider_id']=='40'else'd')*64,
            observed_at=self.test.f.now,physical_identity_verified=True,guard_installed=True,
            persistent_fence=fenced,all_units_inactive=fenced,identity_fenced=fenced,
            active_generation_verified=self.active,
            docker=dict(daemon_inactive=fenced,exact_inventory=True,no_shims=True,running_ids=[] if fenced else ['business']))

    def inspect(self):
        self.test.lifecycle_reads.append(('inspect',self.target['provider_id']))
        return dict(state='observed',proof=self.proof(self.fenced))

    def mutate(self,kind,permit):
        if self.authorize(permit)is not True:raise ValueError('not_authorized')
        self.test.lifecycle_writes.append((kind,self.target['provider_id'],permit['intent_id']))
        self.records[permit['intent_id']]=kind
        self.permits[permit['intent_id']]=copy.deepcopy(permit)
        if kind=='adopt_clone'and self.test.adoption_absent:return dict(state='unknown',intent_id=permit['intent_id'])
        if kind=='fence':
            # The core's own normal planner must already forbid promote/delete.
            state=self.test.f.state();intent=self.test.f.engine.plan(state,{},self.test.f.now)
            self.test.assertEqual(('HOLD','RESTORE_IN_PROGRESS'),(intent.kind,intent.reason))
            self.fenced=True
        elif kind=='adopt_clone':self.fenced=True
        else:self.fenced=False;self.active=True
        if kind in self.test.unknown:return dict(state='unknown',intent_id=permit['intent_id'])
        return dict(state='complete',intent_id=permit['intent_id'],proof=self.proof(self.fenced))

    def fence(self,permit):return self.mutate('fence',permit)
    def adopt_clone(self,permit):return self.mutate('adopt_clone',permit)
    def activate(self,permit):return self.mutate('activate',permit)

    def reconcile(self,intent_id):
        self.test.lifecycle_reads.append(('reconcile',self.target['provider_id']))
        kind=self.records[intent_id]
        if kind=='adopt_clone'and self.test.adoption_absent:
            proof=self.quarantine_inspect(self.permits[intent_id]['params'])['proof']
            proof['journal_adoption_absent']=True
            return dict(state='not_started',intent_id=intent_id,proof=proof)
        if kind in self.test.unknown:return dict(state='unknown',intent_id=intent_id)
        return dict(state='complete',intent_id=intent_id,proof=self.proof(self.fenced))

    def quarantine_inspect(self,params,intent_id=None):
        self.test.lifecycle_reads.append(('quarantine',self.target['provider_id']))
        old={k:params['old_'+k]for k in ('provider_id','generation','config_hash','machine_uuid_sha256')}
        p=self.proof(False)
        p.update(physical_identity_verified=False,read_only=True,quarantine_verified=True,
            guard_denies_current_machine=True,docker_socket_inactive=True,all_units_inactive=True,
            inherited_identity={**old,'service_id':'7001','fenced':True},
            bootstrap=dict(machine_id_ready=self.test.machine_ready,intent_id=self.test.wf.store.get()['create_intent_id'],scope='e'*64,
                machine_id_sha256='1'*64,previous_machine_id_sha256='2'*64,
                boot_id_sha256='3'*64,previous_boot_id_sha256='4'*64))
        return dict(state='observed',proof=p)


class RestoreTests(unittest.TestCase):
    def setUp(self):
        self.fixture=fixture.SnapshotTests();self.fixture.setUp();self.addCleanup(self.fixture.doCleanups)
        self.f,self.world=self.fixture.f,self.fixture.world
        cfg=dataclasses.replace(self.f.policy,candidate_deadline=5000,receipt_max_age=90,observation_max_gap=90,intent_ttl=30)
        self.f.policy=cfg;self.f.effects.policy=cfg;self.f.engine=core.Engine(cfg,self.f.journal)
        self.f.config['policy']=dataclasses.asdict(cfg)
        original=self.f.evidence
        def evidence():
            r=original();r['options'][0]['config_hash']='b'*64;return r
        self.f.evidence=evidence
        self.f.candidate()
        self.config=copy.deepcopy(self.f.config)
        self.config['restore_workflow']={'enabled':True,'minimum_restore_seconds':600,'max_snapshot_monthly_micro':1_000_000}
        self.bootstrap=Bootstrap();self.lifecycle_reads=[];self.lifecycle_writes=[];self.clients={}
        self.unknown=set();self.machine_ready=True;self.trial_good=True;self.ready=True;self.health_good=True;self.replayed_health=None
        self.adoption_absent=False
        def lifecycle_factory(target,artifact,authorize):
            if target['provider_id']=='51':
                self.assertEqual('source',artifact.host_key_origin)
                self.assertEqual('/private/restored',artifact.known_hosts_path)
                self.assertEqual('SHA256:source',artifact.public_host_fingerprint)
            client=Lifecycle(self,target,authorize);self.clients[target['provider_id']]=client;return client
        self.wf=RestoreWorkflow(self.config,provider=self.f.effects,bootstrap=self.bootstrap,lifecycle_factory=lifecycle_factory,
            callbacks={'trial_network':self.trial,'pre_fence_readiness':self.readiness,'post_restore_health':self.health},now=lambda:self.f.now)

    def bound(self,c):
        return {**{k:c[k]for k in ('workflow_id','identity','source_identity','config_hash','incident_id','generation')},
                'verified':True,'received_at':self.f.now,'observed_at':self.f.now}

    def trial(self,c):
        return {**self.bound(c),'network_ok':self.trial_good,'isolated_egress_ok':self.trial_good,
                'staging_isolated':True,'live_dependencies_unchanged':True}

    def readiness(self,c):
        return {**self.bound(c),**{k:self.ready for k in READINESS},'host_fingerprint':c['host_fingerprint']}

    def health(self,c):
        if self.replayed_health is not None:return copy.deepcopy(self.replayed_health)
        r={**self.bound(c),'restore_epoch':c['restore_epoch'],'restore_hash':c['restore_hash'],
           'read_only':True,'global_cutover_performed':False,'all_actual_consumers_checked':True,'ok':self.health_good}
        r['critical_receipts']=[{**r,'consumer':name,'ok':self.health_good}for name in self.f.policy.critical_consumers]
        return r

    def tick(self,seconds=1):
        self.f.now+=seconds;self.f.engine.observe({},self.f.now)
        return self.wf.advance()

    def until(self,phase,limit=35):
        for _ in range(limit):
            r=self.tick()
            if r['phase']==phase:return r
        self.fail('did not reach '+phase+': '+repr(r))

    def test_disabled_and_missing_network_callback_never_touch_source(self):
        self.config['restore_workflow']['enabled']=False
        before=len(self.world.calls)
        self.assertEqual('RESTORE_WORKFLOW_DISABLED',self.wf.advance()['reason'])
        self.assertEqual(before,len(self.world.calls))
        self.config['restore_workflow']['enabled']=True;self.wf.callbacks.pop('trial_network')
        self.tick();self.tick()
        self.assertEqual([],self.lifecycle_writes)
        self.assertEqual('running',self.world.base.servers['40']['status'])
        self.assertFalse(self.wf.collect()['cleanup_forbidden'])

    def test_bad_trial_is_terminal_source_untouched_and_core_cleanup_not_held(self):
        self.trial_good=False
        r=self.tick()
        self.assertEqual('TRIAL_REJECTED',r['phase']);self.assertFalse(r['cleanup_forbidden'])
        self.assertEqual([],self.lifecycle_writes)
        self.assertNotIn('restore_pending',self.f.state()['candidate'])
        self.assertFalse(any(m=='POST' and '/actions/'in p for m,p,_ in self.world.calls))

    def test_headless_controller_and_actual_source_guards_are_hard_prerequisites(self):
        self.ready=False
        self.until('PRE_FENCE_READINESS')
        for _ in range(3):self.tick()
        self.assertEqual([],self.lifecycle_writes)
        self.assertEqual('running',self.world.base.servers['40']['status'])
        self.assertNotIn('restore_pending',self.f.state()['candidate'])

    def test_source_private_network_is_not_silently_lost_by_disk_only_restore(self):
        self.world.base.servers['40']['private_net']=[{'network':10,'ip':'10.0.0.5'}]
        self.until('PRE_FENCE_READINESS')
        self.tick();self.tick()
        self.assertEqual([],self.lifecycle_writes)
        self.assertNotIn('restore_pending',self.f.state()['candidate'])
        self.assertEqual('running',self.world.base.servers['40']['status'])

    def test_core_restore_hold_precedes_irreversible_fence_and_all_rebuilds(self):
        self.until('FENCE_SOURCE')
        self.assertIn('restore_pending',self.f.state()['candidate'])
        self.assertEqual([],self.lifecycle_writes)
        self.tick()
        self.assertEqual('fence',self.lifecycle_writes[0][0]);self.assertTrue(self.wf.collect()['cleanup_forbidden'])
        self.assertFalse(self.wf.collect()['source_rollback_allowed'])

    def test_unknown_source_fence_only_reconciles_never_writes_another_uuid(self):
        self.unknown.add('fence')
        self.until('FENCE_SOURCE');self.tick()
        for _ in range(4):self.tick()
        self.assertEqual(1,len(self.lifecycle_writes));self.assertTrue(self.wf.collect()['pending_action'])
        self.assertTrue(all(x[0]in('inspect','reconcile')for x in self.lifecycle_reads))
        self.unknown.clear();self.tick()
        self.assertEqual('SHUTDOWN_SOURCE',self.wf.collect()['phase'])
        self.assertEqual(1,len(self.lifecycle_writes))

    def test_failed_snapshot_after_fence_holds_safe_without_old_rollback_or_repeat_buy(self):
        self.until('FINAL_SNAPSHOT')
        self.world.response_status['POST','/servers/40/actions/create_image']=503
        self.tick();count=len(self.world.calls)
        for _ in range(3):self.tick(5)
        self.assertTrue(all(m=='GET'for m,_,_ in self.world.calls[count:]))
        self.assertEqual('off',self.world.base.servers['40']['status'])
        self.assertTrue(self.wf.collect()['cleanup_forbidden']);self.assertIsNone(self.wf.collect()['restore_ready'])

    def test_snapshot_source_restart_remains_visible_safe_hold_after_later_off(self):
        self.until('FINAL_SNAPSHOT');self.tick()
        operation_id=self.wf.collect()['pending_action'];self.world.base.servers['40']['status']='running'
        result=self.tick(5)
        self.assertEqual('SOURCE_POWER_STATE_CONFLICT',result['reason'])
        self.assertTrue(result['cleanup_forbidden']);self.assertIsNone(result['restore_ready'])
        action=self.wf.store.action(operation_id);conflict=json.loads(action['result'])
        self.assertEqual('source_power_state_conflict',conflict['reason'])
        self.world.base.servers['40']['status']='off';self.world.calls.clear()
        result=self.tick(60)
        self.assertEqual('SOURCE_POWER_STATE_CONFLICT',result['reason'])
        self.assertEqual(operation_id,result['pending_action'])
        self.assertEqual(conflict,json.loads(self.wf.store.action(operation_id)['result']))
        self.assertFalse(any(m!='GET'for m,_,_ in self.world.calls))
        self.assertFalse(any(k in ('adopt_clone','activate')for k,_,_ in self.lifecycle_writes))

    def test_postclone_requires_actual_new_boot_and_uses_source_host_pin(self):
        self.machine_ready=False
        self.until('BOOTSTRAP_QUARANTINE')
        self.tick();self.tick()
        self.assertFalse(any(k=='adopt_clone'for k,_,_ in self.lifecycle_writes))
        self.assertIn('restore_pending',self.f.state()['candidate'])
        self.machine_ready=True;self.until('VERIFY_ADOPTED_CLONE')
        self.assertTrue(any(k=='adopt_clone'for k,_,_ in self.lifecycle_writes))

    def test_restored_trial_profile_fallback_is_blocked_before_rebuild(self):
        self.until('REBUILD_CANDIDATE');self.bootstrap.origin='trial'
        self.tick()
        self.assertFalse(any(m=='POST'and p.endswith('/rebuild')for m,p,_ in self.world.calls))
        self.assertEqual('off',self.world.base.servers['40']['status'])
        self.assertTrue(self.wf.collect()['cleanup_forbidden'])

    def test_completed_restore_resets_core_epoch_and_requires_new_twenty_minute_window(self):
        self.until('POST_RESTORE_VALIDATION')
        candidate=self.f.state()['candidate']
        self.assertEqual(1,candidate['restore_epoch']);self.assertNotIn('restore_pending',candidate)
        self.assertTrue(self.f.effects.store.read_resource('51').has_writes)
        self.assertNotEqual('ok',self.f.state()['candidate_health'].get('quality'))
        self.assertNotIn('healthy_since',self.f.state()['candidate_health'])
        self.tick()
        for _ in range(39):self.tick(30)
        self.assertIsNone(self.wf.collect()['restore_ready'])
        self.tick(30)
        ready=self.wf.collect()['restore_ready'];self.assertIsNotNone(ready)
        self.assertTrue(ready['pre_cutover_ready']);self.assertFalse(ready['global_cutover_complete'])
        self.assertEqual('PRE_CUTOVER_READY',self.wf.collect()['phase'])
        self.assertEqual(['fence','adopt_clone','activate'],[k for k,_,_ in self.lifecycle_writes])

    def assert_activation_source_unproven(self,change):
        self.until('ACTIVATE_CANDIDATE')
        before=copy.deepcopy(self.wf.store.get()['proofs']['fence'])
        client=self.clients['51'];activate=client.activate
        def changed(permit):
            change()
            return activate(permit)
        client.activate=changed;self.world.calls.clear()
        result=self.tick()
        self.assertFalse(any(k=='activate'for k,_,_ in self.lifecycle_writes))
        self.assertIsNone(result['restore_ready']);self.assertTrue(result['cleanup_forbidden'])
        self.assertTrue(result['pending_action'])
        self.assertEqual(before,self.wf.store.get()['proofs']['fence'])
        self.assertEqual(1,self.f.state()['candidate']['restore_epoch'])
        self.assertTrue(all(m=='GET'for m,_,_ in self.world.calls))
        self.assertEqual(2,sum(p=='/servers/40'for _,p,_ in self.world.calls))

    def test_source_restart_after_activation_attestation_blocks_actual_authorizer(self):
        self.assert_activation_source_unproven(lambda:self.world.base.servers['40'].update(status='running'))

    def test_source_owner_change_after_activation_attestation_blocks_actual_authorizer(self):
        self.assert_activation_source_unproven(lambda:self.world.base.servers['40']['labels'].update({'sn-owner':'foreign'}))

    def test_source_read_failure_after_activation_attestation_blocks_actual_authorizer(self):
        self.assert_activation_source_unproven(lambda:self.world.response_status.update({('GET','/servers/40'):503}))

    def test_slow_activation_source_check_cannot_renew_permit_or_fence_time(self):
        self.until('ACTIVATE_CANDIDATE')
        fence=copy.deepcopy(self.wf.store.get()['proofs']['fence']);reads=[]
        def latency(method,path,body):
            if method=='GET'and path=='/servers/40':
                reads.append(True)
                if len(reads)==2:self.f.now+=31
        self.world.before_request=latency;self.world.calls.clear()
        result=self.tick()
        self.assertEqual(2,len(reads))
        self.assertFalse(any(k=='activate'for k,_,_ in self.lifecycle_writes))
        self.assertTrue(result['pending_action']);self.assertIsNone(result['restore_ready'])
        stored=self.wf.store.get()
        self.assertEqual(fence,stored['proofs']['fence'])
        self.assertEqual(self.f.now-31,stored['proofs']['source_off']['observed_at'])
        action=self.wf.store.action(result['pending_action'])
        permit=json.loads(action['body'])['permit']
        self.assertLess(permit['expires_at'],self.f.now)
        self.assertEqual(self.f.now-31,permit['params']['old_fence_proof_at'])

    def test_restore_epoch_rotation_during_activation_source_get_blocks_old_permit(self):
        self.until('ACTIVATE_CANDIDATE');reads=[]
        def rotated(method,path,body):
            if method=='GET'and path=='/servers/40':
                reads.append(True)
                if len(reads)==2:
                    with self.f.journal.transaction()as db:
                        state=self.f.journal._read(db)
                        state['candidate'].update(restore_epoch=2,restore_hash='f'*64)
                        self.f.journal._write(db,state)
        self.world.before_request=rotated
        result=self.tick()
        self.assertEqual(2,len(reads))
        self.assertFalse(any(k=='activate'for k,_,_ in self.lifecycle_writes))
        self.assertTrue(result['pending_action']);self.assertIsNone(result['restore_ready'])
        self.assertEqual(1,self.wf.store.get()['restore_epoch'])

    def test_old_or_replayed_green_never_accumulates_postrestore_acceptance(self):
        self.until('POST_RESTORE_VALIDATION');self.tick()
        self.replayed_health=copy.deepcopy(self.wf.store.get()['latest_health'])
        for _ in range(42):self.tick(30)
        self.assertIsNone(self.wf.collect()['restore_ready'])
        self.assertNotEqual('PRE_CUTOVER_READY',self.wf.collect()['phase'])
        self.replayed_health=None;self.tick();self.health_good=False;self.tick()
        self.assertIsNone(self.wf.store.get()['healthy_since'])

    def test_collect_is_strictly_local_and_does_not_refresh_old_success(self):
        self.until('POST_RESTORE_VALIDATION');self.tick()
        for _ in range(40):self.tick(30)
        self.assertIsNotNone(self.wf.collect()['restore_ready'])
        before=(len(self.world.calls),len(self.lifecycle_reads),len(self.lifecycle_writes),len(self.bootstrap.calls))
        self.wf.callbacks={k:lambda _:self.fail('callback from collect')for k in self.wf.callbacks}
        for _ in range(3):self.wf.collect()
        self.assertEqual(before,(len(self.world.calls),len(self.lifecycle_reads),len(self.lifecycle_writes),len(self.bootstrap.calls)))
        self.f.now+=91;self.assertIsNone(self.wf.collect()['restore_ready'])

    def test_worker_lock_serializes_with_normal_scheduler(self):
        path=self.f.root/'worker.lock'
        with path.open('wb')as lock:
            path.chmod(0o600);fcntl.flock(lock.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
            self.assertEqual('WORKER_BUSY',self.wf.advance()['reason'])
            self.assertEqual('NOT_STARTED',self.wf.collect()['phase'])
            # An inherited descriptor can be reused in the SAME lock owner.
            self.wf.advance(worker_lock_fd=lock.fileno())
            self.assertNotEqual('NOT_STARTED',self.wf.collect()['phase'])

    def test_core_epoch_commit_crash_reconciles_same_receipt_once(self):
        self.until('RECORD_RESTORE_EPOCH')
        original=self.wf.store.save;crashed=[]
        def save(w):
            if w['phase']=='ACTIVATE_CANDIDATE'and not crashed:
                crashed.append(True);raise OSError('simulated_crash_after_core_commit')
            return original(w)
        self.wf.store.save=save
        self.tick();self.assertEqual(1,self.f.state()['candidate']['restore_epoch'])
        self.wf.store.save=original;self.tick()
        self.assertEqual('ACTIVATE_CANDIDATE',self.wf.collect()['phase'])
        self.assertEqual(1,self.f.state()['candidate']['restore_epoch'])
        with sqlite3.connect(self.f.root/'controller.sqlite3')as db:
            self.assertEqual(1,db.execute('SELECT COUNT(*) FROM restore_epochs').fetchone()[0])

    def test_begin_restore_commit_crash_keeps_exact_receipt_and_never_fences_early(self):
        self.until('BEGIN_RESTORE')
        original=self.wf.store.save;crashed=[]
        def save(w):
            if w['phase']=='FENCE_SOURCE'and not crashed:
                crashed.append(True);raise OSError('crash_after_core_begin')
            return original(w)
        self.wf.store.save=save;self.tick()
        pending=self.f.state()['candidate']['restore_pending']
        self.assertEqual([],self.lifecycle_writes)
        self.wf.store.save=original;self.tick(31)
        self.assertEqual('FENCE_SOURCE',self.wf.collect()['phase'])
        self.assertEqual(pending,self.f.state()['candidate']['restore_pending'])
        with sqlite3.connect(self.f.root/'controller.sqlite3')as db:
            self.assertEqual(1,db.execute('SELECT COUNT(*) FROM restore_begins').fetchone()[0])
        self.assertEqual([],self.lifecycle_writes)

    def test_expired_adoption_with_proven_absence_renews_same_uuid_append_only(self):
        self.adoption_absent=True
        self.until('ADOPT_CLONE');self.tick()
        first=self.wf.collect()['pending_action'];row=self.wf.store.action(first);original=row['body']
        self.tick(31)
        self.assertEqual('RENEWABLE',self.wf.store.action(first)['status'])
        self.assertEqual(1,len([v for v in self.lifecycle_writes if v[0]=='adopt_clone']))
        self.adoption_absent=False;self.tick()
        self.assertEqual('VERIFY_ADOPTED_CLONE',self.wf.collect()['phase'])
        writes=[v for v in self.lifecycle_writes if v[0]=='adopt_clone']
        self.assertEqual([first,first],[v[2]for v in writes])
        self.assertEqual(original,self.wf.store.action(first)['body'])
        with self.wf.store.transaction()as db:
            self.assertEqual(2,db.execute('SELECT COUNT(*) FROM authorizations WHERE action_id=?',(first,)).fetchone()[0])


if __name__=='__main__':unittest.main()
