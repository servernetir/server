<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * مهرِ نسخهٔ فایل‌های استاتیک — `asset_ver()`.
 *
 * 🔴 چرا این تست وجود دارد: روی پروداکشن این تابع **بی‌صدا** از کار افتاده بود.
 * اپ بیرونِ webroot است، پس `public_path()` می‌شود `servernet_app/public` که
 * روی سرور ساخته نشده؛ `is_file()` همیشه false می‌داد و نسخه همیشه همان هشِ
 * ثابتِ `md5($rel)` می‌مانْد.
 *
 * پیامدش این بود که مرورگر و Cloudflare هر CSS/JS را برای همیشه کش می‌کردند و
 * **هیچ تغییرِ ظاهری روی سایت زنده دیده نمی‌شد** — بدونِ خطا، بدونِ لاگ، با
 * کدِ ۲۰۰. اولین بار وقتی پیدا شد که CSSِ تازهٔ تقویم روی سرور بود ولی صفحه
 * نسخهٔ دیروز را می‌گرفت.
 */
class AssetVersionTest extends TestCase
{
    private const REAL_ASSET = 'assets/css/admin.css';

    protected function tearDown(): void
    {
        unset($_SERVER['DOCUMENT_ROOT']);
        parent::tearDown();
    }

    public function test_an_existing_asset_gets_an_mtime_stamp(): void
    {
        $this->assertMatchesRegularExpression(
            '/\?v=\d{9,}$/',
            asset_ver(self::REAL_ASSET),
            'نسخه باید mtime باشد، نه هشِ ثابت',
        );
    }

    /**
     * 🔴 ادعای اصلی: چیدمانِ **پروداکشن** شبیه‌سازی می‌شود.
     *
     * `public_path()` عمداً به جایی می‌رود که وجود ندارد — دقیقاً کاری که
     * cPanel می‌کند — و تابع باید از `DOCUMENT_ROOT` فایل را پیدا کند.
     *
     * ⚠️ تستِ قبلی (بالا) به‌تنهایی این را **نمی‌گرفت**، چون محلی `public_path()`
     * درست است و مسیرِ خراب هرگز اجرا نمی‌شد. همان درسِ «تستی که فرضِ نانوشته را
     * نمی‌سنجد، محافظِ باگ می‌شود».
     */
    public function test_it_falls_back_to_the_document_root_when_public_path_is_wrong(): void
    {
        $realPublic = public_path();

        try {
            app()->usePublicPath('/definitely/not/a/real/path');
            $_SERVER['DOCUMENT_ROOT'] = $realPublic;

            $url = asset_ver(self::REAL_ASSET);

            $this->assertMatchesRegularExpression(
                '/\?v=\d{9,}$/',
                $url,
                'با public_path غلط هم باید از DOCUMENT_ROOT مهرِ واقعی بسازد',
            );
            $this->assertStringNotContainsString(
                substr(md5(self::REAL_ASSET), 0, 8),
                $url,
                'نباید به هشِ ثابت بیفتد — همان حالتی که کش را برای همیشه می‌بندد',
            );
        } finally {
            app()->usePublicPath($realPublic);
        }
    }

    /**
     * 🔴 وقتی **هر دو** مسیر فایل دارند، فایلِ `DOCUMENT_ROOT` برنده است.
     *
     * این حالتِ واقعیِ پروداکشن است و تستِ قبلی نمی‌دیدش: `public_path()` روی
     * سرور `servernet_app/public` است و یک **نسخهٔ دومِ کهنه** از دارایی‌ها
     * آن‌جا مانده بود. چون آن اول امتحان می‌شد، مهرِ نسخه از فایلی ساخته می‌شد
     * که هیچ‌کس دانلود نمی‌کند.
     *
     * نشانه‌اش روی سایتِ زنده: `site.css` و `tools.js` هر دو `?v=1786410974`
     * می‌گرفتند در حالی که فایل‌های واقعیِ `public_html/` تازه آپلود شده بودند
     * (mtime ۱۷۸۶۷۲۷۵۴۳ و ۱۷۸۶۷۲۷۵۴۴). دو فایلِ متفاوت با یک مهرِ یکسان.
     *
     * پیامد: هر تغییرِ CSS آپلود می‌شد ولی آدرس عوض نمی‌شد، پس مرورگرِ
     * بازدیدکنندهٔ قبلی نسخهٔ کهنه را نگه می‌داشت.
     */
    public function test_the_document_root_copy_wins_when_both_exist(): void
    {
        $realPublic = public_path();
        $stale = sys_get_temp_dir().'/sn-stale-public-'.getmypid();
        $rel = self::REAL_ASSET;

        @mkdir(dirname($stale.'/'.$rel), 0777, true);
        file_put_contents($stale.'/'.$rel, '/* نسخهٔ کهنه */');
        // عمداً کهنه: اگر این برنده شود، مهر عقب می‌افتد
        touch($stale.'/'.$rel, time() - 86400 * 30);

        try {
            app()->usePublicPath($stale);
            $_SERVER['DOCUMENT_ROOT'] = $realPublic;

            $resolved = public_asset_path($rel);

            $this->assertSame(
                realpath($realPublic.'/'.$rel),
                realpath((string) $resolved),
                'نسخهٔ کهنهٔ داخلِ public_path برنده شد — همان باگی که مهرِ نسخه را برای همیشه ثابت نگه می‌داشت'
            );

            $this->assertStringContainsString(
                '?v='.filemtime($realPublic.'/'.$rel),
                asset_ver($rel),
                'مهر باید mtimeِ فایلی باشد که وب‌سرور سرو می‌کند'
            );
        } finally {
            app()->usePublicPath($realPublic);
            @unlink($stale.'/'.$rel);
        }
    }

    /**
     * فایلِ نبود هنوز نباید صفحه را بشکند — قاعدهٔ اصلیِ این تابع دست‌نخورده.
     */
    public function test_a_missing_asset_still_returns_a_usable_url(): void
    {
        $url = asset_ver('assets/css/there-is-no-such-file.css');

        $this->assertStringContainsString('there-is-no-such-file.css', $url);
        $this->assertStringContainsString('?v=', $url);
    }
}
