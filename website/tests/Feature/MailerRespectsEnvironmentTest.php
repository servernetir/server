<?php

namespace Tests\Feature;

use App\Services\Mail\MailboxReplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 `MailboxReplier` از `MAIL_MAILER` رد می‌شد و سوکتِ واقعی باز می‌کرد.
 *
 * برای اینکه پاسخ از نشانیِ خودِ صندوق برود، این کلاس یک mailerِ
 * `transport => smtp` **در لحظه** می‌سازد — کارِ درستی است (وگرنه SPF/DKIM
 * نامه را به اسپم می‌برد). ولی همان یعنی تنظیمِ سراسریِ «ایمیل نفرست»
 * دور زده می‌شود.
 *
 * ═══ چطور خودش را نشان داد ═══
 *
 * نه با تستِ قرمز، بلکه با مرگِ کلِ اجرا:
 *
 *     Fatal error: Maximum execution time of 200 seconds exceeded
 *     in symfony/mailer/Transport/Smtp/Stream/SocketStream.php
 *
 * یعنی سوئیت وسطِ کار مُرد و **هیچ گزارشی** نداد — بدترین شکلِ خرابی، چون
 * حتی نمی‌گوید کدام ادعا شکسته.
 *
 * ⚠️ این محافظِ تست نیست: هر محیطی که آگاهانه روی «نفرست» تنظیم شده
 * (staging، اجرای محلی، بازیابیِ حادثه) نباید از این‌جا نامهٔ واقعی بفرستد.
 */
class MailerRespectsEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ ادعا روی **خودِ سورس** است، نه رفتار: صدازدنِ واقعیِ `reply()` نیازمندِ
     * یک صندوقِ پیکربندی‌شده و یک پیامِ واقعی است، و اگر گارد نباشد تست
     * به‌جای قرمزشدن **هنگ می‌کند** — همان چیزی که در سوئیت دیدیم. تستی که
     * برای شکست‌خوردن هنگ کند، بی‌فایده است.
     */
    public function test_the_replier_defers_to_the_configured_mailer_when_sending_is_off(): void
    {
        $src = file_get_contents(app_path('Services/Mail/MailboxReplier.php'));

        $this->assertStringContainsString("config('mail.default')", $src,
            'MailboxReplier تنظیمِ سراسریِ ایمیل را اصلاً نمی‌خوانَد');
        $this->assertStringContainsString("['array', 'log', 'null'], true)", $src,
            'درایورهای «نفرست» شناسایی نمی‌شوند');

        // و ساختِ mailerِ SMTP باید پشتِ همان شرط باشد
        $guardPos = strpos($src, "if (\$mailerKey !== \$default) {");
        $smtpPos  = strpos($src, "'transport'    => 'smtp'");
        $this->assertNotFalse($guardPos, 'ساختِ mailerِ SMTP پشتِ هیچ شرطی نیست');
        $this->assertGreaterThan($guardPos, $smtpPos,
            'mailerِ SMTP بیرونِ گارد ساخته می‌شود — یعنی هنوز از MAIL_MAILER رد می‌شود');
    }

    /** در محیطِ تست، درایورِ پیش‌فرض باید واقعاً «نفرست» باشد. */
    public function test_the_test_environment_really_is_set_to_not_send(): void
    {
        $this->assertContains(config('mail.default'), ['array', 'log', 'null'],
            'phpunit.xml درایورِ ایمیل را روی «نفرست» نگذاشته — سوئیت می‌تواند نامهٔ واقعی بفرستد');
    }
}
