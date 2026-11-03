import importlib.util
import pathlib
import unittest

spec=importlib.util.spec_from_file_location('release',pathlib.Path(__file__).with_name('deploy.py'))
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)

class ContextOverlayTest(unittest.TestCase):
    def test_concurrent_content_outside_the_hunk_survives(self):
        old=b'header\na\nb\nc\nchange\nx\ny\nz\nfooter\n'
        new=old.replace(b'change',b'new value')
        live=old.replace(b'header',b'concurrent header')
        self.assertEqual(release.overlay(old,new,live),new.replace(b'header',b'concurrent header'))

    def test_changed_context_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError,'context_conflict'):
            release.overlay(b'a\nold\nb\n',b'a\nnew\nb\n',b'edited\nold\nb\n')

    def test_ambiguous_context_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError,'context_conflict'):
            release.overlay(b'a\nold\nb\n',b'a\nnew\nb\n',b'a\nold\nb\na\nold\nb\n')

    def test_already_installed_bytes_are_idempotent(self):
        self.assertEqual(release.overlay(b'old\n',b'new\n',b'new\n'),b'new\n')

if __name__=='__main__':unittest.main()
