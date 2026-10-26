<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudCatalogRepricer;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\Cloud\LightNodeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class LightNodePartialRepricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['lightnode.sales_enabled' => true, 'lightnode.public_pricing_enabled' => true]);
        Setting::put('cloud_margin_pct', '50');
        NavasanRates::store(['USD' => 100000, 'EUR' => 100000, 'USDT' => 100000]);
        app(CloudPricingSnapshot::class)->publish([100000, 100000, 100000]);
        NavasanRates::store(['USD' => 110000, 'EUR' => 200000, 'USDT' => 150000]);
    }

    private function plan(string $provider, string $reference, array $extra = []): CloudPlan
    {
        return CloudPlan::create($extra + [
            'provider' => $provider, 'provider_ref' => $reference, 'provider_location' => 'eu-west|zone-1',
            'location_code' => 'de-falkenstein', 'public_name' => 'Existing plan', 'slug' => $reference,
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20,
            'cost_eur_cents' => 1000, 'cost_hour_eur_micro' => 14000, 'setup_eur_cents' => 0,
            'price_eur_cents' => 1500, 'price_irt' => 1500000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ]);
    }

    private function quote(CloudPlan $plan, int $cost = 2000): array
    {
        return ['provider_ref' => $plan->provider_ref, 'location_code' => $plan->location_code,
            'cost_eur_cents' => $cost, 'cost_hour_eur_micro' => 28000, 'setup_eur_cents' => 500];
    }

    private function driver(array $catalog, array $retry, array $missingIds): void
    {
        $driver = \Mockery::mock(LightNodeClient::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchCatalog')->once()->andReturn(['ok' => true, 'plans' => $catalog]);
        $driver->shouldReceive('fetchExistingPlanCosts')->once()
            ->with(\Mockery::on(fn ($plans) => collect($plans)->pluck('id')->all() === $missingIds))
            ->andReturn($retry);
        $this->mock(CloudManager::class)->shouldReceive('driver')->with('lightnode')->once()->andReturn($driver);
    }

    public function test_missing_exact_quote_excludes_only_lightnode_and_keeps_every_old_lightnode_attribute(): void
    {
        $native = $this->plan('lightnode', 'known-native', ['admin_note' => 'Keep existing stock']);
        $missing = $this->plan('lightnode', 'missing-native');
        $eur = $this->plan('hetzner', 'fresh-peer');
        $before = [$native->fresh()->getRawOriginal(), $missing->fresh()->getRawOriginal()];
        // Even the native row with a new quote must not be partly repriced.
        $this->driver([$this->quote($native, 9999)], ['ok' => true, 'plans' => []], [$missing->id]);

        $this->assertSame(1, app(CloudCatalogRepricer::class)->run());
        $this->assertSame($before, [$native->fresh()->getRawOriginal(), $missing->fresh()->getRawOriginal()]);
        $this->assertSame(3000000, (int) $eur->fresh()->price_irt);
        $snapshot = app(CloudPricingSnapshot::class);
        $this->assertTrue($snapshot->current());
        $this->assertTrue($snapshot->current('hetzner'));
        $this->assertFalse($snapshot->current('lightnode'));
        $this->assertContains('lightnode', $snapshot->excludedProviders());
        $this->assertSame([$eur->id], CloudPlan::query()->sellable()->pluck('id')->all());
        $this->assertFalse(CloudPlan::offers()->has($missing->slug));
        $this->assertNull(CloudPlan::bestForSlug($missing->slug));
        $this->assertSame('price', CloudPlan::shelf()->get($missing->slug)->blockedReason());
        $this->assertTrue($missing->fresh()->in_stock);
        $this->assertTrue($missing->fresh()->is_active);
        $this->assertFalse($missing->fresh()->admin_disabled);
        $this->assertNull(Setting::get('cloud_disabled_providers'));
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
    }

    public function test_failed_exact_retry_preserves_lightnode_but_does_not_abort_verified_peers(): void
    {
        $native = $this->plan('lightnode', 'missing-native', ['in_stock' => false]);
        $eur = $this->plan('hetzner', 'fresh-peer');
        $before = $native->fresh()->getRawOriginal();
        $this->driver([], ['ok' => false, 'plans' => [], 'message' => 'Upstream private text must not be logged'], [$native->id]);

        $this->assertSame(1, app(CloudCatalogRepricer::class)->run());
        $this->assertSame($before, $native->fresh()->getRawOriginal());
        $this->assertSame(3000000, (int) $eur->fresh()->price_irt);
        $this->assertTrue(app(CloudPricingSnapshot::class)->current('hetzner'));
        $this->assertFalse(app(CloudPricingSnapshot::class)->current('lightnode'));
        Http::assertNothingSent();
    }

    public function test_successful_exact_retry_reprices_lightnode_and_restores_its_snapshot_eligibility(): void
    {
        $known = $this->plan('lightnode', 'known-native');
        $missing = $this->plan('lightnode', 'missing-native');
        $this->driver([$this->quote($known)], ['ok' => true, 'plans' => [$this->quote($missing, 2500)]], [$missing->id]);

        $this->assertSame(2, app(CloudCatalogRepricer::class)->run());
        $this->assertSame(6000000, (int) $known->fresh()->price_irt);
        $this->assertSame(7500000, (int) $missing->fresh()->price_irt);
        $this->assertTrue(app(CloudPricingSnapshot::class)->current('lightnode'));
        $this->assertNotContains('lightnode', app(CloudPricingSnapshot::class)->excludedProviders());
        $this->assertCount(2, CloudPlan::query()->sellable()->get());
        Http::assertNothingSent();
    }
}
