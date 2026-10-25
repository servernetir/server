<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\TaxEvidenceBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class FinanceEvidenceBundleTest extends TestCase
{
    use RefreshDatabase;

    private function scope(): FinanceFilter
    {
        return new FinanceFilter([], Carbon::parse('2026-10-01'), Carbon::parse('2026-10-25'));
    }

    private function document(): FinanceDocument
    {
        Storage::fake('local');
        $account = FinanceAccount::create(['name' => 'Test partner', 'type' => 'partner', 'currency_code' => 'IRT', 'created_by' => 1]);
        $voucher = app(DocumentedAccounting::class)->post(['idempotency_key' => (string) Str::uuid(), 'type' => 'expense',
            'occurred_at' => '2026-10-01', 'service_line' => 'domain', 'category' => 'domain_wholesale', 'amount_irt' => 1000,
            'original_currency' => 'IRT', 'original_minor' => 1000, 'settlement_currency' => 'IRT', 'settlement_minor' => 1000,
            'funding_account_id' => $account->id, 'note' => 'Documented purchase', 'tax_status' => 'pending', 'vat_status' => 'none'], 1);
        $content = "%PDF-1.4\nTest purchase and bank payment evidence\n";
        Storage::disk('local')->put('finance/test-proof.pdf', $content);

        return FinanceDocument::create(['voucher_id' => $voucher->id, 'path' => 'finance/test-proof.pdf', 'name' => 'proof.pdf',
            'mime' => 'application/pdf', 'size' => strlen($content), 'sha256' => hash('sha256', $content)]);
    }

    public function test_private_bundle_contains_evidence_integrity_manifest_and_exact_rial_conversion(): void
    {
        $doc = $this->document();
        $before = BusinessEntry::count();
        $path = app(TaxEvidenceBundle::class)->build($this->scope());
        $zip = new ZipArchive;
        try {
            $this->assertTrue($zip->open($path));
            $this->assertNotFalse($zip->getFromName('ledger.csv'));
            $this->assertStringContainsString('10000', $zip->getFromName('vouchers.csv'));
            $report = json_decode($zip->getFromName('report.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('management_estimate_not_filed', $report['status']);
            $this->assertSame(10, $report['rials_per_toman']);
            $this->assertSame(1000, $report['report']['summary']['expense']);
            $name = 'evidence/voucher-'.$doc->voucher_id.'/document-'.$doc->id.'.pdf';
            $proof = $zip->getFromName($name);
            $this->assertNotFalse($proof);
            $manifest = json_decode($zip->getFromName('manifest-sha256.json'), true, 512, JSON_THROW_ON_ERROR);
            $item = collect($manifest)->firstWhere('file', $name);
            $this->assertSame(hash('sha256', $proof), $item['sha256']);
            $this->assertSame($before, BusinessEntry::count());
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_bundle_refuses_changed_evidence_instead_of_exporting_an_incomplete_tax_package(): void
    {
        $doc = $this->document();
        Storage::disk('local')->put($doc->path, 'changed after approval');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Evidence missing or hash mismatch');
        app(TaxEvidenceBundle::class)->build($this->scope());
    }

    public function test_bundle_requires_explicit_dates(): void
    {
        $this->expectException(ValidationException::class);
        app(TaxEvidenceBundle::class)->build(new FinanceFilter([], null, null));
    }

    public function test_monthly_book_roi_uses_capital_already_contributed_before_the_period(): void
    {
        $l = app(BusinessLedger::class);
        $l->manual('capital', 10000, null, Carbon::parse('2026-08-01'), null, 1);
        BusinessEntry::create(['currency_code' => 'IRT', 'kind' => 'revenue', 'direction' => 'in', 'amount' => 2000, 'occurred_at' => '2026-10-05']);
        $summary = $l->summary(filter: $this->scope());
        $this->assertSame(20.0, $summary['roi']);
        $this->assertSame(10000, $summary['roi_capital_basis']);
    }

    public function test_populated_accounting_migration_cannot_drop_financial_history(): void
    {
        $this->document();
        $migration = require database_path('migrations/2026_10_25_120000_create_documented_accounting_tables.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Accounting contains records');
        $migration->down();
    }
}
