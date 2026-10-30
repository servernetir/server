<?php

namespace Tests\Feature;

use App\Models\BankTransferReceipt;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\ApprovedBankReceiptRecovery;
use App\Services\Payment\PaymentService;
use App\Services\Payment\SettleOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApprovedBankReceiptRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
    }

    private function fixture(): array
    {
        $customer = Customer::create(['email' => 'recovery@example.test', 'password' => 'x', 'status' => 'active', 'locale' => 'fa']);
        $staff = User::create(['name' => 'Fixture reviewer', 'email' => 'reviewer@example.test', 'password' => bcrypt('fixture'), 'role' => 'admin']);
        $invoiceData = ['customer_id' => $customer->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 275000, 'total' => 275000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()];
        $oldInvoice = Invoice::create(['paid' => 1000, 'total' => 1000, 'status' => 'paid'] + $invoiceData);
        $oldPayment = Payment::create(['invoice_id' => $oldInvoice->id, 'customer_id' => $customer->id,
            'gateway' => 'bank_transfer', 'currency_code' => 'IRT', 'amount' => 1000,
            'status' => 'paid', 'paid_at' => now()->subMonth(), 'external_ref' => 'fixture-reference']);
        $invoice = Invoice::create($invoiceData);
        $receipt = BankTransferReceipt::create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'amount' => 275000, 'reference' => 'fixture-reference', 'status' => 'approved',
            'reviewed_by' => $staff->id, 'reviewed_at' => now()]);

        return [$receipt, $invoice, $oldPayment];
    }

    private function runRecovery(BankTransferReceipt $receipt, bool $apply = false): array
    {
        return app(ApprovedBankReceiptRecovery::class)->run($receipt->id, $receipt->customer_id,
            $receipt->invoice_id, 275000, $apply);
    }

    public function test_preview_is_read_only(): void
    {
        [$receipt, $invoice] = $this->fixture();
        $this->assertSame('eligible', $this->runRecovery($receipt)['status']);
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_recovery_settles_once_and_preserves_older_payment(): void
    {
        [$receipt, $invoice, $oldPayment] = $this->fixture();
        $old = $oldPayment->fresh()->getRawOriginal();
        $this->assertSame('recovered', $this->runRecovery($receipt, true)['status']);
        $this->assertSame('already_recovered', $this->runRecovery($receipt, true)['status']);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(275000, (int) $invoice->fresh()->paid);
        $this->assertSame(275000, (int) CreditEntry::sum('amount'));
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(2, Payment::count());
        $this->assertSame($old, $oldPayment->fresh()->getRawOriginal());
        $this->assertSame('fixture-reference', Payment::where('invoice_id', $invoice->id)->sole()->ref_id);
    }

    public function test_wrong_expected_amount_or_customer_never_writes(): void
    {
        [$receipt] = $this->fixture();
        foreach ([[$receipt->customer_id, 1], [$receipt->customer_id + 1, 275000]] as [$customer, $amount]) {
            try {
                app(ApprovedBankReceiptRecovery::class)->run($receipt->id, $customer, $receipt->invoice_id, $amount, true);
                $this->fail('Expected guard rejection.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('does not match', $error->getMessage());
            }
        }
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_existing_invoice_credit_blocks_recovery(): void
    {
        [$receipt, $invoice] = $this->fixture();
        CreditEntry::create(['customer_id' => $receipt->customer_id, 'currency_code' => 'IRT',
            'amount' => 275000, 'balance_after' => 275000, 'reason' => 'topup',
            'source_type' => Invoice::class, 'source_id' => $invoice->id]);
        $this->expectExceptionMessage('already has financial effects');
        $this->runRecovery($receipt, true);
    }

    public function test_pending_receipt_is_not_a_recovery_authorization(): void
    {
        [$receipt] = $this->fixture();
        $receipt->update(['status' => 'pending']);
        $this->expectExceptionMessage('does not match');
        $this->runRecovery($receipt, true);
    }

    public function test_failed_settlement_rolls_back_recovery_payment(): void
    {
        [$receipt, $invoice] = $this->fixture();
        $mock = \Mockery::mock(PaymentService::class);
        $mock->shouldReceive('settleConfirmed')->once()->andReturn(new SettleOutcome(false));
        $this->app->instance(PaymentService::class, $mock);
        try {
            $this->runRecovery($receipt, true);
            $this->fail('Settlement failure must roll back.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('rolled back', $error->getMessage());
        }
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_command_requires_exact_target_and_defaults_to_preview(): void
    {
        [$receipt] = $this->fixture();
        $this->artisan('billing:recover-approved-bank-receipt', ['receipt' => $receipt->id])->assertFailed();
        $this->artisan('billing:recover-approved-bank-receipt', ['receipt' => $receipt->id,
            '--customer' => $receipt->customer_id, '--invoice' => $receipt->invoice_id, '--amount' => 275000])
            ->expectsOutputToContain('eligible')->assertSuccessful();
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, CreditEntry::count());
    }
}
