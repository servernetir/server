"""Local, temporary fixtures; never contacts or boots the deployed application."""
import contextlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest

HERE=Path(__file__).resolve().parent
REPO=next(parent for parent in HERE.parents if (parent/'.git').exists())
spec=importlib.util.spec_from_file_location('release_fixture',HERE/'deploy.py')
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)
ORIGINAL_ATOMIC=release.atomic
REAL_PAYLOAD=json.loads((HERE/'payload.json').read_text(encoding='utf-8'))

class ReleaseFixtures(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='servernet-release-fixture-')
        self.home=Path(self.tmp.name).resolve();self.root=self.home/'servernet_app'
        self.stage=self.home/'.seo-search-growth-20261102';self.stage.mkdir(mode=0o700)
        payload=json.loads(json.dumps(REAL_PAYLOAD));self.before={}
        for row in payload['files']:
            raw=subprocess.check_output(['git','show','6c0fcb7c:website/'+row['target']],cwd=REPO)
            if 'SiteController' in row['target']:
                raw=raw.replace(b'<?php',b'<?php\n// Independent live overlay preserved by scoped hunks.',1)
            path=self.root/row['target'];path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw)
            self.before[row['target']]=raw;row['before_sha256']=release.digest(raw)
        (self.stage/'compile-blade.php').write_bytes((HERE/'compile-blade.php').read_bytes())
        data=(json.dumps(payload,indent=2,ensure_ascii=False)+'\n').encode('utf-8')
        (self.stage/'payload.json').write_bytes(data);release.PAYLOAD_SHA=release.digest(data)
        self.uid=next(self.root.rglob('CountryVpsController.php')).stat().st_uid
        self.mode=stat.S_IMODE(next(self.root.rglob('CountryVpsController.php')).stat().st_mode)
        self.payload=payload;self.lint_calls=0
        release.atomic=ORIGINAL_ATOMIC
    def tearDown(self):
        release.atomic=ORIGINAL_ATOMIC;self.tmp.cleanup()
    def lint(self,root,stage,rows):
        self.lint_calls+=1
        for i,row in enumerate(rows):
            self.assertEqual(release.patch(self.before[row['target']],self.payload['files'][i]['hunks']),
                             (stage/('candidate-'+str(i)+'.php')).read_bytes())
    def run_phase(self,phase):
        return release.run(self.home,self.stage,phase,uid=self.uid,mode=self.mode,
                           lint=self.lint,locks=lambda *_:contextlib.nullcontext())
    def test_preview_apply_single_use_rollback_preserves_overlay(self):
        preview=self.run_phase('preview');self.assertEqual(1,self.lint_calls)
        self.assertFalse((self.stage/'intent.json').exists())
        self.assertEqual(self.before,{t:(self.root/t).read_bytes() for t in release.TARGETS})
        self.assertEqual('installed_pending_http_acceptance',self.run_phase('apply')['status'])
        for row in preview['files']:
            self.assertEqual(row['after_sha256'],release.digest((self.root/row['target']).read_bytes()))
        self.assertIn(b'Independent live overlay preserved', (self.root/release.TARGETS[1]).read_bytes())
        with self.assertRaisesRegex(release.Stop,'apply_already_attempted'):self.run_phase('apply')
        self.run_phase('rollback')
        self.assertEqual(self.before,{t:(self.root/t).read_bytes() for t in release.TARGETS})
        with self.assertRaisesRegex(release.Stop,'rollback_not_available'):self.run_phase('rollback')
    def test_source_changed_after_preview_stops_before_intent(self):
        self.run_phase('preview');path=self.root/release.TARGETS[1];path.write_bytes(path.read_bytes()+b'\n// later work')
        with self.assertRaisesRegex(release.Stop,'state_changed_after_preview'):self.run_phase('apply')
        self.assertFalse((self.stage/'intent.json').exists());self.assertTrue(path.read_bytes().endswith(b'// later work'))
    def test_candidate_tamper_stops_before_intent(self):
        self.run_phase('preview');(self.stage/'candidate-0.php').write_bytes(b'tampered')
        with self.assertRaisesRegex(release.Stop,'candidate_drift'):self.run_phase('apply')
        self.assertFalse((self.stage/'intent.json').exists())
    def test_backup_tamper_stops_before_intent(self):
        self.run_phase('preview');(self.stage/'backups'/'0').write_bytes(b'tampered')
        with self.assertRaisesRegex(release.Stop,'backup_drift'):self.run_phase('apply')
        self.assertFalse((self.stage/'intent.json').exists())
    def test_partial_install_failure_rolls_back_own_change(self):
        self.run_phase('preview');fired=False
        def fail_after_first(path,data,mode):
            nonlocal fired
            ORIGINAL_ATOMIC(path,data,mode)
            if path==self.root/release.TARGETS[0] and not fired:
                fired=True;raise RuntimeError('fixture install failure')
        release.atomic=fail_after_first
        with self.assertRaisesRegex(RuntimeError,'fixture install failure'):self.run_phase('apply')
        self.assertEqual(self.before,{t:(self.root/t).read_bytes() for t in release.TARGETS})
        self.assertEqual('failed_install_guarded_rollback_complete',json.loads((self.stage/'failure.json').read_bytes())['status'])
    def test_mid_install_later_work_is_never_overwritten(self):
        self.run_phase('preview');later=b'<?php\n// independent concurrent work\n';fired=False
        def change_next(path,data,mode):
            nonlocal fired
            ORIGINAL_ATOMIC(path,data,mode)
            if path==self.root/release.TARGETS[0] and not fired:
                fired=True;(self.root/release.TARGETS[1]).write_bytes(later)
        release.atomic=change_next
        with self.assertRaisesRegex(release.Stop,'newer_source_prevents_rollback'):self.run_phase('apply')
        self.assertEqual(later,(self.root/release.TARGETS[1]).read_bytes())
        self.assertEqual(self.before[release.TARGETS[2]],(self.root/release.TARGETS[2]).read_bytes())
        self.assertEqual('stopped_rollback_refused_preserve_later_changes',json.loads((self.stage/'failure.json').read_bytes())['status'])
    def test_mid_rollback_later_work_is_never_overwritten(self):
        self.run_phase('preview');self.run_phase('apply');later=b'<?php\n// later during restore\n';fired=False
        def change_next(path,data,mode):
            nonlocal fired
            ORIGINAL_ATOMIC(path,data,mode)
            if path==self.root/release.TARGETS[0] and not fired:
                fired=True;(self.root/release.TARGETS[1]).write_bytes(later)
        release.atomic=change_next
        with self.assertRaisesRegex(release.Stop,'newer_source_prevents_rollback'):self.run_phase('rollback')
        self.assertEqual(later,(self.root/release.TARGETS[1]).read_bytes())
        self.assertFalse((self.stage/'rollback.json').exists())

if __name__=='__main__':unittest.main(verbosity=2)
