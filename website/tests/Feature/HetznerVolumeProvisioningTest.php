<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\HetznerVolumeProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HetznerVolumeProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_deterministic_unattached_volume_and_delivers_device_metadata(): void
    {
        Setting::putSecret('hetzner_api_token', 'test-token');
        $customer = Customer::create(['code' => 'SN-VOL-1', 'email' => 'volume@example.test',
            'password' => bcrypt('Secret123!'), 'status' => 'active']);
        $server = Server::create(['name' => 'HZ-BLOCK-CATALOG', 'type' => 'hetzner_volume', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'server_id' => $server->id,
            'name' => 'Block 100', 'price' => 100000, 'currency_code' => 'IRT', 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'plan' => 'sn_volume_100gb_fsn1',
            'provision_meta' => ['volume_offer' => ['size_gb' => 100, 'location' => 'fsn1']]]);
        Http::fakeSequence()->push(['volumes' => []], 200)->push(['volume' => [
            'id' => 321, 'name' => 'sn-volume-'.$service->id, 'size' => 100,
            'linux_device' => '/dev/disk/by-id/scsi-0HC_Volume_321',
            'location' => ['name' => 'fsn1'], 'server' => null,
        ]], 201);

        $result = app(HetznerVolumeProvisioner::class)->create($service);

        $this->assertTrue($result->ok);
        $this->assertSame('321', $result->username);
        $this->assertSame(100, $result->meta['size_gb']);
        $this->assertSame('fsn1', $result->meta['location']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.hetzner.cloud/v1/volumes'
            && $r['name'] === 'sn-volume-'.$service->id && $r['size'] === 100 && $r['automount'] === false);
    }

    public function test_it_adopts_an_existing_matching_volume_without_buying_again(): void
    {
        Setting::putSecret('hetzner_api_token', 'test-token');
        $customer = Customer::create(['code' => 'SN-VOL-2', 'email' => 'volume2@example.test',
            'password' => bcrypt('Secret123!'), 'status' => 'active']);
        $server = Server::create(['name' => 'HZ-BLOCK-CATALOG', 'type' => 'hetzner_volume', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'server_id' => $server->id,
            'name' => 'Block 500', 'price' => 100000, 'currency_code' => 'IRT', 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'plan' => 'sn_volume_500gb_hel1',
            'provision_meta' => ['volume_offer' => ['size_gb' => 500, 'location' => 'hel1']]]);
        Http::fake(["*/volumes*" => Http::response(['volumes' => [[
            'id' => 654, 'size' => 500, 'linux_device' => '/dev/volume-654',
            'location' => ['name' => 'hel1'], 'server' => null,
        ]]], 200)]);

        $result = app(HetznerVolumeProvisioner::class)->create($service);

        $this->assertTrue($result->ok);
        $this->assertTrue($result->meta['reused']);
        Http::assertSentCount(1);
    }
}
