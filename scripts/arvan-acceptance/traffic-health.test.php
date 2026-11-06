<?php
declare(strict_types=1);

// Pure report tests: no Laravel bootstrap, database, provider, notification or scheduler.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$path = $argv[1] ?? (__DIR__.'/../../website/app/Services/Finance/ArvanTrafficHealth.php');
require $path;

use App\Services\Finance\ArvanTrafficHealth as H;

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    ++$checks;
    if (!$ok) { throw new RuntimeException('test_failed:'.$label); }
};
$has = static fn (array $r, string $code): bool => in_array($code, $r['issue_codes'], true);
$now = 1793192400;
$base = [
    'enabled' => true, 'sequence' => 34, 'margin_bp' => 500, 'tax_bp' => 1000,
    'last_success' => gmdate('c', $now - 1800),
    'last_result' => ['mode' => 'apply', 'total_irt' => 232,
        'invoices' => [1, 2, 3, 4], 'held' => [['reason' => 'fixture'], ['reason' => 'fixture']], 'unallocated_count' => 1],
];
$r = H::evaluate($base, $now);
$assert($r['enabled'] === true && $r['sequence'] === 34, 'proven_state_scalars');
$assert($r['last_result_total_irt'] === 232 && $r['invoice_count'] === 4, 'known_receipt_totals');
$assert($r['held_count'] === 2 && $r['unallocated_count'] === 1, 'pending_cost_counts');
$assert($r['level'] === 'warn' && $has($r, 'held_costs_need_review') && $has($r, 'unallocated_costs_need_review'), 'pending_costs_warn');
$assert(!$has($r, 'meter_receipt_stale'), 'recent_receipt_not_stale');
$assert($r['success_age_seconds'] === 1800, 'receipt_age');
$assert($r['financial_control_complete'] === false, 'receipt_is_not_complete_control');
$assert(array_values($r['verification']) === ['unverified', 'unverified', 'unverified'], 'coverage_not_invented');
$row = H::healthRow($r);
$assert($row['key'] === 'arvan_traffic_meter' && $row['ok'] === false && $row['level'] === 'warn', 'native_health_shape');
$assert(str_contains($row['detail'], '232') && str_contains($row['detail'], '4'), 'safe_numeric_receipt_description');
$tehran = $base; $tehran['last_success'] = '2026-10-28T12:31:26+00:00';
$tehranRow = H::healthRow(H::evaluate($tehran, strtotime('2026-10-28T13:01:21+00:00')));
$assert(str_contains($tehranRow['detail'], '2026-10-28 16:01:26') && str_contains($tehranRow['detail'], 'به وقت تهران'), 'ui_time_is_explicit_tehran');

$zero = $base;
$zero['last_result'] = ['mode' => 'apply', 'total_irt' => 0, 'invoices' => [], 'held' => [], 'unallocated_count' => 0];
$r = H::evaluate($zero, $now);
$assert($r['level'] === 'ok' && H::healthRow($r)['ok'] === true, 'scoped_recent_zero_pending_receipt');
$assert($r['last_result_total_irt'] === 0 && $r['held_count'] === 0, 'zero_is_valid_evidence');
$assert($r['financial_control_complete'] === false, 'scoped_ok_not_full_guarantee');

foreach ([null, false, 'secret-state', [], ['unexpected' => 'secret-state']] as $bad) {
    $r = H::evaluate($bad, $now);
    $assert($r['level'] === 'fail', 'missing_or_malformed_state_fails');
    $assert(!str_contains(json_encode($r, JSON_THROW_ON_ERROR), 'secret-state'), 'bad_state_not_exposed');
}
$assert(H::evaluate(null, $now)['state_status'] === 'missing', 'missing_distinguished');
$assert(H::evaluate(null, $now, 'unreadable')['state_status'] === 'unreadable', 'unreadable_distinguished');

$bad = $zero; $bad['enabled'] = false;
$assert($has(H::evaluate($bad, $now), 'meter_disabled'), 'disabled_reported');
foreach (['1', 1, null] as $value) {
    $bad = $zero; $bad['enabled'] = $value;
    $assert($has(H::evaluate($bad, $now), 'enabled_unknown'), 'enabled_not_coerced');
}
foreach (['34', -1, null] as $value) {
    $bad = $zero; $bad['sequence'] = $value;
    $assert($has(H::evaluate($bad, $now), 'sequence_unknown'), 'sequence_strict_integer');
}

