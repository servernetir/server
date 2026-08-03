<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * شمارهٔ پشتیبانی باید **زبان‌محور** باشد.
 *
 * 🔴 چرا: شمارهٔ `021` را مشتری خارجی نمی‌تواند بگیرد (کد کشور ندارد) و شمارهٔ
 * آمریکایی برای مشتری ایرانی هم گران است هم بی‌اعتمادکننده. هر دو حالت یعنی
 * تماسی که هرگز برقرار نمی‌شود — و این تنها راه تماس مستقیم روی سایت است.
 *
 * ⚠️ این تست عمداً **مقدارِ واقعیِ رندرشده** را می‌سنجد نه کدِ ۲۰۰. درسِ
 * گران‌قیمتِ این پروژه: صفحه بارها ۲۰۰ داده و محتوایش غلط بوده.
 */
class SupportPhoneLocaleTest extends TestCase
{
    private const FA = '021-71057757';

    private const FA_LINK = '+982171057757';

    private const INTL = '+1 (716) 666 0425';

    private const INTL_LINK = '+17166660425';

    protected function setUp(): void
    {
        parent::setUp();

        // مقادیر را تثبیت می‌کنیم تا تست به `.env` ماشین وابسته نباشد
        config()->set('servernet.contact.phone', self::FA);
        config()->set('servernet.contact.phone_link', self::FA_LINK);
        config()->set('servernet.contact.phone_intl', self::INTL);
        config()->set('servernet.contact.phone_intl_link', self::INTL_LINK);
    }

    // ═══════════ خودِ کمک‌تابع ═══════════

    public function test_persian_gets_the_tehran_landline(): void
    {
        app()->setLocale('fa');

        $c = site_contact();

        $this->assertSame(self::FA, $c['phone']);
        $this->assertSame(self::FA_LINK, $c['phone_link']);
    }

    public function test_english_and_turkish_get_the_international_number(): void
    {
        foreach (['en', 'tr'] as $loc) {
            app()->setLocale($loc);

            $c = site_contact();

            $this->assertSame(self::INTL, $c['phone'], "زبان {$loc}");
            $this->assertSame(self::INTL_LINK, $c['phone_link'], "زبان {$loc}");
        }
    }

    /** زبانِ صریح باید بر زبانِ اپ بچربد — همان چیزی که ChatController لازم دارد */
    public function test_an_explicit_locale_overrides_the_app_locale(): void
    {
        app()->setLocale('fa');

        $this->assertSame(self::INTL, site_contact('en')['phone']);
        $this->assertSame(self::FA, site_contact('fa')['phone']);
    }

    /**
     * 🔴 نبودِ کلیدِ بین‌المللی نباید صفحه را بی‌شماره کند.
     *
     * اگر روزی کسی `phone_intl` را از config بردارد، صفحهٔ انگلیسی باید به
     * شمارهٔ فارسی برگردد — نه اینکه رشتهٔ خالی چاپ کند و لینکِ `tel:` بشکند.
     */
    public function test_a_missing_international_number_falls_back_instead_of_emptying(): void
    {
        config()->set('servernet.contact.phone_intl', null);
        config()->set('servernet.contact.phone_intl_link', null);
        app()->setLocale('en');

        $c = site_contact();

        $this->assertSame(self::FA, $c['phone']);
        $this->assertSame(self::FA_LINK, $c['phone_link']);
    }

    // ═══════════ صفحهٔ واقعی ═══════════

    public function test_the_persian_homepage_renders_the_tehran_number(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(self::FA, $html);
        $this->assertStringContainsString('tel:'.self::FA_LINK, $html);
        $this->assertStringNotContainsString(self::INTL_LINK, $html,
            'شمارهٔ آمریکایی نباید هیچ‌جای صفحهٔ فارسی باشد');
    }

    public function test_the_english_homepage_renders_the_international_number(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('tel:'.self::INTL_LINK, $html);
        $this->assertStringNotContainsString('tel:'.self::FA_LINK, $html,
            'شمارهٔ ۰۲۱ برای مخاطب خارجی قابل شماره‌گیری نیست');
    }

    public function test_the_turkish_homepage_renders_the_international_number(): void
    {
        $html = $this->get('/tr')->assertOk()->getContent();

        $this->assertStringContainsString('tel:'.self::INTL_LINK, $html);
        $this->assertStringNotContainsString('tel:'.self::FA_LINK, $html);
    }

    /** دادهٔ ساختاریافته هم باید همان شمارهٔ همان زبان را بگوید */
    public function test_the_json_ld_telephone_follows_the_locale(): void
    {
        $this->assertStringContainsString(self::FA, $this->get('/')->getContent());

        $en = $this->get('/en')->getContent();
        $this->assertMatchesRegularExpression(
            '~"telephone"\s*:\s*"'.preg_quote(self::INTL, '~').'"~',
            $en,
            'schema صفحهٔ انگلیسی باید شمارهٔ بین‌المللی بدهد'
        );
    }

    /** صفحهٔ تماس هم همان قاعده — این صفحه‌ای است که مشتری عمداً بازش می‌کند */
    public function test_the_contact_page_follows_the_locale(): void
    {
        $this->assertStringContainsString('tel:'.self::FA_LINK, $this->get('/contact')->assertOk()->getContent());
        $this->assertStringContainsString('tel:'.self::INTL_LINK, $this->get('/en/contact')->assertOk()->getContent());
    }
}
