<?php

namespace Tests\Fixtures;

use App\Models\{CloudPlan, Customer, EngineImage, EngineNode, EnginePlanNode, Service};
use App\Services\Cloud\Engine\Ipam;

trait EngineInventory
{
    private function engineNode(array $attributes = [], string $cidr = '8.8.8.0/29'): EngineNode
    {
        $node = EngineNode::create(array_merge([
            'name' => 'node-'.EngineNode::count(), 'location_code' => 'de-fsn',
            'api_url' => 'https://pve.example.test:8006/api2/json', 'pve_node' => 'pve-'.(EngineNode::count() + 1),
            'token_id' => 'engine@pve!worker', 'token_secret' => 'fixture-only-secret',
            'storage' => 'local-lvm', 'bridge' => 'vmbr0',
            'gateway' => long2ip(ip2long(explode('/', $cidr)[0]) + 1),
            'sales_enabled' => true, 'health' => 'healthy', 'health_checked_at' => now(),
            'vcpu_capacity' => 8, 'ram_mb_capacity' => 16384, 'disk_gb_capacity' => 500,
            'vcpu_reserve' => 1, 'ram_mb_reserve' => 1024, 'disk_gb_reserve' => 50,
        ], $attributes));
        $subnet = app(Ipam::class)->import($node, $cidr, $node->gateway);
        $subnet->update(['enabled' => true]);
        EngineImage::create([
            'node_id' => $node->id, 'key' => 'ubuntu-24.04', 'family' => 'ubuntu', 'version' => '24.04',
            'template_vmid' => 9000, 'checksum_sha256' => str_repeat('a', 64),
            'source_url' => 'https://cloud-images.ubuntu.com/accepted.img',
            'accepted_at' => now(), 'initialization_verified_at' => now(),
            'metadata' => ['guest_agent_verified' => true],
            'min_ram_mb' => 1024, 'min_disk_gb' => 10,
        ]);

        return $node;
    }

    private function enginePlan(EngineNode ...$nodes): CloudPlan
    {
        $plan = CloudPlan::create([
            'provider' => 'cloud-engine', 'provider_ref' => 'economy-'.CloudPlan::count(),
            'location_code' => 'de-fsn', 'public_name' => 'Economy', 'slug' => 'engine-economy-'.CloudPlan::count(),
            'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 20, 'arch' => 'x86',
            'price_irt' => 1000000, 'is_active' => true, 'in_stock' => true,
        ]);
        foreach ($nodes as $node) {
            EnginePlanNode::create(['cloud_plan_id' => $plan->id, 'node_id' => $node->id, 'enabled' => true]);
        }

        return $plan;
    }

    private function engineService(CloudPlan $plan): Service
    {
        $customer = Customer::create(['email' => 'engine-'.Customer::count().'@example.test',
            'password' => 'fixture-password', 'status' => 'active', 'locale' => 'fa']);

        return Service::create([
            'customer_id' => $customer->id, 'name' => 'Simulated VPS', 'price' => 1000000,
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'awaiting_provision',
            'provision_status' => 'pending', 'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
        ]);
    }
}
