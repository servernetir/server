#!/usr/bin/env python3
"""Safety contracts for merging policy copy into a concurrently edited language file."""
import importlib.util
from pathlib import Path
import sys
import unittest

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location(
    'hourly_release', Path(__file__).with_name('deploy-hourly-runtime-credit.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class TranslationReleaseTest(unittest.TestCase):
    base = b'<?php\nreturn [\n    "policy" => "old",\n    "existing" => "keep",\n];\n'
    desired = base.replace(b'"old"', b'"new"').replace(
        b'];\n', b'    "retention" => "separate",\n];\n')

    def test_preserves_unrelated_concurrent_translation(self):
        before = self.base.replace(b'];\n', b'    "another_release" => "keep too",\n];\n')
        merged = release.merge_translations(before, self.base, self.desired)
        self.assertIn(b'"another_release" => "keep too"', merged)
        self.assertIn(b'"policy" => "new"', merged)
        self.assertIn(b'"retention" => "separate"', merged)

    def test_rejects_concurrent_change_to_the_same_key(self):
        with self.assertRaisesRegex(RuntimeError, 'concurrent_translation_conflict:policy'):
            release.merge_translations(self.base.replace(b'old', b'changed'), self.base, self.desired)

    def test_rejects_an_existing_new_key_with_another_value(self):
        before = self.base.replace(b'];\n', b'    "retention" => "different",\n];\n')
        with self.assertRaisesRegex(RuntimeError, 'concurrent_translation_conflict:retention'):
            release.merge_translations(before, self.base, self.desired)

    def test_rejects_a_non_translation_source_change(self):
        with self.assertRaisesRegex(RuntimeError, 'non_translation_source_delta'):
            release.merge_translations(self.base, self.base,
                                       self.desired.replace(b'<?php', b'<?php // unexpected'))

    def test_is_idempotent(self):
        self.assertEqual(self.desired,
                         release.merge_translations(self.desired, self.base, self.desired))

    def test_preserves_existing_crlf(self):
        self.assertEqual(self.desired.replace(b'\n', b'\r\n'), release.merge_translations(
            self.base.replace(b'\n', b'\r\n'), self.base, self.desired))


if __name__ == '__main__':
    unittest.main()
