<?php

namespace Tests\Feature;

use App\Models\{CloudSshKey, EngineVm, Invoice};
use App\Services\Cloud\Engine\{EngineProvider, EngineSshKeys};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\EngineInventory;
use Tests\TestCase;

class EngineSshKeysTest extends TestCase
{
    use RefreshDatabase, EngineInventory;

    private function publicKey(): string
    {
        return 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('a', 32)).' test-fixture';
    }

    public function test_owned_public_key_reaches_encrypted_provisioning_credentials_without_remote_registration(): void
    {
        config(['cloud-engine.enabled' => true]); Http::preventStrayRequests();
        $plan = $this->enginePlan($this->engineNode()); $service = $this->engineService($plan);
        $key = CloudSshKey::create(['customer_id' => $service->customer_id, 'name' => 'fixture', 'public_key' => $this->publicKey(),
            'fingerprint' => CloudSshKey::inspect($this->publicKey())['fingerprint'], 'key_type' => 'ssh-ed25519']);
        $service->update(['cloud_ssh_key_id' => $key->id]);
        Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'total' => 1000000, 'paid' => 1000000, 'status' => 'paid', 'paid_at' => now()]);
        $driver = app(EngineProvider::class); $uploaded = $driver->uploadSshKey('fixture', $key->public_key);
        $this->assertTrue($uploaded['ok']);
        $this->assertTrue($driver->capabilities()['ssh_key']);
        $spec = ['name' => 'sn-svc-'.$service->id, 'plan_ref' => $plan->provider_ref,
            'image_ref' => 'ubuntu-24.04', 'labels' => ['snet-service' => $service->id], 'ssh_keys' => [$uploaded['ref']]];
        $this->assertTrue($driver->createServer($spec)['ok']);
        $vm = EngineVm::firstOrFail();
        $this->assertSame([$this->publicKey()], $vm->credentials['ssh_keys']);
        $this->assertStringNotContainsString($key->public_key, $vm->getRawOriginal('credentials'));
        Http::assertNothingSent();
    }

    public function test_private_invalid_and_foreign_keys_fail_closed(): void
    {
        $keys = app(EngineSshKeys::class);
        $this->assertFalse($keys->upload('-----BEGIN OPENSSH PRIVATE KEY----- fixture')['ok']);
        $this->assertFalse($keys->upload('ssh-rsa invalid')['ok']);
        $plan = $this->enginePlan($this->engineNode()); $service = $this->engineService($plan);
        $key = CloudSshKey::create(['customer_id' => $service->customer_id + 100, 'name' => 'foreign',
            'public_key' => $this->publicKey(), 'fingerprint' => 'fixture', 'key_type' => 'ssh-ed25519']);
        $service->cloud_ssh_key_id = $key->id;
        $this->expectException(\DomainException::class);
        $keys->resolve($service, [$keys->upload($key->public_key)['ref']]);
    }
}
