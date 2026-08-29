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



    public function test_unknown_slugs_are_404_not_500(): void
    {
        $this->get('/urmia/no-such-page')->assertNotFound();
        $this->get('/urmia/cities/no-such-city')->assertNotFound();

        /*
        | ⚠️ زیرِ en/tr همه‌چیز ۴۱۰ است، حتی اسلاگِ ناشناخته — و این درست
        | است: **کلِ زیردرخت** برداشته شده، نه چند صفحهٔ مشخص. ۴۰۴ آن‌جا
        | یعنی «شاید فردا بیاید» و خزنده را برمی‌گردانَد.
        */
        $this->get('/en/urmia/no-such-page')->assertStatus(410);
        $this->get('/tr/urmia/cities/no-such-city')->assertStatus(410);
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



    public function test_schema_blocks_render_without_blade_eating_context(): void
    {
        // ⚠️ فقط فارسی: en/tr از ممیزی نهم ۴۱۰ می‌دهند (UrmiaIsPersianOnlyTest)
        foreach (['/urmia/web-design'] as $url) {
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
