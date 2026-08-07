<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * صفحهٔ خطا در بدترین لحظه دیده می‌شود — پس نباید به زبانِ اشتباه باشد.
 *
 * ═══ باگی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * صفحاتِ ۴۲۹/۵۰۰/۵۰۳ را در همین جلسه ساختم و متنشان را **سخت‌کدِ فارسی**
 * گذاشتم. ۴۲۹ روی مسیرِ ورود و ثبت‌نام دیده می‌شود و ۵۰۰ ممکن است وسطِ پرداخت
 * بیاید: مشتریِ انگلیسی/ترک یک بلوکِ فارسیِ ناخوانا در قابِ چپ‌به‌راست می‌دید و
 * نمی‌فهمید پولش کسر شده یا نه.
 *
 * ⚠️ تستِ «صفحه ۲۰۰ می‌دهد» این را نمی‌گیرد — صفحه کاملاً سالم رندر می‌شد.
 * برای همین این‌جا **محتوای واقعی** سنجیده می‌شود، نه وضعیتِ پاسخ.
 */
class ErrorPageLocaleTest extends TestCase
{
    /** ویوهای خطا و کلیدهایی که باید داشته باشند */
    private const PAGES = ['404', '429', '500', '503'];

    /**
     * 🔴 در نسخهٔ en/tr نباید هیچ نویسهٔ فارسی/عربی در متنِ **دیدنی** باشد.
     *
     * کامنت‌های Blade و مقادیرِ `__()` استثنا نیستند — چون `__()` خودش در آن
     * زبان ترجمه برمی‌گرداند. اگر رشته‌ای سخت‌کد مانده باشد، همان‌جا می‌مانَد.
     */
    public function test_no_error_page_shows_persian_text_in_english_or_turkish(): void
    {
        $bad = [];

        foreach (['en', 'tr'] as $locale) {
            app()->setLocale($locale);

            foreach (self::PAGES as $code) {
                $html = view('errors.'.$code)->render();

                // فقط متنِ دیدنی: تگ‌ها، اسکریپت و استایل بیرون
                $text = trim(preg_replace('~\s+~u', ' ',
                    strip_tags(preg_replace('~<(script|style)[^>]*>.*?</\1>~is', '', $html))));

                /*
                | ⚠️ «فارسی» در کلیدِ تعویضِ زبان **درست** است و باید بماند —
                |    هر انتخابگرِ زبان، نامِ هر زبان را به خطِ خودش نشان می‌دهد.
                |    نسخهٔ اولِ این تست همان را می‌گرفت و هر چهار صفحه را در هر
                |    دو زبان قرمز می‌کرد؛ یعنی ادعا زیادی گشاد بود و چیزی که
                |    باید می‌سنجید را زیرِ نویزِ خودش دفن می‌کرد.
                */
                $text = str_replace(['فارسی'], '', $text);

                if (preg_match('~[\x{0600}-\x{06FF}]~u', $text, $m)) {
                    $bad[] = "errors/{$code} در «{$locale}» متنِ فارسی دارد: "
                        .mb_substr(trim(preg_replace('~^.*?([\x{0600}-\x{06FF}].{0,60}).*$~us', '$1', $text)), 0, 70);
                }
            }
        }

        app()->setLocale('fa');

        $this->assertSame([], $bad,
            "\nصفحهٔ خطا در بدترین لحظه دیده می‌شود؛ متنِ زبانِ اشتباه یعنی\n"
            ."کاربر نمی‌فهمد چه شد:\n".implode("\n", $bad));
    }

    /** و نسخهٔ فارسی باید واقعاً فارسی باشد — وگرنه کلیدها اصلاً ترجمه نشده‌اند */
    public function test_the_persian_version_is_actually_persian(): void
    {
        app()->setLocale('fa');

        foreach (self::PAGES as $code) {
            $text = strip_tags(view('errors.'.$code)->render());

            $this->assertMatchesRegularExpression('~[\x{0600}-\x{06FF}]~u', $text,
                "errors/{$code} در فارسی هیچ متنِ فارسی ندارد — کلیدها ترجمه نشده‌اند");
        }
    }

    /**
     * ⚠️ هر سه فایلِ زبان باید **دقیقاً** کلیدهای یکسان داشته باشند.
     *
     * کلیدِ جاافتاده یعنی کاربر رشتهٔ خامِ `ui.err_500_lead` را می‌بیند — که از
     * متنِ زبانِ اشتباه هم بدتر است.
     */
    public function test_all_three_language_files_have_identical_keys(): void
    {
        $keys = [];

        foreach (['fa', 'en', 'tr'] as $locale) {
            $keys[$locale] = array_keys((array) require lang_path($locale.'/ui.php'));
            sort($keys[$locale]);
        }

        $this->assertSame($keys['fa'], $keys['en'],
            "\nکلیدهای en با fa نمی‌خوانند:\n"
            .'  فقط در fa: '.implode(', ', array_slice(array_diff($keys['fa'], $keys['en']), 0, 10))."\n"
            .'  فقط در en: '.implode(', ', array_slice(array_diff($keys['en'], $keys['fa']), 0, 10)));

        $this->assertSame($keys['fa'], $keys['tr'],
            "\nکلیدهای tr با fa نمی‌خوانند:\n"
            .'  فقط در fa: '.implode(', ', array_slice(array_diff($keys['fa'], $keys['tr']), 0, 10))."\n"
            .'  فقط در tr: '.implode(', ', array_slice(array_diff($keys['tr'], $keys['fa']), 0, 10)));
    }
}
