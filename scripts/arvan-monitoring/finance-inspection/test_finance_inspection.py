#!/usr/bin/env python3
"""Offline tests for the explicit diagnostic CLI and its actual pure helpers.

No Laravel/bootstrap, database, provider credential or network is used. PHP_BINARY
selects the native CLI; all fixtures and autoload canaries are temporary/synthetic.
"""
from __future__ import annotations

import copy
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

TOOL = Path(__file__).with_name("inspect-traffic-finance.php").resolve()
SOURCE = TOOL.parents[3] / "website/app/Services/Finance/ArvanTrafficSource.php"
REF = "ir-thr-c2:11111111-2222-3333-4444-555555555555"
CYCLE = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"
SECRET = "SYNTHETIC_PRIVATE_VALUE_DO_NOT_PROJECT"
CANARY = "OFFLINE_AUTOLOAD_MUST_NOT_RUN"
VALID = ["--inspect", "--customer=17", "--service=18", "--instance=17", "--provider-ref=" + REF]

PURE_HARNESS = r"""
$source = file_get_contents($argv[1]);
$guard = "if (PHP_SAPI !== 'cli')";
if (!is_string($source) || substr_count($source, $guard) !== 1) { exit(91); }
eval(substr($source, 5, strpos($source, $guard) - 5));
require $argv[2]; // The ACTUAL restored validator; never a fake financial validator.
class FixtureStatement {
    public function __construct(private mixed $value) {}
    public function fetchColumn(): mixed { return $this->value; }
}
class FixturePDO {
    public array $sql = []; public bool $active = false;
    public ?Closure $onQuery = null;
    public function __construct(public array $config = []) {}
    public function exec(string $sql): int { $this->sql[] = $sql; return 0; }
    public function getAttribute(int $attribute): string { return $this->config['version'] ?? '10.11.6-MariaDB'; }
    public function query(string $sql): FixtureStatement {
        $this->sql[] = $sql;
        if ($this->onQuery !== null) ($this->onQuery)($sql);
        if (!empty($this->config['fallback_variable']) && str_contains($sql, 'tx_read_only')) throw new PDOException('synthetic unavailable variable');
        if (!empty($this->config['fallback_isolation_variable']) && str_contains($sql, 'tx_isolation')) throw new PDOException('synthetic unavailable isolation variable');
        if (str_contains($sql, 'isolation')) return new FixtureStatement($this->config['isolation'] ?? 'REPEATABLE-READ');
        return new FixtureStatement($this->config['readonly'] ?? 1);
    }
    public function inTransaction(): bool { return $this->active && empty($this->config['not_in_transaction']); }
}
class FixtureCollection {
    public function __construct(private array $rows) {}
    public function all(): array { return $this->rows; }
    public function map(Closure $callback): self { return new self(array_map($callback, $this->rows)); }
}
class FixtureBudgetQuery {
    public array $columns = ['id', 'note']; private bool $aggregate = false;
    public function __construct(public string $from, private object $trace, private array $input) {}
    public function reorder(): self { $this->trace->events[] = 'probe_reorder'; return $this; }
    public function select(array $columns): self { $this->columns = $columns; return $this; }
    public function selectRaw(string $sql): self { $this->aggregate = true; $this->trace->aggregate_sql = $sql; return $this; }
    public function first(): object {
        $this->trace->probe_calls++; $this->trace->events[] = 'probe';
        return (object) ['row_count' => $this->input['count'], 'text_bytes' => $this->input['text_bytes']];
    }
    public function orderBy(...$unused): self { return $this; }
    public function limit(...$unused): self { return $this; }
    public function get(array $columns): FixtureCollection {
        $this->trace->get_calls++; $this->trace->events[] = 'get';
        if ($this->aggregate || $this->columns !== ['id', 'note']) throw new RuntimeException('aggregate_mutated_original_query');
        return new FixtureCollection(array_map(static fn ($row) => (object) $row, $this->input['rows'] ?? []));
    }
}
class FixtureQuery {
    public function __construct(private FixtureDB $db, private string $table) {}
    public function join(...$unused): self { return $this; }
    public function where(...$unused): self { return $this; }
    public function select(...$unused): self { return $this; }
    public function selectRaw(...$unused): self { return $this; }
    public function limit(...$unused): self { return $this; }
    public function get(): FixtureCollection { return new FixtureCollection([(object) $this->db->snapshot]); }
    public function pluck(string $column): FixtureCollection {
        return new FixtureCollection(array_fill(0, $this->db->config['duplicates'] ?? 1, $this->db->snapshot['instance_id']));
    }
}
class FixtureDB {
    public FixturePDO $pdo; public int $level = 0; public int $begins = 0; public int $rollbacks = 0;
    public array $snapshot = []; public array $oldPdos = [];
    public function __construct(public array $live, public array $config = []) { $this->pdo = new FixturePDO($config); }
    public function transactionLevel(): int { return $this->level; }
    public function getPdo(): FixturePDO { return $this->pdo; }
    public function beginTransaction(): void {
        $this->begins++; $this->level = $this->config['begin_level'] ?? 1; $this->snapshot = $this->live;
        if (!empty($this->config['reconnect'])) { $this->oldPdos[] = $this->pdo; $this->pdo = new FixturePDO(['readonly' => 0]); }
        $this->pdo->active = true;
    }
    public function rollBack(): void { $this->rollbacks++; $this->level = 0; $this->pdo->active = false; $this->snapshot = []; }
    public function table(string $table): FixtureQuery { return new FixtureQuery($this, $table); }
}
$input = json_decode(stream_get_contents(STDIN), true, 128, JSON_THROW_ON_ERROR);
$calls = []; $db = null; $trace = null; $remaining = null;
try {
    switch ($input['action']) {
        case 'digest': $result = tfDigest($input['value']); break;
        case 'select': $result = tfSelectItem($input['snapshot'], $input['ref'], $input['accounts'], $input['now']); break;
        case 'ledger': $result = tfLedgerSummary($input['sets'], $input['target_ref'] ?? null,
            $input['target_service'] ?? null, $input['target_customer'] ?? null); break;
        case 'identity': $result = tfIdentity($input['row'], $input['options']); break;
        case 'current_status': $result = tfCurrentStatus($input['row']); break;
        case 'reserve':
            $remaining = $input['remaining'];
            foreach ($input['batches'] as $batch) tfReserveRows($batch['count'], $batch['text_bytes'], $batch['limit'], $remaining);
            $result = ['remaining' => $remaining]; break;
        case 'rows':
            $trace = (object) ['get_calls' => 0, 'probe_calls' => 0, 'events' => []];
            $query = new FixtureBudgetQuery($input['table'] ?? 'credit_ledger', $trace, $input);
            $result = tfRows($query, $input['limit'], ['id', 'note']); break;
        case 'fresh_read':
            $db = new FixtureDB($input['row'], $input['config'] ?? []);
            if (!empty($input['active_snapshot'])) $db->beginTransaction();
            $flavor = tfBeginFreshRead($db);
            $result = ['flavor' => $flavor, 'begins' => $db->begins, 'sql' => $db->pdo->sql];
            break;
        case 'phase_identity':
            $db = new FixtureDB($input['row']);
            tfBeginFreshRead($db); $firstPdo = $db->getPdo(); $first = tfTarget($db, $input['options']);
            tfEndFreshRead($db, $firstPdo);
            $db->live = array_replace($db->live, $input['change']);
            tfBeginFreshRead($db); $secondPdo = $db->getPdo(); $second = tfTarget($db, $input['options']);
            tfEndFreshRead($db, $secondPdo);
            $result = ['first' => $first, 'second' => $second, 'changed' => tfDigest($first) !== tfDigest($second)];
            break;
        case 'end_read':
            $db = new FixtureDB($input['row'], $input['config'] ?? []);
            tfBeginFreshRead($db); $expected = $db->getPdo();
            tfTarget($db, $input['options']); // Actual helper reads before end validation.
            $mutation = $input['mutation'] ?? 'none';
            if ($mutation === 'replace') { $db->pdo = new FixturePDO; $db->pdo->active = true; }
            if ($mutation === 'level_zero') $db->level = 0;
            if ($mutation === 'inactive') $expected->active = false;
            if ($mutation === 'readonly_zero') $expected->config['readonly'] = 0;
            if ($mutation === 'isolation_rc') $expected->config['isolation'] = 'READ-COMMITTED';
            if (str_starts_with($mutation, 'query_')) $expected->onQuery = static function (string $sql) use ($db, $expected, $mutation): void {
                if ($mutation === 'query_replace') { $db->pdo = new FixturePDO; $db->pdo->active = true; }
                if ($mutation === 'query_level_zero') $db->level = 0;
                if ($mutation === 'query_inactive') $expected->active = false;
                if ($mutation === 'query_isolation_replace' && str_contains($sql, 'isolation')) { $db->pdo = new FixturePDO; $db->pdo->active = true; }
            };
            tfEndFreshRead($db, $expected);
            $result = ['level' => $db->transactionLevel(), 'active' => $expected->inTransaction(), 'sql' => $expected->sql];
            break;
        case 'target':
            $db = new FixtureDB($input['row'], $input['config'] ?? []);
            tfBeginFreshRead($db); $result = tfTarget($db, $input['options']);
            break;
        case 'validate': $result = (new App\Services\Finance\ArvanTrafficSource)->validate($input['snapshot']); break;
        case 'fetch':
            $responses = $input['responses']; $index = 0; $clockIndex = 0;
            $get = static function (string $path, array $query = []) use (&$calls, &$index, $responses): array {
                $calls[] = ['path' => $path, 'query' => $query];
                if (!array_key_exists($index, $responses)) throw new RuntimeException('unexpected_extra_request');
                $response = $responses[$index++];
                if (isset($response['_throw'])) throw new RuntimeException('synthetic_transport_failure');
                return $response;
            };
            $times = $input['times'] ?? [1793674800];
            $clock = static function () use (&$clockIndex, $times): int {
                return $times[min($clockIndex++, count($times) - 1)];
            };
            $snapshot = tfFetchCurrent($get, $clock);
            (new App\Services\Finance\ArvanTrafficSource)->validate($snapshot);
            $result = $snapshot;
            break;
        default: exit(92);
    }
    echo json_encode(['ok' => true, 'result' => $result, 'calls' => $calls,
        'begins' => $db?->begins, 'rollbacks' => $db?->rollbacks, 'trace' => $trace, 'remaining' => $remaining], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'error_class' => get_class($error),
        'error_code' => $error instanceof RuntimeException ? $error->getMessage() : 'invalid_fixture',
        'calls' => $calls, 'begins' => $db?->begins, 'rollbacks' => $db?->rollbacks,
        'trace' => $trace, 'remaining' => $remaining], JSON_THROW_ON_ERROR);
}
"""


