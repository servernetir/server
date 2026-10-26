#!/usr/bin/env python3
"""Offline scope, core-pin and guarded three-file release tests; no production."""
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

spec = importlib.util.spec_from_file_location('vpstc_contract_wrapper', Path(__file__).with_name('deploy-vpstc-live-contract.py'))
wrapper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wrapper)
PHP = os.environ.get('PHP_BIN') or shutil.which('php') or '/opt/cpanel/ea-php84/root/usr/bin/php'
TARGET = 'a' * 40
CONTENT = b'<?php\n$first = 1;\n' + b'// stable context\n' * 10 + b'$last = 1;\n'
DESIRED = CONTENT.replace(b'$last = 1;', b'$last = 3;')
EXPECTED_PATHS = (
    'app/Services/Cloud/VpsTcCatalog.php',
    'app/Services/Cloud/VpsTcPreflight.php',
    'resources/views/admin/cloud-vpstc.blade.php',
)


class WrapperIdentityTest(unittest.TestCase):
    def test_core_pin_base_and_scope_are_exact(self):
        path = Path(wrapper.__file__).with_name('deploy-vpstc.py')
        self.assertEqual(wrapper.CORE_SHA256, hashlib.sha256(path.read_bytes()).hexdigest())
        core = wrapper.load_scoped_core()
        self.assertEqual('bbac6e628759dd980a689206d1c7ccd8cc151644', core.BASE)
        self.assertEqual(EXPECTED_PATHS, core.RUNTIME_PATHS)
        self.assertEqual(frozenset(), core.NEW_PATHS)
        self.assertEqual('servernetir/server', core.REPOSITORY)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for disallowed in ('.env', 'routes/web.php', 'database/migrations/new.php',
                               'app/Services/Cloud/VpsTcSales.php', 'app/Models/Setting.php',
                               'app/Services/Cloud/VpsTcApi.php', 'resources/views/account/cloud-store.blade.php'):
                with self.assertRaises(core.ReleaseError):
                    core.checked_file(root, disallowed)

    def test_modified_core_is_rejected_before_any_of_its_code_executes(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            source = Path(wrapper.__file__)
            shutil.copyfile(source, directory / source.name)
            original = source.with_name('deploy-vpstc.py').read_bytes()
            marker = directory / 'unexpected-core-execution'
            malicious = original + ('\nopen('+repr(str(marker))+', "w").write("bad")\n').encode()
            (directory / 'deploy-vpstc.py').write_bytes(malicious)
            process = subprocess.run([sys.executable, str(directory / source.name)],
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            self.assertEqual(2, process.returncode)
            self.assertEqual({'status': 'failed', 'reason': 'pinned_release_core_hash_mismatch'},
                             json.loads(process.stdout))
            self.assertFalse(marker.exists())
            self.assertEqual('', process.stderr)

    def test_same_plan_apply_rollback_interface_is_retained_without_loading_the_app(self):
        process = subprocess.run([sys.executable, wrapper.__file__, '--help'],
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.assertEqual(0, process.returncode)
        for flag in ('--root', '--target', '--release-dir', '--apply', '--rollback'):
            self.assertIn(flag, process.stdout)
        self.assertIn('three VPS.TC', process.stdout)


@unittest.skipUnless(Path(PHP).is_file(), 'Set PHP_BIN to an isolated PHP runtime')
class ThreeFileReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.core = wrapper.load_scoped_core()
        self.root = Path(self.temp.name) / 'application'
        self.root.mkdir()
        for relative in EXPECTED_PATHS:
            file = self.root / relative
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(CONTENT)
            file.chmod(0o640)
        self.outside = self.root / 'app/Models/CloudPlan.php'
        self.outside.parent.mkdir(parents=True, exist_ok=True)
        self.outside.write_bytes(b'<?php\n// concurrent unrelated checkout changes\n')
        self.requests = []

    def source(self, commit, relative):
        self.requests.append((commit, relative))
        return CONTENT if commit == wrapper.BASE else DESIRED

    def plan(self, source=None):
        with self.core.release_locks(self.root):
            return self.core.build_plan(self.root, TARGET, PHP, source or self.source)

    def test_plan_apply_rollback_touch_exactly_three_files_and_preserve_unrelated_drift(self):
        before = self.outside.read_bytes()
        drift = CONTENT.replace(b'$first = 1;', b'$first = 2;')
        (self.root / EXPECTED_PATHS[0]).write_bytes(drift)
        directory, plan = self.plan()
        self.assertEqual(set(EXPECTED_PATHS), {row['path'] for row in plan['files']})
        self.assertEqual(6, len(self.requests))
        self.assertEqual({wrapper.BASE, TARGET}, {commit for commit, _ in self.requests})
        self.assertEqual(set(EXPECTED_PATHS), {relative for _, relative in self.requests})
        self.assertEqual(drift, (self.root / EXPECTED_PATHS[0]).read_bytes())
        self.assertEqual(before, self.outside.read_bytes())
        checked = self.core.load_plan(self.root, TARGET, directory, plan['plan_sha256'])
        with self.core.release_locks(self.root):
            result = self.core.apply_plan(self.root, directory, checked, PHP)
        self.assertEqual(3, result['files_installed'])
        self.assertEqual(drift.replace(b'$last = 1;', b'$last = 3;'), (self.root / EXPECTED_PATHS[0]).read_bytes())
        self.assertEqual(before, self.outside.read_bytes())
        with self.core.release_locks(self.root):
            restored = self.core.explicit_rollback(self.root, directory, checked)
        self.assertEqual('rolled_back', restored['status'])
        self.assertEqual(3, restored['files_restored'])
        self.assertEqual(drift, (self.root / EXPECTED_PATHS[0]).read_bytes())
        self.assertEqual(before, self.outside.read_bytes())
        for relative in EXPECTED_PATHS:
            self.assertEqual(0o640, (self.root / relative).stat().st_mode & 0o777)

    def test_missing_base_source_cannot_turn_into_a_new_file_installation(self):
        before = (self.root / EXPECTED_PATHS[0]).read_bytes()
        def missing(commit, relative):
            return None if commit == wrapper.BASE and relative == EXPECTED_PATHS[0] else self.source(commit, relative)
        with self.assertRaisesRegex(self.core.ReleaseError, 'missing_required_git_source'):
            self.plan(missing)
        self.assertEqual(before, (self.root / EXPECTED_PATHS[0]).read_bytes())

    def test_overlapping_live_change_remains_a_conflict_without_reusing_the_24file_overlay(self):
        current = CONTENT.replace(b'$last = 1;', b'$last = 7;')
        file = self.root / EXPECTED_PATHS[1]
        file.write_bytes(current)
        with self.assertRaisesRegex(self.core.ReleaseError, 'live_patch_conflict'):
            self.plan()
        self.assertEqual(current, file.read_bytes())
        self.assertEqual(CONTENT, (self.root / EXPECTED_PATHS[0]).read_bytes())

    def test_live_change_after_review_stops_apply_before_any_file_is_installed(self):
        directory, plan = self.plan()
        changed = self.root / EXPECTED_PATHS[-1]
        changed.write_bytes(CONTENT + b'// concurrent admin view edit\n')
        checked = self.core.load_plan(self.root, TARGET, directory, plan['plan_sha256'])
        with self.assertRaisesRegex(self.core.ReleaseError, 'live_hash_or_mode_changed'):
            self.core.apply_plan(self.root, directory, checked, PHP)
        self.assertEqual(CONTENT, (self.root / EXPECTED_PATHS[0]).read_bytes())
        self.assertEqual(CONTENT, (self.root / EXPECTED_PATHS[1]).read_bytes())
        self.assertFalse((directory / 'receipt.json').exists())


if __name__ == '__main__':
    unittest.main()
