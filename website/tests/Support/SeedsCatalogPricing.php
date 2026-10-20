<?php

namespace Tests\Support;

use App\Models\Setting;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\ExchangeRate;

trait SeedsCatalogPricing
{
    protected function seedCatalogPricing(int $rate = 100000): void
    {
        foreach (['USD', 'EUR', 'USDT'] as $currency) {
            Setting::put('fx_last_'.strtolower($currency), json_encode([
                'rate_toman' => $rate, 'currency' => $currency,
                'at' => now()->toIso8601String(), 'source' => 'navasan.net',
                'source_item' => ExchangeRate::SOURCE_ITEMS[$currency],
                'source_at' => now()->toIso8601String(),
            ]));
        }
        app(CloudPricingSnapshot::class)->publish([$rate, $rate, $rate]);
    }
}
