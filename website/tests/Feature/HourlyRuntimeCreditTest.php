<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Ai\AiReservations;
use App\Services\Cloud\CloudPricing;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Cloud\HourlyCycleConverter;
use App\Services\Cloud\HourlyHold;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class HourlyRuntimeCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        HourlyHold::flush();
        Wallet::flushSchemaCache();
        $pricing = \Mockery::mock(CloudPricing::class)->makePartial();
        $pricing->shouldReceive('eurToToman')->andReturn(100000);
        $this->app->instance(CloudPricing::class, $pricing);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        HourlyHold::flush();
        Wallet::flushSchemaCache();
        parent::tearDown();
    }

    private function funded(int $amount): Customer
    {
        $customer = Customer::create(['email' => uniqid('runtime').'@example.test',
            'password' => 'test-only', 'status' => 'active', 'locale' => 'fa']);
        app(Wallet::class)->credit($customer->id, 'IRT', $amount, 'topup', $customer);

        return $customer;
    }

    private function server(Customer $customer, array $attributes = []): Service
    {
        $plan = CloudPlan::create(['provider' => 'aeza', 'provider_ref' => uniqid('runtime'),
            'location_code' => 'de-test', 'public_name' => 'Test VPS', 'slug' => uniqid('runtime'),
            'cost_hour_eur_micro' => 8000, 'cost_eur_cents' => 576,
            'price_eur_cents' => 720, 'price_irt' => 720000]);
        $service = Service::create($attributes + ['customer_id' => $customer->id,
            'name' => 'Test hourly VPS', 'currency_code' => 'IRT', 'price' => 720000,
            'cycle' => 'monthly', 'billing_mode' => 'hourly', 'hourly_rate_irt' => 1000,
            'status' => 'active', 'provision_status' => 'done', 'activated_at' => now(),
            'last_metered_at' => now(), 'on_credit_out' => 'suspend', 'cloud_plan_id' => $plan->id]);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'aeza',
            'provider_ref' => 'test-'.$service->id, 'location_code' => 'de-test',
            'image_key' => 'ubuntu-24.04', 'hostname' => 'test-'.$service->id,
            'ipv4' => '192.0.2.10', 'status' => 'running', 'ready_notified_at' => now()]);

        return $service;
    }

    public function test_twenty_four_hours_of_start_credit_buys_twenty_four_hours_of_service(): void
    {
        $customer = $this->funded(24000);
        $service = $this->server($customer);
        app(Wallet::class)->debit($customer->id, 'IRT', 1000, 'cloud_hourly', $service, 'First prepaid hour');
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldReceive('suspend')->once()->andReturnTrue();
        $provider->shouldNotReceive('unsuspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);

        for ($hour = 1; $hour <= 23; $hour++) {
            $this->travel(1)->hours();
            $this->artisan('cloud:meter')->assertSuccessful();
            $this->assertSame('active', $service->fresh()->status, 'Must keep running below the start minimum.');
            $this->assertSame((23 - $hour) * 1000, app(Wallet::class)->balanceOf($customer->id));
        }
        $this->assertSame(0, (int) $service->fresh()->hold_reserve_irt);
        $this->travel(1)->hours();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame(0, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(24, CreditEntry::where('source_type', Service::class)
            ->where('source_id', $service->id)->where('reason', 'cloud_hourly')->count());
    }

    public function test_creation_still_requires_twenty_four_hours_and_debits_only_the_first_hour(): void
    {
        NavasanRates::store(['USD' => 100000, 'EUR' => 100000, 'USDT' => 100000]);
        app(CloudPricingSnapshot::class)->publish([100000, 100000, 100000]);
        CloudLocation::create(['code' => 'de-test', 'country' => 'DE', 'city' => 'Test', 'is_active' => true]);
        CloudImage::create(['provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04',
            'key' => 'ubuntu-24.04', 'kind' => 'os', 'family' => 'ubuntu', 'label' => 'Ubuntu',
            'version' => '24.04', 'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true]);
        $plan = CloudPlan::create(['provider' => 'hetzner', 'provider_ref' => 'test-hourly',
            'provider_location' => 'fsn1', 'location_code' => 'de-test', 'slug' => 'test-hourly',
            'public_name' => 'Test VPS', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40,
            'disk_type' => 'nvme', 'traffic_gb' => 1024, 'arch' => 'x86', 'cpu_kind' => 'shared',
            'cost_eur_cents' => 576, 'cost_hour_eur_micro' => 8000, 'price_eur_cents' => 720,
            'price_irt' => 720000, 'is_active' => true, 'in_stock' => true]);
        $rate = $plan->hourlyIrt();
        $this->assertGreaterThan(0, $rate);
        $minimum = 24 * $rate;
        $customer = $this->funded($minimum - 1);
        $order = ['location' => 'de-test', 'plan' => $plan->slug,
            'image' => 'ubuntu-24.04', 'cycle' => 'monthly', 'billing_mode' => 'hourly'];

        $this->actingAs($customer, 'customer')->post('/account/cloud-store', $order)
            ->assertSessionHasErrors('billing_mode');
        $this->assertSame(0, Service::where('customer_id', $customer->id)->count());
        $this->assertSame($minimum - 1, app(Wallet::class)->balanceOf($customer->id));
        app(Wallet::class)->credit($customer->id, 'IRT', 1, 'topup', $customer);
        $this->post('/account/cloud-store', $order)->assertRedirect();
        $this->assertSame(1, Service::where('customer_id', $customer->id)->count());
        $this->assertSame($minimum - $rate, app(Wallet::class)->balanceOf($customer->id));
    }

    public function test_stale_active_reserves_do_not_block_either_server_or_inflate_conversion_credit(): void
    {
        $customer = $this->funded(2000);
        $first = $this->server($customer, ['hold_rate_irt' => 800, 'hold_reserve_irt' => 19200]);
        $second = $this->server($customer, ['hold_rate_irt' => 800, 'hold_reserve_irt' => 19200]);
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldNotReceive('suspend', 'unsuspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);
        $this->assertSame(2000, app(Wallet::class)->availableOf($customer->id));
        $this->assertSame(2000, app(HourlyCycleConverter::class)->spendable($first));

        $this->travel(1)->hours();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame(0, app(Wallet::class)->balanceOf($customer->id));
    }

    public function test_legacy_credit_suspension_resumes_with_one_hour_including_its_own_reserve(): void
    {
        $customer = $this->funded(2500);
        $service = $this->server($customer, ['status' => 'suspended',
            'suspended_at' => now()->subHours(3), 'last_metered_at' => now()->subHours(3),
            'hold_rate_irt' => 100, 'hold_reserve_irt' => 2400,
            'provision_meta' => ['hourly_suspend' => ['confirmed_at' => now()->subHours(3)->toIso8601String()]]]);
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldReceive('unsuspend')->once()->andReturnTrue();
        $provider->shouldNotReceive('suspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);

        $this->artisan('cloud:meter')->assertSuccessful();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(0, (int) $service->fresh()->hold_reserve_irt);
        $this->assertSame(1500, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, CreditEntry::where('reason', 'cloud_hourly_hold')->count());
        $this->assertSame(1, CreditEntry::where('reason', 'cloud_hourly')->count());
    }

    public function test_other_live_financial_reservations_remain_protected(): void
    {
        $customer = $this->funded(2000);
        $service = $this->server($customer);
        $reservation = app(AiReservations::class)->reserve($customer->id, 1500, 'runtime-ai-reserve');
        $this->assertTrue($reservation->ok);
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldReceive('suspend')->once()->andReturnTrue();
        $provider->shouldNotReceive('unsuspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);

        $this->travel(1)->hours();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame(2000, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(500, app(Wallet::class)->availableOf($customer->id));
    }

    public function test_staff_suspension_is_not_resumed_by_the_credit_policy(): void
    {
        $customer = $this->funded(50000);
        $service = $this->server($customer, ['status' => 'suspended', 'suspended_at' => null]);
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldNotReceive('suspend', 'unsuspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);

        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame(50000, app(Wallet::class)->balanceOf($customer->id));
    }

    public function test_operator_billing_hold_with_old_meter_marker_is_not_resumed_or_charged(): void
    {
        $customer = $this->funded(50000);
        $service = $this->server($customer, ['status' => 'suspended',
            'suspended_at' => now()->subHours(30), 'last_metered_at' => now()->subHours(30),
            'hold_rate_irt' => 100, 'hold_reserve_irt' => 2400,
            'provision_meta' => ['hourly_billing_hold' => ['active' => true],
                'hourly_suspend' => ['confirmed_at' => now()->subHours(30)->toIso8601String()]]]);
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldNotReceive('suspend', 'unsuspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);

        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame(50000, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(2400, (int) $service->fresh()->hold_reserve_irt);
    }

    public function test_failed_power_on_does_not_charge_a_running_hour_or_delete_a_funded_server(): void
    {
        $customer = $this->funded(2500);
        $service = $this->server($customer, ['status' => 'suspended',
            'suspended_at' => now()->subHours(30), 'last_metered_at' => now(),
            'hold_rate_irt' => 100, 'hold_reserve_irt' => 2400,
            'provision_meta' => ['hourly_suspend' => ['confirmed_at' => now()->subHours(30)->toIso8601String()]]]);
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldReceive('unsuspend')->once()->andReturnFalse();
        $provider->shouldReceive('lifecyclePending')->with(\Mockery::type(Service::class), 'running')->andReturnFalse();
        $provider->shouldNotReceive('suspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);

        $this->artisan('cloud:meter')->assertFailed();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame(2500, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(2400, (int) $service->fresh()->hold_reserve_irt);
        $this->assertSame(0, CreditEntry::where('reason', 'cloud_hourly')->count());
    }
}
