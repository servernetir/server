<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\FinanceVoucher;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\AccountingReport;
use App\Services\Finance\BankReconciliation;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\SupplierCreditReconciliation;
use App\Services\Finance\TaxEvidenceBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class FinanceFundingEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31 12:00:00', 'Asia/Tehran'));
        Storage::fake('local');
        Http::fake();
        Mail::fake();
    }

    private function fixture(): array
    {
        $admin = User::create(['name' => 'Synthetic finance admin', 'email' => 'funding-proof@example.test',
            'password' => bcrypt('synthetic-only'), 'role' => 'admin']);
        $partner = FinanceAccount::create(['name' => 'Synthetic original bank', 'type' => 'partner',
            'currency_code' => 'IRT', 'owner_name' => 'Synthetic original owner', 'partner_key' => 'TEST-PARTNER',
            'bank_details' => ['bank_name' => 'Synthetic bank', 'iban' => 'IR000000000000000000000042',
                'card_number' => '4111111111111111'], 'created_by' => $admin->id]);
        $vendor = FinanceAccount::create(['name' => 'Synthetic supplier wallet', 'type' => 'vendor',
            'currency_code' => 'TRY', 'created_by' => $admin->id]);
        $topup = app(DocumentedAccounting::class)->post(['idempotency_key' => (string) Str::uuid(),
            'type' => 'vendor_topup', 'occurred_at' => '2026-10-01', 'service_line' => 'domain', 'category' => 'domain_wholesale',
            'amount_irt' => 1000, 'original_currency' => 'TRY', 'original_minor' => 2000,
            'settlement_currency' => 'IRT', 'settlement_minor' => 1000,
            'funding_account_id' => $partner->id, 'target_account_id' => $vendor->id,
            'bank_reference' => 'SYNTHETIC-TOPUP', 'note' => 'Synthetic documented prepaid credit',
            'tax_status' => 'pending', 'vat_status' => 'none'], $admin->id);
        $proof = "%PDF-1.4\nSynthetic private topup proof\n";
        Storage::disk('local')->put('finance/synthetic-topup.pdf', $proof);
        FinanceDocument::create(['voucher_id' => $topup->id, 'path' => 'finance/synthetic-topup.pdf',
            'name' => 'synthetic-topup.pdf', 'mime' => 'application/pdf', 'size' => strlen($proof),
            'sha256' => hash('sha256', $proof)]);
        $customer = Customer::create(['email' => 'funding-source@example.test', 'status' => 'active']);
        $invoice = Invoice::create(['customer_id' => $customer->id, 'kind' => 'domain', 'currency_code' => 'IRT',
            'subtotal' => 1500, 'tax' => 0, 'total' => 1500, 'paid' => 1500, 'status' => 'paid']);
        $entry = BusinessEntry::create(['kind' => 'expense', 'direction' => 'out', 'currency_code' => 'IRT',
            'amount' => 1000, 'category' => 'domain_wholesale', 'occurred_at' => '2026-10-31',
            'source_type' => $invoice->getMorphClass(), 'source_id' => $invoice->id, 'created_by' => null]);
        return compact('admin', 'partner', 'vendor', 'topup', 'entry', 'invoice');
    }

    private function scope(): FinanceFilter
    {
        return new FinanceFilter([], Carbon::parse('2026-10-31'), Carbon::parse('2026-10-31'));
    }

    public function test_archive_follows_funding_parent_outside_period_and_preserves_masked_historical_identity(): void
    {
        $f = $this->fixture();
        $voucher = app(SupplierCreditReconciliation::class)->reconcile([
            'idempotency_key' => (string) Str::uuid(), 'funding_account_id' => $f['vendor']->id,
            'business_entry_id' => $f['entry']->id, 'occurred_at' => '2026-10-31',
            'original_currency' => 'TRY', 'original_minor' => 2000, 'service_line' => 'domain',
            'bank_reference' => 'SYNTHETIC-DEBIT-42', 'related_voucher_id' => $f['topup']->id,
            'note' => 'Synthetic actual upstream debit',
        ], $f['admin']->id, UploadedFile::fake()->createWithContent('debit.pdf', "%PDF-1.4\nSynthetic supplier receipt\n"));
        $f['partner']->update(['name' => 'Synthetic renamed bank', 'owner_name' => 'Synthetic renamed owner']);
        $before = BusinessEntry::count();
        $path = app(TaxEvidenceBundle::class)->build($this->scope());
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $flow = json_decode($zip->getFromName('funding-flow-vouchers.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([$f['topup']->id, $voucher->id], array_column($flow['vouchers'], 'id'));
            $identity = collect($flow['identity'])->firstWhere('voucher_id', $f['topup']->id);
            $this->assertFalse($identity['legacy_current_identity_only']);
            $this->assertSame('Synthetic original owner', $identity['funding_account_snapshot']['owner_name']);
            $this->assertSame('1111', $identity['funding_account_snapshot']['card_last4']);
            $this->assertNull($identity['funding_account_current']);
            $this->assertStringContainsString('supplier_credit_reconciled', $zip->getFromName('funding-flow-audit.json'));
            $this->assertStringContainsString((string) $f['invoice']->id, $zip->getFromName('funding-flow-audit.json'));
            $parentProof = 'evidence/voucher-'.$f['topup']->id.'/document-'.FinanceDocument::where('voucher_id', $f['topup']->id)->value('id').'.pdf';
            $this->assertNotFalse($zip->getFromName($parentProof));
            $manifest = json_decode($zip->getFromName('manifest-sha256.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($manifest as $item) {
                $this->assertSame($item['sha256'], hash('sha256', $zip->getFromName($item['file'])), $item['file']);
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $content = $zip->getFromIndex($index);
                $this->assertStringNotContainsString('4111111111111111', $content, $zip->getNameIndex($index));
                $this->assertStringNotContainsString('IR000000000000000000000042', $content, $zip->getNameIndex($index));
            }
            $this->assertSame($before, BusinessEntry::count());
            $this->assertSame(1000, (int) BusinessEntry::where('kind', 'expense')->sum('amount'));
            $this->assertSame(0, app(FinanceAudit::class)->inspect($this->scope())['supplier_costs_unreconciled']);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_legacy_identity_is_explicitly_current_data_and_unconfirmed_supplier_cost_stays_visible(): void
    {
        $f = $this->fixture();
        DB::table('finance_audit_events')->where('action', 'voucher_posted')->where('subject_id', $f['topup']->id)
            ->update(['details' => json_encode(['type' => 'vendor_topup', 'base_currency' => 'IRT'])]);
        $this->assertSame(1, app(FinanceAudit::class)->inspect($this->scope())['supplier_costs_unreconciled']);
        $scope = new FinanceFilter([], Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $path = app(TaxEvidenceBundle::class)->build($scope);
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $flow = json_decode($zip->getFromName('funding-flow-vouchers.json'), true, 512, JSON_THROW_ON_ERROR);
            $identity = collect($flow['identity'])->firstWhere('voucher_id', $f['topup']->id);
            $this->assertTrue($identity['legacy_current_identity_only']);
            $this->assertNull($identity['funding_account_snapshot']);
            $this->assertSame('Synthetic original owner', $identity['funding_account_current']['owner_name']);
            $this->assertSame('1111', $identity['funding_account_current']['card_last4']);
            $this->assertSame(0, FinanceVoucher::where('type', 'expense')->count());
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    private function statementRow(FinanceAccount $bank, User $admin, string $date, string $reference, int $credit, int $debit): array
    {
        $csv = "date,reference,credit,debit,currency\n".$date.','.$reference.','.$credit.','.$debit.",IRT\n";
        $path = 'finance/synthetic-statements/'.$reference.'.csv';
        Storage::disk('local')->put($path, $csv);
        $this->assertSame(1, app(BankReconciliation::class)->import($bank, $csv, $path, $admin->id));
        $row = DB::table('finance_bank_transactions')->where('account_id', $bank->id)->where('reference', $reference)->first();

        return ['id' => (int) $row->id, 'import_id' => (int) $row->import_id, 'path' => $path, 'csv' => $csv];
    }

    private function matchedPayment(Customer $customer, User $admin, array $row, int $amount, string $reference): Payment
    {
        $invoice = Invoice::create(['customer_id' => $customer->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => $amount, 'tax' => 0, 'total' => $amount, 'paid' => $amount, 'status' => 'paid']);
        $payment = Payment::create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'gateway' => 'mellat', 'currency_code' => 'IRT', 'amount' => $amount, 'fee' => 0,
            'status' => 'paid', 'paid_at' => Carbon::parse('2026-10-01 08:00:00', 'UTC'), 'ref_id' => $reference]);
        app(BankReconciliation::class)->match($row['id'], $payment->id, $amount, 0, $reference, $admin->id);

        return $payment;
    }

    private function bankAncestorFixture(): array
    {
        $admin = User::create(['name' => 'Synthetic bank evidence reviewer', 'email' => 'bank-chain@example.test',
            'password' => bcrypt('synthetic-only'), 'role' => 'admin']);
        $bank = FinanceAccount::create(['name' => 'Synthetic company bank', 'type' => 'bank', 'currency_code' => 'IRT',
            'owner_name' => 'Synthetic Company', 'created_by' => $admin->id]);
        $partner = FinanceAccount::create(['name' => 'Synthetic business advance', 'type' => 'partner', 'currency_code' => 'IRT',
            'owner_name' => 'Synthetic Partner', 'partner_key' => 'SYNTHETIC-BANK-CHAIN', 'created_by' => $admin->id]);
        $vendor = FinanceAccount::create(['name' => 'Synthetic supplier credit', 'type' => 'vendor',
            'currency_code' => 'TRY', 'created_by' => $admin->id]);
        $customer = Customer::create(['email' => 'bank-chain-customer@example.test', 'status' => 'active']);
        $collectionRow = $this->statementRow($bank, $admin, '2026-10-01', 'SYNTHETIC-COLLECTION', 1000, 0);
        $advanceRow = $this->statementRow($bank, $admin, '2026-10-02', 'SYNTHETIC-ADVANCE', 0, 1000);
        $unrelatedRow = $this->statementRow($bank, $admin, '2026-09-01', 'SYNTHETIC-UNRELATED', 700, 0);
        $periodRow = $this->statementRow($bank, $admin, '2026-10-31', 'SYNTHETIC-PERIOD-ONLY', 99, 0);
        $payment = $this->matchedPayment($customer, $admin, $collectionRow, 1000, 'SYNTHETIC-RELATED-PAYMENT');
        $unrelatedPayment = $this->matchedPayment($customer, $admin, $unrelatedRow, 700, 'SYNTHETIC-UNRELATED-PAYMENT');
        $data = ['idempotency_key' => (string) Str::uuid(), 'type' => 'bank_collection', 'occurred_at' => '2026-10-01',
            'service_line' => 'domain', 'category' => 'domain_wholesale', 'amount_irt' => 1000,
            'original_currency' => 'IRT', 'original_minor' => 1000, 'settlement_currency' => null, 'settlement_minor' => 0,
            'target_account_id' => $bank->id, 'bank_transaction_id' => $collectionRow['id'],
            'bank_reference' => 'SYNTHETIC-COLLECTION', 'note' => 'Synthetic verified bank collection',
            'tax_status' => 'pending', 'vat_status' => 'none'];
        $collection = app(DocumentedAccounting::class)->post($data, $admin->id);
        $advance = app(DocumentedAccounting::class)->post(array_replace($data, [
            'idempotency_key' => (string) Str::uuid(), 'type' => 'partner_advance', 'occurred_at' => '2026-10-02',
            'settlement_currency' => 'IRT', 'settlement_minor' => 1000, 'funding_account_id' => $bank->id,
            'target_account_id' => $partner->id, 'bank_transaction_id' => $advanceRow['id'],
            'bank_reference' => 'SYNTHETIC-ADVANCE', 'related_voucher_id' => $collection->id,
        ]), $admin->id);
        $topup = app(DocumentedAccounting::class)->post(array_replace($data, [
            'idempotency_key' => (string) Str::uuid(), 'type' => 'vendor_topup', 'occurred_at' => '2026-10-03',
            'original_currency' => 'TRY', 'original_minor' => 2000,
            'settlement_currency' => 'IRT', 'settlement_minor' => 1000, 'funding_account_id' => $partner->id,
            'target_account_id' => $vendor->id, 'bank_transaction_id' => null, 'bank_reference' => 'SYNTHETIC-VENDOR-TOPUP',
            'funding_mode' => 'advance', 'related_voucher_id' => $advance->id, 'fx_basis' => 'Synthetic documented conversion',
        ]), $admin->id);
        $invoice = Invoice::create(['customer_id' => $customer->id, 'kind' => 'domain', 'currency_code' => 'IRT',
            'subtotal' => 1500, 'tax' => 0, 'total' => 1500, 'paid' => 1500, 'status' => 'paid']);
        $entry = BusinessEntry::create(['kind' => 'expense', 'direction' => 'out', 'currency_code' => 'IRT',
            'amount' => 1000, 'category' => 'domain_wholesale', 'occurred_at' => '2026-10-31',
            'source_type' => $invoice->getMorphClass(), 'source_id' => $invoice->id, 'created_by' => null]);
        $supplier = app(SupplierCreditReconciliation::class)->reconcile([
            'idempotency_key' => (string) Str::uuid(), 'funding_account_id' => $vendor->id,
            'business_entry_id' => $entry->id, 'occurred_at' => '2026-10-31', 'original_currency' => 'TRY',
            'original_minor' => 2000, 'service_line' => 'domain', 'bank_reference' => 'SYNTHETIC-ACTUAL-SUPPLIER-DEBIT',
            'related_voucher_id' => $topup->id, 'note' => 'Synthetic confirmed supplier consumption',
        ], $admin->id, UploadedFile::fake()->createWithContent('supplier.pdf', "%PDF-1.4\nSynthetic supplier debit proof\n"));

        return compact('collectionRow', 'advanceRow', 'unrelatedRow', 'periodRow', 'payment', 'unrelatedPayment',
            'collection', 'advance', 'topup', 'supplier');
    }

    private function archiveCsvRows(ZipArchive $zip, string $name): array
    {
        $contents = $zip->getFromName($name);
        $this->assertNotFalse($contents, $name);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\\xEF\\xBB\\xBF/', '', $contents));
        rewind($stream);
        try {
            $header = fgetcsv($stream, escape: '');
            $rows = [];
            while (($cells = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = array_combine($header, $cells);
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function normalizedReport(): array
    {
        return json_decode(json_encode(app(AccountingReport::class)->report($this->scope()), JSON_THROW_ON_ERROR),
            true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_archive_follows_only_explicit_ancestor_bank_rows_matches_payments_and_imports_outside_the_period(): void
    {
        $f = $this->bankAncestorFixture();
        $before = $this->normalizedReport();
        $counts = [BusinessEntry::count(), FinanceVoucher::count()];
        $path = app(TaxEvidenceBundle::class)->build($this->scope());
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $flow = json_decode($zip->getFromName('funding-flow-vouchers.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([$f['collection']->id, $f['advance']->id, $f['topup']->id, $f['supplier']->id],
                array_column($flow['vouchers'], 'id'));
            $bankRows = $this->archiveCsvRows($zip, 'bank-transactions.csv');
            $this->assertSame([(string) $f['collectionRow']['id'], (string) $f['advanceRow']['id'], (string) $f['periodRow']['id']],
                array_column($bankRows, 'ID'));
            $matches = $this->archiveCsvRows($zip, 'bank-matches.csv');
            $this->assertCount(1, $matches);
            $this->assertSame((string) $f['collectionRow']['id'], $matches[0]['Bank row']);
            $this->assertSame((string) $f['payment']->id, $matches[0]['Payment']);
            $payments = $this->archiveCsvRows($zip, 'bank-settlement-payments.csv');
            $this->assertCount(1, $payments);
            $this->assertSame((string) $f['payment']->id, $payments[0]['Payment ID']);
            $this->assertSame($f['payment']->ref_id, $payments[0]['External reference']);
            foreach (['collectionRow', 'advanceRow', 'periodRow'] as $key) {
                $this->assertSame($f[$key]['csv'], $zip->getFromName('evidence/bank/import-'.$f[$key]['import_id'].'.csv'));
            }
            $this->assertFalse($zip->getFromName('evidence/bank/import-'.$f['unrelatedRow']['import_id'].'.csv'));
            $report = json_decode($zip->getFromName('report.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($before, $report['report']);
            $this->assertSame(1000, $report['report']['summary']['expense']);
            $this->assertSame(0, $report['report']['summary']['revenue']);
            $periodVouchers = $this->archiveCsvRows($zip, 'vouchers.csv');
            $this->assertSame([(string) $f['supplier']->id], array_column($periodVouchers, 'ID'));
            foreach (json_decode($zip->getFromName('manifest-sha256.json'), true, 512, JSON_THROW_ON_ERROR) as $item) {
                $this->assertSame($item['sha256'], hash('sha256', $zip->getFromName($item['file'])), $item['file']);
            }
            $this->assertSame($counts, [BusinessEntry::count(), FinanceVoucher::count()]);
            $this->assertSame($before, $this->normalizedReport());
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_archive_rejects_a_missing_bank_import_referenced_by_an_ancestor_outside_the_period(): void
    {
        $f = $this->bankAncestorFixture();
        Storage::disk('local')->delete($f['collectionRow']['path']);
        try {
            app(TaxEvidenceBundle::class)->build($this->scope());
            $this->fail('A missing ancestor bank statement was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Bank evidence missing or changed', $e->getMessage());
            $this->assertCount(0, Storage::disk('local')->allFiles('finance/exports'));
        }
    }

    public function test_archive_rejects_a_changed_bank_import_referenced_by_an_ancestor_outside_the_period(): void
    {
        $f = $this->bankAncestorFixture();
        Storage::disk('local')->put($f['advanceRow']['path'], 'Changed synthetic bank statement');
        try {
            app(TaxEvidenceBundle::class)->build($this->scope());
            $this->fail('A changed ancestor bank statement was accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Bank evidence missing or changed', $e->getMessage());
            $this->assertCount(0, Storage::disk('local')->allFiles('finance/exports'));
        }
    }
}
