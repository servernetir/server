import hashlib
import json
from pathlib import Path
import unittest

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]


def sha(data):
    return hashlib.sha256(data).hexdigest()


class NovemberOpportunityReleaseTest(unittest.TestCase):
    def test_scope_and_payloads(self):
        plan = json.loads((HERE/'manifest.json').read_text())
        edits = json.loads((HERE/'edits.json').read_text())
        self.assertEqual(10, len(plan['files']))
        self.assertEqual(10, len({row['destination'] for row in plan['files']}))
        self.assertEqual(4, len(edits))
        self.assertEqual(['pages.lookup','partials.tool-next-step'], plan['views'])
        for row in plan['files']:
            self.assertEqual('servernet_app/'+row['source'].removeprefix('website/'), row['destination'])
            self.assertRegex(row['before'], r'^[0-9a-f]{64}$')
            self.assertRegex(row['after'], r'^[0-9a-f]{64}$')
            current = (ROOT/row['source']).read_bytes()
            if not row.get('patch'):
                self.assertEqual(row['after'], sha(current))
                self.assertNotIn(row['source'], edits)
                continue
            # Simulate a distinct live-only edit. The exact old-byte guards
            # permit the reviewed replacements and preserve every other byte.
            allowed = edits[row['source']]
            baseline = current
            for edit in reversed(allowed):
                self.assertEqual(1, edit['count'])
                old, new = edit['old'].encode(), edit['new'].encode()
                self.assertEqual(1, baseline.count(new))
                baseline = baseline.replace(new, old, 1)
            drift = b'\n// independent live edit\n'
            original = baseline + drift
            for edit in allowed:
                old, new = edit['old'].encode(), edit['new'].encode()
                self.assertEqual(1, original.count(old))
                original = original.replace(old, new, 1)
            self.assertEqual(current+drift, original)
        for helper in plan['helpers'].values():
            self.assertEqual(helper['sha256'], sha((ROOT/helper['path']).read_bytes()))

    def test_pin_previously_reviewed_release_engine(self):
        self.assertEqual('1cf55c18261d113819abbf301ac727c70c055bcf9f12b3aba4fe9b3dedca1dea',
                         sha((ROOT/'scripts/search-growth-20261124/release.py').read_bytes()))


if __name__ == '__main__':
    unittest.main()
