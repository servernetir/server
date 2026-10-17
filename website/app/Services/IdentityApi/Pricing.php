<?php

namespace App\Services\IdentityApi;

use App\Models\Customer;
use App\Models\IdentityPrice;
use App\Models\TaxRate;

class Pricing
{
    public function quote(IdentityPrice $p, ?Customer $customer = null): array
    {
        if ($p->valid_until->isPast() || $p->effective_at->isFuture() || ! in_array($p->source_currency, ['IRR', 'IRT'], true)
            || $p->billing_policy !== 'valid_data_v1' || ! $p->evidence || ! $p->access_evidence
            || ($p->source_amount === 0 && ! $p->confirmed_zero_cost) || $p->daily_quota < 1
            || ! $p->purchase_tax_included || $p->purchase_tax_amount > $p->source_amount
            || $p->source_amount > 1000000000000 || $p->markup_bps > 10000) {
            throw new IdentityError('price_unavailable', 503);
        }
        $divisor = $p->source_currency === 'IRR' ? 10 : 1;
        $sell = $this->ceil($p->source_amount * (10000 + $p->markup_bps), 10000 * $divisor);
        $country = strtoupper((string) ($customer?->getAttribute('country_code') ?: 'IR'));
        $taxRate = TaxRate::where('is_active', true)->where('country', $country)->where('product_kind', 'identity')
            ->whereNull('customer_type')->orderByDesc('priority')->orderByDesc('id')->first()
            ?? TaxRate::resolve($country, null, null);
        if (! $taxRate || $country === 'IR' && $taxRate->country !== 'IR') {
            throw new IdentityError('tax_unavailable', 503);
        }
        $taxBps = (int) $taxRate->rate_bp;
        if ($taxBps < 0 || $taxBps > 10000) {
            throw new IdentityError('tax_unavailable', 503);
        }
        $tax = $this->ceil($sell * $taxBps, 10000);

        return ['version' => $p->id, 'source_amount' => $p->source_amount, 'source_currency' => $p->source_currency, 'source_minor_unit' => 1,
            'purchase_tax_included' => true, 'purchase_tax_amount' => $p->purchase_tax_amount,
            'cost_irt' => $this->ceil($p->source_amount, $divisor), 'fx_numerator' => 1, 'fx_denominator' => $divisor,
            'markup_bps' => $p->markup_bps, 'sell_irt' => $sell, 'tax_bps' => $taxBps, 'tax_rate_id' => $taxRate->id, 'tax_irt' => $tax, 'total_irt' => $sell + $tax,
            'currency' => 'IRT', 'rounding' => 'ceil_once_before_output_tax', 'billable_unit' => 'execute', 'billing_policy' => $p->billing_policy,
            'evidence' => $p->evidence, 'access_evidence' => $p->access_evidence, 'quoted_at' => now()->toIso8601String()];
    }

    private function ceil(int $n, int $d): int
    {
        return intdiv($n + $d - 1, $d);
    }
}
