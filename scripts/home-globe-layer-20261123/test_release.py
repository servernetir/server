"""Release preservation and concurrent-edit protection on disposable files."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('studio_release', Path(__file__).with_name('release.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.home = Path(self.temporary.name)
        self.stage = self.home / 'stage'
        self.stage.mkdir()
        self.original = b'live changes\n<div class="sn-atlas sn-home">\nexisting content\n'
        self.target = self.home / 'servernet_app/resources/views/pages/home.blade.php'
        self.target.parent.mkdir(parents=True)
        self.target.write_bytes(self.original)
        self.partial = b'synthetic partial'
        after = self.original.replace(b'<div class="sn-atlas sn-home">\n', b'<div class="sn-atlas sn-home">\n@include(\'partials.studio-home\')\n')
        self.manifest = {'files': [
            {'source': 'website/resources/views/partials/studio-home.blade.php', 'destination': 'servernet_app/resources/views/partials/studio-home.blade.php', 'before': None, 'after': release.sha(self.partial)},
            {'source': 'website/resources/views/pages/home.blade.php', 'destination': 'servernet_app/resources/views/pages/home.blade.php', 'before': release.sha(self.original), 'after': release.sha(after), 'patch_home': True}], 'helpers': {}, 'home_edits': [['<div class="sn-atlas sn-home">\n', '<div class="sn-atlas sn-home">\n@include(\'partials.studio-home\')\n']]}
        def fetch(commit, path):
            if path.endswith('manifest.json'): return json.dumps(self.manifest).encode()
            if path.endswith('partials/studio-home.blade.php'): return self.partial
            raise AssertionError('Unexpected source request')
        self.patches = [patch.object(release, 'HOME', self.home), patch.object(release, 'fetch', fetch), patch.object(release.subprocess, 'run')]
        for item in self.patches: item.start()
        self.addCleanup(lambda: [item.stop() for item in reversed(self.patches)])
        self.addCleanup(self.temporary.cleanup)

    def test_install_and_rollback_preserve_exact_live_content(self):
        release.run('prepare', 'a' * 40, self.stage)
        release.run('apply', 'a' * 40, self.stage)
        release.run('verify', 'a' * 40, self.stage)
        self.assertIn(b'live changes', self.target.read_bytes())
        self.assertIn(b"@include('partials.studio-home')", self.target.read_bytes())
        with patch.object(release, 'activate', return_value=[]): release.run('rollback', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), self.original)
        self.assertFalse((self.home / self.manifest['files'][0]['destination']).exists())

    def test_concurrent_edit_stops_before_installing_any_dependency(self):
        release.run('prepare', 'a' * 40, self.stage)
        self.target.write_bytes(b'newer live work')
        with self.assertRaisesRegex(AssertionError, 'Unexpected live bytes'): release.run('apply', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), b'newer live work')
        self.assertFalse((self.home / self.manifest['files'][0]['destination']).exists())

    def test_rollback_refuses_a_newer_live_edit_before_any_mutation(self):
        release.run('prepare', 'a' * 40, self.stage)
        release.run('apply', 'a' * 40, self.stage)
        self.target.write_bytes(b'newer live work')
        with self.assertRaisesRegex(AssertionError, 'Unexpected live bytes'): release.run('rollback', 'a' * 40, self.stage)
        self.assertEqual(self.target.read_bytes(), b'newer live work')
        self.assertTrue((self.home / self.manifest['files'][0]['destination']).exists())


if __name__ == '__main__': unittest.main()
