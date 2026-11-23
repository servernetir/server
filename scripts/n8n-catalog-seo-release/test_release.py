import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('console_release', Path(__file__).with_name('release.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseTest(unittest.TestCase):
    def test_activation_helper_is_pinned_to_this_release_and_matches_its_guard(self):
        root = Path(__file__).resolve().parents[2]
        manifest = json.loads(Path(__file__).with_name('manifest.json').read_text())
        helper = manifest['helpers']['activate.php']
        self.assertEqual(release.PREFIX + 'activate.php', helper['path'])
        data = (root / helper['path']).read_bytes()
        self.assertEqual(helper['sha256'], release.sha(data))
        self.assertIn(("!== '" + release.RELEASE + "'").encode(), data)

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.stage = Path(self.temp.name) / 'stage'
        self.stage.mkdir()
        self.rows = [{'source': 'website/app/Example.php', 'destination': 'servernet_app/app/Example.php',
                      'before': release.sha(b'old'), 'after': release.sha(b'new')},
                     {'source': 'website/app/New.php', 'destination': 'servernet_app/app/New.php',
                      'before': None, 'after': release.sha(b'dependency')}]
        (self.home / 'servernet_app/app').mkdir(parents=True)
        (self.home / self.rows[0]['destination']).write_bytes(b'old')
        (self.stage / 'files').mkdir()
        for index, data in enumerate([b'new', b'dependency']):
            (self.stage / 'files' / str(index)).write_bytes(data)
        self.manifest = {'files': self.rows}
        (self.stage / 'prepared.json').write_text(json.dumps([dict(row, mode=0o644) for row in self.rows]))
        self.patches = [patch.object(release, 'HOME', self.home),
                        patch.object(release, 'fetch', return_value=json.dumps(self.manifest).encode())]
        for handle in self.patches:
            handle.start()
            self.addCleanup(handle.stop)

    def test_install_verify_and_rollback_preserve_unrelated_data(self):
        unrelated = self.home / 'servernet_app/app/Untouched.php'
        unrelated.write_bytes(b'unrelated')
        release.run('apply', 'a' * 40, self.stage)
        release.run('verify', 'a' * 40, self.stage)
        with patch.object(release, 'activate', return_value=[]):
            release.run('rollback', 'a' * 40, self.stage)
        self.assertEqual(b'old', (self.home / self.rows[0]['destination']).read_bytes())
        self.assertFalse((self.home / self.rows[1]['destination']).exists())
        self.assertEqual(b'unrelated', unrelated.read_bytes())

    def test_concurrent_file_change_stops_before_any_writes(self):
        (self.home / self.rows[0]['destination']).write_bytes(b'newer-operator-work')
        with self.assertRaises(AssertionError):
            release.run('apply', 'a' * 40, self.stage)
        self.assertEqual(b'newer-operator-work', (self.home / self.rows[0]['destination']).read_bytes())
        self.assertFalse((self.home / self.rows[1]['destination']).exists())

    def test_payload_failure_restores_already_installed_files(self):
        (self.stage / 'files/1').write_bytes(b'corrupted')
        with self.assertRaises(AssertionError):
            release.run('apply', 'a' * 40, self.stage)
        self.assertEqual(b'old', (self.home / self.rows[0]['destination']).read_bytes())
        self.assertFalse((self.home / self.rows[1]['destination']).exists())


if __name__ == '__main__':
    unittest.main()
