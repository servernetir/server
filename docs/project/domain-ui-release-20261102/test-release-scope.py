#!/usr/bin/env python3
"""Local fixed-scope policy checks; native PHP lint/behavior remains a separate CI gate."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent
ENGINE = (ROOT / 'engine.php').read_text()
LAUNCHER = (ROOT / 'launcher.php.template').read_text()

class ReleaseScopeTest(unittest.TestCase):
    def test_exact_file_allowlist_and_private_engine(self):
        allowed = ENGINE.split('private const ALLOWED = [', 1)[1].split('];', 1)[0]
        views = ENGINE.split('private const VIEWS = [', 1)[1].split('];', 1)[0]
        paths = re.findall(r"'([^']+)'", allowed + views)
        self.assertEqual(9, len(paths))
        self.assertEqual(9, len(set(paths)))
        self.assertTrue(all('..' not in p and '*' not in p for p in paths))
        self.assertIn("private const STAGE = self::HOME.'/domain-ui-release-20261102'", ENGINE)

    def test_authenticated_post_and_pinned_inputs(self):
        self.assertIn("->middleware(['web', 'auth:web', 'admin'])", LAUNCHER)
        self.assertIn('csrf_field()', LAUNCHER)
        self.assertIn("hash_file('sha256', $engine) === DUI_ENGINE_SHA256", LAUNCHER)
        self.assertIn("hash_file('sha256', $payload) === DUI_PAYLOAD_SHA256", LAUNCHER)
        self.assertIn("['preview', 'apply', 'verify', 'rollback']", LAUNCHER)
        self.assertNotIn("input('path'", LAUNCHER)
        self.assertNotIn("input('command'", LAUNCHER)

    def test_no_global_activation_or_financial_dispatch(self):
        for forbidden in ['Artisan::call', 'opcache_reset(', 'cache:clear', 'config:clear', 'route:clear',
                          'schedule:run', 'migrate', 'domain-register', 'domain-renew', 'curl_exec', 'shell_exec']:
            self.assertNotIn(forbidden, ENGINE)
        self.assertIn("$store->forget('page:g'", ENGINE)
        self.assertIn("['/', 'domains', 'en', 'en/domains', 'tr', 'tr/domains']", ENGINE)
        self.assertIn("['', '/index.php']", ENGINE)

    def test_guarded_order_and_duplicate_protection(self):
        for lock in ['.servernet-web-release.lock', 'servernet-release.lock', '.codex-web-deploy.lock']:
            self.assertIn(lock, ENGINE)
        self.assertIn('LOCK_EX | LOCK_NB', ENGINE)
        self.assertIn('duplicate_or_unprepared_apply', ENGINE)
        self.assertIn('rollback_newer_file_rejected', ENGINE)
        self.assertIn('rollback_newer_compiled_rejected', ENGINE)
        self.assertIn("$this->atomic($path, (string) file_get_contents($backup), $mode, $expected)", ENGINE)
        restore = ENGINE.split('private function restore(array $state)', 1)[1].split('private function restoreOne', 1)[0]
        self.assertLess(restore.index("$this->opcache(array_column($state['compiled'], 'path'))"),
                        restore.index("if (! in_array($entry['destination'], self::VIEWS, true))"))

if __name__ == '__main__':
    unittest.main()
