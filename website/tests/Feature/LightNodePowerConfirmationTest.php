<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudProviderOperation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudProvisioner;
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
