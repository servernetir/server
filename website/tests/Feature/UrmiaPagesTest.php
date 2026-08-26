<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * بخشِ محلی ارومیه — /urmia/* (سه‌زبانه از مرداد ۱۴۰۵)
 *
 * قراردادها:
 *  - فارسی: هر صفحهٔ اصلی ۲۰۰، H1 یکتا، و کم‌تر از ۸۰۰ کلمه نیست (معیارِ پنل)
 *  - en/tr: ۲۰۰ می‌دهند و محتوای **واقعاً ترجمه‌شده** دارند، نه فارسی —
 *    همان باگی که این بخش را مدتی فقط‌فارسی نگه داشته بود (panel-preview)
 *  - hreflangِ هر سه زبان اعلام می‌شود و sitemap هر سه نسخه را دارد
 *  - schema (ProfessionalService/FAQPage/BreadcrumbList) در هر زبان رندر می‌شود
 *  - بدونِ تلفن/نشانی هیچ جای‌نگهداری نشت نمی‌کند
 */
class UrmiaPagesTest extends TestCase
{
    /** آیا رشته حرفِ فارسی/عربی دارد؟ */
    private function hasPersian(string $s): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $s);
    }

    /** متنِ داخلِ <main> بدونِ تگ‌ها و بدونِ بلوک‌های JSON-LD. */
    private function mainText(string $html): string
    {
        preg_match('~<main[^>]*>(.*)</main>~us', $html, $m);
        $body = preg_replace('~<script[^>]*>.*?</script>~us', ' ', $m[1] ?? $html);

        return strip_tags($body);
    }

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

    /*
    | en/tr باید ۲۰۰ بدهند و ترجمهٔ واقعی داشته باشند. سنجهٔ «واقعی»:
    | H1 و lead هیچ حرفِ فارسی ندارند، و H1 با نسخهٔ فارسی فرق دارد.
    | (متنِ کاملِ main ممکن است نامِ برند/شمارهٔ فارسیِ تلفن را داشته باشد،
    | پس ادعا روی عناصرِ محتواییِ اصلی است نه کلِ صفحه.)
    */
    public function test_en_and_tr_versions_render_with_genuinely_translated_content(): void
    {
        foreach (['en', 'tr'] as $lang) {
            // هاب
            $html = $this->get("/$lang/urmia")->assertOk()->getContent();
            preg_match('~<h1[^>]*>(.*?)</h1>~us', $html, $m);
            $h1 = trim(strip_tags($m[1] ?? ''));
            $this->assertNotEmpty($h1, "هابِ $lang H1 ندارد");
            $this->assertFalse($this->hasPersian($h1), "H1 هابِ $lang هنوز فارسی است: $h1");

            // همهٔ صفحات خدمت
            foreach (array_keys((array) config('urmia.pages')) as $slug) {
                $res = $this->get("/$lang/urmia/$slug");
                $res->assertOk();
                preg_match('~<h1[^>]*>(.*?)</h1>~us', $res->getContent(), $m);
                $h1 = trim(strip_tags($m[1] ?? ''));
                $this->assertFalse($this->hasPersian($h1), "H1 صفحهٔ $lang/$slug فارسی است: $h1");

                $fa = config("urmia.pages.$slug.h1");
                $this->assertNotSame($fa, $h1, "H1 صفحهٔ $lang/$slug همان فارسی است");
            }
        }
    }

    public function test_en_service_page_body_is_substantial_and_not_persian(): void
    {
        foreach (['en', 'tr'] as $lang) {
            $html = $this->get("/$lang/urmia/web-design")->assertOk()->getContent();
            $text = $this->mainText($html);

            // متنِ اصلی به‌جز نامِ شرکت/شماره‌ها نباید فارسی باشد؛ آستانهٔ ۲٪
            $total   = max(1, mb_strlen(preg_replace('/\s+/u', '', $text)));
            $persian = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
            $this->assertLessThan(0.02, $persian / $total,
                "متنِ $lang/web-design هنوز ".round(100 * $persian / $total)."٪ فارسی دارد");

            // و محتوای واقعی است، نه اسکلتِ خالی
            $this->assertGreaterThan(1200, mb_strlen($text), "متنِ $lang/web-design خیلی کوتاه است");
        }
    }

    public function test_city_pages_in_en_and_tr_use_latin_city_names(): void
    {
        $html = $this->get('/en/urmia/cities/khoy')->assertOk()->getContent();
        $this->assertStringContainsString('Khoy', $html);
        $this->assertStringNotContainsString('طراحی سایت در خوی', $html);

        $html = $this->get('/tr/urmia/cities/maku')->assertOk()->getContent();
        $this->assertStringContainsString('Makü', $html);
    }

    public function test_unknown_slugs_are_404_not_500(): void
    {
        $this->get('/urmia/no-such-page')->assertNotFound();
        $this->get('/urmia/cities/no-such-city')->assertNotFound();
        $this->get('/en/urmia/no-such-page')->assertNotFound();
        $this->get('/tr/urmia/cities/no-such-city')->assertNotFound();
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

    public function test_sitemap_lists_all_three_locales_of_urmia_pages(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString(route('urmia.hub'), $xml);
        $this->assertStringContainsString(route('urmia.page', 'web-design'), $xml);
        $this->assertStringContainsString(route('urmia.city', 'khoy'), $xml);
        $this->assertStringContainsString(route('en.urmia.hub'), $xml);
        $this->assertStringContainsString(route('tr.urmia.page', 'web-design'), $xml);
    }

    public function test_urmia_pages_declare_all_three_hreflang_alternates(): void
    {
        $html = $this->get('/urmia/web-design')->getContent();

        $this->assertStringContainsString('hreflang="fa"', $html);
        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('hreflang="tr"', $html);
        $this->assertStringContainsString('/en/urmia/web-design', $html);
    }

    public function test_schema_blocks_render_without_blade_eating_context(): void
    {
        foreach (['/urmia/web-design', '/en/urmia/web-design', '/tr/urmia/web-design'] as $url) {
            $html = $this->get($url)->getContent();

            $this->assertStringContainsString('"ProfessionalService"', $html, $url);
            $this->assertStringContainsString('"FAQPage"', $html, $url);
            $this->assertStringContainsString('"BreadcrumbList"', $html, $url);
            // «@context» نباید توسط Blade بلعیده شده باشد
            $this->assertStringContainsString('"@context"', $html, $url);
        }
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

    /*
    | جایگزینیِ placeholderها باید کامل باشد — %CITY% یا %BRAND%ِ جامانده یعنی
    | یک مسیرِ رندر از overlay رد شده. روی هر سه زبان و هر سه نوع صفحه.
    */
    public function test_no_raw_placeholders_leak_into_any_locale(): void
    {
        foreach (['', '/en', '/tr'] as $p) {
            foreach (["$p/urmia", "$p/urmia/web-design", "$p/urmia/cities/khoy"] as $url) {
                $html = $this->get($url)->getContent();
                $this->assertDoesNotMatchRegularExpression('/%(CITY|BRAND|COMPANY|REG|SINCE)%/', $html, $url);
            }
        }
    }

    public function test_homepage_links_to_the_hub_and_footer_links_in_every_locale(): void
    {
        // پلِ متنیِ خانه فقط فارسی است (محتوای تحریری)، ولی فوتر در هر سه زبان
        // به نسخهٔ همان زبان لینک می‌دهد.
        $this->get('/')->assertSee('href="'.route('urmia.hub').'"', false);
        $this->get('/en')->assertSee('href="'.route('en.urmia.hub').'"', false);
        $this->get('/tr')->assertSee('href="'.route('tr.urmia.hub').'"', false);
    }
}
