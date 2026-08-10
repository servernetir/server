<?php

namespace Tests\Feature;

use App\Services\Notify\NotifyEvent;
use App\Services\Otp\OtpService;
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
            /*
            | خانوادهٔ OTP مسیرِ خودش را دارد: `OtpService` — چه از راهِ
            | `sendOtp()` و چه از راهِ الگوی اختصاصی — **فقط** `code` می‌فرستد.
            |
            | ⚠️ نسخهٔ قبلی این‌جا فقط `continue` می‌زد و یعنی هیچ‌چیزی این را
            | نمی‌سنجید. اگر روزی الگوی OTPی در پنلِ اپراتور متغیرِ دومی بگیرد
            | (مثلاً «نام»)، n8n با `missing_param` ردش می‌کند و **کدِ ورود
            | هرگز نمی‌رود** — بی‌هیچ خطایی. پس صریح می‌سنجیم.
            */
            if (in_array($key, SignedRelaySender::OTP_TEMPLATES, true)) {
                $this->assertSame(['code'], $needs,
                    "الگوی «{$key}» متغیری جز `code` می‌خواهد، ولی OtpService فقط "
                    .'`code` می‌فرستد ⇒ n8n با missing_param ردش می‌کند و آن کد هرگز نمی‌رسد');

                continue;
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
            'domain_transfer',   // انتقالِ دامنه هنوز پیاده نشده
            'password_changed',  // بی‌متغیر است؛ الگوی بی‌جای‌نگهدار معنا ندارد
            /*
            | نگه‌داشتنِ سفارش برای بازبینی — **تصمیمِ آگاهانه**: پیامک نه.
            |
            | رویدادِ نادری است (فقط وقتی محافظِ سوءاستفاده می‌گیرد) و ایمیل و
            | بله هر دو می‌روند. ساختنِ الگوی اپراتور برایش یعنی هزینه و رفت‌وآمدِ
            | تأییدِ متن برای پیامی که ماهی یکی هم نمی‌شود.
            |
            | ⚠️ بهایش این است که `SmsDispatcher::event()` سرِ هر نگه‌داشتن یک
            | ردیف در ردیابِ خطا و `sms:last_error` می‌نویسد («الگو تعریف نشده»).
            | اگر روزی در آی‌پی‌پنل الگویش ساخته شد، به `SignedRelaySender::TEMPLATES`
            | و `relay/n8n/verify-and-map-template.js` اضافه‌اش کن و از این فهرست بردار.
            */
            'service_hold',
        ];
        sort($expected);

        $actual = array_values(array_diff(array_keys(NotifyEvent::ALL), SignedRelaySender::TEMPLATES));
        sort($actual);

        $this->assertSame($expected, $actual,
            "\nفهرستِ رویدادهای بی‌پیامک عوض شده.\n"
            ."اگر الگوی تازه‌ای در آی‌پی‌پنل ساخته‌ای، آن را به **هر دو** فهرست اضافه کن:\n"
            ."  app/Services/Sms/SignedRelaySender.php  →  TEMPLATES\n"
            .'  relay/n8n/verify-and-map-template.js    →  TEMPLATES (با کدِ الگو و نگاشتِ متغیر)');
    }

    /**
     * رویدادی که پیامک دارد باید در کاتالوگ هم باشد — وگرنه هیچ‌وقت شلیک نمی‌شود.
     *
     * ⚠️ خانوادهٔ OTP استثناست چون رویدادِ اطلاع‌رسانی نیست (مسیرش `OtpService`
     * است، متنِ قابلِ ویرایش در `/admin/templates` ندارد). ولی استثنا بی‌قید
     * نیست: تستِ بعدی می‌سنجد که هر عضوِ آن خانواده واقعاً صادرکننده دارد.
     */
    public function test_no_pattern_exists_for_an_event_that_does_not_exist(): void
    {
        $known = array_merge(array_keys(NotifyEvent::ALL), SignedRelaySender::OTP_TEMPLATES);
        $unknown = array_values(array_diff(SignedRelaySender::TEMPLATES, $known));

        $this->assertSame([], $unknown,
            'الگو برای رویدادی تعریف شده که نه در کاتالوگ است و نه کدِ یک‌بارمصرف: '
            .implode(', ', $unknown));
    }

    /**
     * 🔴 خانوادهٔ OTP نباید به دری برای الگوهای مرده تبدیل شود.
     *
     * چون این نام‌ها از بررسیِ «باید در کاتالوگ باشند» معاف‌اند، تنها چیزی که
     * زنده‌بودنشان را تضمین می‌کند همین است: هر نام باید **هدفی** داشته باشد که
     * `OtpService` با آن صادرش کند، و هر هدفِ ثبت‌شده باید نامش در فهرست باشد.
     *
     * بی‌این تست، `OtpService::SMS_TEMPLATES` می‌توانست نامی بدهد که در
     * `TEMPLATES` نیست — و آن‌وقت `sendPattern` نال می‌داد، بی‌صدا به الگوی
     * `otp` برمی‌گشت، و کارفرما هرگز نمی‌فهمید الگوی تازه‌اش استفاده نمی‌شود.
     */
    public function test_every_otp_template_belongs_to_a_real_purpose(): void
    {
        $issued = array_merge(['otp'], array_values(OtpService::SMS_TEMPLATES));

        sort($issued);
        $family = SignedRelaySender::OTP_TEMPLATES;
        sort($family);

        $this->assertSame($issued, $family,
            "\nخانوادهٔ OTP با هدف‌هایی که OtpService صادر می‌کند نمی‌خواند:\n"
            .'  فقط در OTP_TEMPLATES : '.(implode(', ', array_diff($family, $issued)) ?: '—')."\n"
            .'  فقط در SMS_TEMPLATES : '.(implode(', ', array_diff($issued, $family)) ?: '—'));

        $missing = array_values(array_diff($family, SignedRelaySender::TEMPLATES));

        $this->assertSame([], $missing,
            'نامِ خانوادهٔ OTP در فهرستِ مجازِ رله نیست، پس n8n `unknown_template` می‌دهد: '
            .implode(', ', $missing));
    }

    /**
     * 🔴 هدفِ «حذفِ سرویس» باید الگوی **خودش** را داشته باشد، نه `otp`.
     *
     * کارفرما: «زمانی که سروری رو حذف سرویس میکنم پیامک OTP ورود میاد اینو باید
     * اختصاصی حذف سرویسش کنیم.» تا شهریور ۱۴۰۵ هر هدفی همان `sendOtp()` را صدا
     * می‌زد و آن نامِ الگو را سخت‌کد `otp` داشت.
     */
    public function test_the_service_delete_purpose_has_its_own_pattern(): void
    {
        $this->assertSame('otp_service_delete', OtpService::smsTemplateFor('service_terminate'));
        $this->assertSame('otp', OtpService::smsTemplateFor('login'),
            'ورود باید همان الگوی عمومی را نگه دارد');

        $this->assertStringContainsString("code: 'tr4yx3mbo37rvmm'", $this->n8nSource(),
            'کدِ الگوی حذفِ سرور در رجیستریِ n8n نیست — پیامک با `unknown_template` دور ریخته می‌شود');
    }
}
