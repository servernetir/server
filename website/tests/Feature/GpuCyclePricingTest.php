<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudPlan;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\SaladClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GpuCyclePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_gpu_cycles_cover_current_supplier_cost_and_markup_without_vps_discounts(): void
    {
        \Tests\Support\NavasanRates::cloudSnapshot(['USD' => 267700, 'EUR' => 299900, 'USDT' => 267700]);
        Setting::put('cloud_margin_pct_salad', '30');
        Setting::put('pricing_fx_fee_pct_salad', '10');
        Setting::put('salad_org', 'pricing-fixture');
        Setting::putSecret('salad_api_key', 'isolated-key');
        $cost = 0.33 * 267700 * 1.1;
        $plan = new CloudPlan([
            'provider' => 'salad', 'price_irt' => 92130000,
            'cost_hour_eur_micro' => (int) round($cost / 299900 * 1000000),
        ]);
        Http::fake(['api.salad.com/*' => Http::response(['items' => [[
            'id' => 'gc-4090', 'is_high_demand' => false,
            'prices' => [['priority' => 'high', 'price' => '0.33']],
        ]]])]);
        $quote = app(SaladClient::class)->purchaseQuote('gc-4090');
        $this->assertTrue($quote['ok']);
        foreach (CloudStoreController::cycles() as $cycle) {
            $amount = CloudStoreController::priceForCycle($plan, $cycle);
            $effective = $amount / Service::monthsIn($cycle) / SaladClient::HOURS_PER_MONTH;
            $this->assertGreaterThanOrEqual($quote['minimum_hourly_irt'], $effective, $cycle);
        }
    }

    public function test_gpu_cycle_discount_labels_are_zero_and_vps_discounts_are_preserved(): void
    {
        config(['billing.cycles.yearly.discount_pct' => 20]);
        $gpu = new CloudPlan(['provider' => 'salad', 'price_irt' => 100000]);
        $vps = new CloudPlan(['provider' => 'hetzner', 'price_irt' => 100000]);
        $this->assertSame(1200000, CloudStoreController::priceForCycle($gpu, 'yearly'));
        $this->assertSame(960000, CloudStoreController::priceForCycle($vps, 'yearly'));
    }
}
