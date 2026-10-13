<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Setting;
use App\Services\Cloud\HetznerClient;
use App\Services\Provisioning\ObjectGatewayBootstrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObjectGatewayColdStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_cold_start_requires_upstream_credentials_acceptance_and_gateway_marker(): void
    {
        $server = Server::create([
            'name' => 'Object Gateway', 'type' => 'hetzner_object_gateway', 'status' => 'active',
            'hostname' => 'object-api.servernet.cloud', 'meta' => ['cold_start' => true],
        ]);
        $bootstrap = app(ObjectGatewayBootstrapper::class);
        $this->assertFalse($bootstrap->coldStartReady($server));

        Setting::putSecret(ObjectGatewayBootstrapper::ACCESS_SETTING, 'access');
        Setting::putSecret(ObjectGatewayBootstrapper::SECRET_SETTING, 'secret');
        Setting::putSecret('hetzner_api_token', 'provider-token');
        Setting::putSecret('cloudflare_token', 'cloudflare-token');
        Setting::put(ObjectGatewayBootstrapper::ACCEPTED_SETTING, json_encode(['ok' => true]));

        $this->assertTrue($bootstrap->coldStartReady($server));
    }

    public function test_cloud_init_contains_runtime_secrets_but_never_writes_them_to_settings_or_logs(): void
    {
        Setting::putSecret(ObjectGatewayBootstrapper::ACCESS_SETTING, 'ACCESS-ONLY-FOR-TEST');
        Setting::putSecret(ObjectGatewayBootstrapper::SECRET_SETTING, 'SECRET-ONLY-FOR-TEST');

        $script = app(ObjectGatewayBootstrapper::class)->cloudInit(str_repeat('h', 64));

        $this->assertStringContainsString('ACCESS-ONLY-FOR-TEST', $script);
        $this->assertStringContainsString('SECRET-ONLY-FOR-TEST', $script);
        $this->assertStringContainsString('certbot --nginx', $script);
        $this->assertStringContainsString('versitygw_v1.8.0_Linux_arm64.tar.gz', $script);
        $this->assertStringNotContainsString('__SOURCE_ARCHIVE_B64__', $script);
    }

    public function test_lifecycle_keeps_a_fresh_gateway_while_bootstrap_is_still_running(): void
    {
        Server::create([
            'name' => 'Object Gateway', 'type' => 'hetzner_object_gateway', 'status' => 'maintenance',
            'hostname' => 'object-api.servernet.cloud',
            'meta' => ['provider_ref' => 'new-server', 'ipv4' => '192.0.2.10', 'started_at' => now()->toIso8601String()],
        ]);
        $client = $this->mock(HetznerClient::class);
        $client->shouldNotReceive('deleteServer');

        $this->artisan('hetzner:object-gateway-lifecycle')->assertSuccessful();

        $this->assertSame('new-server', Server::first()->meta['provider_ref']);
    }
}
