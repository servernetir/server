"""Disposable filesystem tests; no deployment, Laravel bootstrap or network."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

TOOL = Path(__file__).with_name('console-next-install.py')
SPEC = importlib.util.spec_from_file_location('console_next_install', TOOL)
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)


class GuardedInstallerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.home = Path(self.temp.name)
        self.app = self.home / 'servernet_app'
        self.web = self.home / 'public_html'
        self.private = self.home / 'private-backups'
        self.app.mkdir()
        self.web.mkdir()
        for root, relative in ((self.app, 'artisan'), (self.app, 'bootstrap/app.php'),
                               (self.app, 'vendor/autoload.php'), (self.web, 'index.php')):
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'protected installation marker')
        self.env = self.app / '.env'
        self.env.write_bytes(b'PRIVATE_DO_NOT_READ_OR_CHANGE')
        self.outside = self.home / 'unrelated.txt'
        self.outside.write_bytes(b'unchanged outside release')
        self.roots = release.roots(self.app, self.web)
        self.marker = self.app / 'storage/app/console-next.state'
        self.marker.parent.mkdir(parents=True)
        self.marker.write_text('disabled\n')
        self.entries, self.payload, self.before = [], {}, {}
        for source in release.ALLOWLIST:
            root = 'webroot' if source.startswith('website/public/') else 'app'
            relative = source[len('website/public/'):] if root == 'webroot' else source[len('website/'):]
            data = ('new release: ' + source + '\n').encode()
            original = ('previous production: ' + source + '\n').encode() if source in release.ALLOWLIST[-7:] else None
            entry = {'source_path': source, 'target_root': root, 'target_path': relative,
                     'destination_relative_to_home': ('public_html/' if root == 'webroot' else 'servernet_app/') + relative,
                     'expected_before_sha256_or_absent': release.digest(original) if original is not None else None,
                     'after_sha256': release.digest(data), 'operation': 'replace' if original is not None else 'add',
                     'payload_path': 'payload/' + source, 'mode': 0o644}
            self.entries.append(entry)
            self.payload[source] = data
            self.before[source] = original
            if original is not None:
                target = release.destination(self.roots, entry)
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(original)
        self.manifest = {'schema': 1, 'release_id': 'console-next-' + 'a' * 16, 'base_sha': 'b' * 40,
                         'installer_sha256': release.digest(TOOL.read_bytes()), 'files': self.entries}
        self.archive = self.home / 'console-next-release.zip'
        self.write_archive()

    def tearDown(self):
        self.temp.cleanup()

    def write_archive(self, extra=None, tamper=None):
        with zipfile.ZipFile(self.archive, 'w') as bundle:
            bundle.writestr('manifest.json', json.dumps(self.manifest))
            bundle.writestr('installer.py', TOOL.read_bytes())
            for entry in self.entries:
                bundle.writestr(entry['payload_path'], tamper if tamper is not None and entry is self.entries[0]
                                else self.payload[entry['source_path']])
            if extra:
                bundle.writestr(extra, b'unapproved bytes')

    def snapshot(self):
        return {str(path.relative_to(self.home)): (path.read_bytes(), stat.S_IMODE(path.stat().st_mode))
                for path in self.home.rglob('*') if path.is_file() and not path.is_symlink()}

    def assert_protected(self):
        self.assertEqual(b'PRIVATE_DO_NOT_READ_OR_CHANGE', self.env.read_bytes())
        self.assertEqual(b'protected installation marker', (self.web / 'index.php').read_bytes())
        self.assertEqual(b'unchanged outside release', self.outside.read_bytes())

    def install(self):
        return release.install(self.manifest, self.payload, self.roots, self.private)

    def test_default_preflight_loads_verified_archive_without_any_writes(self):
        self.marker.unlink()
        before = self.snapshot()
        manifest, payload = release.load_release(self.archive)
        result = release.preflight(manifest, self.roots)
        self.assertEqual('preflight_passed', result['status'])
        self.assertEqual(len(release.ALLOWLIST), len(payload))
        self.assertTrue(all(item['state'] == 'base' for item in result['files']))
        self.assertEqual(before, self.snapshot())
        self.assertFalse(self.private.exists())

    def test_cli_without_apply_performs_only_readonly_preflight(self):
        before = self.snapshot()
        argv = ['installer.py', '--archive', str(self.archive), '--app-root', str(self.app), '--webroot', str(self.web)]
        with patch.object(release.sys, 'argv', argv), contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(0, release.main())
        self.assertEqual('preflight_passed', json.loads(output.getvalue())['status'])
        self.assertEqual(before, self.snapshot())

    def test_unknown_production_drift_rejects_before_backup_or_target_writes(self):
        target = release.destination(self.roots, self.entries[-1])
        target.write_bytes(b'someone else changed live routes')
        before = self.snapshot()
        with self.assertRaisesRegex(release.ReleaseError, 'Concurrent/unrecognized'):
            self.install()
        self.assertEqual(before, self.snapshot())
        self.assertFalse(self.private.exists())

    def test_apply_uses_atomic_replacements_dependencies_first_routes_last_and_is_idempotent(self):
        observed = []
        original_replace = os.replace

        def watch(source, target, *args, **kwargs):
            target = Path(target)
            entry = next((item for item in self.entries if release.destination(self.roots, item) == target), None)
            if entry:
                self.assertEqual(self.payload[entry['source_path']], Path(source).read_bytes())
                observed.append(entry['source_path'])
            return original_replace(source, target, *args, **kwargs)

        with patch.object(release.os, 'replace', side_effect=watch):
            result = self.install()
        self.assertEqual('installed_bytes_only', result['status'])
        self.assertEqual('website/routes/web.php', observed[-1])
        self.assertTrue(all(self.before[source] is None for source in observed[:12]))
        self.assertEqual(len(self.entries), len(observed))
        for entry in self.entries:
            target = release.destination(self.roots, entry)
            self.assertEqual(entry['after_sha256'], release.current_hash(target))
            self.assertEqual(0o644, stat.S_IMODE(target.stat().st_mode))
        receipt = Path(result['receipt'])
        self.assertEqual(0o700, stat.S_IMODE(receipt.parent.stat().st_mode))
        self.assertEqual(0o600, stat.S_IMODE(receipt.stat().st_mode))
        before = self.snapshot()
        self.assertEqual('already_installed', self.install()['status'])
        self.assertEqual(before, self.snapshot())
        self.assert_protected()

    def test_preflight_reports_route_cache_without_clearing_or_reading_it(self):
        route_cache = self.app / 'bootstrap/cache/routes-v7.php'
        route_cache.parent.mkdir()
        route_cache.write_bytes(b'private cached route data: do not touch')
        result = release.preflight(self.manifest, self.roots)
        self.assertEqual(['routes-v7.php'], result['route_cache_files'])
        self.assertEqual(b'private cached route data: do not touch', route_cache.read_bytes())

    def test_rollback_preflight_is_readonly_and_apply_restores_only_guarded_shared_files(self):
        installed = self.install()
        before = self.snapshot()
        result = release.rollback(installed['receipt'], self.roots, self.private)
        self.assertEqual('rollback_preflight_passed', result['status'])
        self.assertEqual(before, self.snapshot())
        result = release.rollback(installed['receipt'], self.roots, self.private, apply=True)
        self.assertEqual('rolled_back_bytes_only', result['status'])
        self.assertEqual(7, result['restored_count'])
        for entry in self.entries:
            target = release.destination(self.roots, entry)
            original = self.before[entry['source_path']]
            self.assertEqual(original if original is not None else self.payload[entry['source_path']], target.read_bytes())
        self.assertEqual(0, release.rollback(installed['receipt'], self.roots, self.private, apply=True)['restored_count'])
        self.assert_protected()

    def test_rollback_rejects_newer_live_changes_before_restoring_any_file(self):
        installed = self.install()
        release.destination(self.roots, self.entries[-1]).write_bytes(b'newer production work')
        before = self.snapshot()
        with self.assertRaisesRegex(release.ReleaseError, 'changed after installation'):
            release.rollback(installed['receipt'], self.roots, self.private, apply=True)
        self.assertEqual(before, self.snapshot())
        self.assert_protected()

    def test_rejects_symlink_targets_and_ancestors_without_writing_outside(self):
        target = release.destination(self.roots, self.entries[0])
        target.parent.mkdir(parents=True, exist_ok=True)
        target.symlink_to(self.outside)
        with self.assertRaisesRegex(release.ReleaseError, 'Symlinks'):
            self.install()
        target.unlink()
        target.parent.rmdir()
        target.parent.symlink_to(self.home)
        with self.assertRaisesRegex(release.ReleaseError, 'Symlinks'):
            self.install()
        self.assert_protected()

    def test_archive_traversal_extra_files_and_payload_tampering_are_rejected(self):
        for extra in ('../outside.php', 'payload/website/.env', 'unexpected.php'):
            self.write_archive(extra=extra)
            with self.assertRaises(release.ReleaseError):
                release.load_release(self.archive)
        self.write_archive(tamper=b'changed payload')
        with self.assertRaisesRegex(release.ReleaseError, 'Payload checksum'):
            release.load_release(self.archive)
        self.assert_protected()

    def test_manifest_cannot_redirect_allowed_source_into_other_paths(self):
        self.entries[0]['target_path'] = '../outside.php'
        self.write_archive()
        with self.assertRaisesRegex(release.ReleaseError, 'mapping'):
            release.load_release(self.archive)
        self.assert_protected()

    def test_active_marker_blocks_install_and_rollback_without_changing_state(self):
        marker = self.app / 'storage/app/console-next.state'
        marker.parent.mkdir(parents=True, exist_ok=True)
        marker.write_text('enabled\n')
        with self.assertRaisesRegex(release.ReleaseError, 'explicit disabled marker'):
            self.install()
        self.assertFalse(self.private.exists())
        marker.write_text('disabled\n')
        installed = self.install()
        marker.write_text('enabled\n')
        before = self.snapshot()
        with self.assertRaisesRegex(release.ReleaseError, 'Disable the candidate'):
            release.rollback(installed['receipt'], self.roots, self.private, apply=True)
        self.assertEqual(before, self.snapshot())

    def test_backup_must_be_private_and_separate_from_both_destination_roots(self):
        for location in (self.web / 'backup', self.app / 'backup', self.home):
            with self.assertRaises(release.ReleaseError):
                release.install(self.manifest, self.payload, self.roots, location)
        self.private.mkdir(mode=0o755)
        self.private.chmod(0o755)
        with self.assertRaisesRegex(release.ReleaseError, 'private directory'):
            self.install()
        self.assert_protected()

    def test_receipt_crash_window_can_recover_atomic_writes_from_exact_hashes(self):
        installed = self.install()
        receipt_path = Path(installed['receipt'])
        receipt = json.loads(receipt_path.read_text())
        receipt['complete'] = False
        for change in receipt['changes']:
            change['written'] = False
        release.save_receipt(receipt_path, receipt)
        restored = release.rollback(receipt_path, self.roots, self.private, apply=True)
        self.assertEqual(7, restored['restored_count'])
        self.assert_protected()

    def test_builder_round_trip_is_deterministic_and_excludes_unreviewed_files(self):
        builder_path = TOOL.with_name('build-console-next-release.py')
        spec = importlib.util.spec_from_file_location('console_next_builder', builder_path)
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        checkout = self.home / 'disposable-source'
        checkout.mkdir()
        for source, content in self.before.items():
            if content is not None:
                path = checkout / source
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(content)

        def git(*args):
            return subprocess.run(['git', '-C', str(checkout), *args], check=True, capture_output=True).stdout.decode().strip()

        git('init', '--quiet')
        git('add', 'website')
        git('-c', 'user.name=Release Test', '-c', 'user.email=release-test@example.invalid',
            'commit', '--quiet', '-m', 'Disposable reviewed base')
        base = git('rev-parse', 'HEAD')
        for source, content in self.payload.items():
            path = checkout / source
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(content)
        (checkout / 'website/.env').write_text('FAKE_PRIVATE_CONTROL_MUST_NOT_ENTER_RELEASE')
        (checkout / 'website/public/index.php').write_text('environment-specific entrypoint; excluded')
        outputs = [self.home / 'bundle-a', self.home / 'bundle-b']
        with patch.object(builder, 'REPOSITORY', checkout), contextlib.redirect_stdout(io.StringIO()):
            for output in outputs:
                builder.build(base, output)
        one, two = [output / 'console-next-release.zip' for output in outputs]
        self.assertEqual(one.read_bytes(), two.read_bytes())
        manifest, payload = release.load_release(one)
        self.assertEqual(base, manifest['base_sha'])
        self.assertEqual(set(release.ALLOWLIST), set(payload))
        with zipfile.ZipFile(one) as bundle:
            self.assertFalse(any('/.env' in name or name.endswith('/index.php') for name in bundle.namelist()))
        self.assertEqual(self.payload, payload)

    def test_prepare_disabled_is_explicit_private_no_replace_and_readonly_by_default(self):
        self.marker.unlink()
        before = self.snapshot()
        self.assertEqual('disabled_marker_preflight_passed', release.prepare_disabled(self.roots)['status'])
        self.assertEqual(before, self.snapshot())
        with self.assertRaisesRegex(release.ReleaseError, 'explicit disabled marker'):
            self.install()
        self.assertEqual('disabled_marker_prepared', release.prepare_disabled(self.roots, apply=True)['status'])
        self.assertEqual(b'disabled\n', self.marker.read_bytes())
        self.assertEqual(0o600, stat.S_IMODE(self.marker.stat().st_mode))
        self.marker.write_text('enabled\n')
        with self.assertRaisesRegex(release.ReleaseError, 'cannot override'):
            release.prepare_disabled(self.roots, apply=True)
        self.marker.write_text('unknown\n')
        with self.assertRaisesRegex(release.ReleaseError, 'Unrecognized'):
            release.prepare_disabled(self.roots, apply=True)
        self.assert_protected()

    def test_canonical_per_app_lock_cannot_be_bypassed_by_different_backup_roots(self):
        with release.deployment_lock(self.roots):
            for backup in (self.private, self.home / 'different-private-backups'):
                with self.assertRaisesRegex(release.ReleaseError, 'deployment lock'):
                    release.install(self.manifest, self.payload, self.roots, backup)
            with self.assertRaisesRegex(release.ReleaseError, 'deployment lock'):
                with release.deployment_lock(self.roots):
                    pass
        self.assertFalse(self.private.exists())

    def test_canonical_lock_rejects_hardlinks_without_changing_outside_permissions(self):
        lock = self.app / 'storage/app/.console-next-release.lock'
        self.outside.chmod(0o600)
        os.link(self.outside, lock)
        before = self.snapshot()
        with self.assertRaisesRegex(release.ReleaseError, 'one link'):
            self.install()
        self.assertEqual(before, self.snapshot())
        self.assert_protected()

    def test_rollback_stops_if_a_noncooperating_actor_changes_activation_between_writes(self):
        installed = self.install()
        original_write = release.atomic_write
        restored = []

        def activate_after_first_restore(path, data, mode=0o600):
            original_write(path, data, mode)
            if path in [release.destination(self.roots, item) for item in self.entries[-7:]]:
                restored.append(path)
                self.marker.write_text('enabled\n')

        with patch.object(release, 'atomic_write', side_effect=activate_after_first_restore):
            with self.assertRaisesRegex(release.ReleaseError, 'state changed concurrently'):
                release.rollback(installed['receipt'], self.roots, self.private, apply=True)
        self.assertEqual(1, len(restored))
        self.assert_protected()


if __name__ == '__main__':
    unittest.main()
