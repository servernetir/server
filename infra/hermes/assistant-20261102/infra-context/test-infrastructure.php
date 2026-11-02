<?php

/** Pure behavior fixtures: no app bootstrap, credentials, SQL, network or provider writes. */
declare(strict_types=1);

use App\Services\Hermes\HermesInfrastructureContext as Context;

require_once dirname(__DIR__, 4).'/website/app/Services/Hermes/HermesInfrastructureContext.php';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    $checks++; if (! $condition) { throw new RuntimeException('FAILED: '.$label); }
};
$now = 1793628000;
$registry = ['hetzner' => 'Hetzner Cloud', 'aeza' => 'Aeza', 'arvan' => 'ArvanCloud (ابرآروان)',
    'ovh' => 'OVHcloud', 'proxmox' => 'Proxmox (Tehran)', 'salad' => 'SaladCloud (GPU)',
    'hetzner-robot' => 'Hetzner Robot (Dedicated)', 'lightnode' => 'LightNode', 'vpstc' => 'VPS.TC'];
$records = [];
foreach ($registry as $id => $label) {
    $records[$id] = ['sales' => ['ok' => true, 'reason' => 'available', 'offers' => 8], 'disabled' => false];
}
$records['arvan'] = ['sales' => ['ok' => false, 'reason' => 'admin_disabled', 'offers' => 0],
    'disabled' => true, 'hourly_enabled' => true, 'configured' => true,
    'catalog' => ['plans' => 120, 'active' => 110, 'in_stock' => 100, 'admin_disabled' => 120,
        'synced_at' => $now - 600, 'regions' => ['ir-tabriz', 'ir-tehran']],
    'quarantine_records' => [['admin_disabled' => true,
        'note' => 'خودکار بسته شد: زیرساخت سفارش را نپذیرفت (You have reached RAM size limit)',
        'updated_at' => $now - 200]],
    'inventory' => ['tracked' => 4, 'by_status' => ['running' => 3, 'off' => 1], 'synced_at' => $now - 500],
    'scan' => ['at' => $now - 9000, 'complete' => true],
    'capabilities' => ['resize' => true, 'snapshot' => false, 'metrics' => false],
    'quota' => ['limit_mib' => 65536, 'used_mib' => 0, 'headroom_mib' => 65536],
    'secret' => 'synthetic-secret', 'raw_provider_body' => ['token' => 'synthetic-token']];
$before = $records;
$question = 'چرا زیرساخت آروان باز فروش پنل هاست بسته هست چیشده؟';
$data = Context::project($records, $question, $now, $registry);
$arvan = $data['providers'][0];
$check($arvan['id'] === 'arvan', 'exact requested supplier prioritized');
$check(count($data['providers']) === 9, 'all nine registered selling suppliers visible');
$check($arvan['sales']['state'] === 'closed' && $arvan['sales']['reason_code'] === 'admin_disabled', 'actual local sales flag wins');
$check($arvan['sales']['quarantine_reason_codes'] === ['account_ram_limit'], 'exact RAM refusal explains current automatic block');
$check($arvan['quota']['status'] === 'recorded_ram_quota_refusal', 'quota refusal has explicit recorded provenance');
$check($arvan['quota']['limit_mib'] === null && $arvan['quota']['used_mib'] === null && $arvan['quota']['headroom_mib'] === null, 'unintegrated quota API cannot acquire fabricated values');
$check($arvan['quota']['provider_observed_at'] === null, 'plan update time is not provider quota observation');
$check($arvan['sales']['quarantine_record_updated_at'] === $now - 200, 'local block record date retained separately');
$check($arvan['sales']['quarantine_reason_scope'] === 'sample_of_current_blocked_rows'
    && $arvan['sales']['quarantine_records_sampled'] === 1 && $arvan['sales']['quarantine_sample_complete'] === false,
    'sampled reason not falsely assigned to every blocked catalogue row');
