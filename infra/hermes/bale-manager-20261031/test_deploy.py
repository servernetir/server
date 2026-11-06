import copy
import fcntl
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('bale_manager_release', Path(__file__).with_name('deploy.py'))
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class ManagerReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.source = Path(self.temp.name) / 'source'
        self.home.mkdir()
        self.source.mkdir()
        self.manifest = {'release': deploy.RELEASE, 'files': [
            {'source': 'website/app/Services/Bale/Management/New.php',
             'target': 'app/Services/Bale/Management/New.php', 'before': None, 'after': None},
            {'source': 'infra/hermes/bale-manager-20261031/OwnerReview.php',
             'target': 'app/Services/Hermes/OwnerReview.php', 'before': '', 'after': None},
            {'source': 'website/routes/console.php',
             'target': 'routes/console.php', 'before': '', 'after': None},
        ]}
        for index, row in enumerate(self.manifest['files']):
            before, after = ('before ' + str(index)).encode(), ('after ' + str(index)).encode()
            row['before'] = deploy.digest(before) if row['before'] is not None else None
            row['after'] = deploy.digest(after)
            source = self.source / row['source']
            source.parent.mkdir(parents=True, exist_ok=True)
            source.write_bytes(after)
            if row['before'] is not None:
                target = self.path(index)
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(before)
                target.chmod(0o640)
        self.existing = self.home / 'servernet_app/unchanged-state'
        self.existing.write_text('preserve')
        self.cron = 'unchanged'

    def path(self, index):
        return deploy.target(self.home, self.manifest['files'][index])

    def phase(self, name, lint=lambda path: None, cron=None):
        return deploy.run(self.home, self.source, self.manifest, name, lint, cron or (lambda: self.cron))

    def assert_before(self):
        deploy.verify_targets(self.home, self.manifest, 'before')
        self.assertEqual('preserve', self.existing.read_text())

    def receipt(self):
        return self.home / 'deploy-backups' / deploy.RELEASE / 'receipt.json'

    def test_full_lifecycle_preserves_unrelated_state_modes_and_private_receipts(self):
        self.phase('prepare')
        self.assert_before()
        self.assertFalse(self.path(0).parent.exists())
        receipt = self.phase('apply')
        self.assertEqual([r['target'] for r in self.manifest['files']], receipt['installed'])
        self.assertEqual(0o755, self.path(0).parent.stat().st_mode & 0o777)
        self.assertEqual(0o644, self.path(0).stat().st_mode & 0o777)
        self.assertEqual(0o640, self.path(1).stat().st_mode & 0o777)
        self.assertEqual(0o600, self.receipt().stat().st_mode & 0o777)
        self.assertEqual(0o700, self.receipt().parent.stat().st_mode & 0o777)
        self.assertEqual('verified', self.phase('verify')['phase'])
        self.assertEqual('verified', self.phase('apply')['phase'])
        self.assertEqual('verified', self.phase('verify')['phase'])
        self.assertEqual('rolled_back', self.phase('rollback')['phase'])
        self.assertEqual('rolled_back', self.phase('rollback')['phase'])
        self.assert_before()

    def test_existing_file_at_expected_absent_target_is_not_overwritten(self):
        self.path(0).parent.mkdir(parents=True)
        self.path(0).write_text('other work')
        with self.assertRaisesRegex(deploy.ReleaseError, 'expected_absent'):
            self.phase('prepare')
        self.assertEqual('other work', self.path(0).read_text())

    def test_source_drift_before_prepare_stops_without_target_writes(self):
        (self.source / self.manifest['files'][2]['source']).write_text('drift')
        with self.assertRaisesRegex(deploy.ReleaseError, 'hash_drift'):
            self.phase('prepare')
        self.assert_before()

    def test_target_drift_after_prepare_stops_entire_apply(self):
        self.phase('prepare')
        self.path(2).write_text('later edit')
        with self.assertRaisesRegex(deploy.ReleaseError, 'hash_drift'):
            self.phase('apply')
        self.assertFalse(self.path(0).exists())
        self.assertEqual('later edit', self.path(2).read_text())

    def test_lint_failure_in_last_staged_file_stops_all_installation(self):
        self.phase('prepare')
        def fail(path):
            if path.name == '002.after.php':
                raise deploy.ReleaseError('fixture_lint_failure')
        with self.assertRaisesRegex(deploy.ReleaseError, 'fixture_lint_failure'):
            self.phase('apply', fail)
        self.assert_before()

    def test_partial_install_failure_restores_existing_and_removes_exact_new_file(self):
        self.phase('prepare')
        failed = False
        def fail(path):
            nonlocal failed
            if path == self.path(1) and not failed:
                failed = True
                raise deploy.ReleaseError('fixture_post_install_failure')
        with self.assertRaisesRegex(deploy.ReleaseError, 'fixture_post_install_failure'):
            self.phase('apply', fail)
        self.assert_before()
        self.assertEqual('rolled_back', json.loads(self.receipt().read_text())['phase'])

    def test_later_edit_stops_all_rollback_mutations(self):
        self.phase('prepare')
        self.phase('apply')
        self.path(2).write_text('later edit')
        with self.assertRaisesRegex(deploy.ReleaseError, 'rollback_drift'):
            self.phase('rollback')
        self.assertTrue(self.path(0).exists())
        self.assertEqual('later edit', self.path(2).read_text())

    def test_corrupted_backup_stops_all_rollback_mutations(self):
        self.phase('prepare')
        self.phase('apply')
        deploy.stage_path(self.receipt().parent, 2, 'before').write_text('bad backup')
        with self.assertRaisesRegex(deploy.ReleaseError, 'hash_drift'):
            self.phase('rollback')
        deploy.verify_targets(self.home, self.manifest, 'after')

    def test_corrupted_stage_stops_all_apply_mutations(self):
        self.phase('prepare')
        deploy.stage_path(self.receipt().parent, 2, 'after').write_text('bad stage')
        with self.assertRaisesRegex(deploy.ReleaseError, 'hash_drift'):
            self.phase('apply')
        self.assert_before()

    def test_mode_drift_stops_apply(self):
        self.phase('prepare')
        self.path(2).chmod(0o600)
        with self.assertRaisesRegex(deploy.ReleaseError, 'target_mode_drift'):
            self.phase('apply')
        self.assert_before()

    def test_mode_drift_stops_entire_rollback(self):
        self.phase('prepare')
        self.phase('apply')
        self.path(2).chmod(0o600)
        with self.assertRaisesRegex(deploy.ReleaseError, 'target_mode_drift'):
            self.phase('rollback')
        deploy.verify_targets(self.home, self.manifest, 'after')

    def test_mode_drift_cannot_be_reported_verified(self):
        self.phase('prepare')
        self.phase('apply')
        self.path(0).chmod(0o600)
        with self.assertRaisesRegex(deploy.ReleaseError, 'target_mode_drift'):
            self.phase('verify')
        self.assertEqual('installed', json.loads(self.receipt().read_text())['phase'])

    def test_cron_drift_stops_apply_and_is_not_restored_by_rollback(self):
        self.phase('prepare')
        self.cron = 'operator change'
        with self.assertRaisesRegex(deploy.ReleaseError, 'cron_changed'):
            self.phase('apply')
        self.assert_before()
        self.cron = 'unchanged'
        self.phase('apply')
        self.cron = 'new operator change'
        self.phase('rollback')
        self.assertEqual('new operator change', self.cron)
        self.assert_before()

    def test_cron_drift_during_apply_rolls_back_files_only(self):
        self.phase('prepare')
        calls = 0
        def changed():
            nonlocal calls
            calls += 1
            return 'unchanged' if calls == 1 else 'operator change'
        with self.assertRaisesRegex(deploy.ReleaseError, 'cron_changed_during_apply'):
            self.phase('apply', cron=changed)
        self.assert_before()

    def test_cron_drift_during_verify_cannot_report_verified(self):
        self.phase('prepare')
        self.phase('apply')
        calls = 0
        def changed():
            nonlocal calls
            calls += 1
            return 'unchanged' if calls == 1 else 'operator change'
        with self.assertRaisesRegex(deploy.ReleaseError, 'cron_changed_during_verify'):
            self.phase('verify', cron=changed)
        self.assertEqual('installed', json.loads(self.receipt().read_text())['phase'])

    def test_partial_crash_requires_explicit_rollback(self):
        self.phase('prepare')
        receipt = json.loads(self.receipt().read_text())
        receipt['phase'] = 'installing'
        self.receipt().write_text(json.dumps(receipt))
        with self.assertRaisesRegex(deploy.ReleaseError, 'requires_rollback'):
            self.phase('apply')
        self.phase('rollback')
        self.assert_before()

    def test_missing_prepare_rejected(self):
        with self.assertRaisesRegex(deploy.ReleaseError, 'prepare_required'):
            self.phase('apply')
        self.assert_before()

    def test_manifest_drift_after_prepare_rejected(self):
        self.phase('prepare')
        self.manifest['base_commit'] = 'changed'
        with self.assertRaisesRegex(deploy.ReleaseError, 'receipt_contract_drift'):
            self.phase('apply')
        self.assert_before()

    def test_symlink_target_rejected(self):
        self.path(1).unlink()
        self.path(1).symlink_to(self.existing)
        with self.assertRaisesRegex(deploy.ReleaseError, 'symlink'):
            self.phase('prepare')
        self.assertEqual('preserve', self.existing.read_text())

    def test_symlink_new_parent_rejected_after_prepare(self):
        self.phase('prepare')
        other = self.home / 'elsewhere'
        other.mkdir()
        self.path(0).parent.parent.mkdir()
        self.path(0).parent.symlink_to(other, target_is_directory=True)
        with self.assertRaisesRegex(deploy.ReleaseError, 'symlink'):
            self.phase('apply')
        self.assertEqual([], list(other.iterdir()))

    def test_symlink_source_rejected(self):
        path = self.source / self.manifest['files'][1]['source']
        path.unlink()
        path.symlink_to(self.existing)
        with self.assertRaisesRegex(deploy.ReleaseError, 'symlink'):
            self.phase('prepare')

    def test_non_directory_target_parent_rejected(self):
        self.path(0).parent.parent.mkdir()
        self.path(0).parent.write_text('unrelated file')
        with self.assertRaisesRegex(deploy.ReleaseError, 'non_directory_parent'):
            self.phase('prepare')

    def test_non_private_backup_root_rejected(self):
        backup = self.home / 'deploy-backups'
        backup.mkdir(mode=0o755)
        backup.chmod(0o755)
        with self.assertRaisesRegex(deploy.ReleaseError, 'backup_parent_not_private'):
            self.phase('prepare')
        self.assert_before()

    def test_shared_release_lock_contention_stops_before_prepare(self):
        lock = self.home / '.servernet-web-release.lock'
        with lock.open('a') as handle:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(deploy.ReleaseError, 'release_lock_busy'):
                self.phase('prepare')
        self.assert_before()

    def test_path_and_hash_manifest_validation(self):
        cases = [
            ('source', '../outside.php', 'invalid_relative_path'),
            ('source', '/etc/passwd', 'invalid_relative_path'),
            ('source', 'website//app/a.php', 'invalid_relative_path'),
            ('source', 'website/./app/a.php', 'invalid_relative_path'),
            ('target', 'app/../../.env', 'invalid_relative_path'),
            ('target', 'app\\Test.php', 'invalid_relative_path'),
            ('target', 'storage/app/Test.php', 'outside_php_package'),
            ('target', 'app/.hidden.php', 'hidden_target'),
            ('target', 'app/Services/Secret.env', 'outside_php_package'),
            ('before', 'bad hash', 'invalid_before_hash'),
            ('after', None, 'invalid_after_hash'),
        ]
        for key, value, expected in cases:
            with self.subTest(key=key, value=value):
                manifest = copy.deepcopy(self.manifest)
                manifest['files'][0][key] = value
                with self.assertRaisesRegex(deploy.ReleaseError, expected):
                    deploy.validate_manifest(manifest)

    def test_duplicate_targets_rejected(self):
        self.manifest['files'].append(copy.deepcopy(self.manifest['files'][0]))
        with self.assertRaisesRegex(deploy.ReleaseError, 'duplicate_target'):
            self.phase('prepare')

    def test_duplicate_json_keys_and_invalid_release_rejected(self):
        path = self.source / 'manifest.json'
        path.write_text('{"release":"bale-manager-20261031","release":"other","files":[]}')
        with self.assertRaisesRegex(deploy.ReleaseError, 'duplicate_json_key'):
            deploy.load_manifest(path)
        path.write_text('{"release":"other","files":[]}')
        with self.assertRaisesRegex(deploy.ReleaseError, 'invalid_manifest_release'):
            deploy.load_manifest(path)

    def test_valid_json_manifest_matches_input(self):
        path = self.source / 'manifest.json'
        path.write_text(json.dumps(self.manifest))
        self.assertEqual(self.manifest, deploy.load_manifest(path))


if __name__ == '__main__':
    unittest.main()
