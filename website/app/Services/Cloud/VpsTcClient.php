<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\CloudProviderOperation;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Monthly parent-service procurement. A lost receipt can never authorize another order. */
class VpsTcClient implements CloudProvider
{
    public function __construct(private VpsTcApi $api, private VpsTcCatalog $catalog) {}

    public function slug(): string { return 'vpstc'; }
    public function isConfigured(): bool { return $this->api->isConfigured(); }
    public function fetchCatalog(): array { return $this->catalog->fetch(); }

    public function testConnection(): array
    {
        $r = $this->api->balance();
        return ['ok' => $r['ok'], 'message' => $r['ok']
            ? 'اتصال برقرار است؛ آماده‌بودن فروش باید جدا بررسی شود.' : 'اتصال زیرساخت تأیید نشد.'];
    }

    public function createServer(array $spec): array
    {
        $fail = ['ref' => null, 'ipv4' => null, 'ipv6' => null, 'root_password' => null, 'status' => 'unknown'];
        $name = (string) ($spec['name'] ?? '');
        $id = (string) ($spec['labels']['snet-service'] ?? '');
        if (($spec['term'] ?? '') !== 'month' || ! preg_match('/\Asn-svc-([1-9][0-9]{0,17})\z/D', $name, $m)
            || $id !== $m[1] || ! empty($spec['ssh_keys']) || ! empty($spec['user_data'])) {
            return $this->failure('این زیرساخت فقط سفارش ماهانه با مشخصات تأییدشده می‌پذیرد.') + $fail;
        }
        $lock = Cache::lock('vpstc-order-'.hash('sha256', $name), 120);
        if (! $lock->get()) { return $this->pending('ثبت سفارش در حال بررسی است.') + $fail; }
        try {
            $key = hash('sha256', 'vpstc:create:'.$name);
            $op = CloudProviderOperation::where('operation_key', $key)->first();
            $identity = $this->specIdentity($spec);
            if ($op) {
                if (($op->payload['_account'] ?? '') !== $this->api->accountFingerprint()
                    || ($op->payload['_spec'] ?? []) !== $identity) {
                    return $this->failure('مشخصات یا حساب سفارش قبلی متفاوت است؛ تطبیق مدیر لازم است.') + $fail;
                }
                if (! $op->resource_ref) {
                    return $this->pending('نتیجهٔ خرید قبلی نامشخص است؛ خرید دوباره انجام نمی‌شود.') + $fail;
                }
                return $this->purchaseResult($op) + ['ref' => (string) $op->resource_ref];
            }
            if (Setting::get('vpstc_sales_enabled') !== '1' || ! app(VpsTcPricing::class)->configured()) {
                return $this->failure('فروش این زیرساخت هنوز فعال یا قیمت‌گذاری آن کامل نشده است.') + $fail;
            }
            $quote = $this->catalog->quote((string) ($spec['plan_ref'] ?? ''));
            $plan = CloudPlan::where('provider', $this->slug())->where('provider_ref', (string) ($spec['plan_ref'] ?? ''))
                ->where('provider_location', (string) ($spec['location_ref'] ?? ''))->first();
            if (! $quote || ! $plan || ! $plan->in_stock || ($quote['in_stock'] ?? false) !== true
                || ! $this->quoteMatches($quote, $plan, $spec)) {
                return $this->failure('قیمت، ظرفیت، سیستم‌عامل یا مشخصات جاری با سفارش تطبیق ندارد.') + $fail;
            }
            $balance = $this->api->balance();
            $details = $balance['data']['details'] ?? [];
            $credit = $this->minor($details['acc_credit'] ?? null);
            $ceiling = $this->minor($quote['total_amount'] ?? null);
            if (! $balance['ok'] || ($details['currency'] ?? '') !== $quote['currency'] || $credit === null
                || $ceiling === null || $ceiling <= 0 || $credit < $ceiling) {
                return $this->failure('اعتبار قابل‌استفادهٔ زیرساخت برای این سفارش کافی یا قابل تأیید نیست.') + $fail;
            }
            $inventory = $this->listServers();
            if (! $inventory['ok'] || collect($inventory['servers'])->contains(fn ($server) =>
                in_array($server['name'], [$name, $name.'.servernet.cloud'], true))) {
                return $this->failure('فهرست زیرساخت یا نبودن سفارش هم‌نام بدون رسید تأیید نشد؛ بررسی مدیر لازم است.') + $fail;
            }
            $image = $quote['images'][(string) $spec['image_ref']];
            $custom = array_replace_recursive((array) ($quote['custom'] ?? []), (array) ($image['custom'] ?? $image));
            $payload = ['product_id' => (int) $quote['product_id'], 'domain' => $name.'.servernet.cloud',
                'hostname' => $name.'.servernet.cloud', 'cycle' => 'm', 'pay_method' => (int) $quote['payment_method'], 'custom' => $custom];
            // Persist the complete immutable request before the first potentially paid call.
            $op = CloudProviderOperation::create(['operation_key' => $key, 'provider' => $this->slug(), 'action' => 'create',
                'payload' => ['request' => $payload, '_spec' => $identity, '_account' => $this->api->accountFingerprint(),
                    '_quote' => ['currency' => $quote['currency'], 'total_amount' => $quote['total_amount'],
                        'fingerprint' => $quote['fingerprint'] ?? null]], 'state' => 'submitting']);
            $r = $this->api->order((string) $quote['product_id'], $payload);
            $receipt = $this->orderReceipt($r['data'] ?? [], (string) $quote['product_id']);
            if (! $r['ok'] || $receipt === null) {
                $op->update(['state' => ($r['code'] ?? '') === 'mutation_rejected' ? 'rejected' : 'outcome_unknown']);
                return $this->pending('نتیجهٔ خرید ثبت شد ولی تأیید نشده است؛ خرید دوباره انجام نمی‌شود.') + $fail;
            }
            $op->update(['resource_ref' => $receipt['service'], 'task_ref' => $receipt['invoice'], 'state' => 'pending']);
            return $this->purchaseResult($op) + ['ref' => (string) $op->resource_ref];
        } catch (Throwable) {
            return $this->pending('تعیین نتیجهٔ سفارش نیازمند تطبیق است؛ خرید تکراری انجام نمی‌شود.') + $fail;
        } finally { $lock->release(); }
    }

