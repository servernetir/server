<?php

namespace App\Services\Billing;

use App\Http\Controllers\Admin\ServiceController;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Select future billing terms without granting any unpaid service time. */
class ServiceRenewals
{
    public function eligible(Service $s): bool
    {
        return in_array($s->status, ['active', 'suspended'], true) && $s->provision_status === 'done'
            && ! $s->isCloud() && ! $s->isHourly() && ! $s->isInternalInfrastructure()
            && ! $s->hasUnverifiedBillingTerms() && ! app(ComplimentaryServices::class)->applies($s)
            && in_array($s->server?->type, ['whm', 'plesk', 'directadmin'], true)
            && $s->activated_at && $s->next_due_at && $s->isRecurring()
            && $s->currency_code === 'IRT' && filled($s->plan);
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
        if (! $this->eligible($s) || ! ($product = $this->product($s))) {
            return [];
        }
        $out = [];
        foreach ($product->orderCycles() as $cycle) {
            if (Service::monthsIn($cycle) <= 0) { continue; }
            $price = $product->priceForCycle($cycle, $s->server->country);
            if ($price <= 0 || $product->tax_percent < 0 || $product->tax_percent > 100) { continue; }
            $tax = (int) round($price * $product->tax_percent / 100);
            $out[$cycle] = ['cycle' => $cycle, 'product_id' => (int) $product->id,
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

        return $q + ['from' => ['id' => $s->id, 'customer' => $s->customer_id,
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
            $invoice->items()->first()?->forceFill(app(\App\Services\Marketing\InvoiceContext::class)
                ->attributes($s, 'renewal', $this->product($s)))->save();
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
