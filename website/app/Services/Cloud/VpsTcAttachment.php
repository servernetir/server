<?php

namespace App\Services\Cloud;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\{ActivityLog, CloudInstance, CloudPlan, CloudProviderOperation, Customer, Service, Setting};
use App\Services\Billing\ComplimentaryServices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Cache, DB};
use Illuminate\Validation\ValidationException;

/** Adopt an existing paid period; never order, pay, renew or alter a provider VM. */
class VpsTcAttachment
{
    public function __construct(private VpsTcApi $api, private VpsTcCatalog $catalog) {}

    public function attach(Customer $customer, CloudPlan $plan, array $data, ?int $actor = null): Service
    {
        $ref = (string) ($data['provider_ref'] ?? '');
        $internal = ($data['internal_infrastructure'] ?? false) === true;
        if ($plan->provider !== 'vpstc' || ! preg_match('/\A[1-9][0-9]{0,17}\z/D', $ref)
            || ($data['cycle'] ?? '') !== 'monthly'
            || ! filter_var($data['expected_ipv4'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || ($internal && ! app(ComplimentaryServices::class)->customerExempt($customer))) {
            $this->reject();
        }
        $lock = Cache::lock('vpstc-resource-'.hash('sha256', $ref), 120);
        if (! $lock->get()) { $this->reject(); }
        try {
            $manifest = Setting::query()->whereKey('vpstc_catalog_manifest')->value('value');
            $quote = $this->catalog->quote((string) $plan->provider_ref);
            $price = CloudStoreController::priceForCycle($plan, 'monthly');
            $wantedPrice = $internal ? 0 : $price;
            if (! $quote || $price <= 0 || (int) ($data['price'] ?? -1) !== $wantedPrice
                || ($quote['currency'] ?? null) !== Setting::get('vpstc_currency')
                || ($quote['location_ref'] ?? '') !== (string) $plan->provider_location
                || (int) ($quote['cost_eur_cents'] ?? -1) !== (int) $plan->cost_eur_cents
                || (int) ($quote['setup_eur_cents'] ?? -1) !== (int) $plan->setup_eur_cents
                || ! app(CloudPricingSnapshot::class)->current('vpstc')) { $this->reject(); }
            $read = $this->api->service($ref);
            $remote = $read['data']['service'] ?? [];
            $form = $this->api->productOrderForm((string) $plan->provider_ref);
            $product = $form['data']['product'] ?? [];
            $created = $this->date($remote['date_created'] ?? null);
            $due = $this->date($remote['next_due'] ?? null);
            if (! ($read['ok'] ?? false) || ! ($form['ok'] ?? false)
                || (string) ($remote['id'] ?? '') !== $ref || ($remote['status'] ?? '') !== 'Active'
                || ($remote['billingcycle'] ?? '') !== 'Monthly' || ! $created || ! $due
                || ! $due->gt(now()->startOfDay()->addDays(5))
                || ! is_string($remote['domain'] ?? null) || $remote['domain'] === ''
                || (string) ($product['id'] ?? '') !== (string) $plan->provider_ref
                || ! is_string($product['name'] ?? null) || ($remote['name'] ?? null) !== $product['name']
                || ! in_array($remote['cancellation_request'] ?? null, [null, false, '', [], 0, '0'], true)
                || ! $this->sameAmount($remote['total'] ?? null, $quote['monthly_amount'] ?? null)
                || ($data['activated_at'] ?? '') !== $created->toDateString()
                || ($data['next_due_at'] ?? '') !== $due->toDateString()) { $this->reject(); }
            $list = $this->api->vms($ref);
            $vms = $list['data']['vms'] ?? null;
            if (! ($list['ok'] ?? false) || ! is_array($vms) || count($vms) !== 1) { $this->reject(); }
            $one = reset($vms);
            $vmId = is_array($one) ? (string) ($one['vmid'] ?? $one['id'] ?? array_key_first($vms)) : '';
            if (! preg_match('/\A(?:vm-)?[1-9][0-9]{0,17}\z/D', $vmId)) { $this->reject(); }
            $vmRead = $this->api->vm($ref, $vmId); $vm = $vmRead['data']['vm'] ?? [];
            $ip = $vm['ipv4'] ?? null;
            if (is_array($ip) && count($ip) === 1) { $ip = reset($ip); }
            if (! ($vmRead['ok'] ?? false) || (string) ($vm['vmid'] ?? $vm['id'] ?? '') !== $vmId
                || $ip !== $data['expected_ipv4'] || ! in_array($vm['built'] ?? null, [true, 1, '1'], true)
                || ! in_array($vm['locked'] ?? false, [false, 0, '0', null, ''], true)
                || ! in_array($vm['status'] ?? '', ['running', 'stopped', 'off', 'poweredOff'], true)) { $this->reject(); }
            foreach (['cores' => 'vcpu', 'memory' => 'ram_mb', 'disk' => 'disk_gb'] as $native => $field) {
                if (! preg_match('/\A[1-9][0-9]*\z/D', (string) ($vm[$native] ?? ''))
                    || (int) $vm[$native] !== (int) $plan->$field
                    || (int) ($quote[$field] ?? -1) !== (int) $plan->$field) { $this->reject(); }
            }
            $template = trim((string) ($vm['template_name'] ?? ''));
            if (! preg_match('/\A(Ubuntu|Debian|AlmaLinux|Rocky Linux) ([0-9]+(?:\.[0-9]+)?)\z/iD', $template, $os)) { $this->reject(); }
            $image = CloudNaming::imageKey('os', $os[1], $os[2], $template);
            $password = $vm['password'] ?? null;
            if (! is_string($password) || $password === '' || preg_match('/\A[\*•]+\z/uD', $password)
                || ! is_string($vm['username'] ?? null) || $vm['username'] === '') { $this->reject(); }
            $key = hash('sha256', 'vpstc:attach:'.$this->api->accountFingerprint().':'.$ref);
            return DB::transaction(function () use ($customer, $plan, $data, $actor, $internal, $ref, $manifest,
                $quote, $wantedPrice, $remote, $created, $due, $vmId, $vm, $ip, $image, $password, $key) {
                if (CloudProviderOperation::where('operation_key', $key)->exists()
                    || CloudInstance::where('provider', 'vpstc')->where('provider_ref', $ref)->lockForUpdate()->exists()
                    || CloudInstance::where('ipv4', $ip)->lockForUpdate()->exists()
                    || $manifest !== Setting::query()->whereKey('vpstc_catalog_manifest')->value('value')
                    || (int) $plan->price_irt !== (int) CloudPlan::whereKey($plan->id)->value('price_irt')) { $this->reject(); }
                $service = Service::create([
                    'customer_id' => $customer->id, 'name' => $data['name'], 'currency_code' => 'IRT',
                    'price' => $wantedPrice, 'tax_percent' => $internal ? 0 : CloudStoreController::taxPercent(),
                    'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done',
                    'activated_at' => $created, 'provisioned_at' => now(), 'next_due_at' => $due,
                    'cloud_plan_id' => $plan->id, 'cloud_image_key' => $image, 'created_by' => $actor,
                    'server_ip' => $ip, 'username' => $vm['username'], 'password' => $password,
                    'provision_meta' => ['existing_paid_period' => ['source' => 'owner_confirmed_external_payment',
                        'recorded_at' => now()->toIso8601String(), 'paid_through' => $due->toDateString()],
                        'internal_infrastructure' => $internal, 'internal_infrastructure_customer' => $internal ? $customer->id : null],
                ]);
                $instance = new CloudInstance([
                    'service_id' => $service->id, 'provider' => 'vpstc', 'provider_ref' => $ref,
                    'location_code' => $plan->location_code, 'image_key' => $image, 'hostname' => $remote['domain'],
                    'ipv4' => $ip, 'ipv6' => filter_var($vm['ipv6'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ?: null,
                    'status' => $vm['status'] === 'running' ? 'running' : 'off', 'synced_at' => now(),
                    'ready_notified_at' => now(), 'meta' => ['vpstc_supplier_due_at' => $due->toDateString()],
                    'specs' => ['vcpu' => $plan->vcpu, 'ram_mb' => $plan->ram_mb, 'disk_gb' => $plan->disk_gb],
                ]);
                $instance->setPassword($password); $instance->save();
                $spec = array_map('strval', ['name' => 'sn-svc-'.$service->id, 'plan_ref' => $plan->provider_ref,
                    'location_ref' => $plan->provider_location, 'image_ref' => $plan->provider_ref.'|'.$image,
                    'vcpu' => $plan->vcpu, 'ram_mb' => $plan->ram_mb, 'disk_gb' => $plan->disk_gb,
                    'cpu_kind' => $plan->cpu_kind, 'term' => 'month']); ksort($spec);
                CloudProviderOperation::create(['operation_key' => $key, 'provider' => 'vpstc', 'action' => 'attach',
                    'resource_ref' => $ref, 'state' => 'complete', 'payload' => ['_account' => $this->api->accountFingerprint(),
                        '_spec' => $spec, '_native' => ['service_id' => $ref, 'vm_id' => $vmId, 'domain' => $remote['domain'],
                            'customer_id' => $customer->id, 'local_service_id' => $service->id, 'ipv4' => $ip],
                        '_supplier_due_at' => $due->toDateString(),
                        '_access' => ['username' => $vm['username'], 'password' => $password]]]);
                ActivityLog::forService($service, 'purchase', 'سرور موجود به حساب متصل شد؛ پرداخت دورهٔ جاری با تأیید مالک و بدون ثبت تراکنش تازه. سررسید: '.$due->toDateString(), 'staff');
                return $service;
            });
        } finally { $lock->release(); }
    }

    private function sameAmount(mixed $a, mixed $b): bool
    {
        $a = VpsTcInvoiceAmounts::decimal($a); $b = VpsTcInvoiceAmounts::decimal($b);
        return $a !== null && $b !== null && $a->isPositive() && $a->compareTo($b) === 0;
    }

    private function date(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $raw)) { return null; }
        try { $date = Carbon::createFromFormat('!Y-m-d', $raw); return $date?->toDateString() === $raw ? $date : null; }
        catch (\Throwable) { return null; }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['provider_ref' => 'مالکیت، منابع، IP، قیمت جاری یا دورهٔ پرداخت‌شدهٔ سرور موجود تطبیق نشد؛ اتصال انجام نشد.']);
    }
}