    private function specIdentity(array $spec): array
    {
        $identity = array_map('strval', array_intersect_key($spec, array_flip([
            'name', 'plan_ref', 'location_ref', 'image_ref', 'vcpu', 'ram_mb', 'disk_gb', 'cpu_kind', 'term',
        ])));
        ksort($identity);
        return $identity;
    }

    private function quoteMatches(array $quote, CloudPlan $plan, array $spec): bool
    {
        if (($quote['product_id'] ?? '') !== (string) $spec['plan_ref']
            || ($quote['location_ref'] ?? '') !== (string) $spec['location_ref']
            || ! isset($quote['images'][(string) ($spec['image_ref'] ?? '')])
            || ! is_array($quote['images'][(string) $spec['image_ref']])
            || (int) ($quote['cost_eur_cents'] ?? 0) <= 0
            || (int) $quote['cost_eur_cents'] > (int) $plan->cost_eur_cents
            || (int) ($quote['setup_eur_cents'] ?? -1) < 0
            || (int) $quote['setup_eur_cents'] > (int) $plan->setup_eur_cents
            || ($quote['cpu_kind'] ?? '') !== (string) $plan->cpu_kind
            || ($quote['cpu_kind'] ?? '') !== (string) ($spec['cpu_kind'] ?? '')
            || ($quote['arch'] ?? '') !== (string) $plan->arch
            || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', (string) ($quote['payment_method'] ?? ''))) {
            return false;
        }
        foreach (['vcpu', 'ram_mb', 'disk_gb'] as $field) {
            if (! isset($spec[$field], $quote[$field]) || (int) $spec[$field] !== (int) $quote[$field]
                || (int) $spec[$field] !== (int) $plan->$field) { return false; }
        }
        return true;
    }

    private function orderReceipt(array $data, string $product): ?array
    {
        $items = $data['items'] ?? null;
        if (! is_array($items)) { return null; }
        $items = isset($items['id']) ? [$items] : array_values($items);
        $matches = array_values(array_filter($items, fn ($item) => is_array($item)
            && ($item['type'] ?? '') === 'Hosting' && (string) ($item['product_id'] ?? '') === $product
            && $this->numericId((string) ($item['id'] ?? ''))));
        $invoice = (string) ($data['invoice_id'] ?? '');
        return count($items) === 1 && count($matches) === 1 && $this->numericId($invoice)
            ? ['service' => (string) $matches[0]['id'], 'invoice' => $invoice] : null;
    }

