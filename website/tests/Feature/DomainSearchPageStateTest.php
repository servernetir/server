<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ جستجوی دامنه باید بعد از نتیجه، «تمام‌شده» به‌نظر برسد.
 *
 * ═══ دو خرابیِ دیده‌شده روی سایتِ زنده ═══
 *
 * جستجوی `zhina.com` روی پروداکشن ۶۴ ردیفِ درست برگرداند — و زیرِ همان ۶۴
 * ردیف، هم‌زمان دو چیزِ متناقض چاپ می‌شد:
 *
 *   • چرخندهٔ «در حال بررسی پسوندهای بیشتر…» که هرگز خاموش نمی‌شد
 *   • جملهٔ «دامنه‌ای جستجو نکرده‌اید» زیرِ ۶۴ نتیجه
 *
 * هیچ‌کدام کار را نمی‌شکستند، ولی صفحه‌ای که کارش تمام شده ناتمام به‌نظر
 * می‌رسید — و روی صفحهٔ **فروش**، «انگار هنوز دارد کار می‌کند» یعنی کاربری
 * که منتظر می‌مانَد و بعد می‌رود.
 *
 * ═══ 🔴 علتِ اول: تلهٔ `hidden` — سومین بار در این پروژه ═══
 *
 * `hidden` مرورگر یعنی `display:none`، ولی قاعده‌ای **پیش‌فرضِ user-agent**
 * است و هر `display:` نویسنده بر آن می‌چربد. `.dsx-more` تعریفِ
 * `display:flex` دارد، پس `more.hidden = true` اجرا می‌شد و هیچ اثری نداشت.
 *
 * پیش از این `.ad-bulk` و اسکلتِ تقویم در `admin.css` همین را خوردند. هر دو
 * بار یک کامنتِ هشدار نوشته شد و هر دو بار بارِ بعد تکرار شد — چون کامنت
 * اجرا نمی‌شود. این تست اجرا می‌شود.
 *
 * ═══ 🔴 علتِ دوم: عنصری که جاوااسکریپت اصلاً نمی‌شناختش ═══
 *
 * `#dm-idle` در HTML بود و در **هیچ** خطی از اسکریپت ظاهر نمی‌شد. یعنی از
 * روزِ اول هیچ‌وقت پنهان نمی‌شد. `getElementById` نبودن، خطایی تولید نمی‌کند
 * — فقط یک حالتِ نمایشیِ همیشه‌روشن می‌سازد.
 */
class DomainSearchPageStateTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        $res = $this->get('/domains');
        $res->assertOk();

        return (string) $res->getContent();
    }

    /**
     * هر عنصری که با `hidden` خاموش می‌شود و `display:` صریح دارد، باید
     * قاعدهٔ `[hidden]` خودش را داشته باشد.
     *
     * ⚠️ CSS از **خودِ صفحه** خوانده می‌شود، نه از یک رشتهٔ دستی — وگرنه تست
     * چیزی را می‌سنجد که خودش نوشته.
     */
    public function test_a_display_flex_element_that_toggles_with_hidden_has_its_hidden_rule(): void
    {
        $html = $this->page();

        // کامنت‌ها **اول** حذف می‌شوند — تلهٔ ثبت‌شدهٔ همین پروژه: توضیحی که
        // قاعدهٔ حذف‌شده را نقل می‌کند، ادعای تست را الکی سبز می‌کرد.
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $html);

        $this->assertMatchesRegularExpression(
            '/\.dsx-more\s*\{[^}]*display\s*:\s*flex/',
            $css,
            'پیش‌فرضِ تست عوض شده: .dsx-more دیگر display:flex ندارد'
        );

        $this->assertMatchesRegularExpression(
            '/\.dsx-more\[hidden\][^{]*\{[^}]*display\s*:\s*none/',
            $css,
            'چرخندهٔ «در حال بررسی…» با hidden پنهان نمی‌شود چون display:flex بر آن می‌چربد'
        );
    }

    /** حالتِ اولیه باید در جاوااسکریپت **گرفته و خاموش** شود، نه فقط در HTML باشد */
    public function test_the_idle_block_is_actually_wired_into_the_script(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('id="dm-idle"', $html, 'بلوکِ حالتِ اولیه از صفحه حذف شده');

        $this->assertStringContainsString(
            "getElementById('dm-idle')",
            $html,
            'dm-idle در HTML هست ولی اسکریپت هرگز نمی‌گیردش — پس هرگز پنهان نمی‌شود'
        );

        $this->assertMatchesRegularExpression(
            '/idle\s*\)?\s*\{?\s*idle\.hidden\s*=\s*true/',
            $html,
            'هیچ‌جا idle.hidden = true نمی‌شود — «جستجو نکرده‌اید» زیرِ نتیجه‌ها می‌مانَد'
        );
    }

    /** Terminal/pause/error visibility is exercised by the actual Blade JS behavior suite. */
    public function test_the_spinner_starts_hidden_before_any_search(): void
    {
        $html = $this->page();
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $spinner = (new \DOMXPath($document))->query('//*[@id="dm-more"]')->item(0);
        $this->assertNotNull($spinner);
        $this->assertTrue($spinner->hasAttribute('hidden'));
    }
}
