<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 ممیزی نهم، یافتهٔ ۱ — نامِ فارسی در عنوانِ ۱۳۴ صفحهٔ سفارشِ en/tr.
 *
 *     /en/order/backup-1 → Buy هاست بکاپ — BK-100 — from €0.63/mo
 *
 * نمونه‌گیری ممیزی: ۳۰ از ۳۰ صفحه، ۱۰۰٪.
 *
 * ⚠️ چرا هشت دورِ ممیزی ندیدش: تا دورِ نهم هیچ‌وقت به نسخه‌های زبانی نگاه
 * نشده بود. بقیهٔ سایتِ انگلیسی واقعاً ترجمه است (۰ از ۴۰ صفحهٔ غیرسفارش
 * نشت داشت) — پس هیچ سیگنالِ کلی‌ای وجود نداشت.
 *
 * ادعا **دقیقاً همان معیارِ پذیرشِ ممیزی** است: صفر کدپوینت U+0600–U+06FF
 * در `<title>` و `<h1>` و `schema.name` — نه «صفحه ۲۰۰ داد».
 */
class ProductNameIsLocalizedTest extends TestCase
{
    use RefreshDatabase;

    /** بازهٔ عربی/فارسی یونیکد */
    private function persianCount(string $s): int
    {
        return preg_match_all('/[\x{0600}-\x{06FF}]/u', $s);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'name'    => 'هاست بکاپ — BK-100',
            'name_en' => 'Backup Hosting BK-100',
            'name_tr' => 'Yedekleme Hosting BK-100',
            'slug'    => 'backup-1',
            'category' => 'shared', 'group' => 'backup',
            'price' => 250000, 'price_eur' => 63, 'setup_fee' => 0,
            'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ], $over));
    }

    public function test_the_english_order_page_has_no_persian_anywhere_that_matters(): void
    {
        $p = $this->product();

        $html = $this->get('/en/order/'.$p->slug)->assertOk()->getContent();

        preg_match('#<title>(.*?)</title>#s', $html, $t);
        preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $h);

        $this->assertSame(0, $this->persianCount($t[1] ?? ''),
            'نامِ فارسی در <title> صفحهٔ سفارشِ انگلیسی: '.($t[1] ?? ''));
        $this->assertSame(0, $this->persianCount($h[1] ?? ''),
            'نامِ فارسی در <h1> صفحهٔ سفارشِ انگلیسی: '.($h[1] ?? ''));

        $this->assertStringContainsString('Backup Hosting BK-100', $t[1] ?? '');
    }

    public function test_the_turkish_order_page_is_localized_too(): void
    {
        $p = $this->product();

        $html = $this->get('/tr/order/'.$p->slug)->assertOk()->getContent();
        preg_match('#<title>(.*?)</title>#s', $html, $t);

        $this->assertSame(0, $this->persianCount($t[1] ?? ''), 'نامِ فارسی در عنوانِ ترکی: '.($t[1] ?? ''));
        $this->assertStringContainsString('Yedekleme Hosting BK-100', $t[1] ?? '');
    }

    /**
     * 🔴 اسکیما باید **آینهٔ H1** باشد. اگر یکی ترجمه شود و دیگری نه،
     * ناهمخوانیِ ساختاری می‌سازیم — که از نشتِ اولیه بدتر است، چون گوگل
     * آن را سیگنالِ صفحهٔ دستکاری‌شده می‌خواند.
     */
    public function test_the_schema_name_mirrors_the_visible_h1(): void
    {
        $p = $this->product();

        $html = $this->get('/en/order/'.$p->slug)->assertOk()->getContent();

        preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $h);
        $h1 = trim(strip_tags($h[1] ?? ''));

        $this->assertStringContainsString('Backup Hosting BK-100', $h1);
        $this->assertMatchesRegularExpression('/"name"\s*:\s*"[^"]*Backup Hosting BK-100/', $html,
            'اسکیمای name با H1 نمی‌خوانَد — یک منبع باید هر دو را تغذیه کند');
    }

    /** فارسی دست‌نخورده می‌مانَد. */
    public function test_the_persian_page_still_shows_the_persian_name(): void
    {
        $p = $this->product();

        $html = $this->get('/order/'.$p->slug)->assertOk()->getContent();
        preg_match('#<title>(.*?)</title>#s', $html, $t);

        $this->assertStringContainsString('هاست بکاپ', $t[1] ?? '');
    }

    /**
     * ⚠️ محصولِ بی‌ترجمه باید نامِ فارسی نشان دهد، نه رشتهٔ خالی.
     * عنوانِ خالی از عنوانِ بدخوان بدتر است.
     */
    public function test_a_product_without_a_translation_falls_back_instead_of_going_blank(): void
    {
        $p = $this->product(['name_en' => null, 'name_tr' => null]);

        $this->assertSame('هاست بکاپ — BK-100', $p->displayName('en'));
        $this->assertSame('هاست بکاپ — BK-100', $p->displayName('tr'));
        $this->assertSame('هاست بکاپ — BK-100', $p->displayName('fa'));
    }

    /** «Buy» از عنوان حذف شد — بودجهٔ ~۶۰ کاراکتری را می‌خورد. */
    public function test_the_english_title_does_not_waste_budget_on_buy(): void
    {
        $p = $this->product();
        $html = $this->get('/en/order/'.$p->slug)->assertOk()->getContent();
        preg_match('#<title>(.*?)</title>#s', $html, $t);

        $this->assertStringNotContainsString('Buy ', $t[1] ?? '');
    }
}
