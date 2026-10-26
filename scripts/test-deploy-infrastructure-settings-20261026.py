#!/usr/bin/env python3
"""Offline scope, new-file, drift and rollback checks for the twenty-file wrapper."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('infrastructure_settings_wrapper', Path(__file__).with_name('deploy-infrastructure-settings-20261026.py'))
wrapper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wrapper)
PHP = os.environ.get('PHP_BIN') or shutil.which('php') or '/opt/cpanel/ea-php84/root/usr/bin/php'
TARGET = 'a' * 40
CONTENT = b'<?php\n$first = 1;\n' + b'// stable context\n' * 10 + b'$last = 1;\n'
DESIRED = CONTENT.replace(b'$last = 1;', b'$last = 3;')
NEW = {
    'app/Services/Cloud/CloudOfferSelection.php',
    'app/Services/Cloud/InfrastructureSettingsStatus.php',
    'resources/views/admin/partials/vpstc-settings-panel.blade.php',
    'resources/views/admin/settings/_infra-summary.blade.php',
}


class IdentityTest(unittest.TestCase):
    def test_exact_core_base_scope_and_four_new_dependencies(self):
        self.assertEqual('00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de', wrapper.CORE_SHA256)
        core_path = Path(wrapper.__file__).with_name('deploy-vpstc.py')
        self.assertEqual(wrapper.CORE_SHA256, hashlib.sha256(core_path.read_bytes()).hexdigest())
        core = wrapper.load_scoped_core()
        self.assertEqual('984d7d04fa3a29816465ed63bb165a47f4cec380', core.BASE)
        self.assertEqual(20, len(core.RUNTIME_PATHS))
        self.assertEqual(20, len(set(core.RUNTIME_PATHS)))
        self.assertEqual(NEW, core.NEW_PATHS)
        self.assertEqual('app/Services/ExchangeRate.php', core.RUNTIME_PATHS[0])
        self.assertLess(core.RUNTIME_PATHS.index('app/Services/Cloud/CloudOfferSelection.php'),
                        core.RUNTIME_PATHS.index('app/Models/CloudPlan.php'))
        self.assertLess(core.RUNTIME_PATHS.index('resources/views/admin/partials/vpstc-settings-panel.blade.php'),
                        core.RUNTIME_PATHS.index('resources/views/admin/cloud-vpstc.blade.php'))
        with tempfile.TemporaryDirectory() as temporary:
            for relative in ('.env', 'routes/web.php', 'database/migrations/new.php',
                             'app/Models/Setting.php', 'app/Services/Cloud/VpsTcApi.php',
                             'resources/views/account/cloud-store.blade.php', 'public/assets/css/admin.css'):
                with self.assertRaises(core.ReleaseError):
                    core.checked_file(Path(temporary), relative)

    def test_changed_core_is_refused_before_executing_its_code(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            source = Path(wrapper.__file__)
            shutil.copyfile(source, directory / source.name)
            original = source.with_name('deploy-vpstc.py').read_bytes()
            marker = directory / 'unexpected-execution'
            (directory / 'deploy-vpstc.py').write_bytes(original + ('\nopen('+repr(str(marker))+',"w").write("bad")\n').encode())
            result = subprocess.run([sys.executable, str(directory / source.name)],
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            self.assertEqual(2, result.returncode)
            self.assertEqual({'status': 'failed', 'reason': 'pinned_release_core_hash_mismatch'}, json.loads(result.stdout))
            self.assertEqual('', result.stderr)
            self.assertFalse(marker.exists())


@unittest.skipUnless(Path(PHP).is_file(), 'Set PHP_BIN to an isolated PHP runtime')
class TwentyFileReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'application'
        self.root.mkdir()
        self.core = wrapper.load_scoped_core()
        for relative in self.core.RUNTIME_PATHS:
            if relative in NEW:
                continue
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(CONTENT)
            path.chmod(0o640)
        self.unrelated = self.root / 'app/Services/Cloud/UnrelatedProvider.php'
        self.unrelated.write_bytes(b'<?php\n// independent live change\n')
        self.requests = []

    def source(self, commit, relative):
        self.requests.append((commit, relative))
        if commit == wrapper.BASE:
            return None if relative in NEW else CONTENT
        return DESIRED

    def plan(self, source=None):
        with self.core.release_locks(self.root):
            return self.core.build_plan(self.root, TARGET, PHP, source or self.source)

    def checked(self, directory, plan):
        return self.core.load_plan(self.root, TARGET, directory, plan['plan_sha256'])

    def test_plan_stages_exactly_four_new_files_without_writing_the_application(self):
        directory, plan = self.plan()
        self.assertEqual(20, len(plan['files']))
        self.assertEqual(40, len(self.requests))
        self.assertEqual(set(self.core.RUNTIME_PATHS), {path for _, path in self.requests})
        self.assertEqual(NEW, {row['path'] for row in plan['files'] if row['before_sha256'] is None})
        for relative in NEW:
            self.assertFalse((self.root / relative).exists())
            self.assertEqual(self.core.digest(DESIRED), self.core.digest((directory / 'candidate' / relative).read_bytes()))
            self.assertFalse((directory / 'before' / relative).exists())
        self.assertEqual('plan_sha256' in plan, True)
        self.assertFalse((directory / 'receipt.json').exists())
        self.checked(directory, plan)

    def test_apply_and_rollback_preserve_live_checkout_drift_modes_and_unrelated_files(self):
        relative = 'app/Http/Controllers/Account/CloudStoreController.php'
        path = self.root / relative
        current = CONTENT.replace(b'$first = 1;', b'$first = 2;')
        path.write_bytes(current)
        outside = self.unrelated.read_bytes()
        directory, plan = self.plan()
        checked = self.checked(directory, plan)
        with self.core.release_locks(self.root):
            result = self.core.apply_plan(self.root, directory, checked, PHP)
        self.assertEqual(20, result['files_installed'])
        self.assertEqual(current.replace(b'$last = 1;', b'$last = 3;'), path.read_bytes())
        self.assertEqual(outside, self.unrelated.read_bytes())
        for new in NEW:
            self.assertEqual(0o644, (self.root / new).stat().st_mode & 0o777)
        with self.core.release_locks(self.root):
            result = self.core.explicit_rollback(self.root, directory, checked)
        self.assertEqual('rolled_back', result['status'])
        self.assertEqual(20, result['files_restored'])
        self.assertEqual(current, path.read_bytes())
        self.assertEqual(0o640, path.stat().st_mode & 0o777)
        self.assertEqual(outside, self.unrelated.read_bytes())
        self.assertTrue(all(not (self.root / new).exists() for new in NEW))

    def test_unknown_existing_new_dependency_is_never_overwritten(self):
        relative = 'app/Services/Cloud/CloudOfferSelection.php'
        path = self.root / relative
        path.write_bytes(b'<?php\n// another release owns this file\n')
        expected = self.core.digest(path.read_bytes())
        with self.assertRaisesRegex(self.core.ReleaseError, 'unexpected_existing_new_file'):
            self.plan()
        self.assertEqual(expected, self.core.digest(path.read_bytes()))
        self.assertEqual(CONTENT, (self.root / self.core.RUNTIME_PATHS[0]).read_bytes())

    def test_missing_base_for_an_old_file_or_overlapping_drift_stops_the_plan(self):
        first = self.core.RUNTIME_PATHS[0]
        def missing(commit, relative):
            return None if commit == wrapper.BASE and relative == first else self.source(commit, relative)
        with self.assertRaisesRegex(self.core.ReleaseError, 'missing_required_git_source'):
            self.plan(missing)
        current = CONTENT.replace(b'$last = 1;', b'$last = 7;')
        (self.root / first).write_bytes(current)
        with self.assertRaisesRegex(self.core.ReleaseError, 'live_patch_conflict'):
            self.plan()
        self.assertEqual(current, (self.root / first).read_bytes())
        self.assertTrue(all(not (self.root / new).exists() for new in NEW))

    def test_partial_failure_rolls_back_existing_and_new_files(self):
        directory, plan = self.plan()
        calls = []
        def writer(path, data, mode):
            calls.append(path)
            if len(calls) == 18:
                raise OSError('injected staged install failure')
            self.core.atomic_write(path, data, mode)
        with self.assertRaisesRegex(self.core.ReleaseError, 'rolled_back'):
            self.core.apply_plan(self.root, directory, self.checked(directory, plan), PHP, writer=writer)
        for relative in self.core.RUNTIME_PATHS:
            path = self.root / relative
            if relative in NEW:
                self.assertFalse(path.exists())
            else:
                self.assertEqual(self.core.digest(CONTENT), self.core.digest(path.read_bytes()))
                self.assertEqual(0o640, path.stat().st_mode & 0o777)
        self.assertEqual('rolled_back', json.loads((directory / 'receipt.json').read_bytes())['status'])

    def test_automatic_rollback_preserves_a_new_dependency_changed_by_another_actor(self):
        directory, plan = self.plan()
        newer = b'<?php\n// newer independent dependency change\n'
        protected = 'app/Services/Cloud/CloudOfferSelection.php'
        calls = []
        def writer(path, data, mode):
            calls.append(path)
            if len(calls) == 18:
                (self.root / protected).write_bytes(newer)
                raise OSError('injected failure after another actor changed a new file')
            self.core.atomic_write(path, data, mode)
        with self.assertRaisesRegex(self.core.ReleaseError, 'rollback_incomplete'):
            self.core.apply_plan(self.root, directory, self.checked(directory, plan), PHP, writer=writer)
        self.assertEqual(self.core.digest(newer), self.core.digest((self.root / protected).read_bytes()))
        receipt = json.loads((directory / 'receipt.json').read_bytes())
        self.assertEqual([protected], receipt['protected_files'])
        self.assertEqual(CONTENT, (self.root / self.core.RUNTIME_PATHS[0]).read_bytes())


if __name__ == '__main__':
    unittest.main()
