<?php

namespace Tests\Feature;

use App\Services\Sms\SmsDispatcher;
use App\Services\Sms\SmsSender;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رویدادی که اصلاً الگوی اپراتور ندارد، نباید ساعتی فریاد بزند.
 *
 * ═══ رخدادِ ۱۵ شهریور ۱۴۰۵ ═══
 *
 * `/admin/errors` پر بود از «پیامکِ رویداد «hourly_low_credit» نرفت» و
 * «hourly_credit_out» و «invoice_expired» و «announce» و
 * «undelivered_refund». هیچ‌کدام باگ نبودند: آن پنج رویداد اصلاً در فهرستِ
 * الگوهای اپراتور (`SignedRelaySender::TEMPLATES`) نیستند، پس نتیجه‌شان
 * **قطعی** است — این ساعت و هر ساعتِ دیگر هم نمی‌روند.
 *
 * 🔴 `cloud:meter` ساعتی می‌دود. با ثبتِ بی‌گلوگاه، یک رویدادِ ثبت‌نشده روزی
 * ۲۴ ردیف می‌گذاشت و پنجرهٔ ۴۰۰ خطیِ ردیاب را می‌بلعید — همان «سیلِ ۴۰۴» که
 * یک بار خطاهای واقعی را از فهرست بیرون انداخت.
 *
 * ⚠️ و این خاموش‌کردن نیست: ایمیلِ همان رویداد جداگانه می‌رود، پس مشتری
 * بی‌خبر نمی‌مانَد. تنها چیزی که نمی‌رود پیامک است — و رفعِ **واقعی**اش
 * ساختنِ الگو در پنلِ اپراتور است، نه کدِ ما.
 */
class SmsMissingPatternIsNotHourlyNoiseTest extends TestCase
{
    use RefreshDatabase;

    private function dispatcherWith(object $sender): SmsDispatcher
    {
        return new SmsDispatcher($sender);
    }

    private function rowsMentioning(string $needle): int
    {
        $n = 0;

        foreach (ErrorTracker::recent(400, 'note') as $e) {
            if (str_contains((string) ($e['message'] ?? ''), $needle)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * 🔴 ادعای اصلی: رویدادِ بدونِ الگو فقط **یک بار** در روز ثبت می‌شود.
     *
     * ده اجرا شبیه‌سازیِ ده ساعتِ کرون است؛ بدونِ گلوگاه ده ردیف می‌شد.
     */
    public function test_an_event_with_no_operator_pattern_is_recorded_once(): void
    {
        ErrorTracker::clear();

        $sender = new class implements SmsSender
        {
            public function enabled(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'n8n-relay';
            }

            public function send(string $mobile, string $text): bool
            {
                return false;   // رلهٔ n8n عمداً متنِ آزاد را نمی‌پذیرد
            }

            public function sendOtp(string $mobile, string $code): bool
            {
                return true;
            }
        };

        $dispatcher = $this->dispatcherWith($sender);

        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($dispatcher->event('09121234567', 'hourly_low_credit', ['x' => 1], 'متن'));
        }

        $this->assertSame(1, $this->rowsMentioning('hourly_low_credit'),
            'رویدادِ بدونِ الگو باید یک ردیف بگذارد، نه یکی به‌ازای هر اجرا.');
    }

    /**
     * ⚠️ ادعای منفی: پیام باید بگوید علت **نبودِ الگو در اپراتور** است.
     *
     * بدونِ این جمله، مدیر دنبالِ خرابیِ رله می‌گردد در حالی که کارِ لازم یک
     * الگوی تازه در پنلِ اپراتور است.
     */
    public function test_the_note_says_the_operator_has_no_such_template(): void
    {
        ErrorTracker::clear();

        $sender = new class implements SmsSender
        {
            public function enabled(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'n8n-relay';
            }

            public function send(string $mobile, string $text): bool
            {
                return false;
            }

            public function sendOtp(string $mobile, string $code): bool
            {
                return true;
            }
        };

        $this->dispatcherWith($sender)->event('09121234567', 'announce', ['x' => 1], 'متن');

        $found = false;

        foreach (ErrorTracker::recent(400, 'note') as $e) {
            if (str_contains((string) ($e['message'] ?? ''), 'در فهرستِ الگوهای اپراتور نیست')) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'پیام باید علت را بگوید، نه فقط شکست را.');
    }
}
