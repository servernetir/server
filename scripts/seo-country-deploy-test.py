"""Private rollback preflight regression; never touches production."""
import hashlib,importlib.util,json,tempfile,unittest,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('deploy',Path(__file__).with_name('seo-country-deploy-20261011.py'))
deploy=importlib.util.module_from_spec(spec);spec.loader.exec_module(deploy)
sha=lambda b:hashlib.sha256(b).hexdigest()

class RollbackPreflightTest(unittest.TestCase):
    def test_missing_or_corrupt_backup_cannot_partially_restore_or_delete_files(self):
        for backup_bytes in [None,b'corrupt']:
            with self.subTest(backup=backup_bytes),tempfile.TemporaryDirectory() as tmp:
                packet=Path(tmp)/'packet';packet.mkdir()
                deploy.HERE=packet
                deploy.APP=Path(tmp)/'app'
                first='lang/en/country-seo.php';second='resources/views/pages/hosting.blade.php'
                current=b'new version';old=b'old version'
                for name in [first,second]:
                    target=deploy.APP/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(current)
                (packet/'manifest.json').write_text(json.dumps([
                    {'path':first,'before':None,'after':sha(current)},
                    {'path':second,'before':sha(old),'after':sha(current)}]))
                (packet/'APPLIED.json').write_text('{}')
                if backup_bytes is not None:
                    backup=packet/'backup'/second;backup.parent.mkdir(parents=True);backup.write_bytes(backup_bytes)
                original=sys.argv
                try:
                    sys.argv=['deploy.py','--packet-dir',str(packet),'--rollback']
                    with self.assertRaisesRegex(AssertionError,'Backup mismatch'): deploy.main()
                finally: sys.argv=original
                self.assertEqual(current,(deploy.APP/first).read_bytes())
                self.assertEqual(current,(deploy.APP/second).read_bytes())
                self.assertTrue((packet/'APPLIED.json').exists())

if __name__=='__main__':unittest.main()
