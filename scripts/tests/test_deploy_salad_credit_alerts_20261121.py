"""Offline transaction rehearsal with exact source bytes and disposable directories."""
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('credit_release', REPO / 'scripts/deploy-salad-credit-alerts-20261121.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
TARGET = '1' * 40


class CreditReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='credit-release-rehearsal-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'app'
        self.root.mkdir()
        self.core = release.load_scoped_core(REPO / 'scripts/deploy-vpstc.py')
        self.sources = {}
        for path in release.SOURCES:
            source = subprocess.run(['git', 'show', release.BASE + ':website/' + path], cwd=REPO, capture_output=True)
            self.sources[(release.BASE, path)] = source.stdout if source.returncode == 0 else None
            self.sources[(TARGET, path)] = (REPO / 'website' / path).read_bytes()
            if source.returncode == 0:
                live = self.root / path
                live.parent.mkdir(parents=True, exist_ok=True)
                live.write_bytes(source.stdout.replace(b'<?php\n', b'<?php\n// Live-only marker.\n', 1))
        self.before = {p: (self.root / p).read_bytes() if (self.root / p).exists() else None for p in release.SOURCES}

    def plan(self):
        with self.core.release_locks(self.root):
            return self.core.build_plan(self.root, TARGET, os.environ['CREDIT_TEST_PHP'], lambda c, p: self.sources[(c, p)])

    def test_plan_install_and_rollback_preserve_live_drift(self):
        folder, plan = self.plan()
        self.assertEqual(4, len(plan['files']))
        for path, data in self.before.items():
            self.assertEqual(data, (self.root / path).read_bytes() if (self.root / path).exists() else None)
        with self.core.release_locks(self.root):
            result = self.core.apply_plan(self.root, folder, self.core.load_plan(self.root, TARGET, folder, plan['plan_sha256']), os.environ['CREDIT_TEST_PHP'])
        self.assertEqual('installed', result['status'])
        self.assertEqual(4, result['files_installed'])
        for path, data in self.before.items():
            if data is not None:
                self.assertIn(b'// Live-only marker.', (self.root / path).read_bytes())
        with self.core.release_locks(self.root):
            self.core.explicit_rollback(self.root, folder, self.core.load_plan(self.root, TARGET, folder, plan['plan_sha256']))
        for path, data in self.before.items():
            self.assertEqual(data, (self.root / path).read_bytes() if (self.root / path).exists() else None)

    def test_unreviewed_source_bytes_are_rejected(self):
        path = next(iter(release.SOURCES))
        self.sources[(TARGET, path)] += b'\n// Unreviewed change.\n'
        with self.assertRaisesRegex(self.core.ReleaseError, 'pinned_source_mismatch'):
            self.plan()
        self.assertFalse((self.root / path).exists())

    def test_live_change_after_plan_is_rejected(self):
        folder, plan = self.plan()
        path = self.root / 'app/Services/Cloud/SaladClient.php'
        changed = path.read_bytes() + b'\n// Concurrent edit.\n'
        path.write_bytes(changed)
        with self.core.release_locks(self.root), self.assertRaises(self.core.ReleaseError):
            self.core.apply_plan(self.root, folder, self.core.load_plan(self.root, TARGET, folder, plan['plan_sha256']), os.environ['CREDIT_TEST_PHP'])
        self.assertEqual(changed, path.read_bytes())


if __name__ == '__main__':
    unittest.main()
