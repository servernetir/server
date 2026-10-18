<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\ArvanClient;
use App\Services\Cloud\CloudFraudGuard;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class ArvanWindowsPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-only-Pw9!';
    private const REF = 'ir-test-1:test-server';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Setting::putSecret('arvan_api_token', 'Apikey synthetic-only');
        Setting::put('arvan_windows_ready_image_refs', 'test-windows-image');
        Setting::put('arvan_hourly_sales_enabled', '1');
        Setting::put('arvan_console_worker_enabled', '1');
        $this->instance(CloudFraudGuard::class, Mockery::mock(CloudFraudGuard::class)
            ->shouldReceive('check')->andReturn(['hold' => false])->getMock());
    }

    private function fixture(bool $existing = false): array
    {
        $customer = Customer::create(['email' => 'windows@example.test', 'phone' => '09120000000',
            'password' => 'synthetic-only', 'status' => 'active', 'locale' => 'fa']);
        $plan = CloudPlan::create([
            'provider' => 'arvan', 'provider_ref' => 'test-flavor', 'provider_location' => 'ir-test-1',
            'location_code' => 'ir-test', 'public_name' => 'Synthetic VPS', 'slug' => 'synthetic-windows',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 30, 'disk_type' => 'ssd', 'traffic_gb' => 0,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 100, 'price_eur_cents' => 200,
            'price_irt' => 100000, 'is_active' => true, 'in_stock' => true,
        ]);
        CloudImage::create(['provider' => 'arvan', 'provider_ref' => 'test-windows-image',
            'key' => '2025', 'kind' => 'os', 'family' => 'windows', 'label' => 'Windows Server 2025',
            'is_active' => true]);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic Windows',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'awaiting_provision',
            'provision_status' => 'pending', 'cloud_plan_id' => $plan->id, 'cloud_image_key' => '2025']);
        $instance = $existing ? CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan',
            'provider_ref' => self::REF, 'image_key' => '2025', 'status' => 'building',
            'hostname' => 'sn-svc-'.$service->id, 'ipv4' => '203.0.113.9']) : null;

        return [$customer, $service, $instance];
    }

    private function driver(): \Mockery\MockInterface
    {
        $driver = Mockery::mock(ArvanClient::class)->makePartial();
        $driver->shouldNotReceive('resetPassword');
        $this->instance(ArvanClient::class, $driver);

        return $driver;
    }

    private function providerResult(?string $password = null): array
    {
        return ['ok' => true, 'message' => '', 'ref' => self::REF, 'status' => 'building',
            'ipv4' => '203.0.113.9', 'ipv6' => null, 'root_password' => $password];
    }

    private function assertSalesAndConsoleUnchanged(): void
    {
        $this->assertSame('1', Setting::get('arvan_hourly_sales_enabled'));
        $this->assertSame('1', Setting::get('arvan_console_worker_enabled'));
        $this->assertSame('test-windows-image', Setting::get('arvan_windows_ready_image_refs'));
    }

    public function test_create_preserves_returned_password_and_administrator_without_linux_reset(): void
    {
        [, $service] = $this->fixture();
        $driver = $this->driver();
        $driver->shouldReceive('createServer')->once()->andReturn($this->providerResult(self::PASSWORD));
        $this->assertTrue(app(CloudProvisioner::class)->provision($service));
        $instance = $service->fresh()->cloudInstance;
        $this->assertSame(self::PASSWORD, $instance->password());
        $this->assertSame(self::PASSWORD, $service->fresh()->password);
        $this->assertFalse($instance->password_seen);
        $this->assertSame('Administrator', $service->fresh()->username);
        $this->assertStringNotContainsString(self::PASSWORD, (string) DB::table('cloud_instances')->where('id', $instance->id)->value('root_password_enc'));
        $this->assertStringNotContainsString(self::PASSWORD, (string) DB::table('services')->where('id', $service->id)->value('password'));
        $this->assertStringNotContainsString(self::PASSWORD, $instance->toJson());
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($instance->meta));
        $this->assertSalesAndConsoleUnchanged();
    }

    public function test_missing_create_password_is_not_generated_or_recovered_from_a_late_get(): void
    {
        [, $service] = $this->fixture();
        $driver = $this->driver();
        $driver->shouldReceive('createServer')->once()->andReturn($this->providerResult());
        $driver->shouldNotReceive('serverStatus');
        $this->assertTrue(app(CloudProvisioner::class)->provision($service));
        $this->assertFalse($service->fresh()->cloudInstance->hasPassword());
        $this->assertNull($service->fresh()->password);
        $this->assertSame('Administrator', $service->fresh()->username);
        $this->assertSame(self::REF, $service->fresh()->cloudInstance->provider_ref);
        Http::assertNothingSent();
        $this->assertSalesAndConsoleUnchanged();
    }

    public function test_adoption_does_not_reset_windows_or_recover_a_potentially_stale_password(): void
    {
        [, $service] = $this->fixture(true);
        $driver = $this->driver();
        $driver->shouldNotReceive('createServer');
        $driver->shouldReceive('serverStatus')->with(self::REF)->once()->andReturn($this->providerResult(self::PASSWORD));
        $this->assertTrue(app(CloudProvisioner::class)->provision($service));
        $this->assertFalse($service->fresh()->cloudInstance->hasPassword());
        $this->assertNull($service->fresh()->password);
        $this->assertSame('Administrator', $service->fresh()->username);
        Http::assertNothingSent();
    }

    public function test_adoption_preserves_existing_customer_credential_and_seen_flag(): void
    {
        [, $service, $instance] = $this->fixture(true);
        $instance->setPassword(self::PASSWORD);
        $instance->password_seen = true;
        $instance->save();
        $service->update(['password' => self::PASSWORD]);
        $driver = $this->driver();
        $driver->shouldNotReceive('createServer');
        $driver->shouldReceive('serverStatus')->once()->andReturn($this->providerResult('Stale-synthetic-password!'));
        $this->assertTrue(app(CloudProvisioner::class)->provision($service));
        $this->assertSame(self::PASSWORD, $instance->fresh()->password());
        $this->assertSame(self::PASSWORD, $service->fresh()->password);
        $this->assertTrue($instance->fresh()->password_seen);
    }

    public function test_build_poll_does_not_adopt_password_from_an_earlier_image_generation(): void
    {
        [, $service, $instance] = $this->fixture(true);
        $service->update(['status' => 'active', 'provision_status' => 'done']);
        $driver = $this->driver();
        $driver->shouldReceive('serverStatus')->with(self::REF)->once()->andReturn($this->providerResult(self::PASSWORD));
        app(CloudProvisioner::class)->syncInstances();
        $this->assertFalse($instance->fresh()->hasPassword());
        $this->assertNull($service->fresh()->password);
        Http::assertNothingSent();
    }

    public function test_missing_catalog_metadata_does_not_make_an_existing_windows_vm_linux(): void
    {
        [, $service] = $this->fixture(true);
        CloudImage::query()->delete();
        $driver = $this->driver();
        $driver->shouldNotReceive('createServer');
        $driver->shouldNotReceive('serverStatus');
        $this->assertFalse(app(CloudProvisioner::class)->provision($service));
        $this->assertFalse($service->fresh()->cloudInstance->hasPassword());
    }

    public function test_only_unambiguous_supported_linux_family_permits_arvan_automatic_reset(): void
    {
        [, , $instance] = $this->fixture(true);
        $method = new \ReflectionMethod(CloudProvisioner::class, 'supportsAutomaticPasswordReset');
        $provisioner = app(CloudProvisioner::class);
        $this->assertFalse($method->invoke($provisioner, $instance));
        CloudImage::query()->delete();
        $this->assertFalse($method->invoke($provisioner, $instance));
        $image = CloudImage::create(['provider' => 'arvan', 'provider_ref' => 'linux-image', 'key' => '2025',
            'kind' => 'os', 'family' => 'ubuntu', 'label' => 'Synthetic', 'is_active' => true]);
        $this->assertTrue($method->invoke($provisioner, $instance));
        foreach (['alma-linux', 'rocky-linux', 'centos-stream', 'red-hat-enterprise-linux', 'opensuse-leap'] as $family) {
            $image->update(['family' => $family]);
            $this->assertTrue($method->invoke($provisioner, $instance), $family);
        }
        $image->update(['family' => '']);
        $this->assertFalse($method->invoke($provisioner, $instance));
        $image->update(['family' => 'ubuntu']);
        CloudImage::create(['provider' => 'arvan', 'provider_ref' => 'windows-image', 'key' => '2025',
            'kind' => 'os', 'family' => 'windows', 'label' => 'Synthetic', 'is_active' => true]);
        $this->assertFalse($method->invoke($provisioner, $instance));
        $instance->provider = 'hetzner';
        $this->assertTrue($method->invoke($provisioner, $instance));
    }

    public function test_create_response_password_requires_a_nonempty_bounded_string(): void
    {
        $client = app(ArvanClient::class);
        $method = new \ReflectionMethod(ArvanClient::class, 'responsePassword');
        foreach ([null, '', ' ', ['password' => self::PASSWORD], 12345, true,
            "bad\nvalue", "bad\0value", "bad\x7fvalue", str_repeat('x', 4097)] as $bad) {
            $this->assertNull($method->invoke($client, ['password' => $bad]));
        }
        $this->assertNull($method->invoke($client, []));
        $this->assertSame(self::PASSWORD, $method->invoke($client, ['password' => self::PASSWORD]));
        $this->assertSame(' '.self::PASSWORD.' ', $method->invoke($client, ['password' => ' '.self::PASSWORD.' ']));
    }

    public function test_status_response_and_customer_cache_never_contain_provider_password(): void
    {
        [$customer, $service, $instance] = $this->fixture(true);
        $service->update(['status' => 'active', 'provision_status' => 'done']);
        Http::fake(['*' => Http::response(['data' => ['id' => 'test-server', 'status' => 'ACTIVE',
            'password' => self::PASSWORD, 'addresses' => ['203.0.113.9']]])]);
        $this->assertArrayNotHasKey('root_password', app(ArvanClient::class)->serverStatus(self::REF));
        $response = $this->actingAs($customer, 'customer')->get('/account/cloud/'.$service->id.'/status');
        $response->assertOk();
        $this->assertStringNotContainsString(self::PASSWORD, $response->getContent());
        $this->assertStringNotContainsString(self::PASSWORD, json_encode(Cache::get('cloud-st:'.$instance->id)));
        $this->assertFalse($instance->fresh()->hasPassword());
    }
}
