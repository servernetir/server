<?php

namespace Tests\Unit;

use App\Services\Hermes\HermesSupportEvidenceGuard;
use PHPUnit\Framework\TestCase;

class HermesSupportEvidenceGuardTest extends TestCase
{
    private function evidence(): array
    {
        return ['dossier' => ['service' => ['id' => 1]], 'service_investigation' => ['service_id' => 1,
            'source' => 'servernet_database_and_wallet', 'checked_at' => 10000, 'result' => 'verified',
            'wallet' => ['unit' => 'IRT', 'ledger_balance_irt' => 50000]]];
    }

    public function test_rejects_total_zero_claim_against_fresh_positive_balance(): void
    {
        $this->assertFalse(HermesSupportEvidenceGuard::accepts(['analysis' => 'علت بررسی شد', 'reply' => 'موجودی شما صفر است.'], $this->evidence(), 10000));
        $this->assertFalse(HermesSupportEvidenceGuard::accepts(['analysis' => 'total balance is zero', 'reply' => 'Pending'], $this->evidence(), 10000));
    }

    public function test_preserves_the_total_versus_available_distinction(): void
    {
        $this->assertTrue(HermesSupportEvidenceGuard::accepts(['analysis' => 'موجودی شما صفر نیست؛ ذخیره وجود دارد.',
            'reply' => 'اعتبار قابل مصرف برای ساعت بعد کافی نیست.'], $this->evidence(), 10000));
    }

    public function test_another_service_or_stale_evidence_is_not_used_as_a_contradiction(): void
    {
        $e = $this->evidence(); $e['service_investigation']['service_id'] = 2;
        $this->assertTrue(HermesSupportEvidenceGuard::accepts(['reply' => 'موجودی شما صفر است.'], $e, 10000));
        $e = $this->evidence(); $e['service_investigation']['checked_at'] = 1000;
        $this->assertTrue(HermesSupportEvidenceGuard::accepts(['reply' => 'موجودی شما صفر است.'], $e, 10000));
    }
}
