import importlib.util
import json
from pathlib import Path
import tempfile
import sys
sys.dont_write_bytecode = True
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('araz_recovery_release', Path(__file__).with_name('deploy.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
base = release.base


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.source = Path(self.temp.name) / 'source'
        target = release.TARGETS[0]
        self.package = {'release': release.RELEASE, 'base_commit': release.BASE_COMMIT, 'files': [
            {'target': target, 'source': release.SOURCES[target],
             'before': base.digest(b'old'), 'after': base.digest(b'new')}]}
        self.target = self.home / 'servernet_app' / target
        self.target.parent.mkdir(parents=True)
        self.target.write_bytes(b'old')
        self.file = self.source / release.SOURCES[target]
        self.file.parent.mkdir(parents=True)
        self.file.write_bytes(b'new')
        self.baseline = patch.dict(release.EXPECTED_BEFORE, {target: base.digest(b'old')})
        self.baseline.start()
        self.addCleanup(self.baseline.stop)
        self.save()

    def save(self):
        (self.source / release.MANIFEST).write_text(json.dumps(self.package))

    def phase(self, phase):
        return release.run(self.home, self.source, phase, lint=lambda p: None, observe_cron=lambda: 'same')

    def test_prepare_apply_verify_rollback_preserve_scope(self):
        self.assertEqual('prepared', self.phase('prepare')['phase'])
        self.assertEqual(b'old', self.target.read_bytes())
        self.assertEqual('verified', self.phase('apply')['phase'])
        self.assertEqual(b'new', self.target.read_bytes())
        self.assertEqual('verified', self.phase('verify')['phase'])
        self.assertEqual('rolled_back', self.phase('rollback')['phase'])
        self.assertEqual(b'old', self.target.read_bytes())

    def test_live_drift_before_prepare_is_preserved(self):
        self.target.write_bytes(b'concurrent')
        with self.assertRaises(base.ReleaseError): self.phase('prepare')
        self.assertEqual(b'concurrent', self.target.read_bytes())

    def test_live_drift_after_prepare_is_preserved(self):
        self.phase('prepare')
        self.target.write_bytes(b'concurrent')
        with self.assertRaises(base.ReleaseError): self.phase('apply')
        self.assertEqual(b'concurrent', self.target.read_bytes())

    def test_source_drift_is_rejected(self):
        self.phase('prepare')
        self.file.write_bytes(b'unreviewed')
        with self.assertRaises(base.ReleaseError): self.phase('apply')
        self.assertEqual(b'old', self.target.read_bytes())

    def test_rollback_refuses_a_newer_edit(self):
        self.phase('prepare')
        self.phase('apply')
        self.target.write_bytes(b'newer')
        with self.assertRaises(base.ReleaseError): self.phase('rollback')
        self.assertEqual(b'newer', self.target.read_bytes())

    def test_extra_target_is_rejected(self):
        self.package['files'].append(self.package['files'][0].copy())
        self.save()
        with self.assertRaises(base.ReleaseError): self.phase('prepare')

    def test_source_baseline_and_commit_cannot_be_swapped(self):
        self.package['base_commit'] = 'a' * 40
        self.save()
        with self.assertRaises(base.ReleaseError): self.phase('prepare')


if __name__ == '__main__':
    unittest.main()
