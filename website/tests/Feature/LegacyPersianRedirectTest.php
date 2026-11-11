<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * اسلاگ‌های فارسیِ وردپرسِ قدیمی باید ۳۰۱ بخورند، نه ۴۰۴.
 *
 * گزارشِ «Not found (404)» در Search Console (۱۰ نوامبر ۲۰۲۶) هفت نشانیِ
 * فارسی داشت که گوگل هنوز ماهی چند بار سراغشان می‌رود — یعنی هنوز لینکِ
 * ورودی دارند. هر خزشِ ۴۰۴ هم بودجهٔ خزش می‌سوزاند هم اعتبارِ آن لینک‌ها را
 * دور می‌ریزد.
 *
 * 🔴 ادعای واقعیِ این تست «روت ثبت شده» نیست، «**درخواستِ درصدـکدشده** به
 *    مقصدِ درست می‌رسد» است. مرورگر و خزنده هرگز بایتِ خامِ فارسی نمی‌فرستند؛
 *    `%DA%A9%D8%A7…` می‌فرستند. تطبیقِ لاراول به `rawurldecode()` در
 *    `UriValidator` بند است، و اگر آن رفتار عوض شود این هفت روت بی‌صدا ۴۰۴
 *    می‌شوند: روت در فهرست هست، صفحه کار نمی‌کند، و هیچ خطایی هیچ‌جا نیست.
 */
class LegacyPersianRedirectTest extends TestCase
{
    public static function legacySlugs(): array
    {
        return [
            'google trends'  => ['/کاربرد-گوگل-ترندز-چیست؟', '/blog/keyword-research-for-beginners'],
            'site ux'        => ['/تجربه-کاربری-سایت', '/blog/core-web-vitals-guide'],
            'backlinks'      => ['/معرفی-کامل-اصول-بک-لینک', '/blog/internal-linking-strategy'],
            'wp speed'       => ['/9-روش-برای-افزایش-سرعت-وردپرس', '/blog/wordpress-speed-optimization'],
            'vpn server'     => ['/سرور-وی-پی-ان', '/blog/vpn-for-business-security'],
            'web design'     => ['/طراحی-وب-سایت-حرفه-ای-ساخت-وب-سایت-اختص', '/webdesign'],
            'urmia app'      => ['/ساخت-اپلیکیشن-موبایل-در-ارومیه', '/urmia/app-development'],
        ];
    }

    /** @dataProvider legacySlugs */
    public function test_a_percent_encoded_persian_slug_redirects_to_its_target(string $from, string $to): void
    {
        // دقیقاً همان چیزی که گوگل‌بات می‌فرستد: هر بایتِ غیرِASCII درصدـکدشده.
        $encoded = '/'.implode('/', array_map(
            static fn ($segment) => rawurlencode($segment),
            explode('/', ltrim($from, '/'))
        ));

        $this->assertNotSame($from, $encoded, 'اسلاگِ آزمون باید واقعاً غیرِASCII باشد');

        $this->get($encoded)->assertRedirect($to)->assertStatus(301);
    }

    /** شکلِ خامِ فارسی هم باید کار کند (کپی‌پیستِ کاربر از آدرس‌نوار) */
    public function test_the_raw_persian_form_redirects_too(): void
    {
        $this->get('/تجربه-کاربری-سایت')
            ->assertRedirect('/blog/core-web-vitals-guide')
            ->assertStatus(301);
    }

    /**
     * ⚠️ ادعای منفی، و مهم‌تر از مثبت‌هاست: ۳۰۱ به مقصدِ ۴۰۴ از خودِ ۴۰۴ بدتر
     * است — گوگل soft-404 می‌بیند، اعتبار منتقل نمی‌شود، و چون نشانیِ قدیمی
     * دیگر در گزارشِ ۴۰۴ نیست کسی متوجه نمی‌شود.
     *
     * @dataProvider legacySlugs
     */
    public function test_every_target_is_a_live_page(string $from, string $to): void
    {
        $this->get($to)->assertOk();
    }

    /**
     * ۳۰۱ به صفحه‌ای که خودش ۳۰۱ می‌دهد یعنی زنجیره. `/host` عمداً مستقیم به
     * مقصدِ نهایی می‌رود، نه به `/hosting` که خودش ۳۰۱ است.
     */
    public function test_host_skips_the_hosting_hop(): void
    {
        $this->get('/host')->assertRedirect('/hosting/linux')->assertStatus(301);
        $this->get('/hosting/linux')->assertOk();
    }
}
