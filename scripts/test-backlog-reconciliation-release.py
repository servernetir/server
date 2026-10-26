#!/usr/bin/env python3
"""Offline release-merge safety checks; no application, network or credentials."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('backlog_release', Path(__file__).with_name('deploy-backlog-reconciliation-20261026.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseMergeTest(unittest.TestCase):
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
