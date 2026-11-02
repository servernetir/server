"""Isolated native-PHP source release flows. Never touches the real app or meter.

Only copies under TemporaryDirectory are adapted to PHP8.3/local paths. The
production helper retains its exact cPanel root and native PHP8.4 requirement.
"""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
PHP = Path('/workspace/scratch/669618ddf503/php-runtime/php')
PHP_BINARY = Path('/workspace/scratch/669618ddf503/php-runtime/usr/bin/php8.3')
BEFORE = Path('/workspace/scratch/669618ddf503/arvan-runtime-private/ArvanTrafficMeter.php').read_bytes()
AFTER = (HERE / 'candidate.php').read_bytes()
TARGET = 'app/Services/Finance/ArvanTrafficMeter.php'
BEFORE_HASH = hashlib.sha256(BEFORE).hexdigest()
AFTER_HASH = hashlib.sha256(AFTER).hexdigest()


class ReleaseFlows(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='meter-release-test-')
        self.root = Path(self.temp.name) / 'app'
        self.live = self.root / TARGET
        self.live.parent.mkdir(parents=True)
        self.live.write_bytes(BEFORE)
        self.live.chmod(0o644)
        self.meter_lock = self.root / 'storage/app/private/arvan-traffic-meter/run.lock'
        self.meter_lock.parent.mkdir(parents=True, mode=0o700)
        self.meter_lock.touch(mode=0o600)
        (self.root / 'storage/framework').mkdir(parents=True)
        self.release_lock = self.root / 'storage/framework/arvan-meter-source-release.lock'
        self.release = self.root / 'storage/app/private/arvan-meter-release-test123'
        self.release.mkdir(mode=0o700)
        self.helper = self.release / 'deploy.php'
        source = (HERE / 'deploy.php').read_text()
        source = source.replace("const METER_ROOT = '/home/servernetcloud/servernet_app';", f"const METER_ROOT = '{self.root}';")
        source = source.replace("const METER_PHP = '/opt/cpanel/ea-php84/root/usr/bin/php';", f"const METER_PHP = '{PHP_BINARY}';")
        source = source.replace("version_compare(PHP_VERSION, '8.4.0', '<')", "version_compare(PHP_VERSION, '8.3.0', '<')")
        self.helper.write_text(source)
        self.helper.chmod(0o600)
        self.candidate = self.release / 'candidate.php'
        self.candidate.write_bytes(AFTER)
        self.candidate.chmod(0o600)

    def tearDown(self):
        self.temp.cleanup()

    def run_helper(self, *args, ok=True):
        r = subprocess.run([str(PHP), str(self.helper), *args], cwd=self.root,
                           capture_output=True, text=True, timeout=10)
        self.assertEqual(r.returncode, 0 if ok else 1, (r.stdout, r.stderr))
        self.assertEqual(r.stderr, '', r.stderr)
        return json.loads(r.stdout)

    def plan(self):
        p = self.run_helper('--plan')
        self.assertEqual(p['status'], 'prepared_not_installed')
        self.assertEqual(self.live.read_bytes(), BEFORE)
        self.assertEqual((self.release / 'before.php').read_bytes(), BEFORE)
        self.assertEqual((self.release / 'before.php').stat().st_mode & 0o777, 0o600)
        return p['plan_sha256']

    def inject_after_rename(self, code):
        text = self.helper.read_text()
        marker = '            // Post-replacement verification is deliberately inside rollback scope.\n'
        self.assertEqual(text.count(marker), 1)
        self.helper.write_text(text.replace(marker, marker + code + '\n'))

    def inject_receipt(self, marker, code):
        text = self.helper.read_text()
        self.assertEqual(text.count(marker), 1)
        self.helper.write_text(text.replace(marker, code + '\n' + marker))

    def assert_failed_receipt(self, r):
        self.assertEqual(r['status'], 'failed_source_rollback_complete')
        self.assertTrue(r['attempted_write'])
        self.assertTrue(r['rolled_back'])
        self.assertEqual(self.live.read_bytes(), BEFORE)
        saved = json.loads((self.release / 'receipt.json').read_text())
        self.assertEqual(saved['status'], 'failed_source_rollback_complete')

    def test_success_retry_and_post_success_drift(self):
        self.assertEqual(self.run_helper('--inspect')['comparison'], 'expected_before')
        plan = self.plan()
        self.assertEqual(self.run_helper('--plan')['plan_sha256'], plan)
        self.run_helper('--apply', '0' * 64, ok=False)
        self.assertEqual(self.live.read_bytes(), BEFORE)
        receipt = self.run_helper('--apply', plan)
        self.assertEqual(receipt['status'], 'source_installed_activation_pending')
        self.assertTrue(receipt['attempted_write'])
        self.assertFalse(receipt['rolled_back'])
        self.assertEqual(self.live.read_bytes(), AFTER)
        self.assertEqual(self.live.stat().st_mode & 0o777, 0o644)
        self.assertEqual(self.run_helper('--apply', plan)['status'], 'receipt_already_exists')
        self.assertEqual(self.run_helper('--inspect')['comparison'], 'expected_after')
        drift = AFTER + b'\n// later independent change\n'
        self.live.write_bytes(drift)
        self.assertEqual(self.run_helper('--inspect')['comparison'], 'drift_requires_review')
        self.assertEqual(self.run_helper('--apply', plan, ok=False)['error_code'], 'source_precondition_changed')
        self.assertEqual(self.live.read_bytes(), drift)

    def test_existing_natural_meter_lock_is_respected(self):
        with self.meter_lock.open('r+') as h:
            fcntl.flock(h, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertEqual(self.run_helper('--inspect', ok=False)['error_code'], 'meter_busy')
            self.assertEqual(self.run_helper('--plan', ok=False)['error_code'], 'meter_busy')
        self.assertFalse((self.release / 'plan.json').exists())
        self.assertEqual(self.live.read_bytes(), BEFORE)

    def test_fixed_release_lock_is_respected(self):
        self.release_lock.touch(mode=0o600)
        with self.release_lock.open('r+') as h:
            fcntl.flock(h, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertEqual(self.run_helper('--inspect', ok=False)['error_code'], 'release_busy')
            self.assertEqual(self.run_helper('--plan', ok=False)['error_code'], 'release_busy')
        self.assertFalse((self.release / 'plan.json').exists())
        self.assertEqual(self.live.read_bytes(), BEFORE)

    def test_absent_lock_is_never_created(self):
        self.meter_lock.unlink()
        self.assertEqual(self.run_helper('--plan', ok=False)['error_code'], 'existing_meter_lock_required')
        self.assertFalse(self.meter_lock.exists())

    def test_planned_source_drift_is_preserved(self):
        plan = self.plan()
        drift = BEFORE + b'\n// independent change\n'
        self.live.write_bytes(drift)
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['status'], 'failed_no_source_change')
        self.assertFalse(r['attempted_write'])
        self.assertEqual(self.live.read_bytes(), drift)

    def test_candidate_tampering_is_refused(self):
        plan = self.plan()
        self.candidate.write_bytes(AFTER + b'\n')
        self.assertEqual(self.run_helper('--apply', plan, ok=False)['error_code'], 'candidate_changed')
        self.assertEqual(self.live.read_bytes(), BEFORE)

    def test_backup_tampering_is_refused(self):
        plan = self.plan()
        (self.release / 'before.php').write_bytes(BEFORE + b'\n')
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['status'], 'failed_no_source_change')
        self.assertEqual(r['error_code'], 'backup_changed')
        self.assertEqual(self.live.read_bytes(), BEFORE)

    def test_own_replacement_rolls_back_after_verification_failure(self):
        plan = self.plan()
        self.inject_after_rename("            deny('test_post_rename_failure');")
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['status'], 'failed_source_rollback_complete')
        self.assertTrue(r['attempted_write'])
        self.assertTrue(r['rolled_back'])
        self.assertEqual(self.live.read_bytes(), BEFORE)
        self.assertEqual(self.run_helper('--apply', plan, ok=False)['error_code'], 'historical_failure_requires_operator')

    def test_later_write_is_not_rolled_back(self):
        plan = self.plan()
        self.inject_after_rename("            file_put_contents(METER_ROOT.'/'.METER_TARGET, '<?php // concurrent independent update'); deny('test_post_rename_failure');")
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['status'], 'failed_rollback_requires_operator')
        self.assertEqual(r['rollback_error'], 'concurrent_update_preserved')
        self.assertEqual(self.live.read_text(), '<?php // concurrent independent update')

    def test_target_symlink_is_refused(self):
        original = self.live.with_name('original.php')
        self.live.rename(original)
        self.live.symlink_to(original)
        self.assertEqual(self.run_helper('--plan', ok=False)['error_code'], 'file_missing_or_symlink')
        self.assertEqual(original.read_bytes(), BEFORE)

    def test_mode_drift_is_refused(self):
        plan = self.plan()
        self.live.chmod(0o600)
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['status'], 'failed_no_source_change')
        self.assertEqual(self.live.stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.live.read_bytes(), BEFORE)

    def test_meter_lock_inode_replacement_before_rename_is_refused(self):
        plan = self.plan()
        source = self.helper.read_text()
        marker = "            lintOnly($dir.'/candidate.php'); checkLock($lock, $owner);\n"
        self.assertEqual(source.count(marker), 1)
        replacement = "            unlink(METER_ROOT.'/storage/app/private/arvan-traffic-meter/run.lock'); touch(METER_ROOT.'/storage/app/private/arvan-traffic-meter/run.lock');\n"
        self.helper.write_text(source.replace(marker, marker + replacement))
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['error_code'], 'meter_lock_identity_changed')
        self.assertFalse(r['attempted_write'])
        self.assertEqual(self.live.read_bytes(), BEFORE)

    def test_release_lock_inode_replacement_before_rename_is_refused(self):
        plan = self.plan()
        source = self.helper.read_text()
        marker = "            lintOnly($dir.'/candidate.php'); checkLock($lock, $owner);\n"
        self.assertEqual(source.count(marker), 1)
        replacement = "            unlink(METER_ROOT.'/storage/framework/arvan-meter-source-release.lock'); touch(METER_ROOT.'/storage/framework/arvan-meter-source-release.lock');\n"
        self.helper.write_text(source.replace(marker, marker + replacement))
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['error_code'], 'release_lock_identity_changed')
        self.assertFalse(r['attempted_write'])
        self.assertEqual(self.live.read_bytes(), BEFORE)

    def test_receipt_write_failure_after_source_rename_rolls_back(self):
        plan = self.plan()
        self.inject_receipt('    writeFresh($tmp, $bytes);', "    if (($receipt['status'] ?? '') === 'source_installed_activation_pending') { deny('test_receipt_write_failure'); }")
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['error_code'], 'test_receipt_write_failure')
        self.assert_failed_receipt(r)

    def test_receipt_publication_failure_after_source_rename_rolls_back(self):
        plan = self.plan()
        self.inject_receipt('    // Atomic no-overwrite publication: link fails if any receipt already exists.', "    if (($receipt['status'] ?? '') === 'source_installed_activation_pending') { deny('test_receipt_publish_failure'); }")
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['error_code'], 'test_receipt_publish_failure')
        self.assert_failed_receipt(r)

    def test_published_success_receipt_is_withdrawn_before_rollback(self):
        plan = self.plan()
        self.inject_receipt('    // A verification failure is handled inside the source rollback scope.', "    if (($receipt['status'] ?? '') === 'source_installed_activation_pending') { deny('test_receipt_verify_failure'); }")
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['error_code'], 'test_receipt_verify_failure')
        self.assert_failed_receipt(r)

    def test_unexpected_published_receipt_change_is_preserved(self):
        plan = self.plan()
        self.inject_receipt('    // A verification failure is handled inside the source rollback scope.', "    if (($receipt['status'] ?? '') === 'source_installed_activation_pending') { file_put_contents($path, '{\"independent\":true}'); deny('test_receipt_verify_failure'); }")
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['status'], 'failed_receipt_reconciliation_requires_operator')
        self.assertFalse(r['rolled_back'])
        self.assertEqual(self.live.read_bytes(), AFTER)
        self.assertEqual(json.loads((self.release / 'receipt.json').read_text()), {'independent': True})

    def test_receipt_publication_never_overwrites_an_unexpected_receipt(self):
        plan = self.plan()
        self.inject_receipt('    // Atomic no-overwrite publication: link fails if any receipt already exists.', "    if (($receipt['status'] ?? '') === 'source_installed_activation_pending') { file_put_contents($path, '{\"independent\":true}'); chmod($path, 0600); }")
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['status'], 'failed_receipt_reconciliation_requires_operator')
        self.assertEqual(r['error_code'], 'receipt_publication_failed')
        self.assertEqual(self.live.read_bytes(), AFTER)
        self.assertEqual(json.loads((self.release / 'receipt.json').read_text()), {'independent': True})

    def test_post_publication_source_drift_withdraws_success_receipt(self):
        plan = self.plan()
        text = self.helper.read_text()
        marker = '            // Success requires both the published receipt and the exact live source.\n'
        self.assertEqual(text.count(marker), 1)
        self.helper.write_text(text.replace(marker, "            file_put_contents(METER_ROOT.'/'.METER_TARGET, '<?php // concurrent independent update');\n" + marker))
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['status'], 'failed_rollback_requires_operator')
        self.assertEqual(self.live.read_text(), '<?php // concurrent independent update')
        saved = json.loads((self.release / 'receipt.json').read_text())
        self.assertEqual(saved['status'], 'failed_rollback_requires_operator')

    def test_receipt_io_failure_can_leave_only_verified_before_source(self):
        plan = self.plan()
        original_helper = self.helper.read_text()
        self.inject_receipt('    writeFresh($tmp, $bytes);', "    deny('test_all_receipt_io_unavailable');")
        r = self.run_helper('--apply', plan, ok=False)
        self.assertEqual(r['status'], 'failed_source_rollback_complete')
        self.assertEqual(r['receipt_recording_error'], 'test_all_receipt_io_unavailable')
        self.assertEqual(self.live.read_bytes(), BEFORE)
        self.assertFalse((self.release / 'receipt.json').exists())
        # After fixing the IO fault, the same reviewed plan must pass every guard anew.
        self.helper.write_text(original_helper)
        self.assertEqual(self.run_helper('--inspect')['comparison'], 'expected_before')
        self.assertEqual(self.run_helper('--apply', plan)['status'], 'source_installed_activation_pending')
        self.assertEqual(self.live.read_bytes(), AFTER)


if __name__ == '__main__':
    assert BEFORE_HASH == '464b2db7f0bda854d6473b06e46da630cdc325c82bb22894088af8bcfaba4f3f'
    assert AFTER_HASH == '56c3972ba0896e6bc14c4d443246c1eadaef6bccb63245e0cd3b274e292d2ca8'
    unittest.main(verbosity=2)
