"""Exercise deployment and rollback using disposable files and no network."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import sys
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('search_release', HERE / 'release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
spec = importlib.util.spec_from_file_location('search_plan', HERE / 'plan.py')
planner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(planner)


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.home = Path(self.temp.name)
        self.root = self.home / 'servernet_app'
        self.stage = self.home / 'private-stage'
        self.stage.mkdir()
        self.target = self.root / 'resources/views/pages/gpu.blade.php'
        self.target.parent.mkdir(parents=True)
        self.before = b'private live overlay\noriginal-guide\ncapacity-widget\n'
        self.after = self.before.replace(b'original-guide', b'new-guide')
        self.target.write_bytes(self.before)
        self.new = b'synthetic new partial'
        self.new_target = self.root / 'resources/views/partials/new-guide.blade.php'
        self.manifest = {'files': [
            {'source': 'website/resources/views/partials/new-guide.blade.php', 'destination': str(self.new_target.relative_to(self.home)), 'before': None, 'after': release.sha(self.new)},
            {'source': 'website/resources/views/pages/gpu.blade.php', 'destination': str(self.target.relative_to(self.home)), 'before': release.sha(self.before), 'after': release.sha(self.after), 'patch': True},
        ], 'helpers': {}, 'views': []}
        def fetch(commit, path):
            if path.endswith('manifest.json'):
                return json.dumps(self.manifest).encode()
            if path.endswith('edits.json'):
                return json.dumps({self.manifest['files'][1]['source']: [{'old': 'original-guide', 'new': 'new-guide', 'count': 1}]}).encode()
            if path.endswith('partials/new-guide.blade.php'):
                return self.new
            raise AssertionError('Unexpected network source')
        self.patches = [patch.object(release, 'HOME', self.home), patch.object(release, 'ROOT', self.root),
                        patch.object(release, 'fetch', fetch), patch.object(release.subprocess, 'run'),
                        patch.object(release, 'activate', return_value=[])]
        for p in self.patches:
            p.start()
        self.addCleanup(lambda: [p.stop() for p in reversed(self.patches)])
        self.addCleanup(self.temp.cleanup)

    def test_prepare_does_not_install_and_apply_then_rollback_preserve_live_overlay(self):
        release.run('prepare', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), self.before)
        self.assertFalse(self.new_target.exists())
        release.run('apply', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), self.after)
        self.assertEqual(self.new_target.read_bytes(), self.new)
        self.assertIn(b'capacity-widget', self.target.read_bytes())
        release.run('verify', 'a' * 40, self.stage)
        release.run('rollback', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), self.before)
        self.assertFalse(self.new_target.exists())

    def test_source_drift_stops_before_the_first_dependency_is_installed(self):
        release.run('prepare', 'a' * 40, self.stage)
        self.target.write_bytes(b'newer production change')
        with self.assertRaisesRegex(AssertionError, 'Unexpected live bytes'):
            release.run('apply', 'a' * 40, self.stage)
        self.assertFalse(self.new_target.exists())
        self.assertEqual(self.target.read_bytes(), b'newer production change')

    def test_activation_failure_restores_callers_before_retiring_new_dependencies(self):
        release.run('prepare', 'a' * 40, self.stage)
        phases = []
        def activation(stage, manifest, phase):
            phases.append(phase)
            if phase == 'after':
                raise RuntimeError('synthetic activation failure')
            self.assertEqual(self.target.read_bytes(), self.before)
            self.assertTrue(self.new_target.exists())
            return []
        with patch.object(release, 'activate', side_effect=activation):
            with self.assertRaisesRegex(RuntimeError, 'synthetic activation failure'):
                release.run('apply', 'a' * 40, self.stage)
        self.assertEqual(phases, ['after', 'before'])
        self.assertEqual(self.target.read_bytes(), self.before)
        self.assertFalse(self.new_target.exists())

    def test_newer_live_change_blocks_rollback_before_mutation(self):
        release.run('prepare', 'a' * 40, self.stage)
        release.run('apply', 'a' * 40, self.stage)
        self.target.write_bytes(b'newer production change')
        with self.assertRaisesRegex(AssertionError, 'Unexpected live bytes'):
            release.run('rollback', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), b'newer production change')
        self.assertTrue(self.new_target.exists())

    def test_symlinked_parent_is_rejected(self):
        actual = self.root / 'actual-pages'
        self.target.parent.rename(actual)
        self.target.parent.symlink_to(actual, target_is_directory=True)
        with self.assertRaisesRegex(AssertionError, 'Symlink target'):
            release.run('prepare', 'a' * 40, self.stage)
        self.assertFalse(self.new_target.exists())

    def test_crlf_layout_patch_preserves_unrelated_markup(self):
        before = 'unchanged-head\r\n@php\r\n    $siteCssSource = old;\r\n@endphp\r\n@stack(\'head\')\r\nunchanged-tail\r\n'
        source = '@php\n    $siteCssSource = new;\n@endphp\n@stack(\'head\')'
        edits = planner.edits_for('resources/views/layouts/site.blade.php', before, source)
        self.assertEqual(len(edits), 1)
        after = before.replace(edits[0]['old'], edits[0]['new'])
        self.assertTrue(after.startswith('unchanged-head\r\n'))
        self.assertTrue(after.endswith('unchanged-tail\r\n'))
        self.assertEqual(after.count('\n'), after.count('\r\n'))

    def test_manifest_helpers_pin_the_current_reviewed_bytes(self):
        manifest = json.loads((HERE / 'manifest.json').read_text())
        for helper in manifest['helpers'].values():
            self.assertEqual(hashlib.sha256((HERE.parents[1] / helper['path']).read_bytes()).hexdigest(), helper['sha256'])
        self.assertEqual(len(manifest['files']), 17)
        self.assertEqual(len(manifest['views']), 11)


if __name__ == '__main__':
    unittest.main()
