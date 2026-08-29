<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 قلمِ مالیات — چهار دورِ ممیزی باز بود (ششم تا نهم).
 *
 * تصمیمِ کارفرما (شهریور ۱۴۰۵): «قیمت‌های تومان ارزش افزوده دارد، ولی یورو نه.»
 *
 * ═══ باگی که هنگامِ نوشتنِ بند پیدا شد ═══
 *
 * `tax_percent` بی‌قیدِ ارز اعمال می‌شد: `$grand = $total + $tax` در هر سه
 * زبان. یعنی مشتریِ یورویی هم ۱۰٪ **مالیاتِ ایران** می‌پرداخت — عددی که نه
 * ما موظف به وصولش بودیم و نه او به پرداختش، روی **هر تراکنش**، بی‌هیچ خطایی.
 *
 * ⚠️ این فایل عمداً بند را به **مبلغِ واقعیِ صورت‌حساب** گره می‌زند، نه صرفاً
 * به وجودِ متن. بندِ حقوقی که با کد نخوانَد، از نبودنش بدتر است: مشتری روی
 * آن حساب می‌کند.
 */
class VatClauseMatchesBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('pricing_rate_override', '100000');
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'هاست لینوکس — LX-5', 'name_en' => 'Linux Hosting LX-5',
            'name_tr' => 'Linux Hosting LX-5', 'slug' => 'linux-2',
            'category' => 'shared', 'group' => 'linux',
            'price' => 1000000, 'price_eur' => 1000, 'setup_fee' => 0,
            'cycle' => 'monthly', 'tax_percent' => 10, 'is_active' => true,
        ]);
    }

    /** ریال/تومان مشمول است — رفتارِ امروز نباید عوض شود. */
    public function test_toman_orders_are_charged_vat(): void
    {
        $p = $this->product();

        $this->app->setLocale('fa');
        $this->assertSame(10, $p->effectiveTaxPercent());
        $this->assertGreaterThan(0, $p->taxAmount());
    }

    /**
     * 🔴 هستهٔ باگ: یورو **نباید** مالیاتِ ایران بخورد.
     */
    public function test_euro_orders_are_not_charged_iranian_vat(): void
    {
        $p = $this->product();

        foreach (['en', 'tr'] as $loc) {
            $this->app->setLocale($loc);
            $this->assertSame(0, $p->effectiveTaxPercent(), "زبانِ {$loc} هنوز مالیاتِ ایران می‌خورد");
            $this->assertSame(0, $p->taxAmount());
            $this->assertSame($p->effectivePrice() + $p->effectiveSetup(), $p->firstTotal(),
                'صورت‌حسابِ اولِ یورویی نباید مالیات داشته باشد');
        }
    }

    /** و صفحهٔ سفارش هم همان را نشان دهد — نه فقط مدل. */
    public function test_the_order_page_charges_what_the_clause_promises(): void
    {
        $p = $this->product();

        $en = $this->get('/en/order/'.$p->slug)->assertOk()->getContent();
        $this->assertStringContainsString(__('ui.os_tax_none', [], 'en'), $en,
            'صفحهٔ یورویی نمی‌گوید مالیاتِ ایران تعلق نمی‌گیرد');

        // و ادعای «VAT included» آن‌جا نباشد
        $this->assertStringNotContainsString('VAT is included', $en);
    }

    /**
     * ⚠️ بندِ `/terms` باید در **هر سه زبان** صریح باشد و در بخشِ درست بنشیند.
     * ممیزی چهار دور این را «صفر اشاره به ارزش افزوده» گزارش کرد.
     */
    public function test_the_terms_page_states_the_rule_explicitly(): void
    {
        $needles = [
            'fa' => 'مالیات بر ارزش افزوده: سفارش',
            'en' => 'Value added tax: orders',
            'tr' => 'Katma değer vergisi: İran',
        ];

        $sections = (array) config('pages.terms.sections');
        $this->assertNotEmpty($sections);

        foreach ($needles as $loc => $needle) {
            $found = false;
            foreach ($sections as $sec) {
                if (str_contains((string) ($sec[$loc]['b'] ?? ''), $needle)) {
                    $found = true;
                    // و در بخشِ قیمت/مالیات، نه جای دیگر
                    $this->assertStringContainsString(
                        $loc === 'fa' ? 'قیمت، مالیات' : ($loc === 'en' ? 'Pricing, tax' : 'Fiyat, vergi'),
                        (string) $sec[$loc]['t'],
                        "بندِ مالیات در {$loc} در بخشِ اشتباه نشسته"
                    );
                    break;
                }
            }
            $this->assertTrue($found, "بندِ صریحِ ارزش افزوده در زبانِ {$loc} نیست");
        }
    }

    /** بند باید هر دو نیمه را بگوید — «تومان بله» بی «یورو نه» گمراه‌کننده است. */
    public function test_the_clause_states_both_halves(): void
    {
        $sections = (array) config('pages.terms.sections');
        $fa = '';
        foreach ($sections as $sec) {
            if (str_contains((string) ($sec['fa']['b'] ?? ''), 'مالیات بر ارزش افزوده: سفارش')) {
                $fa = (string) $sec['fa']['b'];
                break;
            }
        }

        $this->assertStringContainsString('ریال یا تومان', $fa);
        $this->assertStringContainsString('یورو', $fa);
        $this->assertStringContainsString('نیستند', $fa, 'نیمهٔ «یورو مشمول نیست» گفته نشده');
    }
}
