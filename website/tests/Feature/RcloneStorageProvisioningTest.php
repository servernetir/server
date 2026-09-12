<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Provisioning\RcloneStorageProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RcloneStorageProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private function server(): Server
    {
        return Server::create([
            'name' => 'Managed backup gateway', 'type' => 'rclone_storage',
            'hostname' => 'https://gateway.example.test/control/path', 'port' => 9080,
            'api_token' => 'a-secret-long-enough-for-tests', 'verify_tls' => true,
            'status' => 'active',
        ]);
    }

    private function service(Server $server, array $over = []): Service
    {
        $customer = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'r'.random_int(1000, 99999).'@x.test',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create(array_merge([
            'customer_id' => $customer->id, 'server_id' => $server->id,
            'name' => 'هاست بکاپ — BK-100', 'plan' => 'sn_backup_1',
            'currency_code' => 'IRT', 'price' => 200000, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'pending',
        ], $over));
    }

    private function tenant(Service $service, array $over = []): array
    {
        return array_merge([
            'id' => 'sn-svc-'.$service->id, 'username' => 'sn'.$service->id,
            'endpoint' => 'backup.example.test', 'port' => 2022,
            'quota_bytes' => 100 * 1024 ** 3, 'used_bytes' => 0,
            'pool' => 'google', 'status' => 'active',
        ], $over);
    }

    public function test_it_is_registered_as_an_automatic_driver_and_admin_type(): void
    {
        $server = $this->server();
        $this->assertSame('rclone_storage', app(ProvisioningService::class)->driverFor($server)->slug());
        $this->assertContains('rclone_storage', Server::AUTO_TYPES);
        $blade = file_get_contents(resource_path('views/admin/partials/server-form.blade.php'));
        $this->assertStringContainsString("'rclone_storage'=>", $blade);
    }

    public function test_it_creates_one_deterministic_tenant_with_the_exact_quota_and_hmac(): void
    {
        $server = $this->server();
        $service = $this->service($server);
        Http::fake(function ($request) use ($service) {
            if ($request->method() === 'GET') {
                return Http::response(['error' => 'not_found'], 404);
            }

            return Http::response(['tenant' => $this->tenant($service)], 201);
        });

        $result = (new RcloneStorageProvisioner)->create($service);
        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame('sftp://backup.example.test:2022', $result->panelUrl);

        Http::assertSent(function ($request) use ($service, $server) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() !== 'PUT' || $path !== '/v1/tenants/sn-svc-'.$service->id) {
                return false;
            }
            $timestamp = $request->header('X-ServerNet-Timestamp')[0] ?? '';
            $nonce = $request->header('X-ServerNet-Nonce')[0] ?? '';
            $signature = $request->header('X-ServerNet-Signature')[0] ?? '';
            $body = $request->body();
            $expected = hash_hmac('sha256', "PUT\n{$path}\n{$timestamp}\n{$nonce}\n{$body}", $server->api_token);
            $data = json_decode($body, true);

            return hash_equals($expected, $signature)
                && ($data['quota_bytes'] ?? null) === 100 * 1024 ** 3
                && ($data['username'] ?? null) === 'sn'.$service->id
                && ! str_contains($body, 'oauth') && ! str_contains($body, 'refresh_token');
        });
    }

    public function test_an_existing_tenant_is_not_created_again_and_unknown_password_is_rotated(): void
    {
        $server = $this->server();
        $service = $this->service($server, ['password' => 'wrong-generated-panel-password']);
        Http::fake(function ($request) use ($service) {
            if ($request->method() === 'GET') {
                return Http::response(['tenant' => $this->tenant($service)], 200);
            }
            if (str_ends_with($request->url(), '/credentials')) {
                return Http::response(['tenant' => $this->tenant($service)], 200);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $result = (new RcloneStorageProvisioner)->create($service);
        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertNotSame('wrong-generated-panel-password', $result->password);
        $this->assertSame($result->password, $result->meta['gateway_password']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/credentials'));
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_it_fails_closed_when_existence_is_unknown(): void
    {
        $service = $this->service($this->server());
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $result = (new RcloneStorageProvisioner)->create($service);
        $this->assertFalse($result->ok);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_a_timeout_after_create_is_reconciled_without_a_second_create(): void
    {
        $service = $this->service($this->server());
        $gets = 0;
        $puts = 0;
        Http::fake(function ($request) use ($service, &$gets, &$puts) {
            if ($request->method() === 'GET') {
                $gets++;

                return $gets === 1
                    ? Http::response(['error' => 'not_found'], 404)
                    : Http::response(['tenant' => $this->tenant($service)], 200);
            }
            $puts++;
            throw new ConnectionException('write completed but response lost');
        });
        $result = (new RcloneStorageProvisioner)->create($service);
        $this->assertTrue($result->ok, (string) $result->error);
        $this->assertSame(1, $puts);
    }

    public function test_large_plans_and_download_products_are_not_mapped(): void
    {
        $map = (array) config('provisioning.rclone_storage.plans');
        foreach (['sn_backup_4', 'sn_backup_5'] as $plan) {
            $this->assertArrayNotHasKey($plan, $map);
        }
        foreach (array_keys($map) as $plan) {
            $this->assertStringStartsNotWith('sn_download', $plan);
        }
    }

    public function test_suspend_does_not_delete_and_terminate_only_retires_with_retention(): void
    {
        $service = $this->service($this->server());
        Http::fake(['*' => Http::response(['tenant' => $this->tenant($service)], 200)]);
        $provisioner = new RcloneStorageProvisioner;
        $this->assertTrue($provisioner->suspend($service)->ok);
        $this->assertTrue($provisioner->terminate($service)->ok);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/suspend'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && ($request->data()['retention_days'] ?? null) === 30);
    }

    public function test_a_success_response_without_connection_data_is_not_delivered(): void
    {
        $service = $this->service($this->server());
        Http::fake(function ($request) use ($service) {
            return $request->method() === 'GET'
                ? Http::response(['error' => 'not_found'], 404)
                : Http::response(['tenant' => $this->tenant($service, ['endpoint' => ''])], 201);
        });
        $this->assertFalse((new RcloneStorageProvisioner)->create($service)->ok);
    }
}