$check($arvan['catalog']['in_stock'] === 100 && $arvan['sales']['state'] === 'closed', 'catalogue stock never proves quota headroom or opens sales');
$check($arvan['inventory']['tracked'] === 4 && $arvan['inventory']['source'] === 'local_cloud_instances', 'local tracked records explicitly scoped');
$check($arvan['inventory']['scan_stale'] && $arvan['inventory']['scan_at'] === $now - 9000, 'old saved provider scan stays stale');
$check($arvan['configured'] === true && $arvan['connected'] === null && ! $data['provider_live_checked'], 'credentials presence not connection evidence');
$check($arvan['capabilities'] === ['resize' => true, 'snapshot' => false, 'metrics' => false], 'only supported capability booleans projected');
$check($data['audience'] === 'owner_private' && $data['product_scope'] === 'cloud_vps_gpu_sales', 'scope distinguishes cloud selling from hosting health');
$check($data['read_paths']['live_quota'] === 'not_integrated', 'missing read adapter explicit');
$check(! str_contains(json_encode($data), 'synthetic-secret') && ! str_contains(json_encode($data), 'synthetic-token'), 'no arbitrary source secrets projected');
$check($before === $records, 'projection leaves source records unchanged');

$all = Context::project($records, 'چه زیرساخت هایی میفروشیم؟', $now, $registry);
$check(count($all['providers']) === 9 && $all['omitted_providers'] === 0, 'registry answer includes every current supplier');
$check(! isset($all['providers'][0]['catalog']), 'general supplier list stays compact');
$check(array_values(array_filter($all['providers'], fn ($row) => $row['id'] === 'arvan'))[0]['sales']['state'] === 'closed', 'closed supplier remains visible in full registry');
$check(array_values(array_filter($all['providers'], fn ($row) => $row['id'] === 'hetzner'))[0]['sales']['state'] === 'open', 'peer supplier sale unaffected');

$unknown = Context::project([], 'Arvan فروش بسته؟', $now, $registry);
$check(! $unknown['available'] && $unknown['providers'][0]['sales']['state'] === 'unknown', 'failed source unknown rather than false healthy');
$check($unknown['providers'][0]['catalog']['plans'] === null && $unknown['providers'][0]['inventory']['tracked'] === null, 'missing catalogue and count are not zero');
$check($unknown['providers'][0]['inventory']['scan_complete'] === null, 'missing scan does not become complete');
$flagOnly = Context::project(['arvan' => ['disabled' => true]], 'زیرساخت ها', $now, $registry);
$check(array_values(array_filter($flagOnly['providers'], fn ($row) => $row['id'] === 'arvan'))[0]['sales']['state'] === 'closed', 'known disabled flag survives unavailable sales snapshot');

$reopened = $records;
$reopened['arvan']['quarantine_records'][0]['admin_disabled'] = false;
$reopened['arvan']['sales'] = ['ok' => true, 'reason' => 'available', 'offers' => 14];
$reopened['arvan']['disabled'] = false;
$open = Context::project($reopened, 'arvan', $now, $registry)['providers'][0];
$check($open['sales']['quarantine_reason_codes'] === [] && $open['quota']['status'] === 'unknown', 'historical inactive quota note cannot explain current state');
$check($open['sales']['state'] === 'open', 'reopened supplier not closed by historical note');
$manual = $records;
$manual['arvan']['quarantine_records'][0]['note'] = 'Customer said You have reached RAM size limit';
$check(Context::project($manual, 'آروان', $now, $registry)['providers'][0]['sales']['quarantine_reason_codes'] === [], 'arbitrary manual note is not automatic quarantine evidence');
$future = $records;
$future['arvan']['scan']['at'] = $now + 10;
$future['arvan']['catalog']['synced_at'] = $now + 10;
$futureData = Context::project($future, 'آروان', $now, $registry)['providers'][0];
$check($futureData['inventory']['scan_complete'] === null && $futureData['inventory']['scan_stale'], 'future scan observation invalid');
$check($futureData['catalog']['synced_at'] === null, 'future sync timestamp not current evidence');
$partial = $records;
$partial['arvan']['scan'] = ['at' => $now - 30, 'complete' => false];
$check(Context::project($partial, 'آروان', $now, $registry)['providers'][0]['inventory']['scan_complete'] === false, 'incomplete fresh scan remains incomplete');
$unsupported = $records;
$unsupported['arvan']['sales']['reason'] = 'raw error token=synthetic-token';
$unsupported['arvan']['capabilities']['token'] = true;
$filtered = Context::project($unsupported, 'آروان', $now, $registry)['providers'][0];
$check($filtered['sales']['reason_code'] === 'admin_disabled' && ! isset($filtered['capabilities']['token'])
    && ! str_contains(json_encode($filtered), 'synthetic-token'),
    'raw reason rejected while known disabled-flag reason remains and unknown capability excluded');
