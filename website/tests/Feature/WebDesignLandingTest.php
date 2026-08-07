<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * صفحهٔ فرودِ /webdesign — مقصدِ لینکِ لینکدین و اینستاگرام.
 *
 * ⚠️ این صفحه از **هیچ‌جای سایت لینک نمی‌شود** (عمداً در منو نیست). یعنی هیچ
 * کاربری تصادفی رویش نمی‌افتد و هیچ‌کس خرابی‌اش را گزارش نمی‌کند. اگر روزی
 * بشکند، ماه‌ها بی‌صدا شکسته می‌ماند در حالی که کارفرما لینکش را در پروفایلش
 * پخش کرده. پس تنها محافظش همین فایل است.
 *
 * ⚠️ «کدِ ۲۰۰» این‌جا کافی نیست: کلِ ارزشِ صفحه در **محتوا**ست — کلیدواژهٔ
 * محلی، نامِ شخصی، و دادهٔ ساختاریافته. صفحه‌ای که سالم رندر شود ولی «ارومیه»
 * در آن نباشد، دقیقاً همان‌قدر بی‌فایده است که ۵۰۰ بدهد.
 */
class WebDesignLandingTest extends TestCase
{
    /** هر سه زبان — چون روت داخلِ closureِ `$site` است و باید هر سه ساخته شوند */
    private const URLS = ['fa' => '/webdesign', 'en' => '/en/webdesign', 'tr' => '/tr/webdesign'];

    public function test_the_page_renders_in_all_three_languages(): void
    {
        foreach (self::URLS as $locale => $url) {
            // ⚠️ بدونِ escape نسنج: عنوانِ انگلیسی «&» دارد و در HTML به
            //    `&amp;` تبدیل می‌شود — تستِ خام این‌جا الکی قرمز می‌شد.
            $this->get($url)->assertOk()
                ->assertSee(config('webdesign.meta.title.'.$locale));
        }
    }

    /**
     * 🔴 کلیدواژه‌های محلی باید در **متنِ صفحه** باشند، نه فقط در تگِ عنوان.
     *
     * کارفرما این صفحه را دقیقاً برای «طراحی سایت در ارومیه» می‌خواهد. کلیدواژه‌ای
     * که فقط در `<title>` باشد و در بدنه نیاید، عملاً رتبه نمی‌گیرد — و چون صفحه
     * ۲۰۰ می‌دهد و ظاهرش هم سالم است، هیچ‌کس متوجهِ نبودنش نمی‌شود.
     */
    public function test_the_persian_page_carries_the_local_seo_keywords(): void
    {
        $html = $this->get('/webdesign')->assertOk()->getContent();

        foreach (['طراحی سایت در ارومیه', 'سرور در ارومیه', 'طراحی فرایند در ارومیه'] as $kw) {
            $this->assertStringContainsString($kw, $html,
                "کلیدواژهٔ محلی «{$kw}» در متنِ صفحه نیست — کلِ هدفِ این صفحه همین است");
        }
    }

    /** نامِ شخصیِ کارفرما باید دیده شود — درخواستِ صریحش بود */
    public function test_the_personal_name_is_on_the_page(): void
    {
        $this->get('/webdesign')->assertOk()->assertSee(config('webdesign.person_fa'), false);
        $this->get('/en/webdesign')->assertOk()->assertSee(config('webdesign.person'), false);
    }

    /**
     * 🔴 در منو نباشد، ولی در نقشهٔ سایت **باشد**.
     *
     * این دو را یک‌بار قاطی کردیم و نتیجه‌اش صفحهٔ یتیمِ ایندکس‌نشده بود.
     */
    public function test_it_is_absent_from_the_menu_but_present_in_the_sitemap(): void
    {
        $this->assertStringNotContainsString('/webdesign',
            json_encode(config('servernet.nav'), JSON_UNESCAPED_UNICODE) ?: '',
            'این صفحه نباید در منوی اصلی باشد — کارفرما صریح گفت فقط از لینکدین/اینستاگرام');

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach (['/webdesign', '/en/webdesign', '/tr/webdesign'] as $path) {
            $this->assertStringContainsString($path.'<', $xml,
                "«{$path}» در نقشهٔ سایت نیست — صفحه‌ای که از هیچ‌جا لینک نمی‌شود، بی‌نقشه ایندکس نمی‌شود");
        }
    }

    /**
     * دادهٔ ساختاریافته باید JSONِ **معتبر** باشد و ارومیه را به‌عنوان
     * `areaServed` نام ببرد. JSON-LDِ خراب بی‌صدا نادیده گرفته می‌شود.
     */
    public function test_the_structured_data_is_valid_and_names_the_city(): void
    {
        $html = $this->get('/webdesign')->assertOk()->getContent();

        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);

        $this->assertGreaterThanOrEqual(2, count($m[1]), 'باید دو بلوکِ JSON-LD باشد: ProfessionalService و FAQPage');

        $types = [];

        foreach ($m[1] as $json) {
            $data = json_decode($json, true);
            $this->assertIsArray($data, 'JSON-LD نامعتبر است: '.mb_substr($json, 0, 120));
            $types[] = $data['@type'] ?? '';

            if (($data['@type'] ?? '') === 'ProfessionalService') {
                $this->assertStringContainsString('ارومیه', json_encode($data, JSON_UNESCAPED_UNICODE),
                    'areaServed باید ارومیه را نام ببرد — سئوی محلی بدونِ آن ناقص است');
            }
        }

        $this->assertContains('ProfessionalService', $types);
        $this->assertContains('FAQPage', $types);
    }

    /**
     * ⚠️ صفحه نباید padding-top خودش را بگذارد — `#main` جبرانِ هدرِ ثابت را
     * سراسری انجام می‌دهد و عددِ دوم یعنی فاصلهٔ دوبرابر.
     * (قانونِ `FixedHeaderOffsetTest`؛ این‌جا فقط برای همین ویو دوباره سنجیده
     * می‌شود چون صفحه‌ای است که کسی نگاهش نمی‌کند.)
     */
    public function test_the_view_does_not_add_its_own_header_offset(): void
    {
        $blade = file_get_contents(resource_path('views/pages/webdesign.blade.php'));

        $this->assertDoesNotMatchRegularExpression('~padding-top\s*:\s*1[0-9]{2}~', $blade,
            'جبرانِ دستیِ هدر نگذار — #main خودش این کار را می‌کند');
    }
}
