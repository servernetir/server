<?php

namespace App\Services\Billing;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** The shared payment window for customer initial orders and unfunded server intents. */
final class OrderPaymentWindow
{
    public static function hours(?int $override = null): int
    {
        return max(1, (int) ($override ?: config('billing.order_expiry_hours', 72)));
    }

    public static function deadline(CarbonInterface $createdAt): Carbon
    {
        return Carbon::instance($createdAt)->addHours(self::hours());
    }
}
