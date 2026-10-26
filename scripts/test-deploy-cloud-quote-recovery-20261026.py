#!/usr/bin/env python3
"""Three focused offline release checks; no application bootstrap or network."""
import hashlib
import importlib.util
import os
from pathlib import Path
import shutil
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('quote_recovery_wrapper', Path(__file__).with_name('deploy-cloud-quote-recovery-20261026.py'))
wrapper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wrapper)
PHP = os.environ.get('PHP_BIN') or shutil.which('php') or '/opt/cpanel/ea-php84/root/usr/bin/php'
TARGET = 'a' * 40
RELATIVE = 'app/Services/Cloud/CloudCatalogRepricer.php'
BEFORE = b'<?php\n$first = 1;\n' + b'// stable context\n' * 10 + b'$last = 1;\n'
AFTER = BEFORE.replace(b'$last = 1;', b'$last = 2;')


class QuoteRecoveryReleaseTest(unittest.TestCase):
    def test_core_pin_base_single_file_scope_and_rejection_before_untrusted_core_executes(self):
        core = wrapper.load_scoped_core()
        self.assertEqual('00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de', wrapper.CORE_SHA256)
        self.assertEqual('fb791fc47e1133d18be1c1d0583a435175d2db77', core.BASE)
        self.assertEqual((RELATIVE,), core.RUNTIME_PATHS)
        self.assertEqual(frozenset(), core.NEW_PATHS)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for path in ('.env', 'app/Models/Setting.php', 'app/Models/CloudPlan.php', 'routes/web.php'):
                with self.assertRaises(core.ReleaseError):
                    core.checked_file(root, path)
            bad = root / 'core.py'
            bad.write_bytes(Path(wrapper.__file__).with_name('deploy-vpstc.py').read_bytes() + b'\nraise AssertionError("must not execute")\n')
            with self.assertRaisesRegex(wrapper.WrapperError, 'pinned_release_core_hash_mismatch'):
                wrapper.load_scoped_core(bad)

    @unittest.skipUnless(Path(PHP).is_file(), 'Set PHP_BIN to isolated PHP')
    def test_one_file_plan_apply_and_rollback_preserve_other_files_live_drift_and_mode(self):
        core = wrapper.load_scoped_core()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'application'
            file = root / RELATIVE
            file.parent.mkdir(parents=True)
            current = BEFORE.replace(b'$first = 1;', b'$first = 3;')
            file.write_bytes(current)
            file.chmod(0o640)
            other = file.with_name('Other.php')
            other.write_bytes(b'<?php\n// unrelated live state\n')
            other_hash = core.digest(other.read_bytes())
            calls = []
            def source(commit, path):
                calls.append((commit, path))
                return BEFORE if commit == wrapper.BASE else AFTER
            with core.release_locks(root):
                directory, plan = core.build_plan(root, TARGET, PHP, source)
                self.assertEqual(2, len(calls))
                self.assertEqual([RELATIVE], [row['path'] for row in plan['files']])
                self.assertEqual(current, file.read_bytes())
                checked = core.load_plan(root, TARGET, directory, plan['plan_sha256'])
                result = core.apply_plan(root, directory, checked, PHP)
                self.assertEqual(1, result['files_installed'])
                self.assertEqual(current.replace(b'$last = 1;', b'$last = 2;'), file.read_bytes())
                self.assertEqual(0o640, file.stat().st_mode & 0o777)
                result = core.explicit_rollback(root, directory, checked)
                self.assertEqual('rolled_back', result['status'])
                self.assertEqual(current, file.read_bytes())
                self.assertEqual(0o640, file.stat().st_mode & 0o777)
                self.assertEqual(other_hash, core.digest(other.read_bytes()))

    @unittest.skipUnless(Path(PHP).is_file(), 'Set PHP_BIN to isolated PHP')
    def test_candidate_tamper_or_new_live_edit_blocks_apply_before_any_write(self):
        core = wrapper.load_scoped_core()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / 'application'
            file = root / RELATIVE
            file.parent.mkdir(parents=True)
            file.write_bytes(BEFORE)
            with core.release_locks(root):
                directory, plan = core.build_plan(root, TARGET, PHP,
                    lambda commit, path: BEFORE if commit == wrapper.BASE else AFTER)
                candidate = directory / 'candidate' / RELATIVE
                staged = candidate.read_bytes()
                candidate.write_bytes(staged + b'// changed after review\n')
                with self.assertRaisesRegex(core.ReleaseError, 'candidate_hash_mismatch'):
                    core.load_plan(root, TARGET, directory, plan['plan_sha256'])
                candidate.write_bytes(staged)
                checked = core.load_plan(root, TARGET, directory, plan['plan_sha256'])
                current = BEFORE + b'// newer independent production edit\n'
                file.write_bytes(current)
                with self.assertRaisesRegex(core.ReleaseError, 'live_hash_or_mode_changed'):
                    core.apply_plan(root, directory, checked, PHP)
                self.assertEqual(core.digest(current), core.digest(file.read_bytes()))
                self.assertFalse((directory / 'receipt.json').exists())


if __name__ == '__main__':
    unittest.main()
