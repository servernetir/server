"""Offline pinned lifecycle SSH tests. All transport/provider facts are fake."""
import base64
import copy
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
import uuid

INFRA = Path(__file__).resolve().parents[2]
if str(INFRA) not in sys.path: sys.path.insert(0, str(INFRA))
from relay_recovery import lifecycle_client as lc, lifecycle_side as side


class FakeSSH:
    def __init__(self, fixture):
        self.f = fixture; self.calls = []; self.remote = {}; self.mode = None; self.change = None
        self.machine = 'a'*64
        self.current_fenced=False; self.retired=False; self.adopted_from=None
        self.change_on_mutation_only=False

    def __call__(self, argv, body, output, maximum, timeout):
        request = json.loads(body)
        side.validate_request(request, self.f.key, self.f.now)
        self.calls.append((argv, request, maximum, timeout))
        op = request['op']
        if self.mode == 'quarantine_only' and op == 'reconcile':
            raise OSError('unadopted clone still has inherited old identity')
        if op in side.MUTATIONS:
            with sqlite3.connect(self.f.client.path) as db:
                row = db.execute('SELECT phase,request FROM operations WHERE wire_intent=?', (request['intent_id'],)).fetchone()
            if row is None or row[0] != 'DISPATCHED' or json.loads(row[1]) != request:
                raise AssertionError('remote mutation must follow durable exact request claim')
            if self.mode != 'timeout_before': self.remote[request['intent_id']] = op
            if self.mode != 'timeout_before':
                self.current_fenced = op != 'activate'
                if op == 'fence': self.retired=True
                if op == 'adopt_clone':
                    self.adopted_from={k:request['old_'+k] for k in ('provider_id','generation','config_hash','machine_uuid_sha256')}
        if op!='inspect' and self.mode in ('timeout_before', 'timeout_after'): raise TimeoutError('raw secret stderr must never escape')
        frozen = True if op == 'quarantine_inspect' else self.current_fenced
        proof = {'schema': side.RESULT_SCHEMA, 'request_id': request['request_id'], 'intent_id': request.get('intent_id'),
            'operation': op, 'service_id': request['service_id'], 'provider_id': request['provider_id'],
            'generation': request['generation'], 'config_hash': request['config_hash'], 'machine_uuid_sha256': self.machine,
            'metadata_provider_id': request['provider_id'], 'observed_at': self.f.now, 'ok': True,
            'physical_identity_verified': True, 'guard_installed': True, 'identity_fenced': frozen,
            'all_units_inactive': frozen, 'units': {'nginx.service': 'inactive' if frozen else 'active'},
            'docker': {'registered': False, 'exact_inventory': True, 'running_ids': [], 'daemon_inactive': frozen, 'no_shims': True},
            'persistent_fence': frozen, 'active_generation_verified': not frozen,
            'writer_inventory_sha256':'7'*64,'systemd_jobs_clear':True,'current_generation_retired':self.retired,
            'activation_adoption_verified':self.adopted_from is not None,'adopted_from':self.adopted_from,
            'global_consistency_proven': False, 'migration_complete': False}
        if op == 'reconcile':
            missing=request['intent_id'] not in self.remote
            not_started=[]
            if missing and not self.retired:
                if not frozen: not_started.append('fence')
                elif self.adopted_from: not_started.append('activate')
            proof.update(read_only=True, recorded_operation=self.remote.get(request['intent_id']), recorded_completion=True,not_started_for=not_started)
        elif op == 'quarantine_inspect':
            proof.update(read_only=True,physical_identity_verified=False,persistent_fence=False,
                quarantine_verified=True,guard_denies_current_machine=True,docker_socket_inactive=True,
                inherited_identity={'service_id':request['service_id'],'provider_id':request['old_provider_id'],
                    'generation':request['old_generation'],'config_hash':request['old_config_hash'],
                    'machine_uuid_sha256':request['old_machine_uuid_sha256'],'fenced':True},
                bootstrap={'machine_id_ready':False},recorded_operation=self.remote.get(request['intent_id']),
                recorded_completion=False,journal_adoption_absent=request['intent_id'] not in self.remote)
        elif op in side.MUTATIONS: proof['outcome'] = {'fence':'fenced','adopt_clone':'adopted_fenced','activate':'activated'}[op]
        if op == 'activate': proof.update(old_fence_proof_digest=request['old_fence_proof_digest'], old_fence_proof_at=request['old_fence_proof_at'])
        if self.change and not(op=='inspect'and self.change_on_mutation_only): self.change(proof)
        raw = json.dumps(proof).encode()
        if op!='inspect'and self.mode == 'duplicate': raw = b'{"ok":true,'+raw[1:]
        if op!='inspect'and self.mode == 'trailing': raw += b'{}'
        output.write(raw)
        return 2 if op!='inspect'and self.mode == 'nonzero' else 0


class ClientTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.root.chmod(0o700)
        self.now = 1_800_000_000; self.key = b'k'*32
        for name, body in [('id_ed25519', b'fake-not-a-real-ssh-key'), ('hmac.key', self.key)]:
            (self.root/name).write_bytes(body); (self.root/name).chmod(0o600)
        algorithm = b'ssh-ed25519'; public = len(algorithm).to_bytes(4,'big')+algorithm+b'x'*32
        known = self.root/'known_hosts'
        known.write_bytes(b'8.8.8.8 ssh-ed25519 '+base64.b64encode(public)+b'\n'); known.chmod(0o600)
        self.config = {'schema': lc.SCHEMA, 'service_id':'711', 'provider_id':'40', 'generation':1,
            'config_hash':'d'*64, 'source_host':'8.8.8.8', 'ssh_key_file':str(self.root/'id_ed25519'),
            'known_hosts_file':str(known), 'expected_host_key_sha256':'SHA256:'+base64.b64encode(hashlib.sha256(public).digest()).decode().rstrip('='),
            'controller_key_file':str(self.root/'hmac.key'), 'state_dir':str(self.root)}
        self.ssh = FakeSSH(self); self.authorizations = []
        self.client = self.make()

    def make(self, **changes):
        return lc.LifecycleClient(self.config, authorize=changes.pop('authorize', self.authorize),
            transport=self.ssh, clock=lambda:self.now, allow_test_paths=True, **changes)

    def authorize(self, permit):
        self.authorizations.append(copy.deepcopy(permit)); return True

    def permit(self, operation='FENCE', **changes):
        params = {}
        if operation == 'ADOPT_CLONE': params = {'old_provider_id':'39','old_generation':self.client.target['generation']-1,
            'old_config_hash':'c'*64,'old_machine_uuid_sha256':'b'*64}
        if operation == 'ACTIVATE': params = {'old_provider_id':'39','old_generation':self.client.target['generation']-1,
            'old_fence_proof_digest':'f'*64,'old_fence_proof_at':self.now}
        value = {'schema':lc.PERMIT_SCHEMA,'workflow_id':str(uuid.uuid4()), 'intent_id':str(uuid.uuid4()),
            'kind':operation,'service_id':'711','target':copy.deepcopy(self.client.target),'params':params,
            'created_at':self.now,'expires_at':self.now+30}
        value.update(changes); return value

    def test_inspect_uses_pinned_ssh_and_learns_exact_machine_without_mutation(self):
        result = self.client.inspect()
        self.assertEqual('observed', result['state']); self.assertEqual([], self.authorizations)
        argv, request, maximum, timeout = self.ssh.calls[0]
        self.assertIn('-F', argv); self.assertIn('/dev/null', argv)
        self.assertIn('-oStrictHostKeyChecking=yes', argv); self.assertIn('-oProxyCommand=none', argv)
        self.assertEqual('root@8.8.8.8', argv[-2]); self.assertEqual(90, request['expires_at']-request['issued_at'])
        self.assertEqual(lc.MAX_RESULT, maximum); self.assertLessEqual(timeout,80)
        with sqlite3.connect(self.client.path) as db:
            self.assertEqual(0, db.execute('SELECT COUNT(*) FROM operations').fetchone()[0])
            self.assertEqual('a'*64, db.execute('SELECT machine FROM machines').fetchone()[0])

    def test_mutation_is_durable_before_ssh_and_permit_deadline_bounds_wire(self):
        permit = self.permit(expires_at=self.now+1)
        result = self.client.fence(permit)
        self.assertEqual('complete', result['state']); self.assertFalse(result['migration_complete'])
        self.assertEqual(permit, self.authorizations[-1])
        self.assertEqual(permit['expires_at'], self.ssh.calls[-1][1]['expires_at'])
        self.assertEqual(lc.wire_intent_id(permit['intent_id']), self.ssh.calls[-1][1]['intent_id'])

    def test_no_authorizer_or_generic_promote_or_json_flag_can_mutate(self):
        client = self.make(authorize=None)
        with self.assertRaisesRegex(lc.LifecycleClientError,'workflow_writes_disabled'): client.fence(self.permit())
        with self.assertRaisesRegex(lc.LifecycleClientError,'workflow_operation_scope'): self.client.fence(self.permit('PROMOTE'))
        with self.assertRaisesRegex(lc.LifecycleClientError,'explicit_workflow'): self.client.fence({**self.permit(),'approved':True})
        self.assertEqual([], self.ssh.calls)

    def test_authorizer_must_return_literal_true(self):
        for answer in (False, None, 1, {'approved':True}):
            client = self.make(authorize=lambda permit:answer)
            # Keep one immutable intent for all rejected authorizers.
            permit = getattr(self,'same_permit',None) or self.permit(); self.same_permit=permit
            with self.assertRaisesRegex(lc.LifecycleClientError,'workflow_writes_disabled'): client.fence(permit)
        self.assertEqual([], self.ssh.calls)

    def test_expiry_spent_in_authorizer_never_dispatches(self):
        def delayed(permit): self.now+=31; return True
        client = self.make(authorize=delayed)
        with self.assertRaisesRegex(lc.LifecycleClientError,'workflow_permit_expired'): client.fence(self.permit())
        self.assertEqual([], self.ssh.calls)

    def test_timeout_survives_restart_and_same_or_new_id_cannot_resend(self):
        permit = self.permit(); self.ssh.mode='timeout_after'
        result = self.client.fence(permit)
        self.assertEqual('unknown',result['state']); self.assertNotIn('proof',result)
        self.client = self.make(); self.client.fence(permit)
        self.assertEqual(2,len(self.ssh.calls))
        with self.assertRaisesRegex(lc.LifecycleClientError,'unresolved_operation_exists'): self.client.fence(self.permit())
        self.assertEqual(2,len(self.ssh.calls))

    def test_readonly_reconcile_uses_fresh_request_and_completes_exact_fence(self):
        permit=self.permit(); self.ssh.mode='timeout_after'; self.client.fence(permit)
        first=self.ssh.calls[-1][1]; self.ssh.mode=None
        result=self.client.reconcile(permit['intent_id']); request=self.ssh.calls[-1][1]
        self.assertEqual('complete',result['state']); self.assertEqual('reconcile',request['op'])
        self.assertEqual(first['intent_id'],request['intent_id'])
        self.assertNotEqual(first['nonce'],request['nonce']); self.assertNotEqual(first['request_id'],request['request_id'])
        self.assertTrue(result['proof']['read_only'])

    def test_missing_remote_intent_never_proves_no_dispatch(self):
        permit=self.permit(); self.ssh.mode='timeout_before'; self.client.fence(permit); self.ssh.mode=None
        result=self.client.reconcile(permit['intent_id'])
        self.assertEqual('unknown',result['state'])
        self.client.fence(permit)
        self.assertEqual(['fence','reconcile'],[call[1]['op'] for call in self.ssh.calls if call[1]['op']!='inspect'])

    def test_remote_completion_alone_never_overrides_unfenced_source(self):
        permit=self.permit(); self.ssh.mode='timeout_after'; self.client.fence(permit); self.ssh.mode=None
        self.ssh.change=lambda proof:proof.update(persistent_fence=False)
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])

    def test_wrong_recorded_operation_and_unscoped_reply_are_unknown(self):
        permit=self.permit(); self.ssh.mode='timeout_after'; self.client.fence(permit); self.ssh.mode=None
        self.ssh.change=lambda proof:proof.update(recorded_operation='activate')
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])
        self.ssh.change=lambda proof:proof.update(provider_id='41')
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])

    def test_nonzero_duplicate_trailing_or_secret_extra_reply_are_unknown(self):
        for mode in ('nonzero','duplicate','trailing'):
            with self.subTest(mode=mode):
                self.ssh.mode=mode
                self.ssh.current_fenced=False;self.ssh.retired=False
                # Each subcase uses a separate private controller journal.
                subroot=self.root/mode; subroot.mkdir(mode=0o700)
                self.config['state_dir']=str(subroot); self.client=self.make()
                result=self.client.fence(self.permit())
                self.assertEqual('unknown',result['state']); self.assertNotIn('proof',result)
        self.ssh.mode=None; subroot=self.root/'secret'; subroot.mkdir(mode=0o700)
        self.ssh.current_fenced=False;self.ssh.retired=False;self.ssh.change_on_mutation_only=True
        self.config['state_dir']=str(subroot); self.client=self.make()
        self.ssh.change=lambda proof:proof.update(private_key='secret')
        self.assertEqual('unknown',self.client.fence(self.permit())['state'])

    def test_existing_dmi_binding_and_known_config_dmi_cannot_change(self):
        self.client.inspect(); self.ssh.machine='b'*64
        with self.assertRaisesRegex(lc.LifecycleClientError,'remote_machine_identity'): self.client.inspect()
        self.config['machine_uuid_sha256']='c'*64; self.client=self.make()
        with self.assertRaisesRegex(lc.LifecycleClientError,'remote_machine_identity'): self.client.inspect()

    def test_metadata_unavailable_requires_previously_bound_dmi(self):
        self.ssh.change=lambda proof:proof.update(metadata_provider_id=None)
        with self.assertRaisesRegex(lc.LifecycleClientError,'remote_machine_unbound'): self.client.inspect()
        self.ssh.change=None; self.client.inspect()
        self.ssh.change=lambda proof:proof.update(metadata_provider_id=None)
        self.assertEqual('observed',self.client.inspect()['state'])

    def test_bad_knownhost_pin_and_group_readable_key_fail_before_ssh(self):
        self.config['expected_host_key_sha256']='SHA256:wrong'
        with self.assertRaises(Exception): self.make()
        self.config['expected_host_key_sha256']=self.client.config['expected_host_key_sha256']
        (self.root/'hmac.key').chmod(0o640)
        with self.assertRaises(Exception): self.make()
        self.assertEqual([],self.ssh.calls)

    def test_clock_regression_and_semantic_rebinding_are_rejected(self):
        permit=self.permit(); self.client.fence(permit)
        changed={**permit,'workflow_id':str(uuid.uuid4())}
        with self.assertRaisesRegex(lc.LifecycleClientError,'operation_scope_changed'): self.client.fence(changed)
        self.now-=1
        with self.assertRaisesRegex(lc.LifecycleClientError,'clock_unsafe'): self.client.inspect()

    def test_adoption_and_activation_proofs_are_explicit_not_global_migration(self):
        self.config.update(generation=2); self.client=self.make()
        result=self.client.adopt_clone(self.permit('ADOPT_CLONE'))
        self.assertEqual('complete',result['state']); self.assertFalse(result['migration_complete'])
        result=self.client.activate(self.permit('ACTIVATE'))
        self.assertEqual('complete',result['state']); self.assertTrue(result['proof']['active_generation_verified'])

    def test_adoption_unknown_needs_recorded_completion_not_only_fenced_state(self):
        self.config.update(generation=2); self.client=self.make(); permit=self.permit('ADOPT_CLONE')
        self.ssh.mode='timeout_after'; self.client.adopt_clone(permit); self.ssh.mode=None
        self.ssh.change=lambda proof:proof.update(recorded_completion=False)
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])

    def test_quarantine_inspection_has_exact_old_new_scope_and_no_mutation(self):
        self.config.update(generation=2); self.client=self.make()
        params=self.permit('ADOPT_CLONE')['params']
        result=self.client.quarantine_inspect(params)
        self.assertEqual('observed',result['state']); self.assertTrue(result['proof']['quarantine_verified'])
        self.assertFalse(result['proof']['physical_identity_verified'])
        self.assertFalse(result['proof']['bootstrap']['machine_id_ready'])
        self.assertEqual([],self.authorizations)
        self.assertEqual(['quarantine_inspect'],[call[1]['op'] for call in self.ssh.calls])

    def test_quarantine_cannot_claim_active_or_mutable_old_generation(self):
        self.config.update(generation=2); self.client=self.make()
        params=self.permit('ADOPT_CLONE')['params']
        self.ssh.change=lambda proof:proof.update(docker_socket_inactive=False)
        with self.assertRaisesRegex(lc.LifecycleClientError,'remote_quarantine'): self.client.quarantine_inspect(params)
        self.ssh.change=lambda proof:proof.update(bootstrap={'machine_id_ready':True,'new_machine_id':'secret'})
        with self.assertRaisesRegex(lc.LifecycleClientError,'remote_bootstrap'): self.client.quarantine_inspect(params)

    def test_unknown_adopt_can_prove_absence_only_after_original_request_expired(self):
        self.config.update(generation=2); self.client=self.make(); permit=self.permit('ADOPT_CLONE')
        self.ssh.mode='timeout_before'; self.client.adopt_clone(permit); self.ssh.mode='quarantine_only'
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])
        self.now+=31
        result=self.client.reconcile(permit['intent_id'])
        self.assertEqual('not_started',result['state']); self.assertFalse(result['requires_reconcile'])
        self.assertTrue(result['proof']['journal_adoption_absent'])

    def test_proven_absent_adopt_needs_fresh_quarantine_and_fresh_authorization_before_retry(self):
        self.config.update(generation=2); self.client=self.make(); permit=self.permit('ADOPT_CLONE')
        self.ssh.mode='timeout_before'; self.client.adopt_clone(permit)
        self.ssh.mode='quarantine_only'; self.now+=31
        self.assertEqual('not_started',self.client.reconcile(permit['intent_id'])['state'])
        fresh={**permit,'created_at':self.now,'expires_at':self.now+30}
        self.ssh.mode=None
        result=self.client.adopt_clone(fresh)
        self.assertEqual('complete',result['state'])
        calls=[call[1]['op'] for call in self.ssh.calls]
        self.assertEqual(['quarantine_inspect','adopt_clone'],calls[-2:])
        self.assertEqual(fresh,self.authorizations[-1])
        with sqlite3.connect(self.client.path) as db:
            self.assertEqual(2,db.execute('SELECT COUNT(*) FROM dispatches WHERE id=?',(permit['intent_id'],)).fetchone()[0])

    def test_absence_uses_remote_observed_clock_for_original_request_expiry(self):
        self.config.update(generation=2); self.client=self.make(); permit=self.permit('ADOPT_CLONE')
        self.ssh.mode='timeout_before'; self.client.adopt_clone(permit); self.ssh.mode='quarantine_only'
        self.now+=30; self.ssh.change=lambda proof:proof.update(observed_at=self.now-5)
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])
        self.now+=5
        self.assertEqual('not_started',self.client.reconcile(permit['intent_id'])['state'])

    def test_fence_not_started_requires_expired_exact_baseline_then_fresh_authorized_retry(self):
        permit=self.permit();self.ssh.mode='timeout_before'
        self.assertEqual('unknown',self.client.fence(permit)['state']);self.ssh.mode=None
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])
        self.now+=31
        self.assertEqual('not_started',self.client.reconcile(permit['intent_id'])['state'])
        fresh={**permit,'created_at':self.now,'expires_at':self.now+30}
        self.assertEqual('complete',self.client.fence(fresh)['state'])
        self.assertEqual(['reconcile','fence'],[c[1]['op']for c in self.ssh.calls[-2:]])
        with sqlite3.connect(self.client.path)as db:
            self.assertEqual(2,db.execute('SELECT COUNT(*) FROM dispatches').fetchone()[0])

    def test_fence_absence_requires_unchanged_writer_inventory_and_no_remote_tombstone(self):
        permit=self.permit();self.ssh.mode='timeout_before';self.client.fence(permit);self.ssh.mode=None;self.now+=31
        self.ssh.change=lambda proof:proof.update(writer_inventory_sha256='8'*64)
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])
        self.ssh.change=lambda proof:proof.update(current_generation_retired=True)
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])
        self.ssh.change=lambda proof:proof.update(systemd_jobs_clear=False)
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])
        self.assertEqual(1,sum(c[1]['op']=='fence'for c in self.ssh.calls))

    def test_initial_fence_can_stop_failed_or_transitional_writers(self):
        self.ssh.change_on_mutation_only=False
        def failed(proof):
            if proof['operation']=='inspect':
                proof['units']['nginx.service']='failed';proof['active_generation_verified']=False
                proof['systemd_jobs_clear']=False
        self.ssh.change=failed
        self.assertEqual('complete',self.client.fence(self.permit())['state'])

    def test_activation_not_started_can_renew_old_fence_attestation_without_changing_lineage(self):
        self.config.update(generation=2);self.client=self.make()
        self.client.adopt_clone(self.permit('ADOPT_CLONE'))
        permit=self.permit('ACTIVATE');self.ssh.mode='timeout_before'
        self.assertEqual('unknown',self.client.activate(permit)['state']);self.ssh.mode=None;self.now+=91
        self.assertEqual('not_started',self.client.reconcile(permit['intent_id'])['state'])
        params={**permit['params'],'old_fence_proof_at':self.now,'old_fence_proof_digest':'8'*64}
        fresh={**permit,'params':params,'created_at':self.now,'expires_at':self.now+30}
        result=self.client.activate(fresh)
        self.assertEqual('complete',result['state'])
        self.assertEqual('8'*64,self.ssh.calls[-1][1]['old_fence_proof_digest'])
        self.assertEqual(self.now,self.ssh.calls[-1][1]['old_fence_proof_at'])

    def test_partial_recorded_mutation_never_becomes_not_started(self):
        permit=self.permit();self.ssh.mode='timeout_after';self.client.fence(permit);self.ssh.mode=None;self.now+=31
        self.ssh.change=lambda proof:proof.update(persistent_fence=False,all_units_inactive=False)
        self.assertEqual('unknown',self.client.reconcile(permit['intent_id'])['state'])
        self.client.fence({**permit,'created_at':self.now,'expires_at':self.now+30})
        self.assertEqual(1,sum(c[1]['op']=='fence'for c in self.ssh.calls))

    def test_changed_absence_proof_blocks_even_previously_proven_not_started_retry(self):
        self.config.update(generation=2); self.client=self.make(); permit=self.permit('ADOPT_CLONE')
        self.ssh.mode='timeout_before'; self.client.adopt_clone(permit)
        self.ssh.mode='quarantine_only'; self.now+=31
        self.client.reconcile(permit['intent_id']); self.ssh.mode=None
        self.ssh.remote[lc.wire_intent_id(permit['intent_id'])]='adopt_clone'
        before=len(self.authorizations)
        with self.assertRaisesRegex(lc.LifecycleClientError,'adoption_absence_not_proven'):
            self.client.adopt_clone({**permit,'created_at':self.now,'expires_at':self.now+30})
        self.assertEqual(before,len(self.authorizations))
        self.assertEqual('quarantine_inspect',self.ssh.calls[-1][1]['op'])

    def test_denied_resume_keeps_absence_phase_and_next_attempt_must_recheck(self):
        self.config.update(generation=2); self.client=self.make(); permit=self.permit('ADOPT_CLONE')
        self.ssh.mode='timeout_before'; self.client.adopt_clone(permit)
        self.ssh.mode='quarantine_only'; self.now+=31
        self.client.reconcile(permit['intent_id']); self.ssh.mode=None
        fresh={**permit,'created_at':self.now,'expires_at':self.now+30}
        self.client.authorize=lambda permit:False
        with self.assertRaisesRegex(lc.LifecycleClientError,'workflow_writes_disabled'): self.client.adopt_clone(fresh)
        with sqlite3.connect(self.client.path) as db:
            self.assertEqual('NOT_STARTED',db.execute('SELECT phase FROM operations WHERE id=?',(permit['intent_id'],)).fetchone()[0])
        self.ssh.remote[lc.wire_intent_id(permit['intent_id'])]='adopt_clone'
        self.client.authorize=self.authorize
        with self.assertRaisesRegex(lc.LifecycleClientError,'adoption_absence_not_proven'): self.client.adopt_clone(fresh)
        self.assertEqual('quarantine_inspect',self.ssh.calls[-1][1]['op'])

    def test_response_boolean_generation_or_unsuccessful_proof_never_completes(self):
        self.ssh.change_on_mutation_only=True
        self.ssh.change=lambda proof:proof.update(generation=True)
        self.assertEqual('unknown',self.client.fence(self.permit())['state'])
        operation_id=self.authorizations[-1]['intent_id']
        self.ssh.change=lambda proof:proof.update(ok=False)
        self.assertEqual('unknown',self.client.reconcile(operation_id)['state'])

    def test_actual_signed_remote_handler_fence_quarantine_adopt_activate_contract(self):
        spec=importlib.util.spec_from_file_location('lifecycle_remote_fixture',Path(__file__).with_name('test_lifecycle_side.py'))
        fixture=importlib.util.module_from_spec(spec); spec.loader.exec_module(fixture)
        root=self.root/'remote'; root.mkdir(mode=0o700); identity_path=root/'identity.json'
        original={'schema':side.guard.SCHEMA,'service_id':'711','provider_id':'40','generation':1,
            'config_hash':'d'*64,'machine_uuid_sha256':'a'*64,'fenced':False,'units':['nginx.service','docker.service']}
        side.guard.atomic_identity(identity_path,original,root_owned=False)
        world=fixture.World(identity_path)
        config={'schema':side.SCHEMA,'identity_file':side.guard.DEFAULT_CONFIG,
            'controller_key_file':'/etc/servernet/relay-lifecycle.key','work_dir':'/var/lib/servernet-relay-lifecycle',
            'guard_sha256':'b'*64,'docker_containers':[world.container]}
        remote=side.Lifecycle(config,runner=world,metadata=lambda:world.provider,machine=lambda:world.machine,
            clock=lambda:self.now,root_owned=False,identity_path=identity_path,work_dir=root,key=self.key,
            guard_check=lambda name,values:True,docker_disk=lambda:list(world.disk))
        world.lifecycle=remote
        transport_errors=[]
        def transport(argv,body,output,maximum,timeout):
            try:result=remote.run(side.decode(body))
            except Exception as error:
                transport_errors.append(type(error).__name__+':'+str(error));raise
            output.write(side.canonical(result))
            return 0 if result['ok'] else 2
        self.client.transport=transport
        fenced=self.client.fence(self.permit()); self.assertEqual('complete',fenced['state'],transport_errors)
        world.provider='51'; world.machine='b'*64
        self.config.update(provider_id='51',generation=2,config_hash='e'*64)
        self.client=self.make(); self.client.transport=transport
        adopt=self.permit('ADOPT_CLONE'); adopt['params'].update(old_provider_id='40',old_generation=1,
            old_config_hash='d'*64,old_machine_uuid_sha256='a'*64)
        quarantined=self.client.quarantine_inspect(adopt['params'],adopt['intent_id'])
        self.assertTrue(quarantined['proof']['quarantine_verified'])
        self.assertEqual('complete',self.client.adopt_clone(adopt)['state'])
        activate=self.permit('ACTIVATE'); activate['params'].update(old_provider_id='40',old_generation=1,
            old_fence_proof_digest=hashlib.sha256(side.canonical(fenced['proof'])).hexdigest())
        active=self.client.activate(activate)
        self.assertEqual('complete',active['state']); self.assertTrue(active['proof']['active_generation_verified'])
        self.assertFalse(active['migration_complete'])


if __name__=='__main__': unittest.main()
