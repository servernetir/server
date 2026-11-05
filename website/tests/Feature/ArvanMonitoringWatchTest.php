<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\ArvanMonitoringHealth;
use App\Services\Cloud\ArvanObservationRegistry;
use App\Services\Notify\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArvanMonitoringWatchTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $customer = Customer::create(['email' => 'monitor-fixture@example.test', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic monitor', 'status' => 'active',
            'currency_code' => 'IRT', 'billing_mode' => 'cycle']);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan', 'status' => 'running',
            'provider_ref' => 'ir-test-sh1:00000000-0000-0000-0000-000000000001', 'ipv4' => '192.0.2.1']);
        $identity = ['service_id' => $service->id, 'instance_id' => $instance->id, 'customer_id' => $customer->id,
            'provider_ref' => $instance->provider_ref, 'enrollment_id' => str_repeat('a', 32), 'device' => 'ens3'];
        return [$service, $instance, $identity];
    }

    private function put(string $key, array $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    private function green(array $identity, int $at): void
    {
        $this->put(ArvanObservationRegistry::ENROLLMENT.$identity['service_id'], ['version' => 1, 'provider' => 'arvan',
            'enabled' => true, 'identity' => $identity, 'operator_acceptance_sha256' => str_repeat('b', 64), 'checkpoint_sha256' => str_repeat('c', 64)]);
        $this->put(ArvanObservationRegistry::STATUS.$identity['service_id'], ['version' => 1, 'identity' => $identity,
            'result' => ['status' => 'observed', 'two_fresh_samples_verified' => true, 'checked_at' => $at]]);
    }

    public function test_missing_stale_reset_and_changed_identity_are_unhealthy_without_financial_or_power_actions(): void
    {
        [$service, $instance, $identity] = $this->fixture();
        $health = app(ArvanMonitoringHealth::class);
        $this->assertSame('missing_enrollment', $health->inspect()['issues'][$service->id]);
        $this->green($identity, time()); $this->assertSame([], $health->inspect()['issues']);
        $this->green($identity, time() - 151); $this->assertNotEmpty($health->inspect()['issues']);
        $this->green($identity, time());
        $this->put(ArvanObservationRegistry::STATUS.$service->id, ['version' => 1, 'identity' => $identity,
            'result' => ['status' => 'unknown', 'reason' => 'counter_reset_requires_rebaseline', 'checked_at' => time()]]);
        $this->assertNotEmpty($health->inspect()['issues']);
        $this->green($identity, time());
        $identity['instance_id']++; $this->green($identity, time());
        $this->assertSame('identity_changed', $health->inspect()['issues'][$service->id]);
        $instance->update(['status' => 'unknown']);
        $this->assertSame('instance_state_unverified', $health->inspect()['issues'][$service->id]);
        $instance->update(['status' => 'off']); $this->assertSame(0, $health->inspect()['checked']);
        $this->assertSame(0, DB::table('invoices')->count()); $this->assertSame(0, DB::table('credit_ledger')->count());
        $this->assertSame('active', $service->fresh()->status); Http::assertNothingSent();
    }

    public function test_repeated_failure_is_coalesced_changed_cause_immediate_and_recovery_once(): void
    {
        [, , $identity] = $this->fixture();
        $storage = storage_path('framework/testing/monitor-watch-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($storage, 0700); $this->app->useStoragePath($storage);
        $notify = $this->mock(AdminNotifier::class);
        $notify->shouldReceive('baleEvent')->times(3)->withArgs(fn ($title, $rows, $url) =>
            !str_contains(json_encode($rows), '192.0.2.1') && str_contains($url, '/admin/errors'));
        try {
            $this->assertSame(0, Artisan::call('cloud:watch-arvan-monitoring'));
            $this->assertSame(0, Artisan::call('cloud:watch-arvan-monitoring'));
            $this->green($identity, time() - 151);
            $this->assertSame(0, Artisan::call('cloud:watch-arvan-monitoring'));
            $this->green($identity, time());
            $this->assertSame(0, Artisan::call('cloud:watch-arvan-monitoring'));
            $this->assertSame(0, Artisan::call('cloud:watch-arvan-monitoring'));
        } finally { File::deleteDirectory($storage); }
        Http::assertNothingSent();
    }
}
