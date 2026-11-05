<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudPlan, EngineAddress, EngineImage, EngineNode, EngineOperation, EnginePlanNode, EngineSubnet, User};
use App\Services\Cloud\Engine\{EngineOperations, NodeHealth, Placement};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EngineAdminTest extends TestCase
{
    use RefreshDatabase, EngineInventory;

    private function asAdmin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
    }

    private function nodeData(array $overrides = []): array
    {
        return array_replace([
            'name' => 'dedicated-fixture', 'location_code' => 'de-fsn',
            'api_url' => 'https://pve.example.test:8006/api2/json', 'pve_node' => 'pve-1',
            'token_id' => 'engine@pve!worker', 'token_secret' => 'fixture-private-node-secret',
            'storage' => 'local-lvm', 'bridge' => 'vmbr0', 'gateway' => '8.8.4.4',
            'vcpu_capacity' => 8, 'ram_mb_capacity' => 16384, 'disk_gb_capacity' => 500,
            'vcpu_reserve' => 1, 'ram_mb_reserve' => 1024, 'disk_gb_reserve' => 50,
            'vmid_min' => 100, 'vmid_max' => 999999999,
        ], $overrides);
    }

    private function existingNodeData(EngineNode $node, array $overrides = []): array
    {
        $fields = array_keys($this->nodeData());
        $fields = array_diff($fields, ['token_secret']);
        return array_replace($node->only($fields), ['token_secret' => ''], $overrides);
    }

    private function imageData(EngineNode $node, array $overrides = []): array
    {
        return array_replace([
            'node_id' => $node->id, 'key' => 'debian-13-test1', 'family' => 'debian',
            'version' => '13-test1', 'template_vmid' => 910013,
            'checksum_sha256' => hash('sha256', 'fixture-verified-image'),
            'source_url' => 'https://cloud.debian.org/images/cloud/trixie/20261101/disk.qcow2',
            'min_ram_mb' => 512, 'min_disk_gb' => 10, 'guest_agent' => 1,
        ], $overrides);
    }

    private function planData(array $overrides = []): array
    {
        return array_replace([
            'provider_ref' => 'dedicated-economy-1', 'slug' => 'dedicated-economy-1',
            'public_name' => 'Dedicated Economy', 'location_code' => 'de-fsn', 'country' => 'DE',
            'city' => 'Falkenstein', 'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 20,
            'traffic_gb' => 500, 'cost_eur_cents' => 500, 'price_irt' => 1000000,
            'price_eur_cents' => 1000,
        ], $overrides);
    }

    public function test_guests_authors_and_support_cannot_read_or_mutate_inventory(): void
    {
        Http::preventStrayRequests();
        $this->get('/admin/cloud-engine')->assertRedirect();
        foreach (['author', 'support'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web');
            foreach (['/admin/cloud-engine', '/en/admin/cloud-engine', '/tr/admin/cloud-engine'] as $uri) {
                $this->get($uri)->assertForbidden();
                $this->post($uri.'/nodes', $this->nodeData())->assertForbidden();
            }
        }
        $this->assertDatabaseCount('cloud_engine_nodes', 0);
        Http::assertNothingSent();
    }

    public function test_three_language_inventory_pages_do_not_expose_credentials_or_operation_payload(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $vm = app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $vm->update(['credentials' => ['password' => 'fixture-never-render-password']]);
        $op = app(EngineOperations::class)->submit($vm, 'create', ['private' => 'fixture-private-payload'], 'fixture-private-key');
        $op->update(['receipt' => ['task' => 'UPID:fixture-private-task']]);
        Http::preventStrayRequests();
        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            $response = $this->get($prefix.'/admin/cloud-engine?node='.$node->id);
            $response->assertOk()->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.($locale === 'fa' ? 'rtl' : 'ltr').'"', false)
                ->assertDontSee('fixture-only-secret')
                ->assertDontSee('fixture-never-render-password')
                ->assertDontSee('fixture-private-payload')
                ->assertDontSee('UPID:fixture-private-task')
                ->assertDontSee('fixture-private-key');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        Http::assertNothingSent();
    }

    public function test_node_registration_works_with_engine_disabled_but_cannot_enable_sales_or_fake_health(): void
    {
        $this->asAdmin();
        config(['cloud-engine.enabled' => false]);
        Http::preventStrayRequests();
        $this->post('/admin/cloud-engine/nodes', $this->nodeData([
            'sales_enabled' => true, 'health' => 'healthy', 'health_checked_at' => now()->toIso8601String(),
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $node = EngineNode::firstOrFail();
        $this->assertFalse($node->sales_enabled);
        $this->assertSame('unknown', $node->health);
        $this->assertNull($node->health_checked_at);
        $this->assertSame('fixture-private-node-secret', $node->token_secret);
        $this->assertNotSame('fixture-private-node-secret', DB::table('cloud_engine_nodes')->value('token_secret'));
        Http::assertNothingSent();
    }

    public function test_invalid_endpoint_and_secret_do_not_leak_into_flashed_input(): void
    {
        $this->asAdmin();
        $this->from('/admin/cloud-engine')->post('/admin/cloud-engine/nodes', $this->nodeData([
            'api_url' => 'https://user:password@pve.example.test/api2/json',
        ]))->assertRedirect()->assertSessionHasErrors('api_url');
        $this->assertNull(session()->getOldInput('token_secret'));
        $this->assertDatabaseCount('cloud_engine_nodes', 0);
    }

    public function test_live_node_endpoint_and_network_identity_are_immutable(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $node->update(['sales_enabled' => false]);
        $this->post('/admin/cloud-engine/nodes/'.$node->id, $this->existingNodeData($node, [
            'api_url' => 'https://other.example.test:8006/api2/json',
        ]))->assertRedirect()->assertSessionHasErrors('api_url');
        $this->assertSame('https://pve.example.test:8006/api2/json', $node->fresh()->api_url);
        $this->post('/admin/cloud-engine/nodes/'.$node->id, $this->existingNodeData($node, ['bridge' => 'vmbr9']))
            ->assertRedirect()->assertSessionHasErrors('bridge');
        $this->assertSame('vmbr0', $node->fresh()->bridge);
    }

    public function test_disable_before_configuration_and_health_reset_after_config_save(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $this->post('/admin/cloud-engine/nodes/'.$node->id, $this->existingNodeData($node, ['name' => 'renamed-node']))
            ->assertRedirect()->assertSessionHasErrors('name');
        $this->post('/admin/cloud-engine/nodes/'.$node->id.'/control', ['action' => 'disable'])->assertRedirect();
        $this->post('/admin/cloud-engine/nodes/'.$node->id, $this->existingNodeData($node->fresh(), ['name' => 'renamed-node']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($node->fresh()->sales_enabled);
        $this->assertSame('unknown', $node->fresh()->health);
        $this->assertNull($node->fresh()->health_checked_at);
    }

    public function test_enable_requires_fresh_health_accepted_image_ip_and_plan_mapping(): void
    {
        $this->asAdmin();
        $node = $this->engineNode(['sales_enabled' => false]);
        $uri = '/admin/cloud-engine/nodes/'.$node->id.'/control';
        $this->post($uri, ['action' => 'enable'])->assertRedirect()->assertSessionHasErrors('action');
        $this->enginePlan($node);
        $node->update(['health_checked_at' => now()->subHour()]);
        $this->post($uri, ['action' => 'enable'])->assertRedirect()->assertSessionHasErrors('action');
        $node->update(['health_checked_at' => now(), 'draining' => true]);
        $this->post($uri, ['action' => 'enable'])->assertRedirect()->assertSessionHasErrors('action');
        $node->update(['draining' => false]);
        $this->post($uri, ['action' => 'enable'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($node->fresh()->sales_enabled);
        $this->post($uri, ['action' => 'drain'])->assertRedirect();
        $this->assertTrue($node->fresh()->draining);
        $this->assertFalse($node->fresh()->sales_enabled);
    }

    public function test_enable_does_not_accept_an_unaccepted_image_or_disabled_subnet(): void
    {
        $this->asAdmin();
        $node = $this->engineNode(['sales_enabled' => false]);
        $this->enginePlan($node);
        $image = $node->images()->firstOrFail();
        $image->update(['accepted_at' => null]);
        $uri = '/admin/cloud-engine/nodes/'.$node->id.'/control';
        $this->post($uri, ['action' => 'enable'])->assertRedirect()->assertSessionHasErrors('action');
        $image->update(['accepted_at' => now()]);
        $node->subnets()->update(['enabled' => false]);
        $this->post($uri, ['action' => 'enable'])->assertRedirect()->assertSessionHasErrors('action');
        $this->assertFalse($node->fresh()->sales_enabled);
    }

    public function test_subnet_import_is_bounded_and_disabled_then_toggle_preserves_allocations(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $this->post('/admin/cloud-engine/subnets', ['node_id' => $node->id, 'cidr' => '10.10.0.0/16', 'gateway' => '8.8.4.4'])
            ->assertRedirect()->assertSessionHasErrors('cidr');
        $this->post('/admin/cloud-engine/subnets', ['node_id' => $node->id, 'cidr' => '9.9.9.0/29', 'gateway' => '9.9.9.1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $subnet = EngineSubnet::where('cidr', '9.9.9.0/29')->firstOrFail();
        $this->assertFalse($subnet->enabled);
        $this->assertDatabaseMissing('cloud_engine_addresses', ['subnet_id' => $subnet->id, 'address' => '9.9.9.1']);
        $before = EngineAddress::where('subnet_id', $subnet->id)->pluck('id')->all();
        $this->post('/admin/cloud-engine/subnets/'.$subnet->id, ['enabled' => true])->assertRedirect();
        $this->assertTrue($subnet->fresh()->enabled);
        $this->post('/admin/cloud-engine/subnets/'.$subnet->id, ['enabled' => false])->assertRedirect();
        $this->assertFalse($subnet->fresh()->enabled);
        $this->assertSame($before, EngineAddress::where('subnet_id', $subnet->id)->pluck('id')->all());
    }

    public function test_images_require_explicit_initialization_evidence_and_windows_license(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $this->post('/admin/cloud-engine/images', $this->imageData($node, ['key' => 'windows-2022-test1', 'family' => 'windows', 'version' => '2022', 'guest_agent_verified' => true]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $image = EngineImage::where('key', 'windows-2022-test1')->firstOrFail();
        $this->assertFalse($image->accepted());
        $this->assertFalse($image->metadata['guest_agent_verified'] ?? false);
        $uri = '/admin/cloud-engine/images/'.$image->id.'/accept';
        $this->post($uri, ['reference' => 'fixture-evidence-20261103', 'initialization_verified' => true])
            ->assertRedirect()->assertSessionHasErrors('license_verified');
        $this->assertFalse($image->fresh()->accepted());
        $this->post($uri, ['reference' => 'short', 'initialization_verified' => true, 'license_verified' => true,
            'guest_agent_verified' => true, 'windows_ready_receipt_verified' => true])
            ->assertRedirect()->assertSessionHasErrors('reference');
        $this->post($uri, ['reference' => 'fixture-evidence-20261103', 'initialization_verified' => true,
            'license_verified' => true, 'guest_agent_verified' => true])
            ->assertRedirect()->assertSessionHasErrors('windows_ready_receipt_verified');
        $this->assertNull($image->fresh()->accepted_at);
        $this->post($uri, ['reference' => 'fixture-evidence-20261103', 'initialization_verified' => true,
            'license_verified' => true, 'guest_agent_verified' => true, 'windows_ready_receipt_verified' => true])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($image->fresh()->accepted());
        $this->assertTrue($image->fresh()->metadata['guest_agent_verified']);
        $this->assertTrue($image->fresh()->metadata['windows_ready_receipt_verified']);
        $this->assertDatabaseHas('cloud_images', ['provider' => 'cloud-engine', 'key' => $image->key, 'is_active' => true]);
    }

    public function test_image_registration_cannot_overwrite_an_existing_template_version(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $existing = $node->images()->firstOrFail();
        $this->post('/admin/cloud-engine/images', $this->imageData($node, ['key' => $existing->key, 'template_vmid' => 999999]))
            ->assertRedirect()->assertSessionHasErrors('key');
        $this->assertSame(9000, $existing->fresh()->template_vmid);
    }

    public function test_linux_image_acceptance_requires_guest_agent_evidence_before_public_catalog(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $this->post('/admin/cloud-engine/images', $this->imageData($node))->assertRedirect()->assertSessionHasNoErrors();
        $image = EngineImage::where('key', 'debian-13-test1')->firstOrFail();
        $uri = '/admin/cloud-engine/images/'.$image->id.'/accept';
        $data = ['reference' => 'fixture-linux-init-20261103', 'initialization_verified' => true];
        $this->post($uri, $data)->assertRedirect()->assertSessionHasErrors('guest_agent_verified');
        $this->assertNull($image->fresh()->accepted_at);
        $this->assertDatabaseMissing('cloud_images', ['provider' => 'cloud-engine', 'key' => $image->key]);
        $this->post($uri, $data + ['guest_agent_verified' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($image->fresh()->accepted());
        $this->assertDatabaseHas('cloud_images', ['provider' => 'cloud-engine', 'key' => $image->key, 'kind' => 'os', 'is_active' => true]);
    }

    public function test_plan_mapping_rejects_foreign_provider_and_wrong_location(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $plan = $this->enginePlan();
        $data = ['node_id' => $node->id, 'cloud_plan_id' => $plan->id, 'enabled' => true];
        $plan->update(['provider' => 'hetzner']);
        $this->post('/admin/cloud-engine/mappings', $data)->assertUnprocessable();
        $plan->update(['provider' => 'cloud-engine', 'location_code' => 'fi-hel']);
        $this->post('/admin/cloud-engine/mappings', $data)->assertUnprocessable();
        $this->assertDatabaseCount('cloud_engine_plan_nodes', 0);
        $plan->update(['location_code' => $node->location_code]);
        $this->post('/admin/cloud-engine/mappings', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('cloud_engine_plan_nodes', ['node_id' => $node->id, 'cloud_plan_id' => $plan->id, 'enabled' => true]);
    }

    public function test_new_catalog_plan_has_explicit_toman_price_and_sales_initially_closed(): void
    {
        $this->asAdmin();
        $this->post('/admin/cloud-engine/plans', $this->planData([
            'provider' => 'hetzner', 'in_stock' => true, 'is_active' => true, 'admin_disabled' => false,
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $plan = CloudPlan::where('slug', 'dedicated-economy-1')->firstOrFail();
        $this->assertSame('cloud-engine', $plan->provider);
        $this->assertSame(1000000, $plan->price_irt);
        $this->assertSame(1000, $plan->price_eur_cents);
        $this->assertFalse($plan->in_stock);
        $this->assertTrue($plan->admin_disabled);
        $this->assertDatabaseHas('cloud_locations', ['code' => 'de-fsn', 'country' => 'DE']);
    }

    public function test_catalog_registration_never_overwrites_existing_or_other_provider_prices(): void
    {
        $this->asAdmin();
        $other = $this->enginePlan();
        $other->update(['provider' => 'hetzner', 'provider_ref' => 'dedicated-economy-1', 'price_irt' => 777777]);
        $this->post('/admin/cloud-engine/plans', $this->planData())->assertRedirect()->assertSessionHasNoErrors();
        $new = CloudPlan::where('provider', 'cloud-engine')->where('provider_ref', 'dedicated-economy-1')->firstOrFail();
        $this->post('/admin/cloud-engine/plans', $this->planData(['price_irt' => 1]))
            ->assertRedirect()->assertSessionHasErrors('provider_ref');
        $this->assertSame(1000000, $new->fresh()->price_irt);
        $this->assertSame(777777, $other->fresh()->price_irt);
    }

    public function test_admin_retry_preserves_uncertain_phase_receipt_payload_and_lease_identity(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $vm = app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $op = app(EngineOperations::class)->submit($vm, 'create', ['fixture' => 'immutable-payload'], 'admin-retry');
        $op->update(['status' => 'uncertain', 'phase' => 'clone_reconcile',
            'receipt' => ['task' => 'UPID:fixture-task'], 'lease_token' => 'fixture-old-worker',
            'lease_until' => now()->subMinute()]);
        $this->post('/admin/cloud-engine/operations/'.$op->id.'/retry', ['confirm' => true])->assertRedirect()->assertSessionHasNoErrors();
        $fresh = $op->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('clone_reconcile', $fresh->phase);
        $this->assertSame($op->receipt, $fresh->receipt);
        $this->assertSame($op->payload, $fresh->payload);
        $this->assertSame($op->lease_token, $fresh->lease_token);
        $this->assertTrue($op->lease_until->eq($fresh->lease_until));
        $this->assertDatabaseHas('cloud_engine_operation_events', ['operation_id' => $op->id,
            'phase' => 'clone_reconcile', 'error_code' => 'admin_reconcile']);
    }

    public function test_admin_retry_does_not_steal_live_lease_or_repeat_completed_operation(): void
    {
        $this->asAdmin();
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $vm = app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $op = app(EngineOperations::class)->submit($vm, 'create', [], 'leased-retry');
        $op->update(['status' => 'uncertain', 'lease_until' => now()->addMinutes(2)]);
        $uri = '/admin/cloud-engine/operations/'.$op->id.'/retry';
        $this->post($uri, ['confirm' => true])->assertConflict();
        $this->assertSame('uncertain', $op->fresh()->status);
        $op->update(['status' => 'succeeded', 'lease_until' => null]);
        $this->post($uri, ['confirm' => true])->assertConflict();
        $this->assertSame('succeeded', $op->fresh()->status);
    }

    private function healthyNodeResponse(array $overrides = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(function ($request) use ($overrides) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $data = match (true) {
                str_contains($path, '/storage/') => ['active' => 1, 'total' => 600 * 1073741824, 'avail' => 600 * 1073741824],
                str_ends_with($path, '/network') => [['iface' => 'vmbr0', 'type' => 'bridge', 'active' => 1]],
                str_ends_with($path, '/qemu') => [['vmid' => 9000, 'template' => 1]],
                default => ['uptime' => 123, 'cpuinfo' => ['cpus' => 16, 'cores' => 8, 'sockets' => 1],
                    'memory' => ['total' => 17179869184, 'free' => 17179869184]],
            };
            foreach ($overrides as $suffix => $replacement) {
                if (str_ends_with($path, $suffix)) $data = $replacement;
            }
            return Http::response(['data' => $data]);
        });
    }

    public function test_node_probe_checks_upstream_response_and_rejects_physical_cpu_oversubscription(): void
    {
        $this->asAdmin();
        $node = $this->engineNode(['health' => 'unknown', 'health_checked_at' => null]);
        $this->healthyNodeResponse();
        $this->post('/admin/cloud-engine/nodes/'.$node->id.'/control', ['action' => 'probe'])->assertRedirect();
        $this->assertSame('healthy', $node->fresh()->health);
        $node->update(['vcpu_capacity' => 9]);
        $result = app(NodeHealth::class)->check($node);
        $this->assertFalse($result['ok']);
        $this->assertSame('capacity_mismatch', $node->fresh()->health);
        $this->healthyNodeResponse(['/status' => []]);
        $this->assertFalse(app(NodeHealth::class)->check($node->fresh())['ok']);
        $this->assertSame('unreachable', $node->fresh()->health);
    }

    public function test_node_probe_rejects_missing_bridge_and_unmanaged_guest_inventory(): void
    {
        $node = $this->engineNode();
        $this->healthyNodeResponse(['/network' => []]);
        $this->assertSame('network_unverified', app(NodeHealth::class)->check($node)['state']);
        $this->healthyNodeResponse(['/qemu' => [['vmid' => 9000, 'template' => 1], ['vmid' => 777, 'template' => 0]]]);
        $this->assertSame('inventory_unverified', app(NodeHealth::class)->check($node->fresh())['state']);
        $this->healthyNodeResponse(['/qemu' => []]);
        $this->assertSame('inventory_unverified', app(NodeHealth::class)->check($node->fresh())['state']);
    }
}
