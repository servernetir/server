<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\{CloudCatalogRepricer, CloudCatalogSync, CloudManager, CloudPricingSnapshot, CloudProvider, CloudProvisioner, VpsTcPricing, VpsTcSales};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CloudPlanFailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['lightnode.sales_enabled' => true, 'lightnode.public_pricing_enabled' => true]);
        NavasanRates::store(['USD' => 100000, 'EUR' => 100000, 'USDT' => 100000]);
        foreach (['cloud_margin_pct' => '50', 'vpstc_currency' => 'TRY', 'cloud_margin_pct_vpstc' => '20',
            'vpstc_supplier_tax_pct' => '20', 'pricing_fx_fee_pct_vpstc' => '5', 'vpstc_fx_rate_toman' => '5000',
            'ovh_auto_order_enabled' => '1'] as $key => $value) { Setting::put($key, $value); }
        $this->mock(VpsTcSales::class)->shouldReceive('enabled')->andReturnTrue();
        app(CloudPricingSnapshot::class)->publish([100000, 100000, 100000], [], [
            'vpstc' => app(VpsTcPricing::class)->fingerprint(),
        ]);
    }

    private function plan(string $provider, string $ref, array $extra = []): CloudPlan
    {
        return CloudPlan::create($extra + ['provider' => $provider, 'provider_ref' => $ref,
            'provider_location' => 'region-one', 'location_code' => 'de-falkenstein', 'slug' => $provider.'-'.$ref,
            'public_name' => 'Existing plan', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 1000, 'cost_hour_eur_micro' => 14000,
            'setup_eur_cents' => 0, 'price_eur_cents' => 1500, 'price_irt' => 1500000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false]);
    }

    private function quote(CloudPlan $plan, array $extra = []): array
    {
        return $extra + ['provider_ref' => $plan->provider_ref, 'provider_location' => $plan->provider_location,
            'location_code' => $plan->location_code, 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 2000,
            'cost_hour_eur_micro' => 28000, 'setup_eur_cents' => 0];
    }

    public static function suppliers(): array
    {
        return array_combine(array_keys(CloudManager::DRIVERS), array_map(fn ($provider) => [$provider], array_keys(CloudManager::DRIVERS)));
    }

    public static function quoteFailures(): iterable
    {
        foreach (['arvan', 'lightnode', 'salad', 'ovh', 'vpstc'] as $provider) {
            foreach (['missing', 'invalid_price', 'missing_hourly', 'disk_changed', 'ram_changed',
                'region_changed', 'duplicate', 'malformed_extra', 'invalid_numeric_spec'] as $failure) {
                yield $provider.'-'.$failure => [$provider, $failure];
            }
        }
    }

    #[DataProvider('quoteFailures')]
    public function test_one_bad_quote_never_discards_verified_quotes_from_the_same_supplier(string $provider, string $failure): void
    {
        $good = $this->plan($provider, 'good');
        $bad = $this->plan($provider, 'bad');
        $before = $bad->fresh()->getRawOriginal();
        $badQuote = $this->quote($bad);
        $quotes = [$this->quote($good)];
        switch ($failure) {
            case 'invalid_price': $badQuote['cost_eur_cents'] = -1; break;
            case 'missing_hourly': unset($badQuote['cost_hour_eur_micro']); break;
            case 'disk_changed': $badQuote['disk_gb'] = 10; break;
            case 'ram_changed': $badQuote['ram_mb'] = 2048; break;
            case 'region_changed': $badQuote['provider_location'] = 'another-region'; break;
            case 'invalid_numeric_spec': $badQuote['vcpu'] = ['invalid']; break;
        }
        if ($failure !== 'missing') { $quotes[] = $badQuote; }
        if ($failure === 'duplicate') { $quotes[] = $badQuote; }
        if ($failure === 'malformed_extra') { $quotes[] = ['unidentifiable' => true]; }
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchCatalog')->once()->andReturn(['ok' => true, 'plans' => $quotes]);
        $this->mock(CloudManager::class)->shouldReceive('driver')->with($provider)->once()->andReturn($driver);

        app(CloudCatalogRepricer::class)->run();
        $snapshot = app(CloudPricingSnapshot::class);
        $this->assertTrue($snapshot->current($provider));
        $this->assertNotContains($provider, $snapshot->excludedProviders());
        $this->assertSame(2000, (int) $good->fresh()->cost_eur_cents);
        $this->assertFalse($good->fresh()->admin_disabled);
        $this->assertTrue(CloudPlan::sellable()->whereKey($good->id)->exists());
        if ($failure === 'malformed_extra') {
            $this->assertSame([], CloudPricingSnapshot::excludedPlanIds());
        } else {
            $this->assertSame($before, $bad->fresh()->getRawOriginal());
            $this->assertSame([$bad->id], CloudPricingSnapshot::excludedPlanIds());
            $this->assertFalse(CloudPlan::sellable()->whereKey($bad->id)->exists());
            $this->assertSame('price', $bad->fresh()->blockedReason());
        }
        Http::assertNothingSent();
    }

    #[DataProvider('suppliers')]
    public function test_rejected_order_quarantines_exactly_one_plan_for_every_supplier(string $provider): void
    {
        $bad = $this->plan($provider, 'bad');
        $good = $this->plan($provider, 'good');
        $manual = $this->plan($provider, 'manual', ['admin_disabled' => true, 'admin_note' => 'manual hold']);
        $method = new \ReflectionMethod(CloudProvisioner::class, 'quarantineProvider');
        $method->invoke(app(CloudProvisioner::class), $bad, 'invalid plan: price changed; insufficient resource quota');
        $this->assertTrue($bad->fresh()->admin_disabled);
        $this->assertFalse($good->fresh()->admin_disabled);
        $this->assertFalse(CloudPlan::providerIsDisabled($provider));
        $this->assertSame('manual hold', $manual->fresh()->admin_note);
        $this->assertTrue(CloudPlan::sellable()->whereKey($good->id)->exists());
        $this->assertStringStartsWith(CloudProvisioner::QUARANTINE_PREFIX, $bad->fresh()->admin_note);
        $settings = Setting::get('cloud_disabled_providers');
        $method->invoke(app(CloudProvisioner::class), $bad->fresh(), 'invalid plan: price changed');
        $this->assertSame($settings, Setting::get('cloud_disabled_providers'));
        Http::assertNothingSent();
    }

    #[DataProvider('suppliers')]
    public function test_gateway_error_strikes_from_different_plans_are_independent(string $provider): void
    {
        $one = $this->plan($provider, 'one'); $two = $this->plan($provider, 'two');
        $method = new \ReflectionMethod(CloudProvisioner::class, 'quarantineProvider');
        foreach ([$one, $two] as $plan) {
            $method->invoke(app(CloudProvisioner::class), $plan, 'Proxy internal server error');
            $this->assertFalse($plan->fresh()->admin_disabled);
        }
        $method->invoke(app(CloudProvisioner::class), $one, 'Proxy internal server error');
        $this->assertTrue($one->fresh()->admin_disabled);
        $this->assertFalse($two->fresh()->admin_disabled);
        $this->assertFalse(CloudPlan::providerIsDisabled($provider));
    }

    #[DataProvider('suppliers')]
    public function test_a_failed_product_sync_preserves_its_row_and_imports_healthy_peers(string $provider): void
    {
        $bad = $this->plan($provider, 'bad'); $good = $this->plan($provider, 'good');
        $before = $bad->fresh()->getRawOriginal();
        CloudPlan::saving(function (CloudPlan $plan) use ($provider) {
            if ($plan->provider === $provider && $plan->provider_ref === 'bad') {
                throw new \RuntimeException('Simulated isolated product write failure');
            }
        });
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('fetchCatalog')->once()->andReturn(['ok' => true,
            'locations' => [], 'images' => [], 'plans' => [$this->quote($bad), $this->quote($good)]]);
        $this->mock(CloudManager::class)->shouldReceive('configured')->once()->andReturn([$provider => $driver]);
        $result = app(CloudCatalogSync::class)->sync($provider);
        $this->assertTrue($result['providers'][$provider]['ok']);
        $this->assertSame(1, $result['providers'][$provider]['plans']);
        $this->assertSame($before, $bad->fresh()->getRawOriginal());
        $this->assertSame([$bad->id], CloudPricingSnapshot::excludedPlanIds());
        $this->assertSame(2000, (int) $good->fresh()->cost_eur_cents);
        $this->assertTrue(app(CloudPricingSnapshot::class)->current($provider));
        $this->assertFalse(CloudPlan::providerIsDisabled($provider));
        $this->assertFalse($good->fresh()->admin_disabled);
        Http::assertNothingSent();
    }
}
