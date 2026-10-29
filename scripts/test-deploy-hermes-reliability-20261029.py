#!/usr/bin/env python3
"""Exercise the file release against synthetic, isolated homes; no network."""
import fcntl
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('release', Path(__file__).with_name(
    'deploy-hermes-reliability-20261029.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseSafetyTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.source = Path(self.temp.name) / 'source'
        self.commands = self.home / 'servernet_app/app/Console/Commands'
        self.commands.mkdir(parents=True)
        (self.source / release.PACKAGE).mkdir(parents=True)
        self.before, self.after, files = {}, {}, {}
        for name in release.FILES:
            self.before[name] = ('<?php // before ' + name).encode()
            self.after[name] = ('<?php // after ' + name).encode()
            (self.commands / name).write_bytes(self.before[name])
            (self.commands / name).chmod(0o640)
            (self.source / release.PACKAGE / name).write_bytes(self.after[name])
            files[name] = (release.digest(self.before[name]), release.digest(self.after[name]))
        deps = {}
        for name in release.DEPENDENCIES:
            path = self.home / 'servernet_app/app/Services/Hermes' / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'fixture dependency')
            deps[name] = release.digest(path.read_bytes())
        chat = self.home / 'servernet_app/app/Services/Bale/Admin/HermesMiniAppStore.php'
        chat.parent.mkdir(parents=True)
        chat.write_bytes(b'fixture chat')
        self.unrelated = self.home / 'servernet_app/unrelated.txt'
        self.unrelated.write_bytes(b'keep concurrent work')
        self.patches = [patch.object(release, 'FILES', files), patch.object(release, 'DEPENDENCIES', deps)]
        for item in self.patches:
            item.start()
            self.addCleanup(item.stop)
        self.cron = {'sha256': 'synthetic-cron-hash', 'jobs': 11}

    def run_phase(self, lint=lambda path: None):
        return release.run(self.home, self.source, lint=lint, observe_cron=lambda: self.cron)

    def assert_original(self):
        for name, data in self.before.items():
            self.assertEqual((self.commands / name).read_bytes(), data)

    def test_prepare_never_changes_runtime_and_private_backups_match(self):
        self.assertEqual(self.run_phase()['phase'], 'prepared')
        self.assert_original()
        folder = self.home / 'deploy-backups' / release.RELEASE
        for name, data in self.before.items():
            path = folder / (name + '.before')
            self.assertEqual(path.read_bytes(), data)
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)

    def test_three_phases_preserve_permissions_and_seal(self):
        self.run_phase()
        self.assertEqual(self.run_phase()['phase'], 'installed')
        self.assertEqual(self.run_phase()['phase'], 'verified')
        for name, data in self.after.items():
            self.assertEqual((self.commands / name).read_bytes(), data)
            self.assertEqual((self.commands / name).stat().st_mode & 0o777, 0o640)
        self.assertEqual(self.unrelated.read_bytes(), b'keep concurrent work')
        with self.assertRaisesRegex(release.ReleaseError, 'sealed'):
            self.run_phase()

    def test_pre_apply_drift_does_not_overwrite_either_command(self):
        self.run_phase()
        first, second = list(self.before)
        (self.commands / second).write_bytes(b'concurrent edit')
        with self.assertRaisesRegex(release.ReleaseError, 'hash_drift'):
            self.run_phase()
        self.assertEqual((self.commands / first).read_bytes(), self.before[first])
        self.assertEqual((self.commands / second).read_bytes(), b'concurrent edit')

    def test_dependency_drift_stops_before_runtime_writes(self):
        self.run_phase()
        (self.home / 'servernet_app/app/Services/Hermes/HermesKnowledge.php').write_bytes(b'drift')
        with self.assertRaisesRegex(release.ReleaseError, 'hash_drift'):
            self.run_phase()
        self.assert_original()

    def test_failed_second_active_lint_rolls_back_both_commands(self):
        self.run_phase()
        def lint(path):
            if path.parent == self.commands and path.name == 'HermesOperationsReview.php':
                raise release.ReleaseError('synthetic_lint_failure')
        with self.assertRaisesRegex(release.ReleaseError, 'synthetic_lint_failure'):
            self.run_phase(lint)
        self.assert_original()
        with self.assertRaisesRegex(release.ReleaseError, 'sealed_or_incomplete'):
            self.run_phase()

    def test_later_edit_is_not_overwritten_by_verification(self):
        self.run_phase()
        self.run_phase()
        name = next(iter(self.after))
        (self.commands / name).write_bytes(b'later edit')
        with self.assertRaisesRegex(release.ReleaseError, 'hash_drift'):
            self.run_phase()
        self.assertEqual((self.commands / name).read_bytes(), b'later edit')

    def test_held_shared_lock_prevents_prepare(self):
        with (self.home / 'servernet-release.lock').open('a') as handle:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):
                self.run_phase()
        self.assert_original()

    def test_symlink_target_is_rejected(self):
        name = next(iter(self.before))
        target = self.commands / name
        target.unlink()
        target.symlink_to(self.unrelated)
        with self.assertRaisesRegex(release.ReleaseError, 'symlink'):
            self.run_phase()
        self.assertEqual(self.unrelated.read_bytes(), b'keep concurrent work')

    def test_changed_cron_stops_apply(self):
        self.run_phase()
        self.cron = {'sha256': 'a-new-cron', 'jobs': 12}
        with self.assertRaisesRegex(release.ReleaseError, 'cron_changed'):
            self.run_phase()
        self.assert_original()


if __name__ == '__main__':
    unittest.main()
