<?php
declare(strict_types=1);

// Pure fixtures only: inclusion never loads Laravel, contacts Arvan, writes files or schedules work.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/owner-test-20261028.php';

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    ++$checks;
    if (! $ok) { throw new RuntimeException('test_failed:'.$label); }
};
$reject = static function (callable $fn, string $guard) use ($assert): void {
    try { $fn(); } catch (RuntimeException $e) {
        $assert($e->getMessage() === 'guard:'.$guard, 'expected_'.$guard);
        return;
    }
    $assert(false, 'missing_'.$guard);
};
$g = OwnerWindowsDiagnostic28::class;
$now = 1793170000;
$id = '11111111-1111-4111-8111-111111111111';
$other = '22222222-2222-4222-8222-222222222222';

// The safety contract is earlier than the owner's one-hour ceiling, even after a delayed response.
$intent = $g::createIntent(['prepared_at' => $now - 10], $now);
$assert($intent['create_intent_at'] === $now, 'intent_recorded');
$assert($g::cleanupDeadline($intent) === $now + 2400, 'forty_minute_deadline');
$assert($g::RUN_SECONDS < 3600, 'within_authorized_runtime');
$assert(! $g::due([], $now + 999999), 'no_unattempted_cleanup');
$assert(! $g::due($intent, $now + 2399), 'not_due_before_deadline');
$assert($g::due($intent, $now + 2400), 'due_at_deadline');
$assert($g::due($intent, $now + 3600), 'remains_due');
$reconciled = $intent + ['provider_created_at' => $now + 300, 'ref' => $g::REGION.':'.$id];
$assert($g::cleanupDeadline($reconciled) === $now + 2400, 'late_reconciliation_keeps_deadline');
$early = $intent + ['early_cleanup_requested_at' => $now + 20];
$assert($g::due($early, $now + 20), 'early_cleanup_immediate');
$reject(static fn () => $g::createIntent($intent, $now + 100), 'create_already_attempted_reconcile_only');
$reject(static fn () => $g::createIntent(['create_intent_at' => null], $now), 'create_already_attempted_reconcile_only');
$reject(static fn () => $g::createIntent(['cleanup_at' => $now + 100], $now), 'create_already_attempted_reconcile_only');
$reject(static fn () => $g::createIntent([], 0), 'intent_time');
$reject(static fn () => $g::createIntent([], PHP_INT_MAX), 'intent_time');
foreach ([48 * 3600, 3600, 2401, 2399] as $seconds) {
    $bad = $intent; $bad['cleanup_at'] = $now + $seconds;
    $reject(static fn () => $g::cleanupDeadline($bad), 'immutable_cleanup_deadline');
    $reject(static fn () => $g::due($bad, $now + 999999), 'immutable_cleanup_deadline');
}
$bad = $intent; unset($bad['cleanup_at']);
$reject(static fn () => $g::cleanupDeadline($bad), 'immutable_cleanup_deadline');
$bad = $intent; $bad['create_intent_at'] = (string) $now;
$reject(static fn () => $g::cleanupDeadline($bad), 'immutable_cleanup_deadline');

$m = ['name' => $g::NAME, 'region' => $g::REGION, 'owner_id' => $g::OWNER, 'owner_code' => $g::OWNER_CODE,
    'plan_id' => 1, 'plan_ref' => 'fixture-eco', 'image_id' => $id, 'image_key' => '2025',
    'shape' => ['vcpu' => 1, 'ram_mb' => 3072, 'disk_gb' => 30, 'cpu_kind' => 'shared'],
    'quote' => ['observed_at' => $now - 60, 'expires_at' => $now + 1200, 'all_in_48h_irt' => 100000,
        'provider_hourly_rial' => 18000, 'currency' => 'IRT', 'hours' => 48,
        'tax_confirmed' => true, 'disk_included' => true, 'ipv4_included' => true,
        'license_included_or_free' => true, 'minimum_bill_bounded' => true],
    'volume_schema' => 'unverified'];
