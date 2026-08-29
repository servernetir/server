<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 ممیزی نهم، قلم ۴ — تنزلِ فروشِ اروپا به «حضور و اعتبار».
 *
 * دلیلش حقوقی است نه بازاریابی: بدونِ DPA و مادهٔ ۲۸ GDPR، فروش به مشتریِ
 * اروپایی یک **نقصِ قراردادی روی هر معاملهٔ B2B** است — به‌علاوهٔ نبودِ
 * بیانیهٔ محلِ داده، رضایتِ کوکی، و نوبتِ پشتیبانیِ اروپایی.
 *
 * ⚠️ صفحات و قیمتِ یورویی می‌مانند (سرمایهٔ entity و AEO). فقط مسیرِ پرداخت
 * بسته می‌شود.
 */
class EuropeSalesDowngradedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ نرخِ یورو لازم است: `OrderSummaryController::schema()` بی‌نرخ
     * **زودهنگام برمی‌گردد** و اصلاً بلوکِ offers نمی‌سازد — یعنی تست
     * چیزی برای سنجیدن ندارد و شکستش گمراه‌کننده است. در محیطِ تست
     * دریافتِ زندهٔ نرخ عمداً خاموش است (PricingNeverPhonesHomeTest).
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\Setting::put('pricing_rate_override', '100000');
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'هاست وردپرس — WP-25', 'name_en' => 'WordPress Hosting WP-25',
            'name_tr' => 'WordPress Hosting WP-25', 'slug' => 'wordpress-3',
            'category' => 'shared', 'group' => 'wordpress',
            'price' => 950000, 'price_eur' => 231, 'setup_fee' => 0,
            'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ]);
    }

    /** معیارِ پذیرشِ ممیزی: صفر لینکِ `/go/pay` روی en و tr. */
    public function test_no_payment_link_survives_on_the_foreign_order_pages(): void
    {
        $p = $this->product();

        foreach (['en', 'tr'] as $lp) {
            $html = $this->get("/{$lp}/order/{$p->slug}")->assertOk()->getContent();

            $this->assertStringNotContainsString('/go/pay', $html,
                "صفحهٔ سفارشِ {$lp} هنوز به درگاهِ پرداخت لینک می‌دهد");
        }
    }

    /** و فارسی دست‌نخورده — این تنزل نباید فروشِ اصلی را بخواباند. */
    public function test_persian_still_sells(): void
    {
        $p = $this->product();

        $html = $this->get("/order/{$p->slug}")->assertOk()->getContent();
        $this->assertStringContainsString('/go/pay', $html, 'فروشِ فارسی نباید تحت تأثیر باشد');
    }

    /**
     * 🔴 اسکیما باید همان چیزی را بگوید که دکمه می‌کند.
     * `InStock` کنارِ دکمه‌ای که به فرمِ استعلام می‌رود، ناهمخوانیِ ساختاری است.
     */
    public function test_the_schema_availability_matches_what_the_button_does(): void
    {
        $p = $this->product();

        $en = $this->get("/en/order/{$p->slug}")->assertOk()->getContent();
        $this->assertStringContainsString('LimitedAvailability', $en);
        $this->assertStringNotContainsString('"https://schema.org/InStock"', $en);

        $fa = $this->get("/order/{$p->slug}")->assertOk()->getContent();
        $this->assertStringContainsString('InStock', $fa);
    }

    /** بیانیهٔ صادقانهٔ داده روی صفحهٔ سفارشِ خارجی — نه فقط صفحهٔ اصلی. */
    public function test_the_data_residency_notice_is_where_the_buyer_decides(): void
    {
        $p = $this->product();

        $en = $this->get("/en/order/{$p->slug}")->assertOk()->getContent();
        $this->assertStringContainsString(__('ui.eu_data_title', [], 'en'), $en);
        $this->assertStringContainsString('Article 28', $en);

        $fa = $this->get("/order/{$p->slug}")->assertOk()->getContent();
        $this->assertStringNotContainsString(__('ui.eu_data_title', [], 'en'), $fa,
            'بیانیهٔ اروپا روی صفحهٔ فارسی بی‌ربط است');
    }

    /**
     * ⚠️ متنِ دکمه هم باید عوض شود، در **هر دو** جا: رندرِ Blade و JSای که
     * بعد از انتخابِ دوره بازنویسی‌اش می‌کند. اگر فقط یکی عوض شود، اولین
     * کلیکِ کاربر «ادامهٔ پرداخت» را برمی‌گردانَد — خرابی‌ای که در HTMLِ اولیه
     * دیده نمی‌شود.
     */
    public function test_the_button_says_quote_in_both_render_paths(): void
    {
        $p = $this->product();
        $html = $this->get("/en/order/{$p->slug}")->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.os_cta_quote', [], 'en'), $html);
        $this->assertStringContainsString('var SELLS = false', $html,
            'JS پرچمِ فروش را نمی‌گیرد و متنِ دکمه را با اولین کلیک برمی‌گردانَد');
    }
}
