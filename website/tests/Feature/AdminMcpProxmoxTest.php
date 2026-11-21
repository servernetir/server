<?php
namespace Tests\Feature;
use App\Models\Setting;
use App\Services\Mcp\InfrastructureRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class AdminMcpProxmoxTest extends TestCase
{
    use RefreshDatabase;
    public function test_verified_transport_and_safe_projection(): void
    {
        Setting::putSecret('proxmox_token_secret', 'fixture');
        Setting::put('proxmox_api_url', 'https://192.0.2.42:8006/api2/json/');
        Setting::put('proxmox_tls_server_name', 'pve.example.test');
        $observed = [];
        Http::fake(function ($request, $options) use (&$observed) {
            $observed = ['url' => $request->url(), 'options' => $options];
            return Http::response(['data' => [['vmid' => 123, 'status' => 'running', 'password' => 'private']]], 200);
        });
        $result = app(InfrastructureRead::class)->status('proxmox');
        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('https://pve.example.test:8006/api2/json/cluster/resources', $observed['url']);
        $this->assertSame(['pve.example.test:8006:192.0.2.42'], $observed['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertTrue($observed['options']['verify']);
        $this->assertFalse($observed['options']['allow_redirects']);
        $this->assertSame([['vmid' => 123, 'status' => 'running']], $result['guests']);
    }
    public function test_invalid_upstream_cannot_be_successful(): void
    {
        Setting::putSecret('proxmox_token_secret', 'fixture');
        Setting::put('proxmox_api_url', 'https://pve.example.test:8006/api2/json');
        foreach ([Http::response('<html>Login</html>', 200), Http::response(['data' => ['wrong' => true]], 200),
            Http::response(['data' => [null]], 200), Http::response('', 302)] as $response) {
            Http::fake(['*' => $response]);
            $this->assertFalse(app(InfrastructureRead::class)->status('proxmox')['ok']);
        }
    }
    public function test_no_insecure_fallback(): void
    {
        Setting::putSecret('proxmox_token_secret', 'fixture');
        Setting::put('proxmox_api_url', 'http://pve.example.test:8006/api2/json');
        Http::fake();
        $this->assertFalse(app(InfrastructureRead::class)->status('proxmox')['ok']);
        Http::assertNothingSent();
    }
}
