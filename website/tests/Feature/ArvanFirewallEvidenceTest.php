<?php

namespace Tests\Feature;

use App\Services\Cloud\ArvanClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArvanFirewallEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('settings.request-memo', [
            'arvan_api_token' => Crypt::encryptString('synthetic-firewall-test'),
            'arvan_security_group' => 'servernet',
        ]);
        Http::preventStrayRequests();
    }

    private function rules(): array
    {
        $rows = [];
        foreach (['ingress', 'egress'] as $direction) {
            foreach (['IPv4', 'IPv6'] as $family) {
                $rows[] = ['type' => $direction, 'version' => $family, 'protocol' => '',
                    'src_dest' => $family === 'IPv4' ? '0.0.0.0/0' : '::/0', 'port_start' => 0, 'port_end' => 0,
                    'private_extra_field' => 'must-not-project'];
            }
        }
        return $rows;
    }

    private function fakeEvidence(?array $rules = null, int $detailsStatus = 200, string $serverId = 'vm', ?array $groups = null): void
    {
        Http::fake([
            '*/v1/regions/region/servers/vm' => Http::response(['data' => ['id' => $serverId,
                'security_groups' => [['id' => 'attached']], 'password' => 'must-not-project']], 200),
            '*/v2/firewall/region/list' => Http::response(['data' => $groups ?? [
                ['id' => 'unattached', 'instances' => 0], ['id' => 'attached', 'instances' => 1],
            ]], 200),
            '*/v2/firewall/region/details/attached' => Http::response(['data' => $rules ?? $this->rules()], $detailsStatus),
        ]);
    }

    public function test_reads_current_attached_group_rules_without_secret_or_repair_claims(): void
    {
        $this->fakeEvidence();
        $r = app(ArvanClient::class)->inspectFirewall('region:vm');
        $this->assertSame('verified', $r['result']);
        $this->assertCount(4, $r['rules']);
        $this->assertSame('0.0.0.0/0', $r['rules'][0]['ip']);
        $this->assertSame(0, $r['rules'][0]['port_start']);
        $this->assertSame('TCP,UDP', $r['rules'][0]['protocol_display']);
        $this->assertSame('all', $r['rules'][0]['port_scope']);
        $this->assertTrue($r['all_tcp_udp_ports']['ingress']['IPv4']);
        $this->assertTrue($r['all_tcp_udp_ports']['ingress']['IPv6']);
        $this->assertTrue($r['all_tcp_udp_ports']['egress']['IPv4']);
        $this->assertFalse($r['packet_delivery_tested']);
        $this->assertFalse($r['guest_firewall_tested']);
        $this->assertFalse($r['repairs_performed']);
        $this->assertStringNotContainsString('must-not-project', json_encode($r));
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' || str_contains($r->url(), '/securities') || str_contains($r->url(), '/details/unattached'));
    }

    #[DataProvider('invalidRuleSets')]
    public function test_malformed_or_unbounded_details_are_unknown(array $rows): void
    {
        $this->fakeEvidence($rows);
        $this->assertSame('unavailable', app(ArvanClient::class)->inspectFirewall('region:vm')['result']);
    }

    public static function invalidRuleSets(): array
    {
        $row = ['type' => 'ingress', 'version' => 'IPv4', 'protocol' => 'udp',
            'src_dest' => '0.0.0.0/0', 'port_start' => 1, 'port_end' => 65535];
        $missing = $row;
        unset($missing['src_dest']);
        return [
            'missing field' => [[$missing]],
            'object shaped rows' => [['named' => $row]],
            'too many rules' => [array_fill(0, 65, $row)],
            'string port' => [[array_replace($row, ['port_start' => '1'])]],
            'reversed ports' => [[array_replace($row, ['port_start' => 100, 'port_end' => 10])]],
            'missing range endpoint' => [[array_replace($row, ['port_end' => null])]],
            'unknown direction' => [[array_replace($row, ['type' => 'unknown'])]],
            'unknown family' => [[array_replace($row, ['version' => 'unknown'])]],
            'oversize source' => [[array_replace($row, ['src_dest' => str_repeat('a', 65)])]],
        ];
    }

    public function test_unavailable_details_never_become_healthy(): void
    {
        $this->fakeEvidence(detailsStatus: 503);
        $r = app(ArvanClient::class)->inspectFirewall('region:vm');
        $this->assertSame('unavailable', $r['result']);
        $this->assertArrayNotHasKey('rules', $r);
    }

    public function test_restricted_source_does_not_become_global_allow_all(): void
    {
        $rules = $this->rules();
        $rules[0]['src_dest'] = '192.0.2.1/32';
        $this->fakeEvidence($rules);
        $r = app(ArvanClient::class)->inspectFirewall('region:vm');
        $this->assertSame('restricted', $r['rules'][0]['address_scope']);
        $this->assertFalse($r['all_tcp_udp_ports']['ingress']['IPv4']);
    }

    public function test_full_tcp_range_alone_does_not_imply_udp_is_open(): void
    {
        $this->fakeEvidence([['type' => 'ingress', 'version' => 'IPv4', 'protocol' => 'tcp',
            'src_dest' => '0.0.0.0/0', 'port_start' => 1, 'port_end' => 65535]]);
        $r = app(ArvanClient::class)->inspectFirewall('region:vm');
        $this->assertSame('all', $r['rules'][0]['port_scope']);
        $this->assertFalse($r['all_tcp_udp_ports']['ingress']['IPv4']);
    }

    public function test_provider_identity_mismatch_and_missing_group_are_not_verified(): void
    {
        $this->fakeEvidence(serverId: 'other');
        $this->assertSame('unavailable', app(ArvanClient::class)->inspectFirewall('region:vm')['result']);
        Http::assertSentCount(2);
    }

    public function test_unrelated_group_cannot_supply_the_rules(): void
    {
        $this->fakeEvidence(groups: [['id' => 'unattached', 'instances' => 1]]);
        $this->assertSame('unavailable', app(ArvanClient::class)->inspectFirewall('region:vm')['result']);
        Http::assertSentCount(2);
    }

    public function test_observed_shared_group_is_flagged(): void
    {
        $this->fakeEvidence(groups: [['id' => 'attached', 'instances' => 2]]);
        $this->assertTrue(app(ArvanClient::class)->inspectFirewall('region:vm')['other_attached_servers_observed']);
    }

    public function test_invalid_reference_does_not_call_provider(): void
    {
        $this->assertSame('unavailable', app(ArvanClient::class)->inspectFirewall('missing-region')['result']);
        Http::assertNothingSent();
    }

    public function test_new_order_discovery_prefers_current_panel_over_old_inventory(): void
    {
        Http::fake([
            '*/v2/firewall/region/list' => Http::response(['data' => [['id' => 'current-group', 'name' => 'servernet']]], 200),
            '*/v1/regions/region/securities' => Http::response(['data' => [['id' => 'old-group', 'name' => 'servernet']]], 200),
        ]);
        $method = new \ReflectionMethod(ArvanClient::class, 'securityGroupIds');
        $this->assertSame(['current-group'], $method->invoke(app(ArvanClient::class), 'region'));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/securities'));
    }

    public function test_legacy_discovery_still_works_when_current_panel_is_unavailable(): void
    {
        Cache::flush();
        Http::fake([
            '*/v2/firewall/region/list' => Http::response([], 503),
            '*/v1/regions/region/securities' => Http::response(['data' => [['id' => 'existing-group', 'name' => 'servernet']]], 200),
        ]);
        $method = new \ReflectionMethod(ArvanClient::class, 'securityGroupIds');
        $this->assertSame(['existing-group'], $method->invoke(app(ArvanClient::class), 'region'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }
}
