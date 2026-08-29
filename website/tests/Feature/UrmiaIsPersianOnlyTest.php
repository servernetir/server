<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحاتِ ارومیه فقط فارسی‌اند — و «فقط فارسی» یعنی **سه** چیز با هم.
 *
 * ═══ تصمیم و تاریخچه‌اش ═══
 *
 * مرداد ۱۴۰۵ به خواستِ صریحِ مدیر سه‌زبانه شد («هر صفحه‌ای en/tr نداشت درستش
 * کن»). ممیزی نهم (قلم ۲) آن را با سه دلیلِ اندازه‌گیری‌شده برگرداند و مدیر
 * تأیید کرد:
 *
 *   · «طراحی سایت در خوی» به انگلیسی/ترکی تقاضای جست‌وجوی ~صفر دارد
 *   · خدمت ذاتاً محلی و فارسی‌زبان است
 *   · ۳۷۲–۶۵۹ کلمه، **نازک‌تر از نسخهٔ فارسی** (۳۵۰–۱۰۳۸) — سطحِ محتوای
 *     نازکِ کلِ سایت را سه برابر کرده بود (۲۹ → ۸۷ صفحه)
 *
 * ═══ 🔴 چرا سه ادعا و نه یکی ═══
 *
 * برداشتنِ صفحه به‌تنهایی وضع را **بدتر** می‌کند. هر سه باید با هم درست باشند،
 * وگرنه نتیجه از نگه‌داشتنِ خودِ صفحات هم بدتر است:
 *
 *   ۱. مسیر واقعاً برود (۴۱۰، نه فقط پنهان‌شده در منو)
 *   ۲. نقشهٔ سایت آن نشانی را نبرد — نشانیِ ۴۱۰ در sitemap یعنی گوگل هر بار
 *      می‌رود، خطا می‌گیرد و بودجهٔ خزش را همان‌جا می‌سوزاند
 *   ۳. صفحهٔ فارسی به آن‌ها `alternate` ندهد — hreflangِ زنده به سمتِ ۴۱۰ یک
 *      حلقهٔ خطای خزش می‌سازد
 *
 * ⚠️ چرا ۴۱۰ و نه `noindex`: `noindex` صفحه را در خزش، لینکِ داخلی و خوشهٔ
 * hreflang نگه می‌دارد و برای همیشه نگهداری می‌خواهد. این صفحات چند روزه‌اند و
 * بک‌لینک ندارند — ۴۱۰ هزینه‌ای ندارد و به گوگل می‌گوید «عمدی بود، برنگرد».
 *
 * ⚠️ نسخهٔ **فارسی** دست‌نخورده می‌مانَد: نقشهٔ ۳۰۱ِ مهاجرتِ servernet.ir به
 * همان آدرس‌ها اشاره می‌کند.
 */
class UrmiaIsPersianOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** یک نمونه از هر سه شکلِ مسیر — هاب، صفحه، شهر. */
    private function paths(): array
    {
        return ['/urmia', '/urmia/web-design', '/urmia/cities/khoy'];
    }

    /** ادعای ۱ — مسیرِ خارجی واقعاً رفته، نه صرفاً از منو پنهان شده. */
    public function test_every_foreign_urmia_path_is_gone_not_merely_hidden(): void
    {
        foreach ($this->paths() as $p) {
            foreach (['en', 'tr'] as $loc) {
                $this->get('/'.$loc.$p)->assertStatus(410);
            }
        }
    }

    /** و نسخهٔ فارسی دست‌نخورده — مقصدِ ۳۰۱های مهاجرتِ .ir همین‌هاست. */
    public function test_the_persian_pages_are_untouched(): void
    {
        foreach ($this->paths() as $p) {
            $this->get($p)->assertOk();
        }
    }

    /**
     * ادعای ۲ — نقشهٔ سایت هیچ نشانیِ ۴۱۰ای نبرد.
     *
     * ⚠️ ادعا روی **نبودنِ** en/tr است **و** بودنِ fa. بی‌نیمهٔ دوم، حذفِ کاملِ
     * ارومیه از نقشه هم سبز می‌شد — و آن یک خرابیِ متفاوت است.
     */
    public function test_the_sitemap_carries_only_the_persian_urls(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('/urmia', $xml, 'ارومیه کلاً از نقشه غیب شد');
        $this->assertStringNotContainsString('/en/urmia', $xml);
        $this->assertStringNotContainsString('/tr/urmia', $xml);
    }

    /** ادعای ۳ — صفحهٔ فارسی به نسخه‌های رفته `alternate` ندهد. */
    public function test_the_persian_page_no_longer_advertises_foreign_alternates(): void
    {
        foreach ($this->paths() as $p) {
            $html = $this->get($p)->assertOk()->getContent();

            $this->assertStringNotContainsString('hreflang="en"', $html,
                "«{$p}» هنوز alternate انگلیسی می‌دهد — hreflang به سمتِ ۴۱۰");
            $this->assertStringNotContainsString('hreflang="tr"', $html);

            // و hreflangِ فارسیِ خودش بماند، وگرنه صفحه بی‌هیچ alternateای می‌شود
            $this->assertStringContainsString('hreflang="fa"', $html);
        }
    }

    /**
     * ⚠️ اسلاگِ ناشناخته در زبانِ خارجی هم باید ۴۱۰ بدهد، نه ۴۰۴.
     *
     * روتِ ۴۱۰ الگوی اسلاگ را نگه داشته، پس هر چیزی که شکلِ اسلاگ داشته باشد
     * همان پاسخ را می‌گیرد. اگر روزی `where()` برداشته شود این تست می‌شکند —
     * و باید بشکند، چون آن‌وقت `/en/urmia/<هرچیز>` هم ۴۱۰ می‌شد.
     */
    public function test_an_unknown_foreign_slug_is_gone_too(): void
    {
        $this->get('/en/urmia/no-such-page')->assertStatus(410);
        $this->get('/tr/urmia/cities/no-such-city')->assertStatus(410);
    }
}
