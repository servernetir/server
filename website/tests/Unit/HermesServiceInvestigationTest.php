<?php

namespace Tests\Unit;

use App\Services\Hermes\HermesServiceInvestigation;
use PHPUnit\Framework\TestCase;

class HermesServiceInvestigationTest extends TestCase
{
    private function service(): array
    {
        return ['status' => 'suspended', 'billing_mode' => 'hourly', 'hourly_rate_irt' => 4000,
            'hold_reserve_irt' => 47000, 'provision_meta' => []];
    }

    public function test_positive_total_and_insufficient_available_credit_are_distinguished(): void
    {
        $r = HermesServiceInvestigation::project($this->service(), ['unit' => 'IRT', 'balance' => 50000, 'reserved' => 47000, 'available' => 3000],
            [['actor' => 'system', 'description' => 'اتمام اعتبار ساعتی — سرور خاموش شد', 'at' => 10000]]);
        $this->assertSame('hourly_credit_out_recorded', $r['cause_code']);
        $this->assertSame(10000, $r['cause_recorded_at']);
        $this->assertFalse($r['total_wallet_is_zero']);
        $this->assertTrue($r['retention_reserve_limits_available_credit']);
        $this->assertSame(3000, $r['wallet']['available_irt']);
        $this->assertFalse($r['financial_action_authorized']);
        $this->assertFalse($r['power_action_authorized']);
        $this->assertSame('not_part_of_recorded_credit_gate', $r['supplier_report_status']);
    }

    public function test_current_insufficiency_alone_does_not_invent_a_historical_cause(): void
    {
        $r = HermesServiceInvestigation::project($this->service(), ['unit' => 'IRT', 'balance' => 50000, 'reserved' => 47000, 'available' => 3000], []);
        $this->assertSame('not_established', $r['cause_code']);
        $this->assertTrue($r['current_wallet_explains_credit_gate']);
    }

    public function test_manual_billing_hold_takes_precedence_over_an_old_credit_out_event(): void
    {
        $s = $this->service(); $s['provision_meta']['hourly_billing_hold'] = ['active' => true, 'reason' => 'provider VM unreachable private-do-not-copy'];
        $r = HermesServiceInvestigation::project($s, null, [['actor' => 'system', 'description' => 'اتمام اعتبار ساعتی', 'at' => 10000]]);
        $this->assertSame('operator_billing_hold_recorded', $r['cause_code']);
        $this->assertStringNotContainsString('private-do-not-copy', json_encode($r));
        $this->assertSame('recorded_provider_incident_pending_verified_recovery', $r['hold_reason_category']);
        $this->assertSame('not_checked', $r['supplier_report_status']);
    }

    public function test_customer_claim_or_active_service_does_not_prove_current_suspension_cause(): void
    {
        $r = HermesServiceInvestigation::project($this->service(), null, [['actor' => 'customer', 'description' => 'اتمام اعتبار ساعتی', 'at' => 10000]]);
        $this->assertSame('not_established', $r['cause_code']);
        $s = $this->service(); $s['status'] = 'active';
        $r = HermesServiceInvestigation::project($s, null, [['actor' => 'system', 'description' => 'اتمام اعتبار ساعتی', 'at' => 10000]]);
        $this->assertSame('not_established', $r['cause_code']);
    }

    public function test_unknown_units_and_malformed_wallets_are_not_monetary_findings(): void
    {
        $r = HermesServiceInvestigation::project($this->service(), ['unit' => 'IRR', 'balance' => 100, 'reserved' => 0, 'available' => 100], []);
        $this->assertNull($r['wallet']);
        $r = HermesServiceInvestigation::project($this->service(), ['unit' => 'IRT', 'balance' => '100', 'reserved' => 0, 'available' => 100], []);
        $this->assertNull($r['wallet']);
    }
}
