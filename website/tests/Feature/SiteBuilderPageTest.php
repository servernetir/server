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

        // ویجت بدونِ این سه data-attribute هیچ تماسی نمی‌تواند بگیرد
        $this->assertStringContainsString('data-chat="', $html);
        $this->assertStringContainsString('data-save="', $html);
        $this->assertStringContainsString('data-domaincheck="', $html);
        $this->assertStringContainsString('assets/js/builder.js', $html);
    }
}
