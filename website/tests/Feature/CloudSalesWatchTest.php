<?php

namespace Tests\Feature;

use App\Models\{CloudPlan, Setting};
use App\Services\Cloud\{CloudManager, CloudProvider, CloudPricingSnapshot, CloudSalesHealth};
use App\Services\Notify\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{File, Http};
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CloudSalesWatchTest extends TestCase
{
    use RefreshDatabase;
    private string $originalStorage;
    private string $watchStorage;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::store();
        app(CloudPricingSnapshot::class)->publish([100000, 200000, 150000]);
        $this->originalStorage = storage_path();
        $this->watchStorage = sys_get_temp_dir().'/cloud-sales-test-'.bin2hex(random_bytes(8));
        mkdir($this->watchStorage.'/app', 0700, true);
        $this->app->useStoragePath($this->watchStorage);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->watchStorage);
        parent::tearDown();
    }

    private function plan(string $provider): CloudPlan
    {
        return CloudPlan::create(['provider' => $provider, 'provider_ref' => $provider.'-test',
            'location_code' => 'de-falkenstein', 'slug' => $provider.'-test', 'public_name' => 'Test',
            'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20,
            'cost_eur_cents' => 1000, 'price_eur_cents' => 1500, 'price_irt' => 3000000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false]);
    }

    public function test_supplier_exclusion_does_not_report_healthy_peer_as_stopped(): void
    {
        $this->plan('hetzner'); $this->plan('aeza');
        $manager = $this->mock(CloudManager::class);
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('isConfigured')->andReturnTrue();
        $driver->shouldNotReceive('fetchCatalog');
        $manager->shouldReceive('driver')->andReturn($driver);
        $manager->shouldReceive('realLabel')->with('hetzner')->andReturn('Hetzner');
        $manager->shouldReceive('realLabel')->with('aeza')->andReturn('Aeza');
        $snapshot = app(CloudPricingSnapshot::class);
        $snapshot->publish($snapshot->rates(), ['aeza'], [], [], ['aeza' => 'invalid_quote']);
        $checks = app(CloudSalesHealth::class)->snapshot();
        $this->assertTrue($checks['hetzner']['ok']);
        $this->assertSame(1, $checks['hetzner']['offers']);
        $this->assertFalse($checks['aeza']['ok']);
        $this->assertSame('invalid_quote', $checks['aeza']['reason']);
        $this->assertStringContainsString('معتبر نبود', CloudSalesHealth::reasonText($checks['aeza']['reason']));
        Http::assertNothingSent();
    }

    public function test_watch_coalesces_failure_causes_and_confirms_recovery_without_repeating_peers(): void
    {
        $sent = [];
        $this->mock(AdminNotifier::class)->shouldReceive('baleEvent')->andReturnUsing(
            function ($title, $rows, $url) use (&$sent) { $sent[] = compact('title', 'rows', 'url'); });
        $a = ['ok' => true, 'offers' => 2, 'reason' => 'available', 'label' => 'Hetzner'];
        $b = ['ok' => false, 'offers' => 0, 'reason' => 'supplier_cost_read_failed', 'label' => 'VPS.TC'];
        $off = ['ok' => false, 'offers' => 0, 'reason' => 'admin_disabled', 'label' => 'OVH'];
        $health = $this->mock(CloudSalesHealth::class);
        $health->shouldReceive('snapshot')->times(3)->andReturn(['hetzner' => $a, 'vpstc' => $b, 'ovh' => $off]);
        $health->shouldReceive('snapshot')->once()->andReturn(['hetzner' => $a, 'vpstc' => array_replace($b, ['reason' => 'missing_quote']), 'ovh' => $off]);
        $health->shouldReceive('snapshot')->twice()->andReturn(['hetzner' => $a, 'vpstc' => array_replace($a, ['label' => 'VPS.TC']), 'ovh' => $off]);
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->assertCount(1, $sent);
        $this->travel(31)->minutes();
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->assertCount(1, $sent);
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->assertCount(2, $sent);
        foreach ($sent as $message) { $this->assertSame('VPS.TC', $message['rows']['زیرساخت']); }
        $this->assertStringContainsString('دوباره', $sent[1]['title']);
        $this->assertSame('https://console.servernet.cloud/admin/cloud', $sent[0]['url']);
        Http::assertNothingSent();
    }

    public function test_failed_full_catalog_read_excludes_only_that_supplier_and_keeps_peer_quote(): void
    {
        $peer = $this->plan('hetzner'); $bad = $this->plan('aeza');
        $before = $bad->fresh()->getRawOriginal();
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('fetchCatalog')->once()->andReturn(['ok' => false, 'message' => 'private upstream error']);
        $this->mock(CloudManager::class)->shouldReceive('configured')->once()->andReturn(['aeza' => $driver]);
        app(\App\Services\Cloud\CloudCatalogSync::class)->sync('aeza');
        $snapshot = app(CloudPricingSnapshot::class);
        $this->assertTrue($snapshot->current('hetzner'));
        $this->assertFalse($snapshot->current('aeza'));
        $this->assertSame([$peer->id], CloudPlan::sellable()->pluck('id')->all());
        $this->assertSame($before, $bad->fresh()->getRawOriginal());
        $this->assertSame('supplier_cost_read_failed', $snapshot->exclusionReasons()['aeza']);
        Http::assertNothingSent();
    }

    public function test_failure_to_inspect_sales_is_an_alert_and_never_leaks_exception_text(): void
    {
        $this->mock(CloudSalesHealth::class)->shouldReceive('snapshot')->once()->andThrow(new \RuntimeException('private bearer token'));
        $this->mock(AdminNotifier::class)->shouldReceive('baleEvent')->once()->withArgs(fn ($title, $rows) =>
            str_contains($title, 'متوقف') && str_contains($rows['علت'], 'کامل نشد'));
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->assertStringNotContainsString('private bearer token', file_get_contents(storage_path('app/cloud-sales-watch.json')));
        Http::assertNothingSent();
    }
    public function test_probe_failure_preserves_an_existing_provider_incident_receipt(): void
    {
        $sent = [];
        $this->mock(AdminNotifier::class)->shouldReceive('baleEvent')->andReturnUsing(
            function ($title, $rows) use (&$sent) { $sent[] = compact('title', 'rows'); });
        $failure = ['ok' => false, 'offers' => 0, 'reason' => 'supplier_cost_read_failed', 'label' => 'VPS.TC'];
        $health = $this->mock(CloudSalesHealth::class);
        $health->shouldReceive('snapshot')->once()->andReturn(['vpstc' => $failure])->ordered();
        $health->shouldReceive('snapshot')->once()->andThrow(new \RuntimeException('private token'))->ordered();
        $health->shouldReceive('snapshot')->twice()->andReturn(['vpstc' => $failure])->ordered();
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->assertCount(2, $sent); // Supplier incident and the independent failed probe.
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->assertCount(2, $sent);
        $this->artisan('cloud:watch-sales')->assertSuccessful();
        $this->assertCount(3, $sent); // Only the probe recovers after two successful observations.
        $receipt = json_decode(file_get_contents(storage_path('app/cloud-sales-watch.json')), true);
        $this->assertTrue($receipt['providers']['vpstc']['incident_open']);
        $this->assertSame(0, $receipt['providers']['vpstc']['reminders_sent']);
        $this->assertStringNotContainsString('private token', json_encode($receipt));
        Http::assertNothingSent();
    }

}
