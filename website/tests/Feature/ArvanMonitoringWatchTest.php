<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\ArvanMonitoringHealth;
use App\Services\Cloud\ArvanObservationRegistry;
use App\Services\Cloud\ArvanObservationState;
use App\Services\Cloud\UnixPrometheusTrafficTransport;
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

    private ?string $nativeRoot = null;
    private mixed $nativeSocket = null;

    protected function tearDown(): void
    {
        if (is_resource($this->nativeSocket)) { fclose($this->nativeSocket); }
        if ($this->nativeRoot !== null) { File::deleteDirectory($this->nativeRoot); }
        parent::tearDown();
    }

    private function socket(): string
    {
        return $this->nativeRoot.UnixPrometheusTrafficTransport::RELATIVE;
    }

    private function openNativeSocket(): void
    {
        $this->nativeSocket = stream_socket_server('unix://'.$this->socket(), $errno, $error);
        $this->assertIsResource($this->nativeSocket);
        chmod($this->socket(), 0600);
    }

    private function centralReady(): void
    {
        $this->putRecord(ArvanObservationRegistry::TRANSPORT, ['version' => 1, 'enabled' => true,
            'operator_acceptance_sha256' => str_repeat('d', 64), 'approved_at' => time() - 60,
            'pin' => UnixPrometheusTrafficTransport::capture($this->nativeRoot)]);
        $this->putRecord(ArvanObservationRegistry::RUN, ['version' => 1, 'checked_at' => time(),
            'status' => 'enabled_observation_only', 'checked' => 1, 'observed' => 1, 'unknown' => 0]);
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $this->nativeRoot = sys_get_temp_dir().'/sn-mh-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists(dirname($this->socket()), 0700);
        chmod(dirname($this->socket()), 0700);
        $this->openNativeSocket();
        $this->app->bind(ArvanMonitoringHealth::class, fn () => new ArvanMonitoringHealth($this->nativeRoot));
        $this->centralReady();
        $customer = Customer::create(['email' => 'monitor-fixture@example.test', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic monitor', 'status' => 'active',
            'currency_code' => 'IRT', 'billing_mode' => 'cycle']);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan', 'status' => 'running',
            'provider_ref' => 'ir-test-sh1:00000000-0000-0000-0000-000000000001', 'ipv4' => '192.0.2.1']);
        $identity = ['service_id' => $service->id, 'instance_id' => $instance->id, 'customer_id' => $customer->id,
            'provider_ref' => $instance->provider_ref, 'enrollment_id' => str_repeat('a', 32), 'device' => 'ens3'];
        return [$service, $instance, $identity];
    }

    private function putRecord(string $key, array $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => ArvanObservationState::encode($value)]);
    }

    private function green(array $identity, int $at, ?int $sampleAt = null): void
    {
        $sample = ['identity' => $identity, 'sample_at' => $sampleAt ?? $at, 'collected_at' => $at,
            'boot_at' => ($sampleAt ?? $at) - 3600, 'rx_bytes' => 1000, 'tx_bytes' => 2000,
            'collector_up' => true, 'netdev_up' => true];
        $checkpoint = ['version' => 1, 'sequence' => 2, 'fresh_samples' => 2,
            'sample' => $sample, 'previous_sha256' => str_repeat('c', 64)];
        $this->putRecord(ArvanObservationRegistry::CHECKPOINT.$identity['service_id'].':'.$identity['enrollment_id'], $checkpoint);
        $this->putRecord(ArvanObservationRegistry::ENROLLMENT.$identity['service_id'], ['version' => 1, 'provider' => 'arvan',
            'enabled' => true, 'identity' => $identity, 'operator_acceptance_sha256' => str_repeat('b', 64),
            'checkpoint_sha256' => hash('sha256', ArvanObservationState::encode($checkpoint))]);
        $this->putRecord(ArvanObservationRegistry::STATUS.$identity['service_id'], ['version' => 1, 'identity' => $identity,
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
        $this->putRecord(ArvanObservationRegistry::STATUS.$service->id, ['version' => 1, 'identity' => $identity,
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
    public function test_fresh_green_rows_cannot_mask_disabled_changed_or_missing_private_transport(): void
    {
        [$service, , $identity] = $this->fixture();
        $this->green($identity, time());
        $health = app(ArvanMonitoringHealth::class);
        $this->assertSame([], $health->inspect()['issues']);
        $transport = json_decode(DB::table('settings')->where('key', ArvanObservationRegistry::TRANSPORT)->value('value'), true);
        $this->putRecord(ArvanObservationRegistry::TRANSPORT, array_replace($transport, ['enabled' => false]));
        $report = $health->inspect();
        $this->assertSame('unverified', $report['central_status']);
        $this->assertSame('central_transport_unavailable', $report['issues'][$service->id]);
        $this->centralReady();

        chmod($this->socket(), 0644);
        $this->assertSame('central_transport_unavailable', $health->inspect()['issues'][$service->id]);
        chmod($this->socket(), 0600);
        $this->assertSame([], $health->inspect()['issues']);

        $oldSocket = $this->nativeSocket; unlink($this->socket());
        $this->assertSame('central_transport_unavailable', $health->inspect()['issues'][$service->id]);
        // A recreated socket is not the approved socket, even under the same UID/path/mode.
        $this->openNativeSocket();
        $this->assertSame('central_transport_unavailable', $health->inspect()['issues'][$service->id]);
        fclose($oldSocket);
        $this->centralReady();
        $this->assertSame([], $health->inspect()['issues']);
        Http::assertNothingSent();
    }

    public function test_missing_stale_future_or_invalid_runner_cannot_recover_from_old_green_rows(): void
    {
        [$service, , $identity] = $this->fixture();
        $this->green($identity, time());
        $health = app(ArvanMonitoringHealth::class);
        DB::table('settings')->where('key', ArvanObservationRegistry::RUN)->delete();
        $this->assertSame('central_runner_unavailable', $health->inspect()['issues'][$service->id]);
        $valid = ['version' => 1, 'checked_at' => time(), 'status' => 'enabled_observation_only',
            'checked' => 1, 'observed' => 1, 'unknown' => 0];
        foreach ([
            ['checked_at' => time() - 151], ['checked_at' => time() + 60], ['version' => 2],
            ['status' => 'disabled'], ['status' => 'transport_approved_awaiting_observation'],
            ['checked' => -1], ['checked' => 21], ['unknown' => 1], ['observed' => '1'],
        ] as $invalid) {
            $this->putRecord(ArvanObservationRegistry::RUN, array_replace($valid, $invalid));
            $this->assertSame('central_runner_unavailable', $health->inspect()['issues'][$service->id]);
        }
        $this->centralReady();
        $this->assertSame([], $health->inspect()['issues']);
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('credit_ledger')->count());
        Http::assertNothingSent();
    }

    public function test_preapproval_sample_and_transport_loss_during_inspection_are_not_healthy(): void
    {
        [$service, , $identity] = $this->fixture();
        $transport = json_decode(DB::table('settings')->where('key', ArvanObservationRegistry::TRANSPORT)->value('value'), true);
        $this->green($identity, time(), $transport['approved_at'] - 1);
        $health = app(ArvanMonitoringHealth::class);
        $this->assertSame('observation_predates_transport_approval', $health->inspect()['issues'][$service->id]);
        $this->green($identity, time());
        $removed = false;
        DB::listen(function ($event) use ($service, &$removed): void {
            if (!$removed && in_array(ArvanObservationRegistry::STATUS.$service->id, $event->bindings, true)) {
                $removed = true;
                fclose($this->nativeSocket); $this->nativeSocket = null; unlink($this->socket());
            }
        });
        $this->assertSame('central_transport_unavailable', $health->inspect()['issues'][$service->id]);
        $this->assertTrue($removed);
        Http::assertNothingSent();
    }

    public function test_central_loss_with_fresh_target_data_alerts_without_false_recovery_or_repeated_alarm(): void
    {
        [, , $identity] = $this->fixture(); $this->green($identity, time());
        $storage = storage_path('framework/testing/monitor-central-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($storage.'/app/private/arvan-monitoring-watch', 0700);
        $this->app->useStoragePath($storage);
        File::put($storage.'/app/private/arvan-monitoring-watch/last-attempt.json',
            json_encode(['bad' => true, 'signature' => str_repeat('f', 64), 'attempted_at' => time()]));
        fclose($this->nativeSocket); $this->nativeSocket = null; unlink($this->socket());
        $notify = $this->mock(AdminNotifier::class);
        $notify->shouldReceive('baleEvent')->once()->withArgs(fn ($title, $rows, $url) =>
            str_contains($title, 'اتصال مرکزی') && !str_contains($title, 'دوباره برقرار')
            && !str_contains(json_encode($rows), '192.0.2.1') && str_contains($url, '/admin/errors'));
        try {
            $this->assertSame(0, Artisan::call('cloud:watch-arvan-monitoring'));
            $this->assertSame(0, Artisan::call('cloud:watch-arvan-monitoring'));
        } finally { File::deleteDirectory($storage); }
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('credit_ledger')->count());
        Http::assertNothingSent();
    }

    public function test_actual_scrape_age_and_hash_bound_history_are_required_despite_fresh_checks(): void
    {
        [$service, , $identity] = $this->fixture();
        $transport = json_decode(DB::table('settings')->where('key', ArvanObservationRegistry::TRANSPORT)->value('value'), true);
        $this->putRecord(ArvanObservationRegistry::TRANSPORT, array_replace($transport, ['approved_at' => time() - 3600]));
        // Accepted when 130 seconds old; now the sample is 180 seconds old but the check only 50.
        $this->green($identity, time() - 50, time() - 180);
        $health = app(ArvanMonitoringHealth::class);
        $this->assertNotEmpty($health->inspect()['issues']);
        $this->green($identity, time());
        $this->assertSame([], $health->inspect()['issues']);
        $key = ArvanObservationRegistry::CHECKPOINT.$identity['service_id'].':'.$identity['enrollment_id'];
        DB::table('settings')->where('key', $key)->update(['value' => '{}']);
        $this->assertSame('invalid_monitoring_record', $health->inspect()['issues'][$service->id]);
        $this->assertSame(0, DB::table('invoices')->count());
        $this->assertSame(0, DB::table('credit_ledger')->count());
        Http::assertNothingSent();
    }

    public function test_transport_disabled_during_target_read_cannot_report_recovery(): void
    {
        [$service, , $identity] = $this->fixture(); $this->green($identity, time());
        $disabled = false;
        DB::listen(function ($event) use ($service, &$disabled): void {
            if (!$disabled && in_array(ArvanObservationRegistry::STATUS.$service->id, $event->bindings, true)) {
                $disabled = true;
                $t = json_decode(DB::table('settings')->where('key', ArvanObservationRegistry::TRANSPORT)->value('value'), true);
                $this->putRecord(ArvanObservationRegistry::TRANSPORT, array_replace($t, ['enabled' => false]));
            }
        });
        $report = app(ArvanMonitoringHealth::class)->inspect();
        $this->assertTrue($disabled);
        $this->assertSame('central_transport_unavailable', $report['issues'][$service->id]);
        $this->assertSame('unverified', $report['central_status']);
        Http::assertNothingSent();
    }

}
