<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Finance\BusinessLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * دفترِ کسب‌وکار باید پولِ دامنه را ببیند — هر سه سمتش.
 *
 * ═══ سه حفرهٔ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 (۱) فروشِ نمایندگی (تسویه از اعتبار) هیچ Payment نمی‌سازد و
 * `recordPayment` هرگز صدایش نمی‌زد ⇒ ۱۰۰٪ درآمدِ نمایندگی + مالیاتش از
 * /admin/finance غایب بود.
 *
 * 🔴 (۲) دستهٔ `domain_wholesale` تعریف شده بود و **هیچ نویسنده‌ای نداشت** ⇒
 * بهای رجیسترار هیچ‌جا ثبت نمی‌شد و مارجینِ دامنه ~۱۰۰٪ نمایش داده می‌شد.
 *
 * 🔴 (۳) `recordRefund` صفر فراخوان داشت ⇒ ستونِ refund همیشه صفر بود.
 */
class DomainLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 500)]);
        Setting::put('pricing_rate_override', '100000');    // هر یورو ۱۰۰٬۰۰۰ تومان
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'lg'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function invoice(Customer $c, ?int $domainId = null, int $paid = 2_090_000): Invoice
    {
        return Invoice::create([
            'customer_id' => $c->id, 'domain_id' => $domainId, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 1_900_000, 'tax' => 190_000,
            'total' => 2_090_000, 'paid' => $paid, 'status' => 'paid',
            'issued_at' => now(), 'paid_at' => now(),
        ]);
    }

    // ═══════════════ (۱) درآمدِ تسویه از اعتبار ═══════════════

    public function test_a_credit_settled_invoice_lands_in_the_ledger_split_by_tax(): void
    {
        $inv = $this->invoice($this->customer());

        app(BusinessLedger::class)->recordCreditSale($inv);

        $this->assertSame(1_900_000, (int) BusinessEntry::where('kind', 'revenue')->sum('amount'),
            'درآمدِ فروشِ اعتباری در دفتر ننشست');
        $this->assertSame(190_000, (int) BusinessEntry::where('kind', 'tax_collected')->sum('amount'),
            'مالیات باید جدا بنشیند — پولِ ما نیست');
    }

    public function test_recording_a_credit_sale_twice_writes_once(): void
    {
        $inv = $this->invoice($this->customer());

        app(BusinessLedger::class)->recordCreditSale($inv);
        app(BusinessLedger::class)->recordCreditSale($inv);

        $this->assertSame(1, BusinessEntry::where('kind', 'revenue')->count(),
            'دو بار settle یعنی درآمدِ دوبرابرِ دروغ');
    }

    // ═══════════════ (۲) هزینهٔ خریدِ عمده ═══════════════

    public function test_a_successful_registration_posts_the_wholesale_cost(): void
    {
        $c = $this->customer();
        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'lg1.com', 'sld' => 'lg1', 'tld' => 'com',
            'status' => 'active', 'provision_status' => 'done', 'period_years' => 1,
            'cost_amount' => 1000, 'cost_currency' => 'EUR',       // €10.00
        ]);
        $this->invoice($c, $d->id);

        app(BusinessLedger::class)->recordDomainWholesale($d, 'register', 1);

        $row = BusinessEntry::where('kind', 'expense')->where('category', 'domain_wholesale')->first();

        $this->assertNotNull($row, 'هزینهٔ عمده ثبت نشد — مارجینِ گزارش دروغ می‌شود');
        $this->assertSame(1_000_000, (int) $row->amount);          // €10 × ۱۰۰٬۰۰۰
    }

    /** 🔴 چندساله: بهای واقعی = ثبت + تمدید × سال‌های اضافه، نه فقط سالِ اول */
    public function test_a_multi_year_registration_costs_create_plus_renewals(): void
    {
        $c = $this->customer();
        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'lg2.shop', 'sld' => 'lg2', 'tld' => 'shop',
            'status' => 'active', 'provision_status' => 'done', 'period_years' => 3,
            'cost_amount' => 190, 'cost_renew_amount' => 1490, 'cost_currency' => 'EUR',
        ]);
        $this->invoice($c, $d->id);

        app(BusinessLedger::class)->recordDomainWholesale($d, 'register', 3);

        // (€1.90 + €14.90×2) × ۱۰۰٬۰۰۰ = ۳٬۱۷۰٬۰۰۰
        $this->assertSame(3_170_000,
            (int) BusinessEntry::where('category', 'domain_wholesale')->sum('amount'),
            'بهای چندساله فقط سالِ اول حساب شد — سودِ گزارش بیش‌نمایی می‌شود');
    }

    public function test_each_renewal_year_gets_its_own_expense_row(): void
    {
        $c = $this->customer();
        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'lg3.com', 'sld' => 'lg3', 'tld' => 'com',
            'status' => 'active', 'provision_status' => 'done', 'period_years' => 1,
            'cost_amount' => 1000, 'cost_renew_amount' => 1200, 'cost_currency' => 'EUR',
        ]);

        $first = $this->invoice($c, $d->id);
        app(BusinessLedger::class)->recordDomainWholesale($d, 'register', 1);

        // سالِ بعد: فاکتورِ تمدیدِ تازه، هزینهٔ تازه
        $this->invoice($c, $d->id);
        app(BusinessLedger::class)->recordDomainWholesale($d, 'renew', 1);

        $this->assertSame(2, BusinessEntry::where('category', 'domain_wholesale')->count(),
            'idempotency روی دامنه بسته شد نه فاکتور — تمدیدِ سالِ بعد گم می‌شود');
    }

    public function test_no_fx_rate_means_no_invented_expense(): void
    {
        Setting::put('pricing_rate_override', '0');

        $c = $this->customer();
        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'lg4.com', 'sld' => 'lg4', 'tld' => 'com',
            'status' => 'active', 'provision_status' => 'done', 'period_years' => 1,
            'cost_amount' => 1000, 'cost_currency' => 'EUR',
        ]);
        $this->invoice($c, $d->id);

        app(BusinessLedger::class)->recordDomainWholesale($d, 'register', 1);

        $this->assertSame(0, BusinessEntry::where('category', 'domain_wholesale')->count(),
            'حدسِ ساختگی وارد دفتر شد — همان قاعدهٔ recordApiCost نقض شد');
    }

    // ═══════════════ (۳) رفاندها ═══════════════

    public function test_an_invoice_refund_lands_in_the_ledger_once(): void
    {
        $inv = $this->invoice($this->customer());

        app(BusinessLedger::class)->recordInvoiceRefund($inv, 2_090_000, 'تست');
        app(BusinessLedger::class)->recordInvoiceRefund($inv, 2_090_000, 'تست');

        $this->assertSame(1, BusinessEntry::where('kind', 'refund')->count());
        $this->assertSame(2_090_000, (int) BusinessEntry::where('kind', 'refund')->sum('amount'));
    }

    /** مسیرِ واقعی: resolve-stuck یک ثبتِ ناموفق را رفاند می‌کند → دفتر می‌بیند */
    public function test_the_stuck_domain_refund_reaches_the_business_ledger(): void
    {
        $c = $this->customer();

        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'lg5.shop', 'sld' => 'lg5', 'tld' => 'shop',
            'registrar' => 'openprovider',
            'status' => 'pending', 'provision_status' => 'manual',
            'provision_tries' => 3, 'period_years' => 1, 'price_toman' => 1_900_000,
            'provision_error' => 'مشخصات ناقص',
        ]);
        $this->invoice($c, $d->id);
        $d->forceFill(['updated_at' => now()->subHours(48)])->saveQuietly();

        $this->artisan('domains:resolve-stuck')->assertSuccessful();

        $this->assertSame('cancelled', $d->refresh()->status);
        $this->assertSame(2_090_000, (int) BusinessEntry::where('kind', 'refund')->sum('amount'),
            'رفاندِ واقعی به دفتر نرسید — سودِ /admin/finance بیش‌نمایی می‌شود');
    }
}
