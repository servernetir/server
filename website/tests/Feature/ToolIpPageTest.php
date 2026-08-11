<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * صفحهٔ `/tools/ip` — اسکرولِ خودکار، پرچمِ SVG، و محتوای سئو.
 *
 * ═══ چرا این تست‌ها لازم‌اند ═══
 *
 * 🔴 **باگی که کارفرما دید:** صفحه با `data-auto="1"` بلافاصله IP بازدیدکننده را
 * بررسی می‌کرد و `renderIp()` بی‌قیدوشرط `scrollIntoView()` می‌زد. یعنی
 * بازدیدکننده‌ای که تازه لینک را باز کرده بود، وسطِ صفحه می‌افتاد و **هدر و
 * عنوان را اصلاً نمی‌دید**. کدِ صفحه ۲۰۰ بود، هیچ خطایی هیچ‌جا ثبت نمی‌شد، و
 * هیچ تستی هم نمی‌توانست بگیردش چون خرابی کاملاً در جاوااسکریپت بود.
 *
 * پس ادعا روی **خودِ فایلِ JS** گذاشته می‌شود — تنها لایه‌ای که این تصمیم در آن
 * زندگی می‌کند. §۸ همین را می‌گوید: «کد ۲۰۰ یعنی هیچ».
 *
 * ⚠️ اسکرولِ دستی حذف نشد و نباید بشود: وقتی کاربر خودش IP دیگری را بررسی
 * می‌کند، پریدن به نتیجه دقیقاً کارِ درست است. تفاوت در «چه کسی خواسته» است.
 */
class ToolIpPageTest extends TestCase
{
    private function js(): string
    {
        $path = public_path('assets/js/tools.js');
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // ═══════════════ ۱) اسکرولِ خودکار — باگِ گزارش‌شده ═══════════════

    public function test_the_automatic_lookup_never_scrolls_the_page(): void
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            "/dataset\.auto === '1'\) lookup\('', \{ scroll: false, quiet: true \}\)/",
            $js,
            'اجرای خودکار باید صریح scroll:false بدهد، وگرنه صفحه در لحظهٔ ورود از هدر رد می‌شود'
        );

