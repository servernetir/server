<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\AccountController;
use App\Models\BankAccount;
use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\FinanceLine;
use App\Models\FinanceVoucher;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletRefundRequest;
use App\Services\Finance\AccountingReport;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\TaxEvidenceBundle;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use App\Services\Finance\WalletRefunds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class WalletRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $admin;

    private BankAccount $bank;

    private Wallet $wallet;

    private WalletRefunds $refunds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-25 08:00:00', 'UTC'));
        Http::fake();
        Mail::fake();
        Storage::fake('local');
        Wallet::flushSchemaCache();
        $this->wallet = app(Wallet::class);
        $this->refunds = app(WalletRefunds::class);
        $this->admin = User::create(['name' => 'Refund admin', 'email' => 'admin@example.test', 'role' => 'admin', 'password' => bcrypt('test')]);
        $this->customer = $this->newCustomer();
        $bban = '0100000000000000000001';
        $iban = 'IR'.str_pad((string) (98 - (int) bcmod($bban.'182700', '97')), 2, '0', STR_PAD_LEFT).$bban;
        $this->bank = BankAccount::create(['customer_id' => $this->customer->id, 'iban' => $iban, 'card_bin' => '603799',
            'card_last4' => '1234', 'owner_name' => 'Test Customer', 'name_matched' => true, 'status' => 'verified', 'verified_at' => now()]);
        $this->wallet->credit($this->customer->id, 'IRT', 100000, 'topup', null);
    }

    private function newCustomer(): Customer
    {
        return Customer::create(['email' => uniqid('customer').'@example.test', 'password' => bcrypt('test'),
            'phone' => '0912'.random_int(1000000, 9999999), 'status' => 'active', 'locale' => 'fa']);
    }

    private function enable(): void
    {
        $this->refunds->permission($this->customer, true, 'Special refund approved', $this->admin);
    }

    private function requestRefund(int $amount = 40000): WalletRefundRequest
    {
        $this->enable();

        return $this->refunds->request($this->customer, (string) Str::uuid(), $amount, $this->bank->id, 'Requesting unused balance');
    }

    private function approve(WalletRefundRequest $r, ?int $source = null): WalletRefundRequest
    {
        return $this->refunds->approve($r, $this->admin, 'Reviewed and approved', $source, 'Unused wallet funds; supporting bank receipt reviewed');
    }

    private function funding(string $currency = 'IRT', int $amount = 500000): FinanceAccount
    {
        $a = FinanceAccount::create(['name' => 'Company bank', 'type' => 'bank', 'currency_code' => $currency, 'created_by' => $this->admin->id]);
        app(DocumentedAccounting::class)->post(['idempotency_key' => (string) Str::uuid(), 'type' => 'opening_balance',
            'occurred_at' => '2026-10-25', 'service_line' => 'other', 'category' => 'opening', 'amount_irt' => $amount,
            'original_currency' => $currency, 'original_minor' => $currency === 'IRR' ? $amount * 10 : $amount,
            'target_account_id' => $a->id, 'bank_reference' => 'REAL-OPENING-PROOF', 'note' => 'Documented company bank opening',
            'tax_status' => 'pending', 'vat_status' => 'none'], $this->admin->id);

        return $a;
    }

    private function receipt(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nBank transfer evidence\n");
    }

    private function settle(WalletRefundRequest $r, FinanceAccount $a, string $ref = 'BANK-1001'): WalletRefundRequest
    {
        return $this->refunds->paid($r, $this->admin, $a->id, '2026-10-25', $ref, $this->receipt());
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid financial operation was accepted');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    private function sourcePayment(Customer $customer, int $amount = 100000, string $gateway = 'mellat'): Payment
    {
        $invoice = Invoice::create(['customer_id' => $customer->id, 'number' => 'TEST-'.random_int(100000, 999999),
            'kind' => 'topup', 'currency_code' => 'IRT', 'subtotal' => $amount, 'total' => $amount,
            'paid' => $amount, 'status' => 'paid', 'paid_at' => now(), 'issued_at' => now()]);

        return Payment::create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'gateway' => $gateway,
            'currency_code' => 'IRT', 'amount' => $amount, 'status' => 'paid', 'paid_at' => now(), 'ref_id' => 'SOURCE-123']);
    }

    public function test_default_is_disabled_and_direct_request_cannot_enable_it(): void
    {
        $this->assertFalse($this->refunds->enabled($this->customer->id));
        $this->actingAs($this->customer, 'customer')->get('/account/wallet-refunds')->assertNotFound();
        $this->post('/account/wallet-refunds', ['enabled' => true, 'amount_irt' => '1000'])->assertForbidden();
        $nav = json_encode(AccountController::shell('dash')['pnlNav']);
        $this->assertStringNotContainsString('/account/wallet-refunds', $nav);
        $this->assertSame(0, WalletRefundRequest::count());
    }

    public function test_only_admin_can_enable_a_single_customer_and_permission_has_audit_history(): void
    {
        $support = User::create(['name' => 'Support', 'email' => 'support@test.invalid', 'password' => bcrypt('test'), 'role' => 'support']);
        $other = $this->newCustomer();
        $url = '/admin/customers/'.$this->customer->id.'/wallet-refund-permission';
        $this->actingAs($support)->post($url, ['enabled' => '1', 'reason' => 'Special approval'])->assertForbidden();
        $this->actingAs($this->admin)->post($url, ['enabled' => '1', 'reason' => 'Special approval'])
            ->assertRedirect('/admin/customers/'.$this->customer->id.'#finance')->assertSessionHasNoErrors();
        $this->assertTrue($this->refunds->enabled($this->customer->id));
        $this->assertFalse($this->refunds->enabled($other->id));
        $this->assertSame(1, DB::table('wallet_refund_events')->where('action', 'permission_changed')->count());
        $this->get('/admin/customers/'.$this->customer->id)->assertOk()->assertSee('type="radio"', false);
    }

    public function test_request_reserves_funds_without_debiting_or_creating_income_and_blocks_other_spending(): void
    {
        $r = $this->requestRefund(70000);
        $this->assertSame(100000, $this->wallet->balanceOf($this->customer->id));
        $this->assertSame(30000, $this->wallet->availableOf($this->customer->id));
        $this->assertSame(70000, $this->wallet->reservedOf($this->customer->id));
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(0, BusinessEntry::count());
        $this->invalid(fn () => $this->refunds->request($this->customer, (string) Str::uuid(), 1000, $this->bank->id, 'Another request'));
        try {
            $this->wallet->debit($this->customer->id, 'IRT', 30001, 'invoice', null);
            $this->fail('Reserved refund was spent');
        } catch (WalletException $e) {
            $this->assertSame('insufficient_funds', $e->errorCode);
        }
        $this->assertSame('requested', $r->fresh()->status);
    }

    public function test_identical_request_retry_is_idempotent_but_changed_payload_is_rejected(): void
    {
        $r = $this->requestRefund();
        $again = $this->refunds->request($this->customer, $r->public_id, 40000, $this->bank->id, 'Requesting unused balance');
        $this->assertSame($r->id, $again->id);
        $this->assertSame(1, WalletRefundRequest::count());
        $this->invalid(fn () => $this->refunds->request($this->customer, $r->public_id, 41000, $this->bank->id, 'Requesting unused balance'));
    }

    public function test_unowned_unverified_or_invalid_iban_accounts_and_excess_amount_are_rejected(): void
    {
        $this->enable();
        $validIban = $this->bank->iban;
        $other = $this->newCustomer();
        $this->bank->forceFill(['customer_id' => $other->id])->save();
        $this->invalid(fn () => $this->refunds->request($this->customer, (string) Str::uuid(), 1000, $this->bank->id, 'Refund request'));
        $this->bank->forceFill(['customer_id' => $this->customer->id, 'status' => 'pending'])->save();
        $this->invalid(fn () => $this->refunds->request($this->customer, (string) Str::uuid(), 1000, $this->bank->id, 'Refund request'));
        $this->bank->forceFill(['status' => 'verified', 'iban' => 'IR000000000000000000000000'])->save();
        $this->invalid(fn () => $this->refunds->request($this->customer, (string) Str::uuid(), 1000, $this->bank->id, 'Refund request'));
        $this->bank->forceFill(['iban' => $validIban])->save();
        $this->invalid(fn () => $this->refunds->request($this->customer, (string) Str::uuid(), 100001, $this->bank->id, 'Excess amount request'));
        $this->assertSame(100000, $this->wallet->availableOf($this->customer->id));
    }

    public function test_cancel_and_reject_release_the_hold_once_without_wallet_credit_entries(): void
    {
        $r = $this->requestRefund();
        $this->refunds->close($r, 'cancelled', 'Customer cancellation', $this->customer);
        $this->refunds->close($r, 'cancelled', 'Repeated cancellation', $this->customer);
        $this->assertSame(100000, $this->wallet->availableOf($this->customer->id));
        $next = $this->requestRefund(20000);
        $this->approve($next);
        $this->refunds->close($next, 'rejected', 'No transfer made; request rejected', $this->admin);
        $this->assertSame(100000, $this->wallet->balanceOf($this->customer->id));
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(0, FinanceVoucher::count());
    }

    public function test_customer_cannot_cancel_approved_request_and_disabling_does_not_release_its_funds(): void
    {
        $r = $this->approve($this->requestRefund());
        $this->refunds->permission($this->customer, false, 'No new refund requests', $this->admin);
        $this->invalid(fn () => $this->refunds->close($r, 'cancelled', 'Cancel now', $this->customer));
        $this->assertSame(60000, $this->wallet->availableOf($this->customer->id));
        $this->actingAs($this->customer, 'customer')->get('/account/wallet-refunds')->assertOk()->assertSee(__('refund.disabled'));
        $this->post('/account/wallet-refunds', [])->assertForbidden();
    }

    public function test_payout_is_atomic_idempotent_documented_and_not_a_second_sales_refund(): void
    {
        $r = $this->approve($this->requestRefund());
        $a = $this->funding();
        $before = app(BusinessLedger::class)->summary();
        $paid = $this->settle($r, $a);
        $retry = $this->settle($r, $a);
        $this->assertSame($paid->id, $retry->id);
        $this->assertSame('paid', $paid->status);
        $this->assertSame(60000, $this->wallet->balanceOf($this->customer->id));
        $this->assertSame(60000, $this->wallet->availableOf($this->customer->id));
        $this->assertSame(1, CreditEntry::where('reason', 'wallet_refund_payout')->count());
        $this->assertSame(1, FinanceVoucher::where('type', 'wallet_refund_payout')->count());
        $this->assertSame(1, FinanceDocument::count());
        $this->assertCount(1, Storage::disk('local')->allFiles('finance/wallet-refunds'));
        $this->assertSame(460000, app(DocumentedAccounting::class)->balance($a)['irt']);
        $lines = FinanceLine::where('voucher_id', $paid->voucher_id)->get();
        $this->assertSame((int) $lines->sum('debit_irt'), (int) $lines->sum('credit_irt'));
        foreach (['revenue', 'tax_collected', 'net_profit', 'refund', 'expense'] as $key) {
            $this->assertSame($before[$key], app(BusinessLedger::class)->summary()[$key]);
        }
        $doc = FinanceDocument::findOrFail($paid->document_id);
        $this->assertSame($doc->sha256, hash('sha256', Storage::disk('local')->get($doc->path)));
        $this->assertArrayNotHasKey('bank_snapshot', $paid->toArray());
        $this->assertStringNotContainsString($this->bank->iban, (string) DB::table('wallet_refund_requests')->where('id', $r->id)->value('bank_snapshot'));
        $this->assertSame($this->admin->name, DB::table('wallet_refund_events')->where('request_id', $r->id)->where('action', 'paid')->value('actor_name'));
    }

    public function test_payout_before_approval_or_with_insufficient_bank_leaves_wallet_and_history_intact(): void
    {
        $r = $this->requestRefund();
        $a = $this->funding('IRT', 1000);
        $this->invalid(fn () => $this->settle($r, $a));
        $this->approve($r);
        $this->invalid(fn () => $this->settle($r, $a));
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(100000, $this->wallet->balanceOf($this->customer->id));
        $this->assertSame(60000, $this->wallet->availableOf($this->customer->id));
        $this->assertSame(0, FinanceDocument::count());
        $this->assertCount(0, Storage::disk('local')->allFiles('finance/wallet-refunds'));
    }

    public function test_duplicate_tracking_reference_does_not_debit_a_second_request(): void
    {
        $a = $this->funding();
        $this->settle($this->approve($this->requestRefund(20000)), $a);
        $second = $this->approve($this->requestRefund(20000));
        $this->invalid(fn () => $this->settle($second, $a));
        $this->assertSame('approved', $second->fresh()->status);
        $this->assertSame(80000, $this->wallet->balanceOf($this->customer->id));
        $this->assertSame(60000, $this->wallet->availableOf($this->customer->id));
        $this->assertSame(1, FinanceDocument::count());
    }

    public function test_bank_snapshot_changes_are_blocked_and_closed_periods_cannot_be_bypassed(): void
    {
        $r = $this->requestRefund();
        $this->bank->forceFill(['owner_name' => 'Changed owner'])->save();
        $this->invalid(fn () => $this->approve($r));
        $this->bank->forceFill(['owner_name' => 'Test Customer'])->save();
        $this->approve($r);
        $a = $this->funding();
        DB::table('finance_controls')->where('id', 1)->update(['locked_through' => '2026-10-25']);
        $this->invalid(fn () => $this->settle($r, $a));
        $this->assertSame('approved', $r->fresh()->status);
    }

    public function test_source_payment_must_belong_to_customer_and_cannot_be_gift_or_overallocated(): void
    {
        $r = $this->requestRefund();
        $foreign = $this->sourcePayment($this->newCustomer());
        $this->invalid(fn () => $this->approve($r, $foreign->id));
        $gift = $this->sourcePayment($this->customer, 100000, 'gift');
        $this->invalid(fn () => $this->approve($r, $gift->id));
        $small = $this->sourcePayment($this->customer, 1000);
        $this->invalid(fn () => $this->approve($r, $small->id));
        $real = $this->sourcePayment($this->customer);
        $this->assertSame('approved', $this->approve($r, $real->id)->status);
    }

    public function test_customer_forms_work_in_three_languages_and_ownership_protects_receipts(): void
    {
        $r = $this->requestRefund();
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $this->actingAs($this->customer, 'customer')->get($prefix.'/account/wallet-refunds')->assertOk()
                ->assertSee(__('refund.title', [], $locale))->assertDontSee('refund.status_');
        }
        $paid = $this->settle($this->approve($r), $this->funding());
        $this->actingAs($this->customer, 'customer')->get('/account/wallet-refunds/'.$paid->public_id.'/receipt')->assertOk();
        $this->actingAs($this->newCustomer(), 'customer')->get('/account/wallet-refunds/'.$paid->public_id.'/receipt')->assertNotFound();
        $this->post('/account/wallet-refunds/'.$paid->public_id.'/cancel')->assertNotFound();
    }

    public function test_real_payment_record_requires_receipt_confirmation_and_accepts_jalali_date(): void
    {
        $r = $this->approve($this->requestRefund());
        $a = $this->funding();
        $url = '/admin/wallet-refunds/'.$r->public_id.'/paid';
        $this->actingAs($this->admin)->post($url, ['funding_account_id' => $a->id, 'payout_date' => '1405/08/03', 'bank_reference' => 'BANK-222'])
            ->assertSessionHasErrors(['evidence', 'transferred']);
        $this->post($url, ['funding_account_id' => $a->id, 'payout_date' => '1405/08/03', 'bank_reference' => 'BANK-222',
            'evidence' => $this->receipt(), 'transferred' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $r->fresh()->status);
        $this->assertSame('2026-10-25', $r->fresh()->payout_date->toDateString());
    }

    public function test_irr_bank_debits_exactly_ten_rials_per_toman_and_generic_reversal_is_blocked(): void
    {
        $a = $this->funding('IRR');
        $r = $this->settle($this->approve($this->requestRefund()), $a);
        $this->assertSame(4600000, app(DocumentedAccounting::class)->balance($a)['native']);
        $this->invalid(fn () => app(DocumentedAccounting::class)->reverse(FinanceVoucher::findOrFail($r->voucher_id), '2026-10-25', 'Unsafe reversal', $this->admin->id));
        $this->assertSame('paid', $r->fresh()->status);
    }

    public function test_report_and_tax_archive_distinguish_wallet_payouts_from_sales_and_keep_complete_evidence(): void
    {
        $pay = $this->sourcePayment($this->customer);
        $r = $this->settle($this->approve($this->requestRefund(), $pay->id), $this->funding());
        $scope = new FinanceFilter([], Carbon::parse('2026-10-01'), Carbon::parse('2026-10-25'));
        $report = app(AccountingReport::class)->report($scope);
        $this->assertSame(100000, $report['payments']['external_receipts_irt']);
        $this->assertSame(40000, $report['payments']['wallet_refunds_paid_irt']);
        $this->assertSame(60000, $report['payments']['net_external_receipts_irt']);
        $this->assertSame(0, $report['summary']['revenue']);
        $path = app(TaxEvidenceBundle::class)->build($scope);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $proof = json_decode($zip->getFromName('wallet-refunds/'.$r->public_id.'.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($this->bank->iban, $proof['bank_destination_at_request']['iban']);
            $this->assertSame($pay->id, $proof['source_payment']['id']);
            $this->assertNotFalse($zip->getFromName('wallet-refund-audit.csv'));
            foreach (json_decode($zip->getFromName('manifest-sha256.json'), true, 512, JSON_THROW_ON_ERROR) as $item) {
                $this->assertSame($item['sha256'], hash('sha256', $zip->getFromName($item['file'])));
            }
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_non_admin_cannot_access_financial_queue_or_complete_a_refund(): void
    {
        $r = $this->requestRefund();
        $support = User::create(['name' => 'Support', 'email' => 'support@test.invalid', 'password' => bcrypt('test'), 'role' => 'support']);
        $this->actingAs($support)->get('/admin/wallet-refunds')->assertForbidden();
        $this->get('/admin/wallet-refunds/'.$r->public_id)->assertForbidden();
        $this->post('/admin/wallet-refunds/'.$r->public_id.'/approve', ['note' => 'Approve this'])->assertForbidden();
        $this->assertSame('requested', $r->fresh()->status);
    }

    public function test_audit_history_cannot_be_deleted_via_customer_deletion_or_migration_rollback(): void
    {
        $r = $this->requestRefund(100000);
        $this->settle($this->approve($r), $this->funding());
        $this->assertSame(0, $this->wallet->balanceOf($this->customer->id));
        $this->actingAs($this->admin)->post('/admin/customers/'.$this->customer->id.'/delete')->assertSessionHasErrors();
        $this->assertDatabaseHas('customers', ['id' => $this->customer->id]);
        $migration = require database_path('migrations/2026_10_25_150000_create_wallet_refund_workflow.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_receipt_metadata_failure_rolls_back_the_wallet_debit_and_entire_journal(): void
    {
        $r = $this->approve($this->requestRefund());
        $a = $this->funding();
        FinanceDocument::creating(fn () => throw new \RuntimeException('Simulated receipt metadata failure'));
        try {
            $this->settle($r, $a);
            $this->fail('The failed receipt was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated receipt metadata failure', $e->getMessage());
        } finally {
            FinanceDocument::flushEventListeners();
        }
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(100000, $this->wallet->balanceOf($this->customer->id));
        $this->assertSame(60000, $this->wallet->availableOf($this->customer->id));
        $this->assertSame(500000, app(DocumentedAccounting::class)->balance($a)['irt']);
        $this->assertSame(0, FinanceVoucher::where('type', 'wallet_refund_payout')->count());
        $this->assertSame(0, DB::table('wallet_refund_events')->where('action', 'paid')->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('finance/wallet-refunds'));
    }

    public function test_paid_records_reject_changed_replays_and_cannot_be_rejected_or_cancelled(): void
    {
        $a = $this->funding();
        $r = $this->settle($this->approve($this->requestRefund()), $a);
        $this->invalid(fn () => $this->settle($r, $a, 'DIFFERENT-REFERENCE'));
        $this->invalid(fn () => $this->refunds->close($r, 'rejected', 'Do not reverse paid funds', $this->admin));
        $this->invalid(fn () => $this->refunds->close($r, 'cancelled', 'Cannot cancel paid funds', $this->customer));
        $this->assertSame('paid', $r->fresh()->status);
        $this->assertSame(1, CreditEntry::where('reason', 'wallet_refund_payout')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('finance/wallet-refunds'));
    }

    public function test_customer_submission_works_in_all_three_locales_with_explicit_irt_amounts(): void
    {
        $this->enable();
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $this->actingAs($this->customer, 'customer')->get($prefix.'/account/wallet-refunds')->assertOk()
                ->assertSee(__('refund.submit', [], $locale))->assertSee('name="amount_irt"', false);
            $this->post($prefix.'/account/wallet-refunds', [
                'idempotency_key' => (string) Str::uuid(), 'amount_irt' => $locale === 'fa' ? '۱۰۰۰' : '1000',
                'bank_account_id' => $this->bank->id, 'note' => 'Unused credit refund request', 'acknowledge' => '1',
            ])->assertSessionHasNoErrors();
            $r = WalletRefundRequest::latest('id')->firstOrFail();
            $this->assertSame(1000, $r->amount_irt);
            $this->refunds->close($r, 'cancelled', 'Cancel test request', $this->customer);
        }
        $this->assertSame(100000, $this->wallet->availableOf($this->customer->id));
    }

    public function test_source_payment_cannot_be_reused_beyond_its_received_amount(): void
    {
        $pay = $this->sourcePayment($this->customer, 50000);
        $a = $this->funding();
        $this->settle($this->approve($this->requestRefund(), $pay->id), $a);
        $next = $this->requestRefund();
        $this->invalid(fn () => $this->approve($next, $pay->id));
        $this->assertSame('requested', $next->fresh()->status);
    }

    public function test_archive_includes_a_related_receipt_paid_after_the_selected_request_period(): void
    {
        $r = $this->approve($this->requestRefund());
        $a = $this->funding();
        $this->travelTo(Carbon::parse('2026-10-26 08:00:00', 'UTC'));
        $r = $this->refunds->paid($r, $this->admin, $a->id, '2026-10-26', 'NEXT-DAY-TRANSFER', $this->receipt());
        $scope = new FinanceFilter([], Carbon::parse('2026-10-25'), Carbon::parse('2026-10-25'));
        $report = app(AccountingReport::class)->report($scope);
        $this->assertSame(0, $report['payments']['wallet_refunds_paid_irt']);
        $path = app(TaxEvidenceBundle::class)->build($scope);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->getFromName('wallet-refunds/'.$r->public_id.'.json'));
            $this->assertStringContainsString((string) $r->voucher_id, $zip->getFromName('wallet-refund-related-journal.json'));
            $this->assertNotFalse($zip->getFromName('evidence/voucher-'.$r->voucher_id.'/document-'.$r->document_id.'.pdf'));
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_tampered_receipt_is_not_served_and_original_bank_snapshot_survives_account_deletion(): void
    {
        $r = $this->settle($this->approve($this->requestRefund()), $this->funding());
        $iban = $this->bank->iban;
        $this->bank->delete();
        $this->assertNull($r->fresh()->bank_account_id);
        $this->assertSame($iban, $r->fresh()->bank_snapshot['iban']);
        $doc = FinanceDocument::findOrFail($r->document_id);
        Storage::disk('local')->put($doc->path, 'tampered receipt');
        $this->actingAs($this->customer, 'customer')->get('/account/wallet-refunds/'.$r->public_id.'/receipt')->assertStatus(409);
        $this->assertSame('paid', $r->fresh()->status);
    }

    public function test_permission_validation_uses_its_own_error_bag_and_preserves_default_disabled_state(): void
    {
        $this->actingAs($this->admin)->from('/admin/customers/'.$this->customer->id)
            ->post('/admin/customers/'.$this->customer->id.'/wallet-refund-permission', [
                'enabled' => '1', 'reason' => '', '_wallet_refund_permission' => '1',
            ])->assertSessionHasErrorsIn('walletRefundPermission', ['reason']);
        $this->assertFalse($this->refunds->enabled($this->customer->id));
        $this->get('/admin/customers/'.$this->customer->id)->assertOk();
    }

    public function test_admin_queue_and_each_review_state_render_with_links_to_the_financial_voucher(): void
    {
        $r = $this->requestRefund();
        $url = '/admin/wallet-refunds/'.$r->public_id;
        $this->actingAs($this->admin)->get('/admin/wallet-refunds')->assertOk()->assertSee($r->customer->code);
        $this->get($url)->assertOk()->assertSee('name="source_note"', false)->assertSee($this->bank->iban);
        $this->approve($r);
        $this->get($url)->assertOk()->assertSee('name="evidence"', false)->assertSee('name="payout_date"', false);
        $r = $this->settle($r, $this->funding());
        $this->get($url)->assertOk()->assertSee('/admin/finance/accounting/vouchers/'.$r->voucher_id, false);
        $this->get('/admin/finance/accounting/vouchers/'.$r->voucher_id)->assertOk()->assertSee(__('refund.paid_no_reverse'))
            ->assertDontSee('/vouchers/'.$r->voucher_id.'/reverse', false);
    }

    public function test_a_matched_bank_debit_is_attached_to_the_payout_and_cannot_be_used_twice(): void
    {
        $a = $this->funding();
        $import = DB::table('finance_bank_imports')->insertGetId(['account_id' => $a->id, 'path' => 'test.csv',
            'sha256' => hash('sha256', 'fixture'), 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $bankRow = DB::table('finance_bank_transactions')->insertGetId(['import_id' => $import, 'account_id' => $a->id,
            'occurred_at' => '2026-10-25', 'reference' => 'DEBIT-4444', 'currency_code' => 'IRT', 'credit_minor' => 0, 'debit_minor' => 40000,
            'fingerprint' => hash('sha256', 'bank-row-fixture'), 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->approve($this->requestRefund());
        $paid = $this->refunds->paid($r, $this->admin, $a->id, '2026-10-25', 'DEBIT-4444', $this->receipt(), $bankRow);
        $this->assertSame($bankRow, (int) FinanceVoucher::findOrFail($paid->voucher_id)->bank_transaction_id);
        $next = $this->approve($this->requestRefund());
        $this->invalid(fn () => $this->refunds->paid($next, $this->admin, $a->id, '2026-10-25', 'OTHER-REFERENCE', $this->receipt(), $bankRow));
        $this->assertSame('approved', $next->fresh()->status);
    }
}
