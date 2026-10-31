<?php

namespace Tests\Feature;

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
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\TaxEvidenceBundle;
use App\Services\Finance\Wallet;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class WalletRefundFundingTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $admin;

    private BankAccount $destination;

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
        $this->admin = User::create(['name' => 'Funding reviewer', 'email' => 'funding-admin@example.test',
            'role' => 'admin', 'password' => bcrypt('test')]);
        $this->customer = Customer::create(['email' => 'refund-customer@example.test', 'password' => bcrypt('test'),
            'phone' => '09123456789', 'status' => 'active', 'locale' => 'fa']);
        $this->destination = BankAccount::create(['customer_id' => $this->customer->id,
            'iban' => 'IR880100000000000000000001', 'card_bin' => '603799', 'card_last4' => '1234',
            'owner_name' => 'Synthetic Customer', 'name_matched' => true, 'status' => 'verified', 'verified_at' => now()]);
        $this->wallet->credit($this->customer->id, 'IRT', 100000, 'topup', null);
        $this->refunds->permission($this->customer, true, 'Documented funding review', $this->admin);
    }

    private function approved(?int $sourcePayment = null): WalletRefundRequest
    {
        $r = $this->refunds->request($this->customer, (string) Str::uuid(), 40000, $this->destination->id, 'Unused funds requested');

        return $this->refunds->approve($r, $this->admin, 'Verified destination and funds', $sourcePayment,
            'Customer credit origin reviewed against the incoming payment evidence');
    }

    private function account(string $type = 'partner', string $currency = 'IRT', ?string $owner = 'Synthetic Partner'): FinanceAccount
    {
        return FinanceAccount::create(['name' => 'Synthetic payout account', 'type' => $type,
            'currency_code' => $currency, 'owner_name' => $owner, 'created_by' => $this->admin->id]);
    }

    private function receipt(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic-transfer.pdf', "%PDF-1.4\nSynthetic refund transfer evidence\n");
    }

    private function settle(WalletRefundRequest $r, FinanceAccount $a): WalletRefundRequest
    {
        return $this->refunds->paid($r, $this->admin, $a->id, '2026-10-25', 'PARTNER-REFUND-1001', $this->receipt());
    }

    private function details(WalletRefundRequest $r): array
    {
        return json_decode(DB::table('wallet_refund_events')->where('request_id', $r->id)
            ->where('action', 'paid')->value('details'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertRejected(callable $action, string $error): void
    {
        try {
            $action();
            $this->fail('An invalid payout was accepted');
        } catch (ValidationException $e) {
            $this->assertContains(__('refund.'.$error), $e->errors()['refund']);
        }
    }

    private function assertNoPayout(WalletRefundRequest $r): void
    {
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(100000, $this->wallet->balanceOf($this->customer->id));
        $this->assertSame(60000, $this->wallet->availableOf($this->customer->id));
        $this->assertSame(0, FinanceVoucher::where('type', 'wallet_refund_payout')->count());
        $this->assertSame(0, FinanceDocument::count());
        $this->assertSame(0, CreditEntry::where('reason', 'wallet_refund_payout')->count());
        $this->assertSame(0, DB::table('wallet_refund_events')->where('action', 'paid')->count());
        $this->assertCount(0, Storage::disk('local')->allFiles('finance/wallet-refunds'));
    }

    private function assertPartnerSettlement(string $currency, int $native): void
    {
        $a = $this->account('partner', $currency);
        $this->assertSame(0, FinanceLine::where('account_id', $a->id)->count());
        $businessEntries = BusinessEntry::count();
        $r = $this->settle($this->approved(), $a);
        $v = FinanceVoucher::findOrFail($r->voucher_id);
        $this->assertSame($a->id, (int) $v->funding_account_id);
        $this->assertSame($currency, $v->settlement_currency);
        $this->assertSame($native, (int) $v->settlement_minor);
        $this->assertSame('none', $v->vat_status);
        $this->assertSame('pending', $v->tax_status);
        $lines = FinanceLine::where('voucher_id', $v->id)->get();
        $this->assertCount(2, $lines);
        $liability = $lines->firstWhere('code', 'customer_wallet_liability');
        $partner = $lines->firstWhere('code', 'partner_current');
        $this->assertNotNull($liability);
        $this->assertNotNull($partner);
        $this->assertSame(40000, (int) $liability->debit_irt);
        $this->assertSame(0, (int) $liability->credit_irt);
        $this->assertSame($a->id, (int) $partner->account_id);
        $this->assertSame(0, (int) $partner->debit_irt);
        $this->assertSame(40000, (int) $partner->credit_irt);
        $this->assertSame(0, (int) $partner->native_debit);
        $this->assertSame($native, (int) $partner->native_credit);
        $this->assertSame((int) $lines->sum('debit_irt'), (int) $lines->sum('credit_irt'));
        $this->assertSame(0, $lines->whereIn('code', ['bank', 'expense', 'revenue', 'vat_payable'])->count());
        $this->assertSame($businessEntries, BusinessEntry::count());
        $this->assertSame(60000, $this->wallet->balanceOf($this->customer->id));
        $this->assertSame(['irt' => 40000, 'native' => $native], app(DocumentedAccounting::class)->balance($a));
        $snapshot = $this->details($r)['funding_account_snapshot'];
        $this->assertSame($a->id, $snapshot['id']);
        $this->assertSame('partner', $snapshot['type']);
        $this->assertSame($a->name, $snapshot['name']);
        $this->assertSame($a->owner_name, $snapshot['owner_name']);
        $this->assertSame($currency, $snapshot['currency_code']);
        $doc = FinanceDocument::findOrFail($r->document_id);
        $this->assertSame($doc->sha256, hash('sha256', Storage::disk('local')->get($doc->path)));
    }

    public function test_partner_payout_settles_customer_liability_and_credits_partner_in_tomans_without_fabricating_a_bank_balance(): void
    {
        $this->assertPartnerSettlement('IRT', 40000);
    }

    public function test_partner_payout_preserves_exact_native_rials(): void
    {
        $this->assertPartnerSettlement('IRR', 400000);
    }

    public function test_missing_partner_owner_unsupported_account_type_and_foreign_currency_leave_no_payout_or_orphan_receipt(): void
    {
        $r = $this->approved();
        foreach ([['partner', 'IRT', null, 'funding_owner'], ['partner', 'IRT', '   ', 'funding_owner'],
            ['vendor', 'IRT', 'Synthetic Vendor', 'funding_bank'], ['partner', 'USD', 'Synthetic Partner', 'funding_bank']] as [$type, $currency, $owner, $error]) {
            $a = $this->account($type, $currency, $owner);
            $this->assertRejected(fn () => $this->settle($r, $a), $error);
            $this->assertNoPayout($r);
        }
    }

    public function test_partner_cannot_attach_even_an_exact_matching_company_statement_row(): void
    {
        $a = $this->account();
        $import = DB::table('finance_bank_imports')->insertGetId(['account_id' => $a->id, 'path' => 'synthetic.csv',
            'sha256' => hash('sha256', 'synthetic'), 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('finance_bank_transactions')->insertGetId(['import_id' => $import, 'account_id' => $a->id,
            'occurred_at' => '2026-10-25', 'currency_code' => 'IRT', 'debit_minor' => 40000, 'credit_minor' => 0,
            'reference' => 'PARTNER-REFUND-1001', 'fingerprint' => hash('sha256', 'synthetic-partner-row'),
            'created_at' => now(), 'updated_at' => now()]);
        $r = $this->approved();
        $this->assertRejected(fn () => $this->refunds->paid($r, $this->admin, $a->id, '2026-10-25',
            'PARTNER-REFUND-1001', $this->receipt(), $row), 'invalid_bank_row');
        $this->assertNoPayout($r);
    }


    private function documentedFunding(FinanceAccount $funding, array $overrides): FinanceVoucher
    {
        return app(DocumentedAccounting::class)->post(array_replace([
            'idempotency_key' => (string) Str::uuid(), 'type' => 'expense', 'occurred_at' => '2026-10-25',
            'service_line' => 'other', 'category' => 'other', 'amount_irt' => 100000,
            'original_currency' => 'IRT', 'original_minor' => 100000,
            'settlement_currency' => $funding->currency_code, 'settlement_minor' => 100000,
            'funding_account_id' => $funding->id, 'target_account_id' => null,
            'bank_reference' => 'SYNTHETIC-FUNDING-'.Str::uuid(), 'note' => 'Synthetic documented funding evidence',
            'tax_status' => 'pending', 'vat_status' => 'none',
        ], $overrides), $this->admin->id);
    }

    #[DataProvider('advancePayoutDates')]
    public function test_personal_payout_cannot_consume_a_company_advance_on_either_relevant_date(
        string $advanceDate, string $payoutDate, bool $clearToday,
    ): void {
        $r = $this->approved();
        $this->travelTo(Carbon::parse('2026-10-26 08:00:00', 'UTC'));
        $bank = $this->account('bank', 'IRT', 'Synthetic company');
        $partner = $this->account('partner', 'IRR');
        $this->documentedFunding($bank, ['type' => 'opening_balance',
            'funding_account_id' => null, 'target_account_id' => $bank->id, 'settlement_minor' => 0]);
        $advance = $this->documentedFunding($bank, ['type' => 'partner_advance', 'occurred_at' => $advanceDate,
            'amount_irt' => 50000, 'original_currency' => 'IRR', 'original_minor' => 500000,
            'settlement_minor' => 50000, 'target_account_id' => $partner->id]);
        if ($clearToday) {
            $this->documentedFunding($partner, ['occurred_at' => '2026-10-26',
                'amount_irt' => 50000, 'original_minor' => 50000, 'settlement_minor' => 500000,
                'funding_mode' => 'advance', 'related_voucher_id' => $advance->id]);
        }
        $before = [FinanceVoucher::count(), FinanceLine::count(), BusinessEntry::count(),
            DB::table('finance_audit_events')->count()];
        $this->assertRejected(fn () => $this->refunds->paid($r, $this->admin, $partner->id, $payoutDate,
            'SYNTHETIC-ADVANCE-REFUND', $this->receipt()), 'funding_advance');
        $this->assertNoPayout($r);
        $this->assertSame($before, [FinanceVoucher::count(), FinanceLine::count(), BusinessEntry::count(),
            DB::table('finance_audit_events')->count()]);
    }

    public static function advancePayoutDates(): array
    {
        return [
            'negative on payout and current dates' => ['2026-10-25', '2026-10-26', false],
            'negative only on current date' => ['2026-10-26', '2026-10-25', false],
            'negative only on payout date' => ['2026-10-25', '2026-10-25', true],
        ];
    }

    public function test_paid_personal_payout_retry_still_returns_original_after_a_later_company_advance(): void
    {
        $partner = $this->account();
        $r = $this->settle($this->approved(), $partner);
        $original = $this->details($r);
        $bank = $this->account('bank', 'IRT', 'Synthetic company');
        $this->documentedFunding($bank, ['type' => 'opening_balance',
            'funding_account_id' => null, 'target_account_id' => $bank->id, 'settlement_minor' => 0]);
        $this->documentedFunding($bank, ['type' => 'partner_repayment', 'amount_irt' => 40000,
            'original_minor' => 40000, 'settlement_minor' => 40000, 'target_account_id' => $partner->id]);
        $this->documentedFunding($bank, ['type' => 'partner_advance', 'amount_irt' => 40000,
            'original_minor' => 40000, 'settlement_minor' => 40000, 'target_account_id' => $partner->id]);
        $this->assertSame(['irt' => -40000, 'native' => -40000], app(DocumentedAccounting::class)->balance($partner));
        $retry = $this->settle($r, $partner);
        $this->assertSame($r->id, $retry->id);
        $this->assertSame($original, $this->details($retry));
        $this->assertSame(1, FinanceVoucher::where('type', 'wallet_refund_payout')->count());
        $this->assertSame(1, FinanceDocument::count());
        $this->assertSame(1, CreditEntry::where('reason', 'wallet_refund_payout')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('finance/wallet-refunds'));
        $this->assertSame(60000, $this->wallet->balanceOf($this->customer->id));
    }

    public function test_allowing_partner_funding_does_not_allow_an_unfunded_company_bank_payout(): void
    {
        $r = $this->approved();
        $a = $this->account('bank');
        $this->assertRejected(fn () => $this->settle($r, $a), 'insufficient_bank');
        $this->assertNoPayout($r);
    }

    public function test_partner_payout_still_obeys_the_closed_accounting_period(): void
    {
        $r = $this->approved();
        $a = $this->account();
        DB::table('finance_controls')->where('id', 1)->update(['locked_through' => '2026-10-25']);
        $this->assertRejected(fn () => $this->settle($r, $a), 'period_locked');
        $this->assertNoPayout($r);
    }

    public function test_incoming_customer_payment_remains_separate_from_the_outgoing_partner_account(): void
    {
        $invoice = Invoice::create(['customer_id' => $this->customer->id, 'number' => 'SYNTHETIC-INCOMING-1001',
            'kind' => 'topup', 'currency_code' => 'IRT', 'subtotal' => 100000, 'total' => 100000,
            'paid' => 100000, 'status' => 'paid', 'paid_at' => now(), 'issued_at' => now()]);
        $payment = Payment::create(['customer_id' => $this->customer->id, 'invoice_id' => $invoice->id,
            'gateway' => 'mellat', 'currency_code' => 'IRT', 'amount' => 100000, 'status' => 'paid',
            'paid_at' => now(), 'ref_id' => 'INCOMING-CUSTOMER-1001']);
        $a = $this->account();
        $r = $this->settle($this->approved($payment->id), $a);
        $this->assertSame($payment->id, (int) $r->source_payment_id);
        $this->assertSame($a->id, (int) FinanceVoucher::findOrFail($r->voucher_id)->funding_account_id);
        $this->assertSame('mellat', $payment->fresh()->gateway);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(100000, (int) $payment->fresh()->amount);
    }

    public function test_identical_payout_retry_after_account_rename_preserves_the_original_identity_and_single_debit(): void
    {
        $a = $this->account();
        $r = $this->settle($this->approved(), $a);
        $original = $this->details($r)['funding_account_snapshot'];
        $a->update(['name' => 'Renamed current account', 'owner_name' => 'Renamed current owner']);
        $retry = $this->settle($r, $a);
        $this->assertSame($r->id, $retry->id);
        $this->assertSame($original, $this->details($retry)['funding_account_snapshot']);
        $this->assertSame(1, FinanceVoucher::where('type', 'wallet_refund_payout')->count());
        $this->assertSame(1, CreditEntry::where('reason', 'wallet_refund_payout')->count());
        $this->assertSame(1, DB::table('wallet_refund_events')->where('action', 'paid')->count());
        $this->assertSame(1, FinanceDocument::count());
        $this->assertCount(1, Storage::disk('local')->allFiles('finance/wallet-refunds'));
        $this->assertSame(60000, $this->wallet->balanceOf($this->customer->id));
        $this->actingAs($this->admin)->get('/admin/wallet-refunds/'.$r->public_id)->assertOk()
            ->assertViewHas('fundingSnapshot', $original)->assertViewHas('fundingLegacyFallback', false);
    }

    private function exportRow(WalletRefundRequest $r): array
    {
        $response = $this->actingAs($this->admin)->get('/admin/wallet-refunds/export')->assertOk();
        $lines = preg_split('/\r?\n/', trim($response->streamedContent()));
        $header = str_getcsv(ltrim(array_shift($lines), "\xEF\xBB\xBF"), escape: '');
        foreach ($lines as $line) {
            $cells = str_getcsv($line, escape: '');
            if (($cells[0] ?? null) === $r->public_id) {
                return array_combine($header, $cells);
            }
        }
        $this->fail('The refund is absent from its admin CSV export');
    }

    private function archiveProof(WalletRefundRequest $r): array
    {
        $scope = new FinanceFilter([], Carbon::parse('2026-10-25'), Carbon::parse('2026-10-25'));
        $path = app(TaxEvidenceBundle::class)->build($scope);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $proof = json_decode($zip->getFromName('wallet-refunds/'.$r->public_id.'.json'), true, 512, JSON_THROW_ON_ERROR);
            $doc = FinanceDocument::findOrFail($r->document_id);
            $evidence = $zip->getFromName('evidence/voucher-'.$r->voucher_id.'/document-'.$doc->id.'.pdf');
            $this->assertSame($doc->sha256, hash('sha256', $evidence));
            $this->assertSame($doc->sha256, $proof['receipt_sha256']);
            foreach (json_decode($zip->getFromName('manifest-sha256.json'), true, 512, JSON_THROW_ON_ERROR) as $item) {
                $this->assertSame($item['sha256'], hash('sha256', $zip->getFromName($item['file'])));
            }

            return $proof;
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_csv_and_private_archive_keep_the_payer_identity_at_transfer_after_the_account_is_renamed(): void
    {
        $a = $this->account();
        $r = $this->settle($this->approved(), $a);
        $snapshot = $this->details($r)['funding_account_snapshot'];
        $a->update(['name' => 'Current replacement label', 'owner_name' => 'Current replacement owner']);
        $row = $this->exportRow($r);
        $this->assertSame((string) $a->id, $row['Funding account ID']);
        $this->assertSame($snapshot['name'], $row['Funding account name']);
        $this->assertSame($snapshot['owner_name'], $row['Funding account owner']);
        $this->assertSame('partner', $row['Funding account type']);
        $this->assertSame('IRT', $row['Funding account currency']);
        $this->assertSame('0', $row['Funding account legacy fallback']);
        $proof = $this->archiveProof($r);
        $this->assertSame($a->id, $proof['funding_account_id']);
        $this->assertSame($snapshot, $proof['funding_account_snapshot']);
        $this->assertNull($proof['funding_account_current']);
        $this->assertFalse($proof['funding_account_legacy_fallback']);
        $this->assertNull($proof['bank_transaction_id']);
    }

    public function test_legacy_paid_record_is_explicitly_identified_as_current_account_fallback_instead_of_historical_evidence(): void
    {
        $a = $this->account();
        $r = $this->settle($this->approved(), $a);
        $details = $this->details($r);
        unset($details['funding_account_snapshot']);
        // Synthetic historical fixture only: the application has no audit-editing endpoint.
        DB::table('wallet_refund_events')->where('request_id', $r->id)->where('action', 'paid')
            ->update(['details' => json_encode($details, JSON_THROW_ON_ERROR)]);
        $a->update(['name' => 'Legacy current account label']);
        $this->actingAs($this->admin)->get('/admin/wallet-refunds/'.$r->public_id)->assertOk()
            ->assertViewHas('fundingSnapshot', null)->assertViewHas('fundingLegacyFallback', true);
        $row = $this->exportRow($r);
        $this->assertSame('1', $row['Funding account legacy fallback']);
        $this->assertSame($a->name, $row['Funding account name']);
        $proof = $this->archiveProof($r);
        $this->assertTrue($proof['funding_account_legacy_fallback']);
        $this->assertNull($proof['funding_account_snapshot']);
        $this->assertSame($a->id, $proof['funding_account_current']['id']);
        $this->assertSame($a->name, $proof['funding_account_current']['name']);
    }

    public function test_new_payer_identity_columns_cannot_execute_spreadsheet_formulas(): void
    {
        $a = $this->account();
        $a->update(['name' => '=SUM(1,2)', 'owner_name' => '@Synthetic Partner']);
        $r = $this->settle($this->approved(), $a);
        $row = $this->exportRow($r);
        $this->assertSame("'".$a->name, $row['Funding account name']);
        $this->assertSame("'".$a->owner_name, $row['Funding account owner']);
        $this->assertSame($a->name, $this->details($r)['funding_account_snapshot']['name']);
    }

    public function test_payer_documents_and_exports_remain_admin_only(): void
    {
        $a = $this->account();
        $r = $this->settle($this->approved(), $a);
        $support = User::create(['name' => 'Support agent', 'email' => 'funding-support@example.test',
            'role' => 'support', 'password' => bcrypt('test')]);
        $this->actingAs($support)->get('/admin/wallet-refunds/'.$r->public_id)->assertForbidden();
        $this->get('/admin/wallet-refunds/export')->assertForbidden();
        $this->get(route('admin.accounting.download', $r->document_id))->assertForbidden();
        $this->actingAs($this->customer, 'customer')->get('/account/wallet-refunds')->assertOk()
            ->assertDontSee($a->name)->assertDontSee($a->owner_name);
    }
}
