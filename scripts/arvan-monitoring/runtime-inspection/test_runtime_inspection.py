#!/usr/bin/env python3
"""Offline CLI and projection tests; never load Laravel, a database or cURL.

Run with PHP_BINARY=/path/to/php python3 -m unittest discover -s <this-dir>
The actual, unmodified CLI is exercised against empty and canary-only directories.
Pure helpers are loaded from the actual source prefix, before its execution guard.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

TOOL = Path(__file__).with_name("inspect-runtime.php").resolve()
CANARY = "OFFLINE_CANARY_MUST_NEVER_LOAD"
SECRET = "SYNTHETIC_PRIVATE_VALUE_MUST_NOT_PROJECT"

# The harness imports only the real declarations. It does not alter the tool,
# fabricate runtime configuration, invoke --inspect or provide a route override.
PURE_HARNESS = r"""
$source = file_get_contents($argv[1]);
$guard = "if (PHP_SAPI !== 'cli')";
if (!is_string($source) || substr_count($source, $guard) !== 1) { exit(91); }
$prefix = substr($source, 5, strpos($source, $guard) - 5);
eval($prefix);
$input = json_decode(stream_get_contents(STDIN), true, 128, JSON_THROW_ON_ERROR);
try {
    switch ($input['action']) {
        case 'state': $result = snState($input['state'], $input['now'], $input['customer']); break;
        case 'coverage': $result = snCoverage($input['snapshot'], $input['refs'], $input['now']); break;
        case 'artifact':
            [$parsed, $hash] = snReadJson($input['directory'], $input['name'], $input['maximum']);
            // Project the parser's computed digest, not raw synthetic contents.
            $result = ['sha256' => $hash, 'keys' => array_keys($parsed)]; break;
        default: exit(92);
    }
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error_class' => get_class($error),
        'error_code' => $error instanceof RuntimeException ? $error->getMessage() : 'invalid_json'], JSON_THROW_ON_ERROR);
}
"""


class RuntimeInspectionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        selected = os.environ.get("PHP_BINARY", "php")
        cls.php = shutil.which(selected) if not os.path.isabs(selected) else selected
        if not cls.php or not Path(cls.php).is_file():
            raise RuntimeError("PHP_BINARY must select an available PHP executable")
        result = subprocess.run([cls.php, "-r", "echo PHP_INT_MAX;"], capture_output=True, text=True, timeout=5, check=True)
        cls.php_int_max = int(result.stdout)

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="runtime-inspection-offline-")
        self.directory = Path(self.temp.name)
        self.empty = self.directory / "empty"
        self.empty.mkdir()
        self.canary_root = self.directory / "canary-app"
        (self.canary_root / "vendor").mkdir(parents=True)
        (self.canary_root / "bootstrap").mkdir()
        self.canary_marker = self.directory / "autoload-ran"
        php_marker = json.dumps(str(self.canary_marker))
        canary = f'<?php file_put_contents({php_marker}, "{CANARY}"); echo "{CANARY}"; exit(99);'
        # They satisfy marker checks only. Valid --inspect never runs here.
        for path in [self.canary_root / "artisan", self.canary_root / "bootstrap/app.php", self.canary_root / "vendor/autoload.php"]:
            path.write_text(canary)

    def tearDown(self):
        self.temp.cleanup()

    def invoke(self, args, cwd=None):
        return subprocess.run([self.php, str(TOOL), *args], cwd=cwd or self.canary_root,
                              capture_output=True, text=True, timeout=5, check=False)

    def pure(self, value):
        result = subprocess.run([self.php, "-r", PURE_HARNESS, str(TOOL)], cwd=self.empty,
                                input=json.dumps(value), capture_output=True, text=True, timeout=5, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        self.assertFalse(self.canary_marker.exists())
        return json.loads(result.stdout)

    def reject(self, args):
        result = self.invoke(args)
        self.assertEqual(result.returncode, 64, (result.stdout, result.stderr))
        self.assertEqual(result.stdout, "", "Malformed CLI must not produce a diagnostic JSON result")
        self.assertIn("Usage:", result.stderr)
        self.assertNotIn(CANARY, result.stdout + result.stderr)
        self.assertFalse(self.canary_marker.exists(), "Parsing must precede autoload/configuration")

    def accept_before_autoload(self, args):
        # An empty directory deliberately fails app-root validation. Reaching
        # its structured preflight error proves parsing accepted the arguments.
        result = self.invoke(args, self.empty)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertEqual(result.stderr, "")
        body = json.loads(result.stdout)
        self.assertEqual(body["result"], "diagnostic_incomplete")
        self.assertEqual(body["failed_stage"], "preflight")
        self.assertEqual(body["error_code"], "wrong_application_directory")
        self.assertTrue(body["read_only"])
        self.assertFalse(body["application_providers_booted"])
        self.assertFalse(body["commands_executed"])
        self.assertNotIn("runtime", body)
        self.assertNotIn("fleet", body)
        self.assertFalse(self.canary_marker.exists())

    def test_help_is_standalone_even_beside_autoload_canary(self):
        result = self.invoke(["--help"])
        self.assertEqual(result.returncode, 0)
        self.assertIn("Read-only bounded diagnostics", result.stdout)
        self.assertEqual(result.stderr, "")
        self.assertFalse(self.canary_marker.exists())
        self.assertNotIn(CANARY, result.stdout)

    def test_self_test_is_synthetic_and_redacted_before_bootstrap(self):
        result = self.invoke(["--self-test"])
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        body = json.loads(result.stdout)
        self.assertEqual(body["self_test"], "passed")
        self.assertGreaterEqual(body["assertions"], 22)
        self.assertNotIn("NEVER_OUTPUT_SECRET", result.stdout)
        self.assertFalse(self.canary_marker.exists())

    def test_state_projection_uses_requested_customer_and_redacts_private_fields(self):
        state = {"version": 1, "enabled": True, "authorization": SECRET, "api_token": SECRET,
                 "excluded_customers": [17], "last_success": "2026-11-03T02:00:00Z",
                 "last_result": {"source_hash": "a" * 64, "held": [{"reason": "owner_exclusion", "note": SECRET},
                                                                            {"reason": SECRET}], "unallocated_count": 1}}
        now = 1793674800
        first = self.pure({"action": "state", "state": state, "now": now, "customer": 17})["result"]
        other = self.pure({"action": "state", "state": state, "now": now, "customer": 18})["result"]
        self.assertTrue(first["requested_customer_excluded"])
        self.assertFalse(other["requested_customer_excluded"])
        self.assertEqual(other["requested_customer_id"], 18)
        self.assertEqual(first["held_reason_counts"], {"owner_exclusion": 1, "unknown": 1})
        self.assertIsNone(first["checkpoint_count"])
        self.assertEqual(first["applied_source_hash"], "a" * 64)
        self.assertFalse(first["last_success_is_usage_freshness_proof"])
        self.assertNotIn(SECRET, json.dumps(first))

    def test_missing_state_never_claims_exclusion_or_zero_cost(self):
        body = self.pure({"action": "state", "state": None, "now": 1793674800, "customer": 17})["result"]
        self.assertFalse(body["initialized"])
        for key in ["requested_customer_excluded", "held_count", "checkpoint_count", "last_success", "unallocated_count"]:
            self.assertIsNone(body[key], key)

    def fixture_snapshot(self):
        ref = "ir-thr-c2:11111111-2222-3333-4444-555555555555"
        row = {"region": "ir-thr-c2", "instanceId": ref.split(":")[1], "productName": "iaas", "scope": "instance",
               "items": [{"metric": "cc_internet", "usageFrom": "2026-11-01T00:00:00Z",
                          "usageTo": "2026-11-02T00:00:00Z", "price": SECRET}]}
        snapshot = {"observed_at": "2026-11-03T02:00:00Z", "current_cycle": "synthetic-cycle",
                    "reports": [{"cycle": {"id": "synthetic-cycle", "accountId": SECRET}, "rows": [row], "total_items": 1}]}
        return ref, snapshot

    def test_coverage_projects_matching_window_and_missing_resource_as_unknown(self):
        ref, snapshot = self.fixture_snapshot()
        missing = "ir-thr-c2:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"
        out = self.pure({"action": "coverage", "snapshot": snapshot, "refs": [ref, missing], "now": 1793674800})["result"]
        self.assertTrue(out["bounded_shape_consistent"])
        self.assertEqual(out["resources"][0]["current_cycle_item_count"], 1)
        self.assertEqual(out["resources"][0]["current_cycle_newest_usage_to"], "2026-11-02T00:00:00Z")
        self.assertIsNone(out["resources"][1]["current_cycle_item_count"])
        self.assertIsNone(out["resources"][1]["current_cycle_oldest_usage_to"])
        self.assertFalse(out["supplier_fetched_now"])
        self.assertFalse(out["financial_totals_revalidated"])
        self.assertNotIn(SECRET, json.dumps(out))

    def test_coverage_rejects_future_usage_window_without_inventing_freshness(self):
        ref, snapshot = self.fixture_snapshot()
        snapshot["reports"][0]["rows"][0]["items"][0]["usageTo"] = "2026-11-04T00:00:00Z"
        out = self.pure({"action": "coverage", "snapshot": snapshot, "refs": [ref], "now": 1793674800})["result"]
        self.assertFalse(out["bounded_shape_consistent"])
        self.assertEqual(out["resources"][0]["evidence"], "invalid_stored_usage_window")
        self.assertIsNone(out["resources"][0]["current_cycle_newest_usage_to"])

    def test_coverage_duplicate_cycles_fail_closed(self):
        ref, snapshot = self.fixture_snapshot()
        snapshot["reports"].append(snapshot["reports"][0])
        out = self.pure({"action": "coverage", "snapshot": snapshot, "refs": [ref], "now": 1793674800})
        self.assertFalse(out["ok"])
        self.assertEqual(out["error_code"], "snapshot_cycle_unknown")

    def test_bounded_artifact_reader_returns_exact_raw_sha256_not_reserialized_hash(self):
        raw = b'{ "synthetic": "private", "value": 17 }\n'
        (self.empty / "snapshot.json").write_bytes(raw)
        out = self.pure({"action": "artifact", "directory": str(self.empty), "name": "snapshot.json", "maximum": 256})
        self.assertTrue(out["ok"])
        self.assertEqual(out["result"]["sha256"], hashlib.sha256(raw).hexdigest())
        self.assertEqual(out["result"]["keys"], ["synthetic", "value"])

    def test_artifact_traversal_symlink_directory_oversize_and_nonobject_refused(self):
        (self.directory / "outside.json").write_text('{"synthetic":17}')
        (self.empty / "link.json").symlink_to(self.directory / "outside.json")
        (self.empty / "subdirectory").mkdir()
        (self.empty / "large.json").write_text('{"synthetic":"' + "x" * 100 + '"}')
        (self.empty / "scalar.json").write_text('"synthetic"')
        (self.empty / "broken.json").write_text('{')
        for name, maximum, reason in [("../outside.json", 256, "unsafe_artifact_name"),
                                      (str(self.directory / "outside.json"), 256, "unsafe_artifact_name"),
                                      ("link.json", 256, "artifact_unavailable"),
                                      ("subdirectory", 256, "artifact_unavailable"),
                                      ("large.json", 16, "artifact_unavailable"),
                                      ("scalar.json", 256, "artifact_invalid"),
                                      ("broken.json", 256, "invalid_json")]:
            with self.subTest(name=name):
                out = self.pure({"action": "artifact", "directory": str(self.empty), "name": name, "maximum": maximum})
                self.assertFalse(out["ok"])
                self.assertEqual(out["error_code"], reason)


INVALID_CASES = {
    "empty": [],
    "inspect_only": ["--inspect"],
    "customer_only": ["--customer=17"],
    "customer_empty": ["--inspect", "--customer="],
    "zero": ["--inspect", "--customer=0"],
    "negative": ["--inspect", "--customer=-17"],
    "plus_sign": ["--inspect", "--customer=+17"],
    "leading_zero": ["--inspect", "--customer=017"],
    "decimal": ["--inspect", "--customer=17.0"],
    "scientific": ["--inspect", "--customer=1e3"],
    "newline_customer": ["--inspect", "--customer=17\n"],
    "newline_inspect": ["--inspect\n", "--customer=17"],
    "unicode_digits": ["--inspect", "--customer=۱۷"],
    "duplicate_inspect": ["--inspect", "--customer=17", "--inspect"],
    "duplicate_same_customer": ["--inspect", "--customer=17", "--customer=17"],
    "duplicate_different_customer": ["--inspect", "--customer=17", "--customer=18"],
    "unknown": ["--inspect", "--customer=17", "--dry-run"],
    "positional": ["--inspect", "--customer=17", "extra"],
    "split_customer": ["--inspect", "--customer", "17"],
    "inspect_value": ["--inspect=1", "--customer=17"],
    "leading_space": ["--inspect", "--customer= 17"],
    "trailing_space": ["--inspect", "--customer=17 "],
    "help_inspect": ["--help", "--inspect", "--customer=17"],
    "inspect_help": ["--inspect", "--customer=17", "--help"],
    "help_customer": ["--help", "--customer=17"],
    "selftest_inspect": ["--self-test", "--inspect", "--customer=17"],
    "help_selftest": ["--help", "--self-test"],
}


def invalid_case(arguments):
    def test(self):
        self.reject(arguments)
    return test


for case, arguments in INVALID_CASES.items():
    setattr(RuntimeInspectionTest, "test_cli_invalid_" + case, invalid_case(arguments))


def valid_case(kind):
    def test(self):
        value = self.php_int_max if kind == "maximum" else (1 if kind == "minimum" else 17)
        arguments = ["--inspect", "--customer=" + str(value)]
        self.accept_before_autoload(list(reversed(arguments)) if kind == "reversed" else arguments)
    return test


for case in ["positive", "minimum", "maximum", "reversed"]:
    setattr(RuntimeInspectionTest, "test_cli_valid_" + case, valid_case(case))


def test_overflow(self):
    self.reject(["--inspect", "--customer=" + str(self.php_int_max + 1)])


RuntimeInspectionTest.test_cli_invalid_overflow = test_overflow

if __name__ == "__main__":
    unittest.main()
