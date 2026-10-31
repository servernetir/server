<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceVoucher;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\BankReconciliation;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\DocumentedAccounting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceSettlementUnitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Tehran')->setDate(2026, 10, 31)->setTime(12, 0));
    }

    private function bank(string $currency, int $credit): array
    {
        $account = FinanceAccount::create(['name' => 'Synthetic company bank', 'type' => 'bank',
            'currency_code' => $currency, 'owner_name' => 'Synthetic legal entity', 'created_by' => 1]);
        app(BankReconciliation::class)->import($account,
            "date,reference,credit,debit,currency\n2026-10-31,SYNTHETIC-".$account->id.",".$credit.",0,".$currency."\n",
            'synthetic-private.csv', 1);

        return [$account, (int) DB::table('finance_bank_transactions')->where('account_id', $account->id)->value('id')];
    }

    private function payment(int $amount, string $currency = 'IRT', int $fee = 0): Payment
    {
        $customer = Customer::create(['email' => Str::uuid().'@example.test', 'status' => 'active']);
        $invoice = Invoice::create(['customer_id' => $customer->id, 'kind' => 'hosting', 'currency_code' => $currency,
            'subtotal' => $amount, 'tax' => 0, 'total' => $amount, 'paid' => $amount, 'status' => 'paid']);
        return Payment::create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'gateway' => 'zarinpal',
            'currency_code' => $currency, 'amount' => $amount, 'fee' => $fee, 'fee_type' => 'Merchant',
            'status' => 'paid', 'paid_at' => now()]);
    }

    public function test_rial_net_settlement_links_toman_sale_and_existing_commission_once(): void
    {
        [$bank, $row] = $this->bank('IRR', 10000);
        $payment = $this->payment(1010, 'IRT', 10);
        $ledger = app(BusinessLedger::class);
        $ledger->recordPayment($payment);
        $ledger->recordPayment($payment);
        $this->assertSame(1010, (int) BusinessEntry::where('kind', 'revenue')->sum('amount'));
        $this->assertSame(10, (int) BusinessEntry::where('kind', 'expense')->sum('amount'));
        $before = BusinessEntry::count();
        app(BankReconciliation::class)->match($row, $payment->id, 10000, 100, 'Synthetic gateway settlement', 1);
        $payload = ['idempotency_key' => (string) Str::uuid(), 'type' => 'bank_collection', 'occurred_at' => '2026-10-31',
            'service_line' => 'other', 'category' => 'other', 'amount_irt' => 1000, 'original_currency' => 'IRR', 'original_minor' => 10000,
            'settlement_currency' => null, 'settlement_minor' => 0, 'target_account_id' => $bank->id,
            'bank_transaction_id' => $row, 'bank_reference' => 'SYNTHETIC-NET', 'note' => 'Net bank credit, sale already recognized',
            'tax_status' => 'pending', 'vat_status' => 'none'];
        $journal = app(DocumentedAccounting::class);
        $voucher = $journal->post($payload, 1);
        $this->assertSame($voucher->id, $journal->post($payload, 1)->id);
        $this->assertSame(['irt' => 1000, 'native' => 10000], $journal->balance($bank));
        $this->assertSame($before, BusinessEntry::count());
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame(1, FinanceVoucher::count());
        $this->assertSame(10000, (int) DB::table('finance_bank_matches')->value('amount_minor'));
        $this->assertSame(100, (int) DB::table('finance_bank_matches')->value('fee_minor'));
    }

    public function test_allocations_from_rial_and_toman_banks_share_one_payment_limit(): void
    {
        [, $toman] = $this->bank('IRT', 500);
        [, $rial] = $this->bank('IRR', 10000);
        $payment = $this->payment(1010);
        $service = app(BankReconciliation::class);
        $service->match($toman, $payment->id, 500, 5, 'Synthetic first part', 1);
        $service->match($rial, $payment->id, 5000, 50, 'Synthetic second part', 1);
        $this->assertSame(2, DB::table('finance_bank_matches')->count());
        try {
            $service->match($rial, $payment->id, 1, 0, 'Synthetic excess rial', 1);
            $this->fail('A gross payment must not be allocated twice across currencies.');
        } catch (ValidationException) {
            $this->assertSame(2, DB::table('finance_bank_matches')->count());
            $this->assertSame(0, BusinessEntry::count());
        }
    }

    public function test_rial_payment_can_match_toman_statement_without_currency_guessing(): void
    {
        [, $row] = $this->bank('IRT', 1000);
        $payment = $this->payment(10100, 'IRR');
        app(BankReconciliation::class)->match($row, $payment->id, 1000, 10, 'Synthetic explicit unit conversion', 1);
        $this->assertSame(1, DB::table('finance_bank_matches')->count());
        $this->assertSame(1000, (int) DB::table('finance_bank_matches')->value('amount_minor'));
    }

    public function test_bank_collection_rejects_wrong_rial_equivalent_before_any_posting(): void
    {
        [$bank, $row] = $this->bank('IRR', 10000);
        try {
            app(DocumentedAccounting::class)->post(['idempotency_key' => (string) Str::uuid(), 'type' => 'bank_collection',
                'occurred_at' => '2026-10-31', 'service_line' => 'other', 'category' => 'other', 'amount_irt' => 10000,
                'original_currency' => 'IRR', 'original_minor' => 10000, 'settlement_currency' => null,
                'settlement_minor' => 0, 'target_account_id' => $bank->id, 'bank_transaction_id' => $row,
                'bank_reference' => 'SYNTHETIC-WRONG-UNIT', 'note' => 'Synthetic wrong rial conversion',
                'tax_status' => 'pending', 'vat_status' => 'none'], 1);
            $this->fail('Rials cannot be silently posted as the same number of tomans.');
        } catch (ValidationException) {
            $this->assertSame(0, FinanceVoucher::count());
            $this->assertSame(['irt' => 0, 'native' => 0], app(DocumentedAccounting::class)->balance($bank));
            $this->assertSame(0, BusinessEntry::count());
        }
    }
}
