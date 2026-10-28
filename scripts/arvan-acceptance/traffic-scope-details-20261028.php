<?php
declare(strict_types=1);

/** CLI-only read-only detail snapshot. No meter invocation, provider API, notification or lifecycle action. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
$root = '/home/servernetcloud/servernet_app'; $db = null; $stage = 'bootstrap';
function trafficDetailId(mixed $v): ?int {
    if (is_int($v)) { return $v > 0 && $v <= 2147483647 ? $v : null; }
    return is_string($v) && preg_match('/^[1-9]\\d{0,9}$/D', $v) === 1
        && (int) $v <= 2147483647 ? (int) $v : null;
}
function trafficDetailBlob(string $path, string $expected): array {
    if (!is_file($path) || is_link($path)) { return ['present' => false, 'matches_reviewed_git_blob' => false]; }
    $source = file_get_contents($path);
    if (!is_string($source)) { return ['present' => true, 'matches_reviewed_git_blob' => false]; }
    return ['present' => true, 'matches_reviewed_git_blob' => hash('sha1', 'blob '.strlen($source)."\0".$source) === $expected];
}
function trafficDetailShape(mixed $row): array {
    if (!is_array($row)) { return ['type' => get_debug_type($row)]; }
    $allowed = ['id', 'invoice_id', 'invoice', 'service_id', 'service', 'reason', 'code', 'amount_irt', 'total_irt', 'status'];
    return ['type' => 'array', 'known_keys' => array_values(array_intersect($allowed, array_keys($row)))];
}
try {
    require $root.'/vendor/autoload.php'; $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = Illuminate\Support\Facades\DB::connection();
    if (!in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) { throw new RuntimeException('driver'); }
    $stage = 'read_state';
    $db->statement('SET TRANSACTION READ ONLY'); $db->beginTransaction();
    $raw = $db->table('settings')->where('key', 'arvan_traffic_meter_v1')->value('value');
    $state = json_decode(Illuminate\Support\Facades\Crypt::decryptString((string) $raw), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($state)) { throw new RuntimeException('state'); }
    $last = is_array($state['last_result'] ?? null) ? $state['last_result'] : [];
    $out = ['utc' => gmdate('c'), 'mode' => 'traffic_scope_details_read_only'];
    $invoiceStatuses = ['unpaid', 'draft', 'partial', 'overdue', 'paid', 'canceled', 'cancelled'];
    $invoiceRows = $last['invoices'] ?? null;
    $out['invoice_node'] = ['type' => get_debug_type($invoiceRows), 'count' => is_array($invoiceRows) ? count($invoiceRows) : null];
    $out['invoices'] = [];
    if (is_array($invoiceRows)) foreach (array_slice($invoiceRows, 0, 20, true) as $row) {
        $id = trafficDetailId($row);
        if (is_array($row)) { $id = trafficDetailId($row['invoice_id'] ?? $row['id'] ?? $row['invoice'] ?? null); }
        $entry = ['schema' => trafficDetailShape($row), 'verified_arvan_service_invoice' => false];
        if ($id !== null) {
            $invoice = $db->table('invoices as i')->join('services as s', 's.id', '=', 'i.service_id')
                ->where('i.id', $id)->whereColumn('i.customer_id', 's.customer_id')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('cloud_instances as ci')
                    ->whereColumn('ci.service_id', 's.id')->where('ci.provider', 'arvan'))
                ->first(['i.id', 'i.service_id', 'i.customer_id', 'i.status', 'i.total', 'i.paid']);
            if ($invoice) {
                $entry += ['invoice_id' => (int) $invoice->id, 'service_id' => (int) $invoice->service_id,
                    'status' => in_array($invoice->status, $invoiceStatuses, true) ? $invoice->status : 'other',
                    'total_irt' => (int) $invoice->total, 'paid_irt' => (int) $invoice->paid,
                    'invoice_linked_wallet_debit_rows' => $db->table('credit_ledger')
                        ->where('customer_id', $invoice->customer_id)->where('currency_code', 'IRT')
                        ->where('source_type', 'App\\Models\\Invoice')->where('source_id', $invoice->id)
                        ->where('amount', '<', 0)->count()];
                $entry['verified_arvan_service_invoice'] = true;
            }
        }
        $out['invoices'][] = $entry;
    }
    $reasons = ['historic_resource_requires_baseline', 'manual_traffic_requires_review', 'unallocated_resource',
        'ownership_ambiguous', 'unknown_owner', 'excluded_customer', 'invoice_failed', 'stale_source'];
    $heldRows = $last['held'] ?? null;
    $out['held_node'] = ['type' => get_debug_type($heldRows), 'count' => is_array($heldRows) ? count($heldRows) : null];
    $out['held'] = [];
    if (is_array($heldRows)) foreach (array_slice($heldRows, 0, 20, true) as $key => $row) {
        $entry = ['schema' => trafficDetailShape($row)];
        $reason = is_array($row) ? ($row['reason'] ?? $row['code'] ?? null) : (is_string($row) ? $row : null);
        $entry['reason_code'] = is_string($reason) && in_array($reason, $reasons, true) ? $reason : 'other_or_unknown';
        $serviceId = is_array($row) ? trafficDetailId($row['service_id'] ?? $row['service'] ?? null) : trafficDetailId($key);
        if ($serviceId !== null && $db->table('services as s')->where('s.id', $serviceId)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cloud_instances as ci')
                ->whereColumn('ci.service_id', 's.id')->where('ci.provider', 'arvan'))->exists()) {
            $entry['verified_service_id'] = $serviceId;
        }
        $out['held'][] = $entry;
    }
    $stage = 'credit_scope';
    $walletMeta = trafficDetailBlob($root.'/app/Services/Finance/Wallet.php', 'a789d47514afa795d57db9c82ce3c013a78372fe');
    $meterMeta = trafficDetailBlob($root.'/app/Console/Commands/CloudMeterHourly.php', '6a72256af179dfd105efa70ada94546ba2a23a08');
    $out['reviewed_code'] = ['wallet' => $walletMeta, 'hourly_meter' => $meterMeta];
    $wallet = $walletMeta['matches_reviewed_git_blob'] ? $app->make(App\Services\Finance\Wallet::class) : null;
    $services = $db->table('services as s')->whereIn('s.status', ['active', 'suspended'])
        ->whereExists(fn ($q) => $q->selectRaw('1')->from('cloud_instances as ci')
            ->whereColumn('ci.service_id', 's.id')->where('ci.provider', 'arvan')->where('ci.status', '!=', 'deleted'))
        ->orderBy('s.id')->limit(51)->get(['s.id', 's.customer_id', 's.status', 's.billing_mode',
            's.hourly_rate_irt', 's.on_credit_out', 's.suspended_at', 's.provision_meta'])->all();
    if (count($services) > 50) { throw new RuntimeException('service_limit'); }
    $available = [];
    $out['credit_control_scope'] = [];
    foreach ($services as $s) {
        $customerId = (int) $s->customer_id;
        if ($wallet !== null && !array_key_exists($customerId, $available)) { $available[$customerId] = $wallet->availableOf($customerId, 'IRT'); }
        $balance = $available[$customerId] ?? null;
        $hourly = $s->billing_mode === 'hourly';
        $rate = (int) $s->hourly_rate_irt;
        $meta = is_string($s->provision_meta) ? json_decode($s->provision_meta, true) : null;
        $suspension = is_array($meta) && is_array($meta['hourly_suspend'] ?? null) ? $meta['hourly_suspend'] : [];
        $out['credit_control_scope'][] = [
            'service_id' => (int) $s->id, 'service_status' => $s->status, 'billing_is_hourly' => $hourly,
            'credit_out_policy' => in_array($s->on_credit_out, ['terminate', 'convert', 'suspend'], true) ? $s->on_credit_out : 'default_suspend',
            'available_credit_verified_from_reviewed_wallet' => $wallet !== null,
            'available_credit_nonpositive' => is_int($balance) ? $balance <= 0 : null,
            'below_one_hour_rate' => $hourly && $rate > 0 && is_int($balance) ? $balance < $rate : null,
            'hourly_suspend_marker_present' => $s->suspended_at !== null || $suspension !== [],
            'hourly_shutdown_confirmed_marker_present' => !empty($suspension['confirmed_at']),
        ];
    }
    $db->rollBack();
    unset($raw, $state, $last, $row, $invoice, $heldRows, $invoiceRows, $services, $meta, $available, $balance);
    $out['notes'] = ['No meter, invoice, wallet, guest, scheduler, provider or notifier action was executed.',
        'Invoice IDs are emitted only after matching its customer and service to a local Arvan instance.',
        'A customer-aggregate invoice without service_id remains unverified here and is not printed.',
        'Negative credit and high consumption are review signals, not proof of abuse.',
        'Reviewed hourly credit control does not establish enforcement for monthly services or guest/network traffic limits.'];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    if ($db && $db->transactionLevel() > 0) { try { $db->rollBack(); } catch (Throwable) {} }
    echo json_encode(['error' => 'traffic_scope_probe_failed', 'stage' => $stage,
        'exception_class' => get_class($e), 'source_file' => basename($e->getFile()), 'source_line' => $e->getLine()], JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(1);
}
