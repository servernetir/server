<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * صفحهٔ `/tools/whois` — قیفِ فروشِ دامنه و محتوای سئو.
 *
 * ═══ 🔴 نشتی‌ای که این تست‌ها می‌بندند ═══
 *
 * دکمهٔ «ثبت این دامنه» به `my.servernet.ir/cart.php` می‌رفت — WHMCSِ بیرونی.
 * یعنی دقیقاً در لحظه‌ای که بازدیدکننده بیشترین قصدِ خرید را دارد («این دامنه
 * آزاد است!»)، از سایت بیرون پرتاب می‌شد به سامانه‌ای با ظاهر و حسابِ متفاوت —
 * در حالی که مسیرِ درون‌خانگیِ ثبتِ دامنه (استعلامِ زنده از رجیسترار، قیمتِ
 * تومانی، پیش‌فاکتور، پنلِ خودمان) ساخته و فعال است.
 *
 * هیچ خطایی هم در کار نبود: لینک کار می‌کرد، صفحه باز می‌شد، و فقط درآمد به
 * سامانهٔ قدیمی می‌رفت.
 */
class ToolWhoisPageTest extends TestCase
{
    private function js(): string
    {
        return (string) file_get_contents(public_path('assets/js/tools.js'));
    }

    // ═══════════════ ۱) قیفِ فروش ═══════════════

    public function test_the_register_button_points_at_our_own_domain_search(): void
    {
        $html = $this->get('/tools/whois')->assertOk()->getContent();

        /*
         * ⚠️ کلیدها در این بلوک **بدون گیومه**اند — یک object literalِ جاوااسکریپت
         * است نه JSON. رجکسِ اولِ همین تست `"registerUrl"` می‌گشت و هرگز پیدا
         * نمی‌کرد؛ یعنی تستی که با کدِ درست هم قرمز می‌ماند.
         *
         * مقدار را `@json` می‌سازد، پس اسلش‌ها `\/` فرار می‌شوند.
         */
        $this->assertMatchesRegularExpression(
            '~registerUrl\s*:\s*"[^"]*\\\\/domains\?q="~',
            $html,
            'دکمهٔ ثبت باید به /domains?q= برود'
        );
    }

    /**
     * ⚠️ نیمهٔ دوم و مهم‌ترش: نباید **به WHMCS بیرونی** برود.
     *
     * بی‌این ادعا، کسی می‌توانست لینکِ داخلی را اضافه کند و لینکِ بیرونی هم سرِ
     * جایش بماند — و همان نشتی از درِ دیگر برگردد.
     */
    public function test_the_whois_page_no_longer_links_into_the_external_billing_system(): void
    {
        $html = $this->get('/tools/whois')->assertOk()->getContent();

        $whmcs = rtrim((string) config('servernet.whmcs.fa'), '/');
        $this->assertNotSame('', $whmcs, 'آدرسِ WHMCS در config نیست — این تست بی‌معنی می‌شود');

        // بلوکِ TOOL_I18N همان جایی است که دکمه آدرسش را از آن می‌گیرد
        preg_match('~window\.TOOL_I18N\s*=\s*\{(.*?)\};~s', $html, $m);
        $this->assertNotEmpty($m, 'بلوکِ TOOL_I18N پیدا نشد');

        $this->assertStringNotContainsString('cart.php', $m[1],
            'سبدِ خریدِ WHMCS هنوز در قیفِ Whois است');
    }

    /** لینکِ داخلی باید در **همان تب** باز شود، وگرنه قیف می‌شکند. */
    public function test_the_internal_cta_does_not_open_a_new_tab(): void
    {
        $js = $this->js();

        preg_match('~function cta\(d, registered\)\s*\{(.*?)\n    \}~s', $js, $m);
        $this->assertNotEmpty($m, 'تابعِ cta پیدا نشد');

        $this->assertStringNotContainsString('target="_blank"', $m[1],
            'مقصد داخلی است؛ تبِ تازه فقط قیف را می‌شکند');
        $this->assertStringContainsString('T.registerUrl', $m[1]);
    }

    /**
     * دامنهٔ گرفته‌شده هم قدمِ بعدی دارد.
     *
     * تا امروز کاربر با یک «گرفته شده» تنها گذاشته می‌شد و صفحه بن‌بست بود —
     * در حالی که همان لحظه دقیقاً دنبالِ نامِ جایگزین است.
     */
    public function test_a_taken_domain_still_offers_a_next_step(): void
    {
        $js = $this->js();

        $this->assertStringContainsString('T.similar', $js,
            'برای دامنهٔ گرفته‌شده هیچ قدمِ بعدی‌ای پیشنهاد نمی‌شود');

        foreach (['fa', 'en', 'tr'] as $l) {
            $this->assertNotSame('ui.tl_wk_similar', __('ui.tl_wk_similar', [], $l),
                "رشتهٔ «نام‌های مشابه» در {$l} نیست");
        }
    }

    // ═══════════════ ۲) محتوای سئو ═══════════════

    public function test_the_page_is_no_longer_thin(): void
    {
        $html = $this->get('/tools/whois')->assertOk()->getContent();
        $seo = require resource_path('content/tools-seo.php');
        $fa = $seo['whois']['fa'];

        $this->assertStringContainsString(e($fa['intro']), $html);
        $this->assertStringContainsString(e($fa['steps'][0]), $html);
        $this->assertStringContainsString(e($fa['faq'][0]['q']), $html);
    }

    public function test_the_structured_data_matches_the_content(): void
    {
        $html = $this->get('/tools/whois')->assertOk()->getContent();
        $seo = require resource_path('content/tools-seo.php');

        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('"@type":"HowTo"', $html);
        $this->assertSame(
            count($seo['whois']['fa']['faq']),
            substr_count($html, '"@type":"Question"')
        );
    }

    public function test_no_raw_translation_keys_leak_in_any_language(): void
    {
        foreach (['/tools/whois', '/en/tools/whois', '/tr/tools/whois'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('ui.tl_wk_', $html, "کلیدِ خام در {$url}");
            $this->assertStringNotContainsString('ui.tl_whois_', $html, "کلیدِ خام در {$url}");
        }
    }

    /** هر سه زبان باید به نسخهٔ زبانیِ **خودشان** از جستجوی دامنه بروند. */
    public function test_each_language_links_to_its_own_domain_search(): void
    {
        foreach (['/en/tools/whois' => '/en/domains', '/tr/tools/whois' => '/tr/domains'] as $url => $expected) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(str_replace('/', '\\/', $expected).'?q=', $html,
                "{$url} باید به {$expected} برود");
        }
    }
}
