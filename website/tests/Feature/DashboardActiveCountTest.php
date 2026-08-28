<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «سرویسِ فعال» یعنی پرداخت‌شده — و کفِ سختِ حاشیهٔ سود.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * کارفرما: «مشتری تازه ثبت‌نام کرده، فقط یک پیش‌فاکتورِ پرداخت‌نشده دارد،
 * ولی داشبورد و ربات "سرویسِ فعال: ۱" نشان می‌دهند.» و جدا از آن،
 * cloud_margin_pct روی سرور ۲ بود و کلِ خطِ ابری به بها فروخته می‌شد.
 */
class DashboardActiveCountTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $locale = 'en'): Customer
    {
        return Customer::create([
            'email' => 'dc'.random_int(1, 999999).'@example.com',
            'phone' => '+9053'.random_int(10000000, 99999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => $locale,
        ]);
    }

    private function service(Customer $c, string $status): Service
    {
        return Service::create([
            'customer_id' => $c->id, 'kind' => 'hosting', 'status' => $status,
            'name' => 'svc-'.$status, 'currency_code' => 'IRT', 'price' => 100_000,
            'cycle' => 'monthly',
        ]);
    }

    /** 🔴 سفارشِ پرداخت‌نشده «سرویسِ فعال» نیست */
    public function test_an_unpaid_pending_order_is_not_counted_as_an_active_service(): void
    {
        $c = $this->customer();
        $this->service($c, 'pending');

        $resp = $this->actingAs($c, 'customer')->get('/en/account');
        $resp->assertOk();
        $this->assertSame(0, (int) $resp->viewData('serviceCount'),
            'پیش‌فاکتورِ پرداخت‌نشده نباید «سرویسِ فعال: ۱» بسازد.');
    }

    /** پرداخت‌شده (حتی هنوز تحویل‌نشده) فعال شمرده می‌شود */
    public function test_a_paid_service_counts_even_before_delivery(): void
    {
        $c = $this->customer();
        $this->service($c, 'awaiting_provision');
        $this->service($c, 'active');

        $resp = $this->actingAs($c, 'customer')->get('/en/account');
        $this->assertSame(2, (int) $resp->viewData('serviceCount'));
    }

    /** دکمهٔ هر کارِ باقی‌مانده متناسب با خودِ کار است، نه «انجام»ِ عمومی */
    public function test_the_todo_button_names_the_action(): void
    {
        $c = $this->customer();
        $s = $this->service($c, 'pending');

        Invoice::create([
            'customer_id' => $c->id, 'service_id' => $s->id, 'kind' => 'service',
            'number' => 'INV-'.random_int(10000, 99999), 'currency_code' => 'IRT',
            'subtotal' => 100_000, 'tax' => 0, 'total' => 100_000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $resp = $this->actingAs($c, 'customer')->get('/en/account');
        $resp->assertOk();
        $resp->assertSee('Pay invoice');
        $resp->assertDontSee('>Open<', false);
    }

    /**
     * حاشیه دقیقاً همان تنظیمِ مدیر است — **بی‌هیچ کفی** (تصمیمِ صریحِ
     * کارفرما: «حاشیهٔ سود من همان عددی است که در تنظیمات می‌نویسم»).
     * محافظِ ضدِ ضرر جای دیگری است: سربارِ بها (تستِ بعدی).
     */
    public function test_the_margin_setting_is_respected_exactly(): void
    {
        Setting::put('cloud_margin_pct', '2');
        $this->assertSame(2.0, app(CloudPricing::class)->marginPct(),
            'حاشیه تصمیمِ مدیر است؛ کفِ سخت عمداً برداشته شد.');

        Setting::put('cloud_margin_pct', '45');
        $this->assertSame(45.0, app(CloudPricing::class)->marginPct());

        Setting::put('cloud_margin_pct', '');
        $this->assertSame(45.0, app(CloudPricing::class)->marginPct(), 'خالی = پیش‌فرضِ ۴۵.');
    }

    /**
     * 🔴 محافظِ واقعیِ «۲٪ سود ولی در عمل ضرر»: سربارِ رساندنِ پول
     * (pricing_fx_fee_pct) باید واردِ بها شود تا حاشیهٔ کوچک هم سودِ واقعی
     * باشد. sn-svc-72 دقیقاً همین بود: بها نرخِ اسمیِ هتزنر بود، بی‌کارمزدِ
     * حواله/VAT.
     */
    public function test_the_fx_fee_uplifts_the_cost_basis(): void
    {
        $pricing = app(CloudPricing::class);

        Setting::put('pricing_fx_fee_pct', null);
        $this->assertSame(0.0, $pricing->fxFeePct(), 'پیش‌فرض صفر — عددِ حدسی ممنوع.');
        $this->assertSame(1000.0, $pricing->costWithFee(1000));

        Setting::put('pricing_fx_fee_pct', '10');
        $this->assertSame(10.0, $pricing->fxFeePct());
        $this->assertSame(1100.0, $pricing->costWithFee(1000));

        // سقفِ ۲۵: عددِ بزرگ‌تر تقریباً همیشه غلطِ تایپی است
        Setting::put('pricing_fx_fee_pct', '400');
        $this->assertSame(25.0, $pricing->fxFeePct());
    }
}
