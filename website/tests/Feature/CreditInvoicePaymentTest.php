<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * پرداختِ فاکتور از اعتبارِ داخلی.
 *
 * ═══ حفره‌ای که ممیزی پیدا کرد ═══
 *
 * 🔴 هر رفاندِ خودکار به `credit_ledger` می‌نشست و به مشتری می‌گفتیم
 * «می‌توانید دوباره اقدام کنید» — ولی هیچ درگاهی برای خرجِ اعتبار روی
 * فاکتور نبود. پولی که مشتری می‌دید و نمی‌توانست خرج کند.
 *
 * قواعدِ قفل‌شده: تسویه از **همان** مسیرِ رسمی (settleConfirmed) می‌رود تا
 * صف‌گذاریِ دامنه و ثبتِ درآمد خودکار بیفتد؛ فقط پرداختِ کاملِ مانده؛
 * topup ممنوع؛ دو کلیک = یک کسر.
 */
class CreditInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const BALANCE = 5_000_000;

    private const DUE = 2_750_000;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 500)]);
    }

    private function customer(int $credit = self::BALANCE): Customer
    {
        $c = Customer::create([
            'email' => 'cp'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($credit > 0) {
            CreditEntry::create([
                'customer_id' => $c->id, 'currency_code' => 'IRT',
                'amount' => $credit, 'balance_after' => $credit,
                'reason' => 'domain_failed_refund', 'note' => 'رفاندِ آزمون',
            ]);
        }

        return $c;
    }

    /** فاکتورِ تمدیدِ دامنه — تا صف‌گذاریِ پس از پرداخت هم سنجیده شود */
    private function renewalInvoice(Customer $c): array
    {
        $d = Domain::create([
            'customer_id' => $c->id, 'domain' => 'cp'.random_int(1000, 99999).'.com',
            'sld' => 'x', 'tld' => 'com', 'status' => 'active',
            'provision_status' => 'done', 'period_years' => 1,
            'renew_toman' => 2_500_000, 'op_id' => 777,
            'expires_at' => now()->addDays(10),
        ]);

        $inv = Invoice::create([
            'customer_id' => $c->id, 'domain_id' => $d->id, 'kind' => 'domain',
            'currency_code' => 'IRT', 'subtotal' => 2_500_000, 'tax' => 250_000,
            'total' => self::DUE, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        return [$d, $inv];
    }

    private function pay(Customer $c, Invoice $inv): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($c, 'customer')
            ->post('/account/invoices/'.$inv->id.'/pay-credit');
    }

    // ═══════════════ مسیرِ شاد ═══════════════

    public function test_credit_pays_the_invoice_through_the_official_settle_path(): void
    {
        $c = $this->customer();
        [$d, $inv] = $this->renewalInvoice($c);

        $this->pay($c, $inv)->assertRedirect();

        $inv->refresh();
        $this->assertSame('paid', $inv->status);
        $this->assertSame(self::DUE, (int) $inv->paid);

        // کسرِ اعتبار — موجودی از جمعِ دفتر
        $this->assertSame(self::BALANCE - self::DUE,
            (int) CreditEntry::where('customer_id', $c->id)->sum('amount'));

        // Payment رسمی از جنسِ credit
        $p = Payment::where('invoice_id', $inv->id)->first();
        $this->assertNotNull($p);
        $this->assertSame('credit', $p->gateway);
        $this->assertTrue($p->isPaid());

        // 🔴 زنجیرهٔ رسمی: دامنه باید در صفِ تمدید بیفتد
        $this->assertSame('pending', $d->fresh()->provision_status,
            'پرداختِ اعتباری صفِ تمدید را راه نینداخت — منطقِ تسویهٔ موازی؟');
    }

    /** درآمدِ اعتبارِ خرج‌شده همین‌جا شناسایی می‌شود (recordPayment روی topup ننوشته بود) */
    public function test_spending_credit_recognizes_revenue_in_the_ledger(): void
    {
        $c = $this->customer();
        [, $inv] = $this->renewalInvoice($c);

        $this->pay($c, $inv);

        $this->assertSame(2_500_000, (int) BusinessEntry::where('kind', 'revenue')->sum('amount'));
        $this->assertSame(250_000, (int) BusinessEntry::where('kind', 'tax_collected')->sum('amount'));
    }

    // ═══════════════ گاردها ═══════════════

    public function test_insufficient_credit_is_refused_and_nothing_is_debited(): void
    {
        $c = $this->customer(credit: 1_000);
        [, $inv] = $this->renewalInvoice($c);

        $this->pay($c, $inv)->assertSessionHasErrors();

        $this->assertSame('unpaid', $inv->fresh()->status);
        $this->assertSame(1_000, (int) CreditEntry::where('customer_id', $c->id)->sum('amount'));
    }

    public function test_a_topup_invoice_cannot_be_paid_with_credit(): void
    {
        $c = $this->customer();

        $inv = Invoice::create([
            'customer_id' => $c->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 1_000_000, 'tax' => 0, 'total' => 1_000_000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $this->pay($c, $inv)->assertSessionHasErrors();

        $this->assertSame('unpaid', $inv->fresh()->status,
            'اعتبار با اعتبار یعنی چاپِ پول از هیچ');
    }

    public function test_double_posting_debits_exactly_once(): void
    {
        $c = $this->customer();
        [, $inv] = $this->renewalInvoice($c);

        $this->pay($c, $inv);
        $this->pay($c, $inv);       // دوم: فاکتور دیگر قابلِ پرداخت نیست

        $this->assertSame(self::BALANCE - self::DUE,
            (int) CreditEntry::where('customer_id', $c->id)->sum('amount'),
            'دو کلیک دو بار کسر کرد');
        $this->assertSame(1, Payment::where('invoice_id', $inv->id)->count());
    }

    public function test_someone_elses_invoice_is_a_404(): void
    {
        $a = $this->customer();
        [, $inv] = $this->renewalInvoice($a);

        $this->pay($this->customer(), $inv)->assertNotFound();
        $this->assertSame('unpaid', $inv->fresh()->status);
    }
}