    /** Payment acknowledgement is never interpreted as a completed delivery. */
    private function purchaseResult(CloudProviderOperation $op): array
    {
        $base = ['ok' => true, 'message' => 'سفارش پذیرفته شد و در حال آماده‌سازی است.', 'status' => 'building',
            'ipv4' => null, 'ipv6' => null, 'root_password' => null];
        if (! $this->verifiedInvoice($op)) {
            return $base;
        }
        $status = $this->serverStatus((string) $op->resource_ref);
        if (! ($status['ok'] ?? false)) { return $base; }
        if (($status['status'] ?? '') === 'deleted') {
            return array_replace($base, ['ok' => false, 'status' => 'deleted',
                'message' => 'سرویس سفارش قبلی نزد زیرساخت خاتمه یافته است؛ سفارش تازه خودکار ایجاد نمی‌شود.']);
        }
        $op->refresh();
        return array_replace($base, $status, ['root_password' => in_array($status['status'], ['running', 'off'], true)
            ? ($op->payload['_access']['password'] ?? null) : null,
            'supplier_due_at' => $op->payload['_supplier_due_at'] ?? null]);
    }

    private function verifiedInvoice(CloudProviderOperation $op): bool
    {
        if (! $this->numericId((string) $op->task_ref)) { return false; }
        $r = $this->api->invoice((string) $op->task_ref);
        $invoice = $r['data']['invoice'] ?? [];
        if (! $r['ok'] || ! $this->invoiceMatches($op, $invoice)) { return false; }
        if (($invoice['status'] ?? '') === 'Paid') { return true; }
        if (($invoice['status'] ?? '') !== 'Unpaid') { return false; }
        $balance = $this->api->balance();
        $details = $balance['data']['details'] ?? [];
        $credit = $this->minor($details['acc_credit'] ?? null);
        if (! $balance['ok'] || ($details['currency'] ?? '') !== ($invoice['currency'] ?? null)
            || $credit === null || $credit < $this->minor($invoice['total'])) { return false; }
        // Claim the payment durably before POST. Pollers and retried provisioners
        // may both reach this point, but exactly one may submit account credit.
        $claimed = DB::transaction(function () use ($op) {
            $fresh = CloudProviderOperation::whereKey($op->id)->lockForUpdate()->first();
            if (! $fresh || isset($fresh->payload['_credit_attempted'])) { return false; }
            $payload = $fresh->payload;
            $payload['_credit_attempted'] = now()->toIso8601String();
            $fresh->update(['payload' => $payload]);
            return true;
        });
        if ($claimed) {
            // Bound the debit to the exact amount inspected above. The
            // supplier's default "maximum" must not fund a changed invoice.
            $this->api->applyInvoiceCredit((string) $op->task_ref, (string) $invoice['total']);
        }
        // A lost acknowledgement is safe: a later read may prove payment,
        // while the durable marker prevents a second payment submission.
        $r = $this->api->invoice((string) $op->task_ref);
        $invoice = $r['data']['invoice'] ?? [];
        return $r['ok'] && $this->invoiceMatches($op, $invoice) && ($invoice['status'] ?? '') === 'Paid';
    }

    private function invoiceMatches(CloudProviderOperation $op, array $invoice): bool
    {
        $quote = $op->payload['_quote'] ?? [];
        $total = $this->minor($invoice['total'] ?? null);
        $limit = $this->minor($quote['total_amount'] ?? null);
        if ((string) ($invoice['id'] ?? '') !== (string) $op->task_ref
            || ($invoice['currency'] ?? '') !== ($quote['currency'] ?? null)
            || $total === null || $limit === null || $total > $limit || $total <= 0) { return false; }
        $items = $invoice['items'] ?? [];
        // Fee-free single-service orders must not fund another service or a
        // mixed invoice merely because one line happens to match our parent ID.
        if (! is_array($items) || count($items) !== 1) { return false; }
        $item = array_values($items)[0];
        if (! is_array($item) || ($item['type'] ?? '') !== 'Hosting'
            || (string) ($item['item_id'] ?? '') !== (string) $op->resource_ref
            || $this->minor($item['qty'] ?? null) !== 100) { return false; }
        return true;
    }

