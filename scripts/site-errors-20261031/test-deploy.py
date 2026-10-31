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
spec = importlib.util.spec_from_file_location('site_errors_release', Path(__file__).with_name('deploy.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
base = release.base


class SiteErrorReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.source = Path(self.temp.name) / 'source'
        self.home.mkdir(); self.source.mkdir()
        self.package = {'release': release.RELEASE, 'base_commit': 'a' * 40, 'files': []}
        old_hashes = {}
        for index, target in enumerate(release.TARGETS):
            before, after = ('old-' + str(index)).encode(), ('new-' + str(index)).encode()
            row = {'source': 'website/' + target, 'target': target,
                   'before': base.digest(before), 'after': base.digest(after)}
            self.package['files'].append(row)
            old_hashes[target] = row['before']
            path = self.home / 'servernet_app' / target
            path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(before); path.chmod(0o640)
            path = self.source / row['source']
            path.parent.mkdir(parents=True, exist_ok=True); path.write_bytes(after)
        # Only synthetic roots are used. No production override exists in CLI.
        self.baselines = patch.dict(release.EXPECTED_BEFORE, old_hashes)
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

    def test_prepare_leaves_runtime_untouched_then_apply_verifies_and_preserves_mode(self):
        self.assertEqual('prepared', self.phase('prepare')['phase'])
        self.assert_before()
        result = self.phase('apply')
        self.assertEqual('verified', result['phase'])
        self.assertEqual(0, result['messages_sent'])
        base.verify_targets(self.home, self.package, 'after')
        for row in self.package['files']:
            self.assertEqual(0o640, stat.S_IMODE(base.target(self.home, row).stat().st_mode))
        backup = self.home / 'deploy-backups' / release.RELEASE
        self.assertEqual(0o700, stat.S_IMODE(backup.stat().st_mode))
        self.assertEqual(0o600, stat.S_IMODE((backup / 'receipt.json').stat().st_mode))
        self.assertEqual('verified', self.phase('verify')['phase'])

    def test_extra_target_and_reordered_dependencies_are_rejected(self):
        self.package['files'].append(copy.deepcopy(self.package['files'][0])); self.save()
        with self.assertRaises(base.ReleaseError): self.phase('prepare')
        self.package['files'].pop(); self.package['files'].reverse(); self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'scope_or_order'): self.phase('prepare')
        self.assert_before()

    def test_wrong_source_and_baseline_cannot_prepare(self):
        self.package['files'][0]['source'] = 'website/routes/console.php'; self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'source_or_baseline'): self.phase('prepare')
        self.package['files'][0]['source'] = 'website/' + release.TARGETS[0]
        self.package['files'][0]['before'] = None; self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'source_or_baseline'): self.phase('prepare')

    def test_noop_and_invalid_metadata_are_rejected(self):
        self.package['files'][0]['after'] = self.package['files'][0]['before']; self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'noop'): self.phase('prepare')
        self.package['base_commit'] = 'not-a-sha'; self.save()
        with self.assertRaisesRegex(base.ReleaseError, 'metadata'): self.phase('prepare')

    def test_drift_between_prepare_and_apply_stops_without_overwriting_other_developer(self):
        self.phase('prepare')
        path = base.target(self.home, self.package['files'][1]); path.write_bytes(b'another developer')
        with self.assertRaisesRegex(base.ReleaseError, 'hash_drift'): self.phase('apply')
        self.assertEqual(b'another developer', path.read_bytes())
        self.assertEqual(b'old-0', base.target(self.home, self.package['files'][0]).read_bytes())

    def test_cron_change_stops_apply_and_is_never_restored(self):
        self.phase('prepare')
        with self.assertRaisesRegex(base.ReleaseError, 'cron_changed'):
            self.phase('apply', cron=lambda: 'operator-changed-cron')
        self.assert_before()

    def test_failure_during_second_runtime_lint_rolls_back_all_installed_files(self):
        self.phase('prepare')
        def lint(path):
            if path == base.target(self.home, self.package['files'][1]) and path.read_bytes() == b'new-1':
                raise RuntimeError('synthetic lint failure')
        with self.assertRaisesRegex(RuntimeError, 'synthetic lint failure'):
            self.phase('apply', lint=lint)
        self.assert_before()

    def test_rollback_refuses_newer_edit_then_restores_only_exact_release_bytes(self):
        self.phase('prepare'); self.phase('apply')
        path = base.target(self.home, self.package['files'][2]); path.write_bytes(b'newer edit')
        with self.assertRaisesRegex(base.ReleaseError, 'rollback_drift'): self.phase('rollback')
        self.assertEqual(b'newer edit', path.read_bytes())
        path.write_bytes(b'new-2')
        self.assertEqual('rolled_back', self.phase('rollback')['phase'])
        self.assert_before()

    def test_sealed_apply_is_idempotent_without_installing_files_again(self):
        self.phase('prepare'); self.phase('apply')
        times = [base.target(self.home, row).stat().st_mtime_ns for row in self.package['files']]
        self.assertEqual('verified', self.phase('apply')['phase'])
        self.assertEqual(times, [base.target(self.home, row).stat().st_mtime_ns for row in self.package['files']])
        with self.assertRaisesRegex(base.ReleaseError, 'already_started'): self.phase('prepare')


if __name__ == '__main__':
    unittest.main()
