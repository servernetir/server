<?php

namespace Tests\Unit;

use App\Services\Marketing\DiscountCalculator;
use Tests\TestCase;

class MarketingDiscountMathTest extends TestCase
{
    public function test_large_integer_allocations_do_not_overflow_or_lose_minor_units(): void
    {
        $rule = ['type' => 'fixed', 'value' => 500_000_000_000, 'max_discount' => 500_000_000_000,
            'min_purchase' => 0, 'currency' => 'IRT', 'locales' => ['fa'], 'operations' => ['purchase'],
            'subsidy_approved' => true];
        $lines = [];
        foreach ([333_333_333_333, 333_333_333_333, 333_333_333_334] as $i => $amount) {
            $lines[] = ['id' => $i + 1, 'line_total' => $amount, 'discount' => 0, 'tax_rate_bp' => 1000,
                'context' => ['eligible' => true, 'operation' => 'purchase']];
        }
        $quote = (new DiscountCalculator)->calculate($rule, $lines, 'IRT', 'fa', 'purchase');
        $this->assertSame(500_000_000_000, array_sum(array_column($quote['allocations'], 'discount')));
        $this->assertNull($quote['cost']);
        foreach ($quote['allocations'] as $i => $line) {
            $this->assertLessThanOrEqual($lines[$i]['line_total'], $line['discount']);
            $this->assertGreaterThanOrEqual(0, $line['tax_amount']);
        }
    }

    public function test_percentage_cap_rounds_down_discount_and_rounds_tax_half_up(): void
    {
        $rule = ['type' => 'percent', 'value' => 3333, 'max_discount' => 1000,
            'min_purchase' => 0, 'currency' => 'EUR', 'locales' => ['en'], 'operations' => ['purchase'],
            'subsidy_approved' => true];
        $quote = (new DiscountCalculator)->calculate($rule, [
            ['id' => 1, 'line_total' => 101, 'discount' => 0, 'tax_rate_bp' => 1000,
                'context' => ['eligible' => true, 'operation' => 'purchase']],
        ], 'EUR', 'en', 'purchase');
        $this->assertSame(33, $quote['amount']);
        $this->assertSame(7, $quote['allocations'][0]['tax_amount']);
    }

    public function test_explicit_stacking_only_discounts_the_remaining_line_price(): void
    {
        $rule = ['type' => 'percent', 'value' => 2000, 'max_discount' => 10000,
            'min_purchase' => 0, 'currency' => 'IRT', 'locales' => ['fa'], 'operations' => ['purchase'],
            'subsidy_approved' => true, 'stackable' => true];
        $quote = (new DiscountCalculator)->calculate($rule, [
            ['id' => 1, 'line_total' => 1000, 'discount' => 100, 'tax_rate_bp' => 1000,
                'context' => ['eligible' => true, 'operation' => 'purchase']],
        ], 'IRT', 'fa', 'purchase');
        $this->assertSame(180, $quote['amount']);
        $this->assertSame(280, $quote['allocations'][0]['discount']);
        $this->assertSame(72, $quote['allocations'][0]['tax_amount']);
    }
}
