<?php

namespace App\Services\Billing;

use App\Http\Controllers\Admin\ServiceController;
use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Http\Controllers\Account\CloudStoreController;
use App\Services\Cloud\CloudAddons;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\Cloud\VpsTcRenewal;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Select future billing terms without granting any unpaid service time. */
class ServiceRenewals
{
    // These allocations continue on usage billing; a retail prepayment does not
    // require changing a supplier term. Prepaid/unknown drivers stay excluded.
    private const CONTINUOUS_PROVIDERS = ['hetzner', 'arvan', 'proxmox', 'lightnode', 'salad'];

    public function eligible(Service $s): bool
    {
        $base = in_array($s->status, ['active', 'suspended'], true) && $s->provision_status === 'done'
            && ! $s->isHourly() && ! $s->isInternalInfrastructure()
            && ! $s->hasUnverifiedBillingTerms() && ! app(ComplimentaryServices::class)->applies($s)
            && $s->activated_at && $s->next_due_at && $s->isRecurring()
            && $s->currency_code === 'IRT';

        return $base && ($s->isCloud() ? $this->cloudContext($s) !== null
            : in_array($s->server?->type, ['whm', 'plesk', 'directadmin'], true) && filled($s->plan));
    }

    /** Recorded binding only. Rendering must never contact a supplier or provision anything. */
    private function cloudContext(Service $s): ?array
    {
        $plan = CloudPlan::find($s->cloud_plan_id);
        $instance = CloudInstance::where('service_id', $s->id)->first();
        if (! $plan || ! $instance || $plan->provider !== $instance->provider
            || $plan->location_code !== $instance->location_code || blank($instance->provider_ref)
            || ! $instance->isDelivered()
            || (! in_array($instance->provider, self::CONTINUOUS_PROVIDERS, true) && $instance->provider !== 'vpstc')
            || filled(data_get($instance->meta, 'provider_lifecycle_review'))
            || app(VpsTcRenewal::class)->pending($s)) {
            return null;
        }
        // The existing prepaid settlement reconciler can prove exactly one month.
        // Longer terms must not be sold until native multi-period reconciliation exists.
        if ($instance->provider === 'vpstc'
            && ($s->cycle !== 'monthly' || blank(data_get($instance->meta, 'vpstc_supplier_due_at')))) {
            return null;
        }

        return ['plan' => $plan, 'instance' => $instance];
    }

    public function unavailableReason(Service $s): string
    {
        if ($s->isHourly()) { return __('renewal.hourly_notice'); }
        if ($s->isCloud()) {
            return __($this->cloudContext($s) ? 'renewal.cloud_quote_unavailable' : 'renewal.supplier_review');
        }

        return __('ui.sr_unavailable');
    }

    private function cloudOptions(Service $s): array
    {
        $context = $this->cloudContext($s);
        if (! $context) { return []; }
        $plan = $context['plan'];
        // Existing stock/manual sales switches do not decide whether an allocated
        // server can renew. A current, non-quarantined price still must be known.
        if ((int) $plan->price_irt <= 0 || ! app(CloudPricingSnapshot::class)->current($plan->provider)
            || in_array((int) $plan->id, CloudPricingSnapshot::excludedPlanIds(), true)
            || $s->tax_percent < 0 || $s->tax_percent > 100) { return []; }
        $addons = (array) $s->cloud_addons;
        $count = filter_var($addons['extra_ipv4'] ?? 0, FILTER_VALIDATE_INT);
        if (array_diff(array_keys($addons), ['extra_ipv4']) !== [] || $count === false
            || $count < 0 || $count > CloudAddons::MAX_EXTRA_IP
            || ($count > 0 && app(CloudAddons::class)->monthlyToman($addons) <= 0)) { return []; }
        $out = [];
        foreach (CloudStoreController::cycles() as $cycle) {
            // The shared checkout pricing applies supplier-specific period limits,
            // discounts, addon pricing and cost floors (including no term discount).
            $price = CloudStoreController::priceForCycle($plan, $cycle, $addons);
            if ($price <= 0) { continue; }
            $tax = (int) round($price * $s->tax_percent / 100);
            $out[$cycle] = ['cycle' => $cycle, 'basis' => 'cloud_catalog', 'product_id' => null,
                'cloud_plan_id' => (int) $plan->id, 'price' => $price,
                'tax_percent' => (int) $s->tax_percent, 'tax' => $tax, 'total' => $price + $tax];
        }

        return $out;
    }

