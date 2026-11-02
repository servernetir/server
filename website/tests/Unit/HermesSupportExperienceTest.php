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
        // All-history keeps both exchanges. The failed earlier answer must
        // remain failed; only the later answer has customer confirmation.
        $byReply = array_column($i['cases'], null, 'reply_message_id');
        $this->assertArrayHasKey(1, $byReply);
        $this->assertArrayHasKey(4, $byReply);
        $this->assertSame('customer_reports_continuing', $byReply[1]['outcome']);
        $this->assertSame('customer_confirmed_report', $byReply[4]['outcome']);
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

    public function test_full_history_covers_all_departments_and_every_human_exchange(): void
    {
        $tickets = [['id' => 1, 'customer_id' => 10, 'subject' => 'فاکتور پرداخت', 'department' => 'billing', 'status' => 'open'],
            ['id' => 2, 'customer_id' => 20, 'subject' => 'قیمت ماهانه', 'department' => 'sales', 'status' => 'answered']];
        $messages = [
            ['id' => 1, 'ticket_id' => 1, 'author_role' => 'staff', 'body' => 'پرداخت ثبت شده است.', 'created_at' => '2026-01-01'],
            ['id' => 2, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'هنوز فاکتور نیامده', 'created_at' => '2026-01-02'],
            ['id' => 3, 'ticket_id' => 1, 'author_role' => 'staff', 'body' => 'فاکتور آماده است.', 'created_at' => '2026-01-03'],
            ['id' => 4, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'مشکل حل شد', 'created_at' => '2026-01-04'],
            ['id' => 5, 'ticket_id' => 2, 'author_role' => 'staff', 'body' => 'قیمت تاریخی ماهانه private-price-label', 'created_at' => '2026-01-05'],
        ];
        $index = HermesSupportExperience::compile($tickets, $messages, 10000, true);
        $this->assertSame('all_departments_public_history', $index['source_scope']);
        $this->assertCount(3, $index['cases']);
        $this->assertSame([1, 3, 5], array_column($index['cases'], 'reply_message_id'));
        $this->assertSame('customer_reports_continuing', $index['cases'][0]['outcome']);
        $this->assertSame('customer_confirmed_report', $index['cases'][1]['outcome']);
        $this->assertSame('billing', $index['cases'][0]['department']);
        $this->assertStringNotContainsString('private-price-label', json_encode($index));
    }

    public function test_full_history_is_not_truncated_at_the_legacy_ticket_limit(): void
    {
        $tickets = [];
        for ($id = 1; $id <= 601; $id++) {
            $tickets[] = ['id' => $id, 'customer_id' => 10, 'subject' => 'sales', 'department' => 'sales', 'status' => 'open'];
        }
        $index = HermesSupportExperience::compile($tickets, [], 10000, true);
        $this->assertTrue($index['bounded_inventory_complete']);
        $this->assertSame(601, $index['source_ticket_count']);
    }

    public function test_sarcasm_and_staff_claims_are_not_confirmation_and_own_history_is_deduplicated(): void
    {
        $tickets = $this->tickets();
        $messages = $this->messages();
        $messages[] = ['id' => 3, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'عاشق این نگارش های ai هستم 🤩', 'created_at' => '2026-01-03'];
        $messages[] = ['id' => 4, 'ticket_id' => 1, 'author_role' => 'staff', 'body' => 'مشکل حل شد', 'created_at' => '2026-01-04'];
        $index = HermesSupportExperience::compile($tickets, $messages, 10000, true);
        $this->assertSame(0, $index['confirmed_case_count']);
        $history = HermesSupportExperience::retrieve($index, 99, 10, 'UDP')['same_customer_history'];
        $this->assertCount(1, $history);
        $this->assertSame(4, $history[0]['reply_message_id']);
    }

    public function test_conditional_questions_and_quoted_staff_claims_are_not_customer_confirmation(): void
    {
        foreach (['اگر حل شد خبر می دهم', 'آیا مشکل حل شد؟', 'you said it was fixed?', 'I hope it is fixed', 'حل شد ولی هنوز قطع می شود'] as $text) {
            $messages = $this->messages();
            $messages[] = ['id' => 3, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => $text, 'created_at' => '2026-01-03'];
            $index = HermesSupportExperience::compile($this->tickets(), $messages, 10000, true);
            $this->assertSame(0, $index['confirmed_case_count'], $text);
        }
    }

    public function test_same_customer_history_keeps_confirmed_substantive_answer_over_closing_acknowledgement(): void
    {
        $messages = $this->messages();
        $messages[0]['body'] = 'نشانی listener اصلاح شد؛ UDP را دوباره آزمایش کنید. own-resolution-marker';
        $messages[] = ['id' => 3, 'ticket_id' => 1, 'author_role' => 'customer',
            'body' => 'working now', 'created_at' => '2026-01-03'];
        $messages[] = ['id' => 4, 'ticket_id' => 1, 'author_role' => 'staff',
            'author_name' => 'Support', 'body' => 'You are welcome.', 'created_at' => '2026-01-04'];
        $tickets = $this->tickets(); $tickets[0]['status'] = 'closed';
        $index = HermesSupportExperience::compile($tickets, $messages, 10000, true);
        $byReply = array_column($index['cases'], null, 'reply_message_id');
        $this->assertTrue($byReply[4]['closing_acknowledgement']);
        $this->assertFalse($byReply[1]['closing_acknowledgement']);
        $history = HermesSupportExperience::retrieve($index, 99, 10, 'UDP')['same_customer_history'];
        $this->assertCount(1, $history);
        $this->assertSame(1, $history[0]['reply_message_id']);
        $this->assertSame('customer_confirmed_report', $history[0]['outcome']);
        $crossCustomer = HermesSupportExperience::retrieve($index, 99, 30, 'UDP');
        $this->assertSame([], $crossCustomer['same_customer_history']);
        $this->assertStringNotContainsString('own-resolution-marker', json_encode($crossCustomer));
        $this->assertStringNotContainsString('private-customer-label', json_encode($index));
    }

    public function test_reopened_case_keeps_newer_substantive_investigation_over_older_confirmed_fix(): void
    {
        $messages = $this->messages();
        $messages[] = ['id' => 3, 'ticket_id' => 1, 'author_role' => 'customer',
            'body' => 'working now', 'created_at' => '2026-01-03'];
        $messages[] = ['id' => 4, 'ticket_id' => 1, 'author_role' => 'staff',
            'body' => 'خوشحالیم، موفق باشید.', 'created_at' => '2026-01-04'];
        $messages[] = ['id' => 5, 'ticket_id' => 1, 'author_role' => 'customer',
            'body' => 'هنوز دوباره قطع می شود', 'created_at' => '2026-01-05'];
        $messages[] = ['id' => 6, 'ticket_id' => 1, 'author_role' => 'staff',
            'body' => 'بررسی مسیر UDP هنوز کامل نیست؛ نتیجه تست تازه لازم است.', 'created_at' => '2026-01-06'];
        $index = HermesSupportExperience::compile($this->tickets(), $messages, 10000, true);
        $history = HermesSupportExperience::retrieve($index, 99, 10, 'UDP')['same_customer_history'];
        $this->assertSame(6, $history[0]['reply_message_id']);
        $this->assertSame('awaiting_customer_confirmation', $history[0]['outcome']);
        $this->assertFalse($history[0]['closing_acknowledgement']);
        $byReply = array_column($index['cases'], null, 'reply_message_id');
        $this->assertTrue($byReply[4]['closing_acknowledgement']);
        $this->assertSame('customer_confirmed_report', $byReply[1]['outcome']);
    }

    public function test_legacy_history_without_acknowledgement_metadata_still_prefers_the_latest_reply(): void
    {
        $this->assertTrue(HermesSupportExperience::preferHistoryCase(['reply_message_id' => 4], ['reply_message_id' => 1]));
        $this->assertFalse(HermesSupportExperience::preferHistoryCase(['reply_message_id' => 1], ['reply_message_id' => 4]));
        $this->assertFalse(HermesSupportExperience::preferHistoryCase(
            ['reply_message_id' => 4, 'closing_acknowledgement' => true],
            ['reply_message_id' => 1, 'closing_acknowledgement' => false]));
    }

    public function test_billing_sales_identity_lessons_are_reached_in_all_three_languages(): void
    {
        $topics = [
            'billing_and_documents' => ['فاکتور پرداخت', 'invoice payment billing', 'fatura ödeme muhasebe'],
            'sales_and_selection' => ['قیمت ماهانه فروش', 'monthly price sales', 'aylık fiyat satış'],
            'account_and_identity' => ['احراز هویت ثبت‌نام', 'identity verification account', 'kimlik doğrulama hesap'],
        ];
        foreach ($topics as $topic => $queries) {
            foreach ($queries as $query) {
                $ticket = ['id' => 1, 'customer_id' => 10, 'subject' => $query, 'department' => 'general', 'status' => 'answered'];
                $messages = [['id' => 1, 'ticket_id' => 1, 'author_role' => 'staff', 'author_name' => 'Support',
                    'body' => 'private-historical-price 999999', 'created_at' => '2026-01-01']];
                $index = HermesSupportExperience::compile([$ticket], $messages, 10000, true);
                $context = HermesSupportExperience::retrieve($index, 99, 99, $query);
                $patterns = array_column($context['related_case_patterns'], null, 'topic');
                $this->assertArrayHasKey($topic, $patterns, $query);
                $this->assertNotSame('', $patterns[$topic]['lesson']);
                $this->assertTrue($patterns[$topic]['reference_only']);
                $this->assertStringNotContainsString('private-historical-price', json_encode($context));
                $this->assertStringNotContainsString('999999', json_encode($context));
            }
        }
    }

    public function test_related_patterns_never_replace_substantive_fix_with_later_confirmed_courtesy(): void
    {
        $messages = $this->messages();
        $messages[] = ['id' => 3, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'working now', 'created_at' => '2026-01-03'];
        $messages[] = ['id' => 4, 'ticket_id' => 1, 'author_role' => 'staff', 'author_name' => 'Support', 'body' => 'You are welcome', 'created_at' => '2026-01-04'];
        $messages[] = ['id' => 5, 'ticket_id' => 1, 'author_role' => 'customer', 'body' => 'working now', 'created_at' => '2026-01-05'];
        $index = HermesSupportExperience::compile($this->tickets(), $messages, 10000, true);
        $context = HermesSupportExperience::retrieve($index, 99, 99, 'UDP');
        $this->assertSame('2026-01-01', $context['related_case_patterns'][0]['source_reply_at']);
        $this->assertSame('customer_confirmed_report', $context['related_case_patterns'][0]['prior_outcome']);
        $this->assertContains('staff_reported_infrastructure_permission', $context['related_case_patterns'][0]['staff_reported_tags']);
        $this->assertSame(2, $index['confirmed_case_count']);
    }
}
