<?php

namespace Tests\Unit;

use App\Services\Hermes\HermesSupportExperience;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/infra/hermes/support-firewall-20261028/HermesSupportPolicy.php';

class HermesSupportExperienceTest extends TestCase
{
    private function tickets(): array
    {
        return [['id' => 1, 'customer_id' => 10, 'subject' => 'UDP', 'department' => 'technical', 'status' => 'answered'],
            ['id' => 2, 'customer_id' => 20, 'subject' => 'UDP', 'department' => 'technical', 'status' => 'closed']];
    }

    private function messages(): array
    {
        return [['id' => 1, 'ticket_id' => 1, 'author_role' => 'staff', 'author_name' => 'Support', 'body' => 'پورت UDP مجاز است. private-customer-label', 'created_at' => '2026-01-01'],
            ['id' => 2, 'ticket_id' => 2, 'author_role' => 'staff', 'author_name' => 'Support', 'body' => 'بررسی شبکه انجام شد.', 'created_at' => '2026-01-02']];
    }

    public function test_staff_reply_and_staff_closed_status_are_not_confirmed_resolutions(): void
    {
        $i = HermesSupportExperience::compile($this->tickets(), $this->messages(), 10000);
        $this->assertSame('awaiting_customer_confirmation', $i['cases'][0]['outcome']);
        $this->assertSame('staff_closed_unconfirmed', $i['cases'][1]['outcome']);
        $this->assertSame(0, $i['confirmed_case_count']);
        $this->assertFalse($i['grants_permissions']);
        $this->assertStringNotContainsString('private-customer-label', json_encode($i));
    }

    public function test_later_customer_outcomes_change_the_learned_case(): void
    {
        $m = $this->messages(); $m[] = ['id' => 3, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'هنوز مشکل دارم', 'created_at' => '2026-01-03'];
        $i = HermesSupportExperience::compile($this->tickets(), $m, 10000);
        $this->assertSame('customer_reports_continuing', $i['cases'][0]['outcome']);
        $m[] = ['id' => 4, 'ticket_id' => 1, 'author_role' => 'staff', 'author_name' => 'Support', 'body' => 'قواعد UDP اصلاح شد.', 'created_at' => '2026-01-04'];
        $m[] = ['id' => 5, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'مشکل حل شد', 'created_at' => '2026-01-05'];
        $i = HermesSupportExperience::compile($this->tickets(), $m, 10001);
        $this->assertSame('customer_confirmed_report', $i['cases'][0]['outcome']);
        $this->assertSame(1, $i['confirmed_case_count']);
    }

    public function test_internal_hermes_drafts_and_sensitive_replies_are_not_learned_answers(): void
    {
        $m = $this->messages(); $m[0]['is_internal'] = true; $m[1]['author_name'] = 'Hermes';
        $this->assertSame([], HermesSupportExperience::compile($this->tickets(), $m, 10000)['cases']);
        $m = $this->messages(); $m[0]['body'] = 'password=synthetic-secret';
        $this->assertCount(1, HermesSupportExperience::compile($this->tickets(), $m, 10000)['cases']);
    }

    public function test_cross_customer_patterns_exclude_identifiers_and_prose_and_current_case_is_excluded(): void
    {
        $i = HermesSupportExperience::compile($this->tickets(), $this->messages(), 10000);
        $r = HermesSupportExperience::retrieve($i, 1, 10, 'UDP');
        $this->assertSame([], $r['same_customer_history']);
        $this->assertCount(1, $r['related_case_patterns']);
        $p = $r['related_case_patterns'][0];
        $this->assertArrayNotHasKey('ticket_id', $p); $this->assertArrayNotHasKey('customer_id', $p);
        $this->assertStringNotContainsString('private-customer-label', json_encode($r));
        $this->assertFalse($r['current_state_verified_by_history']);
        $this->assertSame('staff_closed_unconfirmed', $p['prior_outcome']);
    }

    public function test_sensitive_excerpt_is_omitted_and_contact_addresses_are_redacted(): void
    {
        $this->assertNull(HermesSupportExperience::excerpt('access_token=synthetic-only'));
        $r = HermesSupportExperience::excerpt('نشانی https://example.test/a و admin@example.test و 192.0.2.5 شماره 09123456789');
        foreach (['example.test', '192.0.2.5', '09123456789'] as $s) { $this->assertStringNotContainsString($s, $r); }
        $this->assertSame('[redacted]', HermesSupportExperience::excerpt('00000000-0000-4000-8000-000000000001'));
    }

    public function test_no_customer_confirmation_is_invented_from_a_negated_resolution(): void
    {
        $m = $this->messages(); $m[] = ['id' => 3, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'not resolved', 'created_at' => '2026-01-03'];
        $i = HermesSupportExperience::compile($this->tickets(), $m, 10000);
        $this->assertSame(0, $i['confirmed_case_count']);
    }

    public function test_bounded_scan_is_explicitly_partial(): void
    {
        $tickets = array_fill(0, 501, $this->tickets()[0]);
        $i = HermesSupportExperience::compile($tickets, [], 10000);
        $this->assertFalse($i['bounded_inventory_complete']);
        $this->assertSame(500, $i['source_ticket_count']);
    }

    public function test_closing_acknowledgement_does_not_hide_the_answer_customer_confirmed(): void
    {
        $m = $this->messages();
        $m[] = ['id' => 3, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'حل شد', 'created_at' => '2026-01-03'];
        $m[] = ['id' => 4, 'ticket_id' => 1, 'author_role' => 'staff', 'author_name' => 'Support', 'body' => 'خوشحالیم، موفق باشید.', 'created_at' => '2026-01-04'];
        $i = HermesSupportExperience::compile($this->tickets(), $m, 10000);
        $this->assertSame(1, $i['cases'][0]['reply_message_id']);
        $this->assertSame('customer_confirmed_report', $i['cases'][0]['outcome']);
    }

    public function test_mechanisms_are_learned_as_structured_staff_reports_without_copying_private_text(): void
    {
        $m = $this->messages(); $m[0]['body'] = 'موجودی مثبت است ولی ذخیره اعتبار قابل مصرف را محدود کرده است. private-person';
        $i = HermesSupportExperience::compile($this->tickets(), $m, 10000);
        $this->assertContains('total_reserved_available_credit_distinguished', $i['cases'][0]['staff_reported_tags']);
        $this->assertStringNotContainsString('private-person', json_encode($i));
    }

    public function test_customer_confirmed_cases_rank_above_newer_unconfirmed_cases_for_the_same_topic(): void
    {
        $m = $this->messages(); $m[] = ['id' => 3, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'حل شد', 'created_at' => '2026-01-03'];
        $i = HermesSupportExperience::compile($this->tickets(), $m, 10000);
        $r = HermesSupportExperience::retrieve($i, 99, 99, 'UDP');
        $this->assertSame('customer_confirmed_report', $r['related_case_patterns'][0]['prior_outcome']);
    }
}
