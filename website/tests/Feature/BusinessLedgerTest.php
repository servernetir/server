<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\BusinessLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * دفتر مالی کسب‌وکار.
 *
 * محورها:
 *   • درآمد و مالیات از پرداخت واقعی خودکار ثبت می‌شوند (مبنا دار)
 *   • مالیات در «سود» نمی‌آید — پول دولت است
 *   • ثبت دوبارهٔ یک پرداخت درآمد را دو برابر نمی‌کند
 *   • همهٔ اعداد داشبورد از جمع ردیف‌ها می‌آید، نه ستون ذخیره‌شده
 */
class BusinessLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): BusinessLedger
    {
        return app(BusinessLedger::class);
    }

    private function paidInvoice(int $subtotal, int $tax, string $kind = 'service'): Payment
    {
        $c = Customer::create([
            'email' => 'f'.random_int(1, 99999).'@example.com',
            'password' => 'secret1234', 'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'customer_id' => $c->id, 'kind' => $kind, 'currency_code' => 'IRT',
            'subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax,
            'status' => 'paid', 'issued_at' => now(), 'paid' => $subtotal + $tax, 'paid_at' => now(),
        ]);

        return Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $c->id,
            'gateway' => 'zarinpal', 'currency_code' => 'IRT',
            'amount' => $subtotal + $tax, 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_a_paid_invoice_splits_into_revenue_and_tax(): void
    {
        $payment = $this->paidInvoice(subtotal: 1_000_000, tax: 100_000);

        $this->ledger()->recordPayment($payment);

        $s = $this->ledger()->summary();
        $this->assertSame(1_000_000, $s['revenue']);
        $this->assertSame(100_000, $s['tax_collected']);
        // مالیات در سود نمی‌آید — سود فقط درآمد منهای هزینه
        $this->assertSame(1_000_000, $s['net_profit']);
    }

    public function test_recording_the_same_payment_twice_does_not_double_the_revenue(): void
    {
        $payment = $this->paidInvoice(subtotal: 500_000, tax: 50_000);

        $this->ledger()->recordPayment($payment);
        $this->ledger()->recordPayment($payment);   // مثلاً settle دوباره

        $s = $this->ledger()->summary();
        $this->assertSame(500_000, $s['revenue'], 'درآمد دو برابر شد');
        $this->assertSame(1, BusinessEntry::where('kind', 'revenue')->count());
    }

    public function test_a_topup_is_not_counted_as_revenue(): void
    {
        // افزایش اعتبار درآمد نیست تا وقتی مشتری خرجش کند
        $payment = $this->paidInvoice(subtotal: 2_000_000, tax: 0, kind: 'topup');

        $this->ledger()->recordPayment($payment);

        $this->assertSame(0, $this->ledger()->summary()['revenue']);
        $this->assertSame(0, BusinessEntry::where('kind', 'revenue')->count());
    }

    public function test_profit_is_revenue_minus_expense(): void
    {
        $this->ledger()->recordPayment($this->paidInvoice(3_000_000, 300_000));
        $this->ledger()->manual('expense', 1_200_000, 'server', now(), 'هتزنر', 1);

        $s = $this->ledger()->summary();
        $this->assertSame(3_000_000, $s['revenue']);
        $this->assertSame(1_200_000, $s['expense']);
        $this->assertSame(1_800_000, $s['net_profit']);
        $this->assertSame(60.0, $s['margin']);   // 1.8m / 3m
    }

    public function test_roi_is_profit_over_net_capital(): void
    {
        $this->ledger()->manual('capital', 10_000_000, null, now(), 'سرمایهٔ اولیه', 1);
        $this->ledger()->recordPayment($this->paidInvoice(5_000_000, 0));
        $this->ledger()->manual('expense', 3_000_000, 'server', now(), null, 1);

        $s = $this->ledger()->summary();
        // سود = 5m - 3m = 2m ؛ سرمایهٔ خالص = 10m ؛ ROI = 20%
        $this->assertSame(2_000_000, $s['net_profit']);
        $this->assertSame(10_000_000, $s['net_capital']);
        $this->assertSame(20.0, $s['roi']);
    }

    public function test_tax_liability_is_collected_minus_paid(): void
    {
        $this->ledger()->recordPayment($this->paidInvoice(1_000_000, 100_000));
        $this->ledger()->recordPayment($this->paidInvoice(1_000_000, 100_000));
        $this->ledger()->manual('tax_paid', 60_000, null, now(), 'پرداخت فصلی', 1);

        $s = $this->ledger()->summary();
        $this->assertSame(200_000, $s['tax_collected']);
        $this->assertSame(60_000, $s['tax_paid']);
        $this->assertSame(140_000, $s['tax_liability']);
    }

    public function test_cash_is_everything_in_minus_everything_out(): void
    {
        $this->ledger()->manual('capital', 10_000_000, null, now(), null, 1);      // +10m
        $this->ledger()->recordPayment($this->paidInvoice(2_000_000, 200_000));    // +2.2m (rev+tax)
        $this->ledger()->manual('expense', 1_500_000, 'server', now(), null, 1);   // −1.5m
        $this->ledger()->manual('withdrawal', 3_000_000, null, now(), null, 1);    // −3m

        // 10 + 2.2 − 1.5 − 3 = 7.7m
        $this->assertSame(7_700_000, $this->ledger()->summary()['cash']);
    }

    public function test_capital_can_be_injected_multiple_times(): void
    {
        // چند سرمایه‌گذاری نباید با هم برخورد کنند (منبع null، NULLها متمایزند)
        $this->ledger()->manual('capital', 1_000_000, null, now(), 'قسط اول', 1);
        $this->ledger()->manual('capital', 2_000_000, null, now(), 'قسط دوم', 1);

        $this->assertSame(3_000_000, $this->ledger()->summary()['capital']);
        $this->assertSame(2, BusinessEntry::where('kind', 'capital')->count());
    }

    public function test_api_cost_records_the_configured_amount(): void
    {
        config(['finance.costs.identity' => 68_000]);

        $this->ledger()->recordApiCost('api_kyc', 'identity', 'مشتری تست');

        $s = $this->ledger()->summary();
        $this->assertSame(68_000, $s['expense']);
        $this->assertSame('api_kyc', BusinessEntry::where('kind', 'expense')->first()->category);
    }

    public function test_expense_breakdown_by_category(): void
    {
        $this->ledger()->manual('expense', 1_000_000, 'server', now(), null, 1);
        $this->ledger()->manual('expense', 500_000, 'api_kyc', now(), null, 1);
        $this->ledger()->manual('expense', 300_000, 'server', now(), null, 1);

        $by = $this->ledger()->summary()['by_category'];
        $this->assertSame(1_300_000, $by['server']);
        $this->assertSame(500_000, $by['api_kyc']);
    }

    public function test_the_dashboard_is_reachable_by_staff(): void
    {
        $staff = User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1, 999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        $this->actingAs($staff, 'web')->get('/admin/finance')->assertOk();
    }

    public function test_the_dashboard_is_forbidden_to_customers(): void
    {
        $customer = Customer::create(['email' => 'c@x.com', 'password' => 'secret1234', 'status' => 'active']);

        // guard مشتری هیچ دسترسی‌ای به /admin ندارد
        $this->actingAs($customer, 'customer')->get('/admin/finance')->assertRedirect(route('admin.login'));
    }

    public function test_auto_entries_cannot_be_deleted(): void
    {
        $staff = User::create([
            'name' => 'مدیر', 'email' => 'b'.random_int(1, 999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
        $this->ledger()->recordPayment($this->paidInvoice(1_000_000, 0));
        $auto = BusinessEntry::where('kind', 'revenue')->first();

        $this->actingAs($staff, 'web')->post("/admin/finance/{$auto->id}/delete")
            ->assertSessionHasErrors('entry');

        $this->assertDatabaseHas('business_ledger', ['id' => $auto->id]);
    }

    // ═══════ کارمزد درگاه — ممیزی شهریور ۱۴۰۵ ═══════

    public function test_gateway_fee_paid_by_us_becomes_an_expense(): void
    {
        $payment = $this->paidInvoice(subtotal: 1_000_000, tax: 0);
        $payment->forceFill(['fee' => 12_000, 'fee_type' => 'Merchant'])->save();

        $this->ledger()->recordPayment($payment);

        $s = $this->ledger()->summary();
        $this->assertSame(12_000, $s['expense']);
        $this->assertSame(12_000, $s['by_category']['payment_fee'] ?? 0);
        // و سود دقیقاً به همان اندازه کمتر می‌شود — همان تورمی که ممیزی پیدا کرد
        $this->assertSame(988_000, $s['net_profit']);
    }

    public function test_gateway_fee_paid_by_the_customer_is_not_our_expense(): void
    {
        $payment = $this->paidInvoice(subtotal: 1_000_000, tax: 0);
        $payment->forceFill(['fee' => 12_000, 'fee_type' => 'Payer'])->save();

        $this->ledger()->recordPayment($payment);

        $this->assertSame(0, $this->ledger()->summary()['expense']);
    }

    public function test_an_unknown_fee_bearer_is_never_guessed(): void
    {
        $payment = $this->paidInvoice(subtotal: 1_000_000, tax: 0);
        $payment->forceFill(['fee' => 12_000, 'fee_type' => null])->save();

        $this->ledger()->recordPayment($payment);

        // حدس وارد دفتر نمی‌شود — همان قاعدهٔ recordApiCost
        $this->assertSame(0, $this->ledger()->summary()['expense']);
    }

    public function test_fee_on_a_wallet_topup_is_still_our_expense(): void
    {
        // شارژ کیف پول درآمد نیست، ولی درگاه کارمزدش را گرفته و پول رفته.
        $payment = $this->paidInvoice(subtotal: 500_000, tax: 0, kind: 'topup');
        $payment->forceFill(['fee' => 5_000, 'fee_type' => 'Merchant'])->save();

        $this->ledger()->recordPayment($payment);

        $s = $this->ledger()->summary();
        $this->assertSame(0, $s['revenue'], 'شارژ اعتبار نباید درآمد بسازد');
        $this->assertSame(5_000, $s['expense'], 'ولی کارمزدش هزینهٔ واقعی است');
    }

    public function test_recording_a_payment_twice_does_not_double_the_fee(): void
    {
        $payment = $this->paidInvoice(subtotal: 1_000_000, tax: 0);
        $payment->forceFill(['fee' => 12_000, 'fee_type' => 'Merchant'])->save();

        $this->ledger()->recordPayment($payment);
        $this->ledger()->recordPayment($payment);

        $this->assertSame(12_000, $this->ledger()->summary()['expense']);
    }

    // ═══════ قطع زمانی ═══════

    public function test_an_event_after_midnight_utc_lands_on_the_tehran_day(): void
    {
        // ۲۲:۳۰ به وقت UTC = ۰۲:۰۰ روزِ بعد به وقت تهران.
        // با toDateString خام، این ردیف یک روز عقب می‌نشست — و برای تراکنشِ
        // شبِ آخرِ سال یعنی درآمد در سالِ مالیِ اشتباه.
        $payment = $this->paidInvoice(subtotal: 300_000, tax: 0);
        $payment->forceFill(['paid_at' => \Illuminate\Support\Carbon::parse('2026-03-20 22:30:00', 'UTC')])->save();

        $this->ledger()->recordPayment($payment);

        $this->assertSame('2026-03-21',
            BusinessEntry::where('kind', 'revenue')->value('occurred_at')->toDateString());
    }

    public function test_reading_the_ledger_does_not_move_the_payments_own_timestamp(): void
    {
        $payment = $this->paidInvoice(subtotal: 300_000, tax: 0);
        $at = \Illuminate\Support\Carbon::parse('2026-03-20 22:30:00', 'UTC');
        $payment->forceFill(['paid_at' => $at])->save();

        $this->ledger()->recordPayment($payment);

        // setTimezone نمونه را جابه‌جا می‌کند؛ بدونِ copy() صداکننده هم عوض می‌شد
        $this->assertSame('UTC', $payment->paid_at->timezone->getName());
    }

    // ═══════ ارزهای غیرِ پایه ═══════

    public function test_foreign_currency_rows_are_shown_separately_not_summed(): void
    {
        $eur = $this->paidInvoice(subtotal: 1_000, tax: 0);
        $eur->invoice->forceFill(['currency_code' => 'EUR'])->save();
        $eur->forceFill(['currency_code' => 'EUR'])->save();
        $eur->refresh();

        $this->ledger()->recordPayment($eur);

        $s = $this->ledger()->summary();
        // با تومان جمع نمی‌شود — نرخِ تسعیر نداریم و حدس ممنوع است
        $this->assertSame(0, $s['revenue']);
        // ولی ناپدید هم نمی‌شود
        $this->assertSame(1_000, $s['by_currency']['EUR']['revenue'] ?? 0);
    }

    /**
     * صفحه با ردیفِ ارزی هم باید باز شود.
     *
     * تست‌های موجود /admin/finance را فقط با دفترِ تومانی می‌زدند، پس بلوکِ
     * تازه هرگز اجرا نمی‌شد. صفحهٔ مالی یک بار با ParseError کلِ ۵۰۰ شده —
     * بلوکی که هیچ تستی از آن رد نشود، دقیقاً همان‌جور می‌شکند.
     */
    public function test_the_finance_page_renders_with_a_foreign_currency_row(): void
    {
        $staff = User::create([
            'name' => 'مدیر', 'email' => 'fx'.random_int(1, 999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        $eur = $this->paidInvoice(subtotal: 2_500, tax: 0);
        $eur->invoice->forceFill(['currency_code' => 'EUR'])->save();
        $eur->forceFill(['currency_code' => 'EUR'])->save();
        $this->ledger()->recordPayment($eur->refresh());

        $html = $this->actingAs($staff, 'web')->get('/admin/finance')
            ->assertOk()->getContent();

        $this->assertStringContainsString('EUR', $html);
        $this->assertStringContainsString('ردیف‌های ارزی', $html);
    }
}
