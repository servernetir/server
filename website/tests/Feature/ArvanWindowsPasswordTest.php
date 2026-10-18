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
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_interrupted_finalization_keeps_instance_receipt_without_unsafe_service_recovery(): void
    {
        [, $service] = $this->fixture();
        $driver = $this->driver();
        $driver->shouldReceive('createServer')->once()->andReturn($this->providerResult(self::PASSWORD));
        $driver->shouldReceive('serverStatus')->with(self::REF)->once()->andReturn($this->providerResult('Stale-synthetic-password!'));
        $interrupt = true;
        Service::updating(function (Service $row) use ($service, &$interrupt): void {
            if ($interrupt && $row->id === $service->id && $row->provision_status === 'done') {
                $interrupt = false;
                throw new \RuntimeException('Synthetic finalization interruption');
            }
        });

        $this->assertFalse(app(CloudProvisioner::class)->provision($service));
        $instance = $service->fresh()->cloudInstance;
        $this->assertSame(self::REF, $instance->provider_ref, 'Accepted VM identity must survive a local failure.');
        $this->assertSame(self::PASSWORD, $instance->password());
        $this->assertNull($service->fresh()->password, 'The interrupted transaction must not partially finalize the service.');

        $this->assertTrue(app(CloudProvisioner::class)->provision($service->fresh()));
        $this->assertSame(self::PASSWORD, $instance->fresh()->password());
        $this->assertNull($service->fresh()->password, 'Retry has no generation proof for a retained secret; repair remains deferred.');
        $this->assertFalse($instance->fresh()->password_seen);
        Http::assertNothingSent();
        $this->assertSalesAndConsoleUnchanged();
    }

    public function test_initial_finalization_preserves_existing_service_ciphertext_even_if_unreadable(): void
    {
        [, $service, $instance] = $this->fixture(true);
        $instance->setPassword(self::PASSWORD);
        $instance->save();
        DB::table('services')->where('id', $service->id)->update([
            'password' => 'synthetic-unreadable-existing-ciphertext', 'provision_status' => 'running',
        ]);
        $this->finalizeDirectly($service, $instance, 'Stale-synthetic-password!');
        $this->assertSame('synthetic-unreadable-existing-ciphertext', DB::table('services')->where('id', $service->id)->value('password'));
        $this->assertSame(self::PASSWORD, $instance->fresh()->password());
        $this->assertFalse($instance->fresh()->password_seen);
        Http::assertNothingSent();
    }

    public function test_interrupted_finalization_does_not_adopt_a_password_retained_by_same_image_rebuild(): void
    {
        [$customer, $service] = $this->fixture();
        $driver = $this->driver();
        $driver->shouldReceive('createServer')->once()->andReturn($this->providerResult(self::PASSWORD));
        $driver->shouldReceive('rebuild')->with(self::REF, 'test-windows-image')->once()
            ->andReturn(['ok' => true, 'message' => '', 'root_password' => null]);
        $driver->shouldReceive('serverStatus')->with(self::REF)->once()->andReturn($this->providerResult());
        $interrupt = true;
        Service::updating(function (Service $row) use ($service, &$interrupt): void {
            if ($interrupt && $row->id === $service->id && $row->provision_status === 'done') {
                $interrupt = false;
                throw new \RuntimeException('Synthetic finalization interruption');
            }
        });

        $this->assertFalse(app(CloudProvisioner::class)->provision($service));
        $instance = $service->fresh()->cloudInstance;
        $ciphertext = $instance->getRawOriginal('root_password_enc');
        $this->actingAs($customer, 'customer')->post('/account/cloud/'.$service->id.'/rebuild', [
            'image' => '2025', 'confirm' => 'DELETE',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($ciphertext, $instance->fresh()->getRawOriginal('root_password_enc'));
        $this->assertSame('2025', $instance->fresh()->image_key);
        $this->assertNull($instance->fresh()->ready_notified_at);
        $this->assertFalse($instance->fresh()->password_seen);
        $this->assertNull($service->fresh()->activated_at);
        $this->assertNull($service->fresh()->provisioned_at);

        $this->assertTrue(app(CloudProvisioner::class)->provision($service->fresh()));
        $this->assertNull($service->fresh()->password, 'Same-image rebuild supplies no credential-generation proof for the retained secret.');
        $this->assertSame($ciphertext, $instance->fresh()->getRawOriginal('root_password_enc'));
        Http::assertNothingSent();
        $this->assertSalesAndConsoleUnchanged();
    }

    public function test_initial_finalization_does_not_overwrite_a_newer_service_password(): void
    {
        [, $service, $instance] = $this->fixture(true);
        $instance->setPassword(self::PASSWORD);
        $instance->save();
        $service->fresh()->update(['password' => 'Newer-synthetic-password!', 'provision_status' => 'running']);
        $ciphertext = DB::table('services')->where('id', $service->id)->value('password');
        $this->finalizeDirectly($service, $instance, self::PASSWORD);
        $this->assertSame($ciphertext, DB::table('services')->where('id', $service->id)->value('password'));
        $this->assertSame('Newer-synthetic-password!', $service->fresh()->password);
        Http::assertNothingSent();
    }

    public static function initialRecoveryBlockers(): array
    {
        return array_map(fn ($case) => [$case], [
            'provisioned', 'activated', 'seen', 'notified', 'changed_ref',
            'changed_instance_image', 'changed_service_image', 'changed_owner',
            'deleted_instance', 'cancelled_service',
        ]);
    }

    #[DataProvider('initialRecoveryBlockers')]
    public function test_initial_credential_recovery_rejects_history_or_changed_context(string $blocker): void
    {
        [, $service, $instance] = $this->fixture(true);
        $instance->setPassword(self::PASSWORD);
        $instance->save();
        $service->update(['provision_status' => 'running']);
        $instanceChanges = match ($blocker) {
            'seen' => ['password_seen' => true],
            'notified' => ['ready_notified_at' => now()->subHour()],
            'changed_ref' => ['provider_ref' => 'ir-test-1:different-server'],
            'changed_instance_image' => ['image_key' => 'different-image'],
            'deleted_instance' => ['status' => 'deleted'],
            default => [],
        };
        $serviceChanges = match ($blocker) {
            'provisioned' => ['provisioned_at' => now()->subHour()],
            'activated' => ['activated_at' => now()->subHour()],
            'changed_service_image' => ['cloud_image_key' => 'different-image'],
            'changed_owner' => ['customer_id' => $service->customer_id + 10000],
            'cancelled_service' => ['status' => 'cancelled'],
            default => [],
        };
        if ($instanceChanges !== []) {
            $instance->fresh()->update($instanceChanges);
        }
        if ($serviceChanges !== []) {
            $service->fresh()->update($serviceChanges);
        }
        $before = $instance->fresh()->getRawOriginal('root_password_enc');
        $seen = $instance->fresh()->password_seen;
        $this->finalizeDirectly($service, $instance, self::PASSWORD);
        $this->assertNull(DB::table('services')->where('id', $service->id)->value('password'));
        $this->assertSame($before, $instance->fresh()->getRawOriginal('root_password_enc'));
        $this->assertSame($seen, $instance->fresh()->password_seen);
        if ($blocker === 'cancelled_service') {
            $this->assertSame('cancelled', $service->fresh()->status);
        }
        Http::assertNothingSent();
    }

    private function finalizeDirectly(Service $service, CloudInstance $instance, ?string $password): void
    {
        $method = new \ReflectionMethod(CloudProvisioner::class, 'finalize');
        $method->invoke(app(CloudProvisioner::class), $service, $instance,
            CloudPlan::findOrFail($service->cloud_plan_id), $password);
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