    public function serverStatus(string $ref): array
    {
        $base = ['ok' => false, 'message' => 'وضعیت سرور تأیید نشد.', 'status' => 'unknown',
            'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null, 'root_password' => null];
        try {
            if (! $this->numericId($ref)) { return $base; }
            $op = $this->purchase($ref);
            if ($op && ($op->payload['_account'] ?? '') !== $this->api->accountFingerprint()) { return $base; }
            $r = $this->api->service($ref); $service = $r['data']['service'] ?? [];
            if (! $r['ok'] || (string) ($service['id'] ?? '') !== $ref) { return $base; }
            if ($op && ((isset($service['domain']) && $service['domain'] !== ($op->payload['request']['domain'] ?? null))
                || (isset($service['billingcycle']) && $service['billingcycle'] !== 'Monthly'))) { return $base; }
            if (in_array($service['status'] ?? '', ['Cancelled', 'Terminated'], true)) {
                return array_replace($base, ['ok' => true, 'message' => '', 'status' => 'deleted']);
            }
            if (($service['status'] ?? '') !== 'Active') {
                return array_replace($base, ['ok' => true, 'message' => '', 'status' => 'building']);
            }
            if ($op && ! $this->verifiedInvoice($op)) {
                return array_replace($base, ['ok' => true, 'message' => 'پرداخت تأمین‌کننده هنوز تأیید نشده است.', 'status' => 'building']);
            }
            if ($op && ! isset($op->payload['_supplier_due_at'])
                && is_string($service['next_due'] ?? null) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $service['next_due'])) {
                DB::transaction(function () use ($op, $service) {
                    $fresh = CloudProviderOperation::whereKey($op->id)->lockForUpdate()->first();
                    if ($fresh && ! isset($fresh->payload['_supplier_due_at'])) {
                        $payload = $fresh->payload; $payload['_supplier_due_at'] = $service['next_due'];
                        $fresh->update(['payload' => $payload]);
                    }
                });
                $op->refresh();
            }
            $vm = $this->singleVm($ref);
            if ($vm === null) { return array_replace($base, ['ok' => true, 'status' => 'building']); }
            $detail = $this->api->vm($ref, $vm); $row = $detail['data']['vm'] ?? [];
            if (! $detail['ok'] || ! $this->vmIdentity($row, $vm)) { return $base; }
            $ipv4 = $this->ip($row['ipv4'] ?? null, FILTER_FLAG_IPV4);
            $ipv6 = $this->ip($row['ipv6'] ?? null, FILTER_FLAG_IPV6);
            $built = in_array($row['built'] ?? null, [true, 1, '1'], true);
            $locked = ! in_array($row['locked'] ?? false, [false, 0, '0', '', null], true);
            $status = $built && ! $locked ? match ($row['status'] ?? '') {
                'running' => 'running', 'stopped', 'off', 'poweredOff' => 'off', default => 'building',
            } : 'building';
            $savedAccess = $op?->payload['_access'] ?? [];
            $password = is_string($row['password'] ?? null) && $row['password'] !== '' ? $row['password'] : ($savedAccess['password'] ?? null);
            $username = is_string($row['username'] ?? null) && $row['username'] !== '' ? $row['username'] : ($savedAccess['username'] ?? null);
            if ($op && ! $this->vmSpecsMatch($op, $row)) { return $base; }
            $local = CloudInstance::where('provider', $this->slug())->where('provider_ref', $ref)->first();
            $havePassword = $password !== null || ($local?->hasPassword() ?? false);
            if (in_array($status, ['running', 'off'], true) && (! $ipv4 || ! $havePassword || (! $username && blank($local?->service?->username)))) {
                $status = 'building';
            }
            if ($op && $password !== null && $username !== null && $built && ! $locked && $ipv4) {
                DB::transaction(function () use ($op, $password, $username) {
                    $fresh = CloudProviderOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
                    $payload = $fresh->payload;
                    $access = ['password' => $password, 'username' => $username];
                    if (($payload['_access'] ?? null) !== $access) {
                        $payload['_access'] = $access; $fresh->update(['payload' => $payload]);
                    }
                });
            }
            if ($local && $op && ($op->payload['_spec']['name'] ?? '') === 'sn-svc-'.$local->service_id
                && $password !== null && $username !== null && $built && ! $locked && $ipv4) {
                DB::transaction(function () use ($local, $password, $username, $op) {
                    $current = CloudInstance::whereKey($local->id)->lockForUpdate()->firstOrFail();
                    if ($current->password() !== $password) { $current->setPassword($password); }
                    $meta = $current->meta ?? [];
                    if (! isset($meta['vpstc_supplier_due_at']) && isset($op->payload['_supplier_due_at'])) {
                        $meta['vpstc_supplier_due_at'] = $op->payload['_supplier_due_at'];
                        $current->meta = $meta;
                    }
                    if ($current->isDirty()) { $current->save(); }
                    $current->service?->forceFill(['password' => $password, 'username' => $username])->save();
                });
            }
            return array_replace($base, ['ok' => true, 'message' => '', 'status' => $status, 'ipv4' => $ipv4,
                // This result is cached by the account status endpoint. Access
                // credentials stay in encrypted persistence, never that cache.
                'ipv6' => $ipv6, 'root_password' => null, 'username' => $username]);
        } catch (Throwable) { return $base; }
    }

