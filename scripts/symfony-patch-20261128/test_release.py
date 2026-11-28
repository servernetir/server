import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
spec = importlib.util.spec_from_file_location('symfony_release', HERE/'release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class SymfonyPatchReleaseTest(unittest.TestCase):
    def test_only_four_package_identities_may_change(self):
        current=release.packages((ROOT/'website/composer.lock').read_bytes())
        self.assertEqual({k:v[1] for k,v in release.VERSIONS.items()}, {k:current[k][0] for k in release.VERSIONS})
        old=dict(current, **{k:(v[0],'old-ref') for k,v in release.VERSIONS.items()})
        release.compare(old,current)
        for name in current.keys()-release.VERSIONS.keys():
            with self.assertRaisesRegex(AssertionError, 'four-package scope'):
                release.compare(old,dict(current, **{name:('v0.0.1','unexpected')}))
            break

    def test_unrelated_vendor_bytes_and_modes_are_not_activated(self):
        before={'composer/autoload_static.php':[0o644,'old'], 'laravel/framework/Example.php':[0o644,'same']}
        after=dict(before, **{'composer/autoload_static.php':[0o644,'new'], 'symfony/mailer/Mailer.php':[0o644,'added']})
        self.assertEqual(['composer/autoload_static.php','symfony/mailer/Mailer.php'],release.verify_difference(before,after))
        with self.assertRaisesRegex(AssertionError, 'Unrelated vendor'):
            release.verify_difference(before,dict(after, **{'laravel/framework/Example.php':[0o600,'same']}))

    @unittest.skipUnless(os.name == 'posix', 'Linux exchange rehearsal')
    def test_atomic_exchange_and_reversible_fingerprints(self):
        with tempfile.TemporaryDirectory() as t:
            root=Path(t)
            a,b=root/'live',root/'staged'
            a.mkdir(); b.mkdir()
            (a/'package.php').write_text('old'); (b/'package.php').write_text('new')
            ha=release.signature(release.inventory(a)); hb=release.signature(release.inventory(b))
            release.exchange(a,b)
            self.assertEqual((hb,ha),(release.signature(release.inventory(a)),release.signature(release.inventory(b))))
            release.exchange(a,b)
            self.assertEqual((ha,hb),(release.signature(release.inventory(a)),release.signature(release.inventory(b))))
            (a/'symlink.php').symlink_to(b/'package.php')
            with self.assertRaisesRegex(AssertionError, 'symlink'):
                release.inventory(a)

    def test_failed_activation_restores_old_lock_and_vendor(self):
        class MockEngine:
            phases = []

            def activate(self, stage, manifest, phase):
                self.phases.append(phase)
                if phase == 'after':
                    raise RuntimeError('test activation failed')

            def write(self, path, data, mode):
                path.write_bytes(data)
                path.chmod(mode)

        with tempfile.TemporaryDirectory() as t:
            root=Path(t); app=root/'app'; stage=root/'stage'
            current=app/'vendor'; staged=stage/'deps/vendor'
            current.mkdir(parents=True); staged.mkdir(parents=True)
            (current/'package.php').write_text('old'); (staged/'package.php').write_text('new')
            for path, data in [(app/'composer.json',b'{}'),(app/'composer.lock',b'old'),(stage/'lock.before',b'old'),(stage/'lock.after',b'new')]:
                path.write_bytes(data)
            commit='a'*40
            plan={'sha':commit,'lock_before':release.sha(b'old'),'lock_after':release.sha(b'new'),
                  'vendor_before':release.signature(release.inventory(current)),
                  'vendor_after':release.signature(release.inventory(staged)), 'activation':{}}
            (stage/'prepared.json').write_text(json.dumps(plan))
            eng=MockEngine()
            with patch.multiple(release, APP=app, COMPOSER_JSON=release.sha(b'{}')), patch.object(release,'source',return_value=b'new'), patch.object(release,'engine',return_value=eng):
                with self.assertRaisesRegex(RuntimeError, 'test activation failed'):
                    release.run(stage,commit,'apply')
            self.assertEqual(b'old',(app/'composer.lock').read_bytes())
            self.assertEqual('old',(current/'package.php').read_text())
            self.assertEqual('new',(staged/'package.php').read_text())
            self.assertEqual(['before','after','before'],eng.phases)
            self.assertFalse((stage/'installed.json').exists())


if __name__=='__main__':
    unittest.main()
