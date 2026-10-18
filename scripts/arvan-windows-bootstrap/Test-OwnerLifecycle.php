<?php
declare(strict_types=1);

// Pure synthetic tests. No Laravel boot, host inspection, storage, network or VM calls.
require __DIR__.'/owner-test-20261018.php';
$count = 0;
function check(bool $ok): void {
    global $count; ++$count;
    if (! $ok) { throw new RuntimeException('Synthetic assertion failed: '.$count); }
}
function rejected(callable $work, string $guard): void {
    try { $work(); throw new LogicException('Expected rejection'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'guard:'.$guard); }
}
$g = OwnerWindowsDiagnostic::class;
$g::requireLockDatabaseDriver('mysql'); check(true);
$g::requireLockDatabaseDriver('mariadb'); check(true);
foreach (['pgsql', 'sqlite', '', 'MariaDB', 'mariadb-unverified'] as $driver) {
    rejected(fn () => $g::requireLockDatabaseDriver($driver), 'unattached_lock_database');
}
$now = 1792320000;
$id = '11111111-1111-4111-a111-111111111111';
$other = '22222222-2222-4222-a222-222222222222';
$m = [
    'name' => $g::NAME, 'region' => $g::REGION, 'owner_id' => 1, 'owner_code' => $g::OWNER_CODE,
    'plan_id' => 435, 'plan_ref' => 'eco-3-1-0', 'image_id' => $id, 'image_key' => '2025',
    'shape' => ['vcpu' => 1, 'ram_mb' => 3072, 'disk_gb' => 30, 'cpu_kind' => 'shared'],
    'quote' => ['observed_at' => $now - 10, 'expires_at' => $now + 1200, 'all_in_48h_irt' => 98670,
        'provider_hourly_rial' => 15770,
        'currency' => 'IRT', 'hours' => 48, 'tax_confirmed' => true, 'disk_included' => true,
        'ipv4_included' => true, 'license_included_or_free' => true, 'minimum_bill_bounded' => true],
    'volume_schema' => 'unverified',
];
check($g::manifest($m, $now) === $m);
$bad = $m; $bad['owner_id'] = 2;
rejected(fn () => $g::manifest($bad, $now), 'manifest_identity');
$bad = $m; $bad['quote']['all_in_48h_irt'] = 120001;
rejected(fn () => $g::manifest($bad, $now), 'quote_bound');
$bad = $m; $bad['quote']['all_in_48h_irt'] = '98670';
rejected(fn () => $g::manifest($bad, $now), 'quote_bound');
$bad = $m; $bad['quote']['tax_confirmed'] = false;
rejected(fn () => $g::manifest($bad, $now), 'quote_components');
rejected(fn () => $g::manifest($m, $now + 1800), 'quote_expired');
check($g::manifest($m, $now + 200000, false) === $m); // Expired quote never prevents cleanup.
$bad = $m; $bad['quote']['observed_at'] = $now + 10;
rejected(fn () => $g::manifest($bad, $now), 'quote_expired');
$bad = $m; $bad['shape']['vcpu'] = 8;
rejected(fn () => $g::manifest($bad, $now), 'manifest_shape');
$bad = $m; $bad['volume_schema'] = 'attachments.server_id';
rejected(fn () => $g::manifest($bad, $now), 'volume_schema_not_observed');
$bad['volume_schema_observed'] = true;
check($g::manifest($bad, $now) === $bad);
$res = ['ok' => true, 'status' => 200, 'json_valid' => true, 'data_present' => true, 'data_is_list' => true, 'data' => []];
$sizes = $res; $sizes['data'] = [['id' => 'eco-3-1-0', 'cpu_count' => 1, 'memory' => 3, 'disk' => 30, 'price_per_hour' => 15770]];
$images = $res; $images['data'] = [['name' => 'Windows', 'images' => [['id' => $id, 'name' => '2025', 'disk' => 0, 'ram' => 2048]]]];
$g::providerCatalog($sizes, $images, $m); check(true);
$bad = $images; $bad['data'] = [];
rejected(fn () => $g::providerCatalog($sizes, $bad, $m), 'provider_image_missing_or_duplicate');
$bad = $images; $bad['data'][0]['name'] = 'Linux';
rejected(fn () => $g::providerCatalog($sizes, $bad, $m), 'provider_image_not_exact_windows');
$bad = $images; $bad['data'][0]['images'][0]['ram'] = 8192;
rejected(fn () => $g::providerCatalog($sizes, $bad, $m), 'provider_image_minimum_exceeded');
$bad = $images; unset($bad['data'][0]['images'][0]['disk']);
rejected(fn () => $g::providerCatalog($sizes, $bad, $m), 'provider_image_minimum_unknown');
$bad = $sizes; $bad['data'][0]['price_per_hour'] = 20000;
rejected(fn () => $g::providerCatalog($bad, $images, $m), 'provider_flavor_or_price_changed');
check($g::rows($res) === []);
foreach (['ok', 'json_valid', 'data_present', 'data_is_list'] as $flag) {
    $bad = $res; $bad[$flag] = false;
    rejected(fn () => $g::rows($bad), 'inventory_unreadable');
}
$bad = $res; $bad['status'] = 404;
rejected(fn () => $g::rows($bad), 'inventory_unreadable');
$bad = $res; $bad['data'] = [['id' => $id], ['id' => $id]];
rejected(fn () => $g::rows($bad), 'inventory_identity');
check($g::exactName([['id' => $other, 'name' => 'unrelated-customer']]) === null);
$row = ['id' => $id, 'name' => $g::NAME, 'created' => gmdate('c', $now + 30)];
check($g::exactName([$row, ['id' => $other, 'name' => 'unrelated-customer']]) === $row);
rejected(fn () => $g::exactName([$row, $row]), 'duplicate_test_name');
$state = ['create_intent_at' => $now];
check($g::serverIdentity($row, $state) === $now + 30);
$bad = $row; $bad['created'] = gmdate('c', $now - 600);
rejected(fn () => $g::serverIdentity($bad, $state), 'provider_creation_time_mismatch');
$bad = $row; $bad['created'] = gmdate('c', $now + 601);
rejected(fn () => $g::serverIdentity($bad, $state), 'provider_creation_time_mismatch');
$badState = $state + ['ref' => $g::REGION.':'.$other];
rejected(fn () => $g::serverIdentity($row, $badState), 'provider_reference_changed');
$bad = $row; $bad['name'] = 'sn-owner1-win-20261017';
rejected(fn () => $g::serverIdentity($bad, $state), 'provider_identity');
$port = ['id' => 'test-port', 'instance_id' => $id, 'ips' => ['192.0.2.11']];
$unrelated = ['id' => 'other-port', 'instance_id' => $other, 'ips' => ['192.0.2.12']];
check($g::relatedPorts([$port, $unrelated], $id) === [$port]);
check($g::relatedPorts([$unrelated], $id, ['test-port'], ['192.0.2.11']) === []);
$bad = $port; $bad['instance_id'] = $other;
rejected(fn () => $g::relatedPorts([$bad], $id, ['test-port']), 'port_reassigned');
$bad = $port; unset($bad['ips']);
rejected(fn () => $g::relatedPorts([$bad], $id), 'port_schema');
check($g::relatedVolumes([], $id, 'unverified') === []);
rejected(fn () => $g::relatedVolumes([['id' => 'volume']], $id, 'unverified'), 'volume_attachment_schema_unknown');
$vol = ['id' => 'volume', 'attachments' => [['server_id' => $id]]];
$unrelatedVol = ['id' => 'other-volume', 'attachments' => [['server_id' => $other]]];
check($g::relatedVolumes([$vol, $unrelatedVol], $id, 'attachments.server_id') === [$vol]);
check($g::relatedVolumes([$unrelatedVol], $id, 'attachments.server_id', ['volume']) === []);
$bad = $vol; $bad['attachments'][] = ['server_id' => $other];
rejected(fn () => $g::relatedVolumes([$bad], $id, 'attachments.server_id'), 'volume_shared_or_reassigned');
$bad = $vol; $bad['attachments'] = [['unknown_key' => 'dummy-secret-do-not-print']];
rejected(fn () => $g::relatedVolumes([$bad], $id, 'attachments.server_id'), 'volume_attachment_schema');
check(! $g::due(['cleanup_at' => $now + 1], $now));
check($g::due(['cleanup_at' => $now], $now));
check($g::due(['cleanup_at' => $now + 1000, 'early_cleanup_requested_at' => $now], $now));
$safe = $g::publicState(['receipt' => ['root_password' => 'dummy-secret-do-not-print'],
    'ref' => $g::REGION.':'.$id, 'create_intent_at' => $now]);
