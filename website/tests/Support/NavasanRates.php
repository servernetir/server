<?php

namespace Tests\Support;

use App\Models\Setting;
use App\Services\ExchangeRate;
use Illuminate\Support\Facades\Cache;

final class NavasanRates
{
    /** A synthetic, internally consistent catalogue quote; never fetches live FX. */
    public static function cloudSnapshot(array $rates = ['USD' => 100000, 'EUR' => 100000, 'USDT' => 100000]): void
    {
        self::store($rates);
        app(\App\Services\Cloud\CloudPricingSnapshot::class)->publish([
            $rates['USD'], $rates['EUR'], $rates['USDT'],
        ]);
    }

    public static function rows(array $rates = ['USD' => 100000, 'EUR' => 200000, 'USDT' => 150000], ?string $at = null): array
    {
        $rows = [];
        foreach ($rates as $currency => $rate) {
            $rows[$currency] = ['currency' => $currency, 'rate_toman' => $rate, 'source' => 'navasan.net',
                'source_item' => ExchangeRate::SOURCE_ITEMS[$currency],
                'source_at' => $at ?? now()->toIso8601String(), 'at' => $at ?? now()->toIso8601String()];
        }
        return $rows;
    }

    public static function store(array $rates = ['USD' => 100000, 'EUR' => 200000, 'USDT' => 150000], ?string $at = null): array
    {
        $rows = self::rows($rates, $at);
        foreach ($rows as $currency => $row) {
            Setting::put('fx_last_'.strtolower($currency), json_encode($row, JSON_THROW_ON_ERROR));
        }
        Cache::put('fx.navasan.rates', $rows, now()->addHours(6));
        return $rows;
    }
}
