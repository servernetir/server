<?php
declare(strict_types=1);

/* Explicit CLI-only diagnostic. No application providers, Artisan, meter, writes or mutations. */
const SN_FLEET_LIMIT = 200;
const SN_GET_LIMIT = 50;
const SN_GET_BUDGET = 60;
const SN_JSON_LIMIT = 8_388_608;

/** Strict parsing happens before application configuration or filesystem access. */
function snArgs(array $arguments): array
{
    if ($arguments === ['--help']) return ['action' => 'help'];
    if ($arguments === ['--self-test']) return ['action' => 'self_test'];
    $inspect = false; $customer = null;
    foreach ($arguments as $argument) {
        if ($argument === '--inspect' && !$inspect) { $inspect = true; continue; }
        if (is_string($argument) && preg_match('/\A--customer=([1-9][0-9]*)\z/D', $argument, $match) && $customer === null) {
            $limit = (string) PHP_INT_MAX;
            if (strlen($match[1]) > strlen($limit) || (strlen($match[1]) === strlen($limit) && strcmp($match[1], $limit) > 0)) throw new InvalidArgumentException('invalid_cli_arguments');
            $customer = (int) $match[1]; continue;
        }
        throw new InvalidArgumentException('invalid_cli_arguments');
    }
    if (!$inspect || $customer === null) throw new InvalidArgumentException('invalid_cli_arguments');
    return ['action' => 'inspect', 'customer_id' => $customer];
}

function snRef(mixed $value): ?array
{
    if (!is_string($value) || !preg_match('/\A([A-Za-z0-9][A-Za-z0-9_-]{0,62}):([a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12})\z/D', $value, $m)) return null;
    return [$m[1], strtolower($m[2])];
}

function snDate(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value)) return null;
    try {
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) return null;
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable) { return null; }
}

function snAge(?string $date, int $now): ?int
{
    return $date === null ? null : $now - (new DateTimeImmutable($date))->getTimestamp();
}

function snCount(mixed $value): ?int { return is_array($value) ? count($value) : null; }

function snEnum(mixed $value, array $allowed): string
{
    return is_string($value) && in_array($value, $allowed, true) ? $value : 'unknown';
}

