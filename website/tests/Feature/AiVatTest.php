<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\TaxRate;
use App\Services\Ai\AiVat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مالیاتِ AI (D10): مالیات می‌خورد مگر کشورِ ثبت‌شده غیرِ IR باشد. زبان و
 * نبودِ کشور هرگز معافیت نمی‌سازند.
 *
 * ⚠️ `customers` هنوز ستونِ `country_code` ندارد؛ کشور این‌جا فقط روی مدلِ
 * ذخیره‌نشده گذاشته می‌شود تا قاعده برای روزی که ستون بیاید لنگر شود.
 */
class AiVatTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $locale, ?string $country = null): Customer
    {
        $c = new Customer(['locale' => $locale]);
        if ($country !== null) {
            $c->setAttribute('country_code', $country);
        }

        return $c;
    }

    private function vat(): AiVat
    {
        return app(AiVat::class);
    }

    public function test_fa_customer_pays_ten_percent(): void
    {
        $this->assertSame(['bp' => 1000, 'basis' => 'fa_locale'], $this->vat()->resolve($this->customer('fa')));
    }

    public function test_english_locale_without_country_still_pays(): void
    {
        $this->assertSame(['bp' => 1000, 'basis' => 'no_country'], $this->vat()->resolve($this->customer('en')));
    }

    public function test_declared_foreign_country_is_exempt(): void
    {
        $this->assertSame(['bp' => 0, 'basis' => 'foreign_country'], $this->vat()->resolve($this->customer('en', 'DE')));
        $this->assertSame(['bp' => 0, 'basis' => 'foreign_country'], $this->vat()->resolve($this->customer('fa', 'tr')));
    }

    public function test_declared_iran_pays_even_on_english_site(): void
    {
        $this->assertSame(['bp' => 1000, 'basis' => 'ir_country'], $this->vat()->resolve($this->customer('en', 'IR')));
    }

    public function test_garbage_country_is_not_an_exemption(): void
    {
        $this->assertSame(1000, $this->vat()->resolve($this->customer('en', 'Germany'))['bp']);
        $this->assertSame(1000, $this->vat()->resolve($this->customer('en', ''))['bp']);
    }

    /**
     * ردیفِ AI باید بر ردیفِ عمومیِ ایرانِ سیدر (اولویتِ ۱۰) پیروز شود — با اولویتِ
     * پیش‌فرضِ صفر و با اولویتِ برابر. نسخهٔ اول فقط بی‌سیدر سبز بود چون
     * `TaxRate::resolve` بر نوعِ محصول مرتب نمی‌کند.
     */
    public function test_tax_rates_ai_row_overrides_the_seeded_generic_iran_row(): void
    {
        $this->seed(\Database\Seeders\BillingFoundationSeeder::class);
        $this->assertSame(1000, $this->vat()->iranRateBp(), 'پیش‌فرضِ سیدر: ایران ۱۰٪');

        $ai = TaxRate::create(['name' => 'VAT AI', 'country' => 'IR', 'product_kind' => 'ai', 'rate_bp' => 1200, 'priority' => 0]);
        $this->assertSame(1200, $this->vat()->resolve($this->customer('fa'))['bp']);

        $ai->update(['priority' => 10]);
        $this->assertSame(1200, $this->vat()->resolve($this->customer('fa'))['bp']);

        // ردیفِ مخصوصِ نوعِ مشتری (مثلاً شرکت ۰٪) هرگز جای نرخِ عمومیِ AI نمی‌نشیند
        TaxRate::create(['name' => 'Co', 'country' => 'IR', 'customer_type' => 'company', 'product_kind' => 'ai', 'rate_bp' => 0, 'priority' => 99]);
        $this->assertSame(1200, $this->vat()->iranRateBp());
    }

    public function test_global_zero_row_never_exempts_a_customer_without_country(): void
    {
        // همان ردیفِ «خارج ۰٪» ِ سیدر — بی‌کشور، پس نباید برای مشتریِ بی‌کشور اعمال شود
        TaxRate::create(['name' => 'Foreign', 'country' => null, 'rate_bp' => 0, 'priority' => 99]);

        $this->assertSame(1000, $this->vat()->resolve($this->customer('en'))['bp']);
    }
}
