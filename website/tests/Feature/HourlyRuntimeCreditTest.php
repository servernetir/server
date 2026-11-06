<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\HourlyGpuStart;
use App\Services\Cloud\InterruptibleBillingClock;
use App\Services\Provisioning\ProvisioningService;
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

    private string $liveStatus = 'running';
    private int $httpStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.internal_service_ids' => []]);
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(12, 0));
        Http::preventStrayRequests();
        Setting::putSecret('salad_api_key', 'test-key');
        Setting::put('salad_org', 'test-org');
        Setting::put('salad_project', 'test-project');
        Http::fake(function ($request) {
            if ($this->httpStatus !== 200) {
                return Http::response(['message' => 'rejected'], $this->httpStatus);
            }
            if (str_ends_with($request->url(), '/instances')) {
                return Http::response(['items' => $this->liveStatus === 'running' ? [[
                    'state' => 'running', 'started' => true, 'ready' => true,
                ]] : []]);
            }
            return Http::response(['current_state' => ['status' => $this->liveStatus === 'off'
                ? 'stopped' : $this->liveStatus, 'description' => '']]);
        });
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
        $plan = CloudPlan::create(['provider' => 'salad', 'is_interruptible' => true, 'provider_ref' => uniqid('runtime'),
            'location_code' => 'de-test', 'public_name' => 'Test VPS', 'slug' => uniqid('runtime'),
            'cost_hour_eur_micro' => 8000, 'cost_eur_cents' => 576,
            'price_eur_cents' => 720, 'price_irt' => 720000]);
        $service = Service::create($attributes + ['customer_id' => $customer->id,
            'name' => 'Test hourly VPS', 'currency_code' => 'IRT', 'price' => 720000,
            'cycle' => 'monthly', 'billing_mode' => 'hourly', 'hourly_rate_irt' => 1000,
            'status' => 'active', 'provision_status' => 'done', 'activated_at' => now(),
            'last_metered_at' => now(), 'on_credit_out' => 'suspend', 'cloud_plan_id' => $plan->id]);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'salad',
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
        $reserve = 24 * app(HourlyHold::class)->rateForPlan($plan, $rate);
        $minimum = 24 * $rate + $reserve;
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
        $this->assertSame($reserve, (int) Service::where('customer_id', $customer->id)->first()->hold_reserve_irt);
        $this->assertSame(23 * $rate, app(Wallet::class)->availableOf($customer->id));
    }

    public function test_paid_standby_is_protected_separately_and_not_spent_on_running_hours(): void
    {
        $customer = $this->funded(43200);
        $service = $this->server($customer);
        $service->cloudPlan->update(['provider' => 'aeza', 'is_interruptible' => false]);
        $service->cloudInstance->update(['provider' => 'aeza']);
        app(HourlyHold::class)->syncRunning($service->fresh());
        $this->assertSame(19200, (int) $service->fresh()->hold_reserve_irt);
        $this->assertSame(24000, app(Wallet::class)->availableOf($customer->id));
        app(Wallet::class)->debit($customer->id, 'IRT', 1000, 'cloud_hourly', $service);
        $this->travel(1)->hours();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(22000, app(Wallet::class)->availableOf($customer->id));
        $this->assertSame(19200, (int) $service->fresh()->hold_reserve_irt);
        $this->assertSame(41200, app(HourlyCycleConverter::class)->spendable($service->fresh()));
    }

    public function test_credit_suspended_gpu_resumes_with_just_one_hour_and_charges_once(): void
    {
        $customer = $this->funded(1000);
        $service = $this->server($customer, ['status' => 'suspended',
            'suspended_at' => now()->subHours(23), 'last_metered_at' => now()->subHours(23),
            'provision_meta' => ['hourly_suspend' => ['confirmed_at' => now()->subHours(23)->toIso8601String()]]]);
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldReceive('unsuspend')->once()->andReturnTrue();
        $provider->shouldNotReceive('suspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(0, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, (int) $service->fresh()->hold_reserve_irt);
        $this->assertSame(1, CreditEntry::where('reason', 'cloud_hourly')->count());
        $this->assertSame(0, CreditEntry::where('reason', 'cloud_hourly_hold')->count());
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
            'hold_rate_irt' => 0, 'hold_reserve_irt' => 0,
            'provision_meta' => ['hourly_suspend' => ['confirmed_at' => now()->subHours(30)->toIso8601String()]]]);
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldReceive('unsuspend')->once()->andReturnFalse();
        $provider->shouldReceive('lifecyclePending')->with(\Mockery::type(Service::class), 'running')->andReturnFalse();
        $provider->shouldNotReceive('suspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);

        $this->artisan('cloud:meter')->assertFailed();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame(2500, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, (int) $service->fresh()->hold_reserve_irt);
        $this->assertSame(0, CreditEntry::where('reason', 'cloud_hourly')->count());
    }

    public function test_restart_needs_one_hour_not_twenty_four_and_prepaid_hour_is_not_cut_short(): void
    {
        $customer = $this->funded(1000);
        $service = $this->server($customer);
        $service->cloudInstance->update(['status' => 'off']);
        $this->actingAs($customer, 'customer')->post(route('account.cloud.power', $service), ['action' => 'on'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1000, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, app(Wallet::class)->availableOf($customer->id));
        $this->assertSame(1000, HourlyGpuStart::pending($service->fresh()));
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldReceive('suspend')->once()->andReturnTrue();
        $provider->shouldNotReceive('unsuspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame(0, HourlyGpuStart::pending($service->fresh()));
        $this->assertSame(0, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(1, CreditEntry::where('reason', 'cloud_hourly')->count());
        $this->travel(59)->minutes();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->travel(1)->minutes();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_restart_below_one_hour_rejects_before_sending_a_power_command(): void
    {
        $customer = $this->funded(999);
        $service = $this->server($customer);
        $service->cloudInstance->update(['status' => 'off']);
        $this->actingAs($customer, 'customer')->post(route('account.cloud.power', $service), ['action' => 'on'])
            ->assertSessionHasErrors();
        $this->assertSame('off', $service->cloudInstance->fresh()->status);
        $this->assertSame(999, app(Wallet::class)->availableOf($customer->id));
        $this->assertSame(0, CreditEntry::where('reason', 'cloud_hourly')->count());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/start'));
    }

    public function test_pending_start_reserves_its_hour_without_billing_until_confirmed_running(): void
    {
        $this->liveStatus = 'off';
        $customer = $this->funded(1000);
        $service = $this->server($customer);
        $service->cloudInstance->update(['status' => 'off']);
        $this->actingAs($customer, 'customer')->post(route('account.cloud.power', $service), ['action' => 'on'])
            ->assertSessionHasNoErrors();
        $this->travel(3)->hours();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(1000, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, app(Wallet::class)->availableOf($customer->id));
        $this->liveStatus = 'running';
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame(0, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(1, CreditEntry::where('reason', 'cloud_hourly')->count());
    }

    public function test_unfunded_already_off_gpu_gets_twenty_four_hours_then_exactly_one_release_request(): void
    {
        $this->liveStatus = 'off';
        $customer = $this->funded(1);
        $service = $this->server($customer, ['on_credit_out' => 'terminate']);
        $service->cloudInstance->update(['status' => 'off']);
        $provider = \Mockery::mock(CloudProvisioner::class);
        $provider->shouldReceive('suspend')->once()->andReturnTrue();
        $provider->shouldNotReceive('unsuspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $provider);
        $release = \Mockery::mock(ProvisioningService::class);
        $release->shouldReceive('releaseAndTrack')->once()->with(\Mockery::on(fn ($s) => $s->id === $service->id && $s->status === 'terminated'))->andReturn(\App\Services\Provisioning\ProvisionResult::success(null, null, null));
        $this->app->instance(ProvisioningService::class, $release);
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNotNull($service->fresh()->suspended_at);
        $this->travel(23)->hours();
        $this->travel(59)->minutes();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->travel(1)->minutes();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('terminated', $service->fresh()->status);
        $this->assertSame(1, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, CreditEntry::where('reason', 'cloud_hourly_hold')->count());
    }

    public function test_a_rejected_restart_releases_the_unused_hour(): void
    {
        $customer = $this->funded(1000);
        $service = $this->server($customer);
        $service->cloudInstance->update(['status' => 'off']);
        $this->httpStatus = 400;
        $this->actingAs($customer, 'customer')->post(route('account.cloud.power', $service), ['action' => 'on'])
            ->assertSessionHasErrors();
        $this->assertSame(1000, app(Wallet::class)->availableOf($customer->id));
        $this->assertSame(0, HourlyGpuStart::pending($service->fresh()));
        $this->assertSame('off', $service->cloudInstance->fresh()->status);
    }

    public function test_restart_cannot_spend_another_financial_reservation(): void
    {
        $customer = $this->funded(2000);
        $service = $this->server($customer);
        $service->cloudInstance->update(['status' => 'off']);
        $this->assertTrue(app(AiReservations::class)->reserve($customer->id, 1500, 'restart-protected')->ok);
        $this->actingAs($customer, 'customer')->post(route('account.cloud.power', $service), ['action' => 'on'])
            ->assertSessionHasErrors();
        $this->assertSame(500, app(Wallet::class)->availableOf($customer->id));
        $this->assertSame(0, HourlyGpuStart::pending($service->fresh()));
    }

    public function test_cancelled_pending_start_returns_its_reserved_hour(): void
    {
        $customer = $this->funded(1000);
        $service = $this->server($customer);
        $service->cloudInstance->update(['status' => 'off']);
        $this->actingAs($customer, 'customer')->post(route('account.cloud.power', $service), ['action' => 'on'])
            ->assertSessionHasNoErrors();
        $this->post(route('account.cloud.power', $service), ['action' => 'off'])->assertSessionHasNoErrors();
        $this->assertSame(1000, app(Wallet::class)->availableOf($customer->id));
        $this->assertSame(0, HourlyGpuStart::pending($service->fresh()));
        $this->assertSame(0, CreditEntry::where('reason', 'cloud_hourly')->count());
    }

    public function test_grace_deletion_rechecks_funding_before_closing_the_service(): void
    {
        $customer = $this->funded(1000);
        $service = $this->server($customer, ['status' => 'suspended', 'suspended_at' => now()->subHours(24)]);
        $release = \Mockery::mock(ProvisioningService::class);
        $release->shouldNotReceive('releaseAndTrack');
        $this->app->instance(ProvisioningService::class, $release);
        $command = app(\App\Console\Commands\CloudMeterHourly::class);
        (new \ReflectionMethod($command, 'closeAndRelease'))->invoke($command, $service, 'expired');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame(1000, app(Wallet::class)->balanceOf($customer->id));
    }

    public function test_paid_standby_cost_is_not_capped_by_an_underwater_sale_rate(): void
    {
        $customer = $this->funded(1000);
        $service = $this->server($customer);
        $plan = $service->cloudPlan;
        $plan->update(['provider' => 'aeza', 'is_interruptible' => false, 'cost_hour_eur_micro' => 20000]);
        $this->assertSame(2000, app(HourlyHold::class)->rateForPlan($plan, 1000));
        $plan->update(['cost_hour_eur_micro' => 0, 'cost_eur_cents' => 0]);
        $this->assertFalse(app(HourlyHold::class)->fundablePlan($plan, 1000));
    }

    public function test_cached_running_reply_from_before_restart_never_spends_the_reserved_hour(): void
    {
        $customer = $this->funded(1000);
        $service = $this->server($customer);
        $instance = $service->cloudInstance;
        $instance->update(['status' => 'off']);
        $key = 'cloud-st:'.$instance->id;
        $stale = ['ok' => true, 'status' => 'running', 'ipv4' => '192.0.2.10',
            'ipv6' => null, 'hostname' => 'test', '_observed_at' => now()->subMinute()->toISOString()];
        \Illuminate\Support\Facades\Cache::put($key, $stale, 20);
        $this->actingAs($customer, 'customer')->post(route('account.cloud.power', $service), ['action' => 'on'])
            ->assertSessionHasNoErrors();
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has($key));
        // An older in-flight poll can fill the cache again after invalidation.
        \Illuminate\Support\Facades\Cache::put($key, $stale, 20);
        $this->get(route('account.cloud.status', $service))->assertOk();
        $this->assertSame(1000, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(1000, HourlyGpuStart::pending($service->fresh()));
        $this->assertSame('building', $instance->fresh()->status);
        $this->travel(21)->seconds();
        $this->get(route('account.cloud.status', $service))->assertOk();
        $this->assertSame(0, HourlyGpuStart::pending($service->fresh()));
        $this->assertSame(0, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(1, CreditEntry::where('reason', 'cloud_hourly')->count());
    }

    public function test_a_restart_is_not_charged_before_the_power_command_is_accepted(): void
    {
        $customer = $this->funded(1000);
        $service = $this->server($customer);
        $instance = $service->cloudInstance;
        $instance->update(['status' => 'off']);
        $this->assertArrayHasKey('claimed', app(HourlyGpuStart::class)->claim($service));
        app(InterruptibleBillingClock::class)->record($service->fresh(), $instance->fresh(),
            'running', observedAt: now());
        $this->assertSame(1000, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(1000, HourlyGpuStart::pending($service->fresh()));
        $this->assertSame('building', $instance->fresh()->status);
    }

    public function test_confirmed_restart_honors_the_usage_discount_and_releases_its_unused_hold(): void
    {
        $customer = $this->funded(1000);
        $service = $this->server($customer);
        $service->cloudInstance->update(['status' => 'off']);
        $calculator = \Mockery::mock(\App\Services\Birthday\BirthdayHourly::class)->makePartial();
        $calculator->shouldReceive('rates')->once()->with(\Mockery::on(fn ($s) => $s->id === $service->id),
            \Mockery::type(\Illuminate\Support\Carbon::class), 1, 1000)->andReturn([800]);
        $this->app->instance(\App\Services\Birthday\BirthdayHourly::class, $calculator);
        $this->actingAs($customer, 'customer')->post(route('account.cloud.power', $service), ['action' => 'on'])
            ->assertSessionHasNoErrors();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame(200, app(Wallet::class)->availableOf($customer->id));
        $this->assertSame(0, HourlyGpuStart::pending($service->fresh()));
        $this->assertSame(-800, (int) CreditEntry::where('reason', 'cloud_hourly')->sole()->amount);
    }
}
