import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    obj = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj


release = module('vps_catalog_release', Path(__file__).with_name('deploy-vps-catalog.py'))


class ReleaseBoundaryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.root = self.base / 'application'
        self.public = self.base / 'public_html'
        self.ops = self.base / 'private'
        for directory in (self.root, self.public, self.ops):
            directory.mkdir()
        self.core = module('vps_catalog_test_core', Path(__file__).with_name('deploy-vpstc.py'))

    def test_shared_core_is_the_exact_reviewed_version(self):
        self.assertEqual(release.CORE_SHA256, hashlib.sha256(Path(__file__).with_name('deploy-vpstc.py').read_bytes()).hexdigest())

    def test_assets_map_to_both_roots_and_unknown_paths_are_rejected(self):
        core = release.configure(self.core, self.ops, self.public)
        self.assertEqual(self.root / 'public/assets/css/vps-catalog.css', core.checked_file(self.root, 'public/assets/css/vps-catalog.css'))
        self.assertEqual(self.public / 'assets/css/vps-catalog.css', core.checked_file(self.root, 'site-public/assets/css/vps-catalog.css'))
        for path in ('../.env', 'site-public/../../.env', 'routes/web.php'):
            with self.assertRaises(core.ReleaseError):
                core.checked_file(self.root, path)

    def test_symlinks_in_either_destination_are_rejected(self):
        core = release.configure(self.core, self.ops, self.public)
        (self.public / 'assets').symlink_to(self.ops, target_is_directory=True)
        with self.assertRaises(core.ReleaseError):
            core.checked_file(self.root, 'site-public/assets/js/choice-hints.js')
        (self.root / 'app').symlink_to(self.ops, target_is_directory=True)
        with self.assertRaises(core.ReleaseError):
            core.checked_file(self.root, 'app/Services/Cloud/CloudPublicQuote.php')

    def test_live_or_snapshot_drift_stops_reconciliation_before_any_install(self):
        relative = 'app/Services/Cloud/CloudPublicQuote.php'
        before = b'reviewed baseline'
        digest = hashlib.sha256(before).hexdigest()
        live = self.root / relative
        live.parent.mkdir(parents=True)
        live.write_bytes(before)
        snapshot = self.ops / 'baseline.php'
        snapshot.write_bytes(before)
        self.core.build_plan = lambda root, target, php, source_reader: source_reader
        with patch.object(release, 'RECONCILED', {relative: ('baseline.php', digest)}):
            core = release.configure(self.core, self.ops, self.public)
            reader = core.build_plan(self.root, 'a' * 40, 'php')
            self.assertEqual(before, reader(release.BASE, relative))
            snapshot.write_bytes(b'new snapshot')
            with self.assertRaisesRegex(core.ReleaseError, 'snapshot_hash_changed'):
                reader(release.BASE, relative)
            live.write_bytes(b'new concurrent edit')
            with self.assertRaisesRegex(core.ReleaseError, 'live_baseline_changed'):
                core.build_plan(self.root, 'a' * 40, 'php')


if __name__ == '__main__':
    unittest.main()
