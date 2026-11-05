<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\EngineAgentCredential;
use App\Models\EngineConsoleGrant;
use App\Models\EngineVm;
use App\Services\Cloud\Engine\EngineConsoleService;
use App\Services\Cloud\Engine\EngineTrafficService;
use App\Services\Cloud\Engine\EngineWorker;
use App\Services\Cloud\Engine\Placement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EngineTelemetryTest extends TestCase
{
    use EngineInventory, RefreshDatabase;

    private function vm(): EngineVm
    {
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $service = $this->engineService($plan);
        $service->update(['status' => 'active']);
        $vm = app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04');
        $vm->update(['status' => 'running']);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'cloud-engine', 'provider_ref' => $vm->uuid, 'status' => 'running']);
        Http::fake(['pve.example.test:8006/*' => Http::response(['data' => ['name' => $vm->name,
            'description' => EngineWorker::marker($vm)]])]);
        config(['cloud-engine.console.gateway_url' => 'https://console-gateway.example.test', 'cloud-engine.console.gateway_secret' => str_repeat('s', 64)]);

        return $vm->fresh();
    }

    private function sample(EngineVm $vm, int $seq, int $in, int $out, array $changes = []): array
    {
        return array_replace(['vm_uuid' => $vm->uuid, 'interface' => 'tap'.$vm->vmid.'i0', 'epoch' => str_repeat('a', 32),
            'sequence' => $seq, 'in_bytes' => $in, 'out_bytes' => $out, 'sampled_at' => now('UTC')->format('Y-m-d\TH:i:s\Z')], $changes);
    }

    public function test_separate_exact_counters_duplicates_and_no_billing(): void
    {
        $vm = $this->vm();
        $meter = app(EngineTrafficService::class);
        $this->assertSame('baseline', $meter->ingest($vm->node, $this->sample($vm, 1, 1000, 2000))['quality']);
        $this->travel(10)->seconds();
        $sample = $this->sample($vm, 2, 1000001000, 2000002000);
        $this->assertSame('observed', $meter->ingest($vm->node, $sample)['quality']);
        $this->assertSame('duplicate', $meter->ingest($vm->node, $sample)['status']);
        $report = $meter->report($vm);
        $this->assertSame(1000000000, $report['in_bytes']);
        $this->assertSame(2000000000, $report['out_bytes']);
        $this->assertFalse($report['billable']);
        $this->assertSame(1073741824, $report['unit_bytes']['GiB']);
        $this->assertSame(2, $report['samples']);
        $this->assertSame('incomplete', $report['coverage']);
        $this->assertSame(0, DB::table('credit_ledger')->count());
    }

    public function test_conflicting_replays_bad_interfaces_floats_stale_samples_and_wrong_node_fail(): void
    {
        $vm = $this->vm();
        $meter = app(EngineTrafficService::class);
        $sample = $this->sample($vm, 1, 20, 30);
        $meter->ingest($vm->node, $sample);
        foreach ([['in_bytes' => 21], ['sequence' => 0], ['interface' => 'vmbr0'], ['in_bytes' => 2.2],
            ['sampled_at' => now()->subHour()->format('Y-m-d\TH:i:s\Z')]] as $change) {
            try {
                $meter->ingest($vm->node, array_replace($sample, $change));
                $this->fail('Invalid sample accepted');
            } catch (\InvalidArgumentException) {
                $this->assertSame(1, DB::table('cloud_engine_traffic_samples')->count());
            }
        }
        $other = $this->engineNode([], '8.8.8.8/29');
        $this->expectException(\InvalidArgumentException::class);
        $meter->ingest($other, $sample);
    }

    public function test_epoch_resets_sequence_gaps_and_month_boundary_do_not_invent_usage(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 30)->setTime(23, 59, 50));
        $vm = $this->vm();
        $meter = app(EngineTrafficService::class);
        $meter->ingest($vm->node, $this->sample($vm, 1, 100, 200));
        $this->travel(20)->seconds();
        $this->assertSame('month_boundary_unattributed', $meter->ingest($vm->node, $this->sample($vm, 2, 1000, 2000))['reason']);
        $this->travel(10)->seconds();
        $this->assertSame('counter_decreased', $meter->ingest($vm->node, $this->sample($vm, 3, 1, 2))['reason']);
        $this->travel(10)->seconds();
        $this->assertSame('sequence_gap', $meter->ingest($vm->node, $this->sample($vm, 5, 500, 600))['reason']);
        $this->travel(10)->seconds();
        $this->assertSame('counter_epoch_changed', $meter->ingest($vm->node, $this->sample($vm, 1, 5, 6, ['epoch' => str_repeat('b', 32)]))['reason']);
        $this->assertSame(0, $meter->report($vm, '2026-12')['in_bytes']);
        $this->assertSame(4, $meter->report($vm, '2026-12')['gaps']);
        $this->travel(10)->seconds();
        $this->expectException(\InvalidArgumentException::class);
        $meter->ingest($vm->node, $this->sample($vm, 9, 600, 700));
    }

    public function test_sampling_gaps_and_persisted_deduplicated_thresholds(): void
    {
        $vm = $this->vm();
        $vm->update(['metadata' => ['traffic_quota_bytes' => 1000]]);
        $meter = app(EngineTrafficService::class);
        $meter->ingest($vm->node, $this->sample($vm, 1, 100, 100));
        $this->travel(130)->seconds();
        $this->assertSame('sampling_gap', $meter->ingest($vm->node, $this->sample($vm, 2, 200, 200))['reason']);
        $this->travel(10)->seconds();
        $meter->ingest($vm->node, $this->sample($vm, 3, 1200, 200));
        $this->travel(10)->seconds();
        $meter->ingest($vm->node, $this->sample($vm, 4, 1201, 200));
        $this->assertSame([80, 90, 100], $meter->report($vm)['alerts']);
        $this->assertSame(3, DB::table('cloud_engine_traffic_alerts')->count());
        $this->assertSame(1001, $meter->report($vm)['in_bytes']);
    }

    public function test_console_opaque_grants_and_atomic_one_use_claim(): void
    {
        $vm = $this->vm();
        $console = app(EngineConsoleService::class);
        $grant = $console->issue($vm, (int) $vm->service->customer_id);
        $this->assertStringNotContainsString('pve.example', json_encode($grant));
        $this->assertStringNotContainsString('fixture-only-secret', json_encode($grant));
        $this->assertStringNotContainsString($grant['token'], json_encode(EngineConsoleGrant::first()->getAttributes()));
        $session = $console->exchange($grant['token'], 'redeem')['session'];
        $target = $console->exchange($session, 'connect');
        $this->assertSame((int) $vm->vmid, $target['target']['vmid']);
        $this->assertSame('fixture-only-secret', $target['target']['token_secret']);
        $this->assertTrue($console->exchange($session, 'validate')['valid']);
        $this->expectException(\InvalidArgumentException::class);
        $console->exchange($session, 'connect');
    }

    public function test_ownership_revalidated_at_issue_and_connected_session_validation(): void
    {
        $vm = $this->vm();
        $console = app(EngineConsoleService::class);
        try {
            $console->issue($vm, (int) $vm->service->customer_id + 100);
            $this->fail('Cross-customer console');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, EngineConsoleGrant::count());
        }
        $grant = $console->issue($vm, (int) $vm->service->customer_id);
        $session = $console->exchange($grant['token'], 'redeem')['session'];
        $console->exchange($session, 'connect');
        $vm->service->update(['status' => 'suspended']);
        $this->expectException(\InvalidArgumentException::class);
        $console->exchange($session, 'validate');
    }

    public function test_redeem_replay_revocation_expiry_and_deletion_wait_for_live_socket_lease(): void
    {
        $vm = $this->vm();
        $console = app(EngineConsoleService::class);
        $grant = $console->issue($vm, (int) $vm->service->customer_id);
        $session = $console->exchange($grant['token'], 'redeem')['session'];
        try {
            $console->exchange($grant['token'], 'redeem');
            $this->fail('Replay');
        } catch (\InvalidArgumentException) {
        }
        $console->exchange($session, 'connect');
        $this->assertFalse($console->clearForDeletion($vm));
        try {
            $console->exchange($session, 'validate');
            $this->fail('Revoked grant');
        } catch (\InvalidArgumentException) {
        }
        $this->travel(91)->seconds();
        $this->assertTrue($console->clearForDeletion($vm));
    }

    public function test_vm_snapshot_change_invalidates_grant(): void
    {
        $vm = $this->vm();
        $console = app(EngineConsoleService::class);
        $grant = $console->issue($vm, (int) $vm->service->customer_id);
        $vm->update(['fingerprint' => str_repeat('f', 64)]);
        $this->expectException(\InvalidArgumentException::class);
        $console->exchange($grant['token'], 'redeem');
    }

    public function test_remote_vmid_reuse_inactive_customer_and_cancelled_service_fail_closed(): void
    {
        $vm = $this->vm();
        $console = app(EngineConsoleService::class);
        $this->assertTrue($console->available($vm, (int) $vm->service->customer_id));
        $this->assertSame(0, EngineConsoleGrant::count());
        Http::swap(new Factory);
        Http::fake(['pve.example.test:8006/*' => Http::response(['data' => ['name' => $vm->name, 'description' => 'foreign-vm']])]);
        try {
            $console->issue($vm, (int) $vm->service->customer_id);
            $this->fail('Foreign VM console');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, EngineConsoleGrant::count());
        }
        $vm->service->update(['cancelled_at' => now()]);
        $this->assertFalse($console->available($vm, (int) $vm->service->customer_id));
        $vm->service->update(['cancelled_at' => null]);
        Customer::whereKey($vm->service->customer_id)->update(['status' => 'suspended']);
        $this->assertFalse($console->available($vm, (int) $vm->service->customer_id));
    }

    public function test_internal_hmac_ingestion_and_missing_gateway_auth(): void
    {
        if (! Route::has('engine.internal.traffic')) {
            Route::middleware('api')->group(base_path('routes/cloud-engine-internal.php'));
        }
        $vm = $this->vm();
        $uri = '/api/v1/cloud-engine/internal/nodes/'.$vm->node_id.'/traffic';
        $sample = $this->sample($vm, 1, 0, 0);
        $this->postJson($uri, $sample)->assertForbidden();
        EngineAgentCredential::create(['node_id' => $vm->node_id, 'secret' => str_repeat('t', 64), 'enabled' => true]);
        $body = json_encode($sample);
        $time = (string) now()->timestamp;
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ENGINE_TIME' => $time,
            'HTTP_X_ENGINE_SIGNATURE' => hash_hmac('sha256', $time."\n".$body, str_repeat('t', 64))];
        $this->call('POST', $uri, [], [], [], $headers, $body)->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson('/api/v1/cloud-engine/internal/console', ['token' => str_repeat('a', 64), 'phase' => 'connect'])->assertForbidden();
    }

    public function test_console_validation_budget_supports_multiple_active_guests(): void
    {
        config(['cloud-engine.console.gateway_secret' => str_repeat('s', 64)]);
        $body = json_encode(['token' => str_repeat('a', 64), 'phase' => 'validate']);
        $time = (string) now()->timestamp;
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ENGINE_TIME' => $time,
            'HTTP_X_ENGINE_SIGNATURE' => hash_hmac('sha256', $time."\n".$body, str_repeat('s', 64))];
        // These authenticated but unknown leases must reach the grant check,
        // rather than exhaust a 120/minute bucket shared by all console users.
        for ($i = 0; $i < 150; $i++) {
            $this->call('POST', '/api/v1/cloud-engine/internal/console', [], [], [], $headers, $body)->assertStatus(410);
        }
    }
}