$assert($g::manifest($m, $now) === $m, 'valid_synthetic_manifest');
foreach (['tax_confirmed', 'disk_included', 'ipv4_included', 'license_included_or_free', 'minimum_bill_bounded'] as $flag) {
    $bad = $m; $bad['quote'][$flag] = false;
    $reject(static fn () => $g::manifest($bad, $now), 'quote_components');
}
$bad = $m; $bad['quote']['all_in_48h_irt'] = 120001;
$reject(static fn () => $g::manifest($bad, $now), 'quote_bound');
$bad = $m; $bad['quote']['all_in_48h_irt'] = 100;
$reject(static fn () => $g::manifest($bad, $now), 'quote_bound');
$reject(static fn () => $g::manifest($m, $now + 1201), 'quote_expired');
$assert($g::manifest($m, $now + 1201, false) === $m, 'expired_quote_does_not_block_cleanup');
$bad = $m; $bad['shape']['disk_gb'] = 200;
$reject(static fn () => $g::manifest($bad, $now), 'manifest_shape');
$bad = $m; $bad['name'] = 'some-customer-vm';
$reject(static fn () => $g::manifest($bad, $now), 'manifest_identity');
$bad = $m; $bad['volume_schema'] = 'instance_id';
$reject(static fn () => $g::manifest($bad, $now), 'volume_schema_not_observed');

$response = static fn (array $rows): array => ['ok' => true, 'status' => 200, 'json_valid' => true,
    'data_present' => true, 'data_is_list' => true, 'data' => $rows];
$assert($g::rows($response([])) === [], 'valid_empty_inventory');
$bad = $response([]); $bad['data_is_list'] = false;
$reject(static fn () => $g::rows($bad), 'inventory_unreadable');
$bad = $response([['id' => $id], ['id' => $id]]);
$reject(static fn () => $g::rows($bad), 'inventory_identity');
$reject(static fn () => $g::exactName([['id' => $id, 'name' => $g::NAME], ['id' => $other, 'name' => $g::NAME]]), 'duplicate_test_name');

$detail = ['id' => $id, 'name' => $g::NAME, 'created_at' => gmdate('c', $now + 300)];
$assert($g::serverIdentity($detail, $reconciled) === $now + 300, 'exact_server_identity');
$bad = $detail; $bad['name'] = 'customer-server';
$reject(static fn () => $g::serverIdentity($bad, $reconciled), 'provider_identity');
$bad = $detail; $bad['id'] = $other;
$reject(static fn () => $g::serverIdentity($bad, $reconciled), 'provider_reference_changed');
$bad = $detail; $bad['created_at'] = gmdate('c', $now + 601);
$reject(static fn () => $g::serverIdentity($bad, $reconciled), 'provider_creation_time_mismatch');
$bad = $detail; $bad['created_at'] = gmdate('c', $now + 200);
$reject(static fn () => $g::serverIdentity($bad, $reconciled), 'provider_creation_time_changed');

$sizes = $response([['id' => 'fixture-eco', 'cpu_count' => 1, 'memory' => 3, 'disk' => 30, 'price_per_hour' => 18000]]);
$images = $response([['name' => 'windows', 'images' => [['id' => $id, 'name' => '2025', 'disk' => 30, 'ram' => 3072]]]]);
$g::providerCatalog($sizes, $images, $m); $assert(true, 'exact_live_catalog_fixture');
$bad = $sizes; $bad['data'][0]['price_per_hour'] = 19000;
$reject(static fn () => $g::providerCatalog($bad, $images, $m), 'provider_flavor_or_price_changed');
$bad = $images; $bad['data'][0]['images'][0]['disk'] = 50;
$reject(static fn () => $g::providerCatalog($sizes, $bad, $m), 'provider_image_minimum_exceeded');

$port = ['id' => $other, 'instance_id' => $id, 'ips' => ['192.0.2.10']];
$assert($g::relatedPorts([$port], $id) === [$port], 'exact_dedicated_port');
$bad = $port; $bad['instance_id'] = $other;
$reject(static fn () => $g::relatedPorts([$bad], $id, [$other]), 'port_reassigned');
$volume = ['id' => $other, 'instance_id' => $id];
$assert($g::relatedVolumes([$volume], $id, 'instance_id') === [$volume], 'exact_dedicated_volume');
$reject(static fn () => $g::relatedVolumes([$volume], $id, 'unverified'), 'volume_attachment_schema_unknown');
$bad = ['id' => $other, 'attachments' => [['server_id' => $id], ['server_id' => $other]]];
$reject(static fn () => $g::relatedVolumes([$bad], $id, 'attachments.server_id'), 'volume_shared_or_reassigned');
$g::requireLockDatabaseDriver('mariadb'); $assert(true, 'mariadb_supported');
$reject(static fn () => $g::requireLockDatabaseDriver('sqlite'), 'unattached_lock_database');

echo json_encode(['ok' => true, 'pure_checks' => $checks, 'network_or_laravel_boot' => false], JSON_THROW_ON_ERROR), PHP_EOL;
