<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudLocation, CloudPlan, Setting};
use App\Services\Cloud\{CloudPricingSnapshot, GpuSalesHealth, SaladClient};
use App\Services\Notify\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{File, Http};
use Tests\Support\NavasanRates;
use Tests\TestCase;

class GpuSalesWatchTest extends TestCase
{
    use RefreshDatabase;

    private string $watchStorage;
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->originalStorage = storage_path();
        $this->watchStorage = sys_get_temp_dir().'/gpu-sales-watch-test-'.bin2hex(random_bytes(8));
        mkdir($this->watchStorage.'/app', 0700, true);
        $this->app->useStoragePath($this->watchStorage);
        NavasanRates::store();
        app(CloudPricingSnapshot::class)->publish([100000, 200000, 150000]);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->watchStorage);
        parent::tearDown();
    }

    private function offer(): CloudPlan
    {
        Setting::putSecret('salad_api_key', 'test-only');
        Setting::put('salad_org', 'test-org');
        Setting::put('salad_project', 'test-project');
        CloudLocation::create(['code' => 'global-gpu', 'country' => 'XX', 'is_active' => true]);
        $plan = CloudPlan::create(['provider' => 'salad', 'provider_ref' => 'gpu-test',
            'provider_location' => 'global', 'location_code' => 'global-gpu', 'slug' => 'gpu-watch-test',
            'public_name' => 'RTX 3090', 'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 150,
            'arch' => 'x86', 'gpu_model' => 'RTX 3090', 'gpu_count' => 1,
            'cost_eur_cents' => 1000, 'price_eur_cents' => 2000, 'price_irt' => 4000000,
            'cost_hour_eur_micro' => 15000, 'is_active' => true, 'in_stock' => true]);
        CloudImage::create(['provider' => 'salad', 'key' => 'gpu-jupyter',
            'provider_ref' => SaladClient::APPS['gpu-jupyter']['ref'], 'kind' => 'app',
            'family' => 'gpu-jupyter', 'label' => 'Jupyter PyTorch', 'arch' => 'x86', 'is_active' => true]);
        return $plan;
    }

    public function test_current_prices_do_not_mean_sales_work_when_all_apps_are_paused(): void
    {
        $this->offer();
        $health = app(GpuSalesHealth::class);
        $this->assertTrue($health->snapshot()['ok']);
        Setting::put('salad_jupyter_sales_paused', '1');
        // Pause the app with the actual setting used by SaladClient.
        $this->assertTrue(SaladClient::salesPaused('gpu-jupyter'));
        $this->assertFalse($health->snapshot()['ok']);
        Http::assertNothingSent();
    }

    public function test_stale_snapshot_closes_public_offers_and_is_detected_without_supplier_requests(): void
    {
        $plan = $this->offer();
        NavasanRates::store(['USD' => 120000, 'EUR' => 200000, 'USDT' => 150000]);
        $this->assertGreaterThan(0, $plan->price_irt);
        $this->assertFalse(CloudPlan::sellable()->exists());
        $this->assertSame('stale_price_snapshot', app(GpuSalesHealth::class)->snapshot()['reason']);
        Http::assertNothingSent();
    }

    public function test_first_failure_is_immediate_duplicates_are_quiet_and_recovery_is_announced(): void
    {
        $plan = $this->offer();
        $notifier = $this->mock(AdminNotifier::class);
        $notifier->shouldReceive('baleEvent')->once()->withArgs(fn ($title, $rows, $url) =>
            str_contains($title, 'متوقف') && $rows['عرضهٔ قابل سفارش'] === 0
            && $url === 'https://console.servernet.cloud/admin/cloud');
        $notifier->shouldReceive('baleEvent')->once()->withArgs(fn ($title) => str_contains($title, 'دوباره'));
        $this->artisan('cloud:watch-gpu-sales')->assertSuccessful();
        $plan->update(['in_stock' => false]);
        $this->artisan('cloud:watch-gpu-sales')->assertSuccessful();
        $this->artisan('cloud:watch-gpu-sales')->assertSuccessful();
        $plan->update(['in_stock' => true]);
        $this->artisan('cloud:watch-gpu-sales')->assertSuccessful();
        $state = json_decode(file_get_contents(storage_path('app/gpu-sales-watch.json')), true);
        $this->assertTrue($state['ok']);
        $this->assertSame(1, $state['offers']);
        Http::assertNothingSent();
    }

    public function test_unconfigured_install_is_quiet_and_probe_failure_is_not_reported_as_healthy(): void
    {
        $notifier = $this->mock(AdminNotifier::class);
        $notifier->shouldReceive('baleEvent')->once()->withArgs(fn ($title, $rows) =>
            str_contains($title, 'متوقف') && str_contains($rows['وضعیت'], 'کامل نشد'));
        $this->artisan('cloud:watch-gpu-sales')->assertSuccessful();
        $this->mock(GpuSalesHealth::class)->shouldReceive('snapshot')->once()->andThrow(new \RuntimeException('secret'));
        $this->artisan('cloud:watch-gpu-sales')->assertSuccessful();
        $this->assertStringNotContainsString('secret', file_get_contents(storage_path('app/gpu-sales-watch.json')));
        Http::assertNothingSent();
    }
}
