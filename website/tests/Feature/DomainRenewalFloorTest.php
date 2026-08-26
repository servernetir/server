<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Setting;
use App\Services\Domain\DomainRenewalInvoicer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * کفِ ارزیِ تمدیدِ خرده‌فروشی — «فاکتورِ ضررده صادر نکن».
 *
 * ═══ دو باگِ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 (۱) تمدیدِ خرده‌فروشی هیچ کفی نداشت: `renew_toman` در روزِ خرید فریز
 * می‌شد و یک سال بعد، پس از جهشِ ارز، کرون خودش فاکتوری صادر می‌کرد که از
 * بهای همان‌روزِ رجیسترار ارزان‌تر بود — ضررِ تضمین‌شده روی هر تمدید.
 *
 * 🔴 (۲) کفِ نمایندگی هم که بود، از `cost_amount` — بهای **تبلیغاتیِ سالِ
 * اول** — حساب می‌شد (.shop: ثبت €1.90 / تمدید €14.90 ⇒ کفِ ~€2 در برابرِ
 * هزینهٔ واقعیِ €14.90). حالا بهای تمدید ذخیره و مبنا می‌شود.
 */
class DomainRenewalFloorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // هیچ تماسِ زنده‌ای — نه رجیسترار، نه اسکرپِ نرخ
        Http::fake(['*' => Http::response([], 500)]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'fl'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function domain(array $over = []): Domain
    {
        return Domain::create(array_merge([
            'customer_id'      => $this->customer()->id,
            'domain'           => 'fl'.random_int(1000, 99999).'.shop',
            'sld'              => 'x', 'tld' => 'shop',
            'status'           => 'active',
            'provision_status' => 'done',
            'period_years'     => 1,
            'price_toman'      => 190_000,
            'renew_toman'      => 200_000,          // فریزشده — کهنه
            'cost_amount'      => 190,              // €1.90 سالِ اول (تبلیغاتی)
            'cost_renew_amount' => 1490,            // €14.90 تمدیدِ واقعی
            'cost_currency'    => 'EUR',
            'op_id'            => 777,
            'expires_at'       => now()->addDays(10),
        ], $over));
    }

    // نرخِ ثابتِ آزمون: هر یورو ۱۰۰٬۰۰۰ تومان
    private function rate(): void
    {
        Setting::put('pricing_rate_override', '100000');
    }

    // €14.90 × ۱۰۰٬۰۰۰ × ۱٫۰۸ = ۱٬۶۰۹٬۲۰۰ → گرد به پلهٔ ۱٬۰۰۰
    private const FLOOR = 1_610_000;

    public function test_a_stale_retail_renewal_is_repriced_to_the_cost_floor(): void
    {
        $this->rate();
        $d = $this->domain();

        $inv = app(DomainRenewalInvoicer::class)->issue($d, 1);

        $this->assertSame(self::FLOOR, (int) $inv->subtotal,
            'فاکتورِ تمدید با قیمتِ فریزشدهٔ پارسال صادر شد — زیرِ بهای تمام‌شده');
    }

    /** 🔴 مبنا بهای واقعیِ تمدید است، نه بهای تبلیغاتیِ سالِ اول */
    public function test_the_floor_uses_the_real_renewal_cost_not_the_first_year_teaser(): void
    {
        $this->rate();
        $d = $this->domain();

        $inv = app(DomainRenewalInvoicer::class)->issue($d, 1);

        // اگر از cost_amount (€1.90) حساب می‌شد: ۱٫۹۰×۱۰۰٬۰۰۰×۱٫۰۸ ⇒ ~۲۰۶٬۰۰۰
        $this->assertGreaterThan(1_000_000, (int) $inv->subtotal,
            'کف از بهای تبلیغاتیِ سالِ اول حساب شد — محافظِ بی‌اثر (.shop)');
    }

    /** ردیفِ قدیمی بدونِ بهای تمدید، به cost_amount برمی‌گردد — محافظِ ناقص بهتر از هیچ */
    public function test_an_old_row_without_renewal_cost_falls_back_to_the_create_cost(): void
    {
        $this->rate();
        $d = $this->domain(['cost_renew_amount' => null, 'cost_amount' => 1490]);

        $inv = app(DomainRenewalInvoicer::class)->issue($d, 1);

        $this->assertSame(self::FLOOR, (int) $inv->subtotal);
    }

    public function test_a_domain_with_no_cost_data_keeps_the_stored_price(): void
    {
        $this->rate();
        $d = $this->domain(['cost_amount' => 0, 'cost_renew_amount' => null]);

        $inv = app(DomainRenewalInvoicer::class)->issue($d, 1);

        $this->assertSame(200_000, (int) $inv->subtotal,
            'بی‌داده باید محافظ خاموش بماند، نه اینکه تمدید بسته شود');
    }

    public function test_no_fx_rate_keeps_the_stored_price_instead_of_blocking(): void
    {
        // نرخی ست نشده و اسکرپ هم فیک‌شده و می‌شکند ⇒ نرخ نداریم
        $d = $this->domain();

        $inv = app(DomainRenewalInvoicer::class)->issue($d, 1);

        $this->assertSame(200_000, (int) $inv->subtotal,
            'بستنِ تمدید به‌خاطرِ نبودِ نرخ یعنی دامنهٔ مشتری منقضی شود');
    }

    /** کف هرگز قیمت را **پایین** نمی‌آورد */
    public function test_the_floor_never_lowers_a_healthy_price(): void
    {
        $this->rate();
        $d = $this->domain(['renew_toman' => 5_000_000]);

        $inv = app(DomainRenewalInvoicer::class)->issue($d, 1);

        $this->assertSame(5_000_000, (int) $inv->subtotal,
            'ارزان‌کردن تصمیمِ مالیِ کارفرماست، نه کارِ خودکارِ کد');
    }

    // ═══════════════ بازمصرفِ کاملِ ردیفِ مرده ═══════════════

    /**
     * 🔴 ردیفِ مردهٔ بازمصرف‌شده باید **همه‌چیزش** تازه شود. نسخهٔ قبلی
     * `order_type`/`transfer_status`/قیمت‌ها/بهای تمام‌شده/`provision_tries`
     * را نگه می‌داشت: خریدِ تازه روی لاشهٔ یک انتقالِ ردشده در هیچ صفی
     * نمی‌افتاد — پرداخت‌شده و نامرئی برای همهٔ کرون‌ها.
     */
    public function test_reusing_a_dead_transfer_row_resets_queue_and_money_fields(): void
    {
        $c = $this->customer();

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'first_name' => 'ج', 'last_name' => 'ا', 'email' => $c->email,
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'mobile' => '09121234567',
        ]);

        $fqdn = 'reuse'.random_int(1000, 99999).'.shop';

        Domain::create([
            'customer_id' => $c->id, 'domain' => $fqdn,
            'sld' => 'reuse', 'tld' => 'shop', 'registrar' => 'openprovider',
            'order_type' => 'transfer', 'transfer_status' => 'rejected',
            'status' => 'cancelled', 'provision_status' => 'none',
            'provision_tries' => 3, 'provision_error' => 'رد شد',
            'period_years' => 1, 'price_toman' => 111_111,
            'renew_toman' => 111_111, 'cost_amount' => 111, 'cost_currency' => 'USD',
        ]);

        $q = DomainQuote::create([
            'domain' => $fqdn, 'tld' => 'shop', 'registrar' => 'openprovider',
            'is_premium' => false,
            'cost_amount' => 190, 'cost_renew_amount' => 1490, 'cost_currency' => 'EUR',
            'sell_toman' => 190_000, 'renew_toman' => 1_490_000,
            'honour_until' => now()->addMinutes(15), 'raw' => [],
        ]);

        $this->actingAs($c, 'customer')
            ->post('/account/domains/order', ['quote_id' => $q->id, 'years' => 1])
            ->assertRedirect();

        $d = Domain::where('domain', $fqdn)->firstOrFail();

        $this->assertSame('register', $d->order_type,
            'order_type ریست نشد — ردیفِ پرداختی در صفِ ثبت نمی‌افتد');
        $this->assertNull($d->transfer_status);
        $this->assertSame(0, (int) $d->provision_tries);
        $this->assertSame(1_490_000, (int) $d->renew_toman, 'قیمتِ تمدیدِ کهنه ماند');
        $this->assertSame(1490, (int) $d->cost_renew_amount);
        $this->assertSame('EUR', (string) $d->cost_currency);
    }
}
