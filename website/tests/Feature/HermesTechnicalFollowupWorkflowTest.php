<?php

namespace Tests\Feature;

use App\Services\Hermes\HermesSupportPolicy;
use App\Services\Hermes\HermesSupportProcessor;
use App\Services\Hermes\HermesSupportState;
use App\Services\Hermes\HermesTechnicalFollowup;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/infra/hermes/technical-followups-20261028/HermesSupportState.php';
require_once dirname(__DIR__, 3).'/infra/hermes/technical-followups-20261028/HermesSupportProcessor.php';
require_once dirname(__DIR__, 3).'/infra/hermes/support-firewall-20261028/HermesSupportPolicy.php';

class HermesTechnicalFollowupWorkflowTest extends TestCase
{
    private function state(): HermesSupportState
    {
        // Synthetic fixtures remain under the test checkout; no production state or credentials.
        return new HermesSupportState(dirname(__DIR__, 3).'/output/technical-followup-tests/'.bin2hex(random_bytes(10)));
    }

    public function test_a_followup_is_internally_recorded_once_and_never_dispatched_to_customer(): void
    {
        $state = $this->state(); $drafts = 0; $notes = 0; $dispatches = 0;
        $ticket = ['id' => 10, 'customer_id' => 20, 'subject' => 'سایت', 'department' => 'technical'];
        $message = ['id' => 30, 'body' => 'هنوز مشکل دارم'];
        $fresh = fn () => ['status' => 'open', 'ticket_id' => 10, 'customer_id' => 20, 'message_id' => 30, 'author_role' => 'customer'];
        $draft = function () use (&$drafts, $state): string {
            $drafts++;
            $r = HermesTechnicalFollowup::assess('technical', 'سایت', [['role' => 'support', 'body' => 'دوباره آزمایش کنید'], ['role' => 'customer', 'body' => 'هنوز مشکل دارم']], []);
            $state->put('ticket:10:message:30', ['investigation_required' => true, 'analysis_ciphertext' => base64_encode($r['analysis'])]);
            return 'گزارش داخلی برای مدیر؛ پاسخ مشتری نیست.';
        };
        $note = function () use (&$notes): int { $notes++; return 100; };
        $dispatch = function () use (&$dispatches): int { $dispatches++; return 101; };
        $processor = new HermesSupportProcessor($state, new HermesSupportPolicy(), fn ($s) => base64_encode($s));
        $this->assertSame('review_required', $processor->process($ticket, $message, $fresh, $draft, $note, $dispatch, false, 'synthetic-followup'));
        $this->assertSame('review_required', $processor->process($ticket, $message, $fresh, $draft, $note, $dispatch, false, 'synthetic-duplicate'));
        $this->assertSame(1, $drafts); $this->assertSame(1, $notes); $this->assertSame(0, $dispatches);
        $this->assertTrue($state->item('ticket:10:message:30')['investigation_required']);
        // A fresh process/store view sees the same durable terminal claim.
        $this->assertSame('review_required', $state->read()['items']['ticket:10:message:30']['status']);
    }

    public function test_credentials_never_reach_diagnostics_draft_or_customer_delivery(): void
    {
        $state = $this->state(); $called = 0;
        $processor = new HermesSupportProcessor($state, new HermesSupportPolicy(), fn ($s) => base64_encode($s));
        $fresh = fn () => ['status' => 'open', 'ticket_id' => 10, 'customer_id' => 20, 'message_id' => 30, 'author_role' => 'customer'];
        $blocked = function () use (&$called): int { $called++; return 100; };
        $r = $processor->process(['id' => 10, 'customer_id' => 20, 'subject' => 'سایت', 'department' => 'technical'],
            ['id' => 30, 'body' => 'هنوز مشکل دارد password=synthetic-only'], $fresh, $blocked, $blocked, $blocked, false, 'synthetic-sensitive');
        $this->assertSame('excluded', $r); $this->assertSame(0, $called);
    }

    public function test_a_new_staff_reply_makes_the_customer_claim_stale(): void
    {
        $state = $this->state(); $called = 0;
        $processor = new HermesSupportProcessor($state, new HermesSupportPolicy(), fn ($s) => base64_encode($s));
        $fresh = fn () => ['status' => 'answered', 'ticket_id' => 10, 'customer_id' => 20, 'message_id' => 31, 'author_role' => 'staff'];
        $blocked = function () use (&$called): int { $called++; return 100; };
        $r = $processor->process(['id' => 10, 'customer_id' => 20, 'subject' => 'سایت', 'department' => 'technical'],
            ['id' => 30, 'body' => 'هنوز مشکل دارد'], $fresh, $blocked, $blocked, $blocked, false, 'synthetic-stale');
        $this->assertSame('stale', $r); $this->assertSame(0, $called);
    }
}