$json = json_encode($safe, JSON_THROW_ON_ERROR);
check(! str_contains($json, 'dummy-secret') && ! str_contains($json, $id));
check($safe['arvan_delivery'] === 'unproven' && $safe['reference_preserved'] === true);
check($safe['cleanup_complete'] === false && $safe['password_returned'] === false);
check($g::receiptEvidence([]) === ['receipt_outcome' => 'not_attempted', 'password_evidence' => 'unknown_without_successful_response']);
check($g::receiptEvidence(['create_intent_at' => $now])['receipt_outcome'] === 'response_missing');
$timeout = ['create_intent_at' => $now, 'receipt' => ['ok' => false, 'transient' => true,
    'ref' => null, 'root_password' => null, 'raw' => ['status' => 0, 'detail' => 'dummy-secret-do-not-print']]];
check($g::receiptEvidence($timeout) === ['receipt_outcome' => 'transport_unknown', 'password_evidence' => 'unknown_without_successful_response']);
$reconciledTimeout = $timeout + ['ref' => $g::REGION.':'.$id, 'provider_created_at' => $now + 39,
    'service_id' => 516, 'instance_id' => 327, 'cleanup_complete' => false];
check($g::receiptEvidence($reconciledTimeout) === $g::receiptEvidence($timeout));
check($g::publicState($reconciledTimeout)['receipt_outcome'] === 'transport_unknown');
check($g::publicState($reconciledTimeout)['password_evidence'] === 'unknown_without_successful_response');
$accepted = ['create_intent_at' => $now, 'receipt' => ['ok' => true, 'ref' => $g::REGION.':'.$id,
    'root_password' => null, 'raw' => ['region' => $g::REGION]]];
