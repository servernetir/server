<?php

namespace Tests\Feature;

use App\Models\{EngineAddress, EngineIpEvent, EngineSubnet};
use App\Services\Cloud\Engine\{Ipam, Placement};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EngineIpamTest extends TestCase
{
    use RefreshDatabase, EngineInventory;

    public function test_import_excludes_network_broadcast_gateway_and_is_repeatable(): void
    {
        $node = $this->engineNode(['gateway' => '8.8.8.1']);
        $subnet = app(Ipam::class)->import($node, '8.8.8.0/29', '8.8.8.1');
        $this->assertSame(1, EngineSubnet::count());
        $this->assertSame(['8.8.8.2', '8.8.8.3', '8.8.8.4', '8.8.8.5', '8.8.8.6'],
            $subnet->addresses()->orderBy('id')->pluck('address')->all());
        $this->assertSame(5, EngineIpEvent::where('action', 'imported')->count());
    }

    public function test_nonpublic_noncanonical_oversized_and_overlapping_imports_are_rejected(): void
    {
        $node = $this->engineNode();
        foreach (['10.0.0.0/24', '203.0.113.0/24', '100.64.0.0/24', '224.0.0.0/24',
            '8.8.8.1/29', '8.8.0.0/16', '8.8.8.0/30', '8.8.8.8/31'] as $cidr) {
            try {
                app(Ipam::class)->import($node, $cidr, $node->gateway);
                $this->fail('Unsafe subnet imported: '.$cidr);
            } catch (\DomainException) {
                $this->assertSame(1, EngineSubnet::count());
            }
        }
        $this->assertSame(5, EngineAddress::count());
    }

    public function test_gateway_must_be_a_usable_host_inside_the_routed_subnet(): void
    {
        $node = $this->engineNode();
        foreach (['8.8.4.4', '8.8.8.8', '8.8.8.15'] as $gateway) {
            try {
                app(Ipam::class)->import($node, '8.8.8.8/29', $gateway);
                $this->fail('An external or reserved gateway was imported.');
            } catch (\DomainException) {
                $this->assertSame(1, EngineSubnet::count());
            }
        }
        $subnet = app(Ipam::class)->import($node, '8.8.8.8/29', '8.8.8.9');
        $this->assertSame('8.8.8.9', $subnet->gateway);
        $this->assertSame(5, $subnet->addresses()->count());
        $this->assertNotContains('8.8.8.9', $subnet->addresses()->pluck('address'));
    }

    public function test_unknown_or_mismatched_deletion_never_releases_the_ip(): void
    {
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $vm = app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $vm->update(['status' => 'deleting']);
        $proof = $this->proof($vm);
        foreach ([['absent' => false], ['network_clear' => false], ['console_clear' => false],
            ['uuid' => 'other'], ['node_id' => 999], ['vmid' => 999],
            ['verified_at' => now()->subHour()->toIso8601String()]] as $change) {
            try {
                app(Ipam::class)->releaseVerified($vm, array_merge($proof, $change));
                $this->fail('Unverified release succeeded.');
            } catch (\DomainException) {
                $this->assertSame('allocated', $vm->address()->first()->state);
            }
        }
        $this->assertSame(0, EngineIpEvent::where('action', 'released')->count());
    }

    public function test_verified_release_keeps_history_and_replay_cannot_release_another_guests_ip(): void
    {
        $node = $this->engineNode();
        $plan = $this->enginePlan($node);
        $vm = app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $vm->update(['status' => 'deleting']);
        app(Ipam::class)->releaseVerified($vm, $this->proof($vm));
        app(Ipam::class)->releaseVerified($vm, $this->proof($vm));
        $this->assertSame(1, EngineIpEvent::where('action', 'released')->count());
        $this->assertSame('deleted', $vm->fresh()->status);
        $next = app(Placement::class)->reserve($this->engineService($plan), $plan, 'ubuntu-24.04');
        $this->assertSame($vm->address_id, $next->address_id);
        $this->assertNotSame((int) $vm->vmid, (int) $next->vmid);
        $this->expectException(\DomainException::class);
        try {
            app(Ipam::class)->releaseVerified($vm, $this->proof($vm));
        } finally {
            $this->assertSame((int) $next->id, (int) $next->address()->first()->vm_id);
        }
    }

    public function test_history_cannot_be_updated_or_deleted_through_models(): void
    {
        $this->engineNode();
        $event = EngineIpEvent::firstOrFail();
        $this->expectException(\LogicException::class);
        $event->update(['action' => 'invented']);
    }

    private function proof($vm): array
    {
        return ['uuid' => $vm->uuid, 'node_id' => (int) $vm->node_id, 'vmid' => (int) $vm->vmid,
            'absent' => true, 'network_clear' => true, 'console_clear' => true,
            'verified_at' => now()->toIso8601String()];
    }
}
