"""Acceptance cases for incomplete observations and misleadingly successful HTML."""
import importlib.util
from email.message import Message
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("check_public", Path(__file__).with_name("check_public.py"))
CHECK = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(CHECK)

TARGET = {"path": "/en/vps/hourly", "language": "en", "alternates": {"fa": "/vps/hourly", "en": "/en/vps/hourly"},
          "required_ids": ["linux-hourly-guide"]}
HTML = b'''<!doctype html><html lang="en"><head><title>Hourly VPS</title>
<link rel="canonical" href="/en/vps/hourly"><link rel="alternate" hreflang="fa" href="https://servernet.cloud/vps/hourly">
<link rel="alternate" hreflang="en" href="/en/vps/hourly"><meta name="robots" content="index, follow">
<script type="application/ld+json">{"@type":"WebPage"}</script></head>
<body><h1>Hourly VPS</h1><section id="linux-hourly-guide">Linux guide</section></body></html>'''


class PublicCheckTest(unittest.TestCase):
    def test_cache_bypass_is_requested_and_verified_without_changing_canonical(self):
        class Reply:
            status = 200
            def __init__(self, cache):
                self.headers = Message()
                self.headers['Content-Type'] = 'text/html; charset=utf-8'
                if cache is not None:
                    self.headers['X-Cache'] = cache
            def read(self, limit):
                return HTML
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass
        for cache, state, issue in [('BYPASS', 'pass', None), ('STALE', 'fail', 'stale_response'),
                                    ('HIT', 'fail', 'cache_not_bypassed'), (None, 'unknown', 'cache_bypass_unverified')]:
            with self.subTest(cache=cache), patch.object(CHECK, 'build_opener') as builder:
                builder.return_value.open.return_value = Reply(cache)
                result = CHECK.fetch_once(TARGET, 5, 'test-run')
                sent = builder.return_value.open.call_args.args[0]
            self.assertEqual(sent.full_url, 'https://servernet.cloud/en/vps/hourly?seo_cycle=test-run')
            self.assertEqual(result['state'], state)
            self.assertEqual(result['x_cache'], cache)
            self.assertNotIn('self_canonical', result['issues'])
            if issue:
                self.assertIn(issue, result['issues'])

    def test_relative_links_and_valid_semantic_page_pass(self):
        self.assertEqual(CHECK.inspect_html(HTML, TARGET)["issues"], [])

    def test_success_status_cannot_hide_login_or_error_page(self):
        result = CHECK.inspect_html(b'<html lang="en"><head><title>Sign in</title></head><body>Sign in</body></html>', TARGET)
        self.assertTrue({"h1_count", "self_canonical", "jsonld_missing", "missing_id:linux-hourly-guide"}.issubset(result["issues"]))

    def test_wrong_language_and_duplicate_canonical_are_failures(self):
        wrong = HTML.replace(b'lang="en"', b'lang="tr"', 1).replace(b'</head>', b'<link rel="canonical" href="/gpu"></head>')
        self.assertTrue({"language", "self_canonical"}.issubset(CHECK.inspect_html(wrong, TARGET)["issues"]))

    def test_duplicate_hreflang_and_invalid_jsonld_are_failures(self):
        wrong = HTML.replace(b'{"@type":"WebPage"}', b'{invalid}').replace(b'</head>', b'<link rel="alternate" hreflang="en" href="/en/gpu"></head>')
        self.assertTrue({"alternate_en", "jsonld_invalid"}.issubset(CHECK.inspect_html(wrong, TARGET)["issues"]))

    def test_meta_and_header_noindex_are_detected(self):
        for wrong in [HTML.replace(b'index, follow', b'noindex, follow'), HTML.replace(b'index, follow', b'none')]:
            self.assertIn("noindex", CHECK.inspect_html(wrong, TARGET)["issues"])
        self.assertIn("noindex", CHECK.inspect_html(HTML, TARGET, {"X-Robots-Tag": "googlebot: noindex"})["issues"])

    def test_out_of_scope_paths_are_rejected_before_network(self):
        for path in ['https://attacker.test/', '//attacker.test/', '/admin', '/order/test', '/vps/hourly?token=x', '/en/../admin', '/blog/%61dmin', '/blog/test#foo']:
            with self.subTest(path=path), self.assertRaises(ValueError):
                CHECK.path_url(path)
        self.assertIsNone(CHECK.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://attacker.test/'))

    def test_network_unknown_retries_once_and_stays_distinct_from_site_failure(self):
        with patch.object(CHECK, 'fetch_once', return_value={"state": "unknown", "issues": ["network_observation_failed"]}) as fetch:
            result = CHECK.check_target(TARGET, 5)
        self.assertEqual(fetch.call_count, 2)
        self.assertEqual(result["state"], "unknown")
        self.assertFalse(result["recovered_on_retry"])

    def test_recovered_failure_is_preserved_in_receipt(self):
        with patch.object(CHECK, 'fetch_once', side_effect=[{"state": "fail", "http_status": 503}, {"state": "pass", "http_status": 200}]):
            result = CHECK.check_target(TARGET, 5)
        self.assertTrue(result["recovered_on_retry"])
        self.assertEqual(result["attempts"][0]["http_status"], 503)

    def test_duplicate_or_wrong_language_targets_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            file = Path(directory) / 'targets.json'
            for rows in [[TARGET, TARGET], [dict(TARGET, language='fa')]]:
                file.write_text(json.dumps({"schema_version": 1, "targets": rows}))
                with self.assertRaises(ValueError):
                    CHECK.load_targets(file)


if __name__ == '__main__':
    unittest.main()
