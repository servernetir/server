<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Domain\DomainSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * قیمتِ دامنه باید با **همان نرخِ مبنایی** حساب شود که مدیر در `/admin/settings`
 * می‌گذارد — همان نرخی که سرورِ ابری استفاده می‌کند.
 *
 * 🔴 چرا: `DomainSearch` مستقیم `ExchangeRate` را صدا می‌زد و
 * `pricing_rate_override` را نمی‌دید. نتیجه‌اش دو قیمتِ ناهماهنگ روی یک سایت
 * بود: مدیر نرخ را عوض می‌کرد، سرورها تکان می‌خوردند و دامنه‌ها نه — بی‌هیچ
 * خطا و بی‌هیچ نشانه‌ای.
 */
class DomainPricingRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');
        config()->set('services.openprovider.margin', ['default' => 25]);
    }

    /** یک استعلامِ موفق با قیمتِ مشخصِ یورویی */
    private function fakeCheck(float $eur): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*'    => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/domains/check*' => Http::response(['code' => 0, 'data' => ['results' => [[
                'domain' => 'example.com',
                'status' => 'free',
                'price'  => ['reseller' => ['price' => $eur, 'currency' => 'EUR']],
            ]]]]),
        ]);
    }

    private function search(): array
    {
        return app(DomainSearch::class)->search('example.com', ['com']);
    }

    public function test_the_admin_base_rate_drives_the_domain_price(): void
    {
        Setting::put('pricing_rate_override', '100000');   // هر یورو ۱۰۰٬۰۰۰ تومان
        $this->fakeCheck(10.0);                            // بهای تمام‌شده ۱۰ یورو

        $row = $this->search()[0];

        // ۱۰ × ۱۰۰٬۰۰۰ = ۱٬۰۰۰٬۰۰۰ ← +۲۵٪ ← ۱٬۲۵۰٬۰۰۰
        $this->assertSame(1250000, $row['price_toman']);
    }

    /** 🔴 ادعای اصلی: عوض‌کردنِ نرخ در تنظیمات باید قیمتِ دامنه را عوض کند */
    public function test_changing_the_admin_rate_moves_the_domain_price(): void
    {
        Setting::put('pricing_rate_override', '100000');
        $this->fakeCheck(10.0);
        $before = $this->search()[0]['price_toman'];

        Setting::put('pricing_rate_override', '200000');
        $this->fakeCheck(10.0);
        $after = $this->search()[0]['price_toman'];

        $this->assertSame($before * 2, $after,
            'اگر این بشکند یعنی دامنه دوباره نرخِ خودش را دارد و با بقیهٔ سایت نمی‌خوانَد');
    }

    /**
     * نرخِ نامعلوم ⇒ **قیمت نشان داده نمی‌شود**، نه قیمتِ صفر.
     *
     * همان قاعدهٔ `CloudPlan`: فروشِ دامنه به قیمتِ صفر از نفروختن بدتر است.
     */
    public function test_an_unknown_rate_hides_the_price_instead_of_showing_zero(): void
    {
        Setting::put('pricing_rate_override', '0');
        config()->set('services.exchange.enabled', false);
        $this->fakeCheck(10.0);

        $row = $this->search()[0];

        $this->assertNull($row['price_toman']);
        $this->assertFalse($row['orderable']);
        $this->assertSame('fx_unavailable', $row['reason']);
    }

    /** گردکردن رو به **بالا** — هرگز زیرِ بهای تمام‌شده */
    public function test_rounding_never_goes_below_cost(): void
    {
        Setting::put('pricing_rate_override', '123457');
        $this->fakeCheck(9.99);

        $row = $this->search()[0];
        $raw = 9.99 * 123457 * 1.25;

        $this->assertGreaterThanOrEqual($raw, $row['price_toman']);
    }
}
