<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudProviderOperation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudProvisioner;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LightNodePowerConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public static function transitions(): array
    {
        return [
            'suspend' => ['suspend', 'stop', 'STARTED', 'STOPPED', 'running', 'off'],
            'restore' => ['unsuspend', 'start', 'STOPPED', 'STARTED', 'off', 'running'],
        ];
    }

    public function test_hourly_meter_treats_an_accepted_power_transition_as_pending_not_failed(): void
    {
        Setting::putSecret('lightnode_api_token', 'test-token');
        ErrorTracker::clear();
        $customer = Customer::create(['name' => 'Meter fixture', 'email' => 'meter@example.test',
            'password' => 'fixture-only', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Hourly LightNode',
            'price' => 100000, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 5000, 'status' => 'active', 'provision_status' => 'done',
            'activated_at' => now()->subDay(), 'last_metered_at' => now()->subHour(),
            'on_credit_out' => 'suspend']);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'lightnode',
            'provider_ref' => 'ecs-meter-fixture', 'status' => 'running', 'ipv4' => '203.0.113.8',
            'ready_notified_at' => now()->subDay()]);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/instance/detail*' => Http::response(['instance' => ['ecsResourceUUID' => 'ecs-meter-fixture',
                'ecsStatus' => 'STARTED', 'ecsPendingStatus' => 'NONE']]),
            '*/instance/stop' => Http::response(['asyncTaskInfo' => ['asyncTaskUUID' => 'meter-stop-task']], 202),
            '*/asynctask/getResult*' => Http::response(['asyncTaskInfo' => ['processResult' => 'PROCESSING', 'taskStatus' => 'RUNNING']]),
        ]);

        $this->artisan('cloud:meter')->assertSuccessful();

        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNull($service->fresh()->suspended_at);
        $this->assertSame('building', $instance->fresh()->status);
        $this->assertSame('off', data_get($instance->fresh()->meta, 'lifecycle_pending.expect'));
        $this->assertNotNull(data_get($service->fresh()->provision_meta, 'hourly_suspend.retry_at'));
        $this->assertSame([], ErrorTracker::recent(50, 'error'));
    }

    public function test_a_receipt_that_fails_during_confirmation_is_not_parked_as_pending(): void
    {
        Setting::putSecret('lightnode_api_token', 'test-token');
        ErrorTracker::clear();
        $customer = Customer::create(['name' => 'Failed receipt', 'email' => 'failed-receipt@example.test',
            'password' => 'fixture-only', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Failed receipt',
            'price' => 100000, 'cycle' => 'monthly', 'status' => 'active']);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'lightnode',
            'provider_ref' => 'ecs-failed-receipt', 'status' => 'running']);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/instance/detail*' => Http::response(['instance' => ['ecsResourceUUID' => 'ecs-failed-receipt',
                'ecsStatus' => 'STARTED', 'ecsPendingStatus' => 'NONE']]),
            '*/instance/stop' => Http::response(['asyncTaskInfo' => ['asyncTaskUUID' => 'failed-power-task']], 202),
            '*/asynctask/getResult*' => Http::sequence()
                ->push(['asyncTaskInfo' => ['processResult' => 'PROCESSING', 'taskStatus' => 'RUNNING']], 200)
                ->push(['asyncTaskInfo' => ['processResult' => 'FAILURE', 'taskStatus' => 'FINISHED']], 200),
        ]);

        $this->assertFalse(app(CloudProvisioner::class)->suspend($service));

        $this->assertSame('failed', CloudProviderOperation::firstOrFail()->state);
        $this->assertNull(data_get($instance->fresh()->meta, 'lifecycle_pending'));
        $this->assertSame('running', $instance->fresh()->status);
        $this->assertStringContainsString('پایان یافته ولی موفق نشده', (string) $instance->fresh()->last_error);
        $this->assertNotSame([], ErrorTracker::recent(50, 'error'));
    }

    #[DataProvider('transitions')]
    public function test_pending_power_is_not_confirmation_and_retry_does_not_repeat_the_command(
        string $method, string $action, string $initial, string $final, string $initialStatus, string $finalStatus
    ): void {
        Setting::putSecret('lightnode_api_token', 'test-token');
        $customer = Customer::create(['name' => 'Power fixture', 'email' => 'power@example.test',
            'password' => 'fixture-only', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Power fixture',
            'price' => 100000, 'cycle' => 'monthly', 'status' => $method === 'suspend' ? 'active' : 'suspended']);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'lightnode',
            'provider_ref' => 'ecs-power-fixture', 'status' => $initialStatus]);

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/instance/detail*' => Http::response(['instance' => ['ecsResourceUUID' => 'ecs-power-fixture',
                'ecsStatus' => $initial, 'ecsPendingStatus' => 'NONE']]),
            '*/instance/'.$action => Http::response(['asyncTaskInfo' => ['asyncTaskUUID' => 'power-task']], 202),
            '*/asynctask/getResult*' => Http::response(['asyncTaskInfo' => ['processResult' => 'PROCESSING', 'taskStatus' => 'RUNNING']]),
        ]);

        $provisioner = app(CloudProvisioner::class);
        $this->assertFalse($provisioner->{$method}($service));
        $this->assertSame('building', $instance->fresh()->status);
        $this->assertSame('pending', CloudProviderOperation::firstOrFail()->state);
        $this->assertFalse($provisioner->{$method}($service));
        $this->assertSame('building', $instance->fresh()->status);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/asynctask/getResult*' => Http::response(['asyncTaskInfo' => ['processResult' => 'SUCCESS', 'taskStatus' => 'FINISHED']]),
            '*/instance/detail*' => Http::response(['instance' => ['ecsResourceUUID' => 'ecs-power-fixture',
                'ecsStatus' => $final, 'ecsPendingStatus' => 'NONE']]),
        ]);
        $this->assertTrue($provisioner->{$method}($service));
        $this->assertSame($finalStatus, $instance->fresh()->status);
        $this->assertSame(0, CloudProviderOperation::count());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