$limit = H::EXPECTED_INTERVAL_SECONDS + H::GRACE_SECONDS;
$bad = $zero; $bad['last_success'] = gmdate('c', $now - $limit);
$assert(!$has(H::evaluate($bad, $now), 'meter_receipt_stale'), 'freshness_boundary');
$bad['last_success'] = gmdate('c', $now - $limit - 1);
$assert($has(H::evaluate($bad, $now), 'meter_receipt_stale'), 'stale_after_boundary');
$bad['last_success'] = gmdate('c', $now + H::FUTURE_SKEW_SECONDS + 1);
$r = H::evaluate($bad, $now);
$assert($r['level'] === 'fail' && $has($r, 'success_timestamp_in_future') && $r['success_age_seconds'] === null, 'future_timestamp_not_recent');
$bad['last_success'] = gmdate('c', $now + 1);
$assert($has(H::evaluate($bad, $now), 'success_clock_ahead'), 'small_clock_skew_visible');
foreach (['2026-02-31T00:00:00+00:00', '2026-10-28T12:00:00', 'not-a-date', 'https://secret.invalid/token', 1793192400] as $value) {
    $bad = $zero; $bad['last_success'] = $value;
    $r = H::evaluate($bad, $now);
    $assert($has($r, 'success_timestamp_unknown') && $r['last_success_utc'] === null, 'invalid_time_unknown');
}
$bad = $zero;
$bad['last_success'] = (new DateTimeImmutable(gmdate('c', $now - 1800)))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('c');
$assert(H::evaluate($bad, $now)['success_age_seconds'] === 1800, 'explicit_timezone_normalized');

$bad = $zero; unset($bad['last_result']);
$assert($has(H::evaluate($bad, $now), 'last_result_unknown'), 'missing_last_result_not_success');
$bad = $zero; $bad['last_result']['mode'] = 'preview';
$assert($has(H::evaluate($bad, $now), 'last_result_not_apply'), 'preview_not_apply');
$bad['last_result']['mode'] = 'secret-mode';
$r = H::evaluate($bad, $now);
$assert($r['last_result_mode'] === null && !str_contains(json_encode($r, JSON_THROW_ON_ERROR), 'secret-mode'), 'unrecognized_mode_hidden');
foreach (['232', -1, null] as $value) {
    $bad = $zero; $bad['last_result']['total_irt'] = $value;
    $assert(H::evaluate($bad, $now)['last_result_total_irt'] === null, 'amount_not_coerced');
}

$bad = $zero; unset($bad['last_result']['held']);
$r = H::evaluate($bad, $now);
$assert($r['held_count'] === null && $has($r, 'held_count_unknown'), 'missing_held_not_zero');
$bad = $zero; $bad['last_result']['held_count'] = 1;
$r = H::evaluate($bad, $now);
$assert($r['held_count'] === null && $has($r, 'held_count_unknown'), 'contradictory_held_evidence_unknown');
$bad['last_result']['held_count'] = 0;
$assert(H::evaluate($bad, $now)['held_count'] === 0, 'consistent_held_evidence');
$bad = $zero; unset($bad['last_result']['held']); $bad['last_result']['held_count'] = 2;
$assert(H::evaluate($bad, $now)['held_count'] === 2, 'explicit_count_supported');
$bad = $zero; $bad['last_result']['held'] = 0;
$assert(H::evaluate($bad, $now)['held_count'] === null, 'held_scalar_not_silently_array');
foreach (['invoices' => 'invoice_count', 'held' => 'held_count', 'unallocated' => 'unallocated_count'] as $arrayKey => $countKey) {
    $bad = $zero; $bad['last_result'][$arrayKey] = ['malformed-key' => ['private' => 'not-a-list']];
    $r = H::evaluate($bad, $now);
    $assert($r[$countKey] === null && $has($r, $countKey.'_unknown'), 'associative_item_collection_unknown');
}
$bad = $zero; $bad['last_result']['unallocated_count'] = '0';
$assert(H::evaluate($bad, $now)['unallocated_count'] === null, 'unallocated_string_not_zero');
$bad = $zero; $bad['last_result']['unallocated'] = [['secret' => 'ignored']];
$assert(H::evaluate($bad, $now)['unallocated_count'] === null, 'unallocated_conflict_visible');

$secret = 'Apikey DONT-PRINT-THIS-TEST-CREDENTIAL';
$bad = $base;
$bad['last_error'] = $secret;
$bad['customer_password'] = $secret;
$bad['provider_url'] = 'https://secret.invalid/?token='.$secret;
$bad['excluded_customers'] = [545];
$bad['last_result']['held'] = [['secret' => $secret, 'customer_id' => 987654321]];
$bad['last_result']['invoices'] = [['token' => $secret]];
$r = H::evaluate($bad, $now);
$row = H::healthRow($r);
$encoded = json_encode([$r, $row], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$assert($r['recorded_error_present'] === true && $has($r, 'recorded_error_needs_review'), 'error_presence_review_only');
foreach ([$secret, 'secret.invalid', '987654321', 'customer_password', 'excluded_customers'] as $needle) {
    $assert(!str_contains($encoded, $needle), 'raw_private_values_not_exposed');
}
$assert($r['held_count'] === 1 && $r['invoice_count'] === 1, 'private_items_count_only');

echo json_encode(['ok' => true, 'pure_checks' => $checks, 'laravel_or_network_used' => false], JSON_THROW_ON_ERROR), PHP_EOL;
