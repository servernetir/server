<?php
declare(strict_types=1);

/* CLI evidence collection only. No application boot, Eloquent, financial commands or writes. */
const TF_SOURCE_SHA256 = 'f242b2e5b0c2256749415fac4f277506a2ae0d4204f5bc83da1df51554916db9';
const TF_SOURCE_BYTES = 2_097_152;
const TF_EVIDENCE_BYTES = 4_194_304;

function tfRef(mixed $value): ?array
{
    if (!is_string($value) || !preg_match('/\A([A-Za-z0-9][A-Za-z0-9_-]{0,62}):([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})\z/D', $value, $m)) return null;
    return [$m[1], $m[2]];
}
function tfArgs(array $arguments): array
{
    if ($arguments === ['--help']) return ['action' => 'help'];
    $values = []; $inspect = false;
    foreach ($arguments as $argument) {
        if ($argument === '--inspect' && !$inspect) { $inspect = true; continue; }
        if (is_string($argument) && preg_match('/\A--(customer|service|instance)=([1-9][0-9]*)\z/D', $argument, $m) && !isset($values[$m[1]])) {
            $max = (string) PHP_INT_MAX;
            if (strlen($m[2]) > strlen($max) || (strlen($m[2]) === strlen($max) && strcmp($m[2], $max) > 0)) throw new InvalidArgumentException('invalid_arguments');
            $values[$m[1]] = (int) $m[2]; continue;
        }
        if (is_string($argument) && str_starts_with($argument, '--provider-ref=') && !isset($values['provider_ref']) && tfRef(substr($argument, 15)) !== null) {
            $values['provider_ref'] = substr($argument, 15); continue;
        }
        throw new InvalidArgumentException('invalid_arguments');
    }
    if (!$inspect || count($values) !== 4) throw new InvalidArgumentException('invalid_arguments');
    return ['action' => 'inspect'] + $values;
}
function tfCanonical(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    $list = array_is_list($value);
    foreach ($value as $key => $child) $value[$key] = tfCanonical($child);
    if (!$list) ksort($value);
    return $value;
}
function tfDigest(mixed $value): string { return hash('sha256', json_encode(tfCanonical($value), JSON_THROW_ON_ERROR)); }
function tfCycle(array $value): array
{
    $value = tfCanonical($value);
    if (isset($value['items']) && is_array($value['items']) && array_is_list($value['items']))
        usort($value['items'], static fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
    return $value;
}
function tfDate(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value)) return null;
    try {
        $date = new DateTimeImmutable($value); $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) return null;
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable) { return null; }
}
function tfMoney(mixed $value): ?string
{
    $value = is_int($value) ? (string) $value : $value;
    return is_string($value) && preg_match('/\A-?(?:0|[1-9][0-9]{0,18})\z/D', $value) ? $value : null;
}
function tfEnum(mixed $value, array $allowed): string { return is_string($value) && in_array($value, $allowed, true) ? $value : 'other_or_unknown'; }

