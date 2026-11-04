"""Pinned CLI enrollment with real guest HMAC/replay; no network or cloud calls."""
import copy
import hashlib
import io
import os
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest import mock

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import iran_adapter as adapter
import isolated_verifier_enrollment as mod
import protocol
import windows_probe_client as windows
import test_isolated_acceptance as proof_tests
import test_iran_adapter as adapter_tests


class VerifierEnrollmentTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.proofs = proof_tests.IsolatedAcceptanceTests()
        self.proofs.setUp()
        self.addCleanup(self.proofs.doCleanups)
        self.key_path = self.root / 'guest.key'
        self.key_path.write_bytes(self.proofs.key)
        self.key_path.chmod(0o600)
        self.state = self.root / 'verifier-state'
        self.state.mkdir(mode=0o700)
        self.path = self.root / 'enrollment.json'
        self.enrollment = dict(schema=mod.SCHEMA, enabled=True, agent_id='ir-agent',
            guest_key_file=str(self.key_path), guest_key_sha256=hashlib.sha256(self.proofs.key).hexdigest(),
            state_dir=str(self.state), proxy_binding_sha256='f' * 64,
            access_policy=windows.DEFAULT_ACCESS_POLICY)
        self.publish()

    def publish(self, raw=None):
        raw = protocol.canonical_json(self.enrollment) if raw is None else raw
        self.path.write_bytes(raw)
        self.path.chmod(0o600)
        self.reference = dict(path=str(self.path), sha256=hashlib.sha256(raw).hexdigest())

    def load(self, **options):
        return mod.load_verifier(self.reference, agent_id='ir-agent', root_owned=False,
                                 now=lambda: self.proofs.now, **options)

    def test_explicit_enrollment_checks_real_hmac_and_persists_replay_across_reload(self):
        verifier = self.load()
        result = verifier.verify(self.proofs.context, self.proofs.recipe)
        self.assertTrue(result['verified'])
        self.assertFalse(result['default_path_verified'])
        self.assertFalse(result['global_cutover_performed'])
        self.assertTrue((self.state / mod.REPLAY_NAME).is_file())
        self.assertNotIn(self.proofs.key.hex(), str(result))
        self.assertNotIn(self.proofs.key.decode(), str(result))
        with self.assertRaisesRegex(protocol.ProtocolError, 'vm_replay'):
            self.load().verify(self.proofs.context, self.proofs.recipe)

    def test_absent_or_disabled_enrollment_has_no_key_or_replay_side_effect(self):
        self.assertIsNone(mod.load_verifier(None, agent_id='ir-agent'))
        self.enrollment = dict(schema=mod.SCHEMA, enabled=False, agent_id='ir-agent')
        self.publish()
        self.key_path.unlink()
        self.state.rmdir()
        self.assertIsNone(self.load())
        self.assertFalse(self.state.exists())
        self.enrollment['state_dir'] = str(self.state)
        self.publish()
        with self.assertRaisesRegex(protocol.ProtocolError, 'disabled_fields'):
            self.load()

    def test_missing_or_wrong_pin_unknown_fields_identity_and_boolean_types_fail(self):
        for change in ({'enabled': 1}, {'agent_id': 'other-agent'}, {'unexpected': True},
                       {'schema': 'other-schema'}, {'access_policy': 'unscoped'}):
            value = copy.deepcopy(self.enrollment)
            self.enrollment.update(change)
            self.publish()
            with self.subTest(change=change), self.assertRaises(protocol.ProtocolError):
                self.load()
            self.enrollment = value
        self.publish()
        self.reference['sha256'] = '0' * 64
        with self.assertRaisesRegex(protocol.ProtocolError, 'enrollment_pin'):
            self.load()
        self.reference = dict(path=str(self.path), sha256='0' * 64, enabled=True)
        with self.assertRaisesRegex(protocol.ProtocolError, 'enrollment_reference'):
            self.load()

    def test_noncanonical_duplicate_json_oversize_and_secret_key_size_fail(self):
        for raw in (b'{"enabled":true,"enabled":false}',
                    b'{ "schema":"servernet.relay.isolated-verifier.v1" }', b' ' * 4097):
            self.publish(raw)
            with self.subTest(raw=raw[:30]), self.assertRaises(protocol.ProtocolError):
                self.load()
        for key in (b'k' * 31, b'k' * 33):
            self.key_path.write_bytes(key)
            self.enrollment['guest_key_sha256'] = hashlib.sha256(key).hexdigest()
            self.publish()
            with self.subTest(length=len(key)), self.assertRaises(protocol.ProtocolError):
                self.load()

    def test_wrong_key_pin_and_file_or_state_permission_drift_fail(self):
        self.key_path.write_bytes(b'k' * 32)
        with self.assertRaisesRegex(protocol.ProtocolError, 'enrollment_pin'):
            self.load()
        self.key_path.write_bytes(self.proofs.key)
        for path in (self.path, self.key_path, self.state):
            previous = path.stat().st_mode & 0o777
            path.chmod(0o755)
            with self.subTest(path=path.name), self.assertRaises(protocol.ProtocolError):
                self.load()
            path.chmod(previous)

    def test_missing_state_is_not_created_and_symlink_or_hardlink_aliases_fail(self):
        self.state.rmdir()
        with self.assertRaises(OSError):
            self.load()
        self.assertFalse(self.state.exists())
        self.state.mkdir(mode=0o700)
        link = self.root / 'key-link'
        link.symlink_to(self.key_path)
        self.enrollment['guest_key_file'] = str(link)
        self.publish()
        with self.assertRaisesRegex(protocol.ProtocolError, 'private_file_type'):
            self.load()
        self.enrollment['guest_key_file'] = str(self.key_path)
        self.publish()
        os.link(self.key_path, self.state / mod.REPLAY_NAME)
        with self.assertRaises(protocol.ProtocolError):
            self.load()

    def test_pin_key_proxy_policy_and_replay_cannot_drift_before_acceptance(self):
        verifier = self.load()
        self.key_path.write_bytes(b'z' * 32)
        with self.assertRaisesRegex(protocol.ProtocolError, 'enrollment_pin'):
            verifier.verify(self.proofs.context, self.proofs.recipe)
        self.key_path.write_bytes(self.proofs.key)
        self.path.write_bytes(b'{}')
        with self.assertRaisesRegex(protocol.ProtocolError, 'enrollment_pin'):
            verifier.verify(self.proofs.context, self.proofs.recipe)
        self.publish()
        context = copy.deepcopy(self.proofs.context)
        context['job']['params']['proxy_binding_sha256'] = '0' * 64
        with self.assertRaisesRegex(protocol.ProtocolError, 'enrollment_scope'):
            verifier.verify(context, self.proofs.recipe)
        context = copy.deepcopy(self.proofs.context)
        context['agent_id'] = 'other-agent'
        with self.assertRaisesRegex(protocol.ProtocolError, 'enrollment_scope'):
            verifier.verify(context, self.proofs.recipe)
        replay = self.state / mod.REPLAY_NAME
        replay.unlink()
        replay.symlink_to(self.key_path)
        with self.assertRaisesRegex(protocol.ProtocolError, 'private_file_type'):
            verifier.verify(self.proofs.context, self.proofs.recipe)

    def test_source_hmac_policy_is_verified_before_consuming_guest_nonce(self):
        self.enrollment['access_policy'] = windows.SOURCE_HMAC_POLICY
        self.publish()
        verifier = self.load()
        with self.assertRaisesRegex(protocol.ProtocolError, 'guest_policy'):
            verifier.verify(self.proofs.context, self.proofs.recipe)
        body = self.proofs.guest_body()
        body['access_policy'] = windows.SOURCE_HMAC_POLICY
        recipe = copy.deepcopy(self.proofs.recipe)
        recipe['isolated_evidence']['guest'] = self.proofs.signed_guest(body)
        self.assertTrue(verifier.verify(self.proofs.context, recipe)['verified'])

    def test_missing_or_corrupt_replay_after_loading_is_a_safe_failure(self):
        verifier = self.load()
        replay = self.state / mod.REPLAY_NAME
        replay.unlink()
        with self.assertRaisesRegex(protocol.ProtocolError, 'replay_unavailable'):
            verifier.verify(self.proofs.context, self.proofs.recipe)
        replay.write_bytes(b'not a database')
        replay.chmod(0o600)
        with self.assertRaisesRegex(protocol.ProtocolError, 'replay_unavailable'):
            verifier.verify(self.proofs.context, self.proofs.recipe)

    def test_replaced_inode_between_validation_and_open_is_rejected(self):
        real_open = os.open
        changed = False

        def replace_before_open(path, flags, *args, **kwargs):
            nonlocal changed
            if Path(path) == self.path and not changed:
                changed = True
                replacement = self.root / 'replacement.json'
                replacement.write_bytes(self.path.read_bytes())
                replacement.chmod(0o600)
                os.replace(replacement, self.path)
            return real_open(path, flags, *args, **kwargs)

        with mock.patch.object(mod.os, 'open', side_effect=replace_before_open):
            with self.assertRaisesRegex(protocol.ProtocolError, 'file_changed'):
                self.load()


