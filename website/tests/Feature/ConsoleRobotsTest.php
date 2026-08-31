<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * کنسول باید robots.txtِ **خودش** را بگیرد، نه فایلِ سایتِ اصلی.
 *
 * ═══ خرابی‌ای که این را لازم کرد (Crawl Stats، ۹ شهریور ۱۴۰۵) ═══
 *
 * `console.servernet.cloud` همان `public_html` را سرو می‌کند، پس دقیقاً همان
 * `robots.txt`ی را می‌داد که با **`Allow: /`** شروع می‌شود. گوگل در ۹۰ روز
 * ۶۰۰ درخواستِ خزش — ۱۰٪ کلِ بودجه — خرجِ میزبانی کرد که هر صفحه‌اش از قبل
 * `noindex` است، در حالی که ۶۵۴ صفحهٔ بلاگ هرگز خزیده نشده بودند.
 *
 * ⚠️ `noindex` و `Disallow` یک کار نمی‌کنند: اولی جلوی **ایندکس** را می‌گیرد،
 * دومی جلوی **خزش** را. آن ۱۷۰ صفحهٔ «خارج‌شده با noindex» درست بودند و
 * هیچ‌وقت هم اشتباه نبودند — ولی بودجه را همچنان می‌خوردند.
 *
 * 🔴 این ادعا فقط با خواندنِ فایل سنجیده می‌شود، نه با اجرای آپاچی — پس
 * ضعیف‌تر از یک تستِ رفتاری است و همین‌جا صریح نوشته می‌شود. راستی‌آزماییِ
 * واقعی یک `curl` روی سرور است و در اسکریپتِ دیپلوی آمده. درسِ
 * `SetEnvIf Query_String` تازه است: قاعده‌ای که آپاچی اجرا نکند هم از دیدِ
 * چنین تستی «هست».
 */
class ConsoleRobotsTest extends TestCase
{
    private function htaccess(): string
    {
        return (string) file_get_contents(public_path('.htaccess'));
    }

    public function test_the_console_has_its_own_robots_file(): void
    {
        $path = public_path('robots-console.txt');

        $this->assertFileExists($path, 'robots-console.txt نیست — کنسول دوباره فایلِ سایتِ اصلی را می‌گیرد');

        $body = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression('~^\s*Disallow:\s*/\s*$~m', $body,
            'کنسول باید کاملاً از خزش بیرون باشد');
        $this->assertDoesNotMatchRegularExpression('~^\s*Allow:~m', $body,
            'یک Allow این‌جا کلِ هدفِ فایل را برمی‌دارد');
    }

    /**
     * ⚠️ نقشهٔ سایت عمداً در فایلِ کنسول **نیست**.
     *
     * `Sitemap:` روی میزبانی که خودش Disallow است، هم بی‌اثر است هم
     * گمراه‌کننده: نقشه مالِ میزبانِ اصلی است و اعلامش از میزبانِ دیگر
     * فقط یک ادعای بی‌پشتوانه در گزارش‌های خزش می‌سازد.
     */
    public function test_the_console_file_does_not_announce_the_sitemap(): void
    {
        $this->assertStringNotContainsString('Sitemap:',
            (string) file_get_contents(public_path('robots-console.txt')));
    }

    /** بازنویسیِ داخلی، نه ۳۰۱ — خزنده باید در همان آدرس پاسخ بگیرد. */
    public function test_the_rewrite_is_internal_and_host_scoped(): void
    {
        $ht = $this->htaccess();

        $this->assertSame(1,
            preg_match('~RewriteCond\s+%\{HTTP_HOST\}\s+(\S+)[^\n]*\n\s*RewriteRule\s+(\S+)\s+(\S+)\s+\[([^\]]*)\]~', $ht, $m),
            'قاعدهٔ میزبان‌محورِ robots در .htaccess نیست');

        $this->assertStringContainsString('console', $m[1], 'شرط باید فقط میزبانِ کنسول را بگیرد');
        $this->assertStringContainsString('robots', $m[2]);
        $this->assertSame('robots-console.txt', $m[3]);

        $this->assertStringContainsString('L', $m[4], '[L] لازم است وگرنه قواعدِ بعدی دوباره مسیر را می‌گیرند');
        $this->assertStringNotContainsString('R=', $m[4],
            'ریدایرکت ممنوع — خزنده باید robots.txt را در همان آدرسِ خودش بگیرد، نه به فایلِ دیگری فرستاده شود');
    }

    /**
     * 🔴 و نگهبانِ جهتِ مخالف: فایلِ سایتِ اصلی نباید قربانیِ این تغییر شود.
     *
     * ساده‌ترین «رفع»ِ اشتباه این است که کسی `Disallow: /` را در فایلِ اصلی
     * بگذارد و کلِ سایت را از گوگل بیرون بیندازد. این تست دقیقاً همان را
     * می‌گیرد.
     */
    public function test_the_main_robots_still_opens_the_site_and_declares_the_sitemap(): void
    {
        $main = (string) file_get_contents(public_path('robots.txt'));

        $this->assertMatchesRegularExpression('~^\s*Allow:\s*/\s*$~m', $main);
        $this->assertDoesNotMatchRegularExpression('~^\s*Disallow:\s*/\s*$~m', $main,
            'یک «Disallow: /» در فایلِ اصلی یعنی کلِ سایت از گوگل بیرون می‌رود');
        $this->assertStringContainsString('Sitemap: https://servernet.cloud/sitemap.xml', $main);
    }
}
