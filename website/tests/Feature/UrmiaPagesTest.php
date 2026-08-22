<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * بخشِ محلی ارومیه — /urmia/*
 *
 * معیارهای پذیرشِ پنلِ مهاجرت را قفل می‌کند:
 *  - هر صفحهٔ اصلی ۲۰۰ می‌دهد، H1 یکتا دارد و کم‌تر از ۸۰۰ کلمه نیست
 *  - نسخهٔ en/tr وجود ندارد (۴۰۴) — استثنای عمدی از closureِ سه‌زبانه
 *  - در sitemap هست، و هیچ URL غیرفارسیِ /urmia در sitemap نیست
 *  - hreflangِ en/tr روی این صفحات اعلام نمی‌شود (گاردِ faOnly در layout)
 *  - ProfessionalService و FAQPage schema رندر می‌شوند
 */
class UrmiaPagesTest extends TestCase
{
    public function test_every_core_page_returns_200_with_a_unique_h1(): void
    {
        $h1s = [];

        $this->get('/urmia')->assertOk();

        foreach (array_keys((array) config('urmia.pages')) as $slug) {
            $res = $this->get('/urmia/'.$slug);
            $res->assertOk();

            preg_match('~<h1[^>]*>(.*?)</h1>~us', $res->getContent(), $m);
            $this->assertNotEmpty($m[1] ?? '', "صفحهٔ $slug H1 ندارد");
            $h1 = trim(strip_tags($m[1]));
            $this->assertNotContains($h1, $h1s, "H1 تکراری: $h1");
            $h1s[] = $h1;
        }
    }

    public function test_every_core_page_has_at_least_800_persian_words(): void
    {
        foreach (array_keys((array) config('urmia.pages')) as $slug) {
            $html = $this->get('/urmia/'.$slug)->getContent();

            // فقط محتوای اصلی، بی‌هدر و فوتر — معیارِ پنل دربارهٔ محتوای صفحه است
            preg_match('~<main[^>]*>(.*)</main>~us', $html, $m);
            $body = strip_tags($m[1] ?? $html);

            $words = word_count_fa($body);
            $this->assertGreaterThanOrEqual(800, $words, "صفحهٔ $slug فقط $words کلمه دارد (کمینه ۸۰۰)");
        }
    }

    public function test_non_fa_versions_are_404(): void
    {
        // استثنای عمدی از قراردادِ سه‌زبانه — دلیل در سربرگِ config/urmia.php
        $this->get('/en/urmia')->assertNotFound();
        $this->get('/tr/urmia')->assertNotFound();
        $this->get('/en/urmia/web-design')->assertNotFound();
        $this->get('/tr/urmia/web-design')->assertNotFound();
        $this->get('/en/urmia/cities/khoy')->assertNotFound();
    }

    public function test_unknown_slugs_are_404_not_500(): void
    {
        $this->get('/urmia/no-such-page')->assertNotFound();
        $this->get('/urmia/cities/no-such-city')->assertNotFound();
    }

    public function test_city_pages_render_with_unique_intro(): void
    {
        $seen = [];
        foreach (array_keys((array) config('urmia.cities')) as $slug) {
            $res = $this->get('/urmia/cities/'.$slug);
            $res->assertOk();

            // متنِ یکتای شهر واقعاً رندر شده باشد — نه فقط قالبِ مشترک
            $first = config('urmia.cities.'.$slug.'.p.0');
            $res->assertSee(mb_substr($first, 0, 40), false);

            $this->assertNotContains($first, $seen, "متنِ شهر $slug کپیِ شهر دیگری است");
            $seen[] = $first;
        }
    }

    public function test_sitemap_lists_urmia_pages_and_only_the_fa_versions(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(route('urmia.hub'), $xml);
        $this->assertStringContainsString(route('urmia.page', 'web-design'), $xml);
        $this->assertStringContainsString(route('urmia.city', 'khoy'), $xml);

        // معیارِ پذیرشِ پنل: هیچ URL غیرفارسیِ زیرِ /urmia در sitemap نباشد
        $this->assertStringNotContainsString('/en/urmia', $xml);
        $this->assertStringNotContainsString('/tr/urmia', $xml);
    }

    public function test_fa_only_pages_do_not_declare_en_or_tr_alternates(): void
    {
        $html = $this->get('/urmia/web-design')->getContent();

        $this->assertStringNotContainsString('hreflang="en"', $html);
        $this->assertStringNotContainsString('hreflang="tr"', $html);
        $this->assertStringContainsString('hreflang="fa"', $html);

        // و صفحاتِ عادی همچنان هر سه را دارند — گاردِ faOnly نشتی نکرده باشد
        $home = $this->get('/')->getContent();
        $this->assertStringContainsString('hreflang="en"', $home);
        $this->assertStringContainsString('hreflang="tr"', $home);
    }

    public function test_schema_blocks_render_without_blade_eating_context(): void
    {
        $html = $this->get('/urmia/web-design')->getContent();

        $this->assertStringContainsString('"ProfessionalService"', $html);
        $this->assertStringContainsString('"FAQPage"', $html);
        $this->assertStringContainsString('"BreadcrumbList"', $html);
        // «@context» نباید توسط Blade بلعیده شده باشد
        $this->assertStringContainsString('"@context"', $html);
    }

    public function test_no_placeholder_leaks_when_phone_and_address_are_empty(): void
    {
        // تلفن/نشانی از تماسِ فارسیِ سایت می‌آید (خواستِ مدیر)؛ این تست هر دو
        // منبع را خالی می‌کند تا ثابت شود در نبودشان هیچ جای‌نگهداری نمایش
        // داده نمی‌شود (قاعدهٔ /about) و schema هم فیلد خالی ندارد.
        config([
            'urmia.identity.phone'          => null,
            'urmia.identity.address'        => null,
            'servernet.contact.phone'       => null,
            'servernet.contact.phone_link'  => null,
        ]);

        $html = $this->get('/urmia')->getContent();

        $this->assertStringNotContainsString('URMIA_', $html);
        $this->assertStringNotContainsString('"telephone":""', $html);

        // فقط محتوای اصلی — هدر/فوترِ سراسری tel: تماسِ عمومی سایت را دارند و ربطی
        // به شمارهٔ ۰۴۴ ندارند
        preg_match('~<main[^>]*>(.*)</main>~us', $html, $m);
        $this->assertStringNotContainsString('tel:', $m[1] ?? '');
    }

    public function test_homepage_links_to_the_hub_from_body_text_only_in_fa(): void
    {
        $this->get('/')->assertSee('href="'.route('urmia.hub').'"', false);
        // نسخهٔ انگلیسیِ خانه نباید به صفحهٔ فقط‌فارسی لینک بدهد
        $this->get('/en')->assertDontSee('/urmia', false);
    }
}
