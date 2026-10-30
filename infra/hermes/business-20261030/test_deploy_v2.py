import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('business_deploy_v2', Path(__file__).with_name('deploy-v2.py'))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class OwnerDeltaReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.source = Path(self.temp.name) / 'source'
        (self.home / deploy.APP).mkdir(parents=True)
        self.original = deploy.FILES
        self.addCleanup(setattr, deploy, 'FILES', self.original)
        deploy.FILES = copy.deepcopy(deploy.FILES)
        for name, row in deploy.FILES.items():
            before, after = ('before ' + name).encode(), ('after ' + name).encode()
            row['before'], row['after'] = deploy.digest(before), deploy.digest(after)
            p = self.source / row['source']
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(after)
            p = self.home / deploy.APP / name
            p.write_bytes(before)
            p.chmod(0o640)
        self.cron = 'unchanged-cron'
        # A prior release receipt and dependency are outside this two-file delta.
        self.v1_receipt = self.home / 'deploy-backups/hermes-business-20261030-v1/receipt.json'
        self.v1_receipt.parent.mkdir(parents=True)
        self.v1_receipt.write_text('sealed-v1-receipt')
        self.bridge = self.home / deploy.APP / 'HermesApprovedBusinessKnowledge.php'
        self.bridge.write_text('installed-v1-bridge')

    def run_phase(self, phase, lint=lambda p: None):
        return deploy.run(self.home, self.source, phase, lint, lambda: self.cron)

    def receipt(self):
        return self.home / 'deploy-backups' / deploy.RELEASE / 'receipt.json'

    def assert_v1_preserved(self):
        self.assertEqual('sealed-v1-receipt', self.v1_receipt.read_text())
        self.assertEqual('installed-v1-bridge', self.bridge.read_text())

    def test_prepare_apply_verify_repeat_and_prior_release_preserved(self):
        self.assertEqual('prepared', self.run_phase('prepare')['phase'])
        deploy.verify_targets(self.home, 'before')
        self.assertEqual('installed', self.run_phase('apply')['phase'])
        deploy.verify_targets(self.home, 'after')
        self.assertEqual('verified', self.run_phase('verify')['phase'])
        stamp = self.receipt().stat().st_mtime_ns
        self.assertEqual('verified', self.run_phase('verify')['phase'])
        self.assertEqual('verified', self.run_phase('apply')['phase'])
        self.assertEqual(stamp, self.receipt().stat().st_mtime_ns)
        self.assert_v1_preserved()

    def test_reviewed_order_and_existing_modes_preserved(self):
        self.run_phase('prepare')
        receipt = self.run_phase('apply')
        self.assertEqual(['HermesOperations.php', 'HermesConversationMemory.php'], receipt['installed'])
        for name in deploy.FILES:
            self.assertEqual(0o640, (self.home / deploy.APP / name).stat().st_mode & 0o777)

    def test_missing_prepare_cannot_apply(self):
        with self.assertRaisesRegex(deploy.ReleaseError, 'prepare_required'):
            self.run_phase('apply')

    def test_missing_existing_target_rejected(self):
        (self.home / deploy.APP / 'HermesConversationMemory.php').unlink()
        with self.assertRaisesRegex(deploy.ReleaseError, 'hash_drift'):
            self.run_phase('prepare')

    def test_source_drift_stops_before_prepare(self):
        (self.source / next(iter(deploy.FILES.values()))['source']).write_text('drift')
        with self.assertRaisesRegex(deploy.ReleaseError, 'hash_drift'):
            self.run_phase('prepare')

    def test_live_drift_after_prepare_stops_before_any_install(self):
        self.run_phase('prepare')
        (self.home / deploy.APP / 'HermesConversationMemory.php').write_text('later edit')
        with self.assertRaisesRegex(deploy.ReleaseError, 'hash_drift'):
            self.run_phase('apply')
        check = deploy.FILES['HermesOperations.php']['before']
        self.assertEqual(check, deploy.digest((self.home / deploy.APP / 'HermesOperations.php').read_bytes()))

    def test_symlink_target_rejected(self):
        target = self.home / deploy.APP / 'HermesOperations.php'
        target.unlink()
        target.symlink_to('/etc/passwd')
        with self.assertRaisesRegex(deploy.ReleaseError, 'symlink_path'):
            self.run_phase('prepare')

    def test_cron_change_stops_release(self):
        self.run_phase('prepare')
        self.cron = 'changed'
        with self.assertRaisesRegex(deploy.ReleaseError, 'cron_changed'):
            self.run_phase('apply')

    def test_lint_failure_before_install_preserves_targets(self):
        self.run_phase('prepare')
        def fail(_):
            raise deploy.ReleaseError('lint_failed')
        with self.assertRaisesRegex(deploy.ReleaseError, 'lint_failed'):
            self.run_phase('apply', fail)
        deploy.verify_targets(self.home, 'before')

    def test_lint_failure_during_install_rolls_back_only_delta(self):
        self.run_phase('prepare')
        def fail(path):
            if path.name == 'HermesConversationMemory.php' and deploy.current_hash(path) == deploy.FILES[path.name]['after']:
                raise deploy.ReleaseError('lint_after_install')
        with self.assertRaisesRegex(deploy.ReleaseError, 'lint_after_install'):
            self.run_phase('apply', fail)
        deploy.verify_targets(self.home, 'before')
        self.assertEqual('rolled_back', json.loads(self.receipt().read_text())['phase'])
        self.assert_v1_preserved()

    def test_explicit_rollback_preserves_v1(self):
        self.run_phase('prepare'); self.run_phase('apply'); self.run_phase('verify')
        self.assertEqual('rolled_back', self.run_phase('rollback')['phase'])
        deploy.verify_targets(self.home, 'before')
        self.assertEqual('rolled_back', self.run_phase('rollback')['phase'])
        self.assert_v1_preserved()

    def test_rollback_never_overwrites_later_edits(self):
        self.run_phase('prepare'); self.run_phase('apply')
        target = self.home / deploy.APP / 'HermesOperations.php'
        target.write_text('later edit')
        with self.assertRaisesRegex(deploy.ReleaseError, 'rollback_drift'):
            self.run_phase('rollback')
        self.assertEqual('later edit', target.read_text())
        self.assert_v1_preserved()

    def test_interrupted_install_requires_reconciliation_or_bounded_rollback(self):
        self.run_phase('prepare')
        receipt = json.loads(self.receipt().read_text())
        receipt['phase'] = 'installing'
        self.receipt().write_text(json.dumps(receipt))
        name, row = next(iter(deploy.FILES.items()))
        (self.home / deploy.APP / name).write_bytes((self.source / row['source']).read_bytes())
        with self.assertRaisesRegex(deploy.ReleaseError, 'requires_rollback'):
            self.run_phase('apply')
        self.run_phase('rollback')
        deploy.verify_targets(self.home, 'before')
        self.assert_v1_preserved()


if __name__ == '__main__':
    unittest.main()
