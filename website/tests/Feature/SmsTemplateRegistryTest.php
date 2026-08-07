<?php

namespace Tests\Feature;

use App\Services\Notify\NotifyEvent;
use App\Services\Sms\SignedRelaySender;
use Tests\TestCase;

/**
 * سه فهرستِ الگو که اگر از هم جدا شوند، پیامک **بی‌صدا** نمی‌رود.
 *
 * ═══ خرابیِ واقعی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * `SignedRelaySender::TEMPLATES` یک فهرستِ کهنهٔ هفت‌تایی بود (از پیکربندیِ
 * قدیمیِ آی‌پی‌پنل) و رجیستریِ n8n چهارده الگو داشت. هم‌پوشانی‌شان **سه** تا بود.
 * نتیجه: از ۲۴ رویدادِ وصل‌شده، فقط سه تا پیامکشان می‌رفت — و هیچ‌کدام از دو
 * نیمهٔ خرابی خطایی تولید نمی‌کرد:
 *
 *   نام در PHP نبود، الگو بود  → sendPattern نال → متنِ آزاد → send() = false
 *   نام در PHP بود، الگو نبود  → n8n: unknown_template
 *
 * کارفرما یک پرداختِ آزمایشی کرد و هیچ پیامکی نگرفت. تا آن لحظه هیچ‌چیز در
 * سایت، لاگ، یا ردیابِ خطا نگفته بود که ۲۱ رویداد خاموش‌اند.
 *
 * ═══ چرا تست فایلِ جاوااسکریپت را می‌خوانَد ═══
 *
 * رجیستریِ الگو در گرهٔ n8n زندگی می‌کند و PHP نمی‌تواند در زمانِ اجرا ببیندش.
 * ولی نسخهٔ مرجعش در همین مخزن است، پس تست می‌تواند — و باید — واقعاً بازش
 * کند. مقایسه با یک کپیِ دستی در خودِ تست، همان کهنگی را از درِ دیگر
 * برمی‌گرداند.
 */
class SmsTemplateRegistryTest extends TestCase
{
    /** مسیرِ نسخهٔ مرجعِ گرهٔ n8n */
    private function n8nSource(): string
    {
        $path = base_path('../relay/n8n/verify-and-map-template.js');

        $this->assertFileExists($path,
            'نسخهٔ مرجعِ گرهٔ n8n پیدا نشد — بی‌آن، هیچ چیزی این دو فهرست را هم‌راستا نگه نمی‌دارد');

        return (string) file_get_contents($path);
    }

    /**
     * رجیستریِ n8n: نامِ منطقی ⇒ کلیدهایی که پروژه باید بفرستد.
     *
     * @return array<string,array<int,string>>
     */
    private function n8nRegistry(): array
    {
        $src = $this->n8nSource();

        $this->assertSame(1, preg_match('/const TEMPLATES = \{(.*?)\n\};/s', $src, $m),
            'بلوکِ TEMPLATES در فایلِ n8n پیدا نشد — شکلِ فایل عوض شده و این تست دیگر چیزی نمی‌سنجد');

        preg_match_all("/^\s*(\w+):\s*\{\s*code:\s*'([^']+)',\s*vars:\s*\{([^}]*)\}/m", $m[1], $rows, PREG_SET_ORDER);

        $out = [];

        foreach ($rows as $r) {
            // سمتِ چپ نامِ متغیرِ الگوی اپراتور است، سمتِ راست کلیدی که پروژه می‌فرستد
            preg_match_all("/(\w+)\s*:\s*'(\w+)'/", $r[3], $vars, PREG_SET_ORDER);
            $out[$r[1]] = array_column($vars, 2);
        }

        $this->assertNotEmpty($out, 'رجیستریِ n8n خالی خوانده شد');

        return $out;
    }

    // ═══════════════ 🔴 قلبِ فایل ═══════════════

    public function test_the_php_allow_list_matches_the_n8n_registry_exactly(): void
    {
        $php = SignedRelaySender::TEMPLATES;
        $n8n = array_keys($this->n8nRegistry());

        sort($php);
        sort($n8n);

        $this->assertSame($n8n, $php,
            "\nفهرستِ الگوها از هم جدا شده‌اند:\n"
            .'  فقط در PHP : '.(implode(', ', array_diff($php, $n8n)) ?: '—')."\n"
            .'  فقط در n8n : '.(implode(', ', array_diff($n8n, $php)) ?: '—')."\n\n"
            ."نامِ فقط-در-PHP یعنی n8n می‌گوید unknown_template.\n"
            ."نامِ فقط-در-n8n یعنی sendPattern نال می‌دهد و پیامک اصلاً نمی‌رود.\n"
            .'هر دو حالت **بی‌صدا**ند.');
    }