    private function vmSpecsMatch(CloudProviderOperation $op, array $row): bool
    {
        $spec = $op->payload['_spec'] ?? [];
        foreach (['cores' => 'vcpu', 'memory' => 'ram_mb', 'disk' => 'disk_gb'] as $native => $field) {
            if (! isset($row[$native]) || ! is_numeric($row[$native]) || (int) $row[$native] !== (int) ($spec[$field] ?? -1)) { return false; }
        }
        return true;
    }

    private function singleVm(string $ref): ?string
    {
        $r = $this->api->vms($ref); $rows = $r['data']['vms'] ?? null;
        if (! $r['ok'] || ! is_array($rows) || count($rows) !== 1) { return null; }
        $key = array_key_first($rows); $row = $rows[$key];
        if (! is_array($row)) { return null; }
        $id = (string) ($row['vmid'] ?? $row['id'] ?? $key);
        return preg_match('/\A(?:vm-)?[1-9][0-9]{0,17}\z/D', $id) ? $id : null;
    }

    private function vmIdentity(array $row, string $id): bool
    {
        $actual = $row['vmid'] ?? $row['id'] ?? null;
        return $actual === null || (string) $actual === $id;
    }

    private function purchase(string $ref): ?CloudProviderOperation
    {
        return CloudProviderOperation::where('provider', $this->slug())->where('action', 'create')->where('resource_ref', $ref)->first();
    }

    public function listServers(): array
    {
        try {
            $r = $this->api->services(); $rows = $r['data']['services'] ?? null;
            if (! $r['ok'] || ! is_array($rows) || count($rows) > 1000
                || filled($r['data']['next_page'] ?? null) || filled($r['data']['next'] ?? null)
                || (isset($r['data']['total']) && (int) $r['data']['total'] !== count($rows))) {
                return $this->failure('فهرست کامل سرویس‌های صورت‌حساب‌شونده تأیید نشد.') + ['servers' => []];
            }
            $purchases = CloudProviderOperation::where('provider', $this->slug())->where('action', 'create')->get();
            $knownNames = [];
            foreach ($purchases as $purchase) {
                if ($purchase->resource_ref && ($purchase->payload['_account'] ?? '') === $this->api->accountFingerprint()) {
                    $knownNames[(string) $purchase->resource_ref] = $purchase->payload['_spec']['name'] ?? '';
                }
            }
            $uncertain = $purchases->contains(fn ($purchase) => blank($purchase->resource_ref)
                && in_array($purchase->state, ['submitting', 'outcome_unknown'], true));
            $servers = []; $seen = [];
            foreach ($rows as $row) {
                $id = is_array($row) ? (string) ($row['id'] ?? '') : '';
                if (! $this->numericId($id) || isset($seen[$id])) { return $this->failure('فهرست سرویس‌ها معتبر نیست.') + ['servers' => []]; }
                $seen[$id] = true;
                if (in_array($row['status'] ?? '', ['Cancelled', 'Terminated'], true)) { continue; }
                $domain = (string) ($row['domain'] ?? $row['label'] ?? '');
                $name = isset($knownNames[$id]) && $domain === $knownNames[$id].'.servernet.cloud' ? $knownNames[$id] : $domain;
                $servers[] = ['ref' => $id, 'name' => $name,
                    'status' => 'unknown', 'ipv4' => null, 'ipv6' => null, 'plan' => (string) ($row['name'] ?? ''),
                    'location' => null, 'created' => $row['date_created'] ?? null];
            }
            // A lost order without a receipt may not yet be visible in the list.
            // The shared provisioner treats a nonempty message as an inability
            // to prove absence, so it cannot silently close that billing debt.
            return ['ok' => true, 'message' => $uncertain ? 'نتیجهٔ سفارش بدون رسید هنوز مشخص نیست؛ نبودن منبع اثبات نشده است.' : '',
                'servers' => $servers, 'inventory_kind' => 'billed_services'];
        } catch (Throwable) { return $this->failure('فهرست سرویس‌ها قابل تأیید نیست.') + ['servers' => []]; }
    }

