<?php
declare(strict_types=1);

// CLI-only, database read-only snapshot. Never prints credentials or raw meter state.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = '/home/servernetcloud/servernet_app';
$db = null;
$stage = 'autoload';
$driver = null;
try {
    require $root.'/vendor/autoload.php';
    $stage = 'application';
    $app = require $root.'/bootstrap/app.php';
    $stage = 'kernel_bootstrap';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $stage = 'db_connection';
    $db = Illuminate\Support\Facades\DB::connection();
    $stage = 'db_driver';
    $driver = $db->getDriverName();
    if (!in_array($driver, ['mysql', 'mariadb'], true)) { throw new RuntimeException('unsupported_driver'); }
    $stage = 'db_connect';
    $db->getPdo();
    $stage = 'read_only_transaction';
    $db->statement('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $out = ['utc' => gmdate('c'), 'mode' => 'read_only_snapshot'];
    $stage = 'flags';
    $keys = ['arvan_hourly_sales_enabled', 'arvan_console_worker_enabled', 'arvan_owner_console_pilot'];
    $flags = $db->table('settings')->whereIn('key', $keys)->pluck('value', 'key');
    foreach ($keys as $key) {
        $v = $flags[$key] ?? null;
        $out['flags'][$key] = in_array((string) $v, ['0', '1'], true) ? (int) $v : null;
    }
    $off = (string) $db->table('settings')->where('key', 'cloud_disabled_providers')->value('value');
    $out['arvan_provider_disabled'] = in_array('arvan', array_map('trim', explode(',', $off)), true);
    $stage = 'plans';
    $out['plans'] = $db->table('cloud_plans')->where('provider', 'arvan')
        ->selectRaw('location_code, COUNT(*) AS total_rows, SUM(CASE WHEN is_active=1 AND in_stock=1 AND admin_disabled=0 AND price_irt>0 AND cost_eur_cents>0 THEN 1 ELSE 0 END) AS locally_eligible_rows, SUM(CASE WHEN admin_disabled=1 THEN 1 ELSE 0 END) AS manually_disabled_rows')
        ->groupBy('location_code')->orderBy('location_code')->get()->map(fn ($r) => [
            'location' => $r->location_code, 'total' => (int) $r->total_rows,
            'locally_eligible' => (int) $r->locally_eligible_rows,
            'manually_disabled' => (int) $r->manually_disabled_rows,
        ])->all();
    $stage = 'instances';
    $out['instances'] = $db->table('cloud_instances')->where('provider', 'arvan')
        ->where('status', '!=', 'deleted')->orderBy('id')
        ->get(['id', 'service_id', 'location_code', 'image_key', 'status'])->all();
    $stage = 'meter';
    $out['meter'] = ['state_readable' => false];
    try {
        $raw = $db->table('settings')->where('key', 'arvan_traffic_meter_v1')->value('value');
        $meter = json_decode(Illuminate\Support\Facades\Crypt::decryptString((string) $raw), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($meter)) { throw new RuntimeException('invalid_state'); }
        $success = $meter['last_success'] ?? null;
        $out['meter'] = [
            'state_readable' => true,
            'enabled' => is_bool($meter['enabled'] ?? null) ? $meter['enabled'] : null,
            'sequence' => is_int($meter['sequence'] ?? null) && $meter['sequence'] >= 0 ? $meter['sequence'] : null,
            'last_success' => is_string($success) && strlen($success) <= 40
                && preg_match('/^\\d{4}-\\d{2}-\\d{2}[T ]\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})?$/D', $success) === 1
                ? $success : null,
        ];
        unset($raw, $meter, $success);
    } catch (Throwable) {}
    $db->rollBack();
    $out['tools'] = ['flock' => is_executable('/usr/bin/flock'), 'crontab' => is_executable('/usr/bin/crontab'), 'at' => is_executable('/usr/bin/at')];
    $out['finance_meter_code_present'] = is_file($root.'/app/Services/Finance/ArvanTrafficMeter.php');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    if ($db && $db->transactionLevel() > 0) { try { $db->rollBack(); } catch (Throwable) {} }
    echo json_encode([
        'error' => 'read_only_probe_failed', 'stage' => $stage,
        'exception_class' => get_class($e),
        'source_file' => basename($e->getFile()), 'source_line' => $e->getLine(),
        'db_driver' => in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'], true) ? $driver : null,
    ], JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}
