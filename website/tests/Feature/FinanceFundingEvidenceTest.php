<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\FinanceVoucher;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\SupplierCreditReconciliation;
use App\Services\Finance\TaxEvidenceBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
}
