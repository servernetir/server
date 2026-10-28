<?php

namespace Tests\Unit;

use App\Services\Growth\HermesGrowthPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class HermesGrowthPolicyTest extends TestCase
{
    private function ready(): array
    {
        return [
            'stage' => 'new', 'operation_claimed' => true, 'history_verified' => true,
            'recipient_verified' => true, 'contact_basis_verified' => true,
            'sender_verified' => true, 'optout_available' => true, 'suppression_checked' => true,
            'report_accessible' => true, 'inbox_current' => true,
            'receipt_adapter_verified' => true, 'campaign_segment_active' => true,
            'cooldown_clear' => true, 'organization_history_verified' => true,
            'suppressed' => false, 'bounced' => false, 'complaint' => false,
            'unknown_outcome' => false, 'emergency_pause' => false,
            'new_today' => 0, 'followups_today' => 0, 'followups_sent' => 0,
            'daily_ai_irt' => 333, 'monthly_ai_irt' => 1587,
        ];
    }

    public function test_missing_evidence_is_never_permission_to_send(): void
    {
        $result = (new HermesGrowthPolicy)->outreachDecision(new DateTimeImmutable('2026-10-28T08:00:00Z'), []);
        $this->assertFalse($result['eligible']);
        $this->assertContains('history_verified', $result['blockers']);
        $this->assertContains('unknown_outcome', $result['blockers']);
        $this->assertFalse($result['executes_action']);
    }

    public function test_month_boundary_and_stop_use_tehran_dates(): void
    {
        $policy = new HermesGrowthPolicy;
        $this->assertFalse($policy->context(new DateTimeImmutable('2026-10-25T19:00:00Z'))['active']);
        $this->assertSame(1, $policy->context(new DateTimeImmutable('2026-11-25T19:00:00Z'))['month']);
        $this->assertSame(2, $policy->context(new DateTimeImmutable('2026-11-25T21:00:00Z'))['month']);
        $this->assertSame(24, $policy->context(new DateTimeImmutable('2028-10-26T12:00:00Z'))['month']);
        $this->assertFalse($policy->context(new DateTimeImmutable('2028-10-26T21:00:00Z'))['active']);
    }

    public function test_daily_limits_are_separate_and_cannot_be_raised_by_input(): void
    {
        $policy = new HermesGrowthPolicy;
        $now = new DateTimeImmutable('2026-10-28T08:00:00Z');
        $this->assertTrue($policy->outreachDecision($now, $this->ready())['eligible']);
        $this->assertFalse($policy->outreachDecision($now, array_replace($this->ready(),
            ['new_today' => 5, 'new_contact_cap' => 999]))['eligible']);
        $this->assertFalse($policy->outreachDecision($now, array_replace($this->ready(),
            ['daily_ai_irt' => 50001]))['eligible']);
        $this->assertFalse($policy->outreachDecision($now, array_replace($this->ready(),
            ['monthly_ai_irt' => 1000001]))['eligible']);
    }

    public function test_unknown_result_and_false_like_strings_block_dispatch(): void
    {
        $policy = new HermesGrowthPolicy;
        $now = new DateTimeImmutable('2026-10-28T08:00:00Z');
        foreach (['unknown_outcome', 'suppressed', 'emergency_pause'] as $key) {
            $this->assertFalse($policy->outreachDecision($now,
                array_replace($this->ready(), [$key => 'false']))['eligible']);
        }
    }

    public function test_only_one_followup_after_seven_verified_business_days_is_eligible(): void
    {
        $policy = new HermesGrowthPolicy;
        $now = new DateTimeImmutable('2026-10-28T08:00:00Z');
        $evidence = array_replace($this->ready(), ['stage' => 'followup',
            'prior_send_receipt_verified' => true, 'no_reply_verified' => true,
            'elapsed_business_days' => 7]);
        $this->assertTrue($policy->outreachDecision($now, $evidence)['eligible']);
        foreach ([['elapsed_business_days' => 6], ['followups_sent' => 1],
            ['followups_today' => 2], ['no_reply_verified' => false]] as $change) {
            $this->assertFalse($policy->outreachDecision($now, array_replace($evidence, $change))['eligible']);
        }
    }

    public function test_decision_does_not_echo_private_evidence(): void
    {
        $result = (new HermesGrowthPolicy)->outreachDecision(new DateTimeImmutable('2026-10-28T08:00:00Z'),
            $this->ready() + ['email' => 'private@example.invalid', 'secret' => 'fixture-secret']);
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private@example.invalid', $json);
        $this->assertStringNotContainsString('fixture-secret', $json);
    }
}
