<?php
declare(strict_types=1);

/**
 * Read-only Windows test preflight for 2026-10-28.
 * No VM, grant, cron, service, manifest or state is created.
 * The flavor price is NOT an all-in Windows billing quote.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
$root = '/home/servernetcloud/servernet_app';
$region = 'ir-tbz-sh1';
$testName = 'sn-owner1-windiag-20261028';
$db = null;
$stage = 'autoload';
try {
    require $root.'/vendor/autoload.php';
    $stage = 'application';
    $app = require $root.'/bootstrap/app.php';
    $stage = 'kernel_bootstrap';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection();
    if (!in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) { throw new RuntimeException('driver'); }
    $stage = 'database_reads';
    $db->statement('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $raw = $db->table('settings')->where('key', 'arvan_api_token')->value('value');
    $token = Illuminate\Support\Facades\Crypt::decryptString((string) $raw);
    unset($raw);
    if ($token === '' || preg_match('/[\\r\\n]/', $token)) { throw new RuntimeException('token'); }
    $ownerVerified = $db->table('customers')->where('id', 1)->where('code', 'SN-457136')->where('status', 'active')->exists();
    $plans = $db->table('cloud_plans')->where('provider', 'arvan')->where('provider_location', $region)
        ->where('is_active', 1)->where('vcpu', 1)->where('ram_mb', 3072)->where('disk_gb', 30)
        ->where('cpu_kind', 'shared')->get(['id', 'provider_ref'])->all();
    $imageLocal = $db->table('cloud_images')->where('provider', 'arvan')->where('key', '2025')
        ->where('family', 'windows')->where('is_active', 1)->exists();
    $attemptExists = $db->table('arvan_create_attempts')->where('region', $region)->where('hostname', $testName)->exists();
    $db->rollBack();
    $out = [
        'utc' => gmdate('c'), 'mode' => 'windows_preflight_get_only', 'region' => $region,
        'budget_cap_irt' => 120000, 'owner_verified' => $ownerVerified,
        'existing_create_attempt' => $attemptExists, 'local_windows_2025_active' => $imageLocal,
        'desired_shape' => ['vcpu' => 1, 'ram_mb' => 3072, 'disk_gb' => 30, 'cpu_kind' => 'shared'],
        'no_paid_action_performed' => true,
    ];
    $stage = 'provider_reads';
    $deadline = microtime(true) + 150;
    $get = static function (string $resource) use ($token, $region, $deadline): array {
        if (!in_array($resource, ['sizes', 'images', 'servers', 'ports', 'volumes', 'volumes/os-volumes'], true)) {
            throw new RuntimeException('resource');
        }
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) { return ['http' => 0, 'valid_list' => false, 'data' => [], 'pagination_metadata_present' => false]; }
        try {
            $r = Illuminate\Support\Facades\Http::withHeaders(['Authorization' => $token])
                ->acceptJson()->withoutRedirecting()->connectTimeout(min(5, $remaining))
                ->timeout(min(20, $remaining))->get('https://napi.arvancloud.ir/ecc/v1/regions/'.rawurlencode($region).'/'.$resource,
                    $resource === 'images' ? ['type' => 'distributions'] : []);
            $body = json_decode($r->body(), false, 512, JSON_THROW_ON_ERROR);
            $data = is_object($body) && property_exists($body, 'data') ? $body->data : null;
            $valid = $r->status() === 200 && is_array($data) && array_is_list($data);
            return ['http' => $r->status(), 'valid_list' => $valid, 'data' => $valid ? $data : [],
                'pagination_metadata_present' => is_object($body) && (isset($body->meta) || isset($body->links) || isset($body->pagination))];
        } catch (Throwable) {
            return ['http' => isset($r) ? $r->status() : 0, 'valid_list' => false, 'data' => [], 'pagination_metadata_present' => false];
        }
    };
    $responses = [];
    foreach (['sizes', 'images', 'servers', 'ports', 'volumes', 'volumes/os-volumes'] as $resource) {
        $responses[$resource] = $get($resource);
        $out['api'][$resource] = ['http' => $responses[$resource]['http'],
            'list_valid' => $responses[$resource]['valid_list'],
            'returned_rows' => $responses[$resource]['valid_list'] ? count($responses[$resource]['data']) : null,
            'pagination_metadata_present' => $responses[$resource]['pagination_metadata_present']];
    }
    $int = static fn (mixed $v): ?int => (is_int($v) || is_string($v)) && preg_match('/^\\d{1,12}$/D', (string) $v) === 1 ? (int) $v : null;
    $seen = [];
    $candidates = [];
    if ($responses['sizes']['valid_list']) {
        foreach ($responses['sizes']['data'] as $size) {
            if (!is_object($size) || !is_string($size->id ?? null) || isset($seen[$size->id])) {
                throw new RuntimeException('size_identity');
            }
            $seen[$size->id] = true;
            $cpu = $int($size->cpu_count ?? null);
            $ramGb = $int($size->memory ?? null);
            $disk = $int($size->disk ?? null);
            $hour = $int($size->price_per_hour ?? null);
            $share = is_string($size->cpu_share ?? null) ? strtolower($size->cpu_share) : '';
            if ($cpu !== 1 || $ramGb !== 3 || $disk !== 30 || str_contains($share, 'dedicat') || $hour === null || $hour <= 0 || $hour > 100000000) { continue; }
            $matches = array_values(array_filter($plans, static fn ($p) => $p->provider_ref === $size->id));
            $candidates[] = [
                'local_plan_match_count' => count($matches),
                'local_plan_id' => count($matches) === 1 ? (int) $matches[0]->id : null,
                'provider_hourly_rial_per_existing_driver' => $hour,
                'provider_monthly_rial_reported' => $int($size->price_per_month ?? null),
                'flavor_only_48h_irt_with_assumed_10pct_vat' => (int) ceil($hour * 48 * 11 / 100),
                // Public Arvan pricing observed 2026-10-28: first Iran IPv4 234,000 IRT/month.
                // This additional estimate assumes hourly proration over 720h; it is NOT proof of that contract.
                'flavor_plus_first_ipv4_48h_irt_with_assumed_proration_and_10pct_vat' => (int) ceil(($hour * 48 / 10 + 234000 * 48 / 720) * 1.1),
                'raw_size_shape_matches' => true,
            ];
        }
    }
    $out['matching_flavors'] = $candidates;
    $out['matching_flavor_count'] = count($candidates);
    $windows = [];
    if ($responses['images']['valid_list']) {
        foreach ($responses['images']['data'] as $group) {
            if (!is_object($group)) { throw new RuntimeException('image_group'); }
            $children = isset($group->images) ? $group->images : [$group];
            if (!is_array($children) || !array_is_list($children)) { throw new RuntimeException('image_list'); }
            if (!is_string($group->name ?? null) || strtolower(trim($group->name)) !== 'windows') { continue; }
            foreach ($children as $image) {
                if (!is_object($image) || !is_string($image->id ?? null)) { throw new RuntimeException('image_identity'); }
                if (($image->name ?? null) !== '2025') { continue; }
                $disk = $int($image->disk ?? null);
                $ram = $int($image->ram ?? null);
                $windows[] = [
                    'key' => '2025', 'disk_floor_reported_gb' => $disk, 'ram_floor_reported_mb' => $ram,
                    'reported_minimums_match_shape' => $disk !== null && $ram !== null && $disk <= 30 && $ram <= 3072,
                    'zero_disk_floor_is_not_install_size_proof' => $disk === 0,
                    'license_terms_verified' => false,
                ];
            }
        }
    }
    $out['windows_2025_exact_matches'] = $windows;
    $out['test_name_matches_in_region'] = null;
    if ($responses['servers']['valid_list']) {
        $namesValid = true; $matches = 0;
        foreach ($responses['servers']['data'] as $server) {
            if (!is_object($server) || !is_string($server->name ?? null) || !is_string($server->id ?? null)) { $namesValid = false; break; }
            if ($server->name === $testName) { $matches++; }
        }
        $out['test_name_matches_in_region'] = $namesValid ? $matches : null;
    }
    $portsValid = $responses['ports']['valid_list'];
    foreach ($responses['ports']['data'] as $port) {
        if (!is_object($port) || !property_exists($port, 'instance_id')
            || (!is_string($port->instance_id) && $port->instance_id !== null)
            || !is_array($port->ips ?? null) || !array_is_list($port->ips)) { $portsValid = false; break; }
        foreach ($port->ips as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) { $portsValid = false; break 2; }
        }
    }
    $out['port_attachment_schema_valid'] = $portsValid;
    foreach (['volumes', 'volumes/os-volumes'] as $resource) {
        $rows = $responses[$resource]['data'];
        $instanceSchema = $responses[$resource]['valid_list'] && $rows !== [];
        $attachmentSchema = $instanceSchema;
        foreach ($rows as $volume) {
            if (!is_object($volume) || !property_exists($volume, 'instance_id')
                || (!is_string($volume->instance_id) && $volume->instance_id !== null)) { $instanceSchema = false; }
            if (!is_object($volume) || !is_array($volume->attachments ?? null) || !array_is_list($volume->attachments)) { $attachmentSchema = false; continue; }
            foreach ($volume->attachments as $attachment) {
                if (!is_object($attachment) || !is_string($attachment->server_id ?? null)) { $attachmentSchema = false; break; }
            }
        }
        $out['volume_schema'][$resource] = ['empty_valid_list' => $responses[$resource]['valid_list'] && $rows === [],
            'instance_id_observed' => $instanceSchema, 'attachments_server_id_observed' => $attachmentSchema];
    }
    $out['quote_component_verification'] = [
        'currency_from_account_quote' => false, 'tax_confirmed_for_account_quote' => false,
        'disk_included_in_flavor_price' => null, 'ipv4_included_in_flavor_price' => null,
        'windows_license_included_or_free' => null, 'minimum_bill_bounded' => false,
        'all_in_48h_quote_verified' => false,
    ];
    $out['ready_for_paid_create'] = false;
    $out['notices'] = [
        'Estimates use the existing driver interpretation of price_per_hour as RIAL.',
        '10 percent VAT and 720h IPv4 proration are assumptions here, not account-specific contract proof.',
        'Flavor/list shape and zero reported image disk floor do not prove Windows boot readiness or license terms.',
        'Inventory lists are for preflight; no absence or bill-stop conclusion is made.',
    ];
    unset($token, $responses, $plans, $get, $size, $image, $server, $port, $volume);
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    if ($db && $db->transactionLevel() > 0) { try { $db->rollBack(); } catch (Throwable) {} }
    echo json_encode(['error' => 'windows_preflight_failed', 'stage' => $stage, 'exception_class' => get_class($e),
        'source_file' => basename($e->getFile()), 'source_line' => $e->getLine()], JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}
