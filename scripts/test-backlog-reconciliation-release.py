#!/usr/bin/env python3
"""Offline release-merge safety checks; no application, network or credentials."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('backlog_release', Path(__file__).with_name('deploy-backlog-reconciliation-20261026.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseMergeTest(unittest.TestCase):
    def test_vpstc_addition_preserves_existing_provider_configuration(self):
        path = 'config/services.php'
        base = b"<?php\n\nreturn [\n    'other' => true,\n];\n"
        block = b"    // Reseller origin must be confirmed by VPS.TC; no guessed default host.\n    // Credentials may instead be stored encrypted in the dedicated admin page.\n    'vpstc' => [\n        'base_url' => env('VPSTC_API_BASE_URL', ''),\n        'username' => env('VPSTC_API_USERNAME', ''),\n        'password' => env('VPSTC_API_PASSWORD', ''),\n    ],\n\n"
        desired = base.replace(b'return [\n', b'return [\n' + block)
        current = base.replace(b"'other' => true", b"'other' => false, 'live_only' => 123")
        result = release.merge_runtime(path, current, base, desired)
        self.assertIn(b"'live_only' => 123", result)
        self.assertIn(block, result)
        self.assertEqual(release.merge_runtime(path, result, base, desired), result)
        crlf = current.replace(b'\n', b'\r\n')
        self.assertEqual(release.merge_runtime(path, crlf, base, desired), result.replace(b'\n', b'\r\n'))
        with self.assertRaises(RuntimeError):
            release.merge_runtime(path, current.replace(b"'other'", b"'vpstc'"), base, desired)

    def test_reviewed_caption_patch_preserves_live_controller_formatting_and_other_code(self):
        path = 'app/Http/Controllers/Admin/SettingsController.php'
        old = "        'accounts' => ['t' => 'حساب‌ها',         'icon' => 'i-coins'],\n".encode()
        new = "        'accounts' => ['t' => 'حساب‌ها و پرداخت', 'icon' => 'i-coins'],\n".encode()
        current = b'// existing production change\n' + old
        result = release.merge_runtime(path, current, old, new)
        self.assertEqual(result, current.replace('حساب‌ها'.encode(), 'حساب‌ها و پرداخت'.encode()))
        self.assertEqual(release.merge_runtime(path, result, old, new), result)
        with self.assertRaises(RuntimeError):
            release.merge_runtime(path, current.replace(old, b'other caption\n'), old, new)

    def test_preserves_unrelated_live_changes(self):
        base = b'<?php\n$first = 1;\n$middle = 1;\n$last = 1;\n'
        current = base.replace(b'$first = 1;', b'$first = 2;')
        source = base.replace(b'$last = 1;', b'$last = 3;')
        self.assertEqual(release.merge_candidate(current, base, source),
                         b'<?php\n$first = 2;\n$middle = 1;\n$last = 3;\n')

    def test_overlapping_edits_stop_before_installation(self):
        with self.assertRaisesRegex(RuntimeError, 'Overlapping'):
            release.merge_candidate(b'value=2\n', b'value=1\n', b'value=3\n')

    def test_unexpected_new_file_cannot_be_overwritten(self):
        with self.assertRaisesRegex(RuntimeError, 'Unexpected'):
            release.merge_candidate(b'other release\n', None, b'our release\n')

    def test_preexisting_identical_file_and_expected_absence_are_idempotent(self):
        self.assertEqual(release.merge_candidate(b'new\n', None, b'new\n'), b'new\n')
        self.assertEqual(release.merge_candidate(None, None, b'new\n'), b'new\n')
        with self.assertRaisesRegex(RuntimeError, 'Unexpected'):
            release.merge_candidate(None, b'expected existing\n', b'replacement\n')


if __name__ == '__main__':
    unittest.main()
