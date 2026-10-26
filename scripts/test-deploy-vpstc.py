#!/usr/bin/env python3
"""Offline safety tests for the VPS.TC file-only release helper."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('vpstc_release', Path(__file__).with_name('deploy-vpstc.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
PHP = os.environ.get('PHP_BIN') or shutil.which('php') or '/opt/cpanel/ea-php84/root/usr/bin/php'
TARGET = 'a' * 40
EXISTING = 'app/Services/Cloud/VpsTcApi.php'
NEW = 'app/Services/Cloud/VpsTcSales.php'
BASE_CONTENT = b'<?php\n$first = 1;\n' + b'// unchanged context\n' * 10 + b'$last = 1;\n'
DESIRED = BASE_CONTENT.replace(b'$last = 1;', b'$last = 3;')
NEW_CONTENT = b'<?php\n// new isolated class fixture\n'


@unittest.skipUnless(Path(PHP).is_file(), 'Set PHP_BIN to the isolated PHP runtime')
class ScopedReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.parent = Path(self.temp.name)
        self.root = self.parent / 'application'
        self.root.mkdir()
        self.scope = patch.object(release, 'RUNTIME_PATHS', (EXISTING, NEW))
        self.scope.start()
        self.addCleanup(self.scope.stop)
        self.live = self.root / EXISTING
        self.live.parent.mkdir(parents=True)
        self.live.write_bytes(BASE_CONTENT)
        self.live.chmod(0o640)

    def reader(self, commit, relative):
        return ({EXISTING: BASE_CONTENT, NEW: None} if commit == release.BASE
                else {EXISTING: DESIRED, NEW: NEW_CONTENT})[relative]

    def plan(self):
        with release.release_locks(self.root):
            return release.build_plan(self.root, TARGET, PHP, self.reader)

    def validated(self, directory, plan):
        return release.load_plan(self.root, TARGET, directory, plan['plan_sha256'])

    def test_plan_preserves_live_drift_exact_bytes_and_modes_for_lf_and_crlf(self):
        for ending in (b'\n', b'\r\n'):
            with self.subTest(ending=ending):
                live = BASE_CONTENT.replace(b'$first = 1;', b'$first = 2;').replace(b'\n', ending)
                self.live.write_bytes(live)
                directory, plan = self.plan()
                self.assertEqual(live, self.live.read_bytes())
                candidate = (directory / 'candidate' / EXISTING).read_bytes()
                self.assertEqual(live.replace(b'$last = 1;', b'$last = 3;'), candidate)
                self.assertEqual(live, (directory / 'before' / EXISTING).read_bytes())
                self.assertEqual(0o600, stat.S_IMODE((directory / 'before' / EXISTING).stat().st_mode))
                self.assertEqual(0o700, stat.S_IMODE(directory.stat().st_mode))
                self.assertTrue(plan['files'][0]['preserved_live_drift'])
                self.assertEqual(0o640, plan['files'][0]['before_mode'])
                self.assertFalse((self.root / NEW).exists())
                self.validated(directory, plan)

    def test_overlapping_live_edit_fails_before_application_writes(self):
        current = BASE_CONTENT.replace(b'$last = 1;', b'$last = 7;')
        self.live.write_bytes(current)
        with self.assertRaisesRegex(release.ReleaseError, 'live_patch_conflict'):
            self.plan()
        self.assertEqual(current, self.live.read_bytes())
        self.assertFalse((self.root / NEW).exists())

    def test_unexpected_new_file_is_never_replaced(self):
        other = b'<?php\n// another release\n'
        (self.root / NEW).write_bytes(other)
        with self.assertRaisesRegex(release.ReleaseError, 'unexpected_existing_new_file'):
            self.plan()
        self.assertEqual(other, (self.root / NEW).read_bytes())
        self.assertEqual(BASE_CONTENT, self.live.read_bytes())

    def test_apply_and_explicit_rollback_restore_exact_bytes_modes_and_absence(self):
        directory, plan = self.plan()
        with release.release_locks(self.root):
            result = release.apply_plan(self.root, directory, self.validated(directory, plan), PHP)
        self.assertEqual('installed', result['status'])
        self.assertEqual(2, result['files_installed'])
        self.assertEqual(DESIRED, self.live.read_bytes())
        self.assertEqual(NEW_CONTENT, (self.root / NEW).read_bytes())
        self.assertEqual(0o640, stat.S_IMODE(self.live.stat().st_mode))
        self.assertEqual(0o644, stat.S_IMODE((self.root / NEW).stat().st_mode))
        self.assertEqual('not_performed', result['application_activation'])
        with release.release_locks(self.root):
            result = release.explicit_rollback(self.root, directory, self.validated(directory, plan))
        self.assertEqual('rolled_back', result['status'])
        self.assertEqual(BASE_CONTENT, self.live.read_bytes())
        self.assertEqual(0o640, stat.S_IMODE(self.live.stat().st_mode))
        self.assertFalse((self.root / NEW).exists())

    def test_changed_live_hash_or_mode_stops_all_apply_writes(self):
        directory, plan = self.plan()
        self.live.chmod(0o600)
        with self.assertRaisesRegex(release.ReleaseError, 'live_hash_or_mode_changed'):
            release.apply_plan(self.root, directory, self.validated(directory, plan), PHP)
        self.assertEqual(BASE_CONTENT, self.live.read_bytes())
        self.assertFalse((self.root / NEW).exists())
        self.assertFalse((directory / 'receipt.json').exists())

    def test_candidate_backup_and_plan_tampering_are_rejected(self):
        directory, plan = self.plan()
        candidate = directory / 'candidate' / NEW
        candidate.write_bytes(b'<?php\n// modified after review\n')
        with self.assertRaisesRegex(release.ReleaseError, 'candidate_hash_mismatch'):
            self.validated(directory, plan)
        candidate.write_bytes(NEW_CONTENT)
        backup = directory / 'before' / EXISTING
        backup.write_bytes(b'wrong backup')
        with self.assertRaisesRegex(release.ReleaseError, 'backup_hash_mismatch'):
            self.validated(directory, plan)
        backup.write_bytes(BASE_CONTENT)
        changed = json.loads((directory / 'plan.json').read_bytes())
        changed['files'][0]['before_mode'] = 0o777
        (directory / 'plan.json').write_text(json.dumps(changed))
        with self.assertRaisesRegex(release.ReleaseError, 'plan_hash_mismatch'):
            self.validated(directory, plan)
        self.assertEqual(BASE_CONTENT, self.live.read_bytes())

    def test_partial_install_failure_rolls_back_only_matching_installed_files(self):
        directory, plan = self.plan()
        writes = []
        def writer(path, data, mode):
            writes.append(path)
            if len(writes) == 2:
                raise OSError('injected write failure')
            release.atomic_write(path, data, mode)
        with self.assertRaisesRegex(release.ReleaseError, 'rolled_back'):
            release.apply_plan(self.root, directory, self.validated(directory, plan), PHP, writer=writer)
        self.assertEqual(BASE_CONTENT, self.live.read_bytes())
        self.assertEqual(0o640, stat.S_IMODE(self.live.stat().st_mode))
        self.assertFalse((self.root / NEW).exists())
        self.assertEqual('rolled_back', json.loads((directory / 'receipt.json').read_bytes())['status'])

    def test_rollback_does_not_overwrite_a_concurrent_newer_file(self):
        directory, plan = self.plan()
        release.apply_plan(self.root, directory, self.validated(directory, plan), PHP)
        newer = b'<?php\n// a newer production change\n'
        self.live.write_bytes(newer)
        with self.assertRaisesRegex(release.ReleaseError, 'live_hash_or_mode_changed'):
            release.explicit_rollback(self.root, directory, self.validated(directory, plan))
        self.assertEqual(newer, self.live.read_bytes())
        self.assertTrue((self.root / NEW).exists())

    def test_automatic_rollback_also_preserves_a_concurrent_newer_file(self):
        directory, plan = self.plan()
        newer = b'<?php\n// changed by another actor during release\n'
        writes = []
        def writer(path, data, mode):
            writes.append(path)
            if len(writes) == 2:
                self.live.write_bytes(newer)
                raise OSError('injected failure after a concurrent edit')
            release.atomic_write(path, data, mode)
        with self.assertRaisesRegex(release.ReleaseError, 'rollback_incomplete'):
            release.apply_plan(self.root, directory, self.validated(directory, plan), PHP, writer=writer)
        self.assertEqual(newer, self.live.read_bytes())
        self.assertEqual([EXISTING], json.loads((directory / 'receipt.json').read_bytes())['protected_files'])

    def test_bad_php_route_cache_and_symlink_are_fail_closed(self):
        def bad(commit, relative):
            return b'<?php this is not php' if commit == TARGET and relative == NEW else self.reader(commit, relative)
        with self.assertRaisesRegex(release.ReleaseError, 'php_lint_failed'):
            release.build_plan(self.root, TARGET, PHP, bad)
        cache = self.root / 'bootstrap/cache'
        cache.mkdir(parents=True)
        (cache / 'routes-fixture.php').write_bytes(b'<?php\n')
        with self.assertRaisesRegex(release.ReleaseError, 'route_cache_requires_reviewed_activation'):
            self.plan()
        (cache / 'routes-fixture.php').unlink()
        self.live.unlink()
        self.live.symlink_to(self.parent / 'outside.php')
        with self.assertRaisesRegex(release.ReleaseError, 'symlink_in_release_path'):
            self.plan()

    def test_shared_release_lock_blocks_another_release(self):
        with release.release_locks(self.root):
            with self.assertRaisesRegex(release.ReleaseError, 'another_release_holds_shared_lock'):
                with release.release_locks(self.root):
                    self.fail('second process lock was acquired')

    def test_identical_installed_source_is_a_linted_noop(self):
        self.live.write_bytes(DESIRED)
        (self.root / NEW).write_bytes(NEW_CONTENT)
        directory, plan = self.plan()
        self.assertFalse(any(row['change'] for row in plan['files']))
        result = release.apply_plan(self.root, directory, self.validated(directory, plan), PHP)
        self.assertEqual(0, result['files_installed'])
        self.assertEqual(DESIRED, self.live.read_bytes())


class ScopeDefinitionTest(unittest.TestCase):
    def test_scope_has_only_explicit_runtime_files_and_fixed_base(self):
        self.assertEqual('8489da5b559ce02c7ce34f5ede5b0e086fb72850', release.BASE)
        self.assertEqual(24, len(release.RUNTIME_PATHS))
        self.assertEqual(len(set(release.RUNTIME_PATHS)), len(release.RUNTIME_PATHS))
        for relative in release.RUNTIME_PATHS:
            self.assertTrue(relative.startswith(('app/', 'resources/', 'routes/')))
            self.assertTrue(relative.endswith('.php'))
            self.assertNotIn('..', relative)
            self.assertNotIn('.env', relative)
        self.assertEqual('routes/web.php', release.RUNTIME_PATHS[-1])
        self.assertTrue(release.NEW_PATHS.issubset(release.RUNTIME_PATHS))


if __name__ == '__main__':
    unittest.main()
