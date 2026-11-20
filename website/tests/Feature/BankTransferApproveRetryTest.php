<?php

namespace Tests\Feature;

use App\Models\BankTransferReceipt;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Service;
use App\Services\Billing\BankReceiptReviewer;
use App\Services\Payment\PaymentService;
use App\Services\Payment\SettleOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * تأییدِ «واریز به حساب» باید idempotent باشد.
 *
 * ⚠️ باگِ واقعیِ پروداکشن: ردیفِ Payment بیرونِ تراکنشِ تسویه ساخته و کامیت
 * می‌شد. اگر تسویه بعدش می‌شکست، رسید pending می‌ماند ولی پرداخت باقی بود؛
 * تلاشِ دوبارهٔ مدیر به یکتاییِ external_ref می‌خورد:
 *   Duplicate entry '687646546' for key 'payments_external_ref_unique'
 * یعنی تأییدِ آن رسید **برای همیشه** قفل می‌شد و پولِ مشتری معلق می‌ماند.
 */
class BankTransferApproveRetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 's'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function receipt(string $reference): BankTransferReceipt
    {
        $customer = Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 275000, 'tax' => 0, 'total' => 275000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        return BankTransferReceipt::create([
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'reference' => $reference, 'amount' => 275000, 'status' => 'pending',
            'paid_at' => now(),
        ]);
    }

    /**
     * سناریوی واقعی: پرداختی با همان شناسه از تلاشِ شکست‌خوردهٔ قبلی مانده.
     * تأییدِ دوباره باید کار کند، نه ۵۰۰ بدهد.
     */
    public function test_approve_succeeds_when_a_payment_row_already_exists(): void
    {
        $receipt = $this->receipt('687646546');

        // بازماندهٔ تلاشِ قبلی
        Payment::create([
            'invoice_id' => $receipt->invoice_id, 'customer_id' => $receipt->customer_id,
            'gateway' => 'bank_transfer', 'currency_code' => 'IRT', 'amount' => 275000,
            'status' => 'redirected', 'external_ref' => '687646546',
        ]);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/bank-transfers/{$receipt->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // پرداختِ تکراری ساخته نشده
        $this->assertSame(1, Payment::where('external_ref', '687646546')->count());

        // و رسید بسته و فاکتور تسویه شده
        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertSame('paid', $receipt->invoice->fresh()->status);
    }

    /** تأییدِ عادی (بدونِ بازمانده) هم مثلِ قبل کار کند */
    public function test_normal_approve_still_settles_the_invoice(): void
    {
        $receipt = $this->receipt('999111');

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/bank-transfers/{$receipt->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertSame('paid', $receipt->invoice->fresh()->status);
        $this->assertSame(1, Payment::where('external_ref', BankReceiptReviewer::paymentKey($receipt))->count());
    }

    /** دو بار زدنِ دکمهٔ تأیید نباید دو بار پول به اعتبار بنشیند */
    public function test_double_approve_does_not_double_credit(): void
    {
        $receipt = $this->receipt('555222');
        $staff = $this->staff();

        $this->actingAs($staff, 'web')->post("/admin/bank-transfers/{$receipt->id}/approve");
        $this->actingAs($staff, 'web')->post("/admin/bank-transfers/{$receipt->id}/approve");

        $this->assertSame(1, Payment::where('external_ref', BankReceiptReviewer::paymentKey($receipt))->count());
        $this->assertSame(275000, (int) $receipt->invoice->fresh()->paid);
    }
    public function test_reused_reference_settles_its_own_invoice_without_changing_old_money(): void
    {
        $old = $this->receipt('REUSED');
        app(BankReceiptReviewer::class)->approve($old);
        // A legacy payment used the untrusted bank reference as its global key.
        $oldPayment = Payment::where('invoice_id', $old->invoice_id)->sole();
        $oldPayment->update(['external_ref' => 'REUSED']);
        $oldState = $oldPayment->fresh()->getAttributes();
        $next = $this->receipt('REUSED');

        $this->assertTrue(app(BankReceiptReviewer::class)->approve($next)['ok']);
        $this->assertSame('paid', $next->invoice->fresh()->status);
        $this->assertSame($oldState, $oldPayment->fresh()->getAttributes());
        $this->assertSame(2, Payment::where('status', 'paid')->count());
    }

    public function test_renewal_with_reused_reference_extends_one_period_and_stale_retry_does_not_extend_again(): void
    {
        $old = $this->receipt('RENEW-REUSED');
        app(BankReceiptReviewer::class)->approve($old);
        Payment::where('invoice_id', $old->invoice_id)->update(['external_ref' => 'RENEW-REUSED']);
        $next = $this->receipt('RENEW-REUSED');
        $service = Service::create(['customer_id' => $next->customer_id, 'name' => 'Recurring service',
            'price' => 275000, 'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'active',
            'activated_at' => now()->subMonth(), 'next_due_at' => now()->addDays(5)->startOfDay(),
            'provision_status' => 'done', 'reminder_stage' => '3']);
        $next->invoice->update(['kind' => 'service', 'service_id' => $service->id]);
        $expected = $service->nextDueFrom($service->next_due_at)->toDateString();
        $stale = BankTransferReceipt::findOrFail($next->id);

        $this->assertTrue(app(BankReceiptReviewer::class)->approve($next)['ok']);
        $this->assertFalse(app(BankReceiptReviewer::class)->approve($stale)['ok']);
        $this->assertSame($expected, $service->fresh()->next_due_at->toDateString());
        $this->assertNull($service->fresh()->reminder_stage);
        $this->assertSame(1, Payment::where('invoice_id', $next->invoice_id)->count());
    }

    public function test_missing_invoice_and_wrong_owner_are_not_approved(): void
    {
        $receipt = $this->receipt('BAD-OWNER');
        $other = $this->receipt('OTHER');
        $receipt->update(['invoice_id' => $other->invoice_id]);
        $this->assertFalse(app(BankReceiptReviewer::class)->approve($receipt)['ok']);
        $this->assertSame('pending', $receipt->fresh()->status);
        $receipt->update(['invoice_id' => null]);
        $this->assertFalse(app(BankReceiptReviewer::class)->approve($receipt)['ok']);
        $this->assertSame(0, Payment::count());
    }

    public function test_changed_invoice_balance_requires_reconciliation_instead_of_inventing_payment_amount(): void
    {
        $receipt = $this->receipt('AMOUNT');
        $receipt->invoice->update(['paid' => 1]);
        $this->assertFalse(app(BankReceiptReviewer::class)->approve($receipt)['ok']);
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_false_settlement_outcome_rolls_back_and_does_not_approve(): void
    {
        $receipt = $this->receipt('FAIL');
        // Load the outcome class declared alongside PaymentService before mocking it.
        app(PaymentService::class);
        $this->mock(PaymentService::class)->shouldReceive('settleConfirmed')->once()
            ->andReturnUsing(fn ($payment) => new SettleOutcome(false, $payment, error: 'fixture failure'));
        $this->assertFalse(app(BankReceiptReviewer::class)->approve($receipt)['ok']);
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame('unpaid', $receipt->invoice->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_paid_legacy_attempt_can_finish_receipt_without_double_credit(): void
    {
        $receipt = $this->receipt('COMMITTED');
        $payment = Payment::create(['external_ref' => 'COMMITTED', 'invoice_id' => $receipt->invoice_id,
            'customer_id' => $receipt->customer_id, 'gateway' => 'bank_transfer', 'currency_code' => 'IRT',
            'amount' => $receipt->amount, 'status' => 'redirected']);
        app(PaymentService::class)->settleConfirmed($payment, 'COMMITTED');
        $this->assertTrue(app(BankReceiptReviewer::class)->approve($receipt)['ok']);
        $this->assertSame(1, Payment::count());
        $this->assertSame(275000, (int) $receipt->invoice->fresh()->paid);
    }

    public function test_audit_is_read_only_and_explicit_collision_repair_runs_once(): void
    {
        $old = $this->receipt('HISTORICAL');
        app(BankReceiptReviewer::class)->approve($old);
        Payment::where('invoice_id', $old->invoice_id)->update(['external_ref' => 'HISTORICAL']);
        $receipt = $this->receipt('HISTORICAL');
        $reviewed = now()->subDay()->startOfSecond();
        $receipt->update(['status' => 'approved', 'reviewed_at' => $reviewed]);
        $this->artisan('billing:bank-receipts')->assertSuccessful();
        $this->assertSame('unpaid', $receipt->invoice->fresh()->status);
        $this->artisan('billing:bank-receipts', ['--apply' => true])->assertFailed();
        $this->artisan('billing:bank-receipts', ['--receipt' => $receipt->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('paid', $receipt->invoice->fresh()->status);
        $this->assertTrue($reviewed->equalTo($receipt->fresh()->reviewed_at));
        $this->assertFalse(app(BankReceiptReviewer::class)->reconcileApproved($receipt)['ok']);
        $this->assertSame(2, Payment::count());
    }

    public function test_repair_refuses_approved_receipt_without_evidence_of_reference_collision(): void
    {
        $receipt = $this->receipt('NO-COLLISION');
        $receipt->update(['status' => 'approved']);
        $this->assertFalse(app(BankReceiptReviewer::class)->reconcileApproved($receipt)['ok']);
        $this->assertSame('unpaid', $receipt->invoice->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    public function test_conflicting_receipt_payment_identity_is_not_reused(): void
    {
        $receipt = $this->receipt('BINDING');
        Payment::create(['external_ref' => BankReceiptReviewer::paymentKey($receipt),
            'invoice_id' => $receipt->invoice_id, 'customer_id' => $receipt->customer_id,
            'gateway' => 'zarinpal', 'currency_code' => 'IRT', 'amount' => $receipt->amount,
            'status' => 'paid']);
        $this->assertFalse(app(BankReceiptReviewer::class)->approve($receipt)['ok']);
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame('unpaid', $receipt->invoice->fresh()->status);
        $this->assertSame(1, Payment::count());
    }

    public function test_stale_rejection_cannot_overwrite_a_committed_approval(): void
    {
        $receipt = $this->receipt('REVIEW-RACE');
        $stale = BankTransferReceipt::findOrFail($receipt->id);
        $this->assertTrue(app(BankReceiptReviewer::class)->approve($receipt)['ok']);
        $this->assertFalse(app(BankReceiptReviewer::class)->reject($stale, 'stale')['ok']);
        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertSame('paid', $receipt->invoice->fresh()->status);
    }

}
