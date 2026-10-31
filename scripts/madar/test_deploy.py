import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec=importlib.util.spec_from_file_location('madar_deploy',Path(__file__).with_name('deploy.py'))
deploy=importlib.util.module_from_spec(spec);spec.loader.exec_module(deploy)


class ScopedReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.home=Path(self.temp.name)
        self.root=self.home/'servernet_app';self.root.mkdir()
        (self.root/'public').mkdir();(self.home/'public_html').mkdir()
        # load_core pins a byte-identical deployment engine beside the installed wrapper.
        self.original=deploy.__file__
        deploy.__file__=str(Path(__file__).parents[1]/'deploy-vpstc.py')
        self.core=deploy.load_core();deploy.__file__=self.original
        self.core.lint=lambda *args:None

    def tearDown(self):
        deploy.__file__=self.original;self.temp.cleanup()

    def plan(self, missing=()):
        for rel in deploy.RUNTIME:
            path=self.core.checked_file(self.root,rel)
            if rel not in deploy.NEW and rel not in missing:
                path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(b'base\n')
        def source(commit,relative):
            if commit==deploy.BASE:return None if relative in deploy.NEW else b'base\n'
            return b'new reviewed bytes\n'
        return self.core.build_plan(self.root,'a'*40,'unused',source_reader=source)

    def test_confirmed_missing_read_only_dependencies_are_installed_and_rollback_restores_absence(self):
        release,plan=self.plan(missing=deploy.MISSING_DEPENDENCIES)
        self.core.apply_plan(self.root,release,plan,'unused')
        for rel in deploy.MISSING_DEPENDENCIES:self.assertTrue((self.root/rel).is_file())
        self.core.explicit_rollback(self.root,release,plan)
        for rel in deploy.MISSING_DEPENDENCIES:self.assertFalse((self.root/rel).exists())

    def test_other_missing_existing_file_still_stops_prepare(self):
        with self.assertRaises(self.core.ReleaseError):self.plan(missing=('routes/web.php',))

    def test_native_mariadb_driver_passes_but_unknown_driver_or_existing_schema_stops(self):
        for driver in ('mysql', 'mariadb'):
            deploy.validate_initial_database({'driver':driver,'madar_tables':{'madar_missions':False}})
        for state in ({'driver':'sqlite','madar_tables':{'madar_missions':False}},
                      {'driver':'mariadb','madar_tables':{'madar_missions':True}}):
            with self.assertRaises(RuntimeError):deploy.validate_initial_database(state)

    def test_public_assets_have_two_explicit_destinations_and_no_arbitrary_path(self):
        self.assertEqual(self.home/'public_html/assets/css/madar.css',self.core.checked_file(self.root,'public/assets/css/madar.css'))
        self.assertEqual(self.root/'public/assets/js/madar.js',self.core.checked_file(self.root,'runtime-assets/assets/js/madar.js'))
        with self.assertRaises(self.core.ReleaseError):self.core.checked_file(self.root,'../.env')
        (self.home/'public_html/assets').symlink_to(self.root,target_is_directory=True)
        with self.assertRaises(self.core.ReleaseError):self.core.checked_file(self.root,'public/assets/css/madar.css')

    def test_concurrent_change_rejects_whole_install_before_first_write(self):
        release,plan=self.plan()
        live=self.root/'routes/web.php';live.write_bytes(b'concurrent route change\n')
        writes=[]
        with self.assertRaises(self.core.ReleaseError):
            self.core.apply_plan(self.root,release,plan,'unused',writer=lambda *args:writes.append(args))
        self.assertEqual([],writes)
        self.assertFalse((self.home/'public_html/assets/css/madar.css').exists())
        self.assertEqual(b'concurrent route change\n',live.read_bytes())

    def test_guarded_install_backs_up_existing_files_and_keeps_unrelated_state(self):
        release,plan=self.plan()
        state=self.root/'storage/customer-state';state.parent.mkdir();state.write_bytes(b'untouched')
        result=self.core.apply_plan(self.root,release,plan,'unused')
        self.assertEqual('installed',result['status'])
        self.assertEqual(38,result['files_installed'])
        self.assertEqual(b'base\n',(release/'before/routes/web.php').read_bytes())
        self.assertEqual(b'untouched',state.read_bytes())
        self.assertEqual(b'new reviewed bytes\n',(self.home/'public_html/assets/js/madar.js').read_bytes())
        self.assertEqual(b'new reviewed bytes\n',(self.root/'public/assets/js/madar.js').read_bytes())

    def test_explicit_file_rollback_refuses_a_later_edit(self):
        release,plan=self.plan();self.core.apply_plan(self.root,release,plan,'unused')
        (self.root/'routes/web.php').write_bytes(b'later release\n')
        with self.assertRaises(self.core.ReleaseError):self.core.explicit_rollback(self.root,release,plan)
        self.assertEqual(b'later release\n',(self.root/'routes/web.php').read_bytes())
        self.assertTrue((self.home/'public_html/assets/js/madar.js').is_file())


if __name__=='__main__':unittest.main()
