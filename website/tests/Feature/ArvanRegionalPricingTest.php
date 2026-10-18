<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\ArvanClient;
use App\Services\Cloud\CloudCatalogRepricer;
use App\Services\Cloud\CloudManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class ArvanRegionalPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::cloudSnapshot();
        Setting::putSecret('arvan_api_token', 'Apikey test-only');
        Setting::put('cloud_margin_pct', '50');
    }

    private function supplierSize(): array
    {
        return ['id' => 'live', 'cpu_count' => 2, 'memory_in_bytes' => 2147483648,
            'disk_in_bytes' => 21474836480, 'cpu_share' => 'shared',
            'price_per_month' => 10000000, 'price_per_hour' => 10000];
    }

    private function plan(string $ref, string $provider = 'arvan'): CloudPlan
    {
        return CloudPlan::create(['provider' => $provider, 'provider_ref' => $ref,
            'provider_location' => 'ir-thr-ba1', 'location_code' => 'ir-shiraz',
            'slug' => $provider.'-'.$ref, 'public_name' => 'Existing plan', 'vcpu' => 2,
            'ram_mb' => 2048, 'disk_gb' => 20, 'cpu_kind' => 'shared',
            'cost_eur_cents' => 500, 'price_eur_cents' => 750, 'price_irt' => 750000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false]);
    }

    private function driver(): ArvanClient
    {
        $driver = app(ArvanClient::class);
        $this->mock(CloudManager::class)->shouldReceive('driver')->with('arvan')->once()->andReturn($driver);
        return $driver;
    }

    public function test_live_replay_keeps_all_fifteen_absent_flavors_in_their_original_regions(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/arvan-regional-pricing-20261017.json')), true, flags: JSON_THROW_ON_ERROR);
        $plans = array_map(fn ($row) => (new CloudPlan)->forceFill($row), array_merge($fixture['unavailable_plans'], $fixture['present_plans']));
        Http::fake(function ($request) use ($fixture) {
            foreach ($fixture['regional_sizes'] as $region => $rows) {
                if (str_ends_with($request->url(), '/regions/'.$region.'/sizes')) {
                    return Http::response(['data' => $rows]);
                }
            }
            throw new \RuntimeException('Unexpected provider request');
        });
        $result = app(ArvanClient::class)->fetchExistingPlanCosts($plans);
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertCount(15, $result['unavailable']);
        $this->assertCount(2, $result['plans']);
        foreach ($fixture['unavailable_plans'] as $old) {
            $this->assertContains(['provider_ref' => $old['provider_ref'], 'provider_location' => $old['provider_location'],
                'location_code' => $old['location_code']], $result['unavailable']);
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || ! str_ends_with($request->url(), '/sizes'));
    }

    public function test_current_flavor_uses_live_rial_monthly_and_hourly_costs(): void
    {
        Http::fake(['*/sizes' => Http::response(['data' => [$this->supplierSize()]])]);
        $result = app(ArvanClient::class)->fetchExistingPlanCosts([$this->plan('live')]);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['unavailable']);
        $this->assertSame(1000, $result['plans'][0]['cost_eur_cents']);
        $this->assertSame(10000, $result['plans'][0]['cost_hour_eur_micro']);
        $this->assertSame('ir-thr-ba1', $result['plans'][0]['provider_location']);
    }

    public function test_failed_empty_malformed_or_duplicate_lists_never_prove_unavailability(): void
    {
        $plan = $this->plan('old');
        foreach ([Http::response(['data' => []]), Http::response(['data' => (object) []]),
            Http::response('<html>not JSON</html>'), Http::response(['data' => [$this->supplierSize()]], 503),
            Http::response(['data' => [['cpu_count' => 2]]]),
            Http::response(['data' => [$this->supplierSize(), $this->supplierSize()]])] as $response) {
            Http::swap(new Factory);
            Http::fake(['*/sizes' => $response]);
            $result = app(ArvanClient::class)->fetchExistingPlanCosts([$plan]);
            $this->assertFalse($result['ok']);
            $this->assertSame([], $result['unavailable']);
        }
        $this->assertTrue($plan->fresh()->in_stock);
    }

    public function test_a_present_but_invalid_or_changed_flavor_is_not_treated_as_retired(): void
    {
        $plan = $this->plan('live');
        foreach ([['price_per_month' => 0, 'price_per_hour' => 0], ['memory_in_bytes' => 4294967296]] as $change) {
            Http::swap(new Factory);
            Http::fake(['*/sizes' => Http::response(['data' => [array_replace($this->supplierSize(), $change)]])]);
            $result = app(ArvanClient::class)->fetchExistingPlanCosts([$plan]);
            $this->assertFalse($result['ok']);
            $this->assertSame([], $result['unavailable']);
        }
    }

    public function test_retirement_and_all_other_prices_apply_together_without_deleting_products(): void
    {
        $old = $this->plan('old');
        $live = $this->plan('live');
        $euro = $this->plan('euro', 'hetzner');
        $euro->update(['price_irt' => 400000]);
        Http::fake(['*/sizes' => Http::response(['data' => [$this->supplierSize()]])]);
        $this->driver();
        $this->assertSame(3, app(CloudCatalogRepricer::class)->run());
        $this->assertFalse($old->fresh()->in_stock);
        $this->assertTrue($old->fresh()->is_active);
        $this->assertFalse($old->fresh()->admin_disabled);
        $this->assertSame(750000, (int) $old->fresh()->price_irt);
        $this->assertSame(500, (int) $old->fresh()->cost_eur_cents);
        $this->assertSame(1500000, (int) $live->fresh()->price_irt);
        $this->assertSame(750000, (int) $euro->fresh()->price_irt);
        $this->assertSame(10000, (int) $live->fresh()->cost_hour_eur_micro);
        $this->assertTrue($live->fresh()->in_stock);
        $this->assertDatabaseCount('cloud_plans', 3);
    }

    public function test_preview_does_not_retire_or_reprice_any_product(): void
    {
        $old = $this->plan('old');$live = $this->plan('live');
        $before = CloudPlan::orderBy('id')->get()->map->getAttributes()->all();
        Http::fake(['*/sizes' => Http::response(['data' => [$this->supplierSize()]])]);
        $this->driver();
        $this->assertSame(2, app(CloudCatalogRepricer::class)->run(false));
        $this->assertSame($before, CloudPlan::orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_a_regional_failure_preserves_availability_and_other_provider_prices(): void
    {
        $this->plan('old');$this->plan('euro', 'hetzner');
        $before = CloudPlan::orderBy('id')->get()->map->getAttributes()->all();
        Http::fake(['*/sizes' => Http::response(['message' => 'Unavailable'], 503)]);
        $this->driver();
        try { app(CloudCatalogRepricer::class)->run(); $this->fail('Expected regional failure'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('supplier costs', $e->getMessage()); }
        $this->assertSame($before, CloudPlan::orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_an_unavailability_decision_for_another_region_is_rejected(): void
    {
        $old = $this->plan('old');
        $driver = \Mockery::mock(ArvanClient::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchExistingPlanCosts')->once()->andReturn(['ok' => true, 'plans' => [],
            'unavailable' => [['provider_ref' => 'old', 'provider_location' => 'ir-thr-si1', 'location_code' => 'ir-shiraz']]]);
        $this->mock(CloudManager::class)->shouldReceive('driver')->with('arvan')->once()->andReturn($driver);
        try { app(CloudCatalogRepricer::class)->run(); $this->fail('Expected identity rejection'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('unavailable Arvan', $e->getMessage()); }
        $this->assertTrue($old->fresh()->in_stock);
    }
}
