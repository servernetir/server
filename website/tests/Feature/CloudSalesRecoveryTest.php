<?php

namespace Tests\Feature;

use App\Console\Commands\RecoverCloudSales;
use App\Models\{CloudPlan, Setting};
use App\Services\Cloud\{CloudCatalogSync, CloudManager, CloudPricingSnapshot, CloudProvider};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CloudSalesRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function setupFailedSupplier(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfSecond());
        NavasanRates::store();
        CloudPlan::create(['provider' => 'salad', 'provider_ref' => 'gpu', 'location_code' => 'gpu',
            'public_name' => 'GPU', 'slug' => 'gpu', 'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 20,
            'cost_eur_cents' => 1000, 'price_eur_cents' => 1500, 'price_irt' => 3000000,
            'is_active' => false, 'in_stock' => true, 'admin_disabled' => false]);
        $this->mock(CloudManager::class)->shouldReceive('configured')->andReturn([
            'salad' => \Mockery::mock(CloudProvider::class),
        ]);
        $this->mock(CloudPricingSnapshot::class)->shouldReceive('current')->andReturnTrue()
            ->shouldReceive('rates')->andReturn([100000, 200000, 150000])
            ->shouldReceive('excludedProviders')->andReturn(['salad']);
    }

    public function test_failed_catalogue_reads_retry_at_separate_intervals_without_touching_services(): void
    {
        $this->setupFailedSupplier();
        $this->mock(CloudCatalogSync::class)->shouldReceive('sync')->with('salad')->times(3)
            ->andReturn(['providers' => ['salad' => ['ok' => false]]]);
        $this->artisan('cloud:recover-sales')->assertSuccessful();
        $first = json_decode(Setting::get(RecoverCloudSales::STATE_KEY), true)['salad'];
        $this->assertSame(1, $first['attempts']);
        $this->assertSame(now()->addMinutes(5)->timestamp, $first['next_at']);
        $this->artisan('cloud:recover-sales')->assertSuccessful();
        $this->assertSame($first, json_decode(Setting::get(RecoverCloudSales::STATE_KEY), true)['salad']);
        $this->travel(5)->minutes();
        $this->artisan('cloud:recover-sales')->assertSuccessful();
        $second = json_decode(Setting::get(RecoverCloudSales::STATE_KEY), true)['salad'];
        $this->assertSame(2, $second['attempts']);
        $this->assertSame(now()->addMinutes(15)->timestamp, $second['next_at']);
        $this->travel(15)->minutes();
        $this->artisan('cloud:recover-sales')->assertSuccessful();
        $third = json_decode(Setting::get(RecoverCloudSales::STATE_KEY), true)['salad'];
        $this->assertSame(3, $third['attempts']);
        $this->assertSame(now()->addHour()->timestamp, $third['next_at']);
        $this->assertFalse(CloudPlan::first()->is_active);
        Http::assertNothingSent();
    }

    public function test_manual_supplier_shutdown_is_never_reopened_or_retried(): void
    {
        $this->setupFailedSupplier();
        Setting::put('cloud_disabled_providers', 'salad');
        $this->mock(CloudCatalogSync::class)->shouldNotReceive('sync');
        $this->artisan('cloud:recover-sales')->assertSuccessful();
        $this->assertSame([], json_decode(Setting::get(RecoverCloudSales::STATE_KEY), true));
        Http::assertNothingSent();
    }
}
