<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudPricing;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\Cloud\CloudCatalogRepricer;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudProvider;
use App\Services\Cloud\VpsTcPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class VpsTcPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::store();
    }

    private function inputs(array $overrides = []): array
    {
        $values = array_replace([
            'vpstc_currency' => 'TRY', 'cloud_margin_pct_vpstc' => '20',
            'vpstc_supplier_tax_pct' => '20', 'pricing_fx_fee_pct_vpstc' => '5',
            'vpstc_fx_rate_toman' => '5000',
        ], $overrides);
        foreach ($values as $key => $value) {
            Setting::put($key, $value);
        }
        return $values;
    }

    public function test_missing_account_inputs_never_inherit_global_profit_or_foreign_exchange_fees(): void
    {
        Setting::put('cloud_margin_pct', '45');
        Setting::put('pricing_fx_fee_pct', '10');
        $pricing = app(VpsTcPricing::class);
        $this->assertFalse($pricing->configured());
        $this->assertNull($pricing->costEurCents('100', 'TRY'));
        $this->assertSame(0, app(CloudPricing::class)->sellEurCents(1000, 'vpstc'));
        $this->assertSame(1450, app(CloudPricing::class)->sellEurCents(1000, 'hetzner'));
        $this->assertContains('vpstc', app(CloudPricingSnapshot::class)->excludedProviders());
        Http::assertNothingSent();
    }

    public function test_lira_cost_adds_supplier_tax_and_funding_fee_once_then_separate_profit(): void
    {
        $this->inputs();
        $pricing = app(VpsTcPricing::class);
        // TRY100 * IRT5000 / IRT200000 per EUR * 1.20 * 1.05 = EUR3.15.
        $this->assertTrue($pricing->configured());
        $this->assertSame(315, $pricing->costEurCents('100', 'TRY'));
        $this->assertSame(380, app(CloudPricing::class)->sellEurCents(315, 'vpstc'));
        $this->assertSame(760000, app(CloudPricing::class)->priceFor(315, 'vpstc')['irt']);
        Http::assertNothingSent();
    }

    public function test_zero_percentages_are_explicit_valid_inputs_and_setup_zero_stays_zero(): void
    {
        $this->inputs(['cloud_margin_pct_vpstc' => '0', 'vpstc_supplier_tax_pct' => '0',
            'pricing_fx_fee_pct_vpstc' => '0']);
        $pricing = app(VpsTcPricing::class);
        $this->assertTrue($pricing->configured());
        $this->assertSame(250, $pricing->costEurCents(100, 'TRY'));
        $this->assertSame(0, $pricing->costEurCents(0, 'TRY', true));
        $this->assertNull($pricing->costEurCents(0, 'TRY'));
        $this->assertSame(250, app(CloudPricing::class)->sellEurCents(250, 'vpstc'));
    }

    public function test_setup_uses_this_suppliers_profit_instead_of_the_global_cloud_margin(): void
    {
        Setting::put('cloud_margin_pct', '45');
        $this->inputs(['cloud_margin_pct_vpstc' => '20']);
        $vpstc = new CloudPlan(['provider' => 'vpstc', 'setup_eur_cents' => 1000]);
        $hetzner = new CloudPlan(['provider' => 'hetzner', 'setup_eur_cents' => 1000]);
        $this->assertSame(2400000, $vpstc->setupIrt());
        $this->assertSame(2900000, $hetzner->setupIrt());
        Setting::put('cloud_margin_pct_vpstc', '0');
        $this->assertSame(2000000, $vpstc->setupIrt());
        Setting::put('cloud_margin_pct_vpstc', '');
        $this->assertSame(0, $vpstc->setupIrt());
    }

    public function test_manual_rate_cannot_be_applied_to_another_currency_or_unknown_amount(): void
    {
        $this->inputs();
        $pricing = app(VpsTcPricing::class);
        foreach (['USD', 'EUR', 'try', '$', ''] as $currency) {
            $this->assertNull($pricing->costEurCents(100, $currency));
        }
        foreach (['1,000', 'unknown', -1, INF, NAN, '1e309'] as $amount) {
            $this->assertNull($pricing->costEurCents($amount, 'TRY'));
        }
    }

    public function test_invalid_manual_override_or_supplier_inputs_close_pricing(): void
    {
        $pricing = app(VpsTcPricing::class);
        foreach ([
            ['vpstc_fx_rate_toman' => '0'],
            ['vpstc_fx_rate_toman' => '1e309'], ['cloud_margin_pct_vpstc' => ''],
            ['vpstc_supplier_tax_pct' => '-1'], ['pricing_fx_fee_pct_vpstc' => '101'],
            ['vpstc_currency' => 'GBP'],
        ] as $invalid) {
            $this->inputs($invalid);
            $this->assertFalse($pricing->configured());
            $this->assertNull($pricing->costEurCents(100, 'TRY'));
            $this->assertSame(0, app(CloudPricing::class)->sellEurCents(100, 'vpstc'));
        }
    }

    public function test_supported_currency_uses_current_navasan_when_manual_rate_is_blank(): void
    {
        $this->inputs(['vpstc_currency' => 'USD', 'vpstc_fx_rate_toman' => '',
            'vpstc_supplier_tax_pct' => '0', 'pricing_fx_fee_pct_vpstc' => '0']);
        $pricing = app(VpsTcPricing::class);
        $this->assertSame(500, $pricing->costEurCents(10, 'USD'));
        NavasanRates::store(['USD' => 120000, 'EUR' => 200000, 'USDT' => 150000]);
        $this->assertSame(600, $pricing->costEurCents(10, 'USD'));
        Http::assertNothingSent();
    }

    public function test_lira_defaults_to_the_central_navasan_rate_and_only_try_changes_close_its_snapshot(): void
    {
        $this->inputs(['vpstc_fx_rate_toman' => '']);
        $pricing = app(VpsTcPricing::class);
        $this->assertTrue($pricing->configured());
        $this->assertSame(315, $pricing->costEurCents(100, 'TRY'));
        $snapshot = app(CloudPricingSnapshot::class);
        $snapshot->publish([100000, 200000, 150000], [], ['vpstc' => $pricing->fingerprint()]);
        $this->assertTrue($snapshot->current('vpstc'));
        NavasanRates::store(['USD' => 100000, 'EUR' => 200000, 'USDT' => 150000, 'TRY' => 6000]);
        $this->assertSame(378, $pricing->costEurCents(100, 'TRY'));
        $this->assertFalse($snapshot->current('vpstc'));
        $this->assertContains('vpstc', $snapshot->excludedProviders());
        $this->assertTrue($snapshot->current('hetzner'));
        $snapshot->publish([100000, 200000, 150000], [], ['vpstc' => $pricing->fingerprint()]);
        $this->assertTrue($snapshot->current('vpstc'));
        Http::assertNothingSent();
    }

    public function test_missing_central_try_closes_blank_override_without_network_or_a_fixed_default(): void
    {
        $this->inputs(['vpstc_fx_rate_toman' => '']);
        NavasanRates::store(['USD' => 100000, 'EUR' => 200000, 'USDT' => 150000]);
        Setting::put('fx_last_try', null);
        $pricing = app(VpsTcPricing::class);
        $this->assertFalse($pricing->configured());
        $this->assertNull($pricing->costEurCents(100, 'TRY'));
        $this->assertSame(0, $pricing->sellEurCents(100));
        Http::assertNothingSent();
    }

    public function test_explicit_supplier_override_remains_optional_and_independent_of_central_try(): void
    {
        $this->inputs(['vpstc_fx_rate_toman' => '5000']);
        $pricing = app(VpsTcPricing::class);
        $fingerprint = $pricing->fingerprint();
        NavasanRates::store(['USD' => 100000, 'EUR' => 200000, 'USDT' => 150000, 'TRY' => 6000]);
        $this->assertSame(315, $pricing->costEurCents(100, 'TRY'));
        $this->assertSame($fingerprint, $pricing->fingerprint());
        Http::assertNothingSent();
    }

    public function test_valid_durable_try_fallback_preserves_the_price_input_fingerprint(): void
    {
        $this->inputs(['vpstc_fx_rate_toman' => '']);
        $pricing = app(VpsTcPricing::class);
        $fingerprint = $pricing->fingerprint();
        \Illuminate\Support\Facades\Cache::forget('fx.navasan.rates');
        $this->assertSame($fingerprint, $pricing->fingerprint());
        $this->assertSame(315, $pricing->costEurCents(100, 'TRY'));
        Http::assertNothingSent();
    }

    public function test_try_change_during_supplier_quote_collection_cannot_publish_mixed_costs(): void
    {
        $this->inputs(['vpstc_fx_rate_toman' => '']);
        $plan = CloudPlan::create([
            'provider' => 'vpstc', 'provider_ref' => 'fx-race', 'location_code' => 'tr-istanbul',
            'public_name' => 'CV-1', 'slug' => 'try-race', 'vcpu' => 1, 'ram_mb' => 1024,
            'disk_gb' => 20, 'cost_eur_cents' => 100, 'price_eur_cents' => 120, 'price_irt' => 240000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ]);
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchCatalog')->once()->andReturnUsing(function () {
            NavasanRates::store(['USD' => 100000, 'EUR' => 200000, 'USDT' => 150000, 'TRY' => 6000]);
            return ['ok' => true, 'plans' => [['provider_ref' => 'fx-race', 'location_code' => 'tr-istanbul',
                'cost_eur_cents' => 315, 'cost_hour_eur_micro' => null, 'setup_eur_cents' => 0]]];
        });
        $this->mock(CloudManager::class)->shouldReceive('driver')->with('vpstc')->once()->andReturn($driver);
        try {
            app(CloudCatalogRepricer::class)->run();
            $this->fail('A TRY rate change during a native quote must not publish a mixed batch.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('exchange inputs changed', $error->getMessage());
        }
        $this->assertSame(100, (int) $plan->fresh()->cost_eur_cents);
        $this->assertSame(240000, (int) $plan->fresh()->price_irt);
        Http::assertNothingSent();
    }

    public function test_input_changes_invalidate_only_this_suppliers_price_snapshot(): void
    {
        $this->inputs();
        $snapshot = app(CloudPricingSnapshot::class);
        $snapshot->publish([100000, 200000, 150000], [], ['vpstc' => app(VpsTcPricing::class)->fingerprint()]);
        $this->assertTrue($snapshot->current('vpstc'));
        $this->assertNotContains('vpstc', $snapshot->excludedProviders());
        Setting::put('vpstc_fx_rate_toman', '6000');
        $this->assertFalse($snapshot->current('vpstc'));
        $this->assertContains('vpstc', $snapshot->excludedProviders());
        $this->assertTrue($snapshot->current('hetzner'));
        $snapshot->publish([100000, 200000, 150000], [], ['vpstc' => app(VpsTcPricing::class)->fingerprint()]);
        $this->assertTrue($snapshot->current('vpstc'));
        Setting::putSecret('cloud_vpstc_username', 'different-reseller');
        $this->assertFalse($snapshot->current('vpstc'));
        $this->assertTrue($snapshot->current('hetzner'));
        $this->assertStringNotContainsString('different-reseller', Setting::get(CloudPricingSnapshot::KEY));
    }

    public function test_private_money_evidence_update_only_invalidates_the_vpstc_supplier_proof(): void
    {
        $this->inputs();
        $pricing = app(VpsTcPricing::class);
        $snapshot = app(CloudPricingSnapshot::class);
        $snapshot->publish([100000, 200000, 150000], [], ['vpstc' => $pricing->fingerprint()]);
        Setting::putSecret(\App\Services\Cloud\VpsTcMoneyContract::KEY, '{"reviewed":"new private evidence"}');
        $this->assertFalse($snapshot->current('vpstc'));
        $this->assertTrue($snapshot->current('hetzner'));
    }

    public function test_stale_positive_hourly_cost_never_authorizes_a_prepaid_vpstc_plan(): void
    {
        foreach ([null, 0, 5000] as $index => $cost) {
            $plan = CloudPlan::create([
                'provider' => 'vpstc', 'provider_ref' => 'p'.$index, 'location_code' => 'tr-istanbul',
                'public_name' => 'CV-1', 'slug' => 'monthly-'.$index, 'vcpu' => 1,
                'ram_mb' => 1024, 'disk_gb' => 20, 'disk_type' => 'nvme', 'traffic_gb' => 1000,
                'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 100,
                'cost_hour_eur_micro' => $cost, 'price_eur_cents' => 120, 'price_irt' => 240000,
                'is_active' => true, 'in_stock' => true,
            ]);
            $this->assertFalse($plan->supportsHourly());
        }
        $this->assertSame(0, CloudPlan::query()->hourlyCapable()->count());
    }

    public function test_global_snapshot_refresh_cannot_bless_costs_without_supplier_quote_evidence(): void
    {
        $this->inputs();
        $snapshot = app(CloudPricingSnapshot::class);
        $snapshot->publish([100000, 200000, 150000]);
        $this->assertTrue($snapshot->current('hetzner'));
        $this->assertFalse($snapshot->current('vpstc'));
        $this->assertContains('vpstc', $snapshot->excludedProviders());
        $snapshot->publish([100000, 200000, 150000], [], ['vpstc' => 'old-or-invented-digest']);
        $this->assertFalse($snapshot->current('vpstc'));
    }

    public function test_repricing_reads_fresh_supplier_cost_before_publishing_this_providers_inputs(): void
    {
        $this->inputs();
        $plan = CloudPlan::create([
            'provider' => 'vpstc', 'provider_ref' => 'p10', 'location_code' => 'tr-istanbul',
            'public_name' => 'CV-1', 'slug' => 'monthly', 'vcpu' => 1,
            'ram_mb' => 1024, 'disk_gb' => 20, 'disk_type' => 'nvme', 'traffic_gb' => 1000,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 100,
            'price_eur_cents' => 120, 'price_irt' => 240000, 'is_active' => true,
            'in_stock' => true, 'admin_disabled' => false,
        ]);
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchCatalog')->once()->andReturn(['ok' => true, 'plans' => [[
            'provider_ref' => 'p10', 'location_code' => 'tr-istanbul',
            'cost_eur_cents' => 500, 'cost_hour_eur_micro' => null, 'setup_eur_cents' => 0,
        ]]]);
        $this->mock(CloudManager::class)->shouldReceive('driver')->with('vpstc')->once()->andReturn($driver);
        $this->assertSame(1, app(CloudCatalogRepricer::class)->run());
        $this->assertSame(500, (int) $plan->fresh()->cost_eur_cents);
        $this->assertSame(600, (int) $plan->fresh()->price_eur_cents);
        $this->assertSame(1200000, (int) $plan->fresh()->price_irt);
        $this->assertTrue(app(CloudPricingSnapshot::class)->current('vpstc'));
        Http::assertNothingSent();
    }

    public function test_administrator_saves_independent_inputs_without_changing_credentials_or_making_orders(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
        Setting::putSecret('cloud_vpstc_password', 'existing-private-credential');
        $values = $this->inputs();
        $values['cloud_margin_pct_vpstc'] = '0';
        $this->post('/admin/cloud/vpstc/pricing', $values + ['password' => 'ignore-me'])
            ->assertRedirect('/admin/settings?tab=infra#infra-vpstc')->assertSessionHasNoErrors();
        $this->assertSame('0', Setting::get('cloud_margin_pct_vpstc'));
        $this->assertSame('existing-private-credential', Setting::getSecret('cloud_vpstc_password'));
        $this->get('/admin/settings?tab=infra')->assertOk()->assertSee('name="vpstc_fx_rate_toman"', false)
            ->assertDontSee('existing-private-credential');
        Http::assertNothingSent();
    }

    public function test_invalid_or_unauthorized_pricing_edit_preserves_existing_inputs(): void
    {
        $values = $this->inputs();
        $this->actingAs(User::factory()->create(['role' => 'support']), 'web');
        $this->post('/admin/cloud/vpstc/pricing', $values)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
        $this->post('/admin/cloud/vpstc/pricing', array_replace($values, ['vpstc_fx_rate_toman' => '0']))
            ->assertSessionHasErrors('vpstc_fx_rate_toman');
        $this->assertSame('5000', Setting::get('vpstc_fx_rate_toman'));
        $this->assertSame([], session()->getOldInput());
        $route = Route::getRoutes()->getByName('admin.cloud.vpstc.pricing');
        foreach (['web', 'auth:web', 'admin', 'throttle:6,1'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
        Http::assertNothingSent();
    }
}
