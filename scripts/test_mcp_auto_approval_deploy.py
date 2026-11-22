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
if __name__=='__main__':unittest.main()
