import hashlib, importlib.util, tempfile, unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('ollama_activation', Path(__file__).with_name('activate.py'))
activation = importlib.util.module_from_spec(spec); spec.loader.exec_module(activation)

class ActivationTest(unittest.TestCase):
    def test_protocol_helper_is_bundled_and_matches_the_pinned_hash(self):
        self.assertEqual(activation.FCGI_SHA, hashlib.sha256(Path(__file__).with_name('fcgi.py').read_bytes()).hexdigest())

    def test_only_both_previously_verified_owned_pools_are_eligible(self):
        paths = [activation.FPM_DIRECTORY/name for name in activation.APP_POOL_NAMES]
        other = activation.FPM_DIRECTORY/('a'*40+'.sock')
        data = '\n'.join('0 0 0 '+str(p) for p in paths+[other])
        with patch.object(activation.Path, 'read_text', return_value=data), patch.object(activation, 'owned_socket', return_value=True):
            self.assertEqual(sorted(paths), activation.observe_pools())
        with patch.object(activation.Path, 'read_text', return_value='0 0 0 '+str(paths[0])), patch.object(activation, 'owned_socket', return_value=True):
            with self.assertRaises(RuntimeError): activation.observe_pools()

    def test_temporary_primary_is_denied_has_matching_script_name_and_is_removed(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp); public=root/'public';public.mkdir();tools=root/'tools';tools.mkdir()
            (tools/'activate.php').write_bytes(b'<?php exit;')
            with patch.object(activation,'DOCROOT',public),patch.object(activation,'require_http_denied') as probe:
                with activation.temporary_primary(tools) as (primary,name):
                    self.assertEqual(public/name.lstrip('/'),primary)
                    self.assertEqual(b'Require all denied\n',(primary.parent/'.htaccess').read_bytes())
                    self.assertEqual(0o600,primary.stat().st_mode & 0o777)
                    probe.assert_called_once_with(name)
                self.assertFalse(primary.parent.exists())

if __name__=='__main__':unittest.main()