check($g::receiptEvidence($accepted) === ['receipt_outcome' => 'accepted_response', 'password_evidence' => 'absent_from_validated_response']);
$accepted['receipt']['root_password'] = 'dummy-secret-do-not-print';
check($g::receiptEvidence($accepted)['password_evidence'] === 'returned_unverified');
$accepted['receipt']['raw']['status'] = 201;
check($g::receiptEvidence($accepted)['receipt_outcome'] === 'accepted_response');
$accepted['receipt']['raw']['status'] = 0; // Contradictory success flags cannot override transport ambiguity.
check($g::receiptEvidence($accepted)['receipt_outcome'] === 'transport_unknown');
$accepted['receipt']['raw']['status'] = 201;
foreach (['', "bad\nvalue", ['dummy-secret-do-not-print'], str_repeat('x', 4097)] as $invalid) {
    $accepted['receipt']['root_password'] = $invalid;
    check($g::receiptEvidence($accepted)['password_evidence'] === 'invalid_in_response');
}
$accepted['receipt']['ref'] = 'wrong-region:'.$id;
check($g::receiptEvidence($accepted)['receipt_outcome'] === 'unresolved_response');
$rejected = ['create_intent_at' => $now, 'receipt' => ['ok' => false, 'transient' => false, 'raw' => ['status' => 422]]];
check($g::receiptEvidence($rejected) === ['receipt_outcome' => 'rejected_response', 'password_evidence' => 'unknown_without_successful_response']);
$rejected['receipt']['transient'] = true; $rejected['receipt']['raw']['status'] = 429;
check($g::receiptEvidence($rejected)['receipt_outcome'] === 'unresolved_response');
$rejected['receipt']['raw']['status'] = 500;
check($g::receiptEvidence($rejected)['receipt_outcome'] === 'unresolved_response');
foreach ([null, 'dummy-secret-do-not-print', ['ok' => true, 'raw' => 'dummy-secret-do-not-print'], ['raw' => ['status' => '0']]] as $malformed) {
    check($g::receiptEvidence(['create_intent_at' => $now, 'receipt' => $malformed])['receipt_outcome'] === 'unresolved_response');
}
$json = json_encode($g::publicState($reconciledTimeout), JSON_THROW_ON_ERROR);
check(! str_contains($json, 'dummy-secret') && ! str_contains($json, $id) && ! str_contains($json, 'raw'));
check($g::publicState($reconciledTimeout)['arvan_delivery'] === 'unproven');
echo 'PASS '.$count." synthetic assertions; no host/API/resource actions.\n";