    public function deleteServer(string $ref): array
    {
        if (! $this->numericId($ref)) { return $this->failure('شناسهٔ سرویس معتبر نیست.'); }
        $lock = Cache::lock('vpstc-resource-'.hash('sha256', $ref), 120);
        if (! $lock->get()) { return $this->pending('لغو سرویس در حال بررسی است.'); }
        try {
            $purchase = $this->purchase($ref);
            if ($purchase && ($purchase->payload['_account'] ?? '') !== $this->api->accountFingerprint()) {
                return $this->failure('حساب زیرساخت با سفارش اصلی تطبیق ندارد.');
            }
            $r = $this->api->service($ref); $service = $r['data']['service'] ?? [];
            if (! $r['ok'] || (string) ($service['id'] ?? '') !== $ref) {
                return $this->failure('توقف صورت‌حساب سرویس هنوز تأیید نشده است.');
            }
            if (in_array($service['status'] ?? '', ['Cancelled', 'Terminated'], true)) {
                return ['ok' => true, 'message' => 'خاتمهٔ سرویس و توقف تمدید تأیید شد.'];
            }
            $key = hash('sha256', 'vpstc:cancel:'.$ref);
            $op = CloudProviderOperation::where('operation_key', $key)->first();
            if (! $op) {
                $op = CloudProviderOperation::create(['operation_key' => $key, 'provider' => $this->slug(), 'action' => 'cancel',
                    'resource_ref' => $ref, 'payload' => ['_account' => $this->api->accountFingerprint()], 'state' => 'submitting']);
                $r = $this->api->cancelService($ref, true, 'Customer requested service termination');
                $op->update(['state' => $r['ok'] ? 'pending' : (($r['code'] ?? '') === 'mutation_rejected' ? 'rejected' : 'outcome_unknown')]);
            } elseif (($op->payload['_account'] ?? '') !== $this->api->accountFingerprint()) {
                return $this->failure('حساب عملیات لغو تغییر کرده است.');
            }
            $r = $this->api->service($ref); $service = $r['data']['service'] ?? [];
            if ($r['ok'] && (string) ($service['id'] ?? '') === $ref && in_array($service['status'] ?? '', ['Cancelled', 'Terminated'], true)) {
                $op->update(['state' => 'complete']);
                return ['ok' => true, 'message' => 'خاتمهٔ سرویس و توقف تمدید تأیید شد.'];
            }
            return ['ok' => false, 'pending' => true, 'message' => 'درخواست لغو ثبت شده؛ توقف صورت‌حساب هنوز تأیید نشده و لغو دوباره ارسال نمی‌شود.'];
        } catch (Throwable) { return $this->pending('نتیجهٔ لغو نیازمند تطبیق است؛ حذف ماشین معادل لغو سرویس نیست.'); }
        finally { $lock->release(); }
    }

