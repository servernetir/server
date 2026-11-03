<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\ArvanObservationRegistry;
use App\Services\Cloud\ArvanObservationApprovalFile;
use App\Services\Cloud\ArvanObservationState;
use App\Services\Cloud\UnixPrometheusTrafficTransport;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Actual isolated DB commits, fake samples, and a disposable UDS metadata inode only. No connections or finance calls. */
class ArvanObservationRunnerTest extends TestCase
{
    private string $fixtureRoot;
    private $socket;
    private ArvanObservationRegistry $registry;
    private int $reads = 0;
    private int $boot;

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = config('database.connections.'.config('database.default'));
        $this->assertSame('testing', app()->environment());
        if (($cfg['driver'] ?? null) === 'sqlite') {
            $this->assertSame(':memory:', $cfg['database']);
        } else {
            $this->assertContains($cfg['driver'], ['mysql', 'mariadb']);
            $this->assertSame('127.0.0.1', $cfg['host']);
            $this->assertSame('incident_testing', $cfg['database']);
            $this->assertSame('1', getenv('ARVAN_TRAFFIC_MARIADB'));
        }
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        RefreshDatabaseState::$migrated = false;
        Http::preventStrayRequests();
        $this->boot = time() - 10000;
        $this->fixtureRoot = sys_get_temp_dir().'/sno-'.bin2hex(random_bytes(5));
        $directory = $this->fixtureRoot.'/storage/app/private/arvan-observation';
        $this->assertTrue(mkdir($directory, 0700, true));
        chmod($directory, 0700);
        $path = $this->fixtureRoot.UnixPrometheusTrafficTransport::RELATIVE;
        $this->socket = stream_socket_server('unix://'.$path, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        $this->assertIsResource($this->socket, 'Disposable Unix metadata listener must be available in CI');
        chmod($path, 0600);
        $this->registry = new ArvanObservationRegistry($this->fixtureRoot);
        $this->registry->approve(['version' => 1, 'kind' => 'transport', 'enabled' => true,
            'operator_acceptance_sha256' => str_repeat('c', 64), 'pin' => UnixPrometheusTrafficTransport::capture($this->fixtureRoot)]);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->socket)) { fclose($this->socket); }
        if (isset($this->fixtureRoot)) { (new Filesystem)->deleteDirectory($this->fixtureRoot); }
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function observationFixture(): array
    {
        $customer = Customer::create(['email' => 'observation-'.bin2hex(random_bytes(5)).'@example.test', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic observation', 'currency_code' => 'IRT',
            'billing_mode' => 'cycle', 'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done',
            'provision_meta' => ['staff_marker' => 'preserve', 'hourly_billing_hold' => ['active' => true, 'reason' => 'operator']]]);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan', 'status' => 'running',
            'provider_ref' => 'ir-test-sh1:00000000-0000-0000-0000-'.str_pad((string) $service->id, 12, '0', STR_PAD_LEFT), 'ipv4' => '192.0.2.1']);
        $identity = ['service_id' => (int) $service->id, 'instance_id' => (int) $instance->id, 'customer_id' => (int) $customer->id,
            'provider_ref' => $instance->provider_ref, 'enrollment_id' => md5('synthetic-enrollment-'.$service->id), 'device' => 'ens3'];
        $this->approveObservationIdentity($identity);
        return [$customer, $service, $instance, $identity];
    }

    private function approveObservationIdentity(array $identity, ?string $previous = null, bool $enabled = true): array
    {
        return $this->registry->approve(['version' => 1, 'kind' => 'enrollment', 'enabled' => $enabled, 'identity' => $identity,
            'expected_previous_enrollment_id' => $previous, 'operator_acceptance_sha256' => str_repeat('d', 64)]);
    }

    private function rawObservationRecord(string $key): ?string { return DB::table('settings')->where('key', $key)->value('value'); }
    private function observationRecord(string $key): ?array { return ArvanObservationState::decode($this->rawObservationRecord($key)); }
    private function putObservationRecord(string $key, array $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => ArvanObservationState::encode($value)]);
    }
    private function observationCheckpointKey(array $identity): string
    {
        return ArvanObservationRegistry::CHECKPOINT.$identity['service_id'].':'.$identity['enrollment_id'];
    }
    private function observationSample(array $identity, int $at, int $rx = 1000, int $tx = 2000): array
    {
        return ['identity' => $identity, 'sample_at' => $at, 'collected_at' => $at, 'collector_up' => true,
            'netdev_up' => true, 'rx_bytes' => $rx, 'tx_bytes' => $tx, 'boot_at' => $this->boot];
    }
    private function observationSource(Closure $read): Closure
    {
        return function ($transport) use ($read): object {
            $this->assertInstanceOf(UnixPrometheusTrafficTransport::class, $transport);
            return new class($read) {
                public function __construct(private Closure $read) {}
                public function read(array $identity, int $now): array { return ($this->read)($identity, $now); }
            };
        };
    }
    private function pollObservation(array $identity, array $sample): array
    {
        return $this->registry->poll($identity['service_id'], $this->observationSource(function (array $actual) use ($identity, $sample): array {
            $this->reads++;
            $this->assertSame(0, DB::transactionLevel(), 'The source must run after current DB locks are released');
            $this->assertSame($identity, $actual);
            return ['status' => 'observed', 'sample' => $sample];
        }));
    }
    private function assertGreenObservation(array $identity): void
    {
        $this->assertSame('baseline', $this->pollObservation($identity, $this->observationSample($identity, time() - 60))['status']);
        $this->assertTrue($this->pollObservation($identity, $this->observationSample($identity, time(), 1500, 2600))['two_fresh_samples_verified']);
    }

    public function test_two_distinct_fresh_samples_commit_a_prospective_checkpoint_without_finance_or_power(): void
    {
        [, $service, $instance, $identity] = $this->observationFixture();
        $serviceBefore = $service->fresh()->getAttributes(); $instanceBefore = $instance->fresh()->getAttributes();
        $baseline = $this->pollObservation($identity, $this->observationSample($identity, time() - 60));
        $this->assertSame('baseline', $baseline['status']); $this->assertFalse($baseline['two_fresh_samples_verified']);
        $this->assertNull($baseline['rx_delta_bytes']);
        $second = $this->pollObservation($identity, $this->observationSample($identity, time(), 1500, 2600));
        $this->assertSame('observed', $second['status']); $this->assertTrue($second['two_fresh_samples_verified']);
        $this->assertSame(500, $second['rx_delta_bytes']); $this->assertSame(600, $second['tx_delta_bytes']);
        $state = $this->observationRecord($this->observationCheckpointKey($identity));
        $this->assertSame(2, $state['sequence']); $this->assertSame(2, $state['fresh_samples']);
        $this->assertSame(hash('sha256', $this->rawObservationRecord($this->observationCheckpointKey($identity))), $this->observationRecord(ArvanObservationRegistry::ENROLLMENT.$service->id)['checkpoint_sha256']);
        $this->assertSame($serviceBefore, $service->fresh()->getAttributes());
        $this->assertSame($instanceBefore, $instance->fresh()->getAttributes());
        $this->assertSame(0, CreditEntry::count()); Http::assertNothingSent();
    }

    public function test_replayed_source_scrape_does_not_advance_history_or_claim_two_fresh_samples(): void
    {
        [, , , $identity] = $this->observationFixture(); $sample = $this->observationSample($identity, time() - 60);
        $this->pollObservation($identity, $sample); $before = $this->rawObservationRecord($this->observationCheckpointKey($identity));
        $result = $this->pollObservation($identity, $sample);
        $this->assertSame('unknown', $result['status']); $this->assertFalse($result['two_fresh_samples_verified']);
        $this->assertSame($before, $this->rawObservationRecord($this->observationCheckpointKey($identity)));
        $this->assertSame('unknown', $this->observationRecord(ArvanObservationRegistry::STATUS.$identity['service_id'])['result']['status']);
    }

    public function test_counter_reset_preserves_prior_checkpoint_and_persists_unknown(): void
    {
        [, , , $identity] = $this->observationFixture();
        $this->pollObservation($identity, $this->observationSample($identity, time() - 60)); $before = $this->rawObservationRecord($this->observationCheckpointKey($identity));
        $result = $this->pollObservation($identity, $this->observationSample($identity, time(), 1, 1));
        $this->assertSame('counter_reset_requires_rebaseline', $result['reason']);
        $this->assertSame($before, $this->rawObservationRecord($this->observationCheckpointKey($identity)));
        $this->assertFalse($result['two_fresh_samples_verified']);
    }

    public function test_long_gap_does_not_silently_create_another_baseline(): void
    {
        [, , , $identity] = $this->observationFixture(); $past = time() - 200;
        $checkpoint = ArvanObservationState::advance($identity, null, $this->observationSample($identity, $past), $past)['checkpoint'];
        $this->putObservationRecord($this->observationCheckpointKey($identity), $checkpoint);
        $enrollment = $this->observationRecord(ArvanObservationRegistry::ENROLLMENT.$identity['service_id']);
        $enrollment['checkpoint_sha256'] = hash('sha256', ArvanObservationState::encode($checkpoint));
        $this->putObservationRecord(ArvanObservationRegistry::ENROLLMENT.$identity['service_id'], $enrollment);
        $before = $this->rawObservationRecord($this->observationCheckpointKey($identity));
        $result = $this->pollObservation($identity, $this->observationSample($identity, time(), 2000, 3000));
        $this->assertSame('sample_gap_requires_rebaseline', $result['reason']);
        $this->assertSame($before, $this->rawObservationRecord($this->observationCheckpointKey($identity)));
    }

    public function test_missing_previously_started_history_cannot_remain_green_or_rebaseline(): void
    {
        [, , , $identity] = $this->observationFixture(); $this->assertGreenObservation($identity);
        $enrollmentBefore = $this->rawObservationRecord(ArvanObservationRegistry::ENROLLMENT.$identity['service_id']);
        DB::table('settings')->where('key', $this->observationCheckpointKey($identity))->delete(); $reads = $this->reads;
        $result = $this->pollObservation($identity, $this->observationSample($identity, time(), 2000, 3000));
        $this->assertSame('unknown', $result['status']); $this->assertSame($reads, $this->reads);
        $this->assertNull($this->rawObservationRecord($this->observationCheckpointKey($identity)));
        $this->assertSame($enrollmentBefore, $this->rawObservationRecord(ArvanObservationRegistry::ENROLLMENT.$identity['service_id']));
        $this->assertFalse($this->observationRecord(ArvanObservationRegistry::STATUS.$identity['service_id'])['result']['two_fresh_samples_verified']);
    }

    public function test_source_failure_clears_old_green_status_and_preserves_history(): void
    {
        [, , , $identity] = $this->observationFixture(); $this->assertGreenObservation($identity); $before = $this->rawObservationRecord($this->observationCheckpointKey($identity));
        $result = $this->registry->poll($identity['service_id'], $this->observationSource(static function (): array { throw new \RuntimeException('SYNTHETIC_PRIVATE_ERROR'); }));
        $this->assertSame('unknown', $result['status']); $this->assertStringNotContainsString('SYNTHETIC_PRIVATE_ERROR', json_encode($result));
        $this->assertSame($before, $this->rawObservationRecord($this->observationCheckpointKey($identity)));
        $this->assertSame('unknown', $this->observationRecord(ArvanObservationRegistry::STATUS.$identity['service_id'])['result']['status']);
    }

    public function test_owner_change_and_duplicate_provider_ref_are_rejected_before_any_source_read(): void
    {
        [$customer, $service, , $identity] = $this->observationFixture(); $this->assertGreenObservation($identity); $reads = $this->reads;
        $other = Customer::create(['email' => 'new-owner@example.test', 'status' => 'active']);
        DB::table('services')->where('id', $service->id)->update(['customer_id' => $other->id]);
        $this->assertSame('unknown', $this->pollObservation($identity, $this->observationSample($identity, time()))['status']);
        $this->assertSame($reads, $this->reads);
        DB::table('services')->where('id', $service->id)->update(['customer_id' => $customer->id]);
        $duplicate = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic duplicate', 'currency_code' => 'IRT', 'status' => 'active']);
        CloudInstance::create(['service_id' => $duplicate->id, 'provider' => 'arvan', 'provider_ref' => $identity['provider_ref'], 'status' => 'running']);
        $this->assertSame('unknown', $this->pollObservation($identity, $this->observationSample($identity, time()))['status']);
        $this->assertSame($reads, $this->reads); Http::assertNothingSent();
    }

    public function test_inflight_checkpoint_update_is_never_overwritten_by_an_older_poll(): void
    {
        [, , , $identity] = $this->observationFixture(); $this->pollObservation($identity, $this->observationSample($identity, time() - 60));
        $newer = null;
        $result = $this->registry->poll($identity['service_id'], $this->observationSource(function () use ($identity, &$newer): array {
            $this->assertSame(0, DB::transactionLevel());
            $this->pollObservation($identity, $this->observationSample($identity, time(), 1500, 2600));
            $newer = $this->rawObservationRecord($this->observationCheckpointKey($identity));
            return ['status' => 'observed', 'sample' => $this->observationSample($identity, time() - 1, 1400, 2400)];
        }));
        $this->assertSame('unknown', $result['status']); $this->assertSame($newer, $this->rawObservationRecord($this->observationCheckpointKey($identity)));
        $this->assertTrue($this->observationRecord(ArvanObservationRegistry::STATUS.$identity['service_id'])['result']['two_fresh_samples_verified']);
    }

    public function test_late_failed_poll_cannot_replace_a_newer_status_or_enrollment(): void
    {
        [, , , $identity] = $this->observationFixture(); $this->assertGreenObservation($identity); $next = array_replace($identity, ['enrollment_id' => str_repeat('b', 32)]);
        $newerStatus = null;
        $this->registry->poll($identity['service_id'], $this->observationSource(function () use ($identity, $next, &$newerStatus): array {
            $this->approveObservationIdentity($next, $identity['enrollment_id']);
            $this->pollObservation($next, $this->observationSample($next, time() - 60));
            $newerStatus = $this->rawObservationRecord(ArvanObservationRegistry::STATUS.$identity['service_id']);
            throw new \RuntimeException('SYNTHETIC_LATE_FAILURE');
        }));
        $this->assertSame($next, $this->observationRecord(ArvanObservationRegistry::ENROLLMENT.$identity['service_id'])['identity']);
        $this->assertSame($newerStatus, $this->rawObservationRecord(ArvanObservationRegistry::STATUS.$identity['service_id']));
        $this->assertNotNull($this->rawObservationRecord($this->observationCheckpointKey($identity)));
    }

    public function test_new_generation_requires_two_new_samples_and_preserves_old_history(): void
    {
        [, , , $identity] = $this->observationFixture(); $this->assertGreenObservation($identity); $old = $this->rawObservationRecord($this->observationCheckpointKey($identity));
        $next = array_replace($identity, ['enrollment_id' => str_repeat('b', 32)]);
        $this->approveObservationIdentity($next, $identity['enrollment_id']);
        $this->assertFalse($this->observationRecord(ArvanObservationRegistry::STATUS.$identity['service_id'])['result']['two_fresh_samples_verified']);
        $this->assertSame($old, $this->rawObservationRecord($this->observationCheckpointKey($identity)));
        $this->assertNull($this->rawObservationRecord($this->observationCheckpointKey($next)));
        $this->assertSame('baseline', $this->pollObservation($next, $this->observationSample($next, time() - 60))['status']);
        $this->assertTrue($this->pollObservation($next, $this->observationSample($next, time(), 2000, 3000))['two_fresh_samples_verified']);
    }

    public function test_wrong_previous_generation_and_reusing_retired_generation_refuse_without_erasing_records(): void
    {
        [, , , $identity] = $this->observationFixture(); $this->assertGreenObservation($identity); $before = $this->rawObservationRecord(ArvanObservationRegistry::ENROLLMENT.$identity['service_id']);
        $next = array_replace($identity, ['enrollment_id' => str_repeat('b', 32)]);
        try { $this->approveObservationIdentity($next, str_repeat('f', 32)); $this->fail('Wrong generation must refuse'); }
        catch (\RuntimeException $error) { $this->assertSame('previous_enrollment_changed', $error->getMessage()); }
        $this->assertSame($before, $this->rawObservationRecord(ArvanObservationRegistry::ENROLLMENT.$identity['service_id']));
        $this->approveObservationIdentity($next, $identity['enrollment_id']);
        try { $this->approveObservationIdentity($identity, $next['enrollment_id']); $this->fail('Retired generation must refuse'); }
        catch (\RuntimeException $error) { $this->assertSame('generation_reuse_refused', $error->getMessage()); }
        $this->assertSame($next, $this->observationRecord(ArvanObservationRegistry::ENROLLMENT.$identity['service_id'])['identity']);
    }

    public function test_disabled_enrollment_and_lost_socket_never_advance_or_keep_old_green(): void
    {
        [, , , $identity] = $this->observationFixture(); $this->assertGreenObservation($identity); $before = $this->rawObservationRecord($this->observationCheckpointKey($identity));
        $this->approveObservationIdentity($identity, $identity['enrollment_id'], false);
        $this->assertFalse($this->observationRecord(ArvanObservationRegistry::STATUS.$identity['service_id'])['result']['two_fresh_samples_verified']);
        $reads = $this->reads;
        $this->assertSame('unknown', $this->pollObservation($identity, $this->observationSample($identity, time()))['status']);
        $this->assertSame($reads, $this->reads);
        $this->approveObservationIdentity($identity, $identity['enrollment_id']);
        fclose($this->socket); $this->socket = null; unlink($this->fixtureRoot.UnixPrometheusTrafficTransport::RELATIVE);
        $this->assertSame('unknown', $this->pollObservation($identity, $this->observationSample($identity, time()))['status']);
        $this->assertSame($before, $this->rawObservationRecord($this->observationCheckpointKey($identity)));
        $this->assertFalse($this->observationRecord(ArvanObservationRegistry::STATUS.$identity['service_id'])['result']['two_fresh_samples_verified']);
    }

    public function test_bounded_cursor_passes_invalid_orphan_keys_and_wraps_without_stalling(): void
    {
        foreach (['0', '01', '10', 'bad'] as $id) { $this->putObservationRecord(ArvanObservationRegistry::ENROLLMENT.$id, ['invalid' => true]); }
        $cursors = [];
        for ($i = 0; $i < 5; $i++) {
            $result = $this->registry->run(1);
            $this->assertSame(1, $result['checked']); $this->assertSame(1, $result['unknown']);
            $cursors[] = $this->observationRecord(ArvanObservationRegistry::PROGRESS)['cursor'];
        }
        $this->assertCount(4, array_unique($cursors)); $this->assertSame($cursors[0], $cursors[4]);
        $this->assertSame(0, CreditEntry::count()); Http::assertNothingSent();
    }

    public function test_actual_command_is_disabled_without_source_io_and_invalid_limit_performs_no_queries(): void
    {
        $transport = $this->observationRecord(ArvanObservationRegistry::TRANSPORT);
        $this->registry->approve(['version' => 1, 'kind' => 'transport', 'enabled' => false,
            'operator_acceptance_sha256' => str_repeat('e', 64), 'pin' => $transport['pin']]);
        $this->assertSame(0, Artisan::call('cloud:observe-arvan', ['--limit' => '20']));
        $result = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('disabled', $result['status']); $this->assertSame(0, $result['checked']);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->assertSame(2, Artisan::call('cloud:observe-arvan', ['--limit' => '21']));
            $this->assertStringContainsString('observation_limit_invalid', Artisan::output());
            $this->assertSame([], DB::getQueryLog(), 'Invalid input must stop before registry/SQL/transport');
        } finally { DB::disableQueryLog(); }
        Http::assertNothingSent();
    }

    public function test_observation_scheduler_registers_one_natural_minute_pass_with_overlap_guard(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'cloud:observe-arvan --limit=20'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(2, $event->expiresAt);
        Http::assertNothingSent();
    }

    public function test_approval_descriptor_requires_exact_hash_mode_private_parent_and_no_symlink(): void
    {
        $path = $this->fixtureRoot.'/storage/app/private/arvan-observation/approved.json';
        $descriptor = ['version' => 1, 'kind' => 'transport', 'enabled' => false,
            'operator_acceptance_sha256' => str_repeat('e', 64), 'pin' => $this->observationRecord(ArvanObservationRegistry::TRANSPORT)['pin']];
        $raw = ArvanObservationState::encode($descriptor); file_put_contents($path, $raw); chmod($path, 0600);
        $sha = hash('sha256', $raw);
        $this->assertSame($descriptor, ArvanObservationApprovalFile::read($this->fixtureRoot, $path, $sha));
        foreach (['sha', 'mode', 'symlink', 'outside'] as $case) {
            $candidate = $path; $digest = $sha;
            if ($case === 'sha') { $digest = str_repeat('0', 64); }
            if ($case === 'mode') { chmod($path, 0644); }
            if ($case === 'symlink') { $candidate = dirname($path).'/alias.json'; symlink($path, $candidate); }
            if ($case === 'outside') { $candidate = $this->fixtureRoot.'/outside.json'; file_put_contents($candidate, $raw); chmod($candidate, 0600); }
            try { ArvanObservationApprovalFile::read($this->fixtureRoot, $candidate, $digest); $this->fail('Unsafe descriptor must refuse: '.$case); }
            catch (\RuntimeException $error) { $this->assertStringStartsWith('approval_file_', $error->getMessage()); }
            finally { chmod($path, 0600); }
        }
        $this->assertSame($raw, file_get_contents($path));
    }

    public function test_existing_approved_transport_can_be_disabled_during_a_socket_outage(): void
    {
        $transport = $this->observationRecord(ArvanObservationRegistry::TRANSPORT);
        fclose($this->socket); $this->socket = null; unlink($this->fixtureRoot.UnixPrometheusTrafficTransport::RELATIVE);
        $descriptor = ['version' => 1, 'kind' => 'transport', 'enabled' => false,
            'operator_acceptance_sha256' => str_repeat('e', 64), 'pin' => $transport['pin']];
        $this->assertSame('transport_disabled', $this->registry->approve($descriptor)['status']);
        $this->assertNull($this->registry->transport(false));
        $this->assertSame('disabled', $this->observationRecord(ArvanObservationRegistry::RUN)['status']);
        try { $this->registry->approve(array_replace($descriptor, ['enabled' => true])); $this->fail('Unavailable socket must not be reenabled'); }
        catch (\RuntimeException $error) { $this->assertStringStartsWith('unix_transport_', $error->getMessage()); }
        $this->assertNull($this->registry->transport(false));
    }

    public function test_corrupt_central_configuration_or_cursor_records_a_reviewable_run_without_source_io(): void
    {
        $transport = $this->rawObservationRecord(ArvanObservationRegistry::TRANSPORT);
        DB::table('settings')->where('key', ArvanObservationRegistry::TRANSPORT)->update(['value' => '{malformed']);
        $this->assertSame('trusted_transport_configuration_unavailable', $this->registry->run()['status']);
        $this->assertSame('trusted_transport_configuration_unavailable', $this->observationRecord(ArvanObservationRegistry::RUN)['status']);
        DB::table('settings')->where('key', ArvanObservationRegistry::TRANSPORT)->update(['value' => $transport]);
        $this->putObservationRecord(ArvanObservationRegistry::PROGRESS, ['version' => 1, 'cursor' => 'not-an-enrollment-prefix']);
        $this->assertSame('observation_progress_requires_review', $this->registry->run()['status']);
        $this->assertSame('observation_progress_requires_review', $this->observationRecord(ArvanObservationRegistry::RUN)['status']);
        $this->assertSame(0, CreditEntry::count()); Http::assertNothingSent();
    }

    public function test_oversized_multibyte_enrollment_and_status_fail_before_source_and_cannot_leave_old_green(): void
    {
        [, , , $identity] = $this->observationFixture(); $this->assertGreenObservation($identity);
        $enrollmentKey = ArvanObservationRegistry::ENROLLMENT.$identity['service_id'];
        $statusKey = ArvanObservationRegistry::STATUS.$identity['service_id'];
        $enrollment = $this->rawObservationRecord($enrollmentKey); $greenStatus = $this->rawObservationRecord($statusKey);
        $checkpoint = $this->rawObservationRecord($this->observationCheckpointKey($identity)); $reads = $this->reads;
        // The multibyte case is below the character limit but above the byte limit.
        foreach ([str_repeat('x', ArvanObservationState::MAX_RECORD_BYTES + 1), str_repeat('ر', 9000)] as $oversized) {
            $this->assertGreaterThan(ArvanObservationState::MAX_RECORD_BYTES, strlen($oversized));
            foreach ([$greenStatus, str_repeat('ر', 9000)] as $status) {
                DB::table('settings')->where('key', $enrollmentKey)->update(['value' => $oversized]);
                DB::table('settings')->where('key', $statusKey)->update(['value' => $status]);
                $result = $this->pollObservation($identity, $this->observationSample($identity, time(), 2000, 3000));
                $this->assertSame('unknown', $result['status']); $this->assertSame($reads, $this->reads);
                $this->assertSame($checkpoint, $this->rawObservationRecord($this->observationCheckpointKey($identity)));
                $saved = $this->observationRecord($statusKey);
                $this->assertSame('oversized', $saved['enrollment_record_state']);
                $this->assertSame('unknown', $saved['result']['status']);
                $this->assertFalse($saved['result']['two_fresh_samples_verified']);
                $this->assertArrayNotHasKey('identity', $saved, 'Unverified identity must never be relabeled trusted');
                $this->assertSame($oversized, $this->rawObservationRecord($enrollmentKey), 'Do not erase or reset invalid enrollment history');
            }
        }
        DB::table('settings')->where('key', $enrollmentKey)->update(['value' => $enrollment]);
        $this->assertSame($checkpoint, $this->rawObservationRecord($this->observationCheckpointKey($identity)));
        Http::assertNothingSent();
    }
}
