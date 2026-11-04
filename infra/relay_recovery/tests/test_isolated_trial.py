"""Real composite/client/verifier/NodeStage/CT paths; only OS transports fake."""
import contextlib
import copy
import hashlib
import io
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock

from infra.relay_recovery import isolated_trial as mod
from infra.relay_recovery import ct_stage, iran_stage, protocol
from infra.relay_recovery.tests import test_ct_stage as ct_tests
from infra.relay_recovery.tests import test_iran_stage as node_tests
from infra.relay_recovery.tests.test_windows_candidate_probe_client import CandidateGuest, KEY, REQUEST_NONCE, NOW


class IsolatedTrialTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name); self.root.chmod(0o700)
        self.now = NOW; self.mono = 0; self.counter = 0
        self.ct = ct_tests.CtStageTests(); self.ct.setUp(); self.addCleanup(self.ct.doCleanups)
        self.node_fixture = node_tests.NodeStageTests(); self.node_fixture.setUp(); self.addCleanup(self.node_fixture.doCleanups)
        self.dep_file = self.root/'endpoint.conf'; self.dep_file.write_bytes(b'original endpoint'); self.dep_file.chmod(0o600)
        self.unit_pid = '44'; self.dependency_calls = 0; self.fail_dependency = False
        self.ct_bad = False; self.change_after_guest = False; self.delay_guest = False
        self.stage_context = self.ct.context('stage'); self.stage_context['job']['params'].pop('expires_at')
        self.ct.ctx = self.stage_context
        self.assertTrue(self.ct.helper().run('stage', {**self.stage_context,
            'job': {**self.stage_context['job'], 'params': {**self.stage_context['job']['params'], 'expires_at': NOW+30}}})['ok'])
        self.ct.calls.clear()  # Prior isolated stage is not performed by the producer.
        self.ct_context = copy.deepcopy(self.stage_context); self.ct_context['job'].update(op='accept', id='accept-1')
        identity = dict(provider='hetzner', project_id='p', provider_id='222', generation=2)
        deps = dict(schema=mod.REGISTRY, agent_id='pve-ir', resources=[
            dict(id='endpoint', kind='file', location='pve', path=str(self.dep_file)),
            dict(id='publisher', kind='unit', location='ct113', unit='publisher.service')])
        self.refs = {'dependencies': self.pin('deps.json', deps)}
        self.proxy = dict(schema=mod.candidate.ENROLLMENT_SCHEMA, enabled=True, agent_id='pve-ir',
            proxy_host='192.168.10.109', proxy_port=19081,
            proxy_binding_sha256=mod.candidate.proxy_binding('pve-ir', '192.168.10.109', 19081))
        self.refs['candidate_proxy'] = self.pin('proxy.json', self.proxy)
        self.key = self.root/'guest.key'; self.key.write_bytes(KEY); self.key.chmod(0o600)
        self.refs['windows'] = self.pin('windows.json', dict(schema=mod.windows.SCHEMA,
            agent_id='pve-ir', key_file=str(self.key), state_dir=str(self.root/'guest-state'),
            access_policy=mod.windows.SOURCE_HMAC_POLICY))
        (self.root/'verifier-state').mkdir(mode=0o700)
        self.refs['verifier'] = self.pin('verifier.json', dict(schema=mod.verifier_enrollment.SCHEMA,
            enabled=True, agent_id='pve-ir', guest_key_file=str(self.key),
            guest_key_sha256=hashlib.sha256(KEY).hexdigest(), state_dir=str(self.root/'verifier-state'),
            proxy_binding_sha256=self.proxy['proxy_binding_sha256'], access_policy=mod.windows.SOURCE_HMAC_POLICY))
        self.refs['node_stage'] = self.pin('node.json', self.node_fixture.raw)
        self.config = dict(schema=mod.SCHEMA, enabled=True, agent_id='pve-ir', **self.refs)
        self.reference = self.pin('producer.json', self.config)
        self.context = copy.deepcopy(self.ct_context)
        self.context['job']['params'] = dict(acceptance_profile=mod.isolated.PROFILE, stage_profile='trial',
            expected_registry_hash=self.refs['dependencies']['sha256'], dependency_baseline_sha256='a'*64,
            proxy_binding_sha256=self.proxy['proxy_binding_sha256'], resource_identity=identity,
            resource_config_hash=self.context['config_hash'])
        self.ct_context['job']['params'].update(resource_identity=identity,
            resource_config_hash=self.context['config_hash'], expected_registry_hash=self.refs['dependencies']['sha256'])
        self.peer = CandidateGuest(self.proxy, mod.windows.SOURCE_HMAC_POLICY)
        real_node = iran_stage.NodeStage; real_guest = mod.candidate.WindowsCandidateProbeClient
        def new_node(config, **kw):
            kw.pop('runner', None)  # fixed OS transport replacement only
            return real_node(config, runner=self.node_transport, verify_pct=False,
                nonce=self.node_fixture.nonce, **kw)
        def new_guest(config, enrollment, **kw):
            return real_guest(config, enrollment, transport=self.guest_transport, nonce=lambda: REQUEST_NONCE, **kw)
        self.enter(mock.patch.object(mod.iran_stage, 'NodeStage', side_effect=new_node))
        self.enter(mock.patch.object(mod.candidate, 'WindowsCandidateProbeClient', side_effect=new_guest))
        self.enter(mock.patch.object(mod.agent, '_bounded_process', side_effect=self.dependencies_transport))

    def enter(self, patch):
        value = patch.start(); self.addCleanup(patch.stop); return value

    def pin(self, name, value):
        path = self.root/name; raw = protocol.canonical_json(value)
        path.write_bytes(raw); path.chmod(0o600)
        return dict(path=str(path), sha256=hashlib.sha256(raw).hexdigest())

    def producer(self):
        return mod.IsolatedTrialProducer(self.reference, clock=lambda: self.now,
            monotonic=lambda: self.mono, root_owned=False)

    def unit_output(self, command, **kwargs):
        self.assertEqual(command[:3], ['/usr/bin/systemctl', 'show', 'publisher.service'])
        fields = dict(Id='publisher.service', LoadState='loaded', ActiveState='active', SubState='running',
            MainPID=self.unit_pid, NeedDaemonReload='no', ActiveEnterTimestampMonotonic='10',
            ExecMainStartTimestampMonotonic='10')
        return SimpleNamespace(returncode=0, stdout=''.join(k+'='+v+'\n' for k,v in fields.items()).encode())

    def dependencies_transport(self, argv, data, timeout):
        self.dependency_calls += 1
        self.assertLessEqual(timeout, 3); self.assertEqual(data, b'')
        self.assertIn(mod.DEPENDENCY_READER, argv)
        if self.fail_dependency:
            return 1, b'{"error":"dependency_hold"}'
        # Execute the actual finite reader. Only root ownership and systemd OS
        # observations are simulated; file hashes come from real fixture bytes.
        real_lstat, real_fstat = Path.lstat, os.fstat
        def trusted(s):
            return SimpleNamespace(**{key: getattr(s, key) for key in
                ('st_dev','st_ino','st_size','st_mtime_ns','st_ctime_ns','st_nlink')},
                st_uid=0, st_mode=s.st_mode & ~0o022)
        output = io.StringIO()
        with mock.patch.object(Path, 'lstat', lambda p: trusted(real_lstat(p))), \
             mock.patch.object(os, 'fstat', lambda fd: trusted(real_fstat(fd))), \
             mock.patch('subprocess.run', side_effect=self.unit_output), \
             mock.patch.object(sys, 'argv', ['reader', argv[-1]]), \
             mock.patch('time.time', return_value=self.now), contextlib.redirect_stdout(output):
            exec(compile(mod.DEPENDENCY_READER, '<fixed-dependency-reader>', 'exec'), {})
        return 0, output.getvalue().encode()

    def node_transport(self, argv, data, timeout):
        if '--job-file' not in argv:
            return self.node_fixture.runner(argv, data, timeout)
        self.node_fixture.calls.append((argv, data, timeout))
        raw = self.node_fixture.remote[argv[argv.index('--job-file')+1]]
        nonce = argv[argv.index('--nonce')+1]; path = self.ct.root/'jobs'/(nonce+'.json')
        path.write_bytes(raw); path.chmod(0o600)
        context = ct_stage.load_job(self.ct.cfg, path, argv[argv.index('--job-sha256')+1], nonce, argv[-1], self.now)
        self.ct.ctx = context; self.ct.egress = '9.9.9.9' if self.ct_bad else context['job']['target']['host']
        result = ct_stage.CtStage(self.ct.cfg, runner=self.ct.runner, clock=lambda: self.now,
            monotonic=lambda: 0).run(argv[-1], context)
        return 0 if result['ok'] else 1, protocol.canonical_json(result)

    def guest_transport(self, *args):
        result = self.peer(*args)
        if self.change_after_guest:
            self.dep_file.write_bytes(b'changed endpoint')
        if self.delay_guest:
            self.mono += mod.DEADLINE+1
        return result

    def ready(self):
        producer = self.producer()
        baseline = producer.inspect_dependencies()
        self.context['job']['params']['dependency_baseline_sha256'] = baseline['state_sha256']
        return producer

    def combined(self):
        value=copy.deepcopy(self.context)
        value['job']['params'].update({k:self.ct_context['job']['params'][k] for k in mod.SSH_FIELDS})
        value['job']['params'].update(expires_at=self.now+25,
            isolated_trial_config_sha256=self.reference['sha256'],
            isolated_verifier_sha256=self.refs['verifier']['sha256'])
        return value

    def test_raw_recipe_preserves_strict_backend_grammars_and_consumes_final_nonce_only_in_adapter_verifier(self):
        producer=self.ready(); combined=self.combined()
        guest,ct=mod.project_context(combined)
        self.assertEqual(set(guest['job']['params']),mod.candidate.PARAMS)
        self.assertEqual(set(ct['job']['params']),set(self.ct_context['job']['params'])|{'expires_at'})
        self.assertEqual(ct['jobs_hash'],combined['jobs_hash']);self.assertEqual(guest['jobs_hash'],combined['jobs_hash'])
        recipe=producer.produce_recipe(combined)
        self.assertTrue(recipe['ok']);self.assertLessEqual(len(protocol.canonical_json(recipe)),8192)
        self.assertNotIn('verified',recipe);self.assertIsNone(producer.verifier)
        replay=Path(self.refs['verifier']['path']).parent/'verifier-state'/mod.verifier_enrollment.REPLAY_NAME
        self.assertFalse(replay.exists())
        verifier=mod.verifier_enrollment.load_verifier(self.refs['verifier'],agent_id='pve-ir',root_owned=False,now=lambda:self.now)
        proof=verifier.verify(combined,recipe)
        self.assertTrue(proof['verified']);self.assertFalse(proof['default_path_verified'])
        with self.assertRaises(protocol.ProtocolError):verifier.verify(combined,recipe)

    def test_raw_recipe_can_complete_with_full_eight_second_guest_allowance(self):
        producer=self.ready();combined=self.combined();transport=self.guest_transport
        def full_budget(*args):
            result=transport(*args);self.mono+=mod.windows.DEADLINE;return result
        self.guest_transport=full_budget
        self.assertTrue(producer.produce_recipe(combined)['ok'])
        self.assertEqual(self.mono,mod.windows.DEADLINE)
        self.assertLess(self.mono,mod.WORK_BUDGET)

    def test_combined_extras_or_missing_original_deadline_reject_before_guest(self):
        self.ready();base=self.combined()
        for key,value in [('command','bad'),('critical_consumers',['vm109']),('url','https://invalid.invalid')]:
            context=copy.deepcopy(base);context['job']['params'][key]=value
            with self.subTest(key=key),self.assertRaises(protocol.ProtocolError):mod.project_context(context)
        base['job']['params'].pop('expires_at')
        with self.assertRaises(protocol.ProtocolError):mod.project_context(base)
        self.assertFalse(self.peer.calls)

    def test_raw_recipe_wrong_pins_and_expired_or_extended_read_lease_do_not_launch(self):
        self.ready();base=self.combined()
        for key,value in [('isolated_trial_config_sha256','f'*64),('isolated_verifier_sha256','f'*64),
                          ('expires_at',self.now),('expires_at',self.now+31)]:
            context=copy.deepcopy(base);context['job']['params'][key]=value
            with self.subTest(key=key,value=value),self.assertRaises(protocol.ProtocolError):
                self.producer().produce_recipe(context)
        self.assertFalse(self.peer.calls);self.assertFalse(self.node_fixture.remote)

    def test_original_read_lease_expiring_during_guest_cannot_return_recipe(self):
        producer=self.ready();combined=self.combined();transport=self.guest_transport
        def expired(*args):
            result=transport(*args);self.now=combined['job']['params']['expires_at'];return result
        self.guest_transport=expired
        with self.assertRaisesRegex(protocol.ProtocolError,'expired|timeout'):
            producer.produce_recipe(combined)
        self.assertIsNone(producer.verifier)

    def test_adapter_missing_or_disabled_proxy_enrollment_holds_before_key_or_final_replay_access(self):
        from infra.relay_recovery import iran_adapter
        for disabled in (False,True):
            if disabled:
                self.config['candidate_proxy']=self.pin('disabled-proxy.json',{**self.proxy,'enabled':False})
            else:self.config['candidate_proxy']=None
            self.reference=self.pin('producer.json',self.config)
            combined=self.combined();self.key.unlink(missing_ok=True)
            config=dict(agent_id='pve-ir',candidate_ports=[22,443],root_owned=False,
                isolated_trial=self.reference,isolated_verifier=self.refs['verifier'])
            with self.subTest(disabled=disabled),self.assertRaisesRegex(protocol.ProtocolError,'candidate.*(missing|enrolled)'):
                iran_adapter.build_adapter(config,action='accept',context=combined,now=lambda:self.now)
            self.assertFalse((self.root/'verifier-state'/mod.verifier_enrollment.REPLAY_NAME).exists())
        self.assertFalse(self.peer.calls)

    def test_real_ct_guest_and_enrolled_verifier_make_only_fresh_isolated_proof(self):
        producer = self.ready(); result = producer.produce(self.context, self.ct_context)
        self.assertTrue(result['verified']); self.assertFalse(result['default_path_verified'])
        self.assertFalse(result['global_cutover_performed']); self.assertEqual(NOW, result['observed_at'])
        self.assertEqual(mod.isolated.PATH_PROFILE, result['isolated_candidate']['path_profile'])
        for forbidden in ('isolation_verified','critical_receipts','global_cutover_complete','headers','body_base64'):
            self.assertNotIn(forbidden, str(result))
        self.assertNotIn(KEY.hex(), str(result)); self.assertEqual(1,len(self.peer.calls))
        self.assertEqual({tuple(call[0][:2]) for call in self.ct.calls if call[0][0]=='/usr/bin/systemctl'},
            {('/usr/bin/systemctl','show'),('/usr/bin/systemctl','is-active'),('/usr/bin/systemctl','list-jobs')})

    def test_cached_accept_never_freshens_and_replay_performs_no_second_remote_accept(self):
        producer = self.ready(); producer.produce(self.context, self.ct_context)
        calls = len(self.node_fixture.remote)
        with self.assertRaisesRegex(protocol.ProtocolError, 'already_observed'):
            producer.produce(self.context, self.ct_context)
        self.assertEqual(calls,len(self.node_fixture.remote)); self.assertEqual(1,len(self.peer.calls))

    def test_changed_dependency_after_guest_or_initial_baseline_holds(self):
        producer = self.ready(); self.change_after_guest = True
        with self.assertRaisesRegex(protocol.ProtocolError, 'dependencies_changed'):
            producer.produce(self.context, self.ct_context)
        self.assertEqual(1,len(self.peer.calls))

    def test_wrong_baseline_rejects_before_any_ct_accept_or_guest(self):
        producer = self.producer()
        with self.assertRaisesRegex(protocol.ProtocolError, 'dependency_baseline'):
            producer.produce(self.context, self.ct_context)
        self.assertFalse(self.node_fixture.remote); self.assertFalse(self.peer.calls)

    def test_ct_target_egress_failure_prevents_guest(self):
        producer = self.ready(); self.ct_bad = True
        with self.assertRaises(protocol.ProtocolError): producer.produce(self.context,self.ct_context)
        self.assertFalse(self.peer.calls)

    def test_signed_default_path_wrong_scope_and_tampered_signature_hold(self):
        for change in ('default','scope','signature'):
            with self.subTest(change=change):
                # Each attempt needs its own authenticated accept context.
                self.context['job']['id'] = self.ct_context['job']['id'] = 'accept-'+change
                producer=self.ready();self.peer.mutate=None;self.peer.tamper=False
                if change=='default':self.peer.mutate=lambda r:r.update(path_profile='default_path_v1')
                elif change=='scope':self.peer.mutate=lambda r:r['scope'].update(revision=999)
                else:self.peer.tamper=True
                with self.assertRaises(protocol.ProtocolError):producer.produce(self.context,self.ct_context)

    def test_context_mismatch_and_extra_fields_reject_before_creating_guest_state(self):
        for field,bad in (('revision',9),('config_hash','0'*64)):
            value=copy.deepcopy(self.ct_context);value[field]=bad
            with self.subTest(field=field),self.assertRaises(protocol.ProtocolError):
                self.producer().produce(self.context,value)
        self.assertFalse((self.root/'guest-state').exists());self.assertFalse(self.node_fixture.remote)
        self.context['job']['params']['command']='untrusted'
        with self.assertRaises(protocol.ProtocolError):self.producer().produce(self.context,self.ct_context)

    def test_missing_candidate_enrollment_holds_before_key_state_or_network(self):
        self.config['candidate_proxy']=None; ref=self.pin('producer.json',self.config)
        self.key.unlink()
        with self.assertRaisesRegex(protocol.ProtocolError,'candidate_enrollment_missing'):
            mod.IsolatedTrialProducer(ref,root_owned=False)
        self.assertFalse((self.root/'guest-state').exists());self.assertFalse(self.peer.calls)

    def test_pin_drift_clock_regression_and_budget_expiry_hold(self):
        producer=self.ready();self.dep_file.write_bytes(b'newbaseline')
        with self.assertRaises(protocol.ProtocolError):producer.produce(self.context,self.ct_context)
        producer=self.ready();self.now-=1
        with self.assertRaisesRegex(protocol.ProtocolError,'clock'):producer.produce(self.context,self.ct_context)
        self.now=NOW;producer=self.ready();self.delay_guest=True
        with self.assertRaisesRegex(protocol.ProtocolError,'deadline|timeout'):producer.produce(self.context,self.ct_context)

    def test_changed_pinned_configuration_rejected_before_probe(self):
        producer=self.ready();Path(self.refs['dependencies']['path']).write_bytes(b'{}')
        with self.assertRaisesRegex(protocol.ProtocolError,'pin'):producer.produce(self.context,self.ct_context)
        self.assertFalse(self.peer.calls)

    def test_unknown_resource_kind_duplicate_path_and_partial_catalogue_reject(self):
        original=protocol.decode_json(Path(self.refs['dependencies']['path']).read_bytes())
        for resources in ([original['resources'][0]], [original['resources'][0]]*2,
                          [{**original['resources'][0],'kind':'shell'},original['resources'][1]]):
            self.config['dependencies']=self.pin('deps.json',{**original,'resources':resources})
            ref=self.pin('producer.json',self.config)
            with self.assertRaises(protocol.ProtocolError):mod.IsolatedTrialProducer(ref,root_owned=False)

    def test_loaded_process_identity_change_is_not_a_stable_file_baseline(self):
        producer=self.ready()
        self.peer.mutate=lambda response:setattr(self,'unit_pid','45')
        with self.assertRaisesRegex(protocol.ProtocolError,'dependencies_changed'):
            producer.produce(self.context,self.ct_context)

    def test_raw_dependency_read_failure_cannot_turn_into_coverage(self):
        producer=self.producer();self.fail_dependency=True
        with self.assertRaisesRegex(protocol.ProtocolError,'dependency_read'):
            producer.produce(self.context,self.ct_context)
        self.assertFalse(self.peer.calls);self.assertFalse(self.node_fixture.remote)

    def test_original_guest_clock_is_not_freshened_by_later_receipt(self):
        producer=self.ready()
        self.peer.mutate=lambda response:response.update(observed_at=NOW-31)
        with self.assertRaises(protocol.ProtocolError):producer.produce(self.context,self.ct_context)

    def test_single_instance_concurrent_call_cannot_extend_shared_deadline(self):
        producer=self.ready();producer._busy.acquire()
        try:
            with self.assertRaisesRegex(protocol.ProtocolError,'busy'):
                producer.produce(self.context,self.ct_context)
            with self.assertRaisesRegex(protocol.ProtocolError,'busy'):producer.inspect_dependencies()
        finally:producer._busy.release()
        self.assertFalse(self.peer.calls)

    def test_actual_restored_ct_profile_retains_exact_epoch_hash_and_source_pin(self):
        restored=self.ct.context('stage','restored','stage-restored')
        self.ct.ctx=restored
        self.assertTrue(self.ct.helper().run('stage',restored)['ok'])
        self.ct_context['job']['id']=self.context['job']['id']='accept-restored'
        for value in (self.context,self.ct_context):
            value['job']['params'].update(stage_profile='restored',restore_epoch=1,restore_hash='d'*64)
        self.ct_context['job']['params'].update(**{k:restored['job']['params'][k]
            for k in ('ssh_host_public_key','ssh_host_key_sha256')})
        producer=self.ready();result=producer.produce(self.context,self.ct_context)
        self.assertEqual(1,result['isolated_candidate']['restore_epoch'])
        self.assertEqual('d'*64,result['isolated_candidate']['restore_hash'])

    def test_combined_raw_restored_profile_preserves_both_backend_epoch_and_host_pin(self):
        restored=self.ct.context('stage','restored','raw-stage-restored');self.ct.ctx=restored
        self.assertTrue(self.ct.helper().run('stage',restored)['ok'])
        for value in (self.context,self.ct_context):
            value['job']['id']='raw-accept-restored'
            value['job']['params'].update(stage_profile='restored',restore_epoch=1,restore_hash='d'*64)
        self.ct_context['job']['params'].update({k:restored['job']['params'][k] for k in mod.SSH_FIELDS})
        producer=self.ready();combined=self.combined();guest,ct=mod.project_context(combined)
        recipe=producer.produce_recipe(combined)
        self.assertEqual(1,recipe['isolated_evidence']['ct']['restore_epoch'])
        self.assertEqual('d'*64,recipe['isolated_evidence']['ct']['restore_hash'])
        self.assertEqual(guest['job']['params']['restore_hash'],ct['job']['params']['restore_hash'])
        self.assertEqual(ct['job']['params']['ssh_host_key_sha256'],restored['job']['params']['ssh_host_key_sha256'])

    def test_enrolled_but_unavailable_native_candidate_transport_has_no_fallback(self):
        producer=self.ready()
        with mock.patch.object(self,'guest_transport',side_effect=OSError('fixture unavailable')):
            with self.assertRaisesRegex(protocol.ProtocolError,'transport_failed'):
                producer.produce(self.context,self.ct_context)
        self.assertFalse(self.peer.calls)


if __name__=='__main__':unittest.main()