    public function power(string $ref, string $action): array
    {
        $native = ['on' => 'start', 'off' => 'stop', 'shutdown' => 'shutdown', 'reboot' => 'reboot', 'reset' => 'reset'][$action] ?? null;
        if (! $native || ! $this->numericId($ref)) { return $this->unsupported(); }
        $lock = Cache::lock('vpstc-resource-'.hash('sha256', $ref), 120);
        if (! $lock->get()) { return $this->pending('عملیات قبلی در حال بررسی است.'); }
        try {
            $purchase = $this->purchase($ref);
            if ($purchase && ($purchase->payload['_account'] ?? '') !== $this->api->accountFingerprint()) { return $this->unsupported(); }
            if (CloudProviderOperation::where('provider', $this->slug())->where('action', 'cancel')->where('resource_ref', $ref)->exists()) {
                return $this->failure('لغو این سرویس در جریان است؛ عملیات روشن و خاموش انجام نمی‌شود.');
            }
            $current = $this->serverStatus($ref);
            if (! ($current['ok'] ?? false) || ! in_array($current['status'], ['running', 'off'], true)) {
                return $this->failure('وضعیت و مالکیت ماشین آمادهٔ عملیات تأیید نشد.');
            }
            $vm = $this->singleVm($ref);
            if (! $vm) { return $this->failure('ماشین یکتای سرویس تأیید نشد.'); }
            $key = hash('sha256', 'vpstc:power:'.$ref);
            $op = CloudProviderOperation::where('operation_key', $key)->first();
            if ($op) {
                if (($op->payload['_account'] ?? '') !== $this->api->accountFingerprint()) { return $this->failure('حساب عملیات قبلی تغییر کرده است.'); }
                $state = $this->serverStatus($ref);
                $previousAction = $op->payload['action'] ?? '';
                $want = in_array($previousAction, ['stop', 'shutdown'], true) ? 'off' : 'running';
                $canObserve = $op->state === 'pending'
                    || ($op->state === 'outcome_unknown' && in_array($previousAction, ['start', 'stop', 'shutdown'], true));
                if ($canObserve && ($state['ok'] ?? false) && $state['status'] === $want) {
                    $op->delete();
                    if ($previousAction === $native) { return ['ok' => true, 'message' => 'وضعیت مورد انتظار تأیید شد.']; }
                } else {
                    return $this->pending('نتیجهٔ عملیات قبلی هنوز تأیید نشده؛ درخواست دوباره ارسال نمی‌شود.');
                }
            }
            $op = CloudProviderOperation::create(['operation_key' => $key, 'provider' => $this->slug(), 'action' => 'power',
                'resource_ref' => $ref, 'payload' => ['_account' => $this->api->accountFingerprint(), 'action' => $native, 'vm' => $vm], 'state' => 'submitting']);
            $r = $this->api->power($ref, $vm, $native);
            $op->update(['state' => $r['ok'] ? 'pending' : (($r['code'] ?? '') === 'mutation_rejected' ? 'rejected' : 'outcome_unknown')]);
            return $r['ok'] ? ['ok' => true, 'pending' => true, 'message' => 'درخواست پذیرفته شد؛ وضعیت نهایی در حال بررسی است.']
                : $this->failure('نتیجهٔ عملیات قطعی نیست؛ درخواست خودکار تکرار نمی‌شود.');
        } catch (Throwable) { return $this->failure('عملیات قابل تأیید نیست.'); }
        finally { $lock->release(); }
    }

    // Reinstall template IDs and paid OS options must be bound to the order form before enabling this feature.
    public function rebuild(string $ref, string $imageRef, ?string $password = null): array { return $this->unsupported() + ['root_password' => null]; }
    public function resetPassword(string $ref): array { return $this->unsupported() + ['root_password' => null]; }
    public function console(string $ref): array { return $this->unsupported() + ['url' => null, 'password' => null]; }
    public function metrics(string $ref, string $window = '24h'): array { return $this->unsupported() + ['series' => []]; }
    public function resize(string $ref, string $planRef, bool $upgradeDisk = true): array { return $this->unsupported(); }
    public function uploadSshKey(string $name, string $publicKey): array { return $this->unsupported() + ['ref' => null]; }
    public function addExtraIps(string $ref, int $count): array { return $this->unsupported() + ['ips' => []]; }
    public function capabilities(): array
    {
        return ['console' => false, 'rebuild' => false, 'resize' => false, 'snapshot' => false, 'metrics' => false,
            'reset_password' => false, 'ipv6' => false, 'ssh_key' => false, 'extra_ip' => false];
    }

    private function numericId(string $id): bool { return (bool) preg_match('/\A[1-9][0-9]{0,17}\z/D', $id); }
    private function failure(string $message): array { return ['ok' => false, 'message' => $message]; }
    private function pending(string $message): array { return ['ok' => false, 'transient' => true, 'message' => $message]; }
    private function unsupported(): array { return $this->failure('این قابلیت برای این سرور در دسترس نیست.'); }
    private function ip(mixed $value, int $flag): ?string
    {
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $ip) { if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, $flag)) { return $ip; } }
        return null;
    }
    private function minor(mixed $amount): ?int
    {
        if ((! is_string($amount) && ! is_int($amount) && ! is_float($amount))
            || ! preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.([0-9]{1,2}))?\z/D', (string) $amount)) { return null; }
        $parts = explode('.', (string) $amount);
        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }
}