    public function product(Service $s): ?Product
    {
        $rows = Product::where('plan', $s->plan)->where('is_active', true)->get()
            ->filter(fn ($p) => $p->hostingServerType() === $s->server?->type
                && $p->isReseller() === (bool) $s->is_reseller
                && $p->currency_code === $s->currency_code
                && (! $p->server_id || (int) $p->server_id === (int) $s->server_id)
                && ((array) $p->locations === [] || in_array(strtoupper((string) $s->server?->country), array_map('strtoupper', $p->locations), true)));

        return $rows->count() === 1 ? $rows->first() : null;
    }

    public function options(Service $s): array
    {
        if (! $this->eligible($s)) { return []; }
        if ($s->isCloud()) { return $this->cloudOptions($s); }
        if (! ($product = $this->product($s))) { return []; }
        $out = [];
        foreach ($product->orderCycles() as $cycle) {
            if (Service::monthsIn($cycle) <= 0) { continue; }
            $price = $product->priceForCycle($cycle, $s->server->country);
            $basis = 'catalog';
            // A known monthly hosting contract can renew without inventing FX.
            // Never reconstruct an old prepaid discount or use this for new sales.
            if ($price <= 0 && $s->cycle === 'monthly' && Service::monthsIn('monthly') === 1
                && (int) config('billing.cycles.monthly.discount_pct', 0) === 0 && $s->price > 0) {
                $discount = (int) config('billing.cycles.'.$cycle.'.discount_pct', 0);
                if ($discount < 0 || $discount >= 100) { continue; }
                $price = BigInteger::of($s->price)->multipliedBy(Service::monthsIn($cycle))
                    ->multipliedBy(100 - $discount)->dividedBy(100, RoundingMode::HalfUp)->toInt();
                $basis = 'contract';
            }
            if ($price <= 0 || $product->tax_percent < 0 || $product->tax_percent > 100) { continue; }
            $tax = (int) round($price * $product->tax_percent / 100);
            $out[$cycle] = ['cycle' => $cycle, 'basis' => $basis, 'product_id' => (int) $product->id,
                'price' => $price, 'tax_percent' => (int) $product->tax_percent,
                'tax' => $tax, 'total' => $price + $tax];
        }

        return $out;
    }

    public function quote(Service $s, string $cycle): array
    {
        $q = $this->options($s)[$cycle] ?? null;
        if (! $q) { $this->invalid(); }
        if (app(HostingUpgrades::class)->open($s)) { $this->invalid('sr_busy'); }
        $invoices = $s->invoices()->outstanding()->orderBy('id')->get();
        foreach ($invoices as $invoice) {
            // Never replace money, coupons, bank evidence or an in-flight gateway attempt.
            if ($invoice->kind !== 'service' || ! in_array($invoice->status, ['unpaid', 'draft'], true)
                || $invoice->paid > 0 || $invoice->discount > 0
                || $invoice->payments()->whereIn('status', ['pending', 'redirected'])->exists()) {
                $this->invalid('sr_busy');
            }
            foreach (['bank_transfer_receipts' => ['pending'], 'crypto_payments' => ['pending', 'seen', 'review']] as $table => $statuses) {
                if (Schema::hasTable($table) && DB::table($table)->where('invoice_id', $invoice->id)->whereIn('status', $statuses)->exists()) {
                    $this->invalid('sr_busy');
                }
            }
        }

        $cloud = $s->isCloud() ? $this->cloudContext($s) : null;
        return $q + ['cloud_binding' => $cloud ? [
            'instance_id' => $cloud['instance']->id, 'provider' => $cloud['instance']->provider,
            'reference' => $cloud['instance']->provider_ref, 'location' => $cloud['instance']->location_code,
            'plan_id' => $cloud['plan']->id, 'plan_ref' => $cloud['plan']->provider_ref,
            'addons' => (array) $s->cloud_addons,
            'supplier_due' => data_get($cloud['instance']->meta, 'vpstc_supplier_due_at'),
        ] : null, 'from' => ['id' => $s->id, 'customer' => $s->customer_id,
            'plan' => $s->plan, 'server' => $s->server_id, 'status' => $s->status,
            'cycle' => $s->cycle, 'price' => $s->price, 'tax' => $s->tax_percent,
            'due' => $s->next_due_at->toDateString()],
            'invoices' => $invoices->map(fn ($i) => [$i->id, $i->status, $i->total, $i->paid, $i->updated_at?->toIso8601String()])->all()];
    }

