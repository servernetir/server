import copy
import importlib.util
import json
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('bale_layout_release', Path(__file__).with_name('deploy.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
base = release.base


class BaleLayoutReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.source = Path(self.temp.name) / 'source'
        self.home.mkdir(); self.source.mkdir()
        self.package = {'release': release.RELEASE, 'base_commit': release.BASE_COMMIT, 'files': []}
        baselines = {}
        for index, target in enumerate(release.TARGETS):
            before = None if index == 0 else ('old-' + str(index)).encode()
            after = ('new-' + str(index)).encode()
            row = {'source': 'website/' + target, 'target': target,
                   'before': None if before is None else base.digest(before), 'after': base.digest(after)}
            self.package['files'].append(row)
            baselines[target] = row['before']
            path = self.home / 'servernet_app' / target
            path.parent.mkdir(parents=True, exist_ok=True)
            if before is not None:
                path.write_bytes(before); path.chmod(0o640)
            path = self.source / row['source']
            path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(after)
        # These substitutions are internal to disposable tests, never CLI options.
        self.baselines = patch.dict(release.EXPECTED_BEFORE, baselines)
        self.baselines.start(); self.addCleanup(self.baselines.stop)
        self.save()

    def save(self):
        path = self.source / release.MANIFEST
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(self.package))

    def phase(self, phase, lint=lambda path: None, cron=lambda: 'same-cron'):
        return release.run(self.home, self.source, phase, lint, cron)

    def assert_before(self):
        base.verify_targets(self.home, self.package, 'before')

    def test_prepare_leaves_runtime_untouched_and_apply_verifies_exact_four_file_scope(self):
        self.assertEqual('prepared', self.phase('prepare')['phase'])
        self.assert_before()
        result = self.phase('apply')
        self.assertEqual('verified', result['phase'])
        self.assertEqual(4, result['files'])
        self.assertEqual(0, result['messages_sent'])
        self.assertFalse(result['framework_bootstrapped'])
        base.verify_targets(self.home, self.package, 'after')
        for index, row in enumerate(self.package['files']):
            self.assertEqual(0o644 if index == 0 else 0o640,
                             stat.S_IMODE(base.target(self.home, row).stat().st_mode))
        backup = self.home / 'deploy-backups' / release.RELEASE
        self.assertEqual(0o700, stat.S_IMODE(backup.stat().st_mode))
        self.assertEqual(0o600, stat.S_IMODE((backup / 'receipt.json').stat().st_mode))
        receipt = json.loads((backup / 'receipt.json').read_text())
        self.assertEqual(list(release.TARGETS), receipt['installed'])

    def test_extra_target_and_wrong_dependency_order_are_rejected(self):
        self.package['files'].append(copy.deepcopy(self.package['files'][0])); self.save()
        with self.assertRaises(base.ReleaseError): self.phase('prepare')
        self.package['files'].pop(); self.package['files'].reverse(); self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'scope_or_order'): self.phase('prepare')
        self.package['files'].reverse(); self.assert_before()

    def test_unreviewed_base_commit_and_wrong_source_cannot_prepare(self):
        self.package['base_commit'] = 'a' * 40; self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'metadata'): self.phase('prepare')
        self.package['base_commit'] = release.BASE_COMMIT
        self.package['files'][0]['source'] = 'website/routes/console.php'; self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'source_or_baseline'): self.phase('prepare')

    def test_new_service_cannot_overwrite_a_preexisting_unreviewed_file(self):
        path = base.target(self.home, self.package['files'][0]); path.write_bytes(b'other service')
        with self.assertRaisesRegex(base.ReleaseError, 'expected_absent'): self.phase('prepare')
        self.assertEqual(b'other service', path.read_bytes())

    def test_wrong_before_hash_and_noop_are_rejected(self):
        row = self.package['files'][1]
        original = row['before']
        row['before'] = 'a' * 64; self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'source_or_baseline'): self.phase('prepare')
        row['before'] = original; row['after'] = original; self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'noop'): self.phase('prepare')

    def test_drift_between_prepare_and_apply_preserves_other_developers_changes(self):
        self.phase('prepare')
        path = base.target(self.home, self.package['files'][3]); path.write_bytes(b'concurrent router')
        with self.assertRaisesRegex(base.ReleaseError, 'hash_drift'): self.phase('apply')
        self.assertEqual(b'concurrent router', path.read_bytes())
        self.assertFalse(base.target(self.home, self.package['files'][0]).exists())
        self.assertEqual(b'old-1', base.target(self.home, self.package['files'][1]).read_bytes())

    def test_source_drift_after_prepare_cannot_install_stale_or_unreviewed_bytes(self):
        self.phase('prepare')
        row = self.package['files'][2]
        (self.source / row['source']).write_bytes(b'changed digest')
        with self.assertRaisesRegex(base.ReleaseError, 'hash_drift'): self.phase('apply')
        self.assert_before()

    def test_partial_install_failure_restores_old_files_and_removes_only_new_service(self):
        self.phase('prepare')
        def lint(path):
            if path == base.target(self.home, self.package['files'][2]) and path.read_bytes() == b'new-2':
                raise RuntimeError('synthetic lint failure')
        with self.assertRaisesRegex(RuntimeError, 'synthetic lint failure'):
            self.phase('apply', lint=lint)
        self.assert_before()
        self.assertFalse(base.target(self.home, self.package['files'][0]).exists())

    def test_rollback_refuses_newer_edit_before_mutating_any_release_target(self):
        self.phase('prepare'); self.phase('apply')
        path = base.target(self.home, self.package['files'][0]); path.write_bytes(b'newer calendar')
        with self.assertRaisesRegex(base.ReleaseError, 'rollback_drift'): self.phase('rollback')
        self.assertEqual(b'newer calendar', path.read_bytes())
        self.assertEqual(b'new-3', base.target(self.home, self.package['files'][3]).read_bytes())
        path.write_bytes(b'new-0')
        self.assertEqual('rolled_back', self.phase('rollback')['phase'])
        self.assert_before()

    def test_cron_drift_stops_without_restoring_an_operators_cron(self):
        self.phase('prepare')
        with self.assertRaisesRegex(base.ReleaseError, 'cron_changed'):
            self.phase('apply', cron=lambda: 'operator-changed-cron')
        self.assert_before()

    def test_symlink_target_is_never_followed(self):
        row = self.package['files'][0]
        outside = self.home / 'outside.php'; outside.write_bytes(b'outside bytes')
        base.target(self.home, row).symlink_to(outside)
        with self.assertRaisesRegex(base.ReleaseError, 'symlink'): self.phase('prepare')
        self.assertEqual(b'outside bytes', outside.read_bytes())

    def test_verified_apply_is_idempotent_without_reinstalling_files(self):
        self.phase('prepare'); self.phase('apply')
        times = [base.target(self.home, row).stat().st_mtime_ns for row in self.package['files']]
        self.assertEqual('verified', self.phase('apply')['phase'])
        self.assertEqual(times, [base.target(self.home, row).stat().st_mtime_ns for row in self.package['files']])
        with self.assertRaisesRegex(base.ReleaseError, 'already_started'): self.phase('prepare')


if __name__ == '__main__':
    unittest.main()
