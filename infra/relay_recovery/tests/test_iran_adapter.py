"""Iran adapter tests: fake child processes and sockets; no live mutations."""
from __future__ import annotations

import copy
import hashlib
import os
from pathlib import Path
import sys
import tempfile
import unittest

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import iran_adapter as mod
import protocol
import core


class IranAdapterTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.bundles = self.base / 'bundles'
        self.bundles.mkdir(mode=0o700)
        self.calls = []
        self.patch_result = None
        self.host_ok = True
        self.ct_ok = True
        self.vm_ok = True
        self.tcp_ok = True
        self.icmp_ok = False
        probes = {k: self.make_spec(k, k) for k in ('host', 'ct113', 'vm109')}
        recipes = {k + '-v1': self.make_spec(k + '-v1', k)
                   for k in ('stage', 'apply', 'rollback', 'accept', 'reconcile')}
        self.cfg_raw = dict(schema=mod.CONFIG_SCHEMA, agent_id='pve-ir',
                            manifest_dir=str(self.bundles), candidate_ports=[22, 443],
                            vm109_source_ip='10.10.10.50', probes=probes, recipes=recipes)
        self.cfg_path = self.base / 'config.json'
        self.write_config()
        bundle = dict(schema=mod.BUNDLE_SCHEMA, agent_id='pve-ir', candidate_ports=[22, 443],
                      recipes={k: k + '-v1' for k in ('stage', 'apply', 'rollback', 'accept', 'reconcile')})
        content = protocol.canonical_json(bundle)
        self.bundle_hash = hashlib.sha256(content).hexdigest()
        self.bundle_path = self.bundles / (self.bundle_hash + '.json')
        self.bundle_path.write_bytes(content)
        self.bundle_path.chmod(0o600)
        self.cfg = mod.load_config(self.cfg_path, root_owned=False)

    def make_spec(self, filename, action):
        path = self.base / filename
        content = ('reviewed local fixture ' + filename).encode()
        path.write_bytes(content)
        path.chmod(0o700)
        return dict(action=action, argv=[str(path)], executable_sha256=hashlib.sha256(content).hexdigest(),
                    inputs=[], timeout=6)

    def write_config(self):
        self.cfg_path.write_bytes(protocol.canonical_json(self.cfg_raw))
        self.cfg_path.chmod(0o600)

    def context(self, op='probe', target=None):
        return dict(schema=protocol.SCHEMA, agent_id='pve-ir', incident_id='incident-1',
                    generation=2, revision=4, config_hash=self.bundle_hash, jobs_hash='a' * 64,
                    job=dict(id=op + '-1', op=op,
                             target=target or dict(host='91.107.179.25', port=443, provider_id='173913007')))

    def runner(self, argv, data, timeout):
        self.calls.append((list(argv), protocol.decode_json(data), timeout))
        context = protocol.decode_json(data)
        action = Path(argv[0]).name.removesuffix('-v1')
        probe = action in ('host', 'ct113', 'vm109')
        checks = {}
        ok = True
        if action == 'host':
            checks = dict(host_healthy=self.host_ok, direct_control=self.host_ok, internet_healthy=self.host_ok)
        elif action == 'ct113':
            checks = dict(tunnel_ok=self.ct_ok, probe_ok=self.ct_ok, execution_origin='ct113',
                          egress_ip=context['job']['target']['host'])
        elif action == 'vm109':
            checks = dict(tunnel_ok=self.vm_ok, probe_ok=self.vm_ok, execution_origin='guest',
                          egress_ip=context['job']['target']['host'], source_ip='10.10.10.50')
        else:
            flag = {'stage': 'staged', 'apply': 'applied', 'rollback': 'rolled_back', 'accept': 'accepted', 'reconcile': 'generation_verified'}[action]
            checks = {flag: True, 'generation_verified': True}
        result = dict(schema=mod.PROBE_SCHEMA if probe else mod.RECIPE_SCHEMA,
                      ok=ok, checks=checks, read_only=probe or action in {'accept', 'reconcile'},
                      target=context['job']['target'], **{k: context[k] for k in mod.SCOPE})
        result['probe_kind' if probe else 'action'] = action
        if action == 'reconcile':
            result['reconciled'] = True
        if self.patch_result:
            self.patch_result(action, result)
        return 0, protocol.canonical_json(result)

    def adapter(self, runner=None):
        return mod.IranAdapter(self.cfg, runner=runner or self.runner,
                               tcp=lambda h, p: self.tcp_ok, icmp=lambda h: self.icmp_ok)

    def test_probe_reports_ct_path_and_guest_acceptance_icmp_is_supplementary(self):
        result = self.adapter().run('probe', self.context())
        self.assertTrue(result['ok'])
        self.assertFalse(result['checks']['candidate_icmp'])
        self.assertEqual(result['checks']['consumers'], {'proxmox': True, 'ct113': True, 'vm109': True})
        self.assertEqual({Path(c[0][0]).name for c in self.calls}, {'host', 'ct113', 'vm109'})

    def test_tcp_and_ping_alone_never_prove_tunnel(self):
        self.icmp_ok, self.ct_ok = True, False
        result = self.adapter().run('probe', self.context())
        self.assertFalse(result['ok'])
        self.assertFalse(result['checks']['tunnel_ok'])
        self.assertTrue(result['checks']['candidate_tcp'])

    def test_controller_resource_params_bind_target_and_missing_consumers_fail(self):
        ctx = self.context()
        ctx['job']['params'] = dict(resource_identity={
            'provider': 'hetzner', 'project_id': 'project-1',
            'provider_id': ctx['job']['target']['provider_id'], 'generation': 1},
            resource_config_hash='b' * 64,
            critical_consumers=['proxmox', 'vm109', 'vpn', 'dns', 'storage'])
        result = self.adapter().run('probe', ctx)
        self.assertTrue(result['ok'])
        self.assertEqual(result['checks']['critical_consumers'],
                         dict(proxmox=True, vm109=True, vpn=True, dns=False, storage=False))
        ctx['job']['params']['resource_identity']['provider_id'] = '999999'
        with self.assertRaisesRegex(protocol.ProtocolError, 'resource_identity'):
            self.adapter().run('probe', ctx)

    def test_resource_params_are_typed_and_bounded(self):
        valid = dict(resource_identity={'provider': 'hetzner', 'project_id': 'project-1',
                     'provider_id': '173913007', 'generation': 1}, resource_config_hash='b' * 64,
                     critical_consumers=['proxmox'])
        for key, bad in [('resource_identity', {'provider': 'other'}),
                         ('resource_config_hash', 'not-a-hash'),
                         ('critical_consumers', ['dns', 'dns']),
                         ('critical_consumers', 'dns')]:
            ctx = self.context()
            ctx['job']['params'] = dict(valid, **{key: bad})
            with self.subTest(key=key, bad=bad), self.assertRaises(protocol.ProtocolError):
                self.adapter().run('probe', ctx)

    def test_restore_params_must_be_exact_positive_epoch_and_sha_pair(self):
        ctx = self.context()
        ctx['job']['params'] = {'restore_epoch': 1, 'restore_hash': 'b' * 64}
        self.assertTrue(self.adapter().run('probe', ctx)['ok'])
        # Accepting authenticated typed data does not manufacture an OS restore
        # proof or add an epoch to a probe that never inspected that restore.
        self.assertNotIn('restore_epoch', self.adapter().run('probe', ctx)['checks'])
        for params in ({'restore_epoch': 1}, {'restore_hash': 'b' * 64},
                       {'restore_epoch': True, 'restore_hash': 'b' * 64},
                       {'restore_epoch': 0, 'restore_hash': 'b' * 64},
                       {'restore_epoch': 1, 'restore_hash': 'not-a-hash'}):
            ctx['job']['params'] = params
            with self.subTest(params=params), self.assertRaises(protocol.ProtocolError):
                self.adapter().run('probe', ctx)

    def test_explicit_dns_storage_probes_are_required_and_scope_bound(self):
        self.cfg_raw['consumer_probes'] = {name: self.make_spec(name, name) for name in ('dns', 'storage')}
        self.write_config()
        self.cfg = mod.load_config(self.cfg_path, root_owned=False)
        def child(argv, data, timeout):
            name = Path(argv[0]).name
            if name not in ('dns', 'storage'):
                return self.runner(argv, data, timeout)
            ctx = protocol.decode_json(data)
            result = dict(schema=mod.PROBE_SCHEMA, probe_kind=name, read_only=True, ok=True,
                          checks={'consumer': name, 'probe_ok': name == 'dns'}, target=ctx['job']['target'],
                          **{k: ctx[k] for k in mod.SCOPE})
            return 0, protocol.canonical_json(result)
        ctx = self.context()
        ctx['job']['params'] = {'critical_consumers': ['dns', 'storage']}
        result = self.adapter(runner=child).run('probe', ctx)
        self.assertEqual(result['checks']['critical_consumers'], {'dns': True, 'storage': False})

    def test_consumer_probe_cannot_override_fixed_tunnel_or_guest_acceptance(self):
        self.cfg_raw['consumer_probes'] = {'vm109': self.make_spec('override', 'vm109')}
        self.write_config()
        with self.assertRaisesRegex(protocol.ProtocolError, 'consumer_name'):
            mod.load_config(self.cfg_path, root_owned=False)

    def test_ct113_probe_cannot_impersonate_guest_source(self):
        def patch(action, result):
            if action == 'vm109':
                result['checks']['execution_origin'] = 'ct113'
        self.patch_result = patch
        result = self.adapter().run('probe', self.context())
        self.assertTrue(result['ok'])
        self.assertFalse(result['checks']['critical_consumers']['vm109'])
        self.assertFalse(self.adapter().run('accept', self.context('accept'))['ok'])

    def test_guest_source_and_egress_must_match(self):
        for key, wrong in (('source_ip', '10.10.10.20'), ('egress_ip', '85.9.108.114')):
            def patch(action, result):
                if action == 'vm109':
                    result['checks'][key] = wrong
            self.patch_result = patch
            result = self.adapter().run('probe', self.context())
            self.assertTrue(result['ok'])
            self.assertFalse(result['checks']['critical_consumers']['vm109'])
            self.assertFalse(self.adapter().run('accept', self.context('accept'))['ok'])

    def test_host_or_tcp_failure_fails_acceptance(self):
        self.host_ok = False
        self.assertFalse(self.adapter().run('probe', self.context())['ok'])
        self.host_ok, self.tcp_ok = True, False
        self.assertFalse(self.adapter().run('probe', self.context())['ok'])

    def test_future_candidate_target_is_data_not_a_command_or_preinstalled_ip(self):
        target = dict(host='1.1.1.1', port=443, provider_id='99999')
        ctx = self.context(target=target)
        result = self.adapter().run('probe', ctx)
        self.assertTrue(result['ok'])
        self.assertTrue(all(call[1]['job']['target'] == target for call in self.calls))
        self.assertTrue(all(call[0] == self.cfg['probes'][Path(call[0][0]).name]['argv'] for call in self.calls))

    def test_candidate_target_must_be_public_literal_and_allowlisted_port(self):
        for target in (dict(host='127.0.0.1', port=443, provider_id='1'),
                       dict(host='10.10.10.50', port=443, provider_id='1'),
                       dict(host='example.com', port=443, provider_id='1'),
                       dict(host='91.107.179.25;touch x', port=443, provider_id='1'),
                       dict(host='91.107.179.25', port=25, provider_id='1')):
            with self.assertRaises(protocol.ProtocolError):
                self.adapter().run('probe', self.context(target=target))
        self.assertFalse(self.calls)

    def test_network_command_and_arbitrary_params_rejected_before_child(self):
        for params in ({'argv': ['/bin/sh']}, {'command': 'uname -a'},
                       {'source_file': '/etc/shadow'}, {'checkpoint': '/tmp/evil'}):
            ctx = self.context('apply')
            ctx['job']['params'] = params
            with self.assertRaises(protocol.ProtocolError):
                self.adapter().run('apply', ctx)
        self.assertFalse(self.calls)

    def test_fixed_stage_apply_rollback_require_pinned_bundle_recipe(self):
        for action in ('stage', 'apply', 'rollback'):
            self.assertTrue(self.adapter().run(action, self.context(action))['ok'])
        self.assertEqual([Path(c[0][0]).name for c in self.calls], ['stage-v1', 'apply-v1', 'rollback-v1'])

    def test_missing_bundle_and_hash_drift_prevent_mutation(self):
        self.bundle_path.write_bytes(self.bundle_path.read_bytes() + b' ')
        with self.assertRaisesRegex(protocol.ProtocolError, 'adapter_bundle_hash'):
            self.adapter().run('apply', self.context('apply'))
        self.assertFalse(self.calls)

    def test_executable_is_rehashed_immediately_before_each_run(self):
        self.assertTrue(self.adapter().run('stage', self.context('stage'))['ok'])
        self.calls.clear()
        path = Path(self.cfg['recipes']['apply-v1']['argv'][0])
        path.write_bytes(b'replaced after config load')
        with self.assertRaisesRegex(protocol.ProtocolError, 'adapter_executable_hash'):
            self.adapter().run('apply', self.context('apply'))
        self.assertFalse(self.calls)

    def test_reconcile_only_runs_reconcile_recipe_and_is_read_only(self):
        result = self.adapter().run('reconcile', self.context('apply'))
        self.assertTrue(result['ok'])
        self.assertTrue(result['reconciled'])
        self.assertTrue(result['checks']['read_only'])
        self.assertEqual([Path(c[0][0]).name for c in self.calls], ['reconcile-v1'])

    def test_reconcile_refuses_mutating_receipt_and_does_not_retry_apply(self):
        self.patch_result = lambda action, result: result.update(read_only=False)
        with self.assertRaisesRegex(protocol.ProtocolError, 'adapter_read_only_required'):
            self.adapter().run('reconcile', self.context('apply'))
        self.assertEqual([Path(c[0][0]).name for c in self.calls], ['reconcile-v1'])

    def test_unknown_reconciliation_stays_unresolved(self):
        self.patch_result = lambda action, result: result.update(reconciled=False)
        result = self.adapter().run('reconcile', self.context('apply'))
        self.assertFalse(result['ok'])
        self.assertFalse(result['reconciled'])

    def test_accept_requires_recipe_state_and_real_probes(self):
        self.assertTrue(self.adapter().run('accept', self.context('accept'))['ok'])
        self.vm_ok = False
        self.assertFalse(self.adapter().run('accept', self.context('accept'))['ok'])

    def test_receipt_scope_or_target_mismatch_is_not_success(self):
        for field, value in (('generation', 99), ('config_hash', 'f' * 64),
                             ('target', dict(host='8.8.8.8', port=443, provider_id='8'))):
            self.patch_result = lambda action, result: result.update({field: value})
            with self.assertRaisesRegex(protocol.ProtocolError, 'adapter_child_receipt'):
                self.adapter().run('apply', self.context('apply'))

    def test_exit_zero_and_ok_alone_cannot_claim_mutation_applied(self):
        self.patch_result = lambda action, result: result.update(checks={})
        self.assertFalse(self.adapter().run('apply', self.context('apply'))['ok'])

    def test_recipe_action_must_match_bundle_operation(self):
        self.cfg['recipes']['apply-v1']['action'] = 'rollback'
        with self.assertRaisesRegex(protocol.ProtocolError, 'adapter_spec_action'):
            self.adapter().run('apply', self.context('apply'))
        self.assertFalse(self.calls)

    def test_missing_guest_probe_blocks_acceptance_without_failing_relay_path(self):
        def broken(argv, data, timeout):
            if Path(argv[0]).name == 'vm109':
                raise protocol.ProtocolError('adapter_timeout')
            return self.runner(argv, data, timeout)
        result = self.adapter(broken).run('probe', self.context())
        self.assertTrue(result['ok'])
        self.assertTrue(result['checks']['tunnel_ok'])
        self.assertTrue(result['checks']['path_probe_ok'])
        self.assertFalse(result['checks']['consumers']['vm109'])
        self.assertFalse(self.adapter(broken).run('accept', self.context('accept'))['ok'])

    def _core(self, **policy):
        cfg = core.Config(enabled=True, owner_id='1', service_id='711', project_id='p', agent_id='pve-ir',
                          critical_consumers=('proxmox', 'vm109', 'vpn'), **policy)
        journal = core.Journal(self.base / 'core.sqlite3')
        now = 1_800_000_000
        journal.initialize(dict(provider='hetzner', project_id='p', provider_id='173913007',
                                generation=1, config_hash=self.bundle_hash), now)
        return cfg, core.Engine(cfg, journal), now

    def _core_evidence(self, cfg, engine, now, *, active_ok=True, candidate_probe=None):
        state = engine.snapshot()
        def scoped(resource, **extra):
            return dict(verified=True, received_at=now, incident_id=state['incident_id'],
                        generation=resource['generation'], config_hash=resource['config_hash'], **extra)
        def health(resource, path_ok):
            return scoped(resource, agent_id='pve-ir', direct_control=True, host_healthy=True,
                          internet_healthy=True, tunnel_ok=path_ok, probe_ok=path_ok)
        evidence = dict(health=health(state['active'], active_ok), preflight=dict(verified=True,
            received_at=now, owner_id='1', service_id='711', project_id='p', ready=True,
            checks={name: True for name in cfg.required_preflight}), inventory=dict(verified=True,
            received_at=now, complete=True, managed_vm_count=1, project_id='p', active_identity=core.identity(state['active'])),
            options=[dict(verified=True, received_at=now, country='DE', location='fsn1', available=True,
                compatible=True, provider='hetzner', project_id='p', owner_id='1', service_id='711',
                currency='EUR', architecture='x86', config_hash=self.bundle_hash, server_type='cx23',
                image='ubuntu-x86', monthly_gross_micro=5_000_000, trial_max_gross_micro=100_000)])
        if candidate_probe is not None:
            candidate = state['candidate']
            evidence.update(candidate_health=health(candidate, candidate_probe['ok']),
                critical_receipts=[scoped(candidate, consumer=name,
                    ok=candidate_probe['checks']['critical_consumers'].get(name) is True) for name in cfg.critical_consumers],
                backup=scoped(candidate, checkpoint='backup-2', durable=True, restore_verified=True),
                fencing=scoped(candidate, old_generation=state['active']['generation'], verified_fenced=True))
        return evidence

    def test_healthy_ct_and_missing_qga_never_create_outage_after_three_hours(self):
        self.vm_ok = False
        cfg, engine, now = self._core()
        for minute in range(182):
            result = self.adapter().run('probe', self.context())
            self.assertTrue(result['ok'])
            self.assertFalse(result['checks']['critical_consumers']['vm109'])
            evidence = self._core_evidence(cfg, engine, now, active_ok=result['ok'])
            engine.observe(evidence, now)
            self.assertEqual(engine.plan(engine.snapshot(), evidence, now).kind, 'HOLD')
            self.assertIsNone(engine.snapshot()['incident_id'])
            now += 60
        self.assertEqual(engine.snapshot()['health']['quality'], 'ok')

    def test_missing_guest_acceptance_blocks_core_promotion_with_healthy_candidate_path(self):
        self.vm_ok = False
        cfg, engine, now = self._core(outage_seconds=180, suspect_seconds=60, candidate_seconds=60)
        for _ in range(7):
            evidence = self._core_evidence(cfg, engine, now, active_ok=False)
            engine.observe(evidence, now)
            now += 30
        evidence = self._core_evidence(cfg, engine, now, active_ok=False)
        intent = engine.plan(engine.snapshot(), evidence, now)
        self.assertEqual(intent.kind, 'CREATE')
        engine.commit_intent(intent, evidence, now)
        engine.record_effect(intent.id, dict(intent_id=intent.id, kind='CREATE', outcome='created',
            verified=True, received_at=now, create_intent_id=intent.id,
            identity=dict(provider='hetzner', project_id='p', provider_id='9999', generation=2)), now)
        ctx = self.context(target=dict(host='1.1.1.1', port=443, provider_id='9999'))
        ctx['job']['params'] = {'critical_consumers': list(cfg.critical_consumers)}
        for _ in range(4):
            probe = self.adapter().run('probe', ctx)
            self.assertTrue(probe['ok'])
            evidence = self._core_evidence(cfg, engine, now, active_ok=False, candidate_probe=probe)
            engine.observe(evidence, now)
            now += 30
        evidence = self._core_evidence(cfg, engine, now, active_ok=False, candidate_probe=probe)
        blocked = engine.plan(engine.snapshot(), evidence, now)
        self.assertEqual(blocked.kind, 'HOLD')
        self.assertEqual(blocked.reason, 'CANDIDATE_VALIDATION')
        # Positive control: the already verified path/data gates are sufficient;
        # only a real successful VM109 consumer receipt releases promotion.
        self.vm_ok = True
        probe = self.adapter().run('probe', ctx)
        evidence = self._core_evidence(cfg, engine, now, active_ok=False, candidate_probe=probe)
        self.assertEqual(engine.plan(engine.snapshot(), evidence, now).kind, 'PROMOTE')

    def test_oversized_child_output_is_bounded(self):
        with self.assertRaisesRegex(protocol.ProtocolError, 'adapter_child_output'):
            self.adapter(lambda argv, data, timeout: (0, b'x' * 8193)).run('apply', self.context('apply'))

    def test_config_symlink_or_insecure_mode_is_rejected(self):
        link = self.base / 'config-link'
        link.symlink_to(self.cfg_path)
        with self.assertRaises(protocol.ProtocolError):
            mod.load_config(link, root_owned=False)
        self.cfg_path.chmod(0o644)
        with self.assertRaises(protocol.ProtocolError):
            mod.load_config(self.cfg_path, root_owned=False)

    def test_pinned_interpreter_script_and_operand_are_required(self):
        interpreter = self.base / 'python3.12'
        interpreter.write_bytes(b'reviewed interpreter')
        interpreter.chmod(0o700)
        script = self.base / 'probe.py'
        script.write_bytes(b'reviewed script')
        script.chmod(0o600)
        spec = dict(action='host', argv=[str(interpreter), str(script)],
                    executable_sha256=hashlib.sha256(interpreter.read_bytes()).hexdigest(),
                    inputs=[], timeout=6)
        with self.assertRaisesRegex(protocol.ProtocolError, 'adapter_unpinned_operand'):
            mod._spec(spec, 'host', False, probe=True)
        spec['inputs'] = [dict(path=str(script), sha256=hashlib.sha256(script.read_bytes()).hexdigest())]
        self.assertEqual(mod._spec(spec, 'host', False, probe=True)['argv'], spec['argv'])
        script.write_bytes(b'modified script')
        with self.assertRaisesRegex(protocol.ProtocolError, 'adapter_input_hash'):
            mod._spec(spec, 'host', False, probe=True)

    def test_shell_and_interpreter_command_text_are_forbidden(self):
        for argv in (['/bin/bash', '-c', 'true'], ['/usr/bin/python3.12', '-c', 'print(1)'],
                     ['/usr/bin/python3.12', '-m', 'other']):
            spec = dict(action='host', argv=argv, executable_sha256='a' * 64, inputs=[], timeout=6)
            with self.assertRaisesRegex(protocol.ProtocolError, 'adapter_shell_forbidden'):
                mod._spec(spec, 'host', False, probe=True)

    def test_code_symlink_or_writable_file_is_refused(self):
        path = Path(self.cfg['recipes']['apply-v1']['argv'][0])
        path.chmod(0o722)
        with self.assertRaises(protocol.ProtocolError):
            self.adapter().run('apply', self.context('apply'))
        path.chmod(0o700)
        link = self.base / 'linked-code'
        link.symlink_to(path)
        self.cfg['recipes']['apply-v1']['argv'] = [str(link)]
        with self.assertRaises(protocol.ProtocolError):
            self.adapter().run('apply', self.context('apply'))


if __name__ == '__main__':
    unittest.main()
