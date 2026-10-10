<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\{CustomerMessage, PanelText};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HistoricalMessageTranslationTest extends TestCase
{
    use RefreshDatabase;

    public static function languages(): array { return [['en'], ['tr']]; }

    #[DataProvider('languages')]
    public function test_google_login_keeps_the_channel_and_optional_two_factor_detail(string $locale): void
    {
        foreach (['fa', 'en', 'tr'] as $source) {
            foreach ([false, true] as $twoFactor) {
                $channel = trans('google.channel', [], $source).($twoFactor ? trans('ui.act_login_app', [], $source) : '');
                $raw = trans('ui.act_login', ['channel' => $channel], $source);
                $expected = trans('ui.act_login', ['channel' => trans('google.channel', [], $locale)
                    .($twoFactor ? trans('ui.act_login_app', [], $locale) : '')], $locale);
                $this->assertSame($expected, CustomerMessage::activity($raw, 'login', $locale));
                $this->assertStringNotContainsString(trans('activity.detail', [], $locale), $expected);
            }
        }
    }

    #[DataProvider('languages')]
    public function test_nested_invoice_titles_keep_the_original_service_and_cycle(string $locale): void
    {
        $raw = 'سرور مجازی ایران - 10 هسته CPU و 32 گیگ RAM با آیپی 192.0.2.10 ( پشتیبانی پریمیوم ) (ماهانه)';
        $expected = trans('history.history_iran_cpu', ['cores' => 10, 'ram' => 32, 'ip' => '192.0.2.10'], $locale)
            .' ('.config('billing.cycles.monthly.label.'.$locale).')';
        $this->assertSame($expected, PanelText::text($raw, $locale));
        $this->assertSame($raw, PanelText::text($raw, 'fa'));
        $this->assertSame(trans('history.history_care', [], $locale).' ('.config('billing.cycles.monthly.label.'.$locale).')',
            PanelText::text('پشتیبانی چتر آبی (Care) (ماهانه)', $locale));
    }

    #[DataProvider('languages')]
    public function test_multiline_server_details_and_hourly_orders_retain_all_values(string $locale): void
    {
        $raw = "مشخصات: ۴ هسته · 8 GB رم · 80 GB NVME · ترافیک نامحدود\nمکان: 🇩🇪 آلمان — فالکن‌اشتاین\nسیستم‌عامل: Ubuntu 24.04\nنامِ سرور: web-01";
        $display = PanelText::text($raw, $locale);
        foreach (['4 vCPU', '8 GB RAM', '80 GB NVME', 'Falkenstein', 'Ubuntu 24.04', 'web-01'] as $value) {
            $this->assertStringContainsString($value, $display);
        }
        $hourly = CustomerMessage::activity('سفارشِ سرورِ ساعتی «web-01» — CV-2-4 · 🇩🇪 آلمان — فالکن‌اشتاین · ۲,۰۰۰ تومان/ساعت (پرداخت از کیفِ پول)', 'purchase', $locale);
        $this->assertStringContainsString($locale === 'en' ? '2,000 IRT/hour' : '2,000 IRT/saat', $hourly);
        $this->assertStringContainsString('CV-2-4', $hourly);
        $this->assertStringNotContainsString(trans('activity.detail', [], $locale), $display.$hourly);
    }

    #[DataProvider('languages')]
    public function test_staff_actions_resolve_latin_names_without_replacing_event_details(string $locale): void
    {
        User::factory()->create(['name' => 'مدیر آزمایشی', 'name_latin' => 'Example Administrator']);
        $raw = 'افزایشِ ۱,۵۵۵,۲۰۰ تومان اعتبار توسط «مدیر آزمایشی» — اصلاح برداشت اشتباه صورتحساب ساعتی سرویس #42 — بازگردانی تراکنش دفتر اعتبار #891 (18 ساعت در زمان خاموشی)';
        $display = CustomerMessage::activity($raw, 'payment', $locale);
        foreach (['1,555,200 IRT', 'Example Administrator', '#42', '#891', '18'] as $value) {
            $this->assertStringContainsString($value, $display);
        }
        $this->assertStringNotContainsString(trans('activity.detail', [], $locale), $display);
        $this->assertSame($raw, CustomerMessage::activity($raw, 'payment', 'fa'));
        $this->assertSame(trans('history.history_staff_login', ['staff' => 'Example Administrator'], $locale),
            CustomerMessage::activity('مدیر «مدیر آزمایشی» وارد پنلِ این مشتری شد', 'login', $locale));
    }

    #[DataProvider('languages')]
    public function test_delivery_failures_do_not_turn_into_success_or_generic_support_text(string $locale): void
    {
        $raw = 'تحویلِ سرورِ ابری ناموفق: تحویلِ سرور ناموفق: ساختِ ماشین از قالب انجام نشد.';
        $expected = trans('ui.act_prov_failed', ['reason' => trans('history.history_provider_failed',
            ['reason' => trans('history.history_provider_clone', [], $locale)], $locale)], $locale);
        $this->assertSame($expected, CustomerMessage::activity($raw, 'provision', $locale));
        $this->assertSame(trans('history.history_cancel_refund', ['amount' => '8,200 IRT'], $locale),
            CustomerMessage::activity('لغوِ سفارشِ تحویل‌نشده توسط مشتری — بازگشتِ ۸,۲۰۰ تومان به کیفِ پول', 'terminate', $locale));
    }

    public function test_history_catalogues_have_matching_keys_and_placeholders(): void
    {
        $fa = require lang_path('fa/history.php');
        foreach (['en', 'tr'] as $locale) {
            $foreign = require lang_path($locale.'/history.php');
            $this->assertSame(array_keys($fa), array_keys($foreign));
            foreach ($fa as $key => $text) {
                preg_match_all('/(?<![a-z0-9_]):[a-z_]+/i', $text, $source);
                preg_match_all('/(?<![a-z0-9_]):[a-z_]+/i', $foreign[$key], $target);
                sort($source[0]); sort($target[0]);
                $this->assertSame($source[0], $target[0], $key);
                $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $foreign[$key]);
            }
        }
    }
}
