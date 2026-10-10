<?php

namespace App\Services\Domain;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Operation-specific supplier quotes; no catalogue or registration-price fallback. */
class LiveDomainPricing
{
    private ?float $verifiedVat = null;

    public function __construct(private OpenProviderClient $op) {}

    public function unavailable(): never
    {
        throw ValidationException::withMessages(['domain' => __('domain-pricing.unavailable')]);
    }

    public function supplierMultiplier(): float
    {
        $vat = $this->verifiedVat ?? Setting::get('domain_supplier_vat_pct') ?? config('domain_pricing.supplier_vat_pct');
        $fee = Setting::get('domain_supplier_fee_pct') ?? config('domain_pricing.supplier_fee_pct', 0);
        foreach ([$vat, $fee] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 100) {
                $this->unavailable();
            }
        }

        return (1 + (float) $vat / 100) * (1 + (float) $fee / 100);
    }

    public function fromAmount(float $net, string $currency, string $tld): array
    {
        $rate = app(DomainSearch::class)->rateFor($currency);
        if (! is_finite($net) || $net <= 0 || ! $rate || $rate <= 0) {
            $this->unavailable();
        }
        $gross = $net * $this->supplierMultiplier();
        $wholesale = (int) ceil($gross * $rate);
        $margin = max(0, (float) config('domain_reseller.min_margin_pct', 8));
        $step = max(1, (int) (Currency::find('IRT')?->rounding_step ?: 1000));
        $floor = (int) (ceil($wholesale * (1 + $margin / 100) / $step) * $step);
        $sellMargin = max($margin, app(DomainSearch::class)->marginFor($tld));

        return [
            'net_amount' => $net, 'currency' => $currency,
            'cost_amount' => (int) ceil($gross * 100),
            'wholesale_toman' => $wholesale, 'floor_toman' => $floor,
            'sell_toman' => max($floor, (int) (ceil($wholesale * (1 + $sellMargin / 100) / $step) * $step)),
        ];
    }

    public function quote(string $sld, string $tld, string $operation, int $years = 1): array
    {
        if (! in_array($operation, ['create', 'renew', 'transfer', 'restore'], true)
            || ! DomainSearch::sells($tld) || $sld === '' || $years < 1 || $years > 10
            || (in_array($operation, ['transfer', 'restore'], true) && $years !== 1)) {
            $this->unavailable();
        }
        // Account prices exclude VAT. Read the account's actual tax rate for each order quote.
        $account = $this->op->resellerInfo();
        $vat = data_get($account, 'data.vatperc');
        if (! ($account['ok'] ?? false) || ! is_numeric($vat)
            || ! is_finite((float) $vat) || $vat < 0 || $vat > 100) {
            $this->unavailable();
        }
        // A configured reserve covers supplier charges observed outside the API.
        $reserve = Setting::get('domain_supplier_vat_pct') ?? config('domain_pricing.supplier_vat_pct');
        if ($reserve !== null && (! is_numeric($reserve) || ! is_finite((float) $reserve)
            || $reserve < 0 || $reserve > 100)) {
            $this->unavailable();
        }
        $this->verifiedVat = max((float) $vat, (float) ($reserve ?? 0));
        $this->supplierMultiplier();
        $res = $this->op->domainPrice($sld, $tld, $operation, $operation === 'create' ? $years : 1);
        $price = data_get($res, 'data.price.reseller');
        if (! ($res['ok'] ?? false) || ! is_array($price)
            || ! is_numeric($price['price'] ?? null)
            || ! preg_match('/^[A-Z]{3}$/', strtoupper((string) ($price['currency'] ?? '')))) {
            $this->unavailable();
        }
        // Openprovider documents period as create-only: renew is a unit quote.
        $amount = (float) $price['price'] * ($operation === 'renew' ? $years : 1);
        $quote = $this->fromAmount($amount, strtoupper($price['currency']), $tld);
        if ($operation === 'restore') {
            // Reserve the separate renewal too; some registries require it after restore.
            $quote['restore_wholesale_toman'] = $quote['wholesale_toman'];
            $renew = $this->quote($sld, $tld, 'renew');
            foreach (['wholesale_toman', 'floor_toman', 'sell_toman'] as $key) {
                $quote[$key] += $renew[$key];
            }
        }

        return $quote + ['operation' => $operation, 'years' => $years,
            'supplier_vat_pct' => $this->verifiedVat, 'quoted_at' => now()->toIso8601String()];
    }

    public function remember(Domain $domain, array $quote): void
    {
        $domain->putMeta(['live_price' => $quote]);
    }

    public function invoiceOperation(Domain $domain, Invoice $invoice): array
    {
        $meta = (array) $domain->meta;
        if ((int) ($meta['restore_invoice_id'] ?? 0) === $invoice->id) {
            return ['restore', 1];
        }
        if ((int) ($meta['renew_invoice_id'] ?? 0) === $invoice->id) {
            return ['renew', $domain->renewYears()];
        }
        if ((int) $invoice->domain_id === $domain->id && ! $domain->isActive()) {
            return [$domain->order_type === 'transfer' ? 'transfer' : 'create', max(1, (int) $domain->period_years)];
        }
        $this->unavailable();
    }

    /** A mixed invoice must identify the domain line; hosting revenue cannot cover its floor. */
    private function domainItem(Domain $domain, Invoice $invoice): ?InvoiceItem
    {
        $items = $invoice->items()->get();
        $matches = $items->filter(fn ($item) => in_array($item->title, [
            'ثبتِ دامنهٔ '.$domain->domain, 'تمدیدِ دامنهٔ '.$domain->domain,
            'انتقالِ دامنهٔ '.$domain->domain, 'بازیابیِ دامنهٔ '.$domain->domain,
        ], true));
        if ($matches->count() === 1 && $matches->first()->quantity === 1) {
            return $matches->first();
        }
        // Older domain-only invoices can lack item rows, but mixed/ambiguous invoices fail closed.
        if (! $invoice->service_id && $invoice->kind === 'domain' && $items->count() <= 1) {
            if ($items->isEmpty()) {
                return null;
            }
            if ($items->first()->quantity === 1) {
                return $items->first();
            }
        }
        $this->unavailable();
    }

    private function domainNetAmount(Domain $domain, Invoice $invoice): int
    {
        $item = $this->domainItem($domain, $invoice);
        $header = min((int) $invoice->subtotal, (int) $invoice->total - max(0, (int) $invoice->tax));

        return $item ? min($header, (int) $item->line_total, (int) $item->unit_price) : $header;
    }

    /** Pending receipts and supplier payment attempts freeze the agreed invoice amount. */
    public function hasPaymentInFlight(Invoice $invoice): bool
    {
        if ($invoice->payments()->whereIn('status', ['pending', 'redirected', 'paid'])->exists()) {
            return true;
        }
        if (Schema::hasTable('bank_transfer_receipts') && DB::table('bank_transfer_receipts')
            ->where('invoice_id', $invoice->id)->whereIn('status', ['pending', 'approved'])->exists()) {
            return true;
        }
        return Schema::hasTable('crypto_payments') && DB::table('crypto_payments')
            ->where('invoice_id', $invoice->id)->where(fn ($q) => $q
                ->whereIn('status', ['pending', 'seen', 'confirmed', 'manual', 'unmatched'])
                ->orWhere('received_atomic', '>', 0))->exists();
    }

    /** Refresh an unpaid quote before displaying it for the customer's review. */
    public function reviewInvoice(Invoice $invoice): void
    {
        if (! $invoice->domain_id || ! in_array($invoice->status, ['draft', 'unpaid', 'partial'], true)) {
            return;
        }
        DB::transaction(function () use ($invoice) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($fresh->status, ['draft', 'unpaid', 'partial'], true)) {
                return;
            }
            $domain = Domain::whereKey($fresh->domain_id)->lockForUpdate()->first();
            if (! $domain || $fresh->currency_code !== 'IRT'
                || (int) $fresh->customer_id !== (int) $domain->customer_id) {
                $this->unavailable();
            }
            [$operation, $years] = $this->invoiceOperation($domain, $fresh);
            $quote = $this->quote($domain->sld, $domain->tld, $operation, $years);
            if ($this->domainNetAmount($domain, $fresh) >= $quote['floor_toman']) {
                return;
            }
            if ($fresh->paid > 0 || $this->hasPaymentInFlight($fresh)) {
                throw ValidationException::withMessages(['domain' => __('domain-pricing.changed')]);
            }
            $subtotal = $quote['sell_toman'];
            $pct = CloudStoreController::taxPercent();
            $tax = (int) round($subtotal * $pct / 100);
            $item = $this->domainItem($domain, $fresh);
            if ($item) {
                $item->update(['unit_price' => $subtotal, 'line_total' => $subtotal,
                    'tax_rate_bp' => (int) ($pct * 100), 'tax_amount' => $tax]);
                $subtotal = (int) $fresh->items()->sum('line_total');
                $tax = (int) $fresh->items()->sum('tax_amount');
            }
            $fresh->update(['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax]);
            $this->remember($domain, $quote);
            if ($operation === 'renew') {
                $domain->update(['renew_toman' => (int) ceil($quote['sell_toman'] / $years)]);
            }
        });
        $invoice->refresh();
    }

    /** Read-only guard: a changed quote must be reviewed, never silently charged. */
    public function assertInvoice(Invoice $invoice): void
    {
        if (! $invoice->domain_id || $invoice->status === 'paid') {
            return;
        }
        $domain = Domain::find($invoice->domain_id);
        if (! $domain || (int) $invoice->customer_id !== (int) $domain->customer_id || $invoice->currency_code !== 'IRT') {
            $this->unavailable();
        }
        [$operation, $years] = $this->invoiceOperation($domain, $invoice);
        $quote = $this->quote($domain->sld, $domain->tld, $operation, $years);
        if ($this->domainNetAmount($domain, $invoice) < $quote['floor_toman']) {
            throw ValidationException::withMessages(['domain' => __('domain-pricing.changed')]);
        }
    }

    /** Last guard immediately before any billable supplier operation. */
    public function assertDelivery(Domain $domain, string $operation, int $years = 1): void
    {
        $id = match ($operation) {
            'renew' => data_get($domain->meta, 'renew_invoice_id'),
            'restore' => data_get($domain->meta, 'restore_invoice_id'),
            default => $domain->invoice_id,
        };
        $invoice = $id ? Invoice::find($id) : null;
        if (! $invoice && in_array($operation, ['create', 'transfer'], true)) {
            $invoice = Invoice::where('domain_id', $domain->id)->where('status', 'paid')->latest('id')->first();
        }
        if (! $invoice || $invoice->status !== 'paid' || $invoice->currency_code !== 'IRT'
            || (int) $invoice->domain_id !== (int) $domain->id
            || (int) $invoice->customer_id !== (int) $domain->customer_id
            || (int) $invoice->paid < (int) $invoice->total) {
            $this->unavailable();
        }
        $quote = $this->quote($domain->sld, $domain->tld, $operation, $years);
        if ($this->domainNetAmount($domain, $invoice) < $quote['floor_toman']) {
            throw ValidationException::withMessages(['domain' => __('domain-pricing.changed')]);
        }
        $this->remember($domain, $quote);
    }
}
