<?php

namespace Tests\Feature;

use App\Models\{CloudPlan, Setting};
use App\Services\Cloud\{CloudPricing, SaladClient};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class GpuPurchaseQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Setting::putSecret('salad_api_key', 'test-only');
        Setting::put('salad_org', 'quote-test');
        Setting::put('pricing_usd_rate_override', '100000');
        Setting::put('pricing_rate_override', '110000');
        Setting::put('cloud_margin_pct', '5');
        Setting::put('cloud_margin_pct_salad', '30');
        Setting::put('pricing_fx_fee_pct_salad', '10');
    }

    private function classes(array $over = []): void
    {
        Http::fake(['api.salad.com/*' => Http::response(['items' => [array_replace([
            'id' => 'gpu-test', 'name' => 'RTX 4090', 'is_high_demand' => false,
            'prices' => [['priority' => 'high', 'price' => '0.5'], ['priority' => 'low', 'price' => '0.1']],
        ], $over)]] )]);
    }

    public function test_gpu_markup_is_scoped_and_empty_setting_falls_back(): void
    {
        $pricing = app(CloudPricing::class);
        $this->assertSame(30.0, $pricing->marginPct('salad'));
        $this->assertSame(5.0, $pricing->marginPct('hetzner'));
        $this->assertSame(130, $pricing->sellEurCents(100, 'salad'));
        Setting::put('cloud_margin_pct_salad', '');
        $this->assertSame(5.0, $pricing->marginPct('salad'));
        Setting::put('cloud_margin_pct_salad', '0');
        $this->assertSame(0.0, $pricing->marginPct('salad'));
    }

    public function test_quote_uses_gpu_tariff_fx_fee_and_gpu_markup(): void
    {
        $this->classes();
        $quote = app(SaladClient::class)->purchaseQuote('gpu-test');
        $this->assertTrue($quote['ok']);
        $this->assertEqualsWithDelta(55000, $quote['cost_hourly_irt'], 1);
        $this->assertEqualsWithDelta(71500, $quote['minimum_hourly_irt'], 1);
    }

    public function test_current_fx_and_priority_are_applied_to_cached_classes(): void
    {
        $this->classes();
        $client = app(SaladClient::class);
        $this->assertTrue($client->purchaseQuote('gpu-test')['ok']);
        Setting::put('pricing_usd_rate_override', '200000');
        Setting::put('salad_priority', 'low');
        $this->assertEqualsWithDelta(28600, $client->purchaseQuote('gpu-test')['minimum_hourly_irt'], 1);
        Http::assertSentCount(1);
    }

    public function test_high_demand_can_be_quoted_but_exact_capacity_still_blocks_allocation(): void
    {
        // Since capacity intents were introduced, demand is not a pricing veto.
        // A valid quote must never imply available hardware or trigger a purchase.
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/gpu-classes')) {
                return Http::response(['items' => [['id' => 'gpu-test', 'is_high_demand' => true,
                    'prices' => [['priority' => 'high', 'price' => '0.5']]]]]);
            }
            if (str_contains($request->url(), '/availability/')) {
                return Http::response(['available_gpu_high' => 0]);
            }
            throw new \RuntimeException('Unexpected provider call in quote test');
        });
        $client = app(SaladClient::class);
        $this->assertTrue($client->purchaseQuote('gpu-test')['ok']);
        $plan = new CloudPlan(['provider' => 'salad', 'provider_ref' => 'gpu-test',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 150]);
        $capacity = $client->capacityFor($plan, true);
        $this->assertTrue($capacity['ok']);
        $this->assertFalse($capacity['available']);
        $this->assertSame(['ok' => false, 'reason' => 'capacity'], $client->purchaseQuote('removed'));
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/containers'));
    }

    public function test_provider_failure_is_not_cached_as_a_success(): void
    {
        Http::fake(['api.salad.com/*' => Http::sequence()->push([], 503)->push(['items' => [[
            'id' => 'gpu-test', 'prices' => [['priority' => 'high', 'price' => '0.5']],
        ]]], 200)]);
        $client = app(SaladClient::class);
        $this->assertSame(['ok' => false, 'reason' => 'unavailable'], $client->purchaseQuote('gpu-test'));
        $this->assertTrue($client->purchaseQuote('gpu-test')['ok']);
    }

    public function test_unknown_tariff_or_missing_configuration_blocks_purchase(): void
    {
        $this->classes(['prices' => [['priority' => 'high', 'price' => '']]]);
        $client = app(SaladClient::class);
        $this->assertFalse($client->purchaseQuote('gpu-test')['ok']);
        Setting::put('salad_org', '');
        $this->assertFalse($client->purchaseQuote('gpu-test')['ok']);
    }
}
