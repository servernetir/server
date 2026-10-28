<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/infra/hermes/support-firewall-20261028/HermesSupportPlaybooks.php';

class HermesCloudNetworkPlaybookTest extends TestCase
{
    private function dossier(string $provider = 'arvan'): array
    {
        return ['service' => ['id' => 10, 'cloud' => ['provider' => $provider]]];
    }

    public function test_history_is_reference_only_and_does_not_prove_current_health(): void
    {
        $plans = \App\Services\Hermes\HermesSupportPlaybooks::build('UDP پورت ورودی', $this->dossier(), [], 10000);
        $this->assertCount(1, $plans);
        $p = $plans[0];
        $this->assertSame('cloud_network', $p['playbook']);
        $this->assertSame(['cloud_status', 'cloud_firewall'], $p['missing_checks']);
        $this->assertTrue($p['documented_network_policy']['reference_only']);
        $this->assertFalse($p['documented_network_policy']['current_state_verified_by_reference']);
        $this->assertFalse($p['documented_network_policy']['grants_execution_rights']);
        $this->assertSame('separate_policy_not_proven_unblocked', $p['documented_network_policy']['provider_smtp_25_465_restriction']);
        $this->assertFalse($p['repair_verified']);
        $this->assertSame('read_only_diagnostics_only', $p['authorized_action']);
    }

    public function test_only_fresh_exact_service_verified_checks_are_observed(): void
    {
        $checks = ['service_id' => 10, 'checks' => [
            ['check' => 'cloud_status', 'at' => 9900, 'result' => 'verified', 'source' => 'provider_api'],
            ['check' => 'cloud_firewall', 'at' => 9900, 'result' => 'verified', 'source' => 'provider_api'],
        ]];
        $p = \App\Services\Hermes\HermesSupportPlaybooks::build('firewall', $this->dossier(), $checks, 10000)[0];
        $this->assertCount(2, $p['observed']);
        $this->assertSame([], $p['missing_checks']);
        $checks['service_id'] = 11;
        $p = \App\Services\Hermes\HermesSupportPlaybooks::build('firewall', $this->dossier(), $checks, 10000)[0];
        $this->assertCount(0, $p['observed']);
        $checks['service_id'] = 10;
        $checks['checks'][0]['at'] = 1000;
        $checks['checks'][1]['result'] = 'unavailable';
        $p = \App\Services\Hermes\HermesSupportPlaybooks::build('firewall', $this->dossier(), $checks, 10000)[0];
        $this->assertSame(['cloud_status', 'cloud_firewall'], $p['missing_checks']);
    }

    public function test_policy_is_not_assigned_to_another_provider_or_unverified_service(): void
    {
        $p = \App\Services\Hermes\HermesSupportPlaybooks::build('TCP', $this->dossier('other'), [], 10000)[0];
        $this->assertArrayNotHasKey('documented_network_policy', $p);
        $p = \App\Services\Hermes\HermesSupportPlaybooks::build('پورت', ['service' => null], [], 10000)[0];
        $this->assertFalse($p['service_ownership_verified']);
        $this->assertArrayNotHasKey('documented_network_policy', $p);
    }

    public function test_unrelated_ticket_keeps_existing_playbook_behavior(): void
    {
        $this->assertSame([], \App\Services\Hermes\HermesSupportPlaybooks::build('سلام', [], [], 10000));
        $p = \App\Services\Hermes\HermesSupportPlaybooks::build('PHP', [], [], 10000)[0];
        $this->assertSame('php', $p['playbook']);
        $this->assertArrayNotHasKey('documented_network_policy', $p);
    }
}
