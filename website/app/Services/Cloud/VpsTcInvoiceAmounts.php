<?php

namespace App\Services\Cloud;

use Brick\Math\BigDecimal;

/** Supplier credit reduces total due, never the gross cost of the billed service. */
final class VpsTcInvoiceAmounts
{
    /**
     * Validate the native-currency monetary envelope without interpreting FX rates.
     * A Paid card invoice may retain a positive post-credit total; status is
     * verified separately by callers. Credit-settled invoices may have total=0.
     *
     * @return array{gross:string,due:string,credit:string,subtotal:string,tax:string,tax2:string}|null
     */
    public static function inspect(array $invoice): ?array
    {
        $amounts = [];
        foreach (['total', 'credit', 'subtotal', 'tax'] as $key) {
            $amounts[$key] = self::decimal($invoice[$key] ?? null);
            if ($amounts[$key] === null) { return null; }
        }
        $amounts['tax2'] = array_key_exists('tax2', $invoice) ? self::decimal($invoice['tax2']) : BigDecimal::zero();
        if ($amounts['tax2'] === null) { return null; }
        // This contract has no verified separate discount arithmetic. Do not
        // silently subtract an unfamiliar adjustment from procurement cost.
        foreach (['discount', 'discount_amount', 'discount_total'] as $key) {
            if (array_key_exists($key, $invoice)) {
                $discount = self::decimal($invoice[$key]);
                if ($discount === null || ! $discount->isZero()) { return null; }
            }
        }
        if (array_key_exists('discounts', $invoice) && $invoice['discounts'] !== []) {
            return null;
        }
        $gross = $amounts['subtotal']->plus($amounts['tax'])->plus($amounts['tax2']);
        if (! $gross->isPositive() || $gross->compareTo($amounts['total']->plus($amounts['credit'])) !== 0) {
            return null;
        }
        if (array_key_exists('grandtotal', $invoice)) {
            $grand = self::decimal($invoice['grandtotal']);
            if ($grand === null || $grand->compareTo($gross) !== 0) { return null; }
        }
        return ['gross' => (string) $gross, 'due' => (string) $amounts['total'],
            'credit' => (string) $amounts['credit'], 'subtotal' => (string) $amounts['subtotal'],
            'tax' => (string) $amounts['tax'], 'tax2' => (string) $amounts['tax2']];
    }

    /** The cap always covers gross, including money already consumed as credit. */
    public static function bounded(array $invoice, mixed $ceiling): ?array
    {
        $amounts = self::inspect($invoice);
        $limit = self::decimal($ceiling);
        return $amounts !== null && $limit !== null && $limit->isPositive()
            && BigDecimal::of($amounts['gross'])->compareTo($limit) <= 0 ? $amounts : null;
    }

    public static function decimal(mixed $value): ?BigDecimal
    {
        if ((! is_int($value) && ! is_string($value) && ! is_float($value))
            || (is_float($value) && ! is_finite($value))
            || ! preg_match('/\A(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?\z/D', (string) $value)) {
            return null;
        }
        try { return BigDecimal::of((string) $value); }
        catch (\Throwable) { return null; }
    }
}
