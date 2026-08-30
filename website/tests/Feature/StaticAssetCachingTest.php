<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * قواعدِ کشِ فایل‌های ایستا — و سه تله‌ای که هر کدام خودشان کندی می‌سازند.
 *
 * ═══ چرا ═══
 *
 * اندازه‌گیریِ زنده نشان داد `assets/` هیچ هدرِ `Cache-Control`ی ندارد، فقط
 * `Last-Modified`. یعنی مرورگر برای **هر** فایل در **هر** بازدید یک درخواستِ
 * اعتبارسنجی می‌فرستد، و هر رفت‌وبرگشت از ایران ~۳۱۵ms است.
 *
 * ولی «همه را یک سال کش کن» سه جای مختلف می‌شکند، و این فایل هر سه را قفل
 * می‌کند چون هیچ‌کدام از روی خواندنِ `.htaccess` بدیهی نیستند.
 */
class StaticAssetCachingTest extends TestCase
{
    private function htaccess(): string
    {
        $path = public_path('.htaccess');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function viewFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    /**
     * 🔴 تلهٔ یک: مهرِ نسخه باید **عددی** باشد.
     *
     * `asset_ver()` وقتی فایل را پیدا نکند به `md5($rel)` برمی‌گردد — یک هشِ
     * **ثابت**. اگر قاعده هر `?v=`ی را immutable بگیرد، آن آدرسِ ثابت برای یک
     * سال فریز می‌شود و هر تغییرِ CSS روی سایتِ زنده بی‌اثر می‌مانَد.
     *
     * این دقیقاً یک‌بار در این پروژه رخ داد (بخشِ «مهرِ نسخه هرگز عوض نمی‌شد»
     * در CLAUDE.md). قاعده باید فقط `filemtime` را بپذیرد.
     */
    public function test_only_a_numeric_stamp_earns_the_one_year_cache(): void
    {
        $ht = $this->htaccess();

        $this->assertMatchesRegularExpression('~SetEnvIf\s+Query_String\s+"[^"]*v=\[0-9\]~', $ht,
            'شرطِ نسخه باید عددی باشد؛ هر «v=» یعنی هشِ fallback هم یک‌ساله می‌شود');

        $this->assertStringNotContainsString('v=[^&]+" SN_VERSIONED', $ht,
            'الگوی «هر مقداری» برگشته — هشِ ثابت دوباره immutable می‌شود');
    }

    /**
     * 🔴 تلهٔ دو: preloadِ فونت **نباید** نسخه بگیرد.
     *
     * `@font-face` داخلِ CSS فونت را با آدرسِ بی‌نسخه صدا می‌زند. اگر تگِ
     * preload آدرسِ `?v=`دار بدهد، مرورگر دو URLِ متفاوت می‌بیند و فونت را
     * **دو بار** دانلود می‌کند — یعنی یک بهینه‌سازی که خودش کندی می‌سازد و
     * هیچ خطایی هم تولید نمی‌کند.
     */
    public function test_font_preloads_match_the_url_the_css_actually_requests(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/site.blade.php'));

        preg_match_all('~<link[^>]+rel="preload"[^>]+href="\{\{ *(asset|asset_ver)\(~i', $layout, $m);

        $this->assertNotEmpty($m[1], 'هیچ preloadِ فونتی پیدا نشد — الگوی فایل عوض شده');

        foreach ($m[1] as $fn) {
            $this->assertSame('asset', $fn,
                'preloadِ فونت با asset_ver ساخته شده؛ آدرسش با آدرسِ داخلِ CSS نمی‌خوانَد و فونت دو بار دانلود می‌شود');
        }
    }

    /**
     * 🔴 تلهٔ سه: هر CSS/JS باید مهرِ واقعی داشته باشد.
     *
     * قاعدهٔ `.htaccess` به این قرارداد تکیه می‌کند. یک مهرِ **دستی** مثلِ
     * `?v=2` قرارداد را می‌شکند: آدرس عوض نمی‌شود ولی فایل عوض می‌شود.
     * (`otp-input.js` دقیقاً همین بود.)
     *
     * ⚠️ استثناها صریح‌اند تا افزودنشان یک تصمیم باشد، نه فراموشی.
     */
    public function test_every_stylesheet_and_script_carries_a_real_stamp(): void
    {
        $allowed = [
            'assets/js/novnc/',   // کتابخانهٔ vendorشده؛ ماژول‌های داخلی‌اش را خودش صدا می‌زند
        ];

        $bad = [];

        foreach ($this->viewFiles() as $file) {
            $src = (string) file_get_contents($file);

            preg_match_all("~\{\{ *asset\('(assets/(?:css|js)/[^']+)'\) *\}\}~", $src, $m);

            foreach ($m[1] as $rel) {
                foreach ($allowed as $ok) {
                    if (str_starts_with($rel, $ok)) {
                        continue 2;
                    }
                }
                $bad[] = basename($file).' → '.$rel;
            }
        }

        $this->assertSame([], $bad,
            "این‌ها با asset() صدا زده شده‌اند نه asset_ver()، پس مهرِ واقعی ندارند:\n"
            .implode("\n", $bad));
    }

    /** نبودِ ماژول نباید کلِ سایت را ۵۰۰ کند. */
    public function test_the_cache_rules_cannot_take_the_site_down(): void
    {
        $ht = $this->htaccess();

        $this->assertStringContainsString('<IfModule mod_headers.c>', $ht,
            'قواعدِ Header باید داخلِ IfModule باشند وگرنه نبودِ ماژول ۵۰۰ می‌دهد');

        /*
         * ⚠️ سنجشِ «داخلِ بلوک بودن» با regex تودرتو **کار نمی‌کند** — نسخهٔ اولِ
         * همین تست با الگوی non-greedy روی `</IfModule>`ِ تودرتوی `mod_setenvif`
         * بسته می‌شد و قواعدِ سالم را «بیرون» گزارش کرد.
         *
         * پرسشِ واقعی ساده‌تر است: آیا `Header` ای **پیش از** بازشدنِ گاردْ آمده؟
         */
        $guard = strpos($ht, '<IfModule mod_headers.c>');
        $this->assertNotFalse($guard);

        $this->assertStringNotContainsString('Header ', substr($ht, 0, $guard),
            'یک Header پیش از بازشدنِ IfModule آمده — نبودِ ماژول سایت را ۵۰۰ می‌کند');
        $this->assertStringEndsWith("</IfModule>\n", $ht,
            'بلوکِ گارد بسته نشده');
    }
}
