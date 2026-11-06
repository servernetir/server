import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('openprovider_followup', Path(__file__).with_name('openprovider-followup.py'))
followup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(followup)
base = followup.base


class OpenproviderLayerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.source = Path(self.temp.name) / 'source'
        self.home.mkdir(); self.source.mkdir()
        self.main = {'release': followup.MAIN, 'files': [
            {'source': followup.SOURCE, 'target': followup.TARGET,
             'before': base.digest(b'old adapter'), 'after': base.digest(b'main adapter')},
            {'source': 'website/routes/console.php', 'target': 'routes/console.php',
             'before': base.digest(b'old schedule'), 'after': base.digest(b'main schedule')},
        ]}
        for row, old, new in zip(self.main['files'], [b'old adapter', b'old schedule'], [b'main adapter', b'main schedule']):
            target = base.target(self.home, row)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(old); target.chmod(0o640)
            source = self.source / row['source']
            source.parent.mkdir(parents=True, exist_ok=True); source.write_bytes(new)
        package = self.source / followup.PACKAGE
        package.mkdir(parents=True)
        (package / 'release-manifest.json').write_text(json.dumps(self.main))
        self.main_phase('prepare'); self.main_phase('apply'); self.main_phase('verify')
        (self.source / followup.SOURCE).write_bytes(b'followup adapter')
        self.layer = {'release': followup.RELEASE, 'files': [{
            'source': followup.SOURCE, 'target': followup.TARGET,
            'before': base.digest(b'main adapter'), 'after': base.digest(b'followup adapter'),
        }]}
        self.save_layer()

    def save_layer(self):
        (self.source / followup.PACKAGE / 'openprovider-manifest.json').write_text(json.dumps(self.layer))

    def main_phase(self, phase):
        base.RELEASE = followup.MAIN
        try:
            return base.run(self.home, self.source, self.main, phase, lambda p: None, lambda: 'unchanged')
        finally:
            base.RELEASE = followup.RELEASE

    def phase(self, phase):
        return followup.run(self.home, self.source, phase, lambda p: None, lambda: 'unchanged')

    def install(self):
        self.phase('prepare'); self.phase('apply'); self.phase('verify')

    def test_composed_acceptance_uses_only_pinned_adapter_override(self):
        self.install()
        result = self.phase('accept')
        self.assertEqual(2, result['composed_files_verified'])
        self.assertEqual('verified', result['phase'])
        self.assertEqual(b'followup adapter', base.target(self.home, self.main['files'][0]).read_bytes())
        self.assertEqual(b'main schedule', base.target(self.home, self.main['files'][1]).read_bytes())

    def test_mismatched_layer_before_hash_cannot_prepare(self):
        self.layer['files'][0]['before'] = base.digest(b'wrong ancestor')
        self.save_layer()
        with self.assertRaisesRegex(base.ReleaseError, 'layer_chain_invalid'):
            self.phase('prepare')
        self.assertEqual(b'main adapter', base.target(self.home, self.main['files'][0]).read_bytes())

    def test_noop_or_extra_target_cannot_expand_followup_scope(self):
        self.layer['files'][0]['after'] = self.layer['files'][0]['before']; self.save_layer()
        with self.assertRaisesRegex(base.ReleaseError, 'layer_chain_invalid'):
            self.phase('prepare')
        self.layer['files'][0]['after'] = base.digest(b'followup adapter')
        other = copy.deepcopy(self.main['files'][1]); self.layer['files'].append(other); self.save_layer()
        with self.assertRaisesRegex(base.ReleaseError, 'scope_invalid'):
            self.phase('prepare')

    def test_unrelated_newer_edit_prevents_composed_acceptance(self):
        self.install()
        other = base.target(self.home, self.main['files'][1]); other.write_text('another release')
        with self.assertRaisesRegex(base.ReleaseError, 'hash_drift'):
            self.phase('accept')
        self.assertEqual('another release', other.read_text())
        self.assertEqual(b'followup adapter', base.target(self.home, self.main['files'][0]).read_bytes())

    def test_unrelated_mode_drift_prevents_composed_acceptance(self):
        self.install()
        base.target(self.home, self.main['files'][1]).chmod(0o600)
        with self.assertRaisesRegex(base.ReleaseError, 'composed_target_mode_drift'):
            self.phase('accept')

    def test_unverified_original_receipt_prevents_composed_acceptance(self):
        self.install()
        path = self.home / 'deploy-backups' / followup.MAIN / 'receipt.json'
        receipt = json.loads(path.read_text()); receipt['phase'] = 'installed'; path.write_text(json.dumps(receipt))
        with self.assertRaisesRegex(base.ReleaseError, 'main_verified_receipt_required'):
            self.phase('accept')

    def test_changed_original_manifest_receipt_prevents_composed_acceptance(self):
        self.install()
        path = self.home / 'deploy-backups' / followup.MAIN / 'receipt.json'
        receipt = json.loads(path.read_text()); receipt['manifest']['base_commit'] = 'different'; path.write_text(json.dumps(receipt))
        with self.assertRaisesRegex(base.ReleaseError, 'main_verified_receipt_required'):
            self.phase('accept')

    def test_main_rollback_stops_until_followup_layer_is_rolled_back(self):
        self.install()
        with self.assertRaisesRegex(base.ReleaseError, 'rollback_drift'):
            self.main_phase('rollback')
        self.assertEqual(b'main schedule', base.target(self.home, self.main['files'][1]).read_bytes())
        self.assertEqual('rolled_back', self.phase('rollback')['phase'])
        self.assertEqual(b'main adapter', base.target(self.home, self.main['files'][0]).read_bytes())
        self.assertEqual('rolled_back', self.main_phase('rollback')['phase'])
        self.assertEqual(b'old adapter', base.target(self.home, self.main['files'][0]).read_bytes())


if __name__ == '__main__':
    unittest.main()
