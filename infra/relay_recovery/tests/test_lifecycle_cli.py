"""Offline forced-command dispatcher tests; no source or provider operations."""
import contextlib
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

INFRA = Path(__file__).resolve().parents[2]
if str(INFRA) not in sys.path: sys.path.insert(0, str(INFRA))
from relay_recovery import lifecycle_cli as cli


class DispatcherTests(unittest.TestCase):
    def setUp(self):
        self.output = io.BytesIO()
        self.base = mock.Mock()
        self.base.inspect.return_value = {'ok': True, 'operation': 'inspect'}
        self.base.restore_plan.return_value = {'ok': True, 'operation': 'restore-plan'}
        self.life = mock.Mock()
        self.life.run.return_value = {'ok': True, 'operation': 'fence'}
        self.stack = contextlib.ExitStack(); self.addCleanup(self.stack.close)
        self.base_config = self.stack.enter_context(mock.patch.object(cli.relay_side, 'load_config', return_value={'fake': True}))
        self.parse = self.stack.enter_context(mock.patch.object(cli.relay_side, 'request_from_bytes', side_effect=lambda raw, cfg: json.loads(raw)))
        self.base_factory = self.stack.enter_context(mock.patch.object(cli.relay_side, 'RelaySide', return_value=self.base))
        self.life_config = self.stack.enter_context(mock.patch.object(cli.lifecycle, 'load_config', return_value={'signed': True}))
        self.life_factory = self.stack.enter_context(mock.patch.object(cli.lifecycle, 'Lifecycle', return_value=self.life))

    def send(self, request):
        return cli.dispatch(json.dumps(request).encode(), self.output)

    def test_legacy_inspect_status_and_plan_use_only_fixed_base_config(self):
        for op in ('inspect', 'status', 'restore-plan'):
            self.assertEqual(0, self.send({'op': op, 'request_id': 'read'}))
        self.base_config.assert_called_with(cli.BASE_CONFIG)
        self.life_config.assert_not_called(); self.life_factory.assert_not_called()
        self.assertEqual(2, self.base.inspect.call_count)
        self.base.restore_plan.assert_called_once_with('read')

    def test_legacy_export_remains_binary_artifact_only(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp)/'fake.tar.gz'; path.write_bytes(b'\x1f\x8bfull-private-artifact')
            @contextlib.contextmanager
            def artifact(request_id): yield path, {'not': 'stdout'}
            self.base.export_artifact.side_effect = artifact
            self.assertEqual(0, self.send({'op': 'export', 'request_id': 'archive'}))
            self.assertEqual(path.read_bytes(), self.output.getvalue())
        self.life_factory.assert_not_called()

    def test_signed_schema_selects_lifecycle_without_base_parser(self):
        request = {'schema': cli.lifecycle.REQUEST_SCHEMA, 'op': 'fence', 'signature': 'fake'}
        self.assertEqual(0, self.send(request))
        self.life.run.assert_called_once_with(request)
        self.base_config.assert_not_called(); self.parse.assert_not_called()
        self.assertEqual({'ok': True, 'operation': 'fence'}, json.loads(self.output.getvalue()))

    def test_lifecycle_failure_never_falls_back_to_unsigned_base(self):
        self.life.run.side_effect = cli.lifecycle.LifecycleError('request_authentication')
        with self.assertRaises(cli.lifecycle.LifecycleError):
            self.send({'schema': cli.lifecycle.REQUEST_SCHEMA, 'op': 'fence'})
        self.base_factory.assert_not_called()
        self.assertEqual(b'', self.output.getvalue())

    def test_unsigned_mutation_and_unknown_schema_never_dispatch(self):
        with self.assertRaisesRegex(cli.lifecycle.LifecycleError, 'unsigned_mutation_disabled'):
            self.send({'op': 'fence', 'request_id': 'unsigned'})
        self.base.fence.assert_not_called(); self.base_factory.assert_not_called()
        with self.assertRaisesRegex(cli.lifecycle.LifecycleError, 'request_schema'):
            self.send({'schema': 'unknown', 'op': 'inspect'})
        self.life_factory.assert_not_called()

    def test_duplicate_schema_and_oversize_never_construct_helpers(self):
        for raw in (b'{"schema":"wrong","schema":"servernet.relay.lifecycle.request.v1"}', b'x'*(cli.lifecycle.MAX_BODY+1)):
            with self.assertRaises(cli.lifecycle.LifecycleError): cli.dispatch(raw, self.output)
        self.base_factory.assert_not_called(); self.life_factory.assert_not_called()

    def test_legacy_binding_uses_current_trusted_adopted_guard_generation(self):
        with mock.patch.object(cli.lifecycle.Path,'exists',return_value=True), mock.patch.object(cli.lifecycle.guard,'load_identity',return_value={'generation':2,'config_hash':'a'*64}):
            self.send({'op':'inspect','request_id':'current'})
        self.assertEqual(2,self.parse.call_args.args[1]['generation'])
        self.assertEqual('a'*64,self.parse.call_args.args[1]['config_hash'])

    def test_present_invalid_guard_never_falls_back_to_stale_legacy_binding(self):
        with mock.patch.object(cli.lifecycle.Path,'exists',return_value=True), mock.patch.object(cli.lifecycle.guard,'load_identity',side_effect=cli.lifecycle.guard.GuardError('identity_invalid')):
            with self.assertRaises(cli.lifecycle.guard.GuardError): self.send({'op':'inspect','request_id':'blocked'})
        self.parse.assert_not_called(); self.base_factory.assert_not_called()


if __name__ == '__main__': unittest.main()