function snState(?array $state, int $now, int $customer): array
{
    $last = is_array($state['last_result'] ?? null) ? $state['last_result'] : [];
    $held = is_array($last['held'] ?? null) ? $last['held'] : null;
    $allowed = ['unknown_or_ambiguous_owner', 'owner_exclusion', 'manual_traffic_charge_requires_reconciliation',
        'changed_owner_window_counter_discount_or_gift', 'historic_resource_requires_baseline', 'late_repricing_requires_review'];
    $reasons = [];
    if ($held !== null) foreach ($held as $entry) {
        $reason = snEnum(is_array($entry) ? ($entry['reason'] ?? null) : null, $allowed);
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
    }
    $success = snDate($state['last_success'] ?? null);
    $hash = $last['source_hash'] ?? null;
    return ['initialized' => $state !== null, 'version' => is_int($state['version'] ?? null) ? $state['version'] : null,
        'enabled' => is_bool($state['enabled'] ?? null) ? $state['enabled'] : null,
        'last_success' => $success, 'last_success_age_seconds' => snAge($success, $now),
        'last_success_is_usage_freshness_proof' => false,
        'sequence' => is_int($state['sequence'] ?? null) ? $state['sequence'] : null,
        'checkpoint_count' => snCount($state['checkpoints'] ?? null),
        'requested_customer_id' => $customer,
        'requested_customer_excluded' => is_array($state['excluded_customers'] ?? null)
            ? in_array($customer, array_map('intval', $state['excluded_customers']), true) : null,
        'held_count' => snCount($held), 'held_reason_counts' => $reasons,
        'unallocated_count' => is_int($last['unallocated_count'] ?? null) ? $last['unallocated_count'] : null,
        'applied_source_hash' => is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/D', $hash) ? $hash : null];
}

/** Only a regular, bounded file immediately inside the fixed private meter directory. */
function snReadJson(string $directory, string $name, int $maximum): array
{
    if ($name === '' || basename($name) !== $name || str_contains($name, "\0")) throw new RuntimeException('unsafe_artifact_name');
    $dir = realpath($directory);
    $path = $directory.'/'.$name;
    $meta = @lstat($path);
    if ($dir === false || $meta === false || ($meta['mode'] & 0170000) !== 0100000
        || realpath($path) !== $dir.'/'.$name || $meta['size'] > $maximum) throw new RuntimeException('artifact_unavailable');
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new RuntimeException('artifact_unavailable');
    try {
        $before = fstat($handle);
        if (!$before || $before['dev'] !== $meta['dev'] || $before['ino'] !== $meta['ino']) throw new RuntimeException('artifact_changed');
        $raw = stream_get_contents($handle, $maximum + 1);
        $after = fstat($handle);
        if (!is_string($raw) || strlen($raw) > $maximum || !$after || $before['size'] !== $after['size']
            || $before['mtime'] !== $after['mtime'] || strlen($raw) !== $after['size']) throw new RuntimeException('artifact_changed');
        $value = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('artifact_invalid');
        return [$value, hash('sha256', $raw)];
    } finally { fclose($handle); }
}

/** Projection only. This is not a financial validation or a new supplier fetch. */
function snCoverage(array $snapshot, array $references, int $now): array
{
    $observed = snDate($snapshot['observed_at'] ?? null);
    $current = $snapshot['current_cycle'] ?? null;
    $reports = $snapshot['reports'] ?? null;
    if ($observed === null || !is_string($current) || strlen($current) > 100 || !is_array($reports) || count($reports) > 20) throw new RuntimeException('snapshot_schema_unknown');
    $resources = [];
    foreach ($references as $ref) $resources[$ref] = ['provider_ref' => $ref, 'current_cycle_item_count' => null,
        'current_cycle_usage_from' => null, 'current_cycle_oldest_usage_to' => null, 'current_cycle_newest_usage_to' => null,
        'current_cycle_oldest_usage_age_seconds' => null, 'evidence' => 'no_matching_stored_current_cycle_item'];
    $seenCycles = []; $rowCount = 0; $itemCount = 0; $unmatched = 0; $shapeComplete = true;
    foreach ($reports as $report) {
        if (!is_array($report) || !is_array($report['cycle'] ?? null) || !is_array($report['rows'] ?? null)) throw new RuntimeException('snapshot_schema_unknown');
        $cycle = $report['cycle']['id'] ?? null;
        if (!is_string($cycle) || strlen($cycle) > 100 || isset($seenCycles[$cycle])) throw new RuntimeException('snapshot_cycle_unknown');
        $seenCycles[$cycle] = true;
        if (!is_int($report['total_items'] ?? null) || count($report['rows']) !== $report['total_items']) $shapeComplete = false;
        foreach ($report['rows'] as $row) {
            if (++$rowCount > 10_000 || !is_array($row) || !is_array($row['items'] ?? null)) throw new RuntimeException('snapshot_rows_unknown');
            foreach ($row['items'] as $item) {
                if (++$itemCount > 30_000 || !is_array($item)) throw new RuntimeException('snapshot_items_unknown');
                if (($item['metric'] ?? null) !== 'cc_internet' || ($row['productName'] ?? null) !== 'iaas' || ($row['scope'] ?? null) !== 'instance') continue;
                $ref = (is_string($row['region'] ?? null) ? $row['region'] : '').':'.(is_string($row['instanceId'] ?? null) ? strtolower($row['instanceId']) : '');
                if (!snRef($ref) || !isset($resources[$ref])) { $unmatched++; continue; }
                if ($cycle !== $current) continue;
                $from = snDate($item['usageFrom'] ?? null); $to = snDate($item['usageTo'] ?? null);
                $target = &$resources[$ref];
                $target['current_cycle_item_count'] = ($target['current_cycle_item_count'] ?? 0) + 1;
                if ($from === null || $to === null || $from > $to || $to > $observed) {
                    $target['evidence'] = 'invalid_stored_usage_window';
                    $shapeComplete = false;
                } else {
                    $target['current_cycle_usage_from'] = min($target['current_cycle_usage_from'] ?? $from, $from);
                    $target['current_cycle_oldest_usage_to'] = min($target['current_cycle_oldest_usage_to'] ?? $to, $to);
                    $target['current_cycle_newest_usage_to'] = max($target['current_cycle_newest_usage_to'] ?? $to, $to);
                    if ($target['evidence'] !== 'invalid_stored_usage_window') $target['evidence'] = 'stored_usage_window_only';
                }
                unset($target);
            }
        }
    }
    if (!isset($seenCycles[$current])) $shapeComplete = false;
    foreach ($resources as &$resource) $resource['current_cycle_oldest_usage_age_seconds'] = snAge($resource['current_cycle_oldest_usage_to'], $now);
    unset($resource);
    return ['status' => 'state_bound_stored_snapshot', 'supplier_fetched_now' => false,
        'financial_totals_revalidated' => false, 'bounded_shape_consistent' => $shapeComplete,
        'observed_at' => $observed, 'observed_age_seconds' => snAge($observed, $now),
        'report_count' => count($reports), 'stored_row_count' => $rowCount,
        'unmatched_instance_cc_internet_items' => $unmatched, 'resources' => array_values($resources),
        'freshness_policy_verdict' => 'not_evaluated_no_enforcement_or_credit_inference'];
}

function snProvider(string $ref, string $token): array
{
    $parts = snRef($ref);
    if ($parts === null) return ['result' => 'unknown', 'reason' => 'invalid_reference'];
    if (!extension_loaded('curl')) return ['result' => 'unknown', 'reason' => 'curl_unavailable'];
    $url = 'https://napi.arvancloud.ir/ecc/v1/regions/'.rawurlencode($parts[0]).'/servers/'.rawurlencode($parts[1]);
    $body = ''; $tooLarge = false;
    $handle = curl_init($url);
    if ($handle === false) return ['result' => 'unknown', 'reason' => 'curl_unavailable'];
    try {
        curl_setopt_array($handle, [CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => ['Authorization: '.$token, 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => 4000, CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 262_144) { $tooLarge = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($handle);
        $http = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $tooLarge) return ['result' => 'unknown', 'http_status' => $http, 'reason' => $tooLarge ? 'response_limit' : 'transport_failure'];
        if ($http !== 200) return ['result' => 'unknown', 'http_status' => $http, 'reason' => 'provider_http_not_200'];
        $json = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $data = is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : [];
        if (!is_string($data['id'] ?? null) || strtolower($data['id']) !== $parts[1]) return ['result' => 'unknown', 'http_status' => $http, 'reason' => 'response_identity_unconfirmed'];
        $status = match (strtolower(is_string($data['status'] ?? null) ? $data['status'] : '')) {
            'active', 'running' => 'running', 'shutoff', 'stopped', 'paused', 'suspended' => 'off',
            'build', 'building', 'rebuild', 'creating', 'installing' => 'building', 'deleted', 'deleting' => 'deleted', default => 'unknown'};
        return ['result' => $status === 'unknown' ? 'unknown' : 'identity_confirmed', 'http_status' => $http, 'status' => $status];
    } catch (Throwable) { return ['result' => 'unknown', 'reason' => 'response_unreadable']; }
    finally { curl_close($handle); }
}

function snSelfTest(): void
{
    $tests = 0;
    $assert = static function (bool $ok) use (&$tests): void { $tests++; if (!$ok) throw new RuntimeException('self_test_'.$tests); };
    $ref = 'ir-thr-c2:11111111-2222-3333-4444-555555555555';
    $assert(snRef($ref) !== null); $assert(snRef('https://evil.test:443') === null);
    $assert(snRef('../x:11111111-2222-3333-4444-555555555555') === null); $assert(snRef($ref."\n") === null);
    $assert(snDate('tomorrow') === null); $assert(snDate('2026-02-31T00:00:00Z') === null);
    $assert(snDate('2026-11-03T05:30:00+03:30') === '2026-11-03T02:00:00Z');
    $now = (new DateTimeImmutable('2026-11-03T03:00:00Z'))->getTimestamp();
    $state = snState(['enabled' => true, 'authorization' => 'NEVER_OUTPUT_SECRET', 'excluded_customers' => [17],
        'last_success' => '2026-11-03T02:00:00Z', 'last_result' => ['held' => [['reason' => 'owner_exclusion', 'private' => 'NEVER_OUTPUT_SECRET']]]], $now, 17);
    $assert($state['requested_customer_excluded'] === true); $assert($state['last_success_age_seconds'] === 3600);
    $assert(!str_contains(json_encode($state), 'NEVER_OUTPUT_SECRET')); $assert($state['checkpoint_count'] === null);
    $assert(snState(null, $now, 17)['requested_customer_excluded'] === null);
    $assert(snState(['excluded_customers' => [18]], $now, 17)['requested_customer_excluded'] === false);
    $assert(snArgs(['--inspect', '--customer=17']) === ['action' => 'inspect', 'customer_id' => 17]);
    $assert(snArgs(['--customer=18', '--inspect']) === ['action' => 'inspect', 'customer_id' => 18]);
    $row = ['region' => 'ir-thr-c2', 'instanceId' => '11111111-2222-3333-4444-555555555555', 'productName' => 'iaas', 'scope' => 'instance',
        'items' => [['metric' => 'cc_internet', 'usageFrom' => '2026-11-01T00:00:00Z', 'usageTo' => '2026-11-02T00:00:00Z', 'price' => 'NEVER_OUTPUT_SECRET']]];
    $snapshot = ['observed_at' => '2026-11-03T02:00:00Z', 'current_cycle' => 'cycle1',
        'reports' => [['cycle' => ['id' => 'cycle1', 'accountId' => 'NEVER_OUTPUT_SECRET'], 'rows' => [$row], 'total_items' => 1]]];
    $coverage = snCoverage($snapshot, [$ref, 'ir-thr-c2:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], $now);
    $assert($coverage['bounded_shape_consistent'] === true); $assert($coverage['resources'][0]['current_cycle_item_count'] === 1);
    $assert($coverage['resources'][0]['current_cycle_oldest_usage_age_seconds'] === 97200);
    $assert($coverage['resources'][1]['current_cycle_item_count'] === null); $assert($coverage['resources'][1]['current_cycle_oldest_usage_to'] === null);
    $assert(!str_contains(json_encode($coverage), 'NEVER_OUTPUT_SECRET')); $assert($coverage['supplier_fetched_now'] === false);
    $snapshot['reports'][0]['rows'][0]['items'][0]['usageTo'] = '2026-11-04T00:00:00Z';
    $assert(snCoverage($snapshot, [$ref], $now)['bounded_shape_consistent'] === false);
    $snapshot['reports'][0]['total_items'] = 2;
    $assert(snCoverage($snapshot, [$ref], $now)['bounded_shape_consistent'] === false);
    $snapshot['observed_at'] = 'secret';
    try { snCoverage($snapshot, [$ref], $now); $assert(false); } catch (RuntimeException $e) { $assert($e->getMessage() === 'snapshot_schema_unknown'); }
    echo json_encode(['self_test' => 'passed', 'assertions' => $tests], JSON_THROW_ON_ERROR)."\n";
}

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
try { $options = snArgs(array_slice($argv ?? [], 1)); }
catch (InvalidArgumentException) { fwrite(STDERR, "Usage: php inspect-runtime.php --inspect --customer=<positive-id>\n       php inspect-runtime.php --help | --self-test\n"); exit(64); }
if ($options['action'] === 'help') { echo "From the trusted Laravel application directory: php <private-tool-path> --inspect --customer=<positive-id>\nRead-only bounded diagnostics; output is private operational metadata. --self-test performs synthetic checks only.\n"; exit(0); }
if ($options['action'] === 'self_test') { snSelfTest(); exit(0); }
$customer = $options['customer_id'];

$db = null; $app = null; $stage = 'preflight'; $exit = 1;
$output = ['inspector_version' => 3, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'read_only' => true,
    'application_providers_booted' => false, 'commands_executed' => false,
    'budgets' => ['fleet_rows' => SN_FLEET_LIMIT, 'provider_gets' => SN_GET_LIMIT, 'provider_seconds' => SN_GET_BUDGET,
        'provider_connect_ms' => 2000, 'provider_timeout_ms' => 4000, 'provider_response_bytes' => 262_144, 'stored_json_bytes' => SN_JSON_LIMIT]];
try {
    $root = getcwd();
    if (!is_string($root) || realpath($root) !== $root || !is_file($root.'/artisan') || !is_file($root.'/bootstrap/app.php') || !is_file($root.'/vendor/autoload.php')) throw new RuntimeException('wrong_application_directory');
    define('SN_APP', $root);
    require SN_APP.'/vendor/autoload.php';
    // Intentionally do not require bootstrap/app.php or bootstrap a console kernel.
    $app = new Illuminate\Foundation\Application(SN_APP);
    $app->bootstrapWith([Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class, Illuminate\Foundation\Bootstrap\LoadConfiguration::class]);
    $app['config']->set('cache.default', 'array');
    $app['config']->set('cache.stores.array', ['driver' => 'array', 'serialize' => false]);
    $app['config']->set('logging.default', 'runtime_inspect_null');
    $app['config']->set('logging.channels.runtime_inspect_null', ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class]);
    $stage = 'database_read_only_setup';
    $name = $app['config']->get('database.default');
    $config = $app['config']->get('database.connections.'.$name);
    if (is_array($config)) {
        $config = (new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($config);
        unset($config['url']); // Do not allow a second URL parse to replace the verified options.
    }
    if (!is_array($config) || !in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) throw new RuntimeException('unexpected_database_driver');
    if (isset($config['read']) || isset($config['write'])) throw new RuntimeException('split_database_configuration_not_supported');
    $safeOptions = [PDO::ATTR_TIMEOUT, PDO::ATTR_PERSISTENT];
    foreach (['PDO::MYSQL_ATTR_SSL_CA', 'PDO::MYSQL_ATTR_SSL_CAPATH', 'PDO::MYSQL_ATTR_SSL_CERT', 'PDO::MYSQL_ATTR_SSL_KEY', 'PDO::MYSQL_ATTR_SSL_CIPHER', 'PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'] as $constant) {
        if (defined($constant)) $safeOptions[] = constant($constant);
    }
    if (isset($config['options']) && !is_array($config['options'])) throw new RuntimeException('unsupported_database_option');
    foreach (($config['options'] ?? []) as $key => $value) if (!in_array((int) $key, $safeOptions, true)) throw new RuntimeException('unsupported_database_option');
    $config['options'][PDO::ATTR_PERSISTENT] = false;
    $config['options'][PDO::ATTR_TIMEOUT] = 3;
    $app['config']->set('database.connections.runtime_inspect_readonly', $config);
    $app->register(Illuminate\Database\DatabaseServiceProvider::class);
    $app->register(Illuminate\Encryption\EncryptionServiceProvider::class);
    $db = $app->make('db')->connection('runtime_inspect_readonly');
    $db->disableQueryLog();
    $pdo = $db->getPdo();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $flavor = stripos((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false ? 'mariadb' : 'mysql';
    $pdo->exec($flavor === 'mariadb' ? 'SET SESSION max_statement_time = 5' : 'SET SESSION MAX_EXECUTION_TIME = 5000');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
    $db->beginTransaction();
    try { $readOnly = $pdo->query('SELECT @@session.tx_read_only')->fetchColumn(); }
    catch (PDOException) { $readOnly = $pdo->query('SELECT @@session.transaction_read_only')->fetchColumn(); }
    if ((int) $readOnly !== 1 || !$pdo->inTransaction()) throw new RuntimeException('read_only_not_verified');
    $db->beforeExecuting(static function (string $sql): void {
        if (!preg_match('/\A\s*SELECT\b/i', $sql)) throw new RuntimeException('non_select_query_rejected');
    });
    $output['runtime'] = ['php_version' => PHP_VERSION, 'database_driver' => $config['driver'], 'database_flavor' => $flavor,
        'transaction_read_only_verified' => true, 'cache_driver' => 'array'];
    $stage = 'fleet';
    $rows = $db->table('cloud_instances as ci')->leftJoin('services as s', 's.id', '=', 'ci.service_id')
        ->where('ci.provider', 'arvan')->where(fn ($q) => $q->whereNull('ci.status')->orWhere('ci.status', '<>', 'deleted'))
        ->select(['ci.id', 'ci.service_id', 'ci.provider_ref', 'ci.status as instance_status',
            's.customer_id', 's.status as service_status', 's.billing_mode', 's.currency_code', 's.provision_status'])
        ->selectRaw("CASE WHEN JSON_VALID(s.provision_meta) THEN JSON_TYPE(JSON_EXTRACT(s.provision_meta, '$.traffic_monitor')) IN ('OBJECT', 'ARRAY') ELSE NULL END AS monitor_present")
        ->selectRaw("CASE WHEN JSON_VALID(s.provision_meta) THEN JSON_TYPE(JSON_EXTRACT(s.provision_meta, '$.traffic_guard')) <> 'NULL' ELSE NULL END AS traffic_guard_present")
        ->orderBy('ci.id')->limit(SN_FLEET_LIMIT + 1)->get();
    $complete = count($rows) <= SN_FLEET_LIMIT;
    $fleet = []; $refs = []; $duplicates = []; $invalid = 0;
    foreach ($rows->take(SN_FLEET_LIMIT) as $row) {
        $parts = snRef($row->provider_ref);
        $ref = $parts ? $parts[0].':'.$parts[1] : null;
        if ($ref !== null) { if (isset($refs[$ref])) $duplicates[$ref] = true; $refs[$ref] = true; } else $invalid++;
        $fleet[] = ['instance_id' => (int) $row->id, 'service_id' => (int) $row->service_id,
            'customer_id' => $row->customer_id === null ? null : (int) $row->customer_id, 'provider_ref' => $ref,
            'reference_valid' => $ref !== null,
            'local_instance_status' => snEnum($row->instance_status, ['running', 'off', 'building', 'error', 'unknown', 'stopped', 'pending']),
            'service_status' => snEnum($row->service_status, ['active', 'suspended', 'cancelled', 'pending', 'terminated', 'expired']),
            'billing_mode' => snEnum($row->billing_mode, ['hourly', 'monthly', 'external']),
            'currency' => snEnum($row->currency_code, ['IRT', 'IRR', 'EUR', 'USD']),
            'provision_status' => snEnum($row->provision_status, ['pending', 'queued', 'processing', 'ready', 'failed', 'active', 'done', 'manual', 'none']),
            'monitor_metadata_present' => $row->monitor_present === null ? null : (bool) $row->monitor_present,
            'traffic_guard_metadata_present' => $row->traffic_guard_present === null ? null : (bool) $row->traffic_guard_present,
            'metadata_is_monitoring_or_enforcement_proof' => false];
    }
    $output['fleet'] = ['scope' => 'database_arvan_rows_local_status_not_deleted_including_non_live',
        'complete_within_limit' => $complete, 'returned_rows' => count($fleet), 'invalid_reference_rows' => $invalid,
        'duplicate_reference_count' => count($duplicates), 'entries' => $fleet];
    $stage = 'meter_state';
    $stateCipher = $db->table('settings')->where('key', 'arvan_traffic_meter_v1')->value('value');
    $state = null;
    if ($stateCipher !== null) {
        if (!is_string($stateCipher) || strlen($stateCipher) > 100_000) throw new RuntimeException('state_size_unknown');
        $state = json_decode($app->make('encrypter')->decryptString($stateCipher), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new RuntimeException('state_schema_unknown');
    }
    $output['meter'] = snState($state, time(), $customer);
    $output['stored_financial_coverage'] = ['status' => 'unknown', 'reason' => 'no_state_bound_snapshot'];
    $stage = 'stored_financial_coverage';
    try {
        $hash = $output['meter']['applied_source_hash'];
        if ($hash === null) throw new RuntimeException('no_applied_source_hash');
        [$receipt] = snReadJson(SN_APP.'/storage/app/private/arvan-traffic-meter', 'last-result.json', 262_144);
        if (($receipt['result']['mode'] ?? null) !== 'apply' || !is_string($receipt['result']['source_hash'] ?? null)
            || !hash_equals($hash, $receipt['result']['source_hash'])) throw new RuntimeException('last_result_not_bound_to_applied_state');
        $filename = $receipt['source_file'] ?? null;
        if (!is_string($filename) || !preg_match('/\A\d{8}_\d{6}-[a-f0-9]{8}\.json\z/D', $filename)) throw new RuntimeException('source_filename_unknown');
        [$snapshot, $actualHash] = snReadJson(SN_APP.'/storage/app/private/arvan-traffic-meter', $filename, SN_JSON_LIMIT);
        if (!hash_equals($hash, $actualHash)) throw new RuntimeException('stored_source_hash_mismatch');
        $output['stored_financial_coverage'] = snCoverage($snapshot, array_keys($refs), time());
        $output['stored_financial_coverage']['source_hash_matches_applied_state'] = true;
    } catch (Throwable $error) {
        $allowed = ['no_applied_source_hash', 'last_result_not_bound_to_applied_state', 'source_filename_unknown', 'stored_source_hash_mismatch',
            'unsafe_artifact_name', 'artifact_unavailable', 'artifact_changed', 'artifact_invalid', 'snapshot_schema_unknown', 'snapshot_cycle_unknown', 'snapshot_rows_unknown', 'snapshot_items_unknown'];
        $output['stored_financial_coverage'] = ['status' => 'unknown', 'reason' => in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'stored_evidence_unreadable'];
    }
    // End the read-only DB snapshot before bounded remote GETs; no long-held transaction.
    $db->rollBack();
    $stage = 'provider_gets';
    $cipher = null;
    // Token was intentionally not read by cached Setting::get; use a new verified read-only transaction.
    $db->beginTransaction();
    $cipher = $db->table('settings')->where('key', 'arvan_api_token')->value('value');
    $db->rollBack();
    try { $token = is_string($cipher) && strlen($cipher) <= 100_000 ? $app->make('encrypter')->decryptString($cipher) : null; }
    catch (Throwable) { $token = null; } // Missing/undecryptable access is unknown, never inferred provider absence.
    $gets = []; $start = hrtime(true); $attempts = 0; $confirmed = 0;
    foreach (array_keys($refs) as $ref) {
        if (isset($duplicates[$ref])) $result = ['result' => 'unknown', 'reason' => 'ambiguous_database_reference'];
        elseif (!is_string($token) || trim($token) === '' || str_contains($token, "\r") || str_contains($token, "\n")) $result = ['result' => 'unknown', 'reason' => 'token_unavailable'];
        elseif ($attempts >= SN_GET_LIMIT || (hrtime(true) - $start) / 1e9 > SN_GET_BUDGET - 4) $result = ['result' => 'unknown', 'reason' => 'provider_budget_exhausted'];
        else { $attempts++; $result = snProvider($ref, $token); }
        if ($result['result'] === 'identity_confirmed') $confirmed++;
        $gets[] = ['provider_ref' => $ref] + $result;
    }
    unset($token, $cipher, $stateCipher, $state, $snapshot, $receipt);
    $output['provider'] = ['read_only_detail_get_attempts' => $attempts, 'identity_confirmed_count' => $confirmed,
        'all_database_valid_references_identity_confirmed' => $complete && $invalid === 0 && $duplicates === [] && $confirmed === count($refs),
        'provider_account_inventory_complete' => false, 'provider_account_inventory_reason' => 'account_wide_inventory_not_attempted_no_orphan_claim',
        'entries' => $gets];
    $stage = 'installed_source_hashes';
    $output['installed_source_sha256'] = [];
    foreach (['meter' => 'app/Services/Finance/ArvanTrafficMeter.php', 'source' => 'app/Services/Finance/ArvanTrafficSource.php',
        'command' => 'app/Console/Commands/MeterArvanTraffic.php'] as $label => $relative) {
        $file = SN_APP.'/'.$relative;
        $output['installed_source_sha256'][$label] = is_file($file) && !is_link($file) && filesize($file) <= 100_000 ? hash_file('sha256', $file) : null;
    }
    $output['result'] = 'diagnostic_complete_with_explicit_unknowns'; $exit = 0;
} catch (Throwable $error) {
    $output['result'] = 'diagnostic_incomplete';
    $output['failed_stage'] = $stage;
    $output['error_class'] = get_class($error);
    $safeErrors = ['wrong_application_directory', 'unexpected_database_driver', 'split_database_configuration_not_supported',
        'unsupported_database_option', 'read_only_not_verified', 'non_select_query_rejected', 'state_size_unknown', 'state_schema_unknown'];
    $output['error_code'] = in_array($error->getMessage(), $safeErrors, true) ? $error->getMessage() : 'unclassified_failure_details_omitted';
    // Error messages, SQL, bindings, paths, decrypted values and response bodies are intentionally omitted.
} finally {
    if ($db !== null) {
        try { while ($db->transactionLevel() > 0) $db->rollBack(); } catch (Throwable) {}
        try { $db->disconnect(); } catch (Throwable) {}
    }
}
echo json_encode($output, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($exit);
