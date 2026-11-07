"""Only disposable private files, synthetic socket metadata, and copied caller guards."""
import ast
import hashlib
import importlib.util
import os
from pathlib import Path
import pwd
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
SOURCE = (HERE / 'private-fpm-activation.py').read_text()
PHP = (HERE / 'private-fpm-activation.php').read_bytes()


class PrivatePathGuards(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='private-fpm-guards-')
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.stage = self.home / 'servernet_app/storage/app/private/arvan-delivery-20261103-v3q4m7dz'
        self.stage.mkdir(mode=0o700, parents=True)
        (self.home / 'public_html').mkdir(mode=0o755)
        # Rewrite only copied fixture identity guards; the native caller is never executed.
        code = SOURCE.replace("Path('/home/servernetcloud')", 'Path(' + repr(str(self.home)) + ')')
        code = code.replace('os.getuid() == 0', 'False')
        code = code.replace("!= 'servernetcloud'", '!= ' + repr(pwd.getpwuid(__import__('os').getuid()).pw_name))
        caller = self.stage / 'private-fpm-activation.py'
        caller.write_text(code); caller.chmod(0o600)
        self.primary = self.stage / 'private-fpm-activation.php'
        self.primary.write_bytes(PHP); self.primary.chmod(0o600)
        spec = importlib.util.spec_from_file_location('fixture_private_caller', caller)
        self.client = importlib.util.module_from_spec(spec); spec.loader.exec_module(self.client)
        self.home_patch = patch.object(self.client.Path, 'home', return_value=self.home)
        self.home_patch.start(); self.addCleanup(self.home_patch.stop)
        self.socket_path = self.home / 'owned.sock'; self.socket_path.write_bytes(b'synthetic socket metadata')
        original_lstat = Path.lstat
        def synthetic_socket_metadata(path):
            observed = original_lstat(path)
            if path == self.socket_path:
                values = list(observed); values[0] = stat.S_IFSOCK | stat.S_IMODE(observed.st_mode)
                return os.stat_result(values)
            return observed
        self.stat_patch = patch.object(Path, 'lstat', synthetic_socket_metadata)
        self.stat_patch.start(); self.addCleanup(self.stat_patch.stop)

    def test_native_source_preserves_exact_private_identity_and_root_refusal(self):
        ast.parse(SOURCE)
        self.assertIn('os.getuid() == 0', SOURCE)
        self.assertIn("pwd.getpwuid(os.getuid()).pw_name != 'servernetcloud'", SOURCE)
        self.assertEqual(hashlib.sha256(PHP).hexdigest(), self.client.PRIMARY_SHA256)
        self.assertEqual('private-stage-fcgi-v1', self.client.TRANSPORT)
        self.assertEqual('/__servernet_private_arvan_release_20261103_v3q4m7dz.php', self.client.SCRIPT_NAME)
        self.assertNotIn('_arvan_delivery_20261103_v3q4m7dz/activate.php', SOURCE)
        self.assertNotIn('with_name(\'fpm-activation.php\')', SOURCE)

    def test_private_fixture_with_synthetic_owned_socket_metadata_passes(self):
        self.client.safety(self.socket_path)

    def test_public_primary_and_public_stage_are_refused(self):
        with patch.object(self.client, 'PRIMARY', self.home / 'public_html/private-fpm-activation.php'):
            with self.assertRaisesRegex(RuntimeError, 'bounds_invalid'):
                self.client.safety(self.socket_path)
        public = self.home / 'public_html'
        with patch.object(self.client, 'DIRECTORY', public), patch.object(self.client, 'PRIMARY', public / 'private-fpm-activation.php'), patch.object(self.client, 'CALLER', public / 'private-fpm-activation.py'):
            with self.assertRaisesRegex(RuntimeError, 'bounds_invalid'):
                self.client.safety(self.socket_path)

    def test_primary_mode_symlink_and_hardlink_are_refused(self):
        self.primary.chmod(0o644)
        with self.assertRaisesRegex(RuntimeError, 'permissions_invalid'):
            self.client.safety(self.socket_path)
        self.primary.chmod(0o600)
        target = self.stage / 'other.php'; self.primary.rename(target); self.primary.symlink_to(target)
        with self.assertRaisesRegex(RuntimeError, 'identity_invalid'):
            self.client.safety(self.socket_path)
        self.primary.unlink(); target.rename(self.primary)
        __import__('os').link(self.primary, self.stage / 'hardlink.php')
        with self.assertRaisesRegex(RuntimeError, 'file_invalid'):
            self.client.safety(self.socket_path)

    def test_same_file_php_edits_and_public_caller_mode_fail_closed(self):
        self.primary.write_bytes(PHP + b'\n// drift\n')
        with self.assertRaisesRegex(RuntimeError, 'hash_drift'):
            self.client.safety(self.socket_path)
        self.primary.write_bytes(PHP)
        self.client.CALLER.chmod(0o644)
        with self.assertRaisesRegex(RuntimeError, 'permissions_invalid'):
            self.client.safety(self.socket_path)

    def test_stage_mode_and_account_mismatch_are_refused(self):
        self.stage.chmod(0o755)
        with self.assertRaisesRegex(RuntimeError, 'permissions_invalid'):
            self.client.safety(self.socket_path)
        self.stage.chmod(0o700)
        with patch.object(self.client.pwd, 'getpwuid', return_value=type('WrongAccount', (), {'pw_name': 'other-account'})()):
            with self.assertRaisesRegex(RuntimeError, 'unexpected_account'):
                self.client.safety(self.socket_path)

    def test_non_socket_and_socket_alias_are_refused(self):
        ordinary = self.home / 'not.sock'; ordinary.write_bytes(b'not a socket')
        with self.assertRaisesRegex(RuntimeError, 'socket_not_owned'):
            self.client.safety(ordinary)
        alias = self.home / 'alias.sock'; alias.symlink_to(self.socket_path)
        with self.assertRaisesRegex(RuntimeError, 'invalid_socket_path'):
            self.client.safety(alias)

    def test_each_response_binding_is_required_including_slot_and_plan(self):
        plan = 'b' * 64
        result = {'release': self.client.RELEASE, 'action': 'observe', 'host': 'servernet.cloud',
                  'plan_sha256': plan, 'transport_variant': self.client.TRANSPORT,
                  'primary_path': str(self.client.PRIMARY), 'primary_sha256': self.client.PRIMARY_SHA256}
        self.client.validate_response(result, 'observe', 'servernet.cloud', plan)
        for key in result:
            with self.subTest(key=key):
                changed = dict(result); changed[key] = 'wrong'
                with self.assertRaisesRegex(RuntimeError, 'binding_mismatch'):
                    self.client.validate_response(changed, 'observe', 'servernet.cloud', plan)
                changed.pop(key)
                with self.assertRaisesRegex(RuntimeError, 'binding_mismatch'):
                    self.client.validate_response(changed, 'observe', 'servernet.cloud', plan)


if __name__ == '__main__':
    unittest.main()
