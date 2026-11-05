<?php

namespace Tests\Feature;

use App\Models\{EngineAddress, EngineImage, EngineNode, EnginePlanNode, EngineVm};
use App\Services\Cloud\Engine\Placement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EnginePlacementTest extends TestCase
{
    use RefreshDatabase, EngineInventory;

    public function test_duplicate_physical_node_inventory_cannot_double_capacity(): void
    {
        $node = $this->engineNode();
        $this->expectException(\Illuminate\Database\QueryException::class);
        EngineNode::create(array_merge($node->getAttributes(), ['id' => null, 'name' => 'duplicate',
            'api_url' => 'https://PVE.EXAMPLE.TEST:8006/api2/json/', 'token_secret' => 'fixture-only-secret']));
    }

    public function test_placement_is_persisted_and_replay_reserves_nothing_twice(): void
    {
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $service = $this->engineService($plan);
        $vm = app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04');
        $again = app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04');
        $this->assertSame($vm->id, $again->id);
        $this->assertSame('sn-svc-'.$service->id, $vm->name);
        $this->assertSame(1, EngineVm::count());
        $this->assertSame(1, EngineAddress::where('state', 'allocated')->count());
        $this->assertSame((int) $service->id, (int) $vm->address->service_id);
        $this->assertSame((int) $plan->traffic_gb * 1000000000, $vm->metadata['traffic_quota_bytes']);
        $this->assertNotSame('fixture-only-secret', $node->getRawOriginal('token_secret'));
        $this->assertArrayNotHasKey('token_secret', $node->toArray());
    }

    public function test_changed_replay_is_rejected_without_allocating_another_guest(): void
    {
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $service = $this->engineService($plan);
        app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04', 'first.example.test');
        $this->expectException(\DomainException::class);
        try {
            app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04', 'different.example.test');
        } finally {
            $this->assertSame(1, EngineVm::count());
        }
    }

    public function test_defaults_and_stale_draining_or_unhealthy_nodes_block_sales(): void
    {
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        foreach ([['sales_enabled' => false], ['draining' => true], ['health' => 'unknown'],
            ['health_checked_at' => now()->subMinutes(6)], ['health_checked_at' => now()->addHour()]] as $change) {
            $node->update(['sales_enabled' => true, 'draining' => false, 'health' => 'healthy', 'health_checked_at' => now()]);
            $node->update($change);
            try {
                app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
                $this->fail('Unsafe node accepted sales.');
            } catch (\DomainException) {
                $this->assertSame(0, EngineVm::count());
            }
        }
        $node->update(['sales_enabled' => true, 'draining' => false, 'health' => 'healthy', 'health_checked_at' => now()]);
        EnginePlanNode::query()->update(['enabled' => false]);
        $this->expectException(\DomainException::class);
        app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
    }

    public function test_capacity_reserve_and_uncertain_guests_force_another_eligible_node(): void
    {
        $a = $this->engineNode(['vcpu_capacity' => 3]);
        $b = $this->engineNode([], '8.8.8.8/29');
        $plan = $this->enginePlan($a, $b);
        $first = app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $this->assertSame((int) $a->id, (int) $first->node_id);
        $first->update(['status' => 'uncertain']);
        $second = app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $this->assertSame((int) $b->id, (int) $second->node_id);
        $this->assertNotSame($first->address->address, $second->address->address);
    }

    public function test_image_acceptance_architecture_and_windows_licensing_are_enforced(): void
    {
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $image = EngineImage::firstOrFail();
        foreach ([['initialization_verified_at' => null], ['checksum_sha256' => 'invalid'],
            ['family' => 'windows', 'license_verified_at' => null], ['arch' => 'arm']] as $change) {
            $image->update(['initialization_verified_at' => now(), 'checksum_sha256' => str_repeat('a', 64),
                'family' => 'ubuntu', 'arch' => 'x86']);
            $image->update($change);
            try {
                app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
                $this->fail('Unaccepted image was reserved.');
            } catch (\DomainException) {
                $this->assertSame(0, EngineVm::count());
            }
        }
    }

    public function test_windows_needs_verified_receipt_contract_and_valid_computer_name_before_allocation(): void
    {
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $image = EngineImage::firstOrFail();
        $image->update(['key' => 'windows-2022', 'family' => 'windows', 'version' => '2022',
            'license_verified_at' => now(), 'metadata' => ['guest_agent_verified' => true]]);
        $this->assertFalse($image->accepted());
        try {
            app(Placement::class)->reserve($this->engineService($plan), $plan, 'windows-2022');
            $this->fail('Windows without a verified first-boot receipt contract was allocated.');
        } catch (\DomainException) {
            $this->assertSame(0, EngineVm::count());
        }
        $image->update(['metadata' => ['guest_agent_verified' => true, 'windows_ready_receipt_verified' => true]]);
        $this->assertTrue($image->accepted());
        foreach (['computer.example.test', 'computer-name-too-long'] as $hostname) {
            try {
                app(Placement::class)->reserve($this->engineService($plan), $plan, 'windows-2022', $hostname);
                $this->fail('Invalid Windows computer name was allocated.');
            } catch (\DomainException) {
                $this->assertSame(0, EngineVm::count());
            }
        }
        $vm = app(Placement::class)->reserve($this->engineService($plan), $plan, 'windows-2022', 'WIN-ACCEPTED');
        $this->assertSame('WIN-ACCEPTED', $vm->metadata['hostname']);
        $image->update(['version' => 'unverified-edition']);
        $this->assertFalse($image->accepted());
    }

    public function test_linux_without_verified_guest_agent_never_becomes_saleable_or_allocates(): void
    {
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $image = EngineImage::firstOrFail();
        $image->update(['metadata' => ['guest_agent_verified' => false]]);
        $this->assertFalse($image->accepted());
        $this->expectException(\DomainException::class);
        try {
            app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        } finally {
            $this->assertSame(0, EngineVm::count());
            $this->assertSame(0, EngineAddress::where('state', 'allocated')->count());
        }
    }

    public function test_template_vmid_is_never_allocated_and_exhaustion_leaves_no_ip_reservation(): void
    {
        $node = $this->engineNode(['vmid_min' => 9000, 'vmid_max' => 9001]);
        $plan = $this->enginePlan($node);
        $vm = app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $this->assertSame(9001, (int) $vm->vmid);
        $this->expectException(\DomainException::class);
        try {
            app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        } finally {
            $this->assertSame(1, EngineAddress::where('state', 'allocated')->count());
        }
    }

    public function test_cancelled_service_cannot_reserve_and_ram_or_disk_exhaustion_is_atomic(): void
    {
        $node = $this->engineNode(['ram_mb_capacity' => 2048, 'ram_mb_reserve' => 1024]);
        $plan = $this->enginePlan($node);
        $service = $this->engineService($plan);
        try {
            app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04');
            $this->fail('RAM safety reserve was ignored.');
        } catch (\DomainException) {
            $this->assertSame(0, EngineVm::count());
        }
        $node->update(['ram_mb_capacity' => 16384, 'disk_gb_capacity' => 60]);
        try {
            app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04');
            $this->fail('Disk safety reserve was ignored.');
        } catch (\DomainException) {
            $this->assertSame(0, EngineVm::count());
        }
        $node->update(['disk_gb_capacity' => 500]);
        $service->update(['status' => 'cancelled']);
        $this->expectException(\DomainException::class);
        app(Placement::class)->reserve($service, $plan, 'ubuntu-24.04');
    }
}
