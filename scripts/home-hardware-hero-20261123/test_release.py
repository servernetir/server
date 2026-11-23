"""Exercise the bounded manifest and release/rollback on disposable files."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
spec = importlib.util.spec_from_file_location('parts_release', HERE / 'release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ManifestTests(unittest.TestCase):
    def test_manifest_covers_only_reviewed_bytes_and_both_asset_roots(self):
        manifest = json.loads((HERE / 'manifest.json').read_text())
        with patch.object(release, 'fetch', return_value=json.dumps(manifest).encode()):
            self.assertEqual(release.manifest_for('a' * 40), manifest)
        self.assertEqual(len(manifest['files']), 14)
        self.assertEqual(len({row['destination'] for row in manifest['files']}), 14)
        self.assertEqual(sum(row.get('patch', False) for row in manifest['files']), 1)
        # The immutable original release remains available at its pinned SHA.
        # A narrowly scoped subsequent static release supersedes exactly two
        # assets in both roots; require the exact previous -> new hash chain.
        successor = json.loads((REPO / 'scripts/home-hardware-hero-fit-20261123/manifest.json').read_text())['files']
        next_rows = {(row['source'], row['destination']): row for row in successor}
        self.assertEqual(len(next_rows), 4)
        for row in manifest['files']:
            expected = row.get('source_sha256', row['after'])
            following = next_rows.pop((row['source'], row['destination']), None)
            if following is not None:
                self.assertEqual(following['before'], expected)
                expected = following['after']
            self.assertEqual(release.sha((REPO / row['source']).read_bytes()), expected)
        self.assertEqual(next_rows, {})
        for helper in manifest['helpers'].values():
            self.assertEqual(release.sha((REPO / helper['path']).read_bytes()), helper['sha256'])

    def test_activation_bootstraps_before_route_cache_check_and_stays_scoped(self):
        code = (HERE / 'activate.php').read_text()
        self.assertLess(code.index('->bootstrap();'), code.index('$app->routesAreCached()'))
        self.assertNotIn('->flush(', code)
        self.assertNotIn('opcache_reset(', code)
        self.assertIn("['/', 'en', 'tr']", code)


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.home = Path(directory.name)
        self.stage = self.home / 'stage'
        self.stage.mkdir()
        self.original = b'live-only unrelated edit\n@include(\'partials.home-email-slide\')\noriginal content\n'
        self.old = "@include('partials.home-email-slide')\n"
        self.new = self.old + "@include('partials.home-parts-slide')\n"
        self.patched = self.original.replace(self.old.encode(), self.new.encode())
        self.repo_source = b'git version\n' + self.new.encode()
        self.dependency = b'synthetic new partial'
        self.scope = [('website/resources/views/partials/home-parts-slide.blade.php', 'servernet_app/resources/views/partials/home-parts-slide.blade.php'),
                      ('website/resources/views/pages/home.blade.php', 'servernet_app/resources/views/pages/home.blade.php')]
        self.target = self.home / self.scope[1][1]
        self.dep = self.home / self.scope[0][1]
        self.target.parent.mkdir(parents=True)
        self.target.write_bytes(self.original)
        self.manifest = {'files': [
            {'source': self.scope[0][0], 'destination': self.scope[0][1], 'before': None, 'after': release.sha(self.dependency)},
            {'source': self.scope[1][0], 'destination': self.scope[1][1], 'patch': True,
             'source_sha256': release.sha(self.repo_source), 'before': release.sha(self.original), 'after': release.sha(self.patched)}], 'helpers': {}}

        def fetch(commit, path):
            if path.endswith('/manifest.json'): return json.dumps(self.manifest).encode()
            if path.endswith('/edits.json'): return json.dumps({self.scope[1][0]: [{'old': self.old, 'new': self.new, 'count': 1}]}).encode()
            if path == self.scope[0][0]: return self.dependency
            if path == self.scope[1][0]: return self.repo_source
            raise AssertionError('Unexpected fetch')

        for item in [patch.object(release, 'HOME', self.home), patch.object(release, 'SCOPE', self.scope),
                     patch.object(release, 'HELPERS', {}), patch.object(release, 'fetch', fetch),
                     patch.object(release.subprocess, 'run'), patch.object(release, 'activate', return_value=[])]:
            item.start()
            self.addCleanup(item.stop)

    def test_install_verify_and_rollback_preserve_live_edits(self):
        release.run('install', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), self.patched)
        self.assertEqual(self.dep.read_bytes(), self.dependency)
        release.run('verify', 'a' * 40, self.stage)
        release.run('rollback', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), self.original)
        self.assertFalse(self.dep.exists())
        self.assertEqual((self.stage / 'retired-dependencies' / '0').read_bytes(), self.dependency)

    def test_activation_failure_recompiles_original_before_retiring_new_dependency(self):
        phases = []

        def activate(stage, manifest, phase):
            phases.append(phase)
            if phase == 'after': raise RuntimeError('synthetic activation failure')
            self.assertEqual(self.target.read_bytes(), self.original)
            self.assertEqual(self.dep.read_bytes(), self.dependency)
            return []

        with patch.object(release, 'activate', side_effect=activate):
            with self.assertRaisesRegex(RuntimeError, 'synthetic activation failure'):
                release.run('install', 'a' * 40, self.stage)
        self.assertEqual(phases, ['after', 'before'])
        self.assertEqual(self.target.read_bytes(), self.original)
        self.assertFalse(self.dep.exists())
        self.assertFalse((self.stage / 'receipt.json').exists())

    def test_concurrent_edit_stops_before_any_install(self):
        records = release.prepare('a' * 40, self.stage, self.manifest)
        self.target.write_bytes(b'concurrent edit')
        with self.assertRaisesRegex(AssertionError, 'Unexpected live bytes'):
            release.apply(self.stage, self.manifest, records, 'a' * 40)
        self.assertFalse(self.dep.exists())
        self.assertEqual(self.target.read_bytes(), b'concurrent edit')

    def test_rollback_refuses_newer_work(self):
        release.run('install', 'a' * 40, self.stage)
        self.target.write_bytes(b'newer live edit')
        with self.assertRaisesRegex(AssertionError, 'Unexpected live bytes'):
            release.run('rollback', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), b'newer live edit')
        self.assertEqual(self.dep.read_bytes(), self.dependency)

    def test_stage_payload_tampering_stops_before_install(self):
        records = release.prepare('a' * 40, self.stage, self.manifest)
        (self.stage / 'files' / '1').write_bytes(b'bad staged content')
        with self.assertRaisesRegex(AssertionError, 'Staged payload changed'):
            release.apply(self.stage, self.manifest, records, 'a' * 40)
        self.assertFalse(self.dep.exists())
        self.assertEqual(self.target.read_bytes(), self.original)

    def test_manifest_rejects_extra_destination(self):
        self.manifest['files'].append(dict(self.manifest['files'][0], destination='public_html/unrelated.php'))
        with self.assertRaisesRegex(AssertionError, 'Invalid release scope'):
            release.manifest_for('a' * 40)

    def test_interrupted_stage_requires_inspection_not_automatic_retry(self):
        release.prepare('a' * 40, self.stage, self.manifest)
        with self.assertRaisesRegex(AssertionError, 'Existing/incomplete release'):
            release.run('install', 'a' * 40, self.stage)
        self.assertFalse(self.dep.exists())
        self.assertEqual(self.target.read_bytes(), self.original)


if __name__ == '__main__':
    unittest.main()
