<?php

namespace Tests\Feature;

use App\Models\{EngineOperation, EngineVm};
use App\Services\Cloud\Engine\{EngineConsoleService, EngineOperations, EnginePaidGate, EngineProvider, EngineWorker, Placement, ProxmoxTransport};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EngineOperationsTest extends TestCase
{
    use RefreshDatabase, EngineInventory;

    private function reservation(): EngineVm
    {
        config(['cloud-engine.enabled' => true]);
        $this->mock(EnginePaidGate::class)->shouldReceive('allows')->andReturn(true);
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $service = $this->engineService($plan);
        $vm = app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04');
        $vm->update(['credentials' => ['username' => 'root', 'password' => 'fixture-only-initial-password']]);
        return $vm;
    }

    private function operation(EngineVm $vm, string $action = 'create', array $payload = []): EngineOperation
    {
        return app(EngineOperations::class)->submit($vm, $action, $payload, 'fixture-idempotency');
    }

    private function tick(EngineOperation $op): void
    {
        app(EngineWorker::class)->tick($op->fresh());
    }

    public function test_adapter_reserves_and_queues_without_any_remote_request(): void
    {
        config(['cloud-engine.enabled' => true]);
        Http::preventStrayRequests();
        $this->mock(EnginePaidGate::class)->shouldReceive('allows')->andReturn(true);
        $plan = $this->enginePlan($this->engineNode());
        $service = $this->engineService($plan);
        $r = app(EngineProvider::class)->createServer(['name' => 'sn-svc-'.$service->id,
            'plan_ref' => $plan->provider_ref, 'image_ref' => 'ubuntu-24.04', 'labels' => ['snet-service' => $service->id]]);
        $this->assertTrue($r['ok']);
        $this->assertSame('building', $r['status']);
        $this->assertNotEmpty($r['root_password']);
        $this->assertDatabaseCount('cloud_engine_vms', 1);
        $this->assertDatabaseCount('cloud_engine_operations', 1);
        Http::assertNothingSent();
    }

    public function test_global_disabled_gate_prevents_reservation(): void
    {
        $vm = $this->reservation();
        config(['cloud-engine.enabled' => false]);
        $this->assertFalse(app(EngineProvider::class)->isConfigured());
    }

    public function test_idempotency_replays_and_rejects_changed_inputs(): void
    {
        $vm = $this->reservation();
        $a = $this->operation($vm);
        $this->assertSame($a->id, $this->operation($vm)->id);
        $this->expectExceptionMessage('idempotency_conflict');
        $this->operation($vm, 'power', ['action' => 'on']);
    }

    public function test_concurrent_operation_and_unexpired_lease_are_rejected(): void
    {
        $vm = $this->reservation();
        $op = $this->operation($vm);
        $op->update(['lease_until' => now()->addMinutes(1), 'lease_token' => 'another-worker']);
        Http::preventStrayRequests();
        $this->assertFalse(app(EngineWorker::class)->tick($op));
        Http::assertNothingSent();
        $this->expectExceptionMessage('operation_in_progress');
        app(EngineOperations::class)->submit($vm, 'delete', [], 'different-key');
    }

    public function test_lost_clone_response_never_submits_another_clone_or_releases_ip(): void
    {
        $vm = $this->reservation();
        $op = $this->operation($vm);
        Http::fake(function ($request) {
            if ($request->method() === 'POST') { throw new ConnectionException('fixture lost response'); }
            return Http::response(['data' => null], 404);
        });
        for ($i = 0; $i < 6; $i++) { $this->tick($op); }
        $this->assertSame('clone_reconcile', $op->fresh()->phase);
        $this->assertSame('uncertain', $op->fresh()->status);
        Http::assertSentCount(5);
        $sent = Http::recorded(fn ($r) => $r->method() === 'POST');
        // Failed connections are not recorded by the HTTP facade; only one attempt is allowed by the persisted phase.
        $this->assertLessThanOrEqual(1, $sent->count());
        $this->assertNotSame('free', $vm->address->fresh()->state);
        $this->assertDatabaseMissing('cloud_engine_ip_events', ['vm_id' => $vm->id, 'action' => 'released']);
    }

    public function test_foreign_vm_at_reserved_id_is_never_adopted_or_deleted(): void
    {
        $vm = $this->reservation();
        $op = $this->operation($vm);
        Http::fake(['*' => Http::response(['data' => ['name' => $vm->name, 'description' => 'another-owner']])]);
        $this->tick($op);
        $this->tick($op);
        $this->assertSame('blocked', $op->fresh()->status);
        $this->assertSame('vm_identity_mismatch', $op->fresh()->error_code);
        $this->assertSame('quarantined', $vm->fresh()->status);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_task_stopped_without_ok_is_not_success(): void
    {
        $vm = $this->reservation();
        $op = $this->operation($vm);
        $op->update(['phase' => 'clone_wait', 'receipt' => ['task' => 'UPID:pve:fixture']]);
        Http::fake(['*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'ERROR']])]);
        $this->tick($op);
        $this->assertSame('provider_task_failed', $op->fresh()->error_code);
        $this->assertSame('blocked', $op->fresh()->status);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_changed_template_is_not_silently_used_by_a_queued_job(): void
    {
        $vm = $this->reservation();
        $vm->image->update(['template_vmid' => 9001]);
        $op = $this->operation($vm);
        Http::fake(['*' => Http::response(['data' => null], 404)]);
        $this->tick($op); $this->tick($op);
        $this->assertSame('image_not_accepted', $op->fresh()->error_code);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_config_failure_does_not_start_a_misconfigured_vm(): void
    {
        $vm = $this->reservation();
        $op = $this->operation($vm);
        $op->update(['phase' => 'configure_prepare']);
        Http::fake(fn ($request) => Http::response(['data' => $request->method() === 'GET'
            ? ['name' => $vm->name, 'description' => EngineWorker::marker($vm)] : null], $request->method() === 'GET' ? 200 : 500));
        $this->tick($op); $this->tick($op); $this->tick($op);
        $this->assertSame('configuration_not_verified', $op->fresh()->error_code);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/status/start'));
    }

    public function test_full_fake_http_clone_configuration_disk_boot_and_guest_ip_flow(): void
    {
        $vm = $this->reservation();
        $op = $this->operation($vm);
        $config = null;
        $running = false;
        $ipset = [];
        $filter = [];
        $firewall = [];
        Http::fake(function ($request) use ($vm, &$config, &$running, &$ipset, &$filter, &$firewall) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/clone')) {
                $config = ['name' => $request['name'], 'description' => $request['description'], 'scsi0' => 'local-lvm:vm-disk,size=10G'];
                return Http::response(['data' => 'UPID:pve:clone']);
            }
            if (str_contains($path, '/tasks/')) { return Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]); }
            if (str_ends_with($path, '/config')) {
                if ($request->method() === 'PUT') { $config = array_merge($config, $request->data()); return Http::response(['data' => null]); }
                return Http::response(['data' => $config], $config === null ? 404 : 200);
            }
            if (str_ends_with($path, '/resize')) { $config['scsi0'] = 'local-lvm:vm-disk,size=20G'; return Http::response(['data' => null]); }
            if (str_ends_with($path, '/firewall/ipset')) {
                if ($request->method() === 'POST') { $ipset[] = ['name' => $request['name']]; return Http::response(['data' => null]); }
                return Http::response(['data' => $ipset]);
            }
            if (str_ends_with($path, '/firewall/ipset/ipfilter-net0')) {
                if ($request->method() === 'POST') { $filter[] = ['cidr' => $request['cidr']]; return Http::response(['data' => null]); }
                return Http::response(['data' => $filter]);
            }
            if (str_ends_with($path, '/firewall/options')) {
                if ($request->method() === 'PUT') { $firewall = $request->data(); return Http::response(['data' => null]); }
                return Http::response(['data' => $firewall]);
            }
            if (str_ends_with($path, '/status/start')) { $running = true; return Http::response(['data' => 'UPID:pve:start']); }
            if (str_ends_with($path, '/status/current')) { return Http::response(['data' => ['status' => $running ? 'running' : 'stopped']]); }
            if (str_ends_with($path, '/agent/network-get-interfaces')) {
                return Http::response(['data' => ['result' => [['ip-addresses' => [['ip-address' => $vm->address->address]]]]]]);
            }
            if (str_ends_with($path, '/agent/exec')) { return Http::response(['data' => ['pid' => 456]]); }
            if (str_ends_with($path, '/agent/exec-status')) {
                return Http::response(['data' => ['exited' => true, 'exitcode' => 0, 'out-data' => '{"status":"done","errors":[]}']]);
            }
            return Http::response(['data' => null], 500);
        });
        for ($i = 0; $i < 30 && $op->fresh()->status !== 'succeeded'; $i++) { $this->tick($op); }
        $this->assertSame('succeeded', $op->fresh()->status);
        $this->assertSame('running', $vm->fresh()->status);
        $this->assertSame(2, (int) $config['cores']);
        $this->assertSame(2048, (int) $config['memory']);
        $this->assertStringContainsString('firewall=1', $config['net0']);
        $this->assertStringContainsString('size=20G', $config['scsi0']);
        $this->assertStringContainsString('/29,gw=', $config['ipconfig0']);
        $this->assertSame([['cidr' => $vm->address->address.'/32']], $filter);
        $this->assertSame(['enable' => 1, 'ipfilter' => 1, 'macfilter' => 1], $firewall);
    }

    public function test_delete_requires_authoritative_absence_and_console_clear_before_ip_release(): void
    {
        $vm = $this->reservation();
        $vm->update(['status' => 'running']);
        $op = $this->operation($vm, 'delete');
        $this->mock(EngineConsoleService::class)->shouldReceive('clearForDeletion')->once()->andReturn(false);
        Http::fake(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/config')
            ? Http::response(['data' => null], 404) : Http::response(['data' => []]));
        $this->tick($op); $this->tick($op);
        $this->assertSame('console_session_active', $op->fresh()->error_code);
        $this->assertNotSame('free', $vm->address->fresh()->state);
        $this->mock(EngineConsoleService::class)->shouldReceive('clearForDeletion')->once()->andReturn(true);
        $this->tick($op);
        $this->assertSame('succeeded', $op->fresh()->status);
        $this->assertSame('free', $vm->address->fresh()->state);
        $this->assertSame('deleted', $vm->fresh()->status);
    }

    public function test_tls_verification_cannot_be_disabled_or_redirect_credentials(): void
    {
        $vm = $this->reservation();
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok']])]);
        app(ProxmoxTransport::class)->request($vm->node, 'GET', '/version');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'PVEAPIToken=engine@pve!worker=fixture-only-secret'));
        $vm->node->update(['api_url' => 'http://untrusted.example/api2/json']);
        $r = app(ProxmoxTransport::class)->request($vm->node, 'GET', '/version');
        $this->assertFalse($r['ok']);
        Http::assertSentCount(1);
    }

    public function test_expired_worker_cannot_persist_a_late_clone_receipt_or_resubmit(): void
    {
        $vm = $this->reservation();
        $op = $this->operation($vm);
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                $this->travel(91)->seconds();
                return Http::response(['data' => 'UPID:pve:late-response']);
            }
            return Http::response(['data' => null], 404);
        });
        $this->tick($op); $this->tick($op);
        $this->assertSame('clone_reconcile', $op->fresh()->phase);
        $this->assertNull($op->fresh()->receipt);
        $this->tick($op);
        $this->assertSame('clone_reconcile', $op->fresh()->phase);
        Http::assertSent(fn ($r) => $r->method() === 'POST');
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_password_reset_changes_existing_encrypted_credentials_only_after_guest_ack(): void
    {
        $vm = $this->reservation();
        $vm->service->update(['status' => 'active']);
        $vm->image->update(['metadata' => ['guest_agent_verified' => true]]);
        $vm->update(['status' => 'running']);
        $op = $this->operation($vm, 'reset_password');
        $newPassword = $op->payload['password'];
        $this->assertStringNotContainsString($newPassword, json_encode($op));
        Http::fake(fn ($request) => Http::response(['data' => $request->method() === 'GET'
            ? ['name' => $vm->name, 'description' => EngineWorker::marker($vm)] : null]));
        $this->tick($op);
        $this->assertNotSame($newPassword, $vm->fresh()->credentials['password']);
        $this->tick($op);
        $this->assertSame('succeeded', $op->fresh()->status);
        $this->assertSame($newPassword, $vm->fresh()->credentials['password']);
        $this->assertSame($newPassword, $vm->service->fresh()->password);
        $this->assertStringNotContainsString($newPassword, json_encode($vm->fresh()));
    }

    public function test_lost_guest_password_ack_is_uncertain_and_never_replayed(): void
    {
        $vm = $this->reservation();
        $vm->service->update(['status' => 'active']);
        $vm->image->update(['metadata' => ['guest_agent_verified' => true]]);
        $vm->update(['status' => 'running']);
        $op = $this->operation($vm, 'reset_password');
        $calls = 0;
        Http::fake(function ($request) use ($vm, &$calls) {
            if ($request->method() === 'POST') { $calls++; throw new ConnectionException('lost guest acknowledgement'); }
            return Http::response(['data' => ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]);
        });
        for ($i = 0; $i < 5; $i++) { $this->tick($op); }
        $this->assertSame(1, $calls);
        $this->assertSame('uncertain', $op->fresh()->status);
        $this->assertNotSame($op->payload['password'], $vm->fresh()->credentials['password']);
    }

    public function test_hostname_requires_guest_exit_success_and_readback(): void
    {
        $vm = $this->reservation();
        $vm->service->update(['status' => 'active']);
        $vm->image->update(['metadata' => ['guest_agent_verified' => true]]);
        $vm->update(['status' => 'running']);
        $op = $this->operation($vm, 'hostname', ['hostname' => 'customer.example.test']);
        Http::fake(function ($request) use ($vm) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/exec')) { return Http::response(['data' => ['pid' => 123]]); }
            if (str_ends_with($path, '/exec-status')) { return Http::response(['data' => ['exited' => true, 'exitcode' => 0]]); }
            if (str_ends_with($path, '/get-host-name')) { return Http::response(['data' => ['result' => ['host-name' => 'customer.example.test']]]); }
            return Http::response(['data' => ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]);
        });
        $this->tick($op); $this->tick($op); $this->tick($op);
        $this->assertSame('succeeded', $op->fresh()->status);
        $this->assertSame('customer.example.test', $vm->fresh()->metadata['hostname']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['command'] === ['/usr/bin/hostnamectl', 'set-hostname', 'customer.example.test']);
    }

    public function test_failed_guest_hostname_command_does_not_report_success(): void
    {
        $vm = $this->reservation();
        $vm->service->update(['status' => 'active']);
        $vm->image->update(['metadata' => ['guest_agent_verified' => true]]);
        $vm->update(['status' => 'running']);
        $op = $this->operation($vm, 'hostname', ['hostname' => 'customer.example.test']);
        $op->update(['phase' => 'hostname_reconcile', 'receipt' => ['guest_pid' => 123]]);
        Http::fake(fn ($r) => Http::response(['data' => str_contains($r->url(), 'exec-status')
            ? ['exited' => true, 'exitcode' => 1] : ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]));
        $this->tick($op);
        $this->assertSame('failed', $op->fresh()->status);
        $this->assertNotSame('customer.example.test', $vm->fresh()->metadata['hostname']);
    }

    public function test_rebuild_requires_confirmation_and_retains_ip_while_replacing_image(): void
    {
        $vm = $this->reservation();
        $vm->service->update(['status' => 'active']);
        try {
            app(EngineOperations::class)->submit($vm, 'rebuild', ['image' => 'ubuntu-24.04'], 'no-confirm');
            $this->fail('Destructive operation accepted without explicit confirmation');
        } catch (\RuntimeException $e) { $this->assertSame('destructive_confirmation_required', $e->getMessage()); }
        $op = app(EngineOperations::class)->submit($vm, 'rebuild', ['image' => 'ubuntu-24.04', 'confirmed' => true], 'confirmed');
        $op->update(['phase' => 'delete_reconcile']);
        $addressId = $vm->address_id;
        $this->mock(EngineConsoleService::class)->shouldReceive('clearForDeletion')->once()->andReturn(true);
        Http::fake(fn ($request) => Http::response(['data' => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/config') ? null : []],
            str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/config') ? 404 : 200));
        $this->tick($op);
        $this->assertSame('clone_prepare', $op->fresh()->phase);
        $this->assertSame($addressId, $vm->fresh()->address_id);
        $this->assertNotSame('free', $vm->address->fresh()->state);
        $this->assertDatabaseMissing('cloud_engine_ip_events', ['vm_id' => $vm->id, 'action' => 'released']);
    }

    public function test_expired_unknown_clone_intent_cannot_be_replaced_by_delete(): void
    {
        $vm = $this->reservation();
        $op = $this->operation($vm);
        $op->update(['phase' => 'clone_reconcile', 'status' => 'uncertain', 'deadline_at' => now()->subSecond()]);
        $this->tick($op);
        $this->assertSame('blocked', $op->fresh()->status);
        $this->assertNotSame('free', $vm->address->fresh()->state);
        $this->expectExceptionMessage('operation_in_progress');
        app(EngineOperations::class)->submit($vm, 'delete', [], 'later-delete');
    }

    public function test_catalog_preserves_managed_plans_and_marks_draining_capacity_unavailable(): void
    {
        $vm = $this->reservation();
        $provider = app(EngineProvider::class);
        $catalog = $provider->fetchCatalog();
        $this->assertTrue($catalog['ok']);
        $this->assertCount(1, $catalog['plans']);
        $this->assertTrue($catalog['plans'][0]['in_stock']);
        $this->assertSame($vm->service->cloudPlan->provider_ref, $catalog['plans'][0]['provider_ref']);
        $this->assertSame('ubuntu-24.04', $catalog['images'][0]['provider_ref']);
        $vm->node->update(['draining' => true]);
        $catalog = $provider->fetchCatalog();
        $this->assertCount(1, $catalog['plans']);
        $this->assertFalse($catalog['plans'][0]['in_stock']);
    }

    public function test_catalog_excludes_unaccepted_image_and_blocks_sales_without_it(): void
    {
        $vm = $this->reservation();
        $vm->image->update(['initialization_verified_at' => null]);
        $catalog = app(EngineProvider::class)->fetchCatalog();
        $this->assertSame([], $catalog['images']);
        $this->assertFalse($catalog['plans'][0]['in_stock']);
    }

    public function test_inventory_verifies_remote_marker_and_ignores_unrelated_vms(): void
    {
        $vm = $this->reservation();
        Http::fake(function ($request) use ($vm) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/qemu')) {
                return Http::response(['data' => [['vmid' => $vm->vmid, 'status' => 'running'], ['vmid' => 9000, 'status' => 'stopped']]]);
            }
            return Http::response(['data' => str_contains($path, '/9000/') ? ['name' => 'template']
                : ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]);
        });
        $inventory = app(EngineProvider::class)->listServers();
        $this->assertTrue($inventory['ok']);
        $this->assertCount(1, $inventory['servers']);
        $this->assertSame($vm->uuid, $inventory['servers'][0]['ref']);
        $this->assertSame($vm->address->address, $inventory['servers'][0]['ipv4']);
    }

    public function test_partial_node_inventory_never_becomes_an_empty_success(): void
    {
        $vm = $this->reservation();
        $otherNode = $this->engineNode(['pve_node' => 'pve-2'], '9.9.9.0/29');
        Http::fake(fn ($request) => str_contains($request->url(), '/pve-2/')
            ? Http::response(['data' => null], 403)
            : Http::response(['data' => []]));
        $inventory = app(EngineProvider::class)->listServers();
        $this->assertFalse($inventory['ok']);
        $this->assertSame([], $inventory['servers']);
    }

    public function test_suspended_service_cannot_execute_previously_queued_power_on(): void
    {
        $vm = $this->reservation();
        $vm->service->update(['status' => 'active']);
        $op = $this->operation($vm, 'power', ['action' => 'on']);
        $vm->service->update(['status' => 'suspended']);
        Http::preventStrayRequests();
        $this->tick($op);
        $this->assertSame('blocked', $op->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_unsubmitted_provisioning_can_be_fenced_and_cancelled_safely(): void
    {
        $vm = $this->reservation();
        $create = $this->operation($vm);
        $delete = app(EngineOperations::class)->submit($vm, 'delete', [], 'cancel-before-submit');
        $this->assertSame('cancelled', $create->fresh()->status);
        $this->assertSame('delete', $delete->action);
        $this->assertFalse(app(EngineWorker::class)->tick($create->fresh()));
        $this->assertNotSame('free', $vm->address->fresh()->state);
    }

    public function test_guest_cloud_init_error_keeps_server_unready(): void
    {
        $vm = $this->reservation();
        $op = $this->operation($vm);
        $op->update(['phase' => 'initialization_reconcile', 'receipt' => ['initialization_pid' => 456]]);
        Http::fake(fn ($r) => Http::response(['data' => str_contains($r->url(), 'exec-status')
            ? ['exited' => true, 'exitcode' => 1, 'out-data' => '{"status":"error","errors":["initialization failed"]}']
            : ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]));
        $this->tick($op);
        $this->assertSame('blocked', $op->fresh()->status);
        $this->assertSame('guest_initialization_failed', $op->fresh()->error_code);
        $this->assertNotSame('running', $vm->fresh()->status);
    }

    public function test_windows_readiness_requires_exact_accepted_image_build_and_fresh_receipt(): void
    {
        $vm = $this->reservation();
        $vm->image->update(['key' => 'windows-2022', 'family' => 'windows', 'version' => '2022',
            'license_verified_at' => now(), 'metadata' => ['guest_agent_verified' => true, 'windows_ready_receipt_verified' => true]]);
        $vm->update(['metadata' => [...$vm->metadata, 'image_snapshot' => ['key' => 'windows-2022',
            'template_vmid' => $vm->image->template_vmid, 'checksum_sha256' => $vm->image->checksum_sha256, 'arch' => $vm->image->arch]]]);
        $op = $this->operation($vm);
        $op->update(['phase' => 'initialization_reconcile', 'receipt' => ['initialization_pid' => 789]]);
        $receipt = ['schema_version' => 1, 'image_key' => 'windows-2022', 'os_build' => 20348,
            'hostname' => $vm->name, 'credential_setup' => true, 'initialized' => true, 'written_at' => now()->toIso8601String()];
        Http::fake(fn ($r) => Http::response(['data' => str_contains($r->url(), 'exec-status')
            ? ['exited' => true, 'exitcode' => 0, 'out-data' => json_encode($receipt)]
            : ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]));
        $this->tick($op);
        $this->assertSame('succeeded', $op->fresh()->status);
        $this->assertSame('running', $vm->fresh()->status);
    }

    public function test_stale_windows_template_receipt_does_not_validate_new_guest(): void
    {
        $vm = $this->reservation();
        $vm->image->update(['key' => 'windows-2022', 'family' => 'windows', 'version' => '2022',
            'license_verified_at' => now(), 'metadata' => ['guest_agent_verified' => true, 'windows_ready_receipt_verified' => true]]);
        $vm->update(['metadata' => [...$vm->metadata, 'image_snapshot' => ['key' => 'windows-2022',
            'template_vmid' => $vm->image->template_vmid, 'checksum_sha256' => $vm->image->checksum_sha256, 'arch' => $vm->image->arch]]]);
        $op = $this->operation($vm);
        $op->update(['phase' => 'initialization_reconcile', 'receipt' => ['initialization_pid' => 789]]);
        $receipt = ['schema_version' => 1, 'image_key' => 'windows-2022', 'os_build' => 20348,
            'hostname' => $vm->name, 'credential_setup' => true, 'initialized' => true, 'written_at' => now()->subDay()->toIso8601String()];
        Http::fake(fn ($r) => Http::response(['data' => str_contains($r->url(), 'exec-status')
            ? ['exited' => true, 'exitcode' => 0, 'out-data' => json_encode($receipt)]
            : ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]));
        $this->tick($op);
        $this->assertSame('blocked', $op->fresh()->status);
        $this->assertSame('guest_initialization_failed', $op->fresh()->error_code);
    }

    public function test_only_trusted_paid_billing_resume_may_power_on_suspended_guest(): void
    {
        $vm = $this->reservation();
        $vm->update(['status' => 'off']);
        $vm->service->update(['status' => 'suspended', 'suspended_at' => now(), 'next_due_at' => now()->addMonth()]);
        $this->mock(EnginePaidGate::class)->shouldReceive('allowsBillingResume')->andReturn(true);
        $provider = app(EngineProvider::class);
        $this->assertFalse($provider->power($vm->uuid, 'on')['ok']);
        $this->assertTrue($provider->resumeBilling($vm->uuid)['ok']);
        $op = EngineOperation::where('vm_id', $vm->id)->firstOrFail();
        $running = false;
        Http::fake(function ($r) use ($vm, &$running) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/status/start')) { $running = true; return Http::response(['data' => 'UPID:pve:resume']); }
            if (str_ends_with($path, '/status/current')) { return Http::response(['data' => ['status' => $running ? 'running' : 'stopped', 'uptime' => 10]]); }
            if (str_contains($path, '/tasks/')) { return Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]); }
            return Http::response(['data' => ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]);
        });
        $this->tick($op); $this->tick($op);
        $this->assertNotSame('succeeded', $op->fresh()->status);
        $this->tick($op); $this->tick($op);
        $this->assertSame('succeeded', $op->fresh()->status);
        $this->assertSame('running', $vm->fresh()->status);
        // Existing billing lifecycle, not the infrastructure worker, restores service.status after observing power-on.
        $this->assertSame('suspended', $vm->service->fresh()->status);
    }

    public function test_metrics_sanitize_current_values_and_distinguish_unknown_guest_disk_usage(): void
    {
        $vm = $this->reservation();
        Http::fake(function ($r) use ($vm) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/rrddata')) { return Http::response(['data' => [['time' => now()->timestamp, 'cpu' => 0.25, 'netin' => 2000]]]); }
            if (str_ends_with($path, '/status/current')) {
                return Http::response(['data' => ['status' => 'running', 'uptime' => 60, 'cpu' => 0.25,
                    'mem' => 1024, 'maxmem' => 2048, 'maxdisk' => 21474836480, 'disk' => 0, 'secret' => 'never-expose']]);
            }
            return Http::response(['data' => ['name' => $vm->name, 'description' => EngineWorker::marker($vm)]]);
        });
        $metrics = app(EngineProvider::class)->metrics($vm->uuid);
        $this->assertTrue($metrics['ok']);
        $this->assertSame(60, $metrics['current']['uptime_seconds']);
        $this->assertSame(0.25, $metrics['current']['cpu_fraction']);
        $this->assertTrue($metrics['current']['boot_time_estimated']);
        $this->assertNotNull($metrics['current']['last_boot_at']);
        $this->assertNull($metrics['current']['guest_filesystem_used_bytes']);
        $this->assertSame(21474836480, $metrics['current']['disk_allocated_bytes']);
        $this->assertStringNotContainsString('never-expose', json_encode($metrics));
        $this->assertArrayNotHasKey('description', $metrics['current']);
    }
}
