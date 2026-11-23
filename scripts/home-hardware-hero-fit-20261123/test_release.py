import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import release


class StaticReleaseTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = Path(self.tmp.name)
        self.stage = self.home / 'stage'
        self.stage.mkdir()
        self.commit = 'a' * 40
        self.rows = []
        self.payloads = {}
        for source, destination in release.SCOPE:
            old, new = ('old:' + source).encode(), ('new:' + source).encode()
            file = self.home / destination
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(old)
            file.chmod(0o644)
            self.payloads[source] = new
            self.rows.append(dict(source=source, destination=destination, before=release.sha(old), after=release.sha(new)))
        def fetch(commit, source):
            self.assertEqual(self.commit, commit)
            return json.dumps({'files': self.rows}).encode() if source.endswith('manifest.json') else self.payloads[source]
        for mock in [patch.object(release, 'HOME', self.home), patch.object(release, 'fetch', side_effect=fetch)]:
            mock.start()
            self.addCleanup(mock.stop)
        refresh = patch.object(release, 'refresh_home')
        self.refresh = refresh.start()
        self.addCleanup(refresh.stop)

    def run_release(self, mode='apply'):
        return release.run(mode, self.commit, self.stage)

    def test_install_verify_and_rollback_exact_bytes(self):
        self.assertEqual(self.run_release(), 'installed_and_verified')
        self.assertEqual(self.run_release('verify'), 'verified')
        for row in self.rows:
            path = self.home / row['destination']
            self.assertEqual(release.sha(path.read_bytes()), row['after'])
            self.assertEqual(path.stat().st_mode & 0o777, 0o644)
        self.assertEqual(self.run_release('rollback'), 'rolled_back')
        release.check(self.rows, 'before')
        self.assertEqual(self.refresh.call_count, 2)

    def test_concurrent_preimage_refuses_all_writes(self):
        path = self.home / self.rows[-1]['destination']
        path.write_bytes(b'concurrent')
        with self.assertRaises(AssertionError): self.run_release()
        self.assertEqual(path.read_bytes(), b'concurrent')
        release.check(self.rows[:-1], 'before')
        self.assertFalse((self.stage / 'prepared.json').exists())
        self.refresh.assert_not_called()

    def test_payload_mismatch_refused(self):
        self.payloads[self.rows[0]['source']] = b'wrong'
        with self.assertRaises(AssertionError): self.run_release()
        release.check(self.rows, 'before')
        self.refresh.assert_not_called()

    def test_cache_activation_failure_restores_original_bytes(self):
        self.refresh.side_effect = [RuntimeError('cache failure'), None]
        with self.assertRaises(RuntimeError): self.run_release()
        release.check(self.rows, 'before')
        self.assertFalse((self.stage / 'receipt.json').exists())
        self.assertEqual(self.refresh.call_count, 2)

    def test_rollback_refuses_concurrent_edit(self):
        self.run_release()
        path = self.home / self.rows[0]['destination']
        path.write_bytes(b'newer change')
        with self.assertRaises(AssertionError): self.run_release('rollback')
        self.assertEqual(path.read_bytes(), b'newer change')
        release.check(self.rows[1:], 'after')

    def test_scope_and_interrupted_stage(self):
        self.rows[0]['destination'] = 'public_html/other.css'
        with self.assertRaises(AssertionError): self.run_release()
        self.rows[0]['destination'] = release.SCOPE[0][1]
        (self.stage / 'prepared.json').write_text('{}')
        with self.assertRaises(AssertionError): self.run_release()
        release.check(self.rows, 'before')

    def test_manifest_from_repository_matches_exact_release_assets(self):
        base = Path(__file__).resolve().parents[2]
        rows = json.loads((Path(__file__).parent / 'manifest.json').read_text())['files']
        predecessor = json.loads((base / 'scripts/home-hardware-hero-20261123/manifest.json').read_text())['files']
        old = {r['destination']:r['after'] for r in predecessor}
        self.assertEqual([(r['source'],r['destination']) for r in rows], release.SCOPE)
        for row in rows:
            self.assertEqual(row['before'], old[row['destination']])
            self.assertEqual(row['after'], release.sha((base / row['source']).read_bytes()))


if __name__ == '__main__': unittest.main()
