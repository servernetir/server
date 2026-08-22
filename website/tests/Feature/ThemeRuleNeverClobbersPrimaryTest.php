<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * قانونِ تمِ روشن نباید دکمهٔ اصلی را بی‌صدا بی‌رنگ کند.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * `html[data-theme="light"] .svc-qbtn{background:#fff}` ویژگیِ (0,2,1) دارد و
 * بر `.svc-qbtn.primary{background:var(--grad);color:#fff}` با (0,2,0) می‌چربد
 * — حتی با اینکه در فایل **بالاتر** نوشته شده.
 *
 * 🔴 نتیجه: دکمهٔ «ورود به سی‌پنل» در تمِ روشن پس‌زمینه‌اش سفید می‌شد ولی
 * `color:#fff` را نگه می‌داشت. آیکن و متنِ سفید روی سفید — کاملاً نامرئی، با
 * کدِ ۲۰۰ و بی‌هیچ خطایی. در تمِ تیره آن قانون اعمال نمی‌شود، پس فقط نیمی از
 * کاربران می‌دیدندش و ماه‌ها ماند.
 *
 * ⚠️ در مرورگرِ واقعی سنجیده شد: پیش از تعمیر `background` و `color` هر دو
 * `rgb(255,255,255)` بودند.
 *
 * ⚠️ این از خانوادهٔ همان «تلهٔ سلکتورِ خام» است که یک بار `header{position:fixed}`
 * کلِ سایت را بهم ریخت: قاعده‌ای که پهن‌تر از هدفش می‌گیرد.
 */
class ThemeRuleNeverClobbersPrimaryTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(public_path('assets/css/panel.css'));
    }

    /** 🔴 قانونِ تمِ روشن باید دکمهٔ اصلی را کنار بگذارد. */
    public function test_the_light_theme_rule_excludes_the_primary_button(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '~html\[data-theme="light"\]\s+\.svc-qbtn:not\(\.primary\)\s*\{~',
            $css,
            'قانونِ تمِ روشنِ .svc-qbtn دیگر .primary را کنار نمی‌گذارد — دکمه در لایت‌مود سفیدِ روی سفید می‌شود'
        );

        $this->assertDoesNotMatchRegularExpression(
            '~html\[data-theme="light"\]\s+\.svc-qbtn\s*\{~',
            $css,
            'قانونِ بی‌قیدِ .svc-qbtn برگشت و دوباره .primary را می‌پوشاند'
        );
    }

    /**
     * ⚠️ و همین قاعده برای هر دکمهٔ اصلیِ دیگری در پنل.
     *
     * هر جا تمِ روشن `background` را روی یک کلاسِ **پایه** ست کند در حالی که
     * حالتِ `.primary` همان کلاس رنگِ متنِ روشن دارد، همین فاجعه تکرار می‌شود.
     */
    public function test_no_light_theme_rule_sets_background_on_a_class_that_has_a_primary_variant(): void
    {
        $css = $this->css();

        // کلاس‌هایی که حالتِ .primary با متنِ سفید دارند
        preg_match_all('~\.([a-z][a-z0-9-]*)\.primary\s*\{[^}]*color:\s*#fff~i', $css, $m);
        $withWhitePrimary = array_unique($m[1]);

        $this->assertNotEmpty($withWhitePrimary, 'هیچ دکمهٔ primaryِ سفیدمتن پیدا نشد — الگو کهنه شده');

        $bad = [];

        foreach ($withWhitePrimary as $cls) {
            // قانونِ تمِ روشن که همان کلاس را **بی‌قید** هدف بگیرد و background بدهد
            if (preg_match('~html\[data-theme="light"\][^{,]*\.'.preg_quote($cls, '~').'\s*\{[^}]*background~i', $css)) {
                $bad[] = $cls;
            }
        }

        $this->assertSame([], $bad,
            'قانونِ تمِ روشن پس‌زمینهٔ این کلاس‌ها را می‌پوشاند در حالی که حالتِ primaryشان متنِ سفید دارد: '
            .implode('، ', $bad));
    }
}
