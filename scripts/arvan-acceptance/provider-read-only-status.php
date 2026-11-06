<?php
declare(strict_types=1);

// CLI-only; GET requests and database reads only. No credentials, URLs or raw API bodies in output.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = '/home/servernetcloud/servernet_app';
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
    $targets = $db->table('cloud_instances as ci')->leftJoin('services as s', 's.id', '=', 'ci.service_id')
        ->where('ci.provider', 'arvan')->where('ci.status', '!=', 'deleted')->orderBy('ci.id')
        ->limit(51)->get(['ci.id', 'ci.service_id', 'ci.provider_ref', 'ci.hostname', 'ci.location_code',
            'ci.image_key', 'ci.status', 's.status as service_status', 's.customer_id'])->all();
    if (count($targets) > 50) { throw new RuntimeException('target_limit'); }
    $raw = $db->table('settings')->where('key', 'arvan_api_token')->value('value');
    $token = Illuminate\Support\Facades\Crypt::decryptString((string) $raw);
    unset($raw);
    if ($token === '' || preg_match('/[\\r\\n]/', $token)) { throw new RuntimeException('token'); }
    $pilot = $db->table('settings')->where('key', 'arvan_console_owner_pilot_enabled')->value('value');
    $db->rollBack();
    $out = ['utc' => gmdate('c'), 'mode' => 'provider_get_only', 'db_driver' => $db->getDriverName(),
        'owner_console_pilot' => in_array((string) $pilot, ['0', '1'], true) ? (int) $pilot : null];
    $stage = 'provider_reads';
    $deadline = microtime(true) + 150;
    $get = static function (string $region, ?string $id = null) use ($token, $deadline): array {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) { return ['http' => 0, 'body' => null]; }
        try {
            $path = '/ecc/v1/regions/'.rawurlencode($region).'/servers';
            if ($id !== null) { $path .= '/'.rawurlencode($id); }
            $r = Illuminate\Support\Facades\Http::withHeaders(['Authorization' => $token])
                ->acceptJson()->withoutRedirecting()->connectTimeout(min(5, $remaining))
                ->timeout(min(15, $remaining))->get('https://napi.arvancloud.ir'.$path);
            $body = json_decode($r->body(), false, 512, JSON_THROW_ON_ERROR);
            return ['http' => $r->status(), 'body' => $body];
        } catch (Throwable) { return ['http' => isset($r) ? $r->status() : 0, 'body' => null]; }
    };
    $regions = [];
    foreach ($targets as $t) {
        $parts = explode(':', (string) $t->provider_ref, 2);
        if (count($parts) === 2 && preg_match('/^ir-[a-z0-9]+-[a-z0-9]+$/D', $parts[0])
            && preg_match('/^[a-f0-9-]{36}$/Di', $parts[1])) {
            $regions[$parts[0]] = true;
        }
    }
    $lists = [];
    foreach (array_keys($regions) as $region) {
        $r = $get($region);
        $body = $r['body'];
        $data = is_object($body) && property_exists($body, 'data') ? $body->data : null;
        $valid = $r['http'] === 200 && is_array($data) && array_is_list($data);
        $ids = [];
        if ($valid) {
            foreach ($data as $row) {
                if (!is_object($row) || !is_string($row->id ?? null)
                    || preg_match('/^[a-f0-9-]{36}$/Di', $row->id) !== 1 || isset($ids[$row->id])) {
                    $valid = false; break;
                }
                $ids[$row->id] = true;
            }
        }
        $lists[$region] = ['valid' => $valid, 'ids' => $valid ? $ids : []];
        $out['region_lists'][] = ['region' => $region, 'http' => $r['http'], 'list_valid' => $valid,
            'returned_rows' => $valid ? count($data) : null,
            'pagination_metadata_present' => is_object($body)
                && (isset($body->meta) || isset($body->links) || isset($body->pagination)),
            'absence_is_not_deletion_proof' => true];
    }
    $knownStates = ['ACTIVE', 'RUNNING', 'SHUTOFF', 'STOPPED', 'PAUSED', 'SUSPENDED', 'BUILD',
        'BUILDING', 'REBUILD', 'CREATING', 'INSTALLING', 'SOFT_DELETED', 'DELETED', 'DELETING', 'ERROR', 'UNKNOWN'];
    $serviceStates = ['active', 'pending', 'suspended', 'cancelled', 'terminated', 'expired'];
    foreach ($targets as $t) {
        $entry = ['instance_id' => (int) $t->id, 'service_id' => (int) $t->service_id,
            'location' => $t->location_code, 'local_image' => $t->image_key,
            'local_status' => $t->status, 'owner_is_test_account' => (int) $t->customer_id === 1,
            'service_status' => in_array($t->service_status, $serviceStates, true) ? $t->service_status : 'other'];
        $parts = explode(':', (string) $t->provider_ref, 2);
        if (count($parts) !== 2 || !isset($regions[$parts[0]])
            || preg_match('/^[a-f0-9-]{36}$/Di', $parts[1]) !== 1) {
            $out['instances'][] = $entry + ['reference_valid' => false];
            continue;
        }
        [$region, $id] = $parts;
        $r = $get($region, $id);
        $s = is_object($r['body']) && property_exists($r['body'], 'data') ? $r['body']->data : null;
        $match = $r['http'] === 200 && is_object($s) && ($s->id ?? null) === $id;
        $state = $match && is_string($s->status ?? null) ? strtoupper($s->status) : 'UNKNOWN';
        $image = $match && is_object($s->image ?? null) && is_string($s->image->name ?? null) ? strtolower($s->image->name) : '';
        $family = str_contains($image, 'windows') ? 'windows'
            : ((str_contains($image, 'ubuntu') || str_contains($image, 'debian') || str_contains($image, 'centos') || str_contains($image, 'rocky') || str_contains($image, 'alma')) ? 'linux' : 'unknown');
        $out['instances'][] = $entry + ['reference_valid' => true, 'detail_http' => $r['http'],
            'detail_identity_match' => $match,
            'provider_status' => in_array($state, $knownStates, true) ? strtolower($state) : 'unknown',
            'provider_name_matches_local' => $match && is_string($s->name ?? null) && $t->hostname !== null ? $s->name === $t->hostname : null,
            'provider_image_family' => $family,
            'in_region_response' => $lists[$region]['valid'] ? isset($lists[$region]['ids'][$id]) : null];
    }
    unset($token, $targets, $get, $r, $s, $body, $data, $lists);
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    if ($db && $db->transactionLevel() > 0) { try { $db->rollBack(); } catch (Throwable) {} }
    echo json_encode(['error' => 'provider_probe_failed', 'stage' => $stage, 'exception_class' => get_class($e),
        'source_file' => basename($e->getFile()), 'source_line' => $e->getLine()], JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}
