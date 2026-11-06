import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('chat_release', Path(__file__).with_name('deploy.py'))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class ChatReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.home.mkdir()
        self.source = Path(self.temp.name) / 'source'
        original = deploy.FILES
        self.addCleanup(setattr, deploy, 'FILES', original)
        deploy.FILES = copy.deepcopy(original)
        for name, row in deploy.FILES.items():
            before, after = ('before ' + name).encode(), ('after ' + name).encode()
            row['before'] = deploy.digest(before) if row['before'] else None
            row['after'] = deploy.digest(after)
            src = self.source / row['source']
            src.parent.mkdir(parents=True, exist_ok=True)
            src.write_bytes(after)
            target = deploy.target(self.home, name)
            target.parent.mkdir(parents=True, exist_ok=True)
            if row['before']:
                target.write_bytes(before)
                target.chmod(0o640)
        self.cron = 'unchanged'
        self.existing = self.home / 'servernet_app/unchanged-state'
        self.existing.write_text('preserve')

    def phase(self, name, lint=lambda path: None):
        return deploy.run(self.home, self.source, name, lint, lambda: self.cron)

    def test_prepare_apply_verify_and_repeat_preserve_state_and_modes(self):
        self.phase('prepare')
        deploy.verify_targets(self.home, 'before')
        receipt = self.phase('apply')
        self.assertEqual(list(deploy.FILES), receipt['installed'])
        self.phase('verify')
        for name, row in deploy.FILES.items():
            self.assertEqual(0o640 if row['before'] else 0o644, deploy.target(self.home, name).stat().st_mode & 0o777)
        self.assertEqual('verified', self.phase('apply')['phase'])
        self.assertEqual('preserve', self.existing.read_text())

    def test_preexisting_new_target_is_never_overwritten(self):
        deploy.target(self.home, 'HermesAlertSummary.php').write_text('other work')
        with self.assertRaisesRegex(deploy.ReleaseError, 'expected_absent'):
            self.phase('prepare')

    def test_live_drift_after_prepare_stops_all_writes(self):
        self.phase('prepare')
        deploy.target(self.home, 'ConsoleHost.php').write_text('later edit')
        with self.assertRaisesRegex(deploy.ReleaseError, 'hash_drift'):
            self.phase('apply')
        self.assertFalse(deploy.target(self.home, 'HermesAlertSummary.php').exists())

    def test_source_drift_stops_prepare(self):
        (self.source / deploy.FILES['ConsoleHost.php']['source']).write_text('drift')
        with self.assertRaisesRegex(deploy.ReleaseError, 'hash_drift'):
            self.phase('prepare')

    def test_symlink_target_rejected(self):
        target = deploy.target(self.home, 'ConsoleHost.php')
        target.unlink()
        target.symlink_to(self.existing)
        with self.assertRaisesRegex(deploy.ReleaseError, 'symlink'):
            self.phase('prepare')

    def test_cron_drift_stops_apply(self):
        self.phase('prepare')
        self.cron = 'changed'
        with self.assertRaisesRegex(deploy.ReleaseError, 'cron_changed'):
            self.phase('apply')

    def test_lint_failure_before_install_preserves_targets(self):
        self.phase('prepare')
        def fail(path):
            raise deploy.ReleaseError('lint_failed')
        with self.assertRaisesRegex(deploy.ReleaseError, 'lint_failed'):
            self.phase('apply', fail)
        deploy.verify_targets(self.home, 'before')

    def test_partial_apply_failure_rolls_back_new_and_existing_files(self):
        self.phase('prepare')
        failed = False
        def fail(path):
            nonlocal failed
            if not failed and path.name == 'ConsoleHost.php':
                failed = True
                raise deploy.ReleaseError('injected_after_install')
        with self.assertRaisesRegex(deploy.ReleaseError, 'injected_after_install'):
            self.phase('apply', fail)
        deploy.verify_targets(self.home, 'before')
        self.assertEqual('preserve', self.existing.read_text())

    def test_rollback_preserves_later_edits(self):
        self.phase('prepare')
        self.phase('apply')
        deploy.target(self.home, 'ConsoleHost.php').write_text('later edit')
        with self.assertRaisesRegex(deploy.ReleaseError, 'rollback_drift'):
            self.phase('rollback')
        self.assertEqual('later edit', deploy.target(self.home, 'ConsoleHost.php').read_text())

    def test_explicit_rollback_and_repeat(self):
        self.phase('prepare')
        self.phase('apply')
        self.phase('verify')
        self.assertEqual('rolled_back', self.phase('rollback')['phase'])
        self.assertEqual('rolled_back', self.phase('rollback')['phase'])
        deploy.verify_targets(self.home, 'before')

    def test_missing_prepare_cannot_apply(self):
        with self.assertRaisesRegex(deploy.ReleaseError, 'prepare_required'):
            self.phase('apply')

    def test_interrupted_apply_requires_reconciliation_or_rollback(self):
        self.phase('prepare')
        receipt_path = self.home / 'deploy-backups' / deploy.RELEASE / 'receipt.json'
        receipt = json.loads(receipt_path.read_text())
        receipt['phase'] = 'installing'
        receipt_path.write_text(json.dumps(receipt))
        with self.assertRaisesRegex(deploy.ReleaseError, 'requires_rollback'):
            self.phase('apply')
        self.phase('rollback')
        deploy.verify_targets(self.home, 'before')


if __name__ == '__main__':
    unittest.main()
