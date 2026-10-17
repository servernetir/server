<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Services\CatalogPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class NavasanProductPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Cache::forget('fx.navasan.rates');
        Setting::put('pricing_baseline_rate', '304000');
        Setting::put('pricing_rate_override', '4000000');
        Setting::put('price_margin_pct', '10');
    }

    public function test_native_product_prices_use_only_the_stored_navasan_eur_rate(): void
    {
        NavasanRates::store(['USD' => 100000, 'EUR' => 200000, 'USDT' => 150000]);
        $product = Product::create([
            'name' => 'Native plan', 'slug' => 'native-plan', 'category' => 'shared', 'group' => 'linux',
            'price' => 99999999, 'price_eur' => 1, 'setup_fee' => 88888888,
            'base_price_eur' => '1.750000000000', 'base_setup_eur' => '0.500000000000',
            'cycle' => 'monthly', 'tax_percent' => 0, 'is_active' => true,
        ]);

        $this->assertSame(390000, $product->effectivePrice());
        $this->assertSame(110000, $product->effectiveSetup());
        $this->assertSame(308333, $product->monthlyEquivalent('yearly'));
        $this->assertSame(390000, app(CatalogPricing::class)->minimumQuote('linux')['irt']);
        $this->assertNull(site_price_toman(['irt' => 99999999]));
    }

    public function test_missing_navasan_rate_closes_native_product_pricing(): void
    {
        $product = new Product(['base_price_eur' => '10', 'base_setup_eur' => '2', 'cycle' => 'monthly']);
        $this->assertSame(0, $product->effectivePrice());
        $this->assertSame(0, $product->effectiveSetup());
        $this->assertNull(site_price_toman(['base_eur' => 10]));
    }
}
