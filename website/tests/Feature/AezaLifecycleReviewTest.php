<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Billing\BillingLifecycleActions;
use App\Services\Cloud\AezaClient;
use App\Services\Cloud\AezaLifecycleReview;
use App\Services\Notify\AdminNotifier;
use App\Services\Provisioning\ProvisioningService;
use App\Services\SystemHealth;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AezaLifecycleReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-07 10:00:00');
        Setting::putSecret('aeza_api_token', 'fixture-only');
        config(['billing.internal_service_ids' => []]);
        Mail::fake();
        $this->mock(AdminNotifier::class)->shouldReceive('event')->andReturnNull();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $customer = Customer::create(['name' => 'Lifecycle fixture',
            'email' => uniqid('lifecycle-').'@example.test', 'password' => 'fixture-only', 'status' => 'active']);
        CloudLocation::firstOrCreate(['code' => 'de-fixture'], ['country' => 'DE', 'city' => 'Fixture']);
        $plan = CloudPlan::firstOrCreate(['provider' => 'aeza', 'provider_ref' => 'fixture-plan',
            'location_code' => 'de-fixture'], ['provider_location' => 'fixture-location',
            'public_name' => 'Fixture', 'slug' => 'fixture', 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 50, 'disk_type' => 'ssd', 'price_irt' => 100000, 'is_active' => true, 'in_stock' => true]);
        $service = Service::create(array_merge(['customer_id' => $customer->id, 'name' => 'Lifecycle fixture',
            'currency_code' => 'IRT', 'price' => 100000, 'cycle' => 'monthly', 'billing_mode' => 'monthly',
            'activated_at' => now()->subMonth(), 'next_due_at' => '2026-11-09',
            'cloud_plan_id' => $plan->id, 'status' => 'active', 'provision_status' => 'done'], $attributes));
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'aeza',
            'provider_ref' => 'fixture-'.$service->id, 'status' => 'running', 'meta' => ['keep' => true]]);
        return $service->fresh();
    }

    private function fake(Service $service, string $status, ?string $identity = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/services/*' => Http::response(['data' => ['items' => [[
            'id' => $identity ?? $service->cloudInstance->provider_ref, 'currentStatus' => $status,
            'expiresAt' => '2026-11-05T00:00:00Z', 'autoProlong' => false,
        ]]]])]);
    }

    private function health(): array
    {
        return collect(app(SystemHealth::class)->checks())->firstWhere('key', 'provider_lifecycle');
    }

    public function test_deleted_resource_is_reviewed_without_control_or_shortening_customer_term(): void
    {
        $service = $this->service();
        $this->fake($service, 'deleted');
        $before = $service->getAttributes();
        $result = app(ProvisioningService::class)->suspend($service);
        $this->assertFalse($result->ok);
        $this->assertTrue($result->meta['review']);
        $this->assertSame('deleted', $result->meta['reason']);
        $instance = $service->cloudInstance->fresh();
        $this->assertSame('deleted', $instance->status);
        $this->assertNotNull(data_get($instance->meta, 'provider_deleted_verified_at'));
        $this->assertTrue($instance->meta['keep']);
        foreach (['status', 'next_due_at', 'price', 'cancelled_at', 'suspended_at'] as $field) {
            $this->assertSame($before[$field] ?? null, $service->fresh()->getRawOriginal($field));
        }
        $this->assertSame('fail', $this->health()['level']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_expired_is_not_confirmed_off_and_preserves_supplier_evidence(): void
    {
        $service = $this->service();
        $this->fake($service, 'prolong_wait');
        $result = app(ProvisioningService::class)->suspend($service);
        $this->assertFalse($result->ok);
        $this->assertSame('expired', $result->meta['reason']);
        $instance = $service->cloudInstance->fresh();
        $this->assertSame('unknown', $instance->status);
        $this->assertFalse(data_get($instance->meta, 'provider_billing_observation.auto_prolong'));
        $this->assertSame('2026-11-05T00:00:00Z', data_get($instance->meta, 'provider_billing_observation.expires_at'));
        $this->assertNull(data_get($instance->meta, 'suspend_failed_at'));
        $this->assertSame('active', $service->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_overdue_billing_queues_review_and_backs_off_without_confirmation_or_financial_change(): void
    {
        $service = $this->service(['next_due_at' => '2026-11-05']);
        $invoice = Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 100000, 'tax' => 0,
            'total' => 100000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);
        $this->fake($service, 'prolong_wait');
        $this->assertSame('pending', BillingLifecycleActions::run($service, 'suspend', app(ProvisioningService::class)));
        $state = $service->fresh()->provision_meta['billing_suspend'];
        $this->assertTrue($state['review_required']);
        $this->assertNull($state['confirmed_at']);
        $this->assertSame(now()->addHours(6)->toIso8601String(), $state['retry_at']);
        $this->assertSame('deferred', BillingLifecycleActions::run($service, 'suspend', app(ProvisioningService::class)));
        $this->assertCount(1, Http::recorded());
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertEquals(0, $invoice->fresh()->paid);
        $this->assertSame('2026-11-05', $service->fresh()->next_due_at->toDateString());
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_verified_recovery_clears_review_and_can_confirm_existing_off_state(): void
    {
        $service = $this->service();
        $this->fake($service, 'prolong_wait');
        app(ProvisioningService::class)->suspend($service);
        $this->fake($service, 'stopped');
        $this->assertTrue(app(ProvisioningService::class)->suspend($service->fresh())->ok);
        $this->assertNull(AezaLifecycleReview::forService($service));
        $this->assertSame('off', $service->cloudInstance->fresh()->status);
        $this->assertSame('suspended', $service->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_identity_mismatch_or_unknown_never_clears_a_known_review(): void
    {
        $service = $this->service();
        $this->fake($service, 'prolong_wait');
        app(ProvisioningService::class)->suspend($service);
        $first = data_get($service->cloudInstance->fresh()->meta, 'provider_lifecycle_review.first_observed_at');
        Carbon::setTestNow(now()->addMinute());
        $this->fake($service, 'stopped', 'different-resource');
        $this->assertFalse(app(AezaClient::class)->serverStatus($service->cloudInstance->provider_ref)['ok']);
        $this->assertFalse(app(ProvisioningService::class)->suspend($service->fresh())->ok);
        $this->fake($service, 'unrecognized-state');
        $this->assertFalse(app(ProvisioningService::class)->suspend($service->fresh())->ok);
        $this->assertSame('expired', AezaLifecycleReview::forService($service)['reason']);
        $this->assertSame($first, data_get($service->cloudInstance->fresh()->meta, 'provider_lifecycle_review.first_observed_at'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_supplier_due_mismatch_remains_visible_after_recovery_without_changing_paid_through_date(): void
    {
        $service = $this->service();
        $this->fake($service, 'active');
        $snapshot = app(AezaClient::class)->serverStatus($service->cloudInstance->provider_ref);
        app(AezaLifecycleReview::class)->observe($service->cloudInstance, $snapshot);
        $this->assertSame('fail', $this->health()['level']);
        $this->assertSame('2026-11-09', $service->fresh()->next_due_at->toDateString());
        $this->assertEquals(100000, $service->fresh()->price);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(0, Invoice::count());
    }

    public function test_internal_service_is_not_acted_on_or_counted_as_customer_lifecycle_failure(): void
    {
        $service = $this->service();
        config(['billing.internal_service_ids' => [$service->id]]);
        $this->fake($service, 'deleted');
        $this->assertFalse(app(ProvisioningService::class)->suspend($service)->ok);
        Http::assertNothingSent();
        $this->assertSame('ok', $this->health()['level']);
        $this->assertSame('running', $service->cloudInstance->fresh()->status);
    }
}
