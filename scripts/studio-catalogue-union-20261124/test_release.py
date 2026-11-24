"""Exercise failed activation, rollback and safe retry without any live resources."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('catalogue_release', Path(__file__).with_name('release.py'))
release = importlib.util.module_from_spec(spec); spec.loader.exec_module(release)

class ReleaseRecoveryTest(unittest.TestCase):
    def test_retained_assets_allow_retry_but_unknown_content_blocks_installation(self):
        with tempfile.TemporaryDirectory() as directory:
            home = Path(directory); release.HOME = home; release.STUDIO = home/'studio'
            (release.STUDIO/'runtime').mkdir(parents=True); (release.STUDIO/'tmp').mkdir()
            stage = home/'backup'; stage.mkdir()
            config = release.STUDIO/'runtime/config.json'; config.write_bytes(b'old config')
            (stage/'config-before.bin').write_bytes(b'old config'); (stage/'config-after.bin').write_bytes(b'new config')
            old = home/'existing.php'; old.write_bytes(b'old php')
            rows = [{'destination':'asset.js','before':None,'after':release.sha(b'new js'),'mode':0o644},
                {'destination':'existing.php','before':release.sha(b'old php'),'after':release.sha(b'new php'),'mode':0o644}]
            (stage/'new-0').write_bytes(b'new js'); (stage/'new-1').write_bytes(b'new php'); (stage/'old-1').write_bytes(b'old php')
            plan = {'files':rows,'config_before':release.sha(b'old config'),'config_after':release.sha(b'new config'),'config_mode':0o600}
            (stage/'prepared.json').write_text(json.dumps(plan)); release.fetch = lambda *_: b'{"studio_commit":"fake"}'
            def fail(*args): raise RuntimeError('temporary_pool_unavailable')
            calls = []
            def activation(*args):
                calls.append(args[-1])
                if args[-1] == 'after' and len(calls) == 1: fail()
                return []
            release.activate = activation
            with self.assertRaisesRegex(RuntimeError,'temporary_pool_unavailable'):
                release.run('apply','fake',stage)
            self.assertEqual(old.read_bytes(),b'old php'); self.assertEqual(config.read_bytes(),b'old config')
            self.assertEqual((home/'asset.js').read_bytes(),b'new js')
            release.run('apply','fake',stage)
            self.assertEqual(old.read_bytes(),b'new php'); self.assertEqual(config.read_bytes(),b'new config')
            release.run('rollback','fake',stage)
            self.assertEqual(old.read_bytes(),b'old php'); self.assertEqual((home/'asset.js').read_bytes(),b'new js')
            (home/'asset.js').write_bytes(b'unexpected bytes')
            with self.assertRaisesRegex(AssertionError,'live_hash_changed'): release.run('apply','fake',stage)
            self.assertEqual(old.read_bytes(),b'old php')

if __name__ == '__main__': unittest.main()
