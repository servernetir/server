<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CloudProviderOperation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\LightNodeClient;
use App\Services\Notify\AdminNotifier;
use App\Services\Provisioning\ProvisioningService;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LightNodeReleaseConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-07 10:00:00');
        Setting::putSecret('lightnode_api_token', 'fixture-only');
        config(['billing.internal_service_ids' => []]);
        Mail::fake();
        $this->mock(AdminNotifier::class)->shouldReceive('event')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $status = 'terminated', string $provider = 'lightnode'): Service
    {
        $customer = Customer::create(['name' => 'Release fixture',
            'email' => uniqid('release-').'@example.test', 'password' => 'fixture-only', 'status' => 'active']);
        CloudLocation::firstOrCreate(['code' => 'us-fixture'], ['country' => 'US', 'city' => 'Fixture']);
        $plan = CloudPlan::firstOrCreate(['provider' => $provider, 'provider_ref' => 'fixture-r|fixture-z|fixture-p',
            'location_code' => 'us-fixture'], ['provider_location' => 'fixture-r|fixture-z',
            'public_name' => 'Fixture', 'slug' => uniqid('fixture-'), 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 50, 'disk_type' => 'ssd', 'price_irt' => 100000, 'is_active' => true, 'in_stock' => true]);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Release fixture',
            'currency_code' => 'IRT', 'price' => 100000, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 1000, 'cloud_plan_id' => $plan->id, 'status' => $status,
            'cancelled_at' => $status === 'terminated' ? now() : null,
            'provision_status' => $status === 'terminated' ? 'releasing' : 'done']);
        CloudInstance::create(['service_id' => $service->id, 'provider' => $provider,
            'provider_ref' => 'ecs-fixture-'.$service->id, 'status' => 'running',
            'meta' => ['preserved' => 'fixture']]);
        return $service->fresh();
    }

    private function journal(Service $service, bool $release = true): void
    {
        $ref = $service->cloudInstance->provider_ref;
        CloudProviderOperation::create(['operation_key' => hash('sha256', 'create:'.$ref),
            'provider' => 'lightnode', 'action' => 'create', 'resource_ref' => $ref,
            'task_ref' => 'create-'.$ref, 'state' => 'success',
            'payload' => ['packageConfig' => ['regionCode' => 'fixture-r', 'zoneCode' => 'fixture-z']]]);
        if ($release) {
            CloudProviderOperation::create(['operation_key' => hash('sha256', 'lightnode:release:'.$ref),
                'provider' => 'lightnode', 'action' => 'release', 'resource_ref' => $ref,
                'task_ref' => 'release-'.$ref, 'state' => 'pending', 'payload' => ['ecsResourceUUID' => $ref]]);
            $service->cloudInstance->update(['meta' => ['preserved' => 'fixture',
                'release_pending' => ['accepted_at' => now()->toIso8601String()]]]);
        }
    }

    private function fake(string $result = 'SUCCESS', array $instances = [], bool $valid = true): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/instance/release' => Http::response(['asyncTaskUUID' => 'fixture-release-task'], 202),
            '*/asynctask/getResult*' => Http::response(['asyncTaskInfo' => [
                'processResult' => $result, 'taskStatus' => $result === 'PROCESSING' ? 'RUNNING' : 'FINISHED']]),
            '*/instance/list*' => Http::response($valid ? ['instances' => $instances] : ['success' => false]),
        ]);
    }

    public function test_an_accepted_delete_is_pending_and_the_minute_job_confirms_without_resending(): void
    {
        $service = $this->service();
        $this->journal($service, release: false);
        $this->fake('PROCESSING');
        $result = app(ProvisioningService::class)->releaseAndTrack($service);
        $this->assertFalse($result->ok);
        $this->assertTrue($result->meta['pending']);
        $this->assertNull($service->cloudInstance->fresh()->last_error);
        $this->assertSame([], ErrorTracker::recent(50, 'error'));
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
        $health = collect(app(\App\Services\SystemHealth::class)->checks())->keyBy('key');
        $this->assertSame('warn', $health['cloud_release']['level']);

        $this->fake();
        $this->artisan('cloud:release-retry --confirm-pending')->assertSuccessful();
        $this->assertSame('terminated', $service->fresh()->status);
        $this->assertSame('none', $service->fresh()->provision_status);
        $this->assertSame('deleted', $service->cloudInstance->fresh()->status);
        $this->assertNotNull(data_get($service->cloudInstance->fresh()->meta, 'provider_absence_verified_at'));
        $this->assertNull(data_get($service->cloudInstance->fresh()->meta, 'release_pending'));
        $this->assertSame('fixture', data_get($service->cloudInstance->fresh()->meta, 'preserved'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_successful_task_with_a_remaining_machine_stays_pending_then_escalates_once(): void
    {
        $service = $this->service();
        $this->journal($service);
        $this->fake(instances: [['ecsResourceUUID' => $service->cloudInstance->provider_ref, 'instanceName' => 'fixture']]);
        $this->artisan('cloud:release-retry --confirm-pending')->assertSuccessful();
        $this->assertSame('releasing', $service->fresh()->provision_status);
        $accepted = data_get($service->cloudInstance->fresh()->meta, 'release_pending.accepted_at');
        $this->assertSame([], ErrorTracker::recent(50, 'error'));

        Carbon::setTestNow(now()->addMinutes(31));
        $this->artisan('cloud:release-retry --confirm-pending')->assertSuccessful();
        $meta = $service->cloudInstance->fresh()->meta;
        $this->assertSame($accepted, data_get($meta, 'release_pending.accepted_at'));
        $this->assertNotNull(data_get($meta, 'release_pending.timed_out_at'));
        $this->assertSame(1, $meta['release_attempts']);
        $this->assertNotSame([], ErrorTracker::recent(50, 'error'));
        $this->artisan('cloud:release-retry --confirm-pending')->assertSuccessful();
        $this->assertSame(1, $service->cloudInstance->fresh()->meta['release_attempts']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_incomplete_inventory_never_closes_a_successful_receipt(): void
    {
        $service = $this->service();
        $this->journal($service);
        $this->fake(valid: false);
        $this->artisan('cloud:release-retry --confirm-pending')->assertSuccessful();
        $this->assertSame('releasing', $service->fresh()->provision_status);
        $this->assertSame('running', $service->cloudInstance->fresh()->status);
        $this->assertNull(data_get($service->cloudInstance->fresh()->meta, 'provider_absence_verified_at'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_confirmation_without_a_release_receipt_cannot_issue_a_new_delete(): void
    {
        $service = $this->service();
        $this->journal($service, release: false);
        $this->fake();
        $result = app(LightNodeClient::class)->confirmRelease($service->cloudInstance->provider_ref);
        $this->assertFalse($result['ok']);
        $this->assertSame(1, CloudProviderOperation::count());
        Http::assertNothingSent();
    }

    public function test_failed_receipts_are_not_silenced_as_pending(): void
    {
        $service = $this->service();
        $this->journal($service);
        $this->fake('FAILURE');
        $this->artisan('cloud:release-retry --confirm-pending')->assertSuccessful();
        $this->assertSame('releasing', $service->fresh()->provision_status);
        $this->assertSame('failed', CloudProviderOperation::where('action', 'release')->value('state'));
        $this->assertNull(data_get($service->cloudInstance->fresh()->meta, 'release_pending'));
        $this->assertNotSame([], ErrorTracker::recent(50, 'error'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_active_internal_and_other_provider_services_are_never_changed_by_confirmation(): void
    {
        $active = $this->service('active');
        $internal = $this->service();
        $other = $this->service(provider: 'aeza');
        $this->journal($active);
        $this->journal($internal);
        $this->journal($other);
        config(['billing.internal_service_ids' => [$internal->id]]);
        $this->fake();
        $this->artisan('cloud:release-retry --confirm-pending')->assertSuccessful();
        $result = app(ProvisioningService::class)->releaseAndTrack($active->fresh(), confirmOnly: true);
        $this->assertFalse($result->ok);
        $this->assertSame('done', $active->fresh()->provision_status);
        $this->assertSame('active', $active->fresh()->status);
        $this->assertSame('releasing', $internal->fresh()->provision_status);
        $this->assertSame('releasing', $other->fresh()->provision_status);
        Http::assertNothingSent();
    }

    public function test_late_success_can_close_an_escalated_release_without_repeating_a_command(): void
    {
        $service = $this->service();
        $this->journal($service);
        $service->cloudInstance->update(['meta' => ['release_pending' => [
            'accepted_at' => now()->subHour()->toIso8601String(), 'timed_out_at' => now()->toIso8601String()]]]);
        $this->fake();
        $this->assertTrue(app(ProvisioningService::class)->releaseAndTrack($service->fresh(), confirmOnly: true)->ok);
        $this->assertSame('none', $service->fresh()->provision_status);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }
}
