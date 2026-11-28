"""Local-only tests of the non-deployable release review record."""

import copy
import json
import unittest

import verify_review as review


class ReviewTest(unittest.TestCase):
    def setUp(self):
        self.manifest = json.loads(review.REVIEW.read_text())

    def test_exact_git_candidate_scope_and_hashes(self):
        self.assertEqual(set(review.verify(self.manifest)), review.EXPECTED_RECONCILIATION)

    def test_reject_wrong_public_asset_destination(self):
        modified = copy.deepcopy(self.manifest)
        row = next(r for r in modified['files'] if r['source'].endswith('.css'))
        row['destination'] = 'servernet_app/public/assets/css/international-market.css'
        with self.assertRaises(AssertionError):
            review.verify(modified)

    def test_reject_unreviewed_install_hash(self):
        modified = copy.deepcopy(self.manifest)
        row = next(r for r in modified['files'] if r['source'] in review.EXPECTED_RECONCILIATION)
        row['reviewed_install_sha256'] = row['candidate_sha256']
        with self.assertRaises(AssertionError):
            review.verify(modified)

    def test_clean_merge_preserves_independent_live_change(self):
        base = b'one\nkeep one\nkeep two\nkeep three\nlast\n'
        live = b'changed live\nkeep one\nkeep two\nkeep three\nlast\n'
        candidate = b'one\nkeep one\nkeep two\nkeep three\nchanged pilot\n'
        expected = b'changed live\nkeep one\nkeep two\nkeep three\nchanged pilot\n'
        self.assertEqual(review.three_way(live, base, candidate), expected)

    def test_stop_on_conflicting_changes(self):
        self.assertIsNone(review.three_way(b'live\n', b'base\n', b'pilot\n'))


if __name__ == '__main__':
    unittest.main()
