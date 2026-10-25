<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\BankReconciliation;
use App\Services\Finance\DocumentedAccounting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceBankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $currency = 'IRT'): FinanceAccount
    {
        return FinanceAccount::create(['name' => 'Business bank', 'type' => 'bank', 'currency_code' => $currency, 'created_by' => 1]);
    }

    private function payment(int $amount = 1010, string $currency = 'IRT', string $gateway = 'zarinpal'): Payment
    {
        $c = Customer::create(['email' => Str::uuid().'@test.invalid', 'password' => 'test', 'status' => 'active']);
        $inv = Invoice::create(['customer_id' => $c->id, 'kind' => 'topup', 'currency_code' => $currency, 'subtotal' => $amount, 'tax' => 0, 'total' => $amount, 'paid' => $amount, 'status' => 'paid']);

        return Payment::create(['customer_id' => $c->id, 'invoice_id' => $inv->id, 'gateway' => $gateway, 'currency_code' => $currency, 'amount' => $amount, 'status' => 'paid', 'paid_at' => now()]);
    }

    private function import(FinanceAccount $a): BankReconciliation
    {
        $s = app(BankReconciliation::class);
        $date = now('Asia/Tehran')->toDateString();
        $s->import($a, "date,reference,credit,debit,currency\n".$date.',REF-100,1000,0,'.$a->currency_code."\n", 'private-test.csv', 1);

        return $s;
    }

    public function test_statement_import_is_idempotent_and_never_creates_revenue_payments_or_wallet_credit(): void
    {
        $a = $this->account();
        $s = $this->import($a);
        $this->import($a);
        $this->assertSame(1, DB::table('finance_bank_transactions')->count());
        $this->assertSame(0, BusinessEntry::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_net_settlement_and_actual_fee_match_a_gross_payment_without_posting_duplicate_expenses(): void
    {
        $s = $this->import($this->account());
        $p = $this->payment();
        $id = DB::table('finance_bank_transactions')->value('id');
        $match = $s->match($id, $p->id, 1000, 10, 'Gateway batch evidence', 1);
        $this->assertSame(1, DB::table('finance_bank_matches')->count());
        $this->assertSame(0, BusinessEntry::count());
        $s->void($match, 'Wrong batch allocation', 1);
        $this->assertNotNull(DB::table('finance_bank_matches')->value('voided_at'));
        $s->match($id, $p->id, 1000, 10, 'Corrected allocation', 1);
        $this->assertSame(1, DB::table('finance_bank_matches')->whereNull('voided_at')->count());
    }

    public function test_bank_and_payment_allocation_cannot_exceed_actual_receipts(): void
    {
        $s = $this->import($this->account());
        $p = $this->payment();
        $id = DB::table('finance_bank_transactions')->value('id');
        $s->match($id, $p->id, 500, 5, 'First allocation', 1);
        $this->expectException(ValidationException::class);
        $s->match($id, $p->id, 501, 5, 'Duplicate excess', 1);
    }

    public function test_internal_wallet_payment_cannot_be_matched_as_external_bank_cash(): void
    {
        $s = $this->import($this->account());
        $p = $this->payment(1000, 'IRT', 'credit');
        $id = DB::table('finance_bank_transactions')->value('id');
        $this->expectException(ValidationException::class);
        $s->match($id, $p->id, 1000, 0, 'Not an external payment', 1);
    }

    public function test_foreign_and_toman_receipts_cannot_be_matched_together(): void
    {
        $s = $this->import($this->account());
        $p = $this->payment(100000, 'EUR');
        $id = DB::table('finance_bank_transactions')->value('id');
        $this->expectException(ValidationException::class);
        $s->match($id, $p->id, 1000, 0, 'Wrong currency', 1);
    }

    public function test_reconciliation_screen_renders_and_only_admin_can_read_it(): void
    {
        $this->import($this->account());
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.invalid', 'password' => 'test', 'role' => 'admin']);
        $this->actingAs($admin)->get('/admin/finance/bank-reconciliation')->assertOk()->assertSee('REF-100');
        $admin->update(['role' => 'staff']);
        $this->get('/admin/finance/bank-reconciliation')->assertForbidden();
    }

    public function test_receipt_kpis_use_dates_and_never_add_foreign_minor_units_to_toman_topups(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25', 'UTC'));
        try {
            $this->payment(1000);
            $this->payment(100000, 'EUR');
            $this->payment(2000, 'IRT', 'credit');
            $old = $this->payment(5000);
            $old->update(['paid_at' => Carbon::parse('2026-09-03')]);
            $admin = User::create(['name' => 'Admin', 'email' => 'finance@test.invalid', 'password' => 'test', 'role' => 'admin']);
            $this->actingAs($admin)->get('/admin/transactions?from=2026-10-01&to=2026-10-25')->assertOk()
                ->assertViewHas('kpis', fn ($k) => $k['paidSum'] === 1000 && $k['topups'] === 1000);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_posting_a_bank_receipt_increases_bank_balance_without_new_sales_or_wallet_topups(): void
    {
        $a = $this->account();
        $this->import($a);
        $bank = DB::table('finance_bank_transactions')->first();
        $admin = User::create(['name' => 'Admin', 'email' => 'posting@test.invalid', 'password' => 'test', 'role' => 'admin']);
        $this->actingAs($admin)->post('/admin/finance/bank-reconciliation/post-collection', ['bank_id' => $bank->id, 'amount_irt' => '1000'])->assertRedirect();
        $this->assertSame(['irt' => 1000, 'native' => 1000], app(DocumentedAccounting::class)->balance($a));
        $this->assertSame(0, BusinessEntry::count());
        $this->assertSame(0, CreditEntry::count());
    }
}
