<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\ClosedInfrastructureAudit;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClosedInfrastructureAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-07 10:00:00');
        Setting::putSecret('aeza_api_token', 'fixture-only');
        Setting::putSecret('salad_api_key', 'fixture-only');
        Setting::put('salad_org', 'fixture-org');
        config(['billing.internal_service_ids' => []]);
        Mail::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $provider = 'salad', string $status = 'terminated'): Service
    {
        $customer = Customer::create(['name' => 'Closed fixture', 'email' => uniqid('audit-').'@example.test',
            'password' => 'fixture-only', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Closed fixture',
            'currency_code' => 'IRT', 'price' => 100000, 'cycle' => 'monthly', 'status' => $status,
            'next_due_at' => '2026-11-01', 'provision_status' => 'none']);
        CloudInstance::create(['service_id' => $service->id, 'provider' => $provider,
            'provider_ref' => 'fixture-'.$service->id, 'status' => 'running', 'meta' => ['keep' => true]]);
        return $service->fresh();
    }

    private function salad(Service $service, array $instances = [], ?string $name = null, bool $valid = true): void
    {
        $ref = $service->cloudInstance->provider_ref;
        Http::fake([
            '*/'.$ref.'/instances' => Http::response($valid ? ['instances' => $instances] : ['unexpected' => []]),
            '*/'.$ref => Http::response(['name' => $name ?? $ref, 'current_state' => [
                'status' => 'stopped', 'instance_status_counts' => ['running_count' => 0, 'allocating_count' => 0]]]),
        ]);
    }

    public function test_closed_gpu_is_confirmed_stopped_not_deleted_and_witness_expires(): void
    {
        $service = $this->service();
        $before = $service->getAttributes();
        $this->salad($service);
        $this->artisan('cloud:audit-closed')->assertSuccessful();
        $instance = $service->cloudInstance->fresh();
        $this->assertSame('off', $instance->status);
        $this->assertTrue(ClosedInfrastructureAudit::confirmedStopped($instance));
        $this->assertTrue($instance->meta['keep']);
        foreach (['status', 'price', 'next_due_at', 'provision_status'] as $field) {
            $this->assertSame($before[$field], $service->fresh()->getRawOriginal($field));
        }
        $health = collect(app(SystemHealth::class)->checks())->firstWhere('key', 'cloud_release_untracked');
        $this->assertSame('warn', $health['level']);
        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertFalse(ClosedInfrastructureAudit::confirmedStopped($instance));
        $health = collect(app(SystemHealth::class)->checks())->firstWhere('key', 'cloud_release_untracked');
        $this->assertSame('fail', $health['level']);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_a_remaining_instance_prevents_a_no_compute_cost_witness(): void
    {
        $service = $this->service();
        $this->salad($service, [['state' => 'running']]);
        $result = app(ClosedInfrastructureAudit::class)->run();
        $this->assertSame(1, $result['present']);
        $this->assertSame('running', $service->cloudInstance->fresh()->status);
        $this->assertFalse(ClosedInfrastructureAudit::confirmedStopped($service->cloudInstance->fresh()));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_invalid_list_or_wrong_group_cannot_clear_the_failure(): void
    {
        $first = $this->service();
        $second = $this->service();
        $this->salad($first, name: 'other-resource');
        $this->salad($second, valid: false);
        $result = app(ClosedInfrastructureAudit::class)->run();
        $this->assertSame(2, $result['unknown']);
        $this->assertSame('running', $first->cloudInstance->fresh()->status);
        $this->assertSame('running', $second->cloudInstance->fresh()->status);
        $this->assertFalse(ClosedInfrastructureAudit::confirmedStopped($second->cloudInstance->fresh()));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_exact_aeza_deleted_evidence_corrects_only_the_closed_instance(): void
    {
        $service = $this->service('aeza');
        $ref = $service->cloudInstance->provider_ref;
        Http::fake(['*/services/*' => Http::response(['data' => ['items' => [['id' => $ref, 'currentStatus' => 'deleted']]]])]);
        $this->assertSame(1, app(ClosedInfrastructureAudit::class)->run()['deleted']);
        $this->assertSame('deleted', $service->cloudInstance->fresh()->status);
        $this->assertNotNull(data_get($service->cloudInstance->fresh()->meta, 'provider_deleted_verified_at'));
        $this->assertSame('terminated', $service->fresh()->status);
        $this->assertSame('2026-11-01', $service->fresh()->next_due_at->toDateString());
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_active_internal_releasing_and_ambiguous_identities_are_skipped(): void
    {
        $active = $this->service(status: 'active');
        $internal = $this->service();
        $releasing = $this->service();
        $ambiguous = $this->service();
        $duplicate = $this->service(status: 'active');
        $releasing->update(['provision_status' => 'releasing']);
        $duplicate->cloudInstance->update(['provider_ref' => $ambiguous->cloudInstance->provider_ref]);
        config(['billing.internal_service_ids' => [$internal->id]]);
        Http::fake();
        $result = app(ClosedInfrastructureAudit::class)->run();
        $this->assertSame(0, $result['checked']);
        $this->assertSame(2, $result['skipped']);
        Http::assertNothingSent();
        foreach ([$active, $internal, $releasing, $ambiguous] as $service) {
            $this->assertSame('running', $service->cloudInstance->fresh()->status);
        }
    }

    public function test_read_failure_replaces_a_previous_witness_and_404_does_not_mean_deleted(): void
    {
        $service = $this->service();
        $service->cloudInstance->update(['status' => 'off', 'meta' => ['closed_audit' => [
            'outcome' => 'stopped_empty', 'observed_at' => now()->toIso8601String()]]]);
        Http::fake(['*' => Http::response([], 404)]);
        $this->assertSame(1, app(ClosedInfrastructureAudit::class)->run()['unknown']);
        $this->assertFalse(ClosedInfrastructureAudit::confirmedStopped($service->cloudInstance->fresh()));
        $this->assertSame('off', $service->cloudInstance->fresh()->status);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_missing_stale_or_failed_inventory_cannot_produce_a_healthy_audit(): void
    {
        $check = fn () => collect(app(SystemHealth::class)->checks())->firstWhere('key', 'infrastructure_audit');
        $this->assertSame('warn', $check()['level']);
        \Illuminate\Support\Facades\Cache::put(ClosedInfrastructureAudit::LAST_SCAN_KEY,
            ['at' => now()->toIso8601String(), 'unknown' => 0]);
        \Illuminate\Support\Facades\Cache::put(\App\Services\Cloud\FleetScanner::LAST_SCAN_KEY,
            ['at' => now()->toIso8601String(), 'errors' => []]);
        $this->assertSame('ok', $check()['level']);
        Carbon::setTestNow(now()->addMinutes(31));
        $this->assertSame('warn', $check()['level']);
        \Illuminate\Support\Facades\Cache::put(ClosedInfrastructureAudit::LAST_SCAN_KEY,
            ['at' => now()->toIso8601String(), 'unknown' => 1]);
        $this->assertSame('warn', $check()['level']);
    }
}
