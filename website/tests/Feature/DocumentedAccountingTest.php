<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceAsset;
use App\Models\FinanceDocument;
use App\Models\FinanceLine;
use App\Models\FinanceTaxProfile;
use App\Models\FinanceVoucher;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\AccountingMoney;
use App\Services\Finance\AccountingReport;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\FinanceFilter;
use App\Services\Finance\FiscalPeriods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentedAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function journal(): DocumentedAccounting
    {
        return app(DocumentedAccounting::class);
    }

    private function admin(string $role = 'admin'): User
    {
        return User::create(['name' => 'Finance', 'email' => Str::uuid().'@example.test', 'password' => bcrypt('test'), 'role' => $role]);
    }

    private function account(string $type = 'partner', string $currency = 'TRY'): FinanceAccount
    {
        return FinanceAccount::create(['name' => 'Test '.$type, 'type' => $type, 'currency_code' => $currency, 'owner_name' => 'Test partner', 'created_by' => 1]);
    }

    private function data(FinanceAccount $funding, array $override = []): array
    {
        return array_replace(['idempotency_key' => (string) Str::uuid(), 'type' => 'expense', 'occurred_at' => '2026-10-05', 'service_line' => 'domain', 'category' => 'domain_wholesale',
            'vendor' => 'Supplier', 'invoice_number' => (string) Str::uuid(), 'bank_reference' => 'statement-123', 'fx_basis' => 'Actual bank conversion 2026-10-05',
            'note' => 'Business domain purchase', 'amount_irt' => 1_000_000, 'original_currency' => 'USD', 'original_minor' => 1000,
            'settlement_currency' => $funding->currency_code, 'settlement_minor' => 34_000, 'funding_account_id' => $funding->id,
            'target_account_id' => null, 'tax_status' => 'pending', 'tax_reference' => null, 'input_vat_irt' => 0, 'vat_status' => 'none'], $override);
    }

    private function cost(FinanceAccount $f, array $override = []): FinanceVoucher
    {
        return $this->journal()->post($this->data($f, $override), 1);
    }

    private function scope(): FinanceFilter
    {
        return new FinanceFilter([], Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
    }

    public function test_partner_paid_foreign_purchase_records_real_claim_and_cost_once(): void
    {
        $partner = $this->account();
        $d = $this->data($partner);
        $v = $this->journal()->post($d, 1);
        $this->journal()->post($d, 1);
        $this->assertSame(1, FinanceVoucher::count());
        $this->assertSame(1_000_000, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame(['irt' => 1_000_000, 'native' => 34_000], $this->journal()->balance($partner));
        $this->assertSame(1000, $v->original_minor);
        $this->assertEquals(FinanceLine::sum('debit_irt'), FinanceLine::sum('credit_irt'));
    }

    public function test_a_reused_submission_key_with_changed_amount_is_rejected(): void
    {
        $d = $this->data($this->account());
        $this->journal()->post($d, 1);
        $d['amount_irt']++;
        $this->expectException(ValidationException::class);
        $this->journal()->post($d, 1);
    }

    public function test_supplier_topup_is_asset_and_only_consumption_is_expense(): void
    {
        $partner = $this->account();
        $vendor = $this->account('vendor', 'USD');
        $this->cost($partner, ['type' => 'vendor_topup', 'target_account_id' => $vendor->id, 'amount_irt' => 10_000_000, 'original_minor' => 10000, 'settlement_minor' => 340000]);
        $this->assertSame(0, app(BusinessLedger::class)->summary()['expense']);
        $this->cost($vendor, ['amount_irt' => 1_000_000, 'settlement_minor' => 1000]);
        $this->assertSame(['irt' => 9_000_000, 'native' => 9000], $this->journal()->balance($vendor));
        $this->assertSame(1_000_000, app(BusinessLedger::class)->summary()['expense']);
    }

    public function test_supplier_consumption_cannot_use_today_fx_instead_of_carrying_value(): void
    {
        $partner = $this->account();
        $vendor = $this->account('vendor', 'USD');
        $this->cost($partner, ['type' => 'vendor_topup', 'target_account_id' => $vendor->id, 'amount_irt' => 10_000_000, 'original_minor' => 10000, 'settlement_minor' => 340000]);
        $this->expectException(ValidationException::class);
        $this->cost($vendor, ['amount_irt' => 2_000_000, 'settlement_minor' => 1000]);
    }

    public function test_existing_automatic_cost_can_be_documented_without_counting_it_twice(): void
    {
        $e = BusinessEntry::create(['currency_code' => 'IRT', 'direction' => 'out', 'kind' => 'expense', 'category' => 'domain_wholesale', 'amount' => 1_000_000, 'occurred_at' => '2026-10-05']);
        $v = $this->cost($this->account(), ['business_entry_id' => $e->id]);
        $this->assertFalse($v->owns_business_entry);
        $this->assertSame(1_000_000, app(BusinessLedger::class)->summary()['expense']);
        $this->journal()->reverse($v, '2026-10-06', 'Reverse evidence only', 1);
        $this->assertSame(1_000_000, app(BusinessLedger::class)->summary()['expense']);
        $this->cost($this->account(), ['business_entry_id' => $e->id]);
        $this->assertSame(1_000_000, app(BusinessLedger::class)->summary()['expense']);
    }

    public function test_reversal_retains_history_and_reduces_cost_and_partner_claim(): void
    {
        $a = $this->account();
        $v = $this->cost($a);
        $r = $this->journal()->reverse($v, '2026-10-06', 'Wrong invoice amount', 1);
        $this->assertSame($v->id, (int) $r->reversal_of);
        $this->assertSame(2, FinanceVoucher::count());
        $this->assertSame(0, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($a));
        $this->assertDatabaseHas('business_ledger', ['id' => $v->business_entry_id, 'kind' => 'expense']);
        $this->expectException(ValidationException::class);
        $this->journal()->reverse($v, '2026-10-07', 'Cannot reverse twice', 1);
    }

    public function test_duplicate_supplier_invoice_is_rejected_even_with_a_new_key(): void
    {
        $a = $this->account();
        $this->cost($a, ['invoice_number' => 'INV-1']);
        $this->expectException(ValidationException::class);
        $this->cost($a, ['invoice_number' => 'INV-1']);
    }

    public function test_fixed_asset_is_capitalized_and_monthly_depreciation_is_idempotent(): void
    {
        $v = $this->cost($this->account(), ['type' => 'asset_purchase', 'asset_name' => 'Server', 'asset_serial' => 'TEST',
            'in_service_at' => '2026-10-05', 'useful_life_months' => 10, 'residual_irt' => 0]);
        $this->assertSame(0, app(BusinessLedger::class)->summary()['expense']);
        $a = FinanceAsset::sole();
        Carbon::setTestNow(Carbon::parse('2026-11-05', 'UTC'));
        $dep = $this->journal()->depreciate($a, '2026-10', 1);
        $this->journal()->depreciate($a, '2026-10', 1);
        $this->assertSame(100_000, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame('pending', $dep->tax_status);
        $this->assertSame(2, FinanceVoucher::count());
        $this->expectException(ValidationException::class);
        $this->journal()->reverse($v, '2026-11-05', 'Reverse asset with active depreciation', 1);
    }

    public function test_documented_period_lock_rejects_backdated_new_vouchers(): void
    {
        $this->journal()->closeThrough('2026-10-10', 'October journal review', 1);
        $this->expectException(ValidationException::class);
        $this->cost($this->account());
    }

    public function test_foreign_supplier_tax_is_not_assumed_to_be_iranian_input_vat(): void
    {
        $this->expectException(ValidationException::class);
        $this->cost($this->account(), ['input_vat_irt' => 100000, 'vat_status' => 'eligible', 'tax_reference' => 'Review reference']);
    }

    public function test_domestic_input_vat_is_separate_from_expense_and_income_tax(): void
    {
        $partner = $this->account('partner', 'IRT');
        $v = $this->cost($partner, ['amount_irt' => 11_000_000, 'original_currency' => 'IRT', 'original_minor' => 11_000_000,
            'settlement_minor' => 11_000_000, 'input_vat_irt' => 1_000_000, 'vat_status' => 'eligible', 'tax_status' => 'pending', 'tax_reference' => 'Invoice review 2026-10']);
        $this->assertSame(0, app(AccountingReport::class)->report($this->scope())['input_vat']);
        FinanceDocument::create(['voucher_id' => $v->id, 'path' => 'private-proof', 'name' => 'proof.pdf', 'mime' => 'application/pdf', 'sha256' => str_repeat('a', 64), 'size' => 100]);
        $this->journal()->reviewTax($v, 'eligible', 'Reviewed invoice and input VAT entitlement', 1);
        BusinessEntry::create(['currency_code' => 'IRT', 'direction' => 'in', 'kind' => 'revenue', 'amount' => 11_000_000, 'occurred_at' => '2026-10-05']);
        BusinessEntry::create(['currency_code' => 'IRT', 'direction' => 'in', 'kind' => 'tax_collected', 'amount' => 1_100_000, 'occurred_at' => '2026-10-05']);
        $p = FinanceTaxProfile::create(['from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'legal_name' => 'Test Company',
            'entity_type' => 'corporate', 'income_rate_bp' => 2500, 'legal_basis' => 'Reviewed period and rate', 'reviewed' => true, 'created_by' => 1]);
        $r = app(AccountingReport::class)->report($this->scope());
        $this->assertSame(1_000_000, $r['summary']['net_profit']);
        $this->assertSame(10_000_000, $r['eligible_costs']);
        $this->assertSame(250_000, $r['income_tax_estimate']);
        $this->assertSame(100_000, $r['vat_due']);
        $this->assertSame(0, $r['vat_credit']);
    }

    public function test_tax_profile_does_not_apply_annual_allowances_to_a_different_period(): void
    {
        FinanceTaxProfile::create(['from_date' => '2026-01-01', 'to_date' => '2026-12-31', 'legal_name' => 'Test Company',
            'entity_type' => 'corporate', 'income_rate_bp' => 2500, 'legal_basis' => 'Annual reviewed basis', 'created_by' => 1]);
        $r = app(AccountingReport::class)->report($this->scope());
        $this->assertNull($r['profile']);
        $this->assertNull($r['income_tax_estimate']);
        $this->assertNull($r['vat_due']);
    }

    public function test_finance_search_applies_to_totals_history_and_csv(): void
    {
        $this->actingAs($this->admin());
        app(BusinessLedger::class)->manual('expense', 100, 'other', Carbon::parse('2026-10-03'), 'search needle', 1);
        app(BusinessLedger::class)->manual('expense', 200, 'other', Carbon::parse('2026-09-03'), 'search needle', 1);
        $url = '/admin/finance?from=2026-10-01&to=2026-10-25&q=needle';
        $this->get($url)->assertOk()->assertViewHas('summary', fn ($s) => $s['expense'] === 100)
            ->assertViewHas('recent', fn ($rows) => $rows->total() === 1);
        $csv = $this->get('/admin/finance/export?from=2026-10-01&to=2026-10-25&q=needle')->assertOk()->streamedContent();
        $this->assertStringContainsString('search needle', $csv);
        $this->assertStringNotContainsString('2026-09-03', $csv);
    }

    public function test_taxed_refund_reduces_net_sales_profit_and_vat_without_double_subtracting_cash(): void
    {
        $c = Customer::create(['email' => 'refund@example.test', 'password' => 'test', 'status' => 'active']);
        $inv = Invoice::create(['customer_id' => $c->id, 'kind' => 'domain', 'currency_code' => 'IRT', 'subtotal' => 1000, 'tax' => 100, 'total' => 1100, 'paid' => 1100, 'status' => 'paid']);
        $l = app(BusinessLedger::class);
        $l->recordCreditSale($inv);
        $l->recordInvoiceRefund($inv, 1100);
        $s = $l->summary();
        $this->assertSame(0, $s['revenue']);
        $this->assertSame(0, $s['net_profit']);
        $this->assertSame(0, $s['tax_collected']);
        $this->assertSame(0, $s['cash']);
    }

    public function test_new_pages_render_in_all_languages_and_staff_cannot_access_them(): void
    {
        $admin = $this->admin();
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $this->actingAs($admin)->get('/admin/finance/accounting')->assertOk()->assertDontSee('finance.accounting');
        }
        $this->actingAs($this->admin('staff'))->get('/admin/finance/accounting')->assertForbidden();
        $this->post('/admin/finance/accounting/accounts', ['name' => 'blocked', 'type' => 'bank', 'currency_code' => 'IRT'])->assertForbidden();
    }

    public function test_evidence_is_private_download_is_admin_only_and_exports_neutralize_formulas(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $v = $this->cost($this->account(), ['note' => '=HYPERLINK("https://example.test")']);
        $this->actingAs($admin)->post('/admin/finance/accounting/vouchers/'.$v->id.'/evidence', [
            'evidence' => UploadedFile::fake()->create('receipt.pdf', 1, 'application/pdf')])->assertRedirect();
        $doc = FinanceDocument::sole();
        Storage::disk('local')->assertExists($doc->path);
        $this->get('/admin/finance/accounting/evidence/'.$doc->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $this->get('/admin/finance/accounting/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->actingAs($this->admin('staff'))->get('/admin/finance/accounting/evidence/'.$doc->id)->assertForbidden();
    }

    public function test_posted_documented_cost_cannot_be_deleted_through_the_old_finance_screen(): void
    {
        $v = $this->cost($this->account());
        $this->actingAs($this->admin())->post('/admin/finance/'.$v->business_entry_id.'/delete')->assertSessionHasErrors('entry');
        $this->assertDatabaseHas('business_ledger', ['id' => $v->business_entry_id]);
    }

    public function test_currency_amounts_use_correct_exponent_and_no_float_money(): void
    {
        $this->assertSame(10000, AccountingMoney::minor('100.00', 'USD'));
        $this->assertSame(340001, AccountingMoney::minor('۳۴۰۰.۰۱', 'TRY'));
        $this->assertSame('1.99 EUR', AccountingMoney::display(199, 'EUR'));
        $this->expectException(ValidationException::class);
        AccountingMoney::minor('1.001', 'USD');
    }

    public function test_actual_invoice_correction_posts_only_the_difference_and_reversal_restores_estimate(): void
    {
        $e = BusinessEntry::create(['currency_code' => 'IRT', 'direction' => 'out', 'kind' => 'expense', 'amount' => 1_000_000, 'occurred_at' => '2026-10-05']);
        $v = $this->cost($this->account(), ['type' => 'cost_adjustment', 'business_entry_id' => $e->id, 'amount_irt' => 800_000]);
        $this->assertSame(800_000, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame(-200_000, (int) $v->book_delta_irt);
        $this->journal()->reverse($v, '2026-10-06', 'Reverse invoice correction', 1);
        $this->assertSame(1_000_000, app(BusinessLedger::class)->summary()['expense']);
        $v2 = $this->cost($this->account(), ['type' => 'cost_adjustment', 'business_entry_id' => $e->id, 'amount_irt' => 1_200_000]);
        $this->assertSame(1_200_000, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame(200_000, (int) $v2->book_delta_irt);
    }

    public function test_expense_cannot_credit_a_phantom_destination_account(): void
    {
        $a = $this->account();
        $target = $this->account('bank', 'IRT');
        $this->expectException(ValidationException::class);
        $this->cost($a, ['target_account_id' => $target->id]);
    }

    public function test_tax_review_is_append_only_and_requires_evidence(): void
    {
        $v = $this->cost($this->account());
        FinanceDocument::create(['voucher_id' => $v->id, 'path' => 'private-proof', 'name' => 'proof.pdf', 'mime' => 'application/pdf', 'sha256' => str_repeat('a', 64), 'size' => 100]);
        $this->journal()->reviewTax($v, 'eligible', 'Reviewed invoice and bank statement', 1);
        $r = app(AccountingReport::class)->report($this->scope());
        $this->assertSame(1_000_000, $r['eligible_costs']);
        $this->journal()->reviewTax($v, 'rejected', 'Missing electronic invoice eligibility', 1);
        $this->assertSame(2, DB::table('finance_tax_reviews')->count());
        $this->assertSame('pending', $v->fresh()->tax_status);
        $this->assertSame(0, app(AccountingReport::class)->report($this->scope())['eligible_costs']);
    }

    public function test_persian_fiscal_year_and_quarter_filters_have_exact_boundaries(): void
    {
        $f = FinanceFilter::fromRequest(Request::create('/', 'GET', ['fiscal_year' => 1405, 'quarter' => 1]));
        $this->assertSame('2026-03-21', $f->from->toDateString());
        $this->assertSame('2026-06-21', $f->to->toDateString());
        [$from,$to] = FiscalPeriods::window(1403);
        $this->assertSame('2024-03-20', $from->toDateString());
        $this->assertSame('2025-03-20', $to->toDateString());
    }

    public function test_foreign_bank_spend_recognizes_realized_fx_separately_and_reverses_it(): void
    {
        $partner = $this->account();
        $bank = $this->account('bank', 'USD');
        $this->cost($partner, ['type' => 'opening_balance', 'target_account_id' => $bank->id, 'funding_account_id' => null,
            'amount_irt' => 10_000_000, 'original_minor' => 10000, 'settlement_minor' => 0]);
        $v = $this->cost($bank, ['amount_irt' => 1_200_000, 'settlement_minor' => 1000]);
        $s = app(BusinessLedger::class)->summary();
        $this->assertSame(1_200_000, $s['expense']);
        $this->assertSame(200_000, $s['other_income']);
        $this->assertSame(-1_000_000, $s['net_profit']);
        $this->assertSame(['irt' => 9_000_000, 'native' => 9000], $this->journal()->balance($bank));
        $this->journal()->reverse($v, '2026-10-06', 'Reverse foreign bank purchase', 1);
        $this->assertSame(0, app(BusinessLedger::class)->summary()['other_income']);
        $this->assertSame(0, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame(['irt' => 10_000_000, 'native' => 10000], $this->journal()->balance($bank));
    }

    public function test_partner_repayment_clears_native_claim_and_recognizes_actual_fx_loss(): void
    {
        $partner = $this->account('partner', 'USD');
        $bank = $this->account('bank', 'IRT');
        $this->cost($partner, ['settlement_minor' => 1000]);
        $this->cost($partner, ['type' => 'opening_balance', 'target_account_id' => $bank->id, 'funding_account_id' => null,
            'amount_irt' => 2_000_000, 'original_currency' => 'IRT', 'original_minor' => 2_000_000, 'settlement_minor' => 0]);
        $this->cost($bank, ['type' => 'partner_repayment', 'target_account_id' => $partner->id,
            'amount_irt' => 1_100_000, 'original_currency' => 'USD', 'original_minor' => 1000, 'settlement_minor' => 1_100_000]);
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($partner));
        $this->assertSame(['irt' => 900_000, 'native' => 900_000], $this->journal()->balance($bank));
        $this->assertSame(1_100_000, app(BusinessLedger::class)->summary()['expense']);
        $this->assertEquals(FinanceLine::sum('debit_irt'), FinanceLine::sum('credit_irt'));
    }

    public function test_reversed_depreciation_can_be_reposted_without_losing_its_history(): void
    {
        $this->cost($this->account(), ['type' => 'asset_purchase', 'asset_name' => 'Server', 'in_service_at' => '2026-10-05', 'useful_life_months' => 10, 'residual_irt' => 0]);
        Carbon::setTestNow(Carbon::parse('2026-11-05', 'UTC'));
        $asset = FinanceAsset::sole();
        $first = $this->journal()->depreciate($asset, '2026-10', 1);
        $this->journal()->reverse($first, '2026-11-05', 'Recheck book depreciation', 1);
        $second = $this->journal()->depreciate($asset, '2026-10', 1);
        $this->assertNotEquals($first->id, $second->id);
        $this->assertSame(100_000, app(BusinessLedger::class)->summary()['expense']);
    }

    public function test_unreviewed_tax_profile_cannot_produce_a_tax_payable(): void
    {
        FinanceTaxProfile::create(['from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'legal_name' => 'Test', 'entity_type' => 'corporate', 'income_rate_bp' => 2500, 'legal_basis' => 'Unreviewed draft', 'reviewed' => false, 'created_by' => 1]);
        $r = app(AccountingReport::class)->report($this->scope());
        $this->assertNull($r['income_tax_estimate']);
        $this->assertNull($r['vat_due']);
    }

    public function test_prior_period_reversal_blocks_tax_estimate_until_period_treatment_is_resolved(): void
    {
        $v = $this->cost($this->account(), ['occurred_at' => '2026-09-30']);
        $this->journal()->reverse($v, '2026-10-05', 'Prior period correction', 1);
        FinanceTaxProfile::create(['from_date' => '2026-10-01', 'to_date' => '2026-10-31', 'legal_name' => 'Test', 'entity_type' => 'corporate', 'income_rate_bp' => 2500, 'legal_basis' => 'Reviewed tax profile', 'reviewed' => true, 'created_by' => 1]);
        $r = app(AccountingReport::class)->report($this->scope());
        $this->assertSame(1, $r['coverage']['cross_period_reversals']);
        $this->assertNull($r['income_tax_due']);
        $this->assertNull($r['vat_due']);
    }
}
