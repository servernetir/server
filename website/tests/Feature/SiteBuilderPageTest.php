<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * صفحهٔ `/services/site-builder` — سلامتِ بلوکِ `AIB_I18N` که builder.js از آن
 * زندگی می‌گیرد.
 *
 * ═══ چرا این تست لازم است ═══
 *
 * 🔴 **باگی که کارفرما دید (شهریور ۱۴۰۵):** «سایت‌ساز کار نمی‌کند». صفحه ۲۰۰
 * می‌داد و ظاهرش سالم بود، ولی در Blade نوشته شده بود:
 *
 *     currency: {{ $isFa ? "'تومان'" : "'€'" }}
 *
 * و `{{ }}` کوتیشن را HTML-escape می‌کند، پس خروجی می‌شد
 * `currency: &#039;تومان&#039;` — یک SyntaxError برای کلِ اسکریپتِ inline.
 * `window.AIB_I18N` هرگز ساخته نمی‌شد و builder.js در همان لحظهٔ بارگذاری روی
 * `I.fa` می‌مرد: چت هیچ پاسخی نمی‌داد، استعلامِ دامنه ساکت بود و دکمهٔ دپلوی
 * مرده بود — **بی‌هیچ خطایی در هیچ لاگِ سروری**، چون خرابی تماماً در مرورگر بود.
 *
 * پس ادعا روی **خروجیِ رندرشده** است، نه روی وضعیتِ HTTP — همان قاعدهٔ §۸.
 */
class SiteBuilderPageTest extends TestCase
{
    /** بلوکِ AIB_I18N از خروجیِ واقعیِ رندرشدهٔ صفحه. */
    private function i18nBlock(): string
    {
        $html = $this->get('/services/site-builder')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~window\.AIB_I18N = \{.*?\};~s',
            $html,
            'بلوکِ AIB_I18N در صفحهٔ سایت‌ساز نیست — builder.js بدونِ آن کار نمی‌کند.'
        );

        preg_match('~window\.AIB_I18N = \{.*?\};~s', $html, $m);

        return $m[0];
    }

    public function test_the_i18n_block_is_valid_javascript_not_html_escaped_soup(): void
    {
        $block = $this->i18nBlock();

        // &#039; / &amp; / &quot; داخلِ یک <script> یعنی کسی رشته را از {{ }}
        // رد کرده — همان چیزی که یک‌بار کلِ ویجت را کشت. رشته باید از @json بیاید.
        foreach (['&#039;', '&amp;', '&quot;', '&lt;'] as $entity) {
            $this->assertStringNotContainsString(
                $entity,
                $block,
                "بلوکِ AIB_I18N موجودیتِ HTML ($entity) دارد؛ یعنی SyntaxError و مرگِ کاملِ ویجت."
            );
        }
    }

    public function test_the_i18n_block_carries_the_keys_builder_js_reads(): void
    {
        $block = $this->i18nBlock();

        // کلیدهایی که builder.js مستقیم می‌خواند؛ جاافتادنِ هرکدام یعنی TypeError
        // در لحظهٔ بارگذاری یا وسطِ اولین گفتگو.
        foreach (['fa:', 'building:', 'thinking:', 'steps:', 'err:', 'notConfigured:',
            'limit:', 'busy:', 'left:', 'domainChecking:', 'domainFree:',
            'domainTaken:', 'saved:', 'currency:', 'faNum:'] as $key) {
            $this->assertStringContainsString($key, $block, "کلیدِ $key در AIB_I18N نیست.");
        }
    }

    public function test_the_widget_points_at_live_routes_and_loads_builder_js(): void
    {
        $html = $this->get('/services/site-builder')->assertOk()->getContent();

        // ویجت بدونِ این data-attribute ها هیچ تماسی نمی‌تواند بگیرد
        $this->assertStringContainsString('data-chat="', $html);
        $this->assertStringContainsString('data-stream="', $html);
        $this->assertStringContainsString('data-save="', $html);
        $this->assertStringContainsString('data-domaincheck="', $html);
        $this->assertStringContainsString('assets/js/builder.js', $html);
    }

    public function test_the_i18n_block_carries_the_wizard_and_domain_keys(): void
    {
        $block = $this->i18nBlock();

        // ویزاردِ شناخت + استریم + گاردِ ir. — جاافتادنِ هرکدام یعنی ویجتِ نصفه
        foreach (['qs:', 'colors:', 'skip:', 'sum:', 'writing:', 'unsold:', 'noIr:', 'domainUnknown:'] as $key) {
            $this->assertStringContainsString($key, $block, "کلیدِ $key در AIB_I18N نیست.");
        }

        // فهرستِ پسوندهای فروخته‌نشدنی باید واقعاً ir را داشته باشد
        $this->assertMatchesRegularExpression('~unsold:\s*\[[^\]]*"ir"~', $block);
    }

    public function test_the_stream_route_exists_in_all_three_locales(): void
    {
        foreach (['builder.stream', 'en.builder.stream', 'tr.builder.stream'] as $name) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($name),
                "روتِ $name ثبت نشده — builder.js بدونِ آن به مسیرِ کندِ JSON می‌افتد."
            );
        }
    }

    public function test_the_stream_endpoint_speaks_sse_and_reports_missing_key_in_band(): void
    {
        config(['services.gapgpt.key' => null]);

        $res = $this->post('/api/builder/stream', ['session' => 'sb-test', 'message' => 'hi']);

        $res->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $res->headers->get('Content-Type'));

        // خطا باید داخلِ خودِ استریم بیاید — قراردادِ builder.js پاکتِ done است
        $body = $res->streamedContent();
        $this->assertStringContainsString('"done":true', $body);
        $this->assertStringContainsString('not_configured', $body);
    }

    /**
     * ادعاها روی خودِ فایلِ JS — تنها لایه‌ای که این رفتارها در آن زندگی می‌کنند.
     * همان قاعدهٔ ToolIpPageTest: خرابیِ جاوااسکریپتی هیچ ردِ سروری ندارد.
     */
    public function test_builder_js_streams_first_falls_back_and_guards_unsold_tlds(): void
    {
        $js = file_get_contents(public_path('assets/js/builder.js'));

        // اول SSE (قاعدهٔ ثبت‌شده: پشتِ Cloudflare درخواستِ بی‌خروجی ۵۰۴ می‌گیرد)
        $this->assertStringContainsString('root.dataset.stream', $js);
        // و اگر استریم در دسترس نبود، به مسیرِ JSONِ قدیمی برگردد — نه صفحهٔ مرده
        $this->assertStringContainsString('root.dataset.chat', $js);

        // گاردِ پسوندِ فروخته‌نشدنی پیش از هر تماسِ سرور
        $this->assertStringContainsString('unsoldTld', $js);

        // ویزاردِ چندسؤالی پیش از اولین ساخت
        $this->assertStringContainsString('askNext', $js);

        // پاپ‌آپِ شناور: درگ و کوچک‌سازی
        $this->assertStringContainsString('pointerdown', $js);
        $this->assertStringContainsString('aib-pop-min', $js);

        // «۱۰۰٪ ولی هنوز منتظر» ممنوع — خزشِ شبیه‌سازی هرگز از ۹۵ نگذرد
        $this->assertStringContainsString('Math.min(95', $js);
    }
}