        // و اجرای خودکار نباید هیچ‌وقت به شکلِ بی‌آرگومانِ قدیمی برگردد
        $this->assertStringNotContainsString("dataset.auto === '1') lookup('')", $js);
    }

    /**
     * …و بی‌صدا شکست می‌خورد.
     *
     * IPِ رزروشده (لوکال‌هاست، CG-NAT) یا یک قطعیِ گذرای سرویسِ ژئو، کادرِ قرمز
     * را در **لحظهٔ ورود** روی صفحه می‌گذاشت — برای درخواستی که بازدیدکننده
     * اصلاً نداده بود. اولین چیزی که می‌دید یک خطا بود.
     */
    public function test_the_automatic_lookup_fails_silently(): void
    {
        $js = $this->js();

        $this->assertStringContainsString('quiet: true', $js);
        $this->assertStringContainsString('if (o.quiet) return;', $js);
    }

    public function test_a_lookup_the_visitor_asked_for_still_scrolls_to_the_result(): void
    {
        $js = $this->js();

        // ارسالِ فرم = خواستهٔ کاربر ⇒ بی‌پرچمِ scroll ⇒ پیش‌فرضِ true
        $this->assertStringContainsString(
            "ipForm.addEventListener('submit', (e) => { e.preventDefault(); lookup(input.value.trim()); });",
            $js
        );
        $this->assertStringContainsString('if (scroll) box.scrollIntoView(', $js);
    }

    /**
     * حتی اسکرولِ خواسته‌شده هم نباید نتیجه را زیرِ هدر بگذارد.
     *
     * `block:'start'` بالای عنصر را روی بالای viewport می‌نشانَد و آن‌جا زیرِ
     * هدرِ ثابت است. قاعدهٔ §۳ می‌گوید ارتفاعِ هدر فقط در `--header-h` است، پس
     * جبرانش هم باید از همان بیاید نه از یک عددِ دستی.
     */
    public function test_scrolled_results_reserve_the_fixed_header(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/site.css'));

        $this->assertMatchesRegularExpression(
            '/#ip-result[^{]*\{[^}]*scroll-margin-top:calc\(var\(--header-h\)/',
            $css
        );
    }

    // ═══════════════ ۲) پرچمِ کشور ═══════════════

    public function test_the_page_ships_the_flag_list_so_the_browser_never_requests_a_missing_one(): void
    {
        $html = $this->get('/tools/ip')->assertOk()->getContent();

        $this->assertStringContainsString('"flagBase"', $html);

        // ⚠️ `@json` اسلش را `\/` می‌کند (محافظِ `</script>`)، پس مسیر را
        // نمی‌شود عینی سنجید — همان بی‌خبری یک بار خودِ این تست را قرمز کرد.
        $this->assertStringContainsString('assets\/flags\/', $html);

        // چند کدِ نمونه که فایلشان قطعاً هست
        foreach (['"ir"', '"de"', '"us"'] as $code) {
            $this->assertStringContainsString($code, $html);
        }
    }

    public function test_the_flag_list_matches_the_files_actually_on_disk(): void
    {
        $codes = flag_codes();

        $this->assertNotEmpty($codes, 'پوشهٔ پرچم‌ها خوانده نشد — کارت نتیجه به اموجی برمی‌گردد');
        $this->assertContains('ir', $codes);
        $this->assertNotContains('zw', $codes, 'کدی که فایل ندارد نباید در فهرست باشد');

        // هر کدِ فهرست باید واقعاً فایل داشته باشد — وگرنه <img>ِ شکسته
        foreach ($codes as $cc) {
            $this->assertNotNull(
                public_asset_path(\App\Models\CloudLocation::FLAG_DIR.'/'.$cc.'.svg'),
                "پرچمِ {$cc} در فهرست هست ولی فایلش نیست"
            );
        }
    }

    public function test_the_result_card_prefers_the_svg_and_keeps_the_emoji_only_as_fallback(): void
    {
        $js = $this->js();

        $this->assertStringContainsString('if (flags.has(code))', $js);
        $this->assertStringContainsString('${T.flagBase}${code}.svg', $js);
        // کشوری که فایلش را نداریم هنوز چیزی نشان می‌دهد، نه یک جای خالی
        $this->assertStringContainsString("esc(d.flag || '')", $js);
    }

    // ═══════════════ ۳) محتوای سئو ═══════════════

    public function test_the_page_is_no_longer_thin_and_carries_real_content(): void
    {
        $html = $this->get('/tools/ip')->assertOk()->getContent();

        $seo = require resource_path('content/tools-seo.php');
        $fa = $seo['ip']['fa'];

        $this->assertStringContainsString(e($fa['intro']), $html);
        $this->assertStringContainsString(e($fa['steps'][0]), $html);
        $this->assertStringContainsString(e($fa['faq'][0]['q']), $html);
    }

    public function test_the_faq_and_howto_are_also_machine_readable(): void
    {
        $html = $this->get('/tools/ip')->assertOk()->getContent();

        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('"@type":"HowTo"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"@type":"WebApplication"', $html);

        // ⚠️ `@context` را Blade می‌بلعد اگر با `'@'.'context'` نوشته نشود.
        $this->assertStringContainsString('"@context":"https://schema.org"', $html);
    }

    /**
     * تعدادِ پرسش‌های JSON-LD باید با فایلِ محتوا بخوانَد.
     *
     * وگرنه یک روز کسی سؤالی به فایل اضافه می‌کند، صفحه نشانش می‌دهد و
     * دادهٔ ساختاریافته از آن بی‌خبر می‌مانَد — گوگل نصفِ FAQ را می‌بیند.
     */
    public function test_every_question_in_the_content_file_reaches_the_structured_data(): void
    {
        $html = $this->get('/tools/ip')->assertOk()->getContent();
        $seo = require resource_path('content/tools-seo.php');

        $this->assertSame(
            count($seo['ip']['fa']['faq']),
            substr_count($html, '"@type":"Question"')
        );
    }

    // ═══════════════ ۴) هیچ کلیدِ خامی روی صفحه نماند ═══════════════

    /**
     * کلیدِ جامانده در یکی از سه فایلِ زبان، متنِ خام `ui.tl_ip_…` را روی صفحه
     * چاپ می‌کند — بی‌هیچ خطایی و با کدِ ۲۰۰.
     */
    public function test_no_raw_translation_keys_leak_in_any_language(): void
    {
        foreach (['/tools/ip', '/en/tools/ip', '/tr/tools/ip'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('ui.tl_ip_', $html, "کلیدِ خام در {$url}");
            $this->assertStringNotContainsString('ui.tl_about', $html, "کلیدِ خام در {$url}");
            $this->assertStringNotContainsString('ui.tl_howto', $html, "کلیدِ خام در {$url}");
            $this->assertStringNotContainsString('ui.tl_faq', $html, "کلیدِ خام در {$url}");
        }
    }

    /**
     * صفحاتی که هنوز محتوای سئو ندارند نباید بشکنند — بخش فقط رندر نمی‌شود.
     */
    public function test_a_tool_without_seo_content_still_renders(): void
    {
        foreach (['seo', 'whois', 'meet', 'app-builder'] as $slug) {
            $this->get('/tools/'.$slug)->assertOk();
        }
    }
}
