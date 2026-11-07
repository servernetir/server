<?php

namespace App\Services\Domain;

use App\Services\Domain\Pricing\DomainMoney;
use Illuminate\Support\Carbon;

/** Display-only comparison of the same offer before/within an evidenced promotion. */
final class DomainPromotion
{
    public static function empty(): array
    {
        return ['is_promotion' => false, 'regular_price_toman' => null,
            'discount_percent' => null, 'promotion_ends_at' => null];
    }

    public static function retail(int $current, ?int $regular, mixed $startsAt, mixed $endsAt): array
    {
        try {
            $start = Carbon::parse($startsAt); $end = Carbon::parse($endsAt);
            if (! is_string($startsAt) || ! is_string($endsAt) || $current < 1 || $regular === null
                || $regular <= $current || $start->isFuture() || ! $end->isFuture() || $end->lte($start)) return self::empty();
            return ['is_promotion' => true, 'regular_price_toman' => $regular,
                'discount_percent' => intdiv(DomainMoney::multiply($regular - $current, 100), $regular),
                'promotion_ends_at' => $end->toIso8601String()];
        } catch (\Throwable) { return self::empty(); }
    }
}
