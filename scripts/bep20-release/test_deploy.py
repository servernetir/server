import contextlib, importlib.util, io, json, subprocess, tempfile, unittest
from pathlib import Path
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('bep20_deploy',Path(__file__).with_name('deploy.py'))
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)

class ReleaseGuardsTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.home=Path(self.tmp.name);self.live=self.home/'servernet_app';self.live.mkdir()
        self.commit='a'*40
        self.before={p:b'<?php // before\n' for p in release.FILES if not p.endswith('/BscWatcher.php')}
        self.after={p:b'<?php // after\n' for p in release.FILES}
        for p,data in self.before.items():
            dest=self.live/p;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
        self.compiled=[self.live/'storage/framework/views'/str(i) for i in range(3)]
        def php(code,*args):
            if 'getCompiledPath' in code:return json.dumps([str(p) for p in self.compiled])
            if '->compile($path)' in code:
                for p in self.compiled:p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'compiled')
            return ''
        self.patches=[patch.object(Path,'home',return_value=self.home),patch.object(release,'download',side_effect=lambda c,p:self.before[p] if c==release.BASE else self.after[p]),patch.object(release,'php',side_effect=php),patch.object(release.subprocess,'run',return_value=subprocess.CompletedProcess([],0))]
        for p in self.patches:p.start();self.addCleanup(p.stop)
    def run_mode(self,mode):
        with patch('sys.argv',['deploy.py',mode,self.commit]),contextlib.redirect_stdout(io.StringIO()):release.main()
    def test_preflight_is_read_only_and_install_verifies_hashes(self):
        self.run_mode('prepare')
        for p,data in self.before.items():self.assertEqual(data,(self.live/p).read_bytes())
        self.assertFalse((self.live/release.FILES[0]).exists())
        self.run_mode('install');self.run_mode('verify')
        for p,data in self.after.items():self.assertEqual(data,(self.live/p).read_bytes())
    def test_later_edit_stops_install_without_overwriting(self):
        self.run_mode('prepare');dest=self.live/release.FILES[1];dest.write_bytes(b'coworker change')
        with self.assertRaisesRegex(RuntimeError,'live drift'):self.run_mode('install')
        self.assertEqual(b'coworker change',dest.read_bytes())
        self.assertFalse((self.live/release.FILES[0]).exists())
    def test_mid_install_failure_restores_files_already_installed(self):
        self.run_mode('prepare');original=release.write
        def fail(path,data,mode=0o644):
            if path==self.live/release.FILES[2] and data==self.after[release.FILES[2]]:raise OSError('simulated disk failure')
            return original(path,data,mode)
        with patch.object(release,'write',side_effect=fail),self.assertRaises(OSError):self.run_mode('install')
        for p,data in self.before.items():self.assertEqual(data,(self.live/p).read_bytes())
        self.assertFalse((self.live/release.FILES[0]).exists())
    def test_rollback_refuses_to_destroy_a_later_edit(self):
        self.run_mode('prepare');self.run_mode('install');dest=self.live/release.FILES[1];dest.write_bytes(b'later change')
        with self.assertRaisesRegex(RuntimeError,'later edit'):self.run_mode('rollback')
        self.assertEqual(b'later change',dest.read_bytes())

if __name__=='__main__':unittest.main()
