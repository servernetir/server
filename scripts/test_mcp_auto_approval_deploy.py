#!/usr/bin/env python3
"""Rollback failure must leave every installed destination unchanged."""
import hashlib, importlib.util, json, os, sys, tempfile, unittest
from pathlib import Path
class ReleaseTest(unittest.TestCase):
    def test_rollback_preflights_every_target_and_restores_registration_first(self):
        spec=importlib.util.spec_from_file_location('mcp_deploy',Path(__file__).parent/'mcp-auto-approval-20261122/release.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)/'app';backups=Path(temp)/'backups';(root/'storage/framework').mkdir(parents=True)
            module.ROOT=root;module.BACKUPS=backups;module.fetch=lambda *_:b'[]'
            commit='a'*40;stage=backups/('mcp-auto-approval-20261122-'+commit[:12]);stage.mkdir(parents=True)
            entries=[]
            for name,before,after in [('app/Mcp/AdminInfrastructureServer.php',b'old registration',b'new registration'),('app/Mcp/Tools/Fixture.php',None,b'new tool')]:
                path=root/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(after)
                if before is not None:
                    backup=stage/'backup'/name;backup.parent.mkdir(parents=True);backup.write_bytes(before)
                entries.append({'path':name,'before':hashlib.sha256(before).hexdigest() if before else None,
                    'after':hashlib.sha256(after).hexdigest(),'mode':0o644})
            (stage/'prepared.json').write_text(json.dumps(entries));(stage/'receipt.json').write_text('{}')
            sys.argv=['deploy','rollback',commit]
            server=root/entries[0]['path'];tool=root/entries[1]['path']
            server.write_bytes(b'newer production edit')
            with self.assertRaises(AssertionError):module.run_release('rollback',commit,stage)
            self.assertEqual(b'new tool',tool.read_bytes());self.assertEqual(b'newer production edit',server.read_bytes())
            server.write_bytes(b'new registration');backup=stage/'backup'/entries[0]['path'];backup.write_bytes(b'corrupt')
            with self.assertRaises(AssertionError):module.run_release('rollback',commit,stage)
            self.assertEqual(b'new tool',tool.read_bytes());self.assertEqual(b'new registration',server.read_bytes())
            backup.write_bytes(b'old registration')
            original_unlink=Path.unlink
            def checked_unlink(path,*args,**kwargs):
                if path==tool:self.assertEqual(b'old registration',server.read_bytes())
                return original_unlink(path,*args,**kwargs)
            from unittest.mock import patch
            with patch.object(Path,'unlink',checked_unlink):module.run_release('rollback',commit,stage)
            self.assertFalse(tool.exists());self.assertEqual(b'old registration',server.read_bytes())
    def test_activation_companion_is_bundled_at_the_pinned_hash(self):
        directory=Path(__file__).parent/'mcp-auto-approval-20261122'
        spec=importlib.util.spec_from_file_location('mcp_activation',directory/'activate.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        self.assertEqual(module.FCGI_SHA,hashlib.sha256((directory/'fcgi.py').read_bytes()).hexdigest())
        self.assertTrue((directory/'activate.php').is_file())

    def test_new_policy_dependency_installs_before_its_callers(self):
        from unittest.mock import patch
        spec=importlib.util.spec_from_file_location('mcp_order',Path(__file__).parent/'mcp-auto-approval-20261122/release.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)/'app';stage=Path(temp)/'stage';root.mkdir();stage.mkdir();module.ROOT=root;module.fetch=lambda *_:b'[]'
            names=['app/Http/Controllers/Mcp/AdminController.php','app/Services/Mcp/CommandApprovals.php','app/Services/Mcp/AdminAccess.php']
            records=[]
            for name in names:
                target=root/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(b'old')
                source=stage/'files/website'/name;source.parent.mkdir(parents=True,exist_ok=True);source.write_bytes(b'new')
                records.append({'path':name,'before':hashlib.sha256(b'old').hexdigest(),'after':hashlib.sha256(b'new').hexdigest(),'mode':0o644})
            (stage/'prepared.json').write_text(json.dumps(records))
            original=module.write;observed=[]
            def checked_write(path,data,mode):
                observed.append(str(path.relative_to(root)));return original(path,data,mode)
            with patch.object(module,'write',checked_write):module.run_release('apply','d'*40,stage)
            self.assertEqual(list(reversed(names)),observed)
            module.run_release('verify','d'*40,stage)

    def test_activation_rejects_foreign_sockets_symlinks_and_regular_files(self):
        import stat
        from types import SimpleNamespace
        from unittest.mock import patch
        spec=importlib.util.spec_from_file_location('mcp_socket_checks',Path(__file__).parent/'mcp-auto-approval-20261122/activate.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        target=module.FPM_DIRECTORY/('a'*40+'.sock')
        observed=SimpleNamespace(st_mode=stat.S_IFSOCK | 0o600,st_uid=os.getuid())
        with patch.object(module.Path,'lstat',return_value=observed),patch.object(module.Path,'is_symlink',return_value=False):
            self.assertTrue(module.owned_socket(target,os.getuid()))
            self.assertFalse(module.owned_socket(target,os.getuid()+1))
            self.assertFalse(module.owned_socket(Path('/tmp')/target.name,os.getuid()))
            self.assertFalse(module.owned_socket(target.with_name('unexpected.sock'),os.getuid()))
            observed.st_mode=stat.S_IFREG | 0o600
            self.assertFalse(module.owned_socket(target,os.getuid()))
        observed.st_mode=stat.S_IFSOCK | 0o600
        with patch.object(module.Path,'lstat',return_value=observed),patch.object(module.Path,'is_symlink',return_value=True):
            self.assertFalse(module.owned_socket(target,os.getuid()))

    def test_activation_pool_inventory_keeps_only_observed_owned_sockets(self):
        from unittest.mock import patch
        spec=importlib.util.spec_from_file_location('mcp_socket_inventory',Path(__file__).parent/'mcp-auto-approval-20261122/activate.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        accepted=module.FPM_DIRECTORY/('a'*40+'.sock')
        other=module.FPM_DIRECTORY/('b'*40+'.sock')
        rows='header\n0 0 0 '+str(accepted)+'\n0 0 0 '+str(accepted)+'\n0 0 0 '+str(other)
        with patch.object(module.Path,'read_text',return_value=rows),patch.object(module,'owned_socket',side_effect=lambda p,u:Path(p)==accepted):
            self.assertEqual([accepted],module.observe_pools())
        with patch.object(module.Path,'read_text',return_value='header'),patch.object(module,'owned_socket',return_value=False):
            with self.assertRaisesRegex(RuntimeError,'no_observed'):module.observe_pools()

    def test_primary_matches_docroot_is_http_denied_and_is_cleaned_up(self):
        from unittest.mock import patch
        spec=importlib.util.spec_from_file_location('mcp_primary',Path(__file__).parent/'mcp-auto-approval-20261122/activate.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);module.DOCROOT=root/'public';module.DOCROOT.mkdir()
            tools=root/'tools';tools.mkdir();(tools/'activate.php').write_bytes(b'<?php echo "test";')
            with patch.object(module,'require_http_denied') as probe:
                with module.temporary_primary(tools) as (primary,name):
                    self.assertEqual(module.DOCROOT/str(name).lstrip('/'),primary)
                    self.assertEqual(b'Require all denied\n',(primary.parent/'.htaccess').read_bytes())
                    self.assertEqual(0o600,primary.stat().st_mode & 0o777)
                    probe.assert_called_once_with(name)
                self.assertFalse(primary.parent.exists())
            with patch.object(module,'require_http_denied',side_effect=RuntimeError('denial failed')):
                with self.assertRaisesRegex(RuntimeError,'denial failed'):
                    with module.temporary_primary(tools):self.fail('Unsafe primary was yielded')
            self.assertEqual([],list(module.DOCROOT.iterdir()))

    def test_primary_http_probe_requires_denial_for_both_hosts(self):
        from unittest.mock import patch, MagicMock
        spec=importlib.util.spec_from_file_location('mcp_probe',Path(__file__).parent/'mcp-auto-approval-20261122/activate.py')
        module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
        response=MagicMock();response.__enter__.return_value.status=403
        with patch.object(module.urllib.request,'urlopen',return_value=response) as request:
            module.require_http_denied('/temporary/activate.php')
            self.assertEqual(2,request.call_count)
        response.__enter__.return_value.status=200
        with patch.object(module.urllib.request,'urlopen',return_value=response):
            with self.assertRaisesRegex(RuntimeError,'http_not_denied'):module.require_http_denied('/temporary/activate.php')

if __name__=='__main__':unittest.main()
