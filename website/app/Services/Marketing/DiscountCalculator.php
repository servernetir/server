<?php

namespace App\Services\Marketing;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class DiscountCalculator
{
    /** All amounts are integer minor units. No FX conversion or browser supplied price. */
    public function calculate(array $rule, array $lines, string $currency, string $locale, string $operation): array
    {
        $fail = fn (string $key) => throw ValidationException::withMessages(['code' => __('marketing.'.$key)]);
        if ($currency !== $rule['currency'] || ! in_array($locale, $rule['locales'], true)) {
            $fail('currency');
        }
        if (! in_array($operation, $rule['operations'], true)) {
            $fail('scope');
        }
        $eligible = [];
        foreach ($lines as $line) {
            $ctx = $line['context'] ?? [];
            if (! ($ctx['eligible'] ?? false) || ($ctx['operation'] ?? null) !== $operation) {
                continue;
            }
            $matches = true;
            foreach (['product_ids' => 'product_id', 'families' => 'family', 'providers' => 'provider',
                'countries' => 'country', 'plans' => 'plan'] as $filter => $field) {
                if (! empty($rule[$filter]) && ! in_array($ctx[$field] ?? null, $rule[$filter], true)) {
                    $matches = false;
                }
            }
            if (! $matches) {
                continue;
            }
            if (($line['discount'] ?? 0) > 0 && ! ($rule['stackable'] ?? false)) {
                $fail('stacking');
            }
            $base = max(0, (int) $line['line_total'] - (int) ($line['discount'] ?? 0));
            if ($base > 0) {
                $eligible[] = $line + ['base' => $base];
            }
        }
        $base = array_sum(array_column($eligible, 'base'));
        if ($base > 1000000000000) {
            $fail('state');
        }
        if ($base <= 0) {
            $fail('scope');
        }
        if ($base < $rule['min_purchase']) {
            $fail('minimum');
        }
        $amount = $rule['type'] === 'percent'
            ? intdiv($base * $rule['value'], 10000) : $rule['value'];
        $amount = min($amount, $base, $rule['max_discount']);
        if ($amount <= 0) {
            $fail('scope');
        }
        $remaining = $amount;
        $remainingBase = $base;
        $allocations = [];
        $knownCost = true;
        $cost = 0;
        foreach ($eligible as $line) {
            $part = BigInteger::of($remaining)->multipliedBy($line['base'])
                ->dividedBy($remainingBase, RoundingMode::Down)->toInt();
            $remaining -= $part;
            $remainingBase -= $line['base'];
            $context = $line['context'];
            if (! isset($context['cost_minor']) || ($context['cost_currency'] ?? null) !== $currency) {
                $knownCost = false;
            } else {
                $cost += (int) $context['cost_minor'];
            }
            $net = $line['base'] - $part;
            // Same frozen tax basis and round-half-up as ProductInvoiceIssuer.
            $tax = intdiv($net * (int) $line['tax_rate_bp'] + 5000, 10000);
            $allocations[] = ['id' => $line['id'], 'discount' => (int) ($line['discount'] ?? 0) + $part,
                'tax_amount' => $tax];
        }
        $net = $base - $amount;
        $safeMargin = $knownCost && ($net - $cost) * 10000 >= $net * ($rule['min_margin_bp'] ?? 0);
        if (! $safeMargin && empty($rule['subsidy_approved'])) {
            $fail($knownCost ? 'margin' : 'unknown_cost');
        }

        return ['amount' => $amount, 'eligible_base' => $base, 'allocations' => $allocations,
            'cost' => $knownCost ? $cost : null, 'profit' => $knownCost ? $net - $cost : null];
    }
}
