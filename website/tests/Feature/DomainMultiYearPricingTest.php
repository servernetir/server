<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DomainQuote;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Multi-year invoices must use the exact supplier full-term quote. */
class DomainMultiYearPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSupplierPrices();
        \Illuminate\Support\Facades\Http::fake([
            ...$this->supplierPriceStubs(),
            '*/domains/prices*' => function ($request) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                // Full-period prices supplied independently of the promotional first year.
                $totals = [1 => 1.9, 2 => 30.2, 3 => 60.4, 5 => 151, 10 => 302];
                return \Illuminate\Support\Facades\Http::response(['code' => 0, 'data' =>
                    ['price' => ['reseller' => ['price' => $totals[(int) $query['period']], 'currency' => 'EUR']]]]);
            },
        ]);
    }

    use RefreshDatabase;
    use \Tests\Support\SupplierDomainPrices;

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
        $this->assertSame(206000, (int) $this->orderFor(1)->subtotal);
    }

    /** 🔴 قلبِ ماجرا: سال‌های بعد به نرخِ تمدید، نه نرخِ سالِ اول */
    public function test_extra_years_use_the_suppliers_selected_term_total(): void
    {
        $expected = 6_524_000;

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
            $cost = [1 => 190000, 2 => 3020000, 3 => 6040000, 5 => 15100000, 10 => 30200000][$years];

            $this->assertGreaterThanOrEqual(
                $cost,
                (int) $this->orderFor($years)->subtotal,
                "روی ثبتِ {$years} ساله کمتر از بهایِ تمام‌شده گرفتیم"
            );
        }
    }

    /** Stored catalogue renewal data is never used to estimate a create-period total. */
    public function test_a_missing_catalogue_renewal_price_does_not_replace_the_full_term_quote(): void
    {
        $q = $this->quote();
        $q->forceFill(['renew_toman' => null])->save();

        $this->actingAs($this->customer(), 'customer')
            ->post('/account/domains/order', ['quote_id' => $q->id, 'years' => 3])
            ->assertRedirect();

        $this->assertSame(6_524_000, (int) Invoice::latest('id')->first()->subtotal);
    }

    /** The invoice review page presents the fresh price after selecting the term. */
    public function test_checkout_requests_a_fresh_quote_instead_of_showing_a_stale_total(): void
    {
        $q = $this->quote();

        $html = $this->actingAs($this->customer(), 'customer')
            ->get('/account/domains/checkout/'.$q->id.'?years=3')
            ->assertOk()->getContent();

        $expected = 6_524_000;

        $this->assertStringContainsString(__('domain-pricing.quote'), $html);
        $this->assertStringContainsString(__('domain-pricing.note'), $html);
        $this->assertStringNotContainsString(fa_num(number_format(self::CREATE * 3)), $html);
    }
}
