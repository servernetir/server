<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\HetznerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HetznerAllocationInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putSecret('hetzner_api_token', 'test-token');
        Http::preventStrayRequests();
    }

    public function test_server_inspection_returns_only_allocation_metadata_using_get(): void
    {
        Http::fake(['https://api.hetzner.cloud/v1/servers/123' => Http::response([
            'server' => ['id' => 123, 'name' => 'staging', 'status' => 'running',
                'public_net' => ['ipv4' => ['id' => 456, 'ip' => '192.0.2.10'],
                    'ipv6' => ['id' => 789, 'ip' => '2001:db8::/64']],
                'labels' => ['ignored' => 'not-part-of-inspection']],
        ])]);

        $result = (new HetznerClient)->inspectServerAllocation('123');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['exists']);
        $this->assertSame(200, $result['http_status']);
        $this->assertSame([
            'server_ref' => '123', 'name' => 'staging', 'status' => 'running',
            'primary_ipv4_ref' => '456', 'primary_ipv6_ref' => '789',
            'ipv4' => '192.0.2.10', 'ipv6' => '2001:db8::/64',
        ], $result['allocation']);
        $this->assertArrayNotHasKey('raw', $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.hetzner.cloud/v1/servers/123');
    }

    public function test_server_without_addresses_has_explicit_null_allocations(): void
    {
        Http::fake(['*/servers/123' => Http::response(['server' => [
            'id' => 123, 'name' => 'staging', 'status' => 'off',
            'public_net' => ['ipv4' => null, 'ipv6' => null],
        ]])]);

        $result = (new HetznerClient)->inspectServerAllocation('123');

        $this->assertTrue($result['ok']);
        $this->assertNull($result['allocation']['primary_ipv4_ref']);
        $this->assertNull($result['allocation']['primary_ipv6_ref']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    public function test_primary_ip_inspection_preserves_assignment_and_auto_delete(): void
    {
        Http::fake(['*/primary_ips/123' => Http::response(['primary_ip' => [
            'id' => 123, 'ip' => '192.0.2.10', 'type' => 'ipv4',
            'assignee_type' => 'server', 'assignee_id' => 456, 'auto_delete' => false,
            'labels' => ['ignored' => 'not-part-of-inspection'],
        ]])]);

        $result = (new HetznerClient)->inspectPrimaryIp('123');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['exists']);
        $this->assertSame([
            'ref' => '123', 'ip' => '192.0.2.10', 'type' => 'ipv4',
            'assignee_type' => 'server', 'assignee_ref' => '456', 'auto_delete' => false,
        ], $result['primary_ip']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.hetzner.cloud/v1/primary_ips/123');
    }

    public static function primaryIpAssignments(): array
    {
        return [
            'current unassigned' => ['unassigned', null],
            'legacy unassigned server' => ['server', null],
            'assigned load balancer' => ['load_balancer', 456],
        ];
    }

    #[DataProvider('primaryIpAssignments')]
    public function test_primary_ip_assignment_types_are_preserved(string $type, ?int $id): void
    {
        Http::fake(['*/primary_ips/123' => Http::response(['primary_ip' => [
            'id' => '123', 'ip' => '2001:db8::/64', 'type' => 'ipv6',
            'assignee_type' => $type, 'assignee_id' => $id, 'auto_delete' => true,
        ]])]);

        $result = (new HetznerClient)->inspectPrimaryIp('123');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['exists']);
        $this->assertSame($type, $result['primary_ip']['assignee_type']);
        $this->assertSame($id === null ? null : (string) $id, $result['primary_ip']['assignee_ref']);
        $this->assertTrue($result['primary_ip']['auto_delete']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    public static function inspectionTargets(): array
    {
        return [
            'server' => ['inspectServerAllocation', '/servers/123', 'allocation'],
            'primary ip' => ['inspectPrimaryIp', '/primary_ips/123', 'primary_ip'],
        ];
    }

    #[DataProvider('inspectionTargets')]
    public function test_only_not_found_is_confirmed_absence(string $method, string $path, string $key): void
    {
        Http::fake(['https://api.hetzner.cloud/v1'.$path => Http::response([
            'error' => ['code' => 'not_found', 'message' => 'Resource not found'],
        ], 404)]);

        $result = (new HetznerClient)->{$method}('123');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['exists']);
        $this->assertSame(404, $result['http_status']);
        $this->assertNull($result[$key]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    public static function providerFailures(): array
    {
        $cases = [];
        foreach (self::inspectionTargets() as $target => [$method, $path, $key]) {
            foreach ([401, 403, 429, 500] as $status) {
                $cases[$target.' '.$status] = [$method, $path, $key, $status];
            }
        }
        return $cases;
    }

    #[DataProvider('providerFailures')]
    public function test_provider_errors_remain_unknown(string $method, string $path, string $key, int $status): void
    {
        Http::fake(['https://api.hetzner.cloud/v1'.$path => Http::response([
            'error' => ['code' => 'denied', 'message' => 'Synthetic provider error'],
        ], $status)]);

        $result = (new HetznerClient)->{$method}('123');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['exists']);
        $this->assertSame($status, $result['http_status']);
        $this->assertNull($result[$key]);
        $this->assertNotSame('', $result['message']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    #[DataProvider('inspectionTargets')]
    public function test_transport_failure_remains_unknown(string $method, string $path, string $key): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = [$request->method(), $request->url()];
            throw new ConnectionException('Synthetic connection failure');
        });

        $result = (new HetznerClient)->{$method}('123');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['exists']);
        $this->assertSame(0, $result['http_status']);
        $this->assertNull($result[$key]);
        $this->assertSame([['GET', 'https://api.hetzner.cloud/v1'.$path]], $requests);
    }

    public static function malformedResponses(): array
    {
        $server = ['id' => 123, 'name' => 'staging', 'status' => 'running',
            'public_net' => ['ipv4' => ['id' => 456, 'ip' => '192.0.2.10'],
                'ipv6' => null]];
        $primaryIp = ['id' => 123, 'ip' => '192.0.2.10', 'type' => 'ipv4',
            'assignee_type' => 'server', 'assignee_id' => 456, 'auto_delete' => false];
        $cases = [
            'missing server' => ['inspectServerAllocation', []],
            'server id only' => ['inspectServerAllocation', ['server' => ['id' => 123]]],
            'missing primary ip' => ['inspectPrimaryIp', []],
            'primary ip id only' => ['inspectPrimaryIp', ['primary_ip' => ['id' => 123]]],
        ];
        foreach (['id', 'name', 'status', 'public_net'] as $field) {
            $row = $server;
            unset($row[$field]);
            $cases['server missing '.$field] = ['inspectServerAllocation', ['server' => $row]];
        }
        foreach (['id' => [123], 'name' => ['staging'], 'status' => ['running'],
            'public_net' => 'invalid'] as $field => $value) {
            $cases['server array or scalar '.$field] = ['inspectServerAllocation',
                ['server' => array_replace($server, [$field => $value])]];
        }
        $cases['wrong server id'] = ['inspectServerAllocation',
            ['server' => array_replace($server, ['id' => 999])]];
        foreach ([
            'missing family' => ['ipv4' => null],
            'missing primary ip id' => ['ipv4' => ['ip' => '192.0.2.10'], 'ipv6' => null],
            'primary ip id only' => ['ipv4' => ['id' => 456], 'ipv6' => null],
            'array primary ip id' => ['ipv4' => ['id' => [456], 'ip' => '192.0.2.10'], 'ipv6' => null],
            'array address' => ['ipv4' => ['id' => 456, 'ip' => ['192.0.2.10']], 'ipv6' => null],
            'non-object primary ip' => ['ipv4' => '192.0.2.10', 'ipv6' => null],
        ] as $label => $publicNet) {
            $cases['server '.$label] = ['inspectServerAllocation',
                ['server' => array_replace($server, ['public_net' => $publicNet])]];
        }
        foreach (array_keys($primaryIp) as $field) {
            $row = $primaryIp;
            unset($row[$field]);
            $cases['primary ip missing '.$field] = ['inspectPrimaryIp', ['primary_ip' => $row]];
        }
        foreach (['id' => [123], 'ip' => ['192.0.2.10'], 'type' => ['ipv4'],
            'assignee_type' => ['server'], 'assignee_id' => [456],
            'auto_delete' => 'false'] as $field => $value) {
            $cases['primary ip invalid '.$field] = ['inspectPrimaryIp',
                ['primary_ip' => array_replace($primaryIp, [$field => $value])]];
        }
        $cases['wrong primary ip id'] = ['inspectPrimaryIp',
            ['primary_ip' => array_replace($primaryIp, ['id' => 999])]];

        $cases['unassigned primary ip with assignee id'] = ['inspectPrimaryIp',
            ['primary_ip' => array_replace($primaryIp, ['assignee_type' => 'unassigned'])]];
        $cases['load balancer primary ip without assignee id'] = ['inspectPrimaryIp',
            ['primary_ip' => array_replace($primaryIp, ['assignee_type' => 'load_balancer', 'assignee_id' => null])]];
        $cases['unknown primary ip assignee type'] = ['inspectPrimaryIp',
            ['primary_ip' => array_replace($primaryIp, ['assignee_type' => 'unexpected'])]];

        return $cases;
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_success_is_not_absence(string $method, array $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);

        $result = (new HetznerClient)->{$method}('123');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['exists']);
        $this->assertSame(200, $result['http_status']);
        $this->assertNull($result[$method === 'inspectServerAllocation' ? 'allocation' : 'primary_ip']);
        $this->assertNotSame('', $result['message']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
    }

    public static function invalidReferences(): array
    {
        $cases = [];
        foreach (array_keys(self::inspectionTargets()) as $target) {
            $method = self::inspectionTargets()[$target][0];
            foreach (['', '0', '-1', '1/other', '1?query=1', "123\n"] as $ref) {
                $cases[$target.' '.json_encode($ref)] = [$method, $ref];
            }
        }
        return $cases;
    }

    #[DataProvider('invalidReferences')]
    public function test_invalid_reference_never_makes_a_request(string $method, string $ref): void
    {
        Http::fake();

        $result = (new HetznerClient)->{$method}($ref);

        $this->assertFalse($result['ok']);
        $this->assertNull($result['exists']);
        $this->assertSame(0, $result['http_status']);
        Http::assertNothingSent();
    }
}