def cycle_fixture(rows):
    price = sum(int(row["totalPrice"]) for row in rows)
    discount = sum(int(row["totalDiscount"]) for row in rows)
    gift = sum(int(row["totalUsedGift"]) for row in rows)
    vat = (price - discount - gift) // 10
    return {"id": CYCLE, "accountId": "synthetic-account", "currency": "irr",
            "startedAt": "2026-11-01T00:00:00Z", "expiredAt": "2026-12-01T00:00:00Z",
            "totalItemsPrice": str(price), "totalItemsDiscount": str(discount), "totalUsedGift": str(gift),
            "totalVatPrice": str(vat), "totalPrice": str(price - discount - gift + vat),
            "items": [{"productName": "iaas"}, {"productName": "cdn"}]}


def rows_fixture(count=21):
    rows = []
    for index in range(count):
        target = index == 0
        price = "3000" if target else "1000"
        item = {"itemId": "synthetic-component-" + str(index), "metric": "cc_internet" if target else "cdn_requests",
                "price": price, "discount": "0", "usedGift": "0", "amount": "3.5",
                "usageFrom": "2026-11-01T00:00:00Z", "usageTo": "2026-11-03T02:00:00Z",
                "private_note": SECRET}
        rows.append({"region": REF.split(":")[0], "instanceId": REF.split(":")[1] if target else "synthetic-other",
                     "productName": "iaas" if target else "cdn", "scope": "instance" if target else "tenant",
                     "items": [item], "totalPrice": price, "totalDiscount": "0", "totalUsedGift": "0", "netPrice": price})
    return rows