/** Transport callback is internal/test-only; CLI cannot replace origin, token or routes. */
function tfFetchCurrent(callable $get, callable $clock): array
{
    $current = $get('current-billing-cycle', [])['data'] ?? null;
    $id = is_array($current) ? ($current['id'] ?? null) : null;
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $id)) throw new RuntimeException('invalid_current_cycle');
    $path = 'billing-cycles/'.$id;
    $before = $get($path, [])['data'] ?? null;
    if (!is_array($before) || ($before['id'] ?? null) !== $id) throw new RuntimeException('invalid_cycle');
    $rows = []; $pages = null; $total = null;
    for ($page = 1; $page <= ($pages ?? 1); $page++) {
        $result = $get($path.'/usage-items', ['page' => $page, 'perPage' => 20]);
        $meta = $result['meta']['pagination'] ?? null;
        if (!is_array($meta) || !is_int($meta['totalItems'] ?? null) || !is_int($meta['totalPages'] ?? null)
            || ($meta['current'] ?? null) !== $page || $meta['totalPages'] < 1 || $meta['totalPages'] > 100
            || $meta['totalItems'] < 0 || $meta['totalItems'] > 2000
            || ($pages !== null && ($meta['totalPages'] !== $pages || $meta['totalItems'] !== $total))) throw new RuntimeException('pagination_unknown');
        if (!is_array($result['data'] ?? null) || !array_is_list($result['data']) || count($result['data']) > 100) throw new RuntimeException('usage_page_unknown');
        $pages = $meta['totalPages']; $total = $meta['totalItems'];
        array_push($rows, ...$result['data']);
        if (count($rows) > 2000) throw new RuntimeException('source_row_limit');
    }
    $after = $get($path, [])['data'] ?? null;
    $finalCurrent = $get('current-billing-cycle', [])['data'] ?? null;
    if (!is_array($after) || tfCycle($before) !== tfCycle($after) || count($rows) !== $total
        || !is_array($finalCurrent) || ($finalCurrent['id'] ?? null) !== $id) throw new RuntimeException('source_changed_or_incomplete');
    $snapshot = ['observed_at' => gmdate('Y-m-d\TH:i:s\Z', $clock()), 'current_cycle' => $id,
        'reports' => [['cycle' => $after, 'rows' => $rows, 'total_items' => $total]]];
    if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > TF_SOURCE_BYTES) throw new RuntimeException('source_size_limit');
    return $snapshot;
}
function tfSelectItem(array $snapshot, string $ref, array $knownAccountHashes, int $now): array
{
    $unknown = ['status' => 'unknown', 'meter_price_basis_irr' => null, 'supplier_net_irr' => null,
        'reported_amount' => null, 'reported_amount_unit' => 'unknown', 'usage_to' => null,
        'current_cycle_cutoff_proven' => false, 'reason' => 'missing_unique_target_component'];
    $found = [];
    foreach ($snapshot['reports'] as $report) {
        foreach ($report['rows'] as $row) {
            if (($row['productName'] ?? null) !== 'iaas' || ($row['scope'] ?? null) !== 'instance'
                || ($row['region'] ?? '').':'.($row['instanceId'] ?? '') !== $ref) continue;
            foreach ($row['items'] as $item) if (($item['metric'] ?? null) === 'cc_internet') $found[] = [$report['cycle'], $item];
        }
    }
    if (count($found) !== 1) return $unknown;
    [$cycle, $item] = $found[0];
    $account = $cycle['accountId'] ?? null;
    $hash = is_string($account) ? hash('sha256', $account) : null;
    $matched = $hash !== null && $knownAccountHashes !== [] && count(array_unique($knownAccountHashes)) === 1 && hash_equals($knownAccountHashes[0], $hash);
    $from = tfDate($item['usageFrom'] ?? null); $to = tfDate($item['usageTo'] ?? null);
    $start = tfDate($cycle['startedAt'] ?? null); $end = tfDate($cycle['expiredAt'] ?? null); $observed = tfDate($snapshot['observed_at'] ?? null);
    if ($from === null || $to === null || $start === null || $end === null || $observed === null
        || $from < $start || $to <= $from || $to > $end || $to > $observed) return $unknown + ['window_valid' => false];
    $price = App\Services\Finance\ArvanTrafficSource::integer($item['price']);
    $discount = App\Services\Finance\ArvanTrafficSource::integer($item['discount']);
    $gift = App\Services\Finance\ArvanTrafficSource::integer($item['usedGift']);
    $qty = $item['amount'] ?? null;
    $qtyValid = (is_string($qty) || is_int($qty) || is_float($qty)) && is_numeric($qty) && is_finite((float) $qty) && (float) $qty >= 0 && (float) $qty <= 1_000_000_000;
    return ['status' => $matched && $qtyValid ? 'validated_current_source_component' : 'unknown', 'account_matches_meter' => $matched,
        'cycle_id' => $cycle['id'], 'cycle_started_at' => $start, 'cycle_expired_at' => $end, 'provider_account_sha256' => $hash,
        'item_id_sha256' => is_string($item['itemId'] ?? null) ? hash('sha256', $item['itemId']) : null,
        'price_irr' => (string) $price, 'discount_irr' => (string) $discount, 'used_gift_irr' => (string) $gift,
        'meter_price_basis_irr' => (string) ($price - $discount), 'supplier_net_irr' => (string) ($price - $discount - $gift),
        'reported_amount' => $qtyValid ? (string) $qty : null, 'reported_amount_unit' => 'unknown', 'amount_is_cutoff_proof' => false,
        'usage_from' => $from, 'usage_to' => $to, 'usage_to_age_seconds' => $now - (new DateTimeImmutable($to))->getTimestamp(),
        'fetched_at' => $observed, 'fetch_time_is_usage_freshness' => false, 'current_cycle_cutoff_proven' => false,
        'discount_or_gift_requires_review' => $discount !== 0 || $gift !== 0];
}
function tfIdentity(array $row, array $options): bool
{
    return (int) ($row['instance_id'] ?? 0) === $options['instance'] && (int) ($row['service_id'] ?? 0) === $options['service']
        && (int) ($row['customer_id'] ?? 0) === $options['customer'] && ($row['provider'] ?? null) === 'arvan'
        && ($row['provider_ref'] ?? null) === $options['provider_ref'];
}
function tfCurrentStatus(array $row): bool
{
    return is_string($row['instance_status'] ?? null) && $row['instance_status'] !== 'deleted'
        && is_string($row['service_status'] ?? null) && !in_array($row['service_status'], ['cancelled', 'canceled', 'terminated', 'deleted', 'expired'], true);
}
function tfLedgerSummary(array $sets, ?string $targetRef = null, ?int $targetService = null, ?int $targetCustomer = null): array
{
    $out = ['history_semantically_reconciled' => false, 'raw_ledger_balance_is_spendable_credit' => false, 'sets' => []];
    foreach ($sets as $name => $rows) {
        $projected = [];
        foreach ($rows as $row) {
            $record = ['id' => (int) $row['id'], 'row_sha256' => tfDigest($row)];
            foreach (['invoice_id', 'service_id', 'source_id', 'credit_entry_id', 'source_payment_id'] as $field) if (array_key_exists($field, $row)) $record[$field] = $row[$field] === null ? null : (int) $row[$field];
            foreach (['subject_id', 'quantity', 'tax_rate_bp'] as $field) if (array_key_exists($field, $row)) $record[$field] = $row[$field] === null ? null : (int) $row[$field];
            foreach (['amount', 'balance_after', 'subtotal', 'discount', 'tax', 'total', 'paid', 'line_total', 'unit_price', 'tax_amount', 'amount_irt'] as $field) if (array_key_exists($field, $row)) $record[$field] = tfMoney($row[$field]);
            if (isset($row['currency_code'])) $record['currency'] = tfEnum($row['currency_code'], ['IRT', 'IRR', 'EUR', 'USD', 'TRY']);
            if (isset($row['status'])) $record['status'] = tfEnum($row['status'], ['paid', 'draft', 'unpaid', 'void', 'refunded', 'pending', 'failed', 'expired', 'canceled', 'cancelled', 'requested', 'approved', 'rejected']);
            if ($name === 'invoices') $record['kind'] = tfEnum($row['kind'] ?? null, ['service', 'topup', 'domain']);
            if (isset($row['source_type'])) $record['source_kind'] = tfEnum($row['source_type'], ['App\\Models\\Invoice', 'App\\Models\\Payment', 'App\\Models\\CreditEntry', 'App\\Models\\Service']);
            $text = (string) ($row['reason'] ?? '').' '.(string) ($row['note'] ?? '').' '.(string) ($row['title'] ?? '').' '.(string) ($row['description'] ?? '');
            $record['traffic_keyword_present'] = stripos($text, 'traffic') !== false || str_contains($text, 'ترافیک');
            if (array_key_exists('reason', $row)) $record['reason'] = tfEnum($row['reason'], ['topup', 'invoice_payment', 'refund', 'adjustment', 'manual_credit', 'manual_debit', 'traffic', 'hourly_refund']);
            if (array_key_exists('traffic_ref_kind', $row)) $record['traffic_ref_kind'] = tfEnum($row['traffic_ref_kind'], ['owner', 'meter', 'other']);
            if (array_key_exists('action', $row)) $record['action'] = tfEnum($row['action'], ['owner_traffic_settlement', 'traffic_meter_settlement', 'traffic_meter_initialized']);
            foreach (['created_at', 'updated_at', 'issued_at', 'paid_at', 'occurred_at'] as $field) if (array_key_exists($field, $row))
                $record[$field.'_database_string'] = is_string($row[$field]) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D', $row[$field]) ? $row[$field] : null;
            if ($name === 'audits') {
                $lines = is_string($row['lines_json'] ?? null) ? json_decode($row['lines_json'], true, 128) : null;
                $record['attribution_present'] = is_array($lines); $record['selected_resource_lines'] = [];
                foreach (is_array($lines) ? $lines : [] as $line) {
                    if (!is_array($line)) continue;
                    $next = is_array($line['next'] ?? null) ? $line['next'] : $line;
                    if ($targetRef === null || ($next['resource'] ?? null) !== $targetRef || (int) ($next['service_id'] ?? 0) !== $targetService
                        || (int) ($next['customer_id'] ?? 0) !== $targetCustomer) continue;
                    $projection = ['service_id' => $targetService, 'resource' => $targetRef, 'from' => tfDate($next['from'] ?? null), 'to' => tfDate($next['to'] ?? null)];
                    $projection['line_from'] = tfDate($line['from'] ?? null);
                    $projection['line_to'] = tfDate($line['to'] ?? null);
                    $projection['from_to_are_cumulative_next_window'] = is_array($line['next'] ?? null);
                    foreach (['cost_irr', 'sell_irt', 'tax_irt'] as $field) $projection[$field] = tfMoney($next[$field] ?? null);
                    foreach (['subtotal_irt', 'tax_irt'] as $field) if (array_key_exists($field, $line)) $projection['line_'.$field] = tfMoney($line[$field]);
                    $record['selected_resource_lines'][] = $projection;
                }
            }
            $projected[] = $record;
        }
        $out['sets'][$name] = ['count' => count($rows), 'set_sha256' => tfDigest($rows), 'records' => $projected];
    }
    return $out;
}

