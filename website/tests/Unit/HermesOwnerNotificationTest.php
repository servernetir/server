<?php

namespace Tests\Unit;

use App\Services\Hermes\HermesOwnerNotification as Notification;
use PHPUnit\Framework\TestCase;

class HermesOwnerNotificationTest extends TestCase
{
    private function context(): array
    {
        return ['ticket_id' => 11, 'ticket_number' => 'TK-261031-0011',
            'customer_name' => 'مشتری آزمایشی', 'customer_code' => 'SN-123456',
            'service_id' => 88, 'provider_label' => 'Hetzner Cloud', 'ipv4' => '192.0.2.10',
            'question' => 'سرورم وصل نمی‌شود؛ بررسی کنید.'];
    }

    public function test_compact_proposal_has_the_actual_customer_service_provider_and_question(): void
    {
        $text = Notification::proposal($this->context(), 'پاسخ پیشنهادی بررسی شد.', 'وضعیت سرویس از شواهد پرونده خوانده شد.');
        foreach (['TK-261031-0011', 'مشتری آزمایشی', 'SN-123456', '#88', 'Hetzner Cloud', '192.0.2.10', 'سرورم وصل نمی شود'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        $this->assertStringContainsString('هنوز برای مشتری ارسال نشده', $text);
        $this->assertStringContainsString('همان متن کامل', $text);
    }

    public function test_long_preview_is_bounded_but_does_not_replace_the_original_draft(): void
    {
        $context = array_map(fn ($value) => is_string($value) ? str_repeat($value, 100) : $value, $this->context());
        $draft = str_repeat('پاسخ کامل محفوظ ', 500);
        $before = $draft;
        $text = Notification::proposal($context, $draft, str_repeat('شاهد جاری ', 300));
        $this->assertLessThanOrEqual(1350, mb_strlen($text));
        $this->assertStringContainsString('…', $text);
        $this->assertSame($before, $draft);
        $this->assertStringContainsString('متن کامل', $text);
    }

    public function test_source_markup_control_characters_and_tokens_are_not_forwarded(): void
    {
        $text = Notification::proposal($this->context(), "<script>value</script> password=fixture-secret Bearer fixture-bearer https://example.test/?token=private-query\u{202E}", 'api_key=fixture-key');
        foreach (['<script>', 'fixture-secret', 'fixture-bearer', 'private-query', 'fixture-key', "\u{202E}"] as $value) {
            $this->assertStringNotContainsString($value, $text);
        }
    }

    public function test_investigation_has_no_approval_or_completed_repair_claim(): void
    {
        $text = Notification::investigation($this->context(), 'وضعیت مهمان قابل تأیید نیست؛ بررسی شبکه لازم است.');
        $this->assertStringContainsString('نیازمند بررسی فنی', $text);
        $this->assertStringContainsString('پاسخ آماده‌ای برای ارسال تأیید نشده', $text);
        $this->assertStringContainsString('اقدام بعدی', $text);
        $this->assertLessThanOrEqual(1100, mb_strlen($text));
    }

    public function test_unknown_evidence_stays_explicitly_unknown(): void
    {
        $text = Notification::proposal(['ticket_id' => 11], 'پاسخ', 'بررسی');
        $this->assertStringContainsString('سرویس: تعیین نشده', $text);
        $this->assertStringContainsString('زیرساخت: ثبت نشده', $text);
        $this->assertStringNotContainsString('IP:', $text);
    }

    public function test_error_codes_are_allowlisted_without_raw_provider_error_content(): void
    {
        $text = Notification::failure($this->context(), 'delivery_uncertain');
        $this->assertStringContainsString('ارسال دوباره مجاز نیست', $text);
        $unknown = Notification::failure($this->context(), 'raw-credentials-exception');
        $this->assertStringNotContainsString('raw-credentials-exception', $unknown);
        $this->assertStringContainsString('پاسخ دستی', $unknown);
    }
}
