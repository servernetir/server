<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DomainQuote;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ثبتِ چندساله نباید از جیبِ ما برود.
 *
 * ═══ باگی که این تست از آن آمد ═══
 *
 * فرمولِ قبلی `sell_toman * $years` بود — قیمتِ **سالِ اول** ضرب در تعدادِ سال.
 * ولی قیمتِ سالِ اولِ بیشترِ پسوندها تبلیغاتی است و رجیسترار برای سال‌های بعد
 * نرخِ **تمدید** را می‌گیرد.
 *
 * نمونهٔ واقعی از کاتالوگِ خودمان (`.shop`): ثبت ۱۹۰٬۰۰۰ · تمدید ۱٬۴۹۰٬۰۰۰.
 *
 *     ۳ ساله، فرمولِ قبلی : ۱۹۰٬۰۰۰ × ۳            =   ۵۷۰٬۰۰۰  ← از مشتری
 *     بهایِ واقعیِ رجیسترار: ۱۹۰٬۰۰۰ + ۲×۱٬۴۹۰٬۰۰۰  = ۳٬۱۷۰٬۰۰۰  ← از ما
 *                                            ضرر  ≈ ۲٬۶۰۰٬۰۰۰ تومان
 *
 * و هرچه مشتری سالِ بیشتری می‌خرید، ضرر بزرگ‌تر می‌شد — یعنی بدترین مشتری برای
 * ما، وفادارترینشان بود.
 */
class DomainMultiYearPricingTest extends TestCase
{
    use RefreshDatabase;

    private const CREATE = 190000;

    private const RENEW = 1490000;

    private function customer(): Customer
    {
        $c = Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => 'my'.random_int(1000, 9999).'@example.test',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-for-test'),
            'status'   => 'active',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'first_name' => 'ج', 'last_name' => 'ا', 'email' => $c->email,
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'mobile' => '09121234567',
        ]);

        return $c;
    }

    /**
     * ⚠️ دامنه **یکتا** است، نه ثابت.
     *
     * نسخهٔ اول این فایل یک نامِ ثابت داشت و حلقهٔ چندساله از بارِ دوم به
     * «این دامنه از قبل در سامانه ثبت شده است» می‌خورد: هیچ فاکتورِ تازه‌ای
     * ساخته نمی‌شد و `Invoice::latest()` همان فاکتورِ **یک‌سالهٔ** اول را
     * برمی‌گرداند. یعنی تست سبز می‌شد بی‌آنکه چیزی را سنجیده باشد — همان
     * الگویی که در این پروژه ثبت است: فیکسچرِ مشترک، ادعای بی‌اثر.
     */
    private function quote(): DomainQuote
    {
        return DomainQuote::create([
            'domain'        => 'my'.random_int(100000, 999999).'.shop',
            'tld'           => 'shop',
            'registrar'     => 'openprovider',
            'is_premium'    => false,
            'cost_amount'   => 1000,
            'cost_currency' => 'EUR',
            'sell_toman'    => self::CREATE,
            'renew_toman'   => self::RENEW,
            'honour_until'  => now()->addMinutes(15),
            'raw'           => [],
        ]);
    }

    private function orderFor(int $years): Invoice
    {
        $this->actingAs($this->customer(), 'customer')
            ->post('/account/domains/order', ['quote_id' => $this->quote()->id, 'years' => $years])
            ->assertRedirect();

        return Invoice::latest('id')->firstOrFail();
    }

    /** یک‌ساله دست‌نخورده — رفتارِ موجود نباید بشکند */
    public function test_a_single_year_is_charged_at_the_create_price(): void
    {
        $this->assertSame(self::CREATE, (int) $this->orderFor(1)->subtotal);
    }

    /** 🔴 قلبِ ماجرا: سال‌های بعد به نرخِ تمدید، نه نرخِ سالِ اول */
    public function test_extra_years_are_charged_at_the_renewal_rate(): void
    {
        $expected = self::CREATE + self::RENEW * 2;

        $this->assertSame($expected, (int) $this->orderFor(3)->subtotal,
            'ثبتِ چندساله با نرخِ سالِ اول حساب شد — روی هر خرید ضرر می‌کنیم');
    }

    /**
     * ⚠️ مهم‌ترین ادعای این فایل: مبلغِ گرفته‌شده هرگز از بهایی که به رجیسترار
     * می‌دهیم کمتر نباشد. این همان چیزی است که کارفرما پرسید.
     */
    public function test_we_never_charge_less_than_the_registrar_costs_us(): void
    {
        foreach ([1, 2, 3, 5, 10] as $years) {
            $cost = self::CREATE + self::RENEW * ($years - 1);

            $this->assertGreaterThanOrEqual(
                $cost,
                (int) $this->orderFor($years)->subtotal,
                "روی ثبتِ {$years} ساله کمتر از بهایِ تمام‌شده گرفتیم"
            );
        }
    }

    /** نبودِ نرخِ تمدید نباید بدتر از رفتارِ قبلی شود */
    public function test_a_missing_renewal_price_falls_back_to_the_create_price(): void
    {
        $q = $this->quote();
        $q->forceFill(['renew_toman' => null])->save();

        $this->actingAs($this->customer(), 'customer')
            ->post('/account/domains/order', ['quote_id' => $q->id, 'years' => 3])
            ->assertRedirect();

        $this->assertSame(self::CREATE * 3, (int) Invoice::latest('id')->first()->subtotal);
    }

    /** صفحهٔ تسویه باید **همان** عدد را نشان دهد که فاکتور می‌گیرد */
    public function test_the_checkout_page_shows_the_same_total_the_invoice_will_charge(): void
    {
        $q = $this->quote();

        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/account/domains/checkout/'.$q->id.'?years=3')
            ->assertOk()->getContent();

        $expected = self::CREATE + self::RENEW * 2;

        $this->assertStringContainsString(fa_num(number_format($expected)), $html,
            'قیمتِ صفحهٔ تسویه با فاکتور نمی‌خوانَد — بی‌اعتمادی سرِ قیمت، بدترین جای ممکن است');
    }
}