function tfHttpGet(string $path, array $query, string $token, array &$budget): array
{
    if (!preg_match('/\A(?:current-billing-cycle|billing-cycles\/[a-f0-9-]{36}(?:\/usage-items)?)\z/D', $path)
        || ++$budget['calls'] > 104 || (hrtime(true) - $budget['start']) / 1e9 > 86) throw new RuntimeException('provider_budget_or_path');
    $body = ''; $handle = curl_init('https://napi.arvancloud.ir/resid/v1/'.$path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''));
    if ($handle === false) throw new RuntimeException('transport_unavailable');
    try {
        $set = curl_setopt_array($handle, [CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => ['Authorization: '.$token, 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => 4000, CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body, &$budget): int {
                if (strlen($body) + strlen($chunk) > 262_144 || $budget['bytes'] + strlen($chunk) > TF_SOURCE_BYTES) return 0;
                $body .= $chunk; $budget['bytes'] += strlen($chunk); return strlen($chunk);
            }]);
        if (!$set || curl_exec($handle) === false || (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) throw new RuntimeException('provider_get_unknown');
        $json = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($json) || !is_array($json['data'] ?? null)) throw new RuntimeException('provider_json_unknown');
        return $json;
    } finally { curl_close($handle); }
}
function tfReserveRows(int $count, int $textBytes, int $limit, int &$remaining): void
{
    if ($count < 0 || $count > $limit) throw new RuntimeException('history_cap_exceeded');
    // Conservative JSON escaping/object overhead allowance, checked BEFORE get().
    if ($textBytes < 0 || $textBytes > TF_EVIDENCE_BYTES || $count > intdiv($remaining, 2048)) throw new RuntimeException('history_byte_limit');
    $reserve = $textBytes * 6 + $count * 2048;
    if ($reserve > $remaining) throw new RuntimeException('history_byte_limit');
    $remaining -= $reserve;
}
function tfRows($query, int $limit, array $columns): array
{
    static $remaining = TF_EVIDENCE_BYTES - TF_SOURCE_BYTES;
    $text = ['credit_ledger' => ['reason', 'source_type', 'note'], 'invoices' => ['note'], 'invoice_items' => ['title', 'description'],
        'payments' => ['external_ref', 'ref_id', 'fee_type'], 'wallet_refund_requests' => [], 'finance_audit_events' => ['details'], 'business_ledger' => ['note']];
    if (!is_string($query->from) || !array_key_exists($query->from, $text)) throw new RuntimeException('history_table_unknown');
    $length = $text[$query->from] === [] ? '0' : implode(' + ', array_map(static fn ($column) => 'COALESCE(OCTET_LENGTH(`'.$column.'`), 0)', $text[$query->from]));
    $probe = clone $query;
    $size = $probe->reorder()->select([])->selectRaw('COUNT(*) AS row_count, COALESCE(SUM('.$length.'), 0) AS text_bytes')->first();
    if (!$size || !preg_match('/\A[0-9]{1,12}\z/D', (string) $size->row_count) || !preg_match('/\A[0-9]{1,12}\z/D', (string) $size->text_bytes)) throw new RuntimeException('history_size_unknown');
    tfReserveRows((int) $size->row_count, (int) $size->text_bytes, $limit, $remaining);
    $rows = $query->orderBy('id')->limit($limit + 1)->get($columns)->map(static fn ($row) => (array) $row)->all();
    if (count($rows) > $limit) throw new RuntimeException('history_cap_exceeded');
    return $rows;
}
function tfIsolation($pdo): string
{
    try { $value = $pdo->query('SELECT @@session.tx_isolation')->fetchColumn(); }
    catch (PDOException) { $value = $pdo->query('SELECT @@session.transaction_isolation')->fetchColumn(); }
    return is_string($value) ? strtoupper(str_replace([' ', '_'], '-', $value)) : 'unknown';
}
/** Each identity phase must start a new, independently verified read-only snapshot. */
function tfBeginFreshRead($db): string
{
    if ($db->transactionLevel() !== 0) throw new RuntimeException('fresh_read_requires_closed_transaction');
    $pdo = $db->getPdo();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $flavor = stripos((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false ? 'mariadb' : 'mysql';
    $pdo->exec($flavor === 'mariadb' ? 'SET SESSION max_statement_time = 5' : 'SET SESSION MAX_EXECUTION_TIME = 5000');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
    $db->beginTransaction();
    // Laravel may reconnect while beginning a transaction. A new connection
    // without these session settings must fail rather than reuse old proof.
    if ($db->getPdo() !== $pdo) throw new RuntimeException('read_connection_changed');
    try { $guard = $pdo->query('SELECT @@session.tx_read_only')->fetchColumn(); }
    catch (PDOException) { $guard = $pdo->query('SELECT @@session.transaction_read_only')->fetchColumn(); }
    if ((int) $guard !== 1 || !$pdo->inTransaction() || $db->transactionLevel() !== 1) throw new RuntimeException('read_only_unverified');
    if (tfIsolation($pdo) !== 'REPEATABLE-READ') throw new RuntimeException('read_isolation_unverified');
    return $flavor;
}
function tfEndFreshRead($db, $expectedPdo): void
{
    if ($db->getPdo() !== $expectedPdo || $db->transactionLevel() !== 1 || !$expectedPdo->inTransaction()) throw new RuntimeException('read_snapshot_lost');
    try { $guard = $expectedPdo->query('SELECT @@session.tx_read_only')->fetchColumn(); }
    catch (PDOException) { $guard = $expectedPdo->query('SELECT @@session.transaction_read_only')->fetchColumn(); }
    if ((int) $guard !== 1 || $db->getPdo() !== $expectedPdo || $db->transactionLevel() !== 1 || !$expectedPdo->inTransaction()) throw new RuntimeException('read_snapshot_lost');
    if (tfIsolation($expectedPdo) !== 'REPEATABLE-READ' || $db->getPdo() !== $expectedPdo || $db->transactionLevel() !== 1 || !$expectedPdo->inTransaction()) throw new RuntimeException('read_snapshot_lost');
    $db->rollBack();
}
function tfTarget($db, array $options): array
{
    $rows = $db->table('cloud_instances as ci')->join('services as s', 's.id', '=', 'ci.service_id')
        ->where('ci.id', $options['instance'])->select(['ci.id as instance_id', 'ci.service_id', 'ci.provider', 'ci.provider_ref', 'ci.status as instance_status', 'ci.image_key', 's.customer_id', 's.currency_code', 's.status as service_status'])
        ->selectRaw('CASE WHEN ci.root_password_enc IS NOT NULL AND OCTET_LENGTH(ci.root_password_enc) > 0 THEN 1 ELSE 0 END AS encrypted_credential_present')->limit(2)->get()->all();
    if (count($rows) !== 1 || !tfIdentity((array) $rows[0], $options)) throw new RuntimeException('target_identity_mismatch');
    if (!tfCurrentStatus((array) $rows[0])) throw new RuntimeException('target_not_current');
    $duplicate = $db->table('cloud_instances')->where('provider', 'arvan')->where('provider_ref', $options['provider_ref'])->limit(2)->pluck('id')->all();
    if (count($duplicate) !== 1 || (int) $duplicate[0] !== $options['instance']) throw new RuntimeException('ambiguous_resource');
    return (array) $rows[0];
}

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
try { $options = tfArgs(array_slice($argv ?? [], 1)); }
catch (InvalidArgumentException) { fwrite(STDERR, "Usage: php inspect-traffic-finance.php --inspect --customer=<id> --service=<id> --instance=<id> --provider-ref=<region:uuid>\nUse --help alone for details.\n"); exit(64); }
if ($options['action'] === 'help') { echo "Run from the trusted Laravel app directory with --inspect and all four identity flags. GET/SELECT only; no app boot or financial command. Redirect stdout to a private file: it contains application-encrypted financial evidence and private monetary projections.\n"; exit(0); }
set_error_handler(static function (int $level): bool {
    if (error_reporting() & $level) throw new ErrorException('diagnostic_warning');
    return true;
});

$db = null; $stage = 'preflight'; $exit = 1;
$out = ['tool_version' => 1, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'application_booted' => false, 'database_writes' => false,
    'financial_commands_executed' => false, 'diagnostic_is_enrollment_or_charge_authorization' => false];
try {
    $root = getcwd();
    if (!is_string($root) || realpath($root) !== $root || !is_file($root.'/artisan') || !is_file($root.'/bootstrap/app.php') || !is_file($root.'/vendor/autoload.php') || !extension_loaded('curl')) throw new RuntimeException('application_or_curl_unavailable');
    require $root.'/vendor/autoload.php';
    $app = new Illuminate\Foundation\Application($root);
    $app->bootstrapWith([Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class, Illuminate\Foundation\Bootstrap\LoadConfiguration::class]);
    $app['config']->set('cache.default', 'array'); $app['config']->set('cache.stores.array', ['driver' => 'array', 'serialize' => false]);
    $app['config']->set('logging.default', 'traffic_inspect_null');
    $app['config']->set('logging.channels.traffic_inspect_null', ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class]);
    $stage = 'read_only_setup';
    $config = $app['config']->get('database.connections.'.$app['config']->get('database.default'));
    if (!is_array($config)) throw new RuntimeException('database_configuration_unknown');
    $config = (new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($config); unset($config['url']);
    if (!in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true) || isset($config['read']) || isset($config['write'])) throw new RuntimeException('database_configuration_unknown');
    $safe = [PDO::ATTR_TIMEOUT, PDO::ATTR_PERSISTENT];
    foreach (['PDO::MYSQL_ATTR_SSL_CA', 'PDO::MYSQL_ATTR_SSL_CAPATH', 'PDO::MYSQL_ATTR_SSL_CERT', 'PDO::MYSQL_ATTR_SSL_KEY', 'PDO::MYSQL_ATTR_SSL_CIPHER', 'PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'] as $c) if (defined($c)) $safe[] = constant($c);
    if (isset($config['options']) && !is_array($config['options'])) throw new RuntimeException('database_options_unknown');
    foreach (($config['options'] ?? []) as $key => $value) if (!in_array((int) $key, $safe, true)) throw new RuntimeException('database_options_unknown');
    $config['options'][PDO::ATTR_PERSISTENT] = false; $config['options'][PDO::ATTR_TIMEOUT] = 3;
    $app['config']->set('database.connections.traffic_inspect_readonly', $config);
    $app->register(Illuminate\Database\DatabaseServiceProvider::class); $app->register(Illuminate\Encryption\EncryptionServiceProvider::class);
    $db = $app->make('db')->connection('traffic_inspect_readonly'); $db->disableQueryLog();
    $flavor = tfBeginFreshRead($db);
    $phasePdo = $db->getPdo();
    $out['runtime'] = ['php_version' => PHP_VERSION, 'database_driver' => $config['driver'], 'database_flavor' => $flavor,
        'session_read_only_verified' => true, 'repeatable_read_verified' => true, 'cache_driver' => 'array'];
    $db->beforeExecuting(static function (string $sql): void { if (!preg_match('/\A\s*SELECT\b/i', $sql)) throw new RuntimeException('non_select_rejected'); });
    $encrypter = $app->make('encrypter'); $before = tfTarget($db, $options);
    $tokenCipher = $db->table('settings')->where('key', 'arvan_api_token')->value('value');
    if (!is_string($tokenCipher) || strlen($tokenCipher) > 100_000) throw new RuntimeException('credential_unavailable');
    $token = $encrypter->decryptString($tokenCipher);
    if (!is_string($token) || trim($token) === '' || strpbrk($token, "\r\n\0") !== false) throw new RuntimeException('credential_unavailable');
    tfEndFreshRead($db, $phasePdo);
    $out['runtime']['independently_verified_read_snapshots'] = 1;
    $stage = 'source_code_verification';
    $sourcePath = $root.'/app/Services/Finance/ArvanTrafficSource.php';
    if (!is_file($sourcePath) || is_link($sourcePath) || filesize($sourcePath) > 100_000 || !hash_equals(TF_SOURCE_SHA256, hash_file('sha256', $sourcePath))) throw new RuntimeException('source_code_unreviewed');
    require_once $sourcePath;
    if ((new ReflectionClass(App\Services\Finance\ArvanTrafficSource::class))->getFileName() !== $sourcePath
        || !hash_equals(TF_SOURCE_SHA256, hash_file('sha256', $sourcePath))) throw new RuntimeException('source_code_unreviewed');
    $stage = 'fresh_current_supplier_snapshot';
    $budget = ['start' => hrtime(true), 'calls' => 0, 'bytes' => 0];
    $snapshot = tfFetchCurrent(static function (string $path, array $query) use ($token, &$budget): array { return tfHttpGet($path, $query, $token, $budget); }, static fn (): int => time());
    (new App\Services\Finance\ArvanTrafficSource)->validate($snapshot); // Pure validation only; never fetch/run/initialize/apply.
    unset($token);
    $sourceJson = json_encode($snapshot, JSON_THROW_ON_ERROR); $out['supplier'] = ['complete_current_cycle_validated' => true,
        'source_sha256' => hash('sha256', $sourceJson), 'source_validator_sha256' => TF_SOURCE_SHA256,
        'get_count' => $budget['calls'], 'response_bytes' => $budget['bytes'], 'observed_at' => $snapshot['observed_at'], 'cycle_count' => 1,
        'closed_or_older_cycles_fetched' => false, 'per_resource_cutoff_immutability_proven' => false];
    $stage = 'target_ledger_snapshot'; tfBeginFreshRead($db);
    $phasePdo = $db->getPdo();
    $after = tfTarget($db, $options);
    if (tfDigest($before) !== tfDigest($after) || $db->table('settings')->where('key', 'arvan_api_token')->value('value') !== $tokenCipher) throw new RuntimeException('identity_or_credential_changed');
    $stateCipher = $db->table('settings')->where('key', 'arvan_traffic_meter_v1')->value('value'); $state = null;
    if (is_string($stateCipher) && strlen($stateCipher) <= 100_000) $state = json_decode($encrypter->decryptString($stateCipher), true, 128, JSON_THROW_ON_ERROR);
    $accounts = [];
    foreach (is_array($state['cycles'] ?? null) ? $state['cycles'] : [] as $cycle) if (is_string($cycle['account_hash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $cycle['account_hash'])) $accounts[] = $cycle['account_hash'];
    if (!is_array($state['cycles'] ?? null) || count($accounts) !== count($state['cycles'])) $accounts = [];
    $out['target_supplier_component'] = tfSelectItem($snapshot, $options['provider_ref'], $accounts, time());
    $key = $snapshot['current_cycle'].'|'.$options['provider_ref']; $checkpoint = $state['checkpoints'][$key] ?? null;
    $out['meter'] = ['state_present' => is_array($state), 'state_sha256' => is_array($state) ? tfDigest($state) : null,
        'version' => is_int($state['version'] ?? null) ? $state['version'] : null, 'enabled' => is_bool($state['enabled'] ?? null) ? $state['enabled'] : null,
        'last_success' => tfDate($state['last_success'] ?? null), 'authorized_at' => tfDate($state['authorized_at'] ?? null), 'baseline_paid_at' => tfDate($state['baseline_paid_at'] ?? null),
        'sequence' => is_int($state['sequence'] ?? null) ? $state['sequence'] : null,
        'selected_owner_excluded' => is_array($state['excluded_customers'] ?? null) ? in_array($options['customer'], $state['excluded_customers'], true) : null,
        'selected_current_cycle_checkpoint_present' => is_array($checkpoint), 'checkpoint_sha256' => is_array($checkpoint) ? tfDigest($checkpoint) : null];
    $sets = [];
    $sets['credits'] = tfRows($db->table('credit_ledger')->where('customer_id', $options['customer']), 2000,
        ['id', 'customer_id', 'currency_code', 'amount', 'balance_after', 'reason', 'source_type', 'source_id', 'note', 'created_at', 'updated_at']);
    $sets['invoices'] = tfRows($db->table('invoices')->where('customer_id', $options['customer']), 500,
        ['id', 'customer_id', 'service_id', 'kind', 'currency_code', 'subtotal', 'discount', 'tax', 'total', 'paid', 'status', 'note', 'issued_at', 'due_at', 'paid_at', 'created_at', 'updated_at']);
    $invoiceIds = array_column($sets['invoices'], 'id');
    $sets['items'] = tfRows($db->table('invoice_items')->whereIn('invoice_id', $invoiceIds), 3000,
        ['id', 'invoice_id', 'title', 'description', 'quantity', 'unit_price', 'line_total', 'discount', 'tax_rate_bp', 'tax_amount', 'created_at', 'updated_at']);
    $sets['payments'] = tfRows($db->table('payments')->where('customer_id', $options['customer'])->select(['id', 'invoice_id', 'customer_id', 'gateway', 'currency_code', 'amount', 'status', 'fee', 'fee_type', 'paid_at', 'created_at', 'updated_at'])
        ->selectRaw("SHA2(COALESCE(external_ref, ''), 256) AS external_ref_sha256, external_ref IS NULL AS external_ref_is_null, SHA2(COALESCE(ref_id, ''), 256) AS ref_id_sha256, ref_id IS NULL AS ref_id_is_null, CASE WHEN external_ref LIKE 'traffic-owner:%' THEN 'owner' WHEN external_ref LIKE 'traffic-meter:%' THEN 'meter' ELSE 'other' END AS traffic_ref_kind"), 2000, ['*']);
    $available = $db->table('information_schema.tables')->where('table_schema', $db->getDatabaseName())->whereIn('table_name', ['wallet_refund_requests', 'business_ledger', 'finance_audit_events'])->pluck('table_name')->all();
    $out['optional_table_coverage'] = [];
    foreach (['wallet_refund_requests', 'business_ledger', 'finance_audit_events'] as $table) $out['optional_table_coverage'][$table] = in_array($table, $available, true);
    if (in_array('wallet_refund_requests', $available, true)) $sets['refunds'] = tfRows($db->table('wallet_refund_requests')->where('customer_id', $options['customer']), 500,
        ['id', 'customer_id', 'currency_code', 'amount_irt', 'status', 'source_payment_id', 'credit_entry_id', 'voucher_id', 'document_id', 'paid_at', 'created_at', 'updated_at']);
    if (in_array('finance_audit_events', $available, true)) $sets['audits'] = tfRows($db->table('finance_audit_events')->whereIn('subject_id', $invoiceIds)
        ->whereIn('action', ['owner_traffic_settlement', 'traffic_meter_settlement'])->select(['id', 'action', 'subject_id', 'created_at', 'updated_at'])
        ->selectRaw("SHA2(details, 256) AS details_sha256, CASE WHEN JSON_VALID(details) THEN JSON_EXTRACT(details, '$.lines') ELSE NULL END AS lines_json"), 500, ['*']);
    if (in_array('business_ledger', $available, true)) $sets['business'] = tfRows($db->table('business_ledger')->where(function ($q) use ($sets, $invoiceIds) {
        $q->where(fn ($x) => $x->where('source_type', 'App\\Models\\Payment')->whereIn('source_id', array_column($sets['payments'], 'id')))
            ->orWhere(fn ($x) => $x->where('source_type', 'App\\Models\\Invoice')->whereIn('source_id', $invoiceIds))
            ->orWhere(fn ($x) => $x->where('source_type', 'App\\Models\\CreditEntry')->whereIn('source_id', array_column($sets['credits'], 'id')));
    })->select(['id', 'currency_code', 'direction', 'kind', 'amount', 'source_type', 'source_id', 'occurred_at', 'created_at', 'updated_at'])
        ->selectRaw("SHA2(COALESCE(note, ''), 256) AS note_sha256, note IS NULL AS note_is_null"), 3000, ['*']);
    $images = $db->table('cloud_images')->where('provider', 'arvan')->where('key', $after['image_key'])->limit(2)->pluck('family')->all();
    $out['target'] = ['customer_id' => $options['customer'], 'service_id' => $options['service'], 'instance_id' => $options['instance'], 'provider_ref' => $options['provider_ref'],
        'service_currency' => tfEnum($after['currency_code'], ['IRT', 'IRR', 'EUR', 'USD', 'TRY']),
        'local_instance_status' => tfEnum($after['instance_status'], ['running', 'off', 'building', 'error', 'unknown', 'stopped', 'pending']),
        'service_status' => tfEnum($after['service_status'], ['active', 'suspended', 'pending']),
        'image_key' => is_string($after['image_key']) && preg_match('/\A[A-Za-z0-9_.:-]{1,190}\z/D', $after['image_key']) ? $after['image_key'] : null,
        'image_family' => count($images) === 1 ? tfEnum($images[0], ['windows', 'ubuntu', 'debian', 'centos', 'almalinux', 'rocky', 'fedora', 'docker', 'linux', 'freebsd']) : 'other_or_unknown',
        'encrypted_credential_present' => (bool) $after['encrypted_credential_present'], 'credential_decrypted_or_usability_checked' => false,
        'identity_before_after_verified' => false];
    $out['ledger'] = tfLedgerSummary($sets, $options['provider_ref'], $options['service'], $options['customer']);
    $out['ledger']['database_datetime_timezone_not_assumed'] = true;
    $out['ledger']['payment_invoice_link_mismatch_count'] = count(array_filter($sets['payments'], static fn ($p) => !in_array($p['invoice_id'], $invoiceIds, false)));
    $out['ledger']['credit_invoice_link_mismatch_count'] = count(array_filter($sets['credits'], static fn ($c) => $c['source_type'] === 'App\\Models\\Invoice' && !in_array($c['source_id'], $invoiceIds, false)));
    $out['ledger']['raw_balance_by_currency'] = $db->table('credit_ledger')->where('customer_id', $options['customer'])->select('currency_code')->selectRaw('SUM(amount) AS amount')->groupBy('currency_code')->get()->map(static fn ($r) => ['currency' => tfEnum($r->currency_code, ['IRT', 'IRR', 'EUR', 'USD', 'TRY']), 'amount' => tfMoney($r->amount)])->all();
    $out['ledger']['collected_at'] = gmdate('Y-m-d\TH:i:s\Z'); tfEndFreshRead($db, $phasePdo);
    $out['runtime']['independently_verified_read_snapshots'] = 2;
    $out['target']['identity_before_after_verified'] = true;
    $stage = 'private_evidence_envelope';
    $proof = ['version' => 1, 'source' => $snapshot, 'identity' => $options, 'target_row' => $after, 'ledger' => $sets,
        'selected_checkpoint' => $checkpoint, 'meter_state_sha256' => $out['meter']['state_sha256']];
    $proofJson = json_encode($proof, JSON_THROW_ON_ERROR);
    if (strlen($proofJson) > TF_EVIDENCE_BYTES) throw new RuntimeException('private_evidence_size_limit');
    $out['private_evidence'] = ['format' => 'laravel-encrypter-v1', 'plaintext_sha256' => hash('sha256', $proofJson),
        'plaintext_bytes' => strlen($proofJson), 'ciphertext' => $encrypter->encryptString($proofJson), 'must_remain_private' => true];
    unset($proof, $proofJson, $snapshot, $sets, $sourceJson, $tokenCipher, $stateCipher, $state);
    $out['result'] = 'read_only_evidence_collected_not_reconciled'; $exit = 0;
} catch (Throwable $error) {
    $out['result'] = 'incomplete_unknown'; $out['failed_stage'] = $stage; $out['error_class'] = get_class($error);
    $safeErrors = ['application_or_curl_unavailable', 'database_configuration_unknown', 'database_options_unknown', 'read_only_unverified', 'non_select_rejected',
        'target_identity_mismatch', 'target_not_current', 'ambiguous_resource', 'credential_unavailable', 'source_code_unreviewed', 'invalid_current_cycle', 'invalid_cycle', 'pagination_unknown',
        'usage_page_unknown', 'source_row_limit', 'source_changed_or_incomplete', 'source_size_limit', 'provider_budget_or_path', 'transport_unavailable', 'provider_get_unknown',
        'provider_json_unknown', 'identity_or_credential_changed', 'history_cap_exceeded', 'private_evidence_size_limit',
        'fresh_read_requires_closed_transaction', 'read_connection_changed', 'read_snapshot_lost', 'read_isolation_unverified',
        'history_byte_limit', 'history_table_unknown', 'history_size_unknown'];
    $out['error_code'] = in_array($error->getMessage(), $safeErrors, true) ? $error->getMessage() : 'details_omitted';
} finally {
    if ($db !== null) { try { while ($db->transactionLevel() > 0) $db->rollBack(); } catch (Throwable) {} try { $db->disconnect(); } catch (Throwable) {} }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($exit);