$unsupported['arvan']['disabled'] = false;
$withoutFlag = Context::project($unsupported, 'آروان', $now, $registry)['providers'][0];
$check($withoutFlag['sales']['reason_code'] === null && ! str_contains(json_encode($withoutFlag), 'synthetic-token'),
    'raw upstream reason has no safe replacement without independent disabled flag');
$current = $records;
$current['arvan']['sales'] = ['ok' => true, 'reason' => 'available', 'offers' => 268];
$current['arvan']['disabled'] = false;
$current['arvan']['catalog']['plans'] = 804;
$current['arvan']['catalog']['admin_disabled'] = 473;
$current['arvan']['quarantine_records'] = array_fill(0, 40, ['admin_disabled' => true,
    'note' => 'manual review', 'updated_at' => $now - 100]);
$current['arvan']['inventory']['by_status'] = ['active' => 2, 'stopped' => 1];
$currentArvan = Context::project($current, 'آروان', $now, $registry)['providers'][0];
$check($currentArvan['sales']['state'] === 'open' && $currentArvan['sales']['offers'] === 268, 'some blocked plans do not close otherwise open supplier');
$check($currentArvan['sales']['quarantine_records_sampled'] === 32 && $currentArvan['sales']['quarantine_sample_complete'] === false,
    '32-note limit and incomplete473-row coverage explicit');
$check($currentArvan['sales']['quarantine_reason_codes'] === [] && $currentArvan['quota']['status'] === 'unknown',
    'no current quota evidence means unknown despite historical quota report');
$check($currentArvan['inventory']['by_status'] === ['active' => 2, 'stopped' => 1], 'legacy active and stopped statuses are retained');

for ($i = 0; $i < 40; $i++) {
    $largeRegistry = $registry;
    for ($j = 0; $j < $i; $j++) { $largeRegistry['provider-'.$j] = str_repeat('برچسب', 10); }
    $large = $records;
    $large['arvan']['catalog']['regions'] = array_map(fn ($j) => 'ir-'.str_repeat('x', 40).$j, range(0, 100));
    $large['arvan']['quarantine_records'] = array_fill(0, 100, $records['arvan']['quarantine_records'][0]);
    $bounded = Context::project($large, $question, $now, $largeRegistry);
    $check(mb_strlen(json_encode($bounded, JSON_UNESCAPED_UNICODE)) <= Context::MAX_CHARS, 'full projection bound with registry expansion '.$i);
    $check($bounded['providers'][0]['sales']['quarantine_reason_codes'] === ['account_ram_limit'], 'relevant reason survives bounded projection '.$i);
    $check(count($bounded['providers']) <= Context::MAX_PROVIDERS, 'provider count bound '.$i);
}
$check(Context::project($records, 'hetzner-robot', $now, $registry)['providers'][0]['id'] === 'hetzner-robot', 'dedicated Robot not confused with cloud account');
echo json_encode(['ok' => true, 'checks' => $checks, 'network_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