class AdapterEnrollmentIntegrationTests(unittest.TestCase):
    def setUp(self):
        self.fixture = adapter_tests.IranAdapterTests()
        self.fixture.setUp()
        self.addCleanup(self.fixture.doCleanups)
        self.enrollment = VerifierEnrollmentTests()
        self.enrollment.setUp()
        self.addCleanup(self.enrollment.doCleanups)
        self.proofs = self.enrollment.proofs
        self.proofs.context = self.fixture.context('accept',
            target=dict(host='9.9.9.9', port=22, provider_id='51'))
        self.proofs.context['agent_id'] = 'pve-ir'
        self.proofs.context['job']['params'] = dict(acceptance_profile='isolated_candidate_v1',
            stage_profile='trial', expected_registry_hash='d' * 64,
            dependency_baseline_sha256='e' * 64, proxy_binding_sha256='f' * 64,
            resource_identity=dict(provider='hetzner', project_id='project-1',
                provider_id=self.proofs.context['job']['target']['provider_id'], generation=2),
            resource_config_hash='a' * 64)
        self.enrollment.enrollment['agent_id'] = 'pve-ir'
        self.enrollment.publish()
        self.fixture.cfg['isolated_verifier'] = self.enrollment.reference

        def child(action, result):
            if action == 'accept':
                result['isolated_evidence'] = self.proofs.receipt()['isolated_evidence']
        self.fixture.patch_result = child

    def build(self, action='accept', context=None):
        return adapter.build_adapter(self.fixture.cfg, action=action,
            context=self.proofs.context if context is None else context,
            runner=self.fixture.runner, now=lambda: self.proofs.now)

    def test_explicit_cli_builder_wires_real_pinned_verifier_and_returns_only_isolated_proof(self):
        result = self.build().run('accept', self.proofs.context)
        self.assertTrue(result['ok'])
        self.assertTrue(result['checks']['isolated_candidate']['verified'])
        self.assertFalse(result['checks']['default_path_verified'])
        self.assertEqual(['accept-v1'], [Path(c[0][0]).name for c in self.fixture.calls])

    def test_normal_probe_ignores_absent_or_broken_optional_enrollment(self):
        self.fixture.cfg['isolated_verifier'] = {'path': '/unavailable', 'sha256': '0' * 64}
        context = self.fixture.context('probe')
        instance = self.build('probe', context)
        self.assertIsNone(instance.isolated_verifier)
        instance.tcp = lambda host, port: True
        instance.icmp = lambda host: False
        self.assertTrue(instance.run('probe', context)['ok'])
        self.assertFalse((self.enrollment.state / mod.REPLAY_NAME).exists())

    def test_absent_enrollment_remains_hold_without_default_path_fallback(self):
        self.fixture.cfg.pop('isolated_verifier')
        result = self.build().run('accept', self.proofs.context)
        self.assertFalse(result['ok'])
        self.assertEqual('isolated_verifier_not_enrolled', result['code'])
        self.assertEqual(['accept-v1'], [Path(c[0][0]).name for c in self.fixture.calls])

    def test_context_rejected_before_enrollment_key_or_replay_open(self):
        context = copy.deepcopy(self.proofs.context)
        context['agent_id'] = 'other-agent'
        with self.assertRaisesRegex(protocol.ProtocolError, 'context_identity'):
            self.build(context=context)
        self.assertFalse((self.enrollment.state / mod.REPLAY_NAME).exists())
        self.assertFalse(self.fixture.calls)

    def test_main_uses_enrolled_builder_without_network_or_secret_output(self):
        real_build = adapter.build_adapter
        output = io.BytesIO()

        def build(config, **options):
            return real_build(config, **options, runner=self.fixture.runner, now=lambda: self.proofs.now)

        with mock.patch.object(adapter.os, 'geteuid', return_value=0), \
             mock.patch.object(adapter, 'load_config', return_value=self.fixture.cfg), \
             mock.patch.object(adapter, 'build_adapter', side_effect=build), \
             mock.patch.object(adapter.sys, 'stdin', types.SimpleNamespace(buffer=io.BytesIO(protocol.canonical_json(self.proofs.context)))), \
             mock.patch.object(adapter.sys, 'stdout', types.SimpleNamespace(buffer=output)):
            code = adapter.main(['--config', '/private/adapter.json', 'accept'])
        self.assertEqual(0, code)
        result = protocol.decode_json(output.getvalue())
        self.assertTrue(result['ok'])
        self.assertNotIn(self.proofs.key, output.getvalue())


if __name__ == '__main__':
    unittest.main()
