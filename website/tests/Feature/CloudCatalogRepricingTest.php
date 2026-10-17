<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\Cloud\CloudCatalogRepricer;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CloudCatalogRepricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::store(['USD' => 100000, 'EUR' => 100000, 'USDT' => 100000]);
        Setting::put('pricing_rate_override', null);
        Setting::put('cloud_margin_pct', '50');
    }

    private function plan(string $provider, array $extra = []): CloudPlan
    {
        return CloudPlan::create($extra + [
            'provider' => $provider, 'provider_ref' => $provider.'-existing',
            'location_code' => 'de-falkenstein', 'public_name' => 'Existing plan',
            'slug' => $provider.'-existing', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20,
            'cost_eur_cents' => 1000, 'cost_hour_eur_micro' => 14000,
            'setup_eur_cents' => 0, 'price_eur_cents' => 1500, 'price_irt' => 1500000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ]);
    }

    private function provider(string $provider, array $rows, bool $ok = true): void
    {
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchCatalog')->once()->andReturn(['ok' => $ok, 'plans' => $rows]);
        $this->mock(CloudManager::class)->shouldReceive('driver')->with($provider)->once()->andReturn($driver);
    }

    private function quote(CloudPlan $plan, array $extra = []): array
    {
        return $extra + ['provider_ref' => $plan->provider_ref, 'location_code' => $plan->location_code,
            'cost_eur_cents' => 2000, 'cost_hour_eur_micro' => 28000, 'setup_eur_cents' => 500];
    }

    public function test_native_costs_and_hourly_setup_prices_refresh_without_catalog_side_effects(): void
    {
        $plan = $this->plan('salad', ['in_stock' => false, 'admin_note' => 'Keep this note']);
        $this->provider('salad', [
            $this->quote($plan, ['in_stock' => true, 'name' => 'Changed', 'vcpu' => 100]),
            ['provider_ref' => 'new-ignored', 'location_code' => 'xx-new', 'cost_eur_cents' => 500],
        ]);

        $this->assertSame(1, app(CloudCatalogSync::class)->reprice());
        $fresh = $plan->fresh();
        $this->assertSame(2000, (int) $fresh->cost_eur_cents);
        $this->assertSame(28000, (int) $fresh->cost_hour_eur_micro);
        $this->assertSame(500, (int) $fresh->setup_eur_cents);
        $this->assertSame(3000000, (int) $fresh->price_irt);
        $this->assertSame(1000, (int) $fresh->previous_cost_eur_cents);
        $this->assertFalse($fresh->in_stock);
        $this->assertSame(1, (int) $fresh->vcpu);
        $this->assertSame('Existing plan', $fresh->public_name);
        $this->assertSame('Keep this note', $fresh->admin_note);
        $this->assertSame(1, CloudPlan::count());
        $this->assertDatabaseCount('cloud_images', 0);
        Http::assertNothingSent();
    }

    public function test_supplier_failure_preserves_all_prices_including_eur_products(): void
    {
        $eur = $this->plan('hetzner');
        $usd = $this->plan('salad');
        $this->provider('salad', [], false);

        try {
            app(CloudCatalogSync::class)->reprice();
            $this->fail('Supplier failure must fail the price batch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('supplier costs', $e->getMessage());
        }
        $this->assertSame(1500000, (int) $eur->fresh()->price_irt);
        $this->assertSame(1500000, (int) $usd->fresh()->price_irt);
    }

    public function test_missing_existing_native_plan_is_not_repriced_using_stale_euro_cost(): void
    {
        $plan = $this->plan('salad');
        $this->provider('salad', []);

        try {
            app(CloudCatalogSync::class)->reprice();
            $this->fail('Missing existing plan must fail the price batch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('missing an existing supplier plan', $e->getMessage());
        }
        $this->assertSame(1500000, (int) $plan->fresh()->price_irt);
    }

    public function test_missing_hourly_cost_preserves_the_previous_batch(): void
    {
        $plan = $this->plan('salad');
        $this->provider('salad', [$this->quote($plan, ['cost_hour_eur_micro' => null])]);

        try {
            app(CloudCatalogSync::class)->reprice();
            $this->fail('Missing hourly cost must fail the price batch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('hourly supplier cost', $e->getMessage());
        }
        $this->assertSame(14000, (int) $plan->fresh()->cost_hour_eur_micro);
    }

    public function test_disabled_provider_is_not_read_or_repriced(): void
    {
        $plan = $this->plan('salad');
        Setting::put('cloud_disabled_providers', 'salad');
        $this->mock(CloudManager::class)->shouldNotReceive('driver');

        $this->assertSame(0, app(CloudCatalogSync::class)->reprice());
        $this->assertSame(1500000, (int) $plan->fresh()->price_irt);
    }

    public function test_preview_validates_and_counts_changes_without_writing_products(): void
    {
        $plan = $this->plan('salad');
        $before = $plan->fresh()->getRawOriginal();
        $this->provider('salad', [$this->quote($plan)]);

        $this->assertSame(1, app(CloudCatalogRepricer::class)->run(false));
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }

    public function test_rate_change_during_supplier_read_rejects_the_product_batch(): void
    {
        $plan = $this->plan('salad');
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchCatalog')->once()->andReturnUsing(function () use ($plan) {
            NavasanRates::store(['USD' => 95000, 'EUR' => 100000, 'USDT' => 100000]);
            return ['ok' => true, 'plans' => [$this->quote($plan)]];
        });
        $this->mock(CloudManager::class)->shouldReceive('driver')->with('salad')->once()->andReturn($driver);

        try {
            app(CloudCatalogSync::class)->reprice();
            $this->fail('Concurrent FX change must reject the product batch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Exchange rates changed', $e->getMessage());
        }
        $this->assertSame(1500000, (int) $plan->fresh()->price_irt);
    }

    public function test_concurrent_catalog_change_is_preserved_and_the_batch_rejected(): void
    {
        $plan = $this->plan('salad');
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchCatalog')->once()->andReturnUsing(function () use ($plan) {
            $plan->update(['admin_note' => 'Concurrent change']);
            return ['ok' => true, 'plans' => [$this->quote($plan)]];
        });
        $this->mock(CloudManager::class)->shouldReceive('driver')->with('salad')->once()->andReturn($driver);

        try {
            app(CloudCatalogSync::class)->reprice();
            $this->fail('Concurrent change must reject the batch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Catalog changed', $e->getMessage());
        }
        $this->assertSame('Concurrent change', $plan->fresh()->admin_note);
        $this->assertSame(1500000, (int) $plan->fresh()->price_irt);
    }

    public static function pricingSettings(): array
    {
        return array_map(fn (string $key) => [$key], [
            'cloud_margin_pct', 'cloud_margin_pct_salad', 'pricing_fx_fee_pct',
            'pricing_fx_fee_pct_lightnode', 'salad_vcpu_usd_hour', 'lightnode_manifest',
        ]);
    }

    #[DataProvider('pricingSettings')]
    public function test_other_request_pricing_edit_rejects_batch_despite_stale_settings_memo(string $key): void
    {
        $eur = $this->plan('hetzner');
        $native = $this->plan('salad');
        Setting::put($key, '100000');
        Setting::cached();
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchCatalog')->once()->andReturnUsing(function () use ($native, $key) {
            // Another PHP request does not clear this process's Setting memo.
            DB::table('settings')->where('key', $key)->update(['value' => '200000']);
            $this->assertSame('100000', Setting::get($key));
            return ['ok' => true, 'plans' => [$this->quote($native)]];
        });
        $this->mock(CloudManager::class)->shouldReceive('driver')->with('salad')->once()->andReturn($driver);

        try {
            app(CloudCatalogSync::class)->reprice();
            $this->fail('Concurrent pricing setting change must reject the batch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Pricing settings changed', $e->getMessage());
        }
        $this->assertSame(1500000, (int) $eur->fresh()->price_irt);
        $this->assertSame(1500000, (int) $native->fresh()->price_irt);
        $this->assertSame(1000, (int) $native->fresh()->cost_eur_cents);
        $this->assertSame('200000', DB::table('settings')->where('key', $key)->value('value'));
    }

    public function test_pricing_change_during_apply_rolls_back_product_writes(): void
    {
        $plan = $this->plan('salad');
        $this->provider('salad', [$this->quote($plan)]);
        CloudPlan::saved(function () {
            DB::table('settings')->where('key', 'cloud_margin_pct')->update(['value' => '60']);
        });

        try {
            app(CloudCatalogSync::class)->reprice();
            $this->fail('Pricing changes before commit must roll back the batch.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Pricing settings changed', $e->getMessage());
        }
        $this->assertSame(1500000, (int) $plan->fresh()->price_irt);
        $this->assertSame(1000, (int) $plan->fresh()->cost_eur_cents);
    }

    public function test_unrelated_settings_change_does_not_block_repricing(): void
    {
        $plan = $this->plan('salad');
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->once()->andReturnTrue();
        $driver->shouldReceive('fetchCatalog')->once()->andReturnUsing(function () use ($plan) {
            DB::table('settings')->insert(['key' => 'support_phone', 'value' => '123']);
            return ['ok' => true, 'plans' => [$this->quote($plan)]];
        });
        $this->mock(CloudManager::class)->shouldReceive('driver')->with('salad')->once()->andReturn($driver);

        $this->assertSame(1, app(CloudCatalogSync::class)->reprice());
        $this->assertSame(3000000, (int) $plan->fresh()->price_irt);
    }

    public function test_stale_pricing_memo_at_start_rejects_before_supplier_reads(): void
    {
        $plan = $this->plan('salad');
        Setting::cached();
        DB::table('settings')->where('key', 'cloud_margin_pct')->update(['value' => '60']);
        $this->mock(CloudManager::class)->shouldNotReceive('driver');

        try {
            app(CloudCatalogSync::class)->reprice();
            $this->fail('A stale memo must not price against the new settings snapshot.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Pricing settings changed before', $e->getMessage());
        }
        $this->assertSame(1500000, (int) $plan->fresh()->price_irt);
        $this->assertSame('60', DB::table('settings')->where('key', 'cloud_margin_pct')->value('value'));
    }
}