    /**
     * هر متغیری که الگو لازم دارد، باید در `vars` همان رویداد باشد.
     *
     * ⚠️ اگر نباشد، n8n `missing_param` می‌دهد و آن پیامک هرگز نمی‌رود — برای
     * **همان یک رویداد**، در حالی که بقیه سالم‌اند. یعنی خرابیِ نقطه‌ای که در
     * تستِ دستی به‌سادگی از قلم می‌افتد.
     */
    public function test_every_pattern_variable_is_actually_sent_by_the_project(): void
    {
        $bad = [];

        foreach ($this->n8nRegistry() as $key => $needs) {
            if ($key === 'otp') {
                continue;   // مسیرِ خودش را دارد: sendOtp همیشه `code` می‌فرستد
            }

            $event = NotifyEvent::get($key);

            if ($event === null) {
                $bad[] = "$key: الگو در n8n هست ولی هیچ رویدادی در کاتالوگ ندارد";
                continue;
            }

            $missing = array_diff($needs, $event['vars']);

            if ($missing) {
                $bad[] = "$key: الگو «".implode('، ', $missing).'» می‌خواهد ولی رویداد نمی‌فرستد ⇒ missing_param';
            }
        }

        $this->assertSame([], $bad, "\n".implode("\n", $bad));
    }

    /**
     * 🔴 فهرستِ صریحِ رویدادهایی که **پیامک ندارند**.
     *
     * اینها ایمیل و بله می‌گیرند ولی پیامک نه، چون در پنلِ اپراتور الگویی
     * برایشان ساخته نشده. عمداً این‌جا سخت‌کد شده تا:
     *
     *   • افزودنِ رویدادِ تازه یک **تصمیمِ آگاهانه** باشد، نه فراموشی
     *   • و ساختنِ یک الگوی تازه در آی‌پی‌پنل، این تست را قرمز کند تا یادمان
     *     بیفتد به هر دو فهرست اضافه‌اش کنیم
     *
     * ⚠️ اگر این تست قرمز شد، **قبل از به‌روزکردنِ این آرایه** بپرس: آیا آن
     * رویداد واقعاً نباید پیامک بدهد؟ `paid` (تأییدِ پرداخت) و `expiring`
     * (یادآوریِ تمدید) هنوز در این فهرست‌اند و **باید** الگو بگیرند.
     */
    public function test_the_events_without_an_sms_pattern_are_explicitly_known(): void
    {
        $expected = [
            'announce',          // اطلاعیهٔ گروهی — پیامکِ انبوه عمداً نه
            'bank_rejected',
            'domain_expired',
            'domain_expiring',   // ⚠️ باید الگو بگیرد
            'domain_transfer',   // هنوز پیاده نشده
            'expiring',          // ⚠️ باید الگو بگیرد
            'paid',              // ⚠️ باید الگو بگیرد
            'password_changed',  // بی‌متغیر است؛ الگوی بی‌جای‌نگهدار معنا ندارد
            'reactivated',
            'service_ready',     // ⚠️ باید الگو بگیرد
            'suspended',         // ⚠️ باید الگو بگیرد
            'ticket_reply',      // ⚠️ باید الگو بگیرد
        ];

        $actual = array_values(array_diff(array_keys(NotifyEvent::ALL), SignedRelaySender::TEMPLATES));
        sort($actual);

        $this->assertSame($expected, $actual,
            "\nفهرستِ رویدادهای بی‌پیامک عوض شده.\n"
            ."اگر الگوی تازه‌ای در آی‌پی‌پنل ساخته‌ای، آن را به **هر دو** فهرست اضافه کن:\n"
            ."  app/Services/Sms/SignedRelaySender.php  →  TEMPLATES\n"
            .'  relay/n8n/verify-and-map-template.js    →  TEMPLATES (با کدِ الگو و نگاشتِ متغیر)');
    }

    /** رویدادی که پیامک دارد باید در کاتالوگ هم باشد — وگرنه هیچ‌وقت شلیک نمی‌شود */
    public function test_no_pattern_exists_for_an_event_that_does_not_exist(): void
    {
        $unknown = array_values(array_diff(SignedRelaySender::TEMPLATES, array_keys(NotifyEvent::ALL)));

        $this->assertSame([], $unknown,
            'الگو برای رویدادی تعریف شده که در کاتالوگ نیست: '.implode(', ', $unknown));
    }
}