    public function hash(array $quote): string
    {
        return hash_hmac('sha256', json_encode($quote), (string) config('app.key'));
    }

    public function issue(Service $service, string $cycle, array $preview, string $hash): Invoice
    {
        return DB::transaction(function () use ($service, $cycle, $preview, $hash) {
            $s = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $last = data_get($s->provision_meta, 'renewal_selection');
            if (is_array($last) && ($last['hash'] ?? null) === $hash) {
                $existing = $s->invoices()->outstanding()->whereKey($last['invoice_id'] ?? 0)->first();
                if ($existing) { return $existing; }
            }
            // Lock invoices as well: settlement/cancellation must finish before replacement.
            $s->invoices()->outstanding()->orderBy('id')->lockForUpdate()->get();
            if ($s->isCloud()) {
                CloudInstance::where('service_id', $s->id)->lockForUpdate()->get();
                CloudPlan::whereKey($s->cloud_plan_id)->lockForUpdate()->get();
            }
            $fresh = $this->quote($s, $cycle);
            if (! $preview || ! hash_equals($this->hash($preview), $hash) || $fresh !== $preview) {
                $this->invalid('sr_changed');
            }
            foreach ($s->invoices()->outstanding()->limit(1)->get() as $invoice) {
                if (! app(InvoiceCanceller::class)->cancel($invoice, __('ui.sr_replaced'), false)) {
                    $this->invalid('sr_busy');
                }
            }
            // This is the customer's future billing preference, not activation or free renewal.
            // The ordinary settlement path advances the original due date only after payment.
            $s->forceFill(['cycle' => $cycle, 'price' => $fresh['price'], 'tax_percent' => $fresh['tax_percent']])->save();
            $invoice = app(ServiceController::class)->issueInvoice($s);
            if ($invoice->subtotal !== $fresh['price'] || $invoice->total !== $fresh['total']) {
                $this->invalid('sr_changed');
            }
            $item = $invoice->items()->first();
            $item?->forceFill(app(\App\Services\Marketing\InvoiceContext::class)
                ->attributes($s, 'renewal', $s->isCloud() ? null : $this->product($s))
                + ($fresh['basis'] === 'contract' ? ['description' => trim((string) $item->description.' '. __('ui.sr_contract'))] : []))->save();
            $meta = (array) $s->provision_meta;
            $meta['renewal_selection'] = ['hash' => $hash, 'invoice_id' => $invoice->id];
            $s->forceFill(['provision_meta' => $meta])->save();
            ActivityLog::forService($s, 'renew', 'Customer selected billing cycle '.$cycle.'; invoice '.$invoice->number.' issued; due date unchanged.', 'customer');

            return $invoice;
        }, 3);
    }

    private function invalid(string $key = 'sr_unavailable'): never
    {
        throw ValidationException::withMessages(['renewal' => __('ui.'.$key)]);
    }
}