def snapshot_fixture(count=21):
    rows = rows_fixture(count)
    return {"observed_at": "2026-11-03T03:00:00Z", "current_cycle": CYCLE,
            "reports": [{"cycle": cycle_fixture(rows), "rows": rows, "total_items": count}]}


def responses_fixture(snapshot=None):
    snapshot = snapshot or snapshot_fixture()
    report = snapshot["reports"][0]
    rows, cycle = report["rows"], report["cycle"]
    pages = max(1, (len(rows) + 19) // 20)
    responses = [{"data": cycle}, {"data": cycle}]
    for page in range(1, pages + 1):
        responses.append({"data": rows[(page - 1) * 20:page * 20],
                          "meta": {"pagination": {"totalItems": len(rows), "totalPages": pages, "current": page}}})
    return responses + [{"data": cycle}, {"data": cycle}]


class FinanceInspectionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        selected = os.environ.get("PHP_BINARY", "php")
        cls.php = shutil.which(selected) if not os.path.isabs(selected) else selected
        if not cls.php or not Path(cls.php).is_file():
            raise RuntimeError("PHP_BINARY must select an available PHP executable")
        result = subprocess.run([cls.php, "-r", "echo PHP_INT_MAX;"], capture_output=True, text=True, timeout=5, check=True)
        cls.php_int_max = int(result.stdout)

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="finance-inspection-offline-")
        self.root = Path(self.temp.name)
        self.empty = self.root / "empty"
        self.empty.mkdir()
        self.canary_root = self.root / "canary-app"
        (self.canary_root / "vendor").mkdir(parents=True)
        (self.canary_root / "bootstrap").mkdir()
        self.canary_marker = self.root / "autoload-ran"
        canary = f'<?php file_put_contents({json.dumps(str(self.canary_marker))}, "{CANARY}"); echo "{CANARY}"; exit(99);'
        for file in ["artisan", "bootstrap/app.php", "vendor/autoload.php"]:
            (self.canary_root / file).write_text(canary)

    def tearDown(self):
        self.temp.cleanup()

    def invoke(self, arguments, cwd=None):
        return subprocess.run([self.php, str(TOOL), *arguments], cwd=cwd or self.canary_root,
                              capture_output=True, text=True, timeout=5, check=False)

    def helper(self, value):
        result = subprocess.run([self.php, "-r", PURE_HARNESS, str(TOOL), str(SOURCE)], cwd=self.empty,
                                input=json.dumps(value), capture_output=True, text=True, timeout=5, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        self.assertFalse(self.canary_marker.exists())
        return json.loads(result.stdout)

    def reject(self, arguments):
        result = self.invoke(arguments)
        self.assertEqual(result.returncode, 64, (result.stdout, result.stderr))
        self.assertEqual(result.stdout, "")
        self.assertIn("Usage:", result.stderr)
        self.assertFalse(self.canary_marker.exists())

    def accept(self, arguments):
        result = self.invoke(arguments, self.empty)
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertEqual(result.stderr, "")
        body = json.loads(result.stdout)
        self.assertEqual(body["result"], "incomplete_unknown")
        self.assertEqual(body["failed_stage"], "preflight")
        self.assertEqual(body["error_code"], "application_or_curl_unavailable")
        self.assertFalse(body["application_booted"])
        self.assertFalse(body["database_writes"])
        self.assertFalse(body["financial_commands_executed"])
        self.assertFalse(self.canary_marker.exists())

    def test_help_never_loads_autoload_even_beside_app_markers(self):
        result = self.invoke(["--help"])
        self.assertEqual(result.returncode, 0)
        self.assertEqual(result.stderr, "")
        self.assertIn("--inspect", result.stdout)
        self.assertFalse(self.canary_marker.exists())

    def test_unsupported_selftest_flag_is_rejected_before_application(self):
        result = self.invoke(["--self-test"])
        self.assertEqual(result.returncode, 64, result.stderr)
        self.assertEqual(result.stdout, "")
        self.assertNotIn(SECRET, result.stdout)
        self.assertFalse(self.canary_marker.exists())

    def test_valid_cli_positive(self):
        self.accept(VALID)

    def test_valid_cli_reversed(self):
        self.accept(list(reversed(VALID)))

    def test_valid_cli_maximum_integer_identifiers(self):
        self.accept(["--inspect", "--customer=" + str(self.php_int_max), "--service=" + str(self.php_int_max),
                     "--instance=" + str(self.php_int_max), "--provider-ref=" + REF])

    def test_canonical_digest_ignores_object_key_order_and_detects_value_changes(self):
        first = self.helper({"action": "digest", "value": {"a": 17, "b": {"x": 18, "y": [1, 2]}}})["result"]
        reorder = self.helper({"action": "digest", "value": {"b": {"y": [1, 2], "x": 18}, "a": 17}})["result"]
        changed = self.helper({"action": "digest", "value": {"a": 18, "b": {"x": 18, "y": [1, 2]}}})["result"]
        self.assertEqual(first, reorder)
        self.assertNotEqual(first, changed)
        self.assertRegex(first, r"^[a-f0-9]{64}$")

    def test_real_financial_validator_accepts_complete_fixture(self):
        result = self.helper({"action": "validate", "snapshot": snapshot_fixture()})
        self.assertTrue(result["ok"], result)

    def test_real_validator_rejects_invalid_non_target_product_total(self):
        snapshot = snapshot_fixture()
        snapshot["reports"][0]["rows"][-1]["items"][0]["price"] = "999"
        result = self.helper({"action": "validate", "snapshot": snapshot})
        self.assertFalse(result["ok"])
        self.assertEqual(result["error_code"], "Supplier parent/component totals differ")

    def test_fetch_reads_exact_current_complete_snapshot_once(self):
        result = self.helper({"action": "fetch", "responses": responses_fixture()})
        self.assertTrue(result["ok"], result)
        self.assertEqual(result["result"]["current_cycle"], CYCLE)
        self.assertEqual(len(result["result"]["reports"][0]["rows"]), 21)
        self.assertEqual([call["path"] for call in result["calls"]],
                         ["current-billing-cycle", "billing-cycles/" + CYCLE,
                          "billing-cycles/" + CYCLE + "/usage-items", "billing-cycles/" + CYCLE + "/usage-items",
                          "billing-cycles/" + CYCLE, "current-billing-cycle"])
        self.assertEqual(result["calls"][2]["query"], {"page": 1, "perPage": 20})
        self.assertEqual(result["calls"][3]["query"], {"page": 2, "perPage": 20})

    def test_fetch_rejects_changed_cycle_without_retry(self):
        responses = responses_fixture()
        responses[-2] = copy.deepcopy(responses[-2])
        responses[-2]["data"]["totalItemsPrice"] = "99999"
        result = self.helper({"action": "fetch", "responses": responses})
        self.assertFalse(result["ok"])
        self.assertLessEqual(len(result["calls"]), 6)

    def test_fetch_rejects_cycle_rollover(self):
        responses = responses_fixture()
        responses[-1] = copy.deepcopy(responses[-1])
        responses[-1]["data"]["id"] = "bbbbbbbb-cccc-dddd-eeee-ffffffffffff"
        result = self.helper({"action": "fetch", "responses": responses})
        self.assertFalse(result["ok"])

    def test_fetch_rejects_partial_wrong_page_or_changing_pagination(self):
        cases = [lambda r: r[3]["meta"]["pagination"].update(current=1),
                 lambda r: r[3]["meta"]["pagination"].update(totalItems=22),
                 lambda r: r[3]["meta"]["pagination"].update(totalPages=3),
                 lambda r: r[3].update(data=[]),
                 lambda r: r[2]["meta"]["pagination"].update(totalPages=1000)]
        for mutate in cases:
            with self.subTest(mutation=mutate):
                responses = copy.deepcopy(responses_fixture())
                mutate(responses)
                result = self.helper({"action": "fetch", "responses": responses})
                self.assertFalse(result["ok"])
                self.assertLessEqual(len(result["calls"]), 6)

    def test_fetch_transport_failure_does_not_retry_or_validate_partial_data(self):
        result = self.helper({"action": "fetch", "responses": [{"_throw": True}]})
        self.assertFalse(result["ok"])
        self.assertEqual(len(result["calls"]), 1)

    def test_fetch_accepts_unordered_cycle_product_summaries_but_keeps_values(self):
        responses = copy.deepcopy(responses_fixture())
        responses[-2]["data"]["items"].reverse()
        result = self.helper({"action": "fetch", "responses": responses})
        self.assertTrue(result["ok"], result)

    def test_fetch_full_validator_rejects_bad_parent_and_cycle_totals(self):
        for mutate in [lambda s: s["reports"][0]["rows"][-1]["items"][0].update(price="999"),
                       lambda s: s["reports"][0]["cycle"].update(totalPrice="1"),
                       lambda s: s["reports"][0]["cycle"].update(currency="eur"),
                       lambda s: s["reports"][0]["rows"][-1]["items"][0].update(itemId="synthetic-component-0")]:
            with self.subTest(mutation=mutate):
                snapshot = snapshot_fixture()
                mutate(snapshot)
                result = self.helper({"action": "fetch", "responses": responses_fixture(snapshot)})
                self.assertFalse(result["ok"], "A selected target alone cannot validate the whole bill")

    def select(self, snapshot=None, accounts=None):
        account_hash = hashlib.sha256(b"synthetic-account").hexdigest()
        return self.helper({"action": "select", "snapshot": snapshot or snapshot_fixture(), "ref": REF,
                            "accounts": [account_hash] if accounts is None else accounts,
                            "now": 1793674800})

    def test_target_component_keeps_integer_irr_and_stale_usage_distinct_from_fetch(self):
        result = self.select()
        self.assertTrue(result["ok"], result)
        out = result["result"]
        self.assertEqual(out["status"], "validated_current_source_component")
        self.assertEqual(out["meter_price_basis_irr"], "3000")
        self.assertEqual(out["supplier_net_irr"], "3000")
        self.assertEqual(out["reported_amount"], "3.5")
        self.assertEqual(out["reported_amount_unit"], "unknown")
        self.assertFalse(out["amount_is_cutoff_proof"])
        self.assertEqual(out["price_irr"], "3000")
        self.assertEqual(out["discount_irr"], "0")
        self.assertEqual(out["used_gift_irr"], "0")
        self.assertEqual(out["usage_to"], "2026-11-03T02:00:00Z")
        self.assertEqual(out["fetched_at"], "2026-11-03T03:00:00Z")
        self.assertFalse(out["fetch_time_is_usage_freshness"])
        self.assertFalse(out["current_cycle_cutoff_proven"])
        self.assertNotIn(SECRET, json.dumps(out))

    def test_missing_and_duplicate_target_components_are_unknown_not_zero(self):
        for mode in ["missing", "duplicate"]:
            with self.subTest(mode=mode):
                snapshot = snapshot_fixture()
                target = snapshot["reports"][0]["rows"][0]
                if mode == "missing":
                    target["items"][0]["metric"] = "cc_cpu"
                else:
                    target["items"].append(copy.deepcopy(target["items"][0]))
                out = self.select(snapshot)["result"]
                self.assertEqual(out["status"], "unknown")
                self.assertIsNone(out["meter_price_basis_irr"])
                self.assertIsNone(out["supplier_net_irr"])
                self.assertIsNone(out["reported_amount"])
                self.assertIsNone(out["usage_to"])
                self.assertFalse(out["current_cycle_cutoff_proven"])

    def test_wrong_or_missing_account_does_not_claim_attribution(self):
        for accounts in [[], ["0" * 64], [hashlib.sha256(b"synthetic-account").hexdigest(), "0" * 64]]:
            with self.subTest(accounts=accounts):
                out = self.select(accounts=accounts)["result"]
                self.assertEqual(out["status"], "unknown")
                self.assertFalse(out["account_matches_meter"])

    def test_invalid_target_window_is_unknown(self):
        for field, value in [("usageTo", "2026-11-04T00:00:00Z"), ("usageFrom", "2026-10-31T23:59:59Z"),
                             ("usageTo", "2026-11-01T00:00:00Z"), ("usageFrom", "tomorrow")]:
            with self.subTest(field=field, value=value):
                snapshot = snapshot_fixture()
                snapshot["reports"][0]["rows"][0]["items"][0][field] = value
                out = self.select(snapshot)["result"]
                self.assertEqual(out["status"], "unknown")
                self.assertFalse(out["window_valid"])
                self.assertIsNone(out["meter_price_basis_irr"])
                self.assertIsNone(out["supplier_net_irr"])

    def test_discount_and_gift_remain_explicit_review_signals(self):
        for field in ["discount", "usedGift"]:
            with self.subTest(field=field):
                snapshot = snapshot_fixture()
                snapshot["reports"][0]["rows"][0]["items"][0][field] = "1000"
                out = self.select(snapshot)["result"]
                self.assertTrue(out["discount_or_gift_requires_review"])
                self.assertEqual(out["discount_irr" if field == "discount" else "used_gift_irr"], "1000")
                self.assertEqual(out["supplier_net_irr"], "2000")
                self.assertEqual(out["meter_price_basis_irr"], "2000" if field == "discount" else "3000")
                self.assertFalse(out["current_cycle_cutoff_proven"])

    def test_ledger_projection_hashes_private_text_without_printing_it_or_claiming_credit(self):
        sets = {"credits": [{"id": 17, "currency_code": "IRT", "amount": "-300", "balance_after": "-200",
                              "reason": "invoice_payment", "source_type": "App\\Models\\Invoice", "source_id": 18,
                              "note": "traffic " + SECRET}],
                "invoices": [{"id": 18, "currency_code": "IRT", "total": "300", "paid": "300", "status": "paid", "note": SECRET}],
                "items": [{"id": 17, "invoice_id": 18, "title": "traffic " + SECRET,
                           "description": SECRET, "unit_price": "300", "line_total": "300"}],
                "refunds": [{"id": 18, "currency_code": "IRT", "amount_irt": "100", "status": "approved",
                             "source_payment_id": 17, "credit_entry_id": 18, "private_bank": SECRET}]}
        out = self.helper({"action": "ledger", "sets": sets})["result"]
        self.assertNotIn(SECRET, json.dumps(out))
        self.assertFalse(out["history_semantically_reconciled"])
        self.assertFalse(out["raw_ledger_balance_is_spendable_credit"])
        credit = out["sets"]["credits"]["records"][0]
        self.assertEqual(credit["amount"], "-300")
        self.assertEqual(credit["currency"], "IRT")
        self.assertTrue(credit["traffic_keyword_present"])
        self.assertEqual(out["sets"]["refunds"]["records"][0]["source_payment_id"], 17)

    def test_ledger_fingerprint_changes_for_private_mutation_and_added_or_deleted_items(self):
        original = {"items": [{"id": 17, "invoice_id": 18, "title": "traffic", "description": SECRET, "line_total": "300"}]}
        prior = self.helper({"action": "ledger", "sets": original})["result"]["sets"]["items"]
        for altered in [{"items": [{**original["items"][0], "description": "changed"}]},
                        {"items": [*original["items"], {"id": 18, "invoice_id": 18, "line_total": "100"}]},
                        {"items": []}]:
            with self.subTest(altered=altered):
                after = self.helper({"action": "ledger", "sets": altered})["result"]["sets"]["items"]
                self.assertNotEqual(prior["set_sha256"], after["set_sha256"])

    def test_exact_identity_tuple_cannot_borrow_another_resource_or_owner(self):
        options = {"instance": 17, "service": 18, "customer": 17, "provider_ref": REF}
        row = {"instance_id": 17, "service_id": 18, "customer_id": 17, "provider": "arvan", "provider_ref": REF}
        self.assertTrue(self.helper({"action": "identity", "row": row, "options": options})["result"])
        for field, value in [("instance_id", 18), ("service_id", 17), ("customer_id", 18),
                             ("provider", "other"), ("provider_ref", "ir-thr-c2:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee")]:
            with self.subTest(field=field):
                self.assertFalse(self.helper({"action": "identity", "row": {**row, field: value}, "options": options})["result"])

    def target_fixture(self):
        options = {"instance": 17, "service": 18, "customer": 17, "provider_ref": REF}
        row = {"instance_id": 17, "service_id": 18, "customer_id": 17, "provider": "arvan", "provider_ref": REF,
               "instance_status": "running", "service_status": "active", "currency_code": "IRT",
               "image_key": "synthetic-linux", "encrypted_credential_present": 1}
        return row, options

    def test_fresh_read_verifies_mariadb_and_mysql_dialect_on_same_connection(self):
        row, _ = self.target_fixture()
        for version, expected, timeout_sql in [("10.11.6-MariaDB", "mariadb", "SET SESSION max_statement_time = 5"),
                                                ("8.0.40", "mysql", "SET SESSION MAX_EXECUTION_TIME = 5000")]:
            with self.subTest(version=version):
                out = self.helper({"action": "fresh_read", "row": row, "config": {"version": version}})
                self.assertTrue(out["ok"], out)
                self.assertEqual(out["result"]["flavor"], expected)
                self.assertEqual(out["begins"], 1)
                self.assertIn("SET SESSION TRANSACTION READ ONLY", out["result"]["sql"])
                self.assertIn(timeout_sql, out["result"]["sql"])

    def test_fresh_read_refuses_an_already_active_repeatable_read_snapshot(self):
        row, _ = self.target_fixture()
        out = self.helper({"action": "fresh_read", "row": row, "active_snapshot": True})
        self.assertFalse(out["ok"])
        self.assertEqual(out["error_code"], "fresh_read_requires_closed_transaction")
        self.assertEqual(out["begins"], 1, "The helper must not nest into the old snapshot")

    def test_fresh_read_refuses_readonly_zero_inactive_pdo_or_wrong_transaction_level(self):
        row, _ = self.target_fixture()
        for config in [{"readonly": 0}, {"not_in_transaction": True}, {"begin_level": 2}]:
            with self.subTest(config=config):
                out = self.helper({"action": "fresh_read", "row": row, "config": config})
                self.assertFalse(out["ok"])
                self.assertEqual(out["error_code"], "read_only_unverified")

    def test_fresh_read_refuses_connection_replaced_during_begin(self):
        row, _ = self.target_fixture()
        out = self.helper({"action": "fresh_read", "row": row, "config": {"reconnect": True}})
        self.assertFalse(out["ok"])
        self.assertEqual(out["error_code"], "read_connection_changed")

    def test_fresh_read_supports_alternative_readonly_variable(self):
        row, _ = self.target_fixture()
        out = self.helper({"action": "fresh_read", "row": row, "config": {"fallback_variable": True}})
        self.assertTrue(out["ok"], out)
        self.assertIn("SELECT @@session.transaction_read_only", out["result"]["sql"])

    def test_fresh_read_requires_repeatable_read_and_sets_it_before_snapshot(self):
        row, _ = self.target_fixture()
        out = self.helper({"action": "fresh_read", "row": row})
        self.assertTrue(out["ok"], out)
        self.assertIn("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ", out["result"]["sql"])
        self.assertIn("SELECT @@session.tx_isolation", out["result"]["sql"])
        refused = self.helper({"action": "fresh_read", "row": row, "config": {"isolation": "READ-COMMITTED"}})
        self.assertFalse(refused["ok"])
        self.assertEqual(refused["error_code"], "read_isolation_unverified")

    def test_repeatable_read_isolation_variable_fallback_and_normalization(self):
        row, _ = self.target_fixture()
        out = self.helper({"action": "fresh_read", "row": row,
                           "config": {"fallback_isolation_variable": True, "isolation": "repeatable read"}})
        self.assertTrue(out["ok"], out)
        self.assertIn("SELECT @@session.transaction_isolation", out["result"]["sql"])

    def end_read(self, mutation="none", config=None):
        row, options = self.target_fixture()
        return self.helper({"action": "end_read", "row": row, "options": options,
                            "mutation": mutation, "config": config or {}})

    def test_verified_end_rechecks_readonly_and_rolls_back_exact_snapshot(self):
        out = self.end_read()
        self.assertTrue(out["ok"], out)
        self.assertEqual(out["begins"], 1)
        self.assertEqual(out["rollbacks"], 1)
        self.assertEqual(out["result"]["level"], 0)
        self.assertFalse(out["result"]["active"])
        self.assertEqual(out["result"]["sql"].count("SELECT @@session.tx_read_only"), 2)

    def test_verified_end_supports_alternative_readonly_variable(self):
        out = self.end_read(config={"fallback_variable": True})
        self.assertTrue(out["ok"], out)
        self.assertEqual(out["rollbacks"], 1)
        self.assertEqual(out["result"]["sql"].count("SELECT @@session.transaction_read_only"), 2)

    def assert_snapshot_lost(self, mutation):
        out = self.end_read(mutation)
        self.assertFalse(out["ok"], out)
        self.assertEqual(out["error_code"], "read_snapshot_lost")
        self.assertEqual(out["rollbacks"], 0, "A changed/unknown snapshot must not be certified as a verified end")

    def test_verified_end_refuses_midread_connection_replacement(self):
        self.assert_snapshot_lost("replace")

    def test_verified_end_refuses_midread_transaction_level_zero(self):
        self.assert_snapshot_lost("level_zero")

    def test_verified_end_refuses_midread_inactive_pdo(self):
        self.assert_snapshot_lost("inactive")

    def test_verified_end_refuses_lost_readonly_mode(self):
        self.assert_snapshot_lost("readonly_zero")

    def test_verified_end_refuses_lost_repeatable_read_isolation(self):
        self.assert_snapshot_lost("isolation_rc")

    def test_verified_end_checks_pdo_again_after_isolation_query(self):
        self.assert_snapshot_lost("query_isolation_replace")

    def test_verified_end_rechecks_connection_after_readonly_query(self):
        self.assert_snapshot_lost("query_replace")

    def test_verified_end_rechecks_transaction_after_readonly_query(self):
        for mutation in ["query_level_zero", "query_inactive"]:
            with self.subTest(mutation=mutation):
                self.assert_snapshot_lost(mutation)

    def test_second_fresh_phase_sees_changed_owner_instead_of_reusing_old_snapshot(self):
        row, options = self.target_fixture()
        out = self.helper({"action": "phase_identity", "row": row, "options": options, "change": {"customer_id": 18}})
        self.assertFalse(out["ok"])
        self.assertEqual(out["error_code"], "target_identity_mismatch")
        self.assertEqual(out["begins"], 2)
        self.assertEqual(out["rollbacks"], 1, "The mismatching second phase must not receive a verified end")

    def test_second_fresh_phase_exposes_status_change_for_main_digest_comparison(self):
        row, options = self.target_fixture()
        out = self.helper({"action": "phase_identity", "row": row, "options": options, "change": {"instance_status": "off"}})
        self.assertTrue(out["ok"], out)
        self.assertEqual(out["begins"], 2)
        self.assertEqual(out["rollbacks"], 2)
        self.assertEqual(out["result"]["first"]["instance_status"], "running")
        self.assertEqual(out["result"]["second"]["instance_status"], "off")
        self.assertTrue(out["result"]["changed"])

    def test_current_target_rejects_deleted_vm_and_closed_service(self):
        row, options = self.target_fixture()
        for changed in [{"instance_status": "deleted"}, {"service_status": "cancelled"},
                        {"service_status": "terminated"}, {"service_status": "expired"}]:
            with self.subTest(changed=changed):
                out = self.helper({"action": "target", "row": {**row, **changed}, "options": options})
                self.assertFalse(out["ok"])
                self.assertEqual(out["error_code"], "target_not_current")

    def test_current_target_rejects_ambiguous_provider_reference(self):
        row, options = self.target_fixture()
        out = self.helper({"action": "target", "row": row, "options": options, "config": {"duplicates": 2}})
        self.assertFalse(out["ok"])
        self.assertEqual(out["error_code"], "ambiguous_resource")

    def test_current_status_requires_typed_statuses_and_preserves_readable_suspended_target(self):
        for row, expected in [({"instance_status": "running", "service_status": "active"}, True),
                              ({"instance_status": "off", "service_status": "suspended"}, True),
                              ({"instance_status": "deleted", "service_status": "active"}, False),
                              ({"instance_status": "running", "service_status": "cancelled"}, False),
                              ({"instance_status": "running", "service_status": "canceled"}, False),
                              ({"instance_status": "running", "service_status": "terminated"}, False),
                              ({"instance_status": "running", "service_status": "expired"}, False),
                              ({"instance_status": "running", "service_status": "deleted"}, False),
                              ({"instance_status": None, "service_status": "active"}, False),
                              ({"instance_status": "running", "service_status": None}, False),
                              ({"instance_status": 1, "service_status": "active"}, False),
                              ({"instance_status": "running", "service_status": 1}, False), ({}, False)]:
            with self.subTest(row=row):
                out = self.helper({"action": "current_status", "row": row})
                self.assertTrue(out["ok"], out)
                self.assertIs(out["result"], expected)

    def test_row_budget_reserves_escape_and_per_record_overhead(self):
        remaining = 2_097_152
        out = self.helper({"action": "reserve", "remaining": remaining,
                           "batches": [{"count": 2, "text_bytes": 7, "limit": 2},
                                       {"count": 0, "text_bytes": 0, "limit": 0}]})
        self.assertTrue(out["ok"], out)
        self.assertEqual(out["remaining"], remaining - 2 * 2048 - 7 * 6)

    def test_row_budget_refuses_negative_oversized_or_excessive_counts_before_reserving(self):
        for batch, reason in [({"count": -1, "text_bytes": 0, "limit": 2}, "history_cap_exceeded"),
                              ({"count": 3, "text_bytes": 0, "limit": 2}, "history_cap_exceeded"),
                              ({"count": 1, "text_bytes": -1, "limit": 2}, "history_byte_limit"),
                              ({"count": 1, "text_bytes": 4_194_305, "limit": 2}, "history_byte_limit"),
                              ({"count": 1, "text_bytes": 400_000, "limit": 2}, "history_byte_limit"),
                              ({"count": 1025, "text_bytes": 0, "limit": 2000}, "history_byte_limit")]:
            with self.subTest(batch=batch):
                out = self.helper({"action": "reserve", "remaining": 2_097_152, "batches": [batch]})
                self.assertFalse(out["ok"])
                self.assertEqual(out["error_code"], reason)
                self.assertEqual(out["remaining"], 2_097_152)

    def test_row_budget_is_cumulative_across_ledger_sets(self):
        out = self.helper({"action": "reserve", "remaining": 2_097_152,
                           "batches": [{"count": 100, "text_bytes": 0, "limit": 2000},
                                       {"count": 1000, "text_bytes": 0, "limit": 2000}]})
        self.assertFalse(out["ok"])
        self.assertEqual(out["error_code"], "history_byte_limit")
        self.assertEqual(out["remaining"], 2_097_152 - 100 * 2048)

    def test_actual_rows_helper_rejects_oversized_aggregate_before_get(self):
        for count, text_bytes, reason in [(1, 400_000, "history_byte_limit"), (2001, 0, "history_cap_exceeded")]:
            with self.subTest(count=count, text_bytes=text_bytes):
                out = self.helper({"action": "rows", "count": count, "text_bytes": text_bytes,
                                   "limit": 2000, "rows": [{"id": 17, "note": SECRET}]})
                self.assertFalse(out["ok"])
                self.assertEqual(out["error_code"], reason)
                self.assertEqual(out["trace"]["probe_calls"], 1)
                self.assertEqual(out["trace"]["get_calls"], 0)

    def test_actual_rows_aggregate_probe_does_not_mutate_original_select(self):
        out = self.helper({"action": "rows", "count": 1, "text_bytes": 6, "limit": 2,
                           "rows": [{"id": 17, "note": "fixture"}]})
        self.assertTrue(out["ok"], out)
        self.assertEqual(out["result"], [{"id": 17, "note": "fixture"}])
        self.assertEqual(out["trace"]["events"], ["probe_reorder", "probe", "get"])
        self.assertIn("OCTET_LENGTH(`note`)", out["trace"]["aggregate_sql"])

    def test_actual_rows_rejects_unknown_table_or_malformed_size_before_get(self):
        for override, reason in [({"table": "unapproved_table"}, "history_table_unknown"),
                                 ({"count": "1e3"}, "history_size_unknown"),
                                 ({"text_bytes": "-1"}, "history_size_unknown")]:
            with self.subTest(override=override):
                out = self.helper({"action": "rows", "count": 1, "text_bytes": 0, "limit": 2,
                                   "rows": [], **override})
                self.assertFalse(out["ok"])
                self.assertEqual(out["error_code"], reason)
                self.assertEqual(out["trace"]["get_calls"], 0)

    def test_audit_attribution_requires_exact_owner_service_and_resource(self):
        selected = {"resource": REF, "service_id": 18, "customer_id": 17,
                    "from": "2026-11-01T00:00:00Z", "to": "2026-11-03T02:00:00Z",
                    "cost_irr": 3000, "sell_irt": 315, "tax_irt": 32, "private_note": SECRET}
        lines = [selected, {"next": selected, "subtotal_irt": 30, "tax_irt": 3,
                            "from": "2026-11-02T02:00:00Z", "to": "2026-11-03T02:00:00Z"},
                 {**selected, "customer_id": 18}, {**selected, "service_id": 17},
                 {**selected, "resource": "ir-thr-c2:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"}]
        sets = {"audits": [{"id": 17, "action": "traffic_meter_settlement", "subject_id": 18,
                            "lines_json": json.dumps(lines), "details_sha256": "a" * 64}]}
        out = self.helper({"action": "ledger", "sets": sets, "target_ref": REF,
                           "target_service": 18, "target_customer": 17})["result"]
        matched = out["sets"]["audits"]["records"][0]["selected_resource_lines"]
        self.assertEqual(len(matched), 2)
        self.assertEqual(matched[0]["cost_irr"], "3000")
        self.assertEqual(matched[1]["line_subtotal_irt"], "30")
        self.assertEqual(matched[1]["from"], "2026-11-01T00:00:00Z")
        self.assertEqual(matched[1]["line_from"], "2026-11-02T02:00:00Z")
        self.assertEqual(matched[1]["line_to"], "2026-11-03T02:00:00Z")
        self.assertTrue(matched[1]["from_to_are_cumulative_next_window"])
        self.assertFalse(matched[0]["from_to_are_cumulative_next_window"])
        self.assertNotIn(SECRET, json.dumps(out))
        no_selection = self.helper({"action": "ledger", "sets": sets})["result"]
        self.assertEqual(no_selection["sets"]["audits"]["records"][0]["selected_resource_lines"], [])


INVALID = {
    "empty": [], "inspect_only": ["--inspect"],
    "help_mixed": [*VALID, "--help"], "selftest_mixed": [*VALID, "--self-test"],
    "duplicate_inspect": [*VALID, "--inspect"], "unknown": [*VALID, "--apply"],
    "positional": [*VALID, "extra"], "newline_inspect": ["--inspect\n", *VALID[1:]],
}
for key in ["customer", "service", "instance", "provider-ref"]:
    INVALID["missing_" + key] = [arg for arg in VALID if not arg.startswith("--" + key + "=")]
    value = next(arg for arg in VALID if arg.startswith("--" + key + "="))
    INVALID["duplicate_" + key] = [*VALID, value]
for key in ["customer", "service", "instance"]:
    for name, value in {"zero": "0", "negative": "-17", "plus": "+17", "leading_zero": "017",
                        "newline": "17\n", "space": "17 ", "decimal": "17.0", "scientific": "1e3", "unicode": "۱۷"}.items():
        INVALID[key + "_" + name] = ["--" + key + "=" + value if arg.startswith("--" + key + "=") else arg for arg in VALID]
for name, value in {"url": "https://evil.example:443", "traversal": "../region:" + REF.split(":")[1],
                    "missing_region": ":" + REF.split(":")[1], "malformed_uuid": "ir-thr-c2:not-a-uuid",
                    "newline": REF + "\n"}.items():
    INVALID["reference_" + name] = ["--provider-ref=" + value if arg.startswith("--provider-ref=") else arg for arg in VALID]


def invalid_case(arguments):
    def test(self):
        self.reject(arguments)
    return test


for name, arguments in INVALID.items():
    setattr(FinanceInspectionTest, "test_cli_invalid_" + name.replace("-", "_"), invalid_case(arguments))


def overflow_case(key):
    def test(self):
        self.reject(["--" + key + "=" + str(self.php_int_max + 1) if arg.startswith("--" + key + "=") else arg for arg in VALID])
    return test


for key in ["customer", "service", "instance"]:
    setattr(FinanceInspectionTest, "test_cli_invalid_" + key + "_overflow", overflow_case(key))

if __name__ == "__main__":
    unittest.main()
