<?php

namespace Tests\Unit;

use App\Services\Marketing\EmailCampaignTemplate;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailCampaignTemplateTest extends TestCase
{
    public function test_persian_name_and_unsubscribe_tokens_are_substituted_in_the_supplied_text(): void
    {
        $template = new EmailCampaignTemplate;
        $text = "دکتر {{نام و نام خانوادگی}} گرامی،\n\nلغو دریافت پیام‌های تبلیغاتی: {{لینک لغو عضویت}}";

        $rendered = $template->render($text, 'مریم رضایی', 'https://example.test/unsubscribe/one');

        $this->assertSame("دکتر مریم رضایی گرامی،\n\nلغو دریافت پیام‌های تبلیغاتی: https://example.test/unsubscribe/one", $rendered);
        $this->assertTrue($template->containsUnsubscribeToken($text));
        $this->assertSame(1, substr_count($rendered, 'https://example.test/unsubscribe/one'));
    }

    public function test_english_aliases_whitespace_and_each_recipients_link_work_independently(): void
    {
        $template = new EmailCampaignTemplate;
        $text = '{{ full_name }} — {{ unsubscribe_url }}';

        $this->assertSame('First Person — https://example.test/one', $template->render($text, 'First Person', 'https://example.test/one'));
        $this->assertSame('Second Person — https://example.test/two', $template->render($text, 'Second Person', 'https://example.test/two'));
    }

    public function test_name_controls_are_normalized_without_destroying_persian_half_spaces(): void
    {
        $template = new EmailCampaignTemplate;

        $this->assertSame('علی‌رضا احمدی', $template->render('{{full_name}}', "  علی‌رضا\r\n\0\tاحمدی  ", ''));
    }

    public function test_name_requirement_and_normalization_are_available_before_queueing(): void
    {
        $template = new EmailCampaignTemplate;

        $this->assertTrue($template->requiresName('{{نام و نام خانوادگی}}'));
        $this->assertTrue($template->requiresName('{{ full_name }}'));
        $this->assertFalse($template->requiresName('{{unsubscribe_url}}'));
        $this->assertFalse($template->requiresName('A static message.'));
        $this->assertSame('مریم رضایی', $template->normalizeName(" مریم\r\n رضایی "));
    }

    #[DataProvider('unsupportedTemplates')]
    public function test_unknown_or_malformed_placeholders_are_rejected(string $text): void
    {
        $this->expectException(DomainException::class);

        (new EmailCampaignTemplate)->validate($text);
    }

    public static function unsupportedTemplates(): array
    {
        return [
            'unknown' => ['{{email}}'],
            'executable expression' => ["{{ system('anything') }}"],
            'unclosed' => ['{{full_name'],
            'unopened' => ['full_name}}'],
            'nested' => ['{{{{full_name}}}}'],
            'empty' => ['{{ }}'],
        ];
    }

    #[DataProvider('missingNames')]
    public function test_a_name_placeholder_requires_a_real_usable_name(?string $name): void
    {
        $this->expectException(DomainException::class);

        (new EmailCampaignTemplate)->render('دکتر {{نام و نام خانوادگی}} گرامی', $name, 'https://example.test/one');
    }

    public static function missingNames(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'controls only' => [" \r\n\t\0 "],
            'too long' => [str_repeat('ن', 191)],
            'template-looking name' => ['{{unsubscribe_url}}'],
        ];
    }

    public function test_static_text_has_no_name_requirement_and_no_template_engine_is_invoked(): void
    {
        $template = new EmailCampaignTemplate;
        $text = '@php throw new RuntimeException("must not execute"); @endphp';

        $this->assertSame($text, $template->render($text, null, ''));
        $this->assertFalse($template->containsUnsubscribeToken($text));
        $this->assertSame('Literal {braces} stay.', $template->render('Literal {braces} stay.', null, ''));
    }

    public function test_an_unsubscribe_placeholder_requires_a_valid_web_url(): void
    {
        $this->expectException(DomainException::class);

        (new EmailCampaignTemplate)->render('{{unsubscribe_url}}', null, "https://example.test/one\nBcc: other@example.test");
    }
}
