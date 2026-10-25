<?php

namespace Tests\Unit;

use App\Services\Finance\InvestmentAnalysis;
use Tests\TestCase;

class InvestmentAnalysisTest extends TestCase
{
    public function test_dated_return_matches_known_annual_irr_roi_and_npv(): void
    {
        $r = (new InvestmentAnalysis)->calculate([['date' => '2026-01-01', 'amount' => -100000], ['date' => '2027-01-01', 'amount' => 120000]], 0.1);
        $this->assertSame(20.0, $r['roi_percent']);
        $this->assertEqualsWithDelta(20.0, $r['irr_percent'], 0.00001);
        $this->assertEqualsWithDelta(9090.91, $r['npv'], 0.01);
    }

    public function test_nonconventional_cash_flows_do_not_claim_a_unique_irr(): void
    {
        $r = (new InvestmentAnalysis)->calculate([['date' => '2026-01-01', 'amount' => -100], ['date' => '2027-01-01', 'amount' => 230], ['date' => '2028-01-01', 'amount' => -132]], 0.1);
        $this->assertNull($r['irr_percent']);
    }

    public function test_no_investment_has_no_roi_or_irr_and_same_day_flows_have_no_irr(): void
    {
        $r = (new InvestmentAnalysis)->calculate([['date' => '2026-01-01', 'amount' => 100], ['date' => '2027-01-01', 'amount' => 120]], 0.0);
        $this->assertNull($r['roi_percent']);
        $this->assertNull($r['irr_percent']);
        $r = (new InvestmentAnalysis)->calculate([['date' => '2026-01-01', 'amount' => -100], ['date' => '2026-01-01', 'amount' => 120]], 0.0);
        $this->assertNull($r['irr_percent']);
        $this->assertSame(20.0, $r['npv']);
    }
}
