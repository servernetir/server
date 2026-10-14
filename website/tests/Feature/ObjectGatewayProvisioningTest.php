<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Services\Provisioning\ObjectGatewayProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ObjectGatewayProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_delivers_only_the_gateway_credentials_and_owned_bucket(): void
    {
        $customer = $this->customer('one');
        $server = Server::create([
            'name' => 'Object Gateway', 'type' => 'hetzner_object_gateway', 'status' => 'active',
            'hostname' => 'https://object-api.example.test', 'api_token' => str_repeat('c', 64),
        ]);
        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'Object 1TB', 'price' => 100000,
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'awaiting_provision',
            'server_id' => $server->id, 'plan' => 'sn_object_1tb_fsn1',
        ]);
        Http::fake(function ($request) use ($service) {
            if (str_ends_with($request->url(), '/v1/health')) {
                return Http::response(['ready' => true], 200);
            }
            if ($request->method() === 'GET') {
                return Http::response(['error' => 'not_found'], 404);
            }

            return Http::response([
                'tenant' => [
                    'id' => 'sn-svc-'.$service->id, 'region' => 'fsn1',
                    'bucket' => 'sn-fsn1-'.$service->id, 'access_key' => 'SNCLIENTACCESS',
                    'endpoint' => 'https://fsn1.s3.servernet.cloud', 'quota_bytes' => 1024 ** 4,
                    'used_bytes' => 0, 'status' => 'active',
                ],
                'credentials' => ['access_key' => 'SNCLIENTACCESS', 'secret_access_key' => 'client-secret'],
            ], 201);
        });

        $result = (new ObjectGatewayProvisioner)->create($service);

        $this->assertTrue($result->ok);
        $this->assertSame('SNCLIENTACCESS', $result->username);
        $this->assertSame('client-secret', $result->password);
        $this->assertSame('sn-fsn1-'.$service->id, $result->meta['bucket']);
        $this->assertArrayNotHasKey('hetzner_access_key', $result->meta);
    }

    public function test_interrupted_create_rotates_before_adopting_instead_of_using_local_random_password(): void
    {
        $customer = $this->customer('two');
        $server = Server::create([
            'name' => 'Object Gateway', 'type' => 'hetzner_object_gateway', 'status' => 'active',
            'hostname' => 'https://object-api.example.test', 'api_token' => str_repeat('d', 64),
        ]);
        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'Object 1TB', 'price' => 100000,
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'awaiting_provision',
            'server_id' => $server->id, 'plan' => 'sn_object_1tb_fsn1', 'password' => 'generic-local-password',
        ]);
        $tenant = [
            'id' => 'sn-svc-'.$service->id, 'region' => 'fsn1', 'bucket' => 'sn-fsn1-'.$service->id,
            'access_key' => 'SNCLIENTACCESS', 'endpoint' => 'https://fsn1.s3.servernet.cloud',
            'quota_bytes' => 1024 ** 4, 'used_bytes' => 0, 'status' => 'active',
        ];
        Http::fakeSequence()
            ->push(['ready' => true], 200)
            ->push(['tenant' => $tenant], 200)
            ->push(['tenant' => $tenant, 'credentials' => [
                'access_key' => 'SNCLIENTACCESS', 'secret_access_key' => 'rotated-secret',
            ]], 200);

        $result = (new ObjectGatewayProvisioner)->create($service);

        $this->assertTrue($result->ok);
        $this->assertSame('rotated-secret', $result->password);
        $this->assertNotSame('generic-local-password', $result->password);
    }

    private function customer(string $suffix): Customer
    {
        return Customer::create([
            'code' => 'SN-OBJ-'.strtoupper($suffix),
            'email' => 'object-'.$suffix.'@example.test',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);
    }
}
