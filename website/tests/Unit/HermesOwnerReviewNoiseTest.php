<?php

namespace Tests\Unit;

use App\Services\Hermes\HermesSupportOwnerReview;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/infra/hermes/support-firewall-20261028/HermesSupportPolicy.php';
require_once dirname(__DIR__, 3).'/infra/hermes/bale-manager-20261031/HermesSupportOwnerReview.php';

class HermesOwnerReviewNoiseTest extends TestCase
{
    private function item(int $message = 10): array
    {
        return ['ticket_id' => 7, 'customer_id' => 4, 'customer_message_id' => $message,
            'reason' => 'credentials', 'status' => 'excluded'];
    }

    public function test_multipart_manual_review_notice_is_coalesced_for_the_same_ticket_and_reason(): void
    {
        $prior = $this->item(9) + ['owner_notice_at' => 1000, 'notice_owner_id' => '1', 'notice_chat_id' => '2'];
        $this->assertSame(2800, HermesSupportOwnerReview::manualNoticeRetryAt($this->item(), [$prior], '1', '2', 1200));
        $prior['status'] = 'stale';
        $this->assertSame(2800, HermesSupportOwnerReview::manualNoticeRetryAt($this->item(), [$prior], '1', '2', 1200));
        $this->assertNull(HermesSupportOwnerReview::manualNoticeRetryAt($this->item(), [$prior], '1', '2', 2800));
    }

    public function test_other_ticket_owner_reason_or_investigation_is_not_suppressed(): void
    {
        $prior = $this->item(9) + ['owner_notice_at' => 1000, 'notice_owner_id' => '1', 'notice_chat_id' => '2'];
        foreach (['ticket_id' => 8, 'customer_id' => 5, 'reason' => 'writer_unavailable', 'investigation_required' => true] as $field => $value) {
            $next = $this->item(); $next[$field] = $value;
            $this->assertNull(HermesSupportOwnerReview::manualNoticeRetryAt($next, [$prior], '1', '2', 1200));
        }
        $this->assertNull(HermesSupportOwnerReview::manualNoticeRetryAt($this->item(), [$prior], '3', '2', 1200));
        $this->assertNull(HermesSupportOwnerReview::manualNoticeRetryAt($this->item(), [$prior], '1', '3', 1200));
    }

    public function test_preview_scrubs_identity_and_access_data_even_for_generic_writer_failures(): void
    {
        $preview = HermesSupportOwnerReview::safeQuestion('کد ملی ۰۰۱۲۳۴۵۶۷۸ و شماره 0912-345-6789 نشانی https://example.test/secret');
        foreach (['۰۰۱۲۳۴۵۶۷۸', '0912-345-6789', 'example.test', 'secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $preview);
        }
        $preview = HermesSupportOwnerReview::safeQuestion('password=synthetic-password');
        $this->assertStringNotContainsString('synthetic-password', $preview);
        $this->assertStringContainsString('پنل', $preview);
    }
}
