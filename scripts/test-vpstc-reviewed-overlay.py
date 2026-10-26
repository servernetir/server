#!/usr/bin/env python3
"""Verify reviewed VPS.TC overlays using private live snapshots, never production.

Requires --snapshots DIR with CloudStoreController.php and cloud-store.blade.php,
--target SHA available in --repository (defaults to this checkout), and --php.
The private production source snapshots are deliberately not included in Git.
"""
import argparse
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

_spec = importlib.util.spec_from_file_location('vpstc_reviewed_overlay', Path(__file__).with_name('deploy-vpstc-reviewed-overlay.py'))
overlay = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(overlay)
CONFIG = None


class ReviewedOverlayTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sources = {}
        for relative in overlay.core.RUNTIME_PATHS:
            for commit in (overlay.core.BASE, CONFIG.target):
                result = subprocess.run(['git', 'show', commit + ':website/' + relative],
                                        cwd=CONFIG.repository, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
                cls.sources[commit, relative] = result.stdout if result.returncode == 0 else None
        cls.cases = (
            (overlay.RELATIVE, 'CloudStoreController.php', overlay.cloud_store_overlay,
             overlay.LIVE_SHA256, overlay.CANDIDATE_SHA256),
            (overlay.VIEW_RELATIVE, 'cloud-store.blade.php', overlay.cloud_store_view_overlay,
             overlay.VIEW_LIVE_SHA256, overlay.VIEW_CANDIDATE_SHA256),
        )

    def inputs(self, case):
        relative, filename, function, before_hash, after_hash = case
        before = (CONFIG.snapshots / filename).read_bytes()
        base = self.sources[overlay.core.BASE, relative]
        desired = self.sources[CONFIG.target, relative]
        self.assertIsNotNone(base)
        self.assertIsNotNone(desired)
        return before, base, desired

    def test_exact_known_snapshots_produce_only_the_reviewed_candidate_hashes(self):
        for case in self.cases:
            before, base, desired = self.inputs(case)
            self.assertEqual(case[3], overlay.core.digest(overlay.core.normalized(before, case[0])[0]))
            candidate = case[2](before, base, desired)
            self.assertEqual(case[4], overlay.core.digest(candidate))
        self.assertEqual(5, len(overlay.REPLACEMENTS))
        self.assertEqual(3, len(overlay.VIEW_REPLACEMENTS))

    def test_crlf_is_preserved_without_changing_normalized_candidate(self):
        for case in self.cases:
            before, base, desired = self.inputs(case)
            candidate = case[2](before.replace(b'\n', b'\r\n'), base, desired)
            self.assertEqual(case[4], overlay.core.digest(candidate.replace(b'\r\n', b'\n')))
            self.assertEqual(candidate.count(b'\n'), candidate.count(b'\r\n'))

    def test_unknown_live_or_git_bytes_stop_instead_of_broadening_the_recipe(self):
        for case in self.cases:
            before, base, desired = self.inputs(case)
            for values in ((before + b'\n', base, desired), (before, base + b'\n', desired),
                           (before, base, desired + b'\n')):
                with self.assertRaises(overlay.core.ReleaseError):
                    case[2](*values)

    def test_an_exact_previously_installed_overlay_is_idempotent(self):
        for case in self.cases:
            before, base, desired = self.inputs(case)
            candidate = case[2](before, base, desired)
            self.assertEqual(overlay.core.digest(candidate), overlay.core.digest(case[2](candidate, base, desired)))

    def test_candidates_pass_php_syntax_checks(self):
        with tempfile.TemporaryDirectory(prefix='vpstc-overlay-lint-') as temp:
            for case in self.cases:
                before, base, desired = self.inputs(case)
                candidate = Path(temp) / Path(case[0]).name
                candidate.write_bytes(case[2](before, base, desired))
                process = subprocess.run([CONFIG.php, '-l', str(candidate)],
                                         stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                self.assertEqual(0, process.returncode, 'Candidate PHP syntax rejected: ' + case[0])

    def test_full_private_plan_uses_twenty_two_regular_merges_and_two_exact_overlays(self):
        with tempfile.TemporaryDirectory(prefix='vpstc-reviewed-plan-fixture-') as temporary:
            root = Path(temporary) / 'application'
            root.mkdir()
            before_hashes = {}
            for relative in overlay.core.RUNTIME_PATHS:
                data = self.sources[overlay.core.BASE, relative]
                if data is not None:
                    file = root / relative
                    file.parent.mkdir(parents=True, exist_ok=True)
                    file.write_bytes(data)
            for case in self.cases:
                (root / case[0]).write_bytes(self.inputs(case)[0])
            for relative in overlay.core.RUNTIME_PATHS:
                file = root / relative
                before_hashes[relative] = overlay.core.digest(file.read_bytes()) if file.exists() else None
            with overlay.core.release_locks(root):
                directory, plan = overlay.core.build_plan(root, CONFIG.target, CONFIG.php,
                    lambda commit, relative: self.sources[commit, relative])
                overlay.core.load_plan(root, CONFIG.target, directory, plan['plan_sha256'])
            self.assertEqual(24, len(plan['files']))
            self.assertEqual(2, sum(row['preserved_live_drift'] for row in plan['files']))
            for case in self.cases:
                self.assertEqual(case[4], overlay.core.digest((directory / 'candidate' / case[0]).read_bytes()))
            for relative in overlay.core.RUNTIME_PATHS:
                file = root / relative
                actual = overlay.core.digest(file.read_bytes()) if file.exists() else None
                self.assertEqual(before_hashes[relative], actual, 'Plan changed fixture application: ' + relative)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--snapshots', type=Path, required=True)
    parser.add_argument('--target', required=True)
    parser.add_argument('--repository', type=Path, default=Path(__file__).resolve().parent.parent)
    parser.add_argument('--php', required=True)
    CONFIG = parser.parse_args()
    overlay.core.validate_sha(CONFIG.target)
    unittest.main(argv=[sys.argv[0]])
