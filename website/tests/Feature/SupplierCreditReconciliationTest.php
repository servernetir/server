<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\FinanceLine;
use App\Models\FinanceVoucher;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\SupplierCreditReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierCreditReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(10, 0));
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Storage::fake('local');
        $this->admin = User::create(['name' => 'Synthetic finance reviewer', 'email' => 'supplier-review@example.test',
            'role' => 'admin', 'password' => bcrypt('test')]);
        $this->customer = Customer::create(['email' => 'supplier-customer@example.test', 'status' => 'active', 'locale' => 'fa']);
    }

    private function account(string $type = 'vendor', string $currency = 'USD'): FinanceAccount
    {
        return FinanceAccount::create(['name' => 'Synthetic '.$type.' '.$currency, 'type' => $type,
            'currency_code' => $currency, 'owner_name' => 'Synthetic owner', 'created_by' => $this->admin->id]);
    }

    private function topup(FinanceAccount $vendor, int $native = 10000, int $irt = 1000000, string $date = '2026-10-20'): FinanceVoucher
    {
        $partner = $this->account('partner', 'IRT');

        return app(DocumentedAccounting::class)->post([
            'idempotency_key' => (string) Str::uuid(), 'type' => 'vendor_topup', 'occurred_at' => $date,
            'service_line' => 'domain', 'category' => 'domain_wholesale', 'amount_irt' => $irt,
            'original_currency' => $vendor->currency_code, 'original_minor' => $native,
            'settlement_currency' => 'IRT', 'settlement_minor' => $irt, 'fx_basis' => 'Synthetic actual supplier conversion',
            'funding_account_id' => $partner->id, 'target_account_id' => $vendor->id,
            'note' => 'Synthetic prepaid supplier funding', 'tax_status' => 'pending', 'vat_status' => 'none',
        ], $this->admin->id);
    }

    private function cost(int $amount = 100000, string $date = '2026-10-25'): BusinessEntry
    {
        $invoice = Invoice::create(['customer_id' => $this->customer->id, 'kind' => 'domain', 'currency_code' => 'IRT',
            'subtotal' => 200000, 'tax' => 20000, 'total' => 220000, 'paid' => 220000, 'status' => 'paid',
            'issued_at' => $date, 'paid_at' => $date]);

        return BusinessEntry::create(['kind' => 'expense', 'category' => 'domain_wholesale', 'currency_code' => 'IRT',
            'direction' => 'out', 'amount' => $amount, 'occurred_at' => $date, 'created_by' => null,
            'source_type' => $invoice->getMorphClass(), 'source_id' => $invoice->id, 'note' => 'Synthetic automatic supplier estimate']);
    }

    private function data(FinanceAccount $account, BusinessEntry $entry, array $overrides = []): array
    {
        return array_replace(['idempotency_key' => (string) Str::uuid(), 'funding_account_id' => $account->id,
            'business_entry_id' => $entry->id, 'occurred_at' => $entry->occurred_at->toDateString(),
            'original_currency' => $account->currency_code, 'original_minor' => 1000, 'service_line' => 'domain',
            'bank_reference' => 'SUPPLIER-DEBIT-1001', 'note' => 'Confirmed synthetic supplier debit',
            'vendor' => 'Synthetic supplier', 'invoice_number' => 'SUPPLIER-INVOICE-12'], $overrides);
    }

    private function proof(string $suffix = 'original'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('supplier-proof.pdf', "%PDF-1.4\nSynthetic supplier debit ".$suffix."\n%%EOF");
    }

    private function reconcile(array $d, ?UploadedFile $proof = null): FinanceVoucher
    {
        return app(SupplierCreditReconciliation::class)->reconcile($d, $this->admin->id, $proof ?? $this->proof());
    }

    private function snapshot(): array
    {
        return [FinanceVoucher::count(), FinanceLine::count(), BusinessEntry::count(), FinanceDocument::count(),
            DB::table('finance_audit_events')->count(), Storage::disk('local')->allFiles()];
    }

    private function rejected(array $data, string $error, ?UploadedFile $proof = null): void
    {
        $before = $this->snapshot();
        try {
            $this->reconcile($data, $proof);
            $this->fail('Expected an explicit accounting review failure.');
        } catch (ValidationException $e) {
            $this->assertContains(__('finance.'.$error), $e->errors()['accounting'] ?? []);
        }
        $this->assertSame($before, $this->snapshot(), 'A rejected reconciliation must not mutate ledgers or leave a receipt.');
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_verified_prepaid_consumption_links_existing_cost_without_second_expense_or_revenue(): void
    {
        $vendor = $this->account();
        $topup = $this->topup($vendor);
        $this->assertSame(0, app(BusinessLedger::class)->summary()['expense'], 'Funding supplier credit is an asset transfer.');
        $entry = $this->cost();
        $before = app(BusinessLedger::class)->summary();
        $voucher = $this->reconcile($this->data($vendor, $entry, ['related_voucher_id' => $topup->id]));
        $this->assertSame('expense', $voucher->type);
        $this->assertSame($entry->id, (int) $voucher->business_entry_id);
        $this->assertFalse($voucher->owns_business_entry);
        $this->assertSame($topup->id, (int) $voucher->related_voucher_id);
        $this->assertSame(['irt' => 900000, 'native' => 9000], app(DocumentedAccounting::class)->balance($vendor));
        $this->assertSame($before['expense'], app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame($before['revenue'], app(BusinessLedger::class)->summary()['revenue']);
        $this->assertDatabaseHas('finance_lines', ['voucher_id' => $voucher->id, 'code' => 'vendor_prepaid',
            'account_id' => $vendor->id, 'credit_irt' => 100000, 'native_credit' => 1000]);
        $this->assertEquals(FinanceLine::sum('debit_irt'), FinanceLine::sum('credit_irt'));
        $document = FinanceDocument::where('voucher_id', $voucher->id)->sole();
        $this->assertTrue(Storage::disk('local')->exists($document->path));
        $this->assertSame(hash('sha256', Storage::disk('local')->get($document->path)), $document->sha256);
        $event = DB::table('finance_audit_events')->where('subject_id', $voucher->id)->where('action', 'supplier_credit_reconciled')->sole();
        $details = json_decode($event->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($entry->id, $details['business_entry_id']);
        $this->assertSame($entry->source_type, $details['source_type']);
        $this->assertSame((int) $entry->source_id, (int) $details['source_id']);
        $this->assertSame('SUPPLIER-INVOICE-12', $details['supplier_invoice_number']);
        $this->assertSame($document->sha256, $details['document_sha256']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_actual_cost_uses_historical_weighted_pool_and_posts_only_difference_from_estimate(): void
    {
        $vendor = $this->account();
        $this->topup($vendor, 1000, 100000);
        $this->topup($vendor, 2000, 400000, '2026-10-21');
        $entry = $this->cost(200000);
        $voucher = $this->reconcile($this->data($vendor, $entry));
        $this->assertSame(166666, (int) $voucher->amount_irt);
        $this->assertSame('cost_adjustment', $voucher->type);
        $this->assertSame($entry->id, (int) $voucher->adjusts_entry_id);
        $this->assertSame(-33334, (int) $voucher->book_delta_irt);
        $this->assertSame(200000, (int) $entry->fresh()->amount, 'Original automatic estimate stays immutable.');
        $this->assertSame(166666, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame(['irt' => 333334, 'native' => 2000], app(DocumentedAccounting::class)->balance($vendor));
        $this->assertSame(0, (int) $voucher->fx_gain_irt);
        $this->assertDatabaseCount('finance_documents', 1);
    }

    public function test_replay_retains_original_weighted_amount_snapshot_and_private_receipt(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $d = $this->data($vendor, $this->cost());
        $first = $this->reconcile($d);
        $document = FinanceDocument::where('voucher_id', $first->id)->sole();
        $this->topup($vendor, 10000, 2000000, '2026-10-26');
        $vendor->update(['name' => 'Synthetic renamed provider']);
        $before = $this->snapshot();
        $again = $this->reconcile($d, $this->proof());
        $this->assertSame($first->id, $again->id);
        $this->assertSame(100000, (int) $again->amount_irt);
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue(Storage::disk('local')->exists($document->path));
        $event = DB::table('finance_audit_events')->where('action', 'supplier_credit_reconciled')->where('subject_id', $first->id)->sole();
        $details = json_decode($event->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Synthetic vendor USD', $details['funding_account_snapshot']['name']);
        DB::table('finance_controls')->where('id', 1)->update(['locked_through' => '2026-10-25']);
        $this->assertSame($first->id, $this->reconcile($d)->id, 'A receipt-only replay does not post into the now-closed period.');
    }

    public function test_same_key_with_different_receipt_or_source_does_not_silently_replay(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $d = $this->data($vendor, $this->cost());
        $this->reconcile($d);
        $this->rejected($d, 'duplicate_mismatch', $this->proof('different'));
        $this->rejected(array_replace($d, ['business_entry_id' => $this->cost()->id]), 'duplicate_mismatch');
    }

    public function test_a_different_key_cannot_document_same_external_debit_or_same_automatic_cost_twice(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $entry = $this->cost();
        $this->reconcile($this->data($vendor, $entry));
        $this->rejected($this->data($vendor, $this->cost(), ['bank_reference' => 'supplier-debit-1001']), 'supplier_reference_used');
        $this->rejected($this->data($vendor, $entry, ['bank_reference' => 'SUPPLIER-DEBIT-2002']), 'already_documented');
    }

    public function test_reference_is_scoped_to_actual_supplier_account_and_long_reference_is_lossless(): void
    {
        $one = $this->account();
        $two = $this->account();
        $this->topup($one);
        $this->topup($two);
        $reference = str_repeat('A', 150);
        $first = $this->reconcile($this->data($one, $this->cost(), ['bank_reference' => $reference]));
        $second = $this->reconcile($this->data($two, $this->cost(), ['bank_reference' => $reference]));
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($reference, $first->bank_reference);
        $this->assertLessThanOrEqual(150, strlen($first->invoice_number));
    }

    public function test_reconciliation_requires_explicit_vendor_currency_real_source_and_matching_date(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $entry = $this->cost();
        $this->rejected($this->data($this->account('partner'), $entry), 'supplier_review_required');
        $this->rejected($this->data($vendor, $entry, ['original_currency' => 'EUR']), 'supplier_review_required');
        $this->rejected($this->data($vendor, $entry, ['occurred_at' => '2026-10-24']), 'supplier_review_required');
        $this->rejected($this->data($vendor, $entry, ['source_type' => $entry->source_type, 'source_id' => $entry->source_id + 100]), 'supplier_review_required');
        $entry->update(['source_type' => null, 'source_id' => null]);
        $this->rejected($this->data($vendor, $entry), 'supplier_review_required');
        $entry->update(['kind' => 'revenue']);
        $this->rejected($this->data($vendor, $entry), 'supplier_review_required');
    }

    public function test_insufficient_or_later_funded_credit_and_closed_period_require_review_without_side_effects(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $entry = $this->cost();
        $this->rejected($this->data($vendor, $entry, ['original_minor' => 10001]), 'insufficient_account');
        $this->rejected($this->data($vendor, $this->cost(100000, '2026-10-19')), 'supplier_review_required');
        DB::table('finance_controls')->where('id', 1)->update(['locked_through' => '2026-10-25']);
        $this->rejected($this->data($vendor, $entry), 'period_locked');
        DB::table('finance_controls')->where('id', 1)->update(['locked_through' => null]);
        $this->rejected($this->data($vendor, $entry, ['occurred_at' => '2026-11-01']), 'future_date');
    }

    public function test_related_topup_cannot_belong_to_another_vendor_be_future_or_be_reversed(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $entry = $this->cost();
        $other = $this->topup($this->account());
        $this->rejected($this->data($vendor, $entry, ['related_voucher_id' => $other->id]), 'supplier_review_required');
        $later = $this->topup($vendor, 1000, 100000, '2026-10-26');
        $this->rejected($this->data($vendor, $entry, ['related_voucher_id' => $later->id]), 'supplier_review_required');
        app(DocumentedAccounting::class)->reverse($later, '2026-10-26', 'Synthetic wrong funding evidence', $this->admin->id);
        $this->rejected($this->data($vendor, $entry, ['related_voucher_id' => $later->id]), 'supplier_review_required');
    }

    public function test_invalid_receipt_or_ambiguous_unverified_reference_has_no_accounting_effect(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $d = $this->data($vendor, $this->cost());
        $fake = UploadedFile::fake()->createWithContent('not-proof.pdf', '<html>not a PDF</html>');
        // Laravel's fake advertises MIME from the filename; use a real uploaded file to exercise content sniffing.
        $hostile = new UploadedFile($fake->getRealPath(), 'not-proof.pdf', null, null, true);
        $this->assertNotContains($hostile->getMimeType(), ['application/pdf', 'image/jpeg', 'image/png']);
        $this->rejected($d, 'supplier_receipt_invalid', $hostile);
        $this->rejected($d, 'supplier_receipt_invalid', UploadedFile::fake()->create('too-large.pdf', 10241, 'application/pdf'));
        $this->rejected(array_replace($d, ['bank_reference' => 'not a valid <reference>']), 'supplier_review_required');
    }

    public function test_receipt_write_failure_rolls_back_cost_adjustment_balance_and_audit(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $d = $this->data($vendor, $this->cost(120000));
        $before = $this->snapshot();
        $dispatcher = FinanceDocument::getEventDispatcher();
        FinanceDocument::setEventDispatcher(clone $dispatcher);
        FinanceDocument::creating(function () {
            throw new \RuntimeException('Synthetic document persistence failure');
        });
        try {
            $this->reconcile($d);
            $this->fail('Document failure should roll back the complete financial transaction.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic document persistence failure', $e->getMessage());
        } finally {
            FinanceDocument::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(['irt' => 1000000, 'native' => 10000], app(DocumentedAccounting::class)->balance($vendor));
    }

    public function test_integer_native_precision_is_preserved_for_try_and_irr(): void
    {
        foreach ([['TRY', 10001, 1000100, 1, 100], ['IRR', 100010, 10001, 10, 1]] as [$currency, $native, $irt, $spend, $cost]) {
            $vendor = $this->account('vendor', $currency);
            $this->topup($vendor, $native, $irt);
            $entry = $this->cost($cost);
            $voucher = $this->reconcile($this->data($vendor, $entry, ['original_minor' => $spend]));
            $this->assertSame($spend, (int) $voucher->original_minor);
            $this->assertSame($cost, (int) $voucher->amount_irt);
            $this->assertSame(['irt' => $irt - $cost, 'native' => $native - $spend], app(DocumentedAccounting::class)->balance($vendor));
        }
    }

    public function test_period_identified_auto_cost_can_be_documented_without_guessing_customer_invoice(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $entry = $this->cost();
        $entry->update(['source_type' => null, 'source_id' => null, 'period' => '2026-10', 'ref_id' => 71, 'category' => 'server']);
        $voucher = $this->reconcile($this->data($vendor, $entry, ['service_line' => 'shared_hosting']));
        $event = DB::table('finance_audit_events')->where('subject_id', $voucher->id)->where('action', 'supplier_credit_reconciled')->sole();
        $details = json_decode($event->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertNull($details['source_type']);
        $this->assertSame('2026-10', $details['period']);
        $this->assertSame(71, (int) $details['ref_id']);
        $this->assertSame($entry->id, (int) $voucher->business_entry_id);
    }

    public function test_staff_cannot_reconcile_supplier_spend(): void
    {
        $staff = User::create(['name' => 'Synthetic staff', 'email' => 'supplier-staff@example.test', 'role' => 'staff', 'password' => bcrypt('test')]);
        $vendor = $this->account();
        $this->topup($vendor);
        $d = $this->data($vendor, $this->cost());
        $before = $this->snapshot();
        try {
            app(SupplierCreditReconciliation::class)->reconcile($d, $staff->id, $this->proof());
            $this->fail('Only an administrator can record supplier debit evidence.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_full_native_closure_consumes_the_recorded_rounding_remainder(): void
    {
        $vendor = $this->account();
        $this->topup($vendor, 3, 101);
        $one = $this->reconcile($this->data($vendor, $this->cost(40), ['original_minor' => 1]));
        $this->assertSame(33, (int) $one->amount_irt);
        $this->assertSame(['irt' => 68, 'native' => 2], app(DocumentedAccounting::class)->balance($vendor));
        $last = $this->reconcile($this->data($vendor, $this->cost(70), [
            'original_minor' => 2, 'bank_reference' => 'SUPPLIER-DEBIT-FINAL',
        ]));
        $this->assertSame(68, (int) $last->amount_irt);
        $this->assertSame(['irt' => 0, 'native' => 0], app(DocumentedAccounting::class)->balance($vendor));
        $this->assertSame(101, app(BusinessLedger::class)->summary()['expense']);
    }

    public function test_a_debit_below_one_toman_requires_review_without_losing_prepaid_native_units(): void
    {
        $vendor = $this->account();
        $this->topup($vendor, 10000, 1);
        $this->rejected($this->data($vendor, $this->cost(1), ['original_minor' => 1]), 'carrying_cost');
        $this->assertSame(['irt' => 1, 'native' => 10000], app(DocumentedAccounting::class)->balance($vendor));
    }

    public function test_reversing_a_voucher_does_not_authorize_reusing_the_confirmed_supplier_debit(): void
    {
        $vendor = $this->account();
        $this->topup($vendor);
        $voucher = $this->reconcile($this->data($vendor, $this->cost()));
        app(DocumentedAccounting::class)->reverse($voucher, '2026-10-26', 'Synthetic accounting correction', $this->admin->id);
        $this->rejected($this->data($vendor, $this->cost()), 'supplier_reference_used');
    }


    public function test_a_backdated_debit_after_later_consumption_and_replenishment_requires_ordered_review(): void
    {
        $vendor = $this->account();
        $this->topup($vendor, 1000, 1000, '2026-10-20');
        $later = $this->cost(500, '2026-10-26');
        $this->reconcile($this->data($vendor, $later, [
            'original_minor' => 500, 'bank_reference' => 'SUPPLIER-LATER-CONSUMPTION',
        ]));
        $this->topup($vendor, 500, 1500, '2026-10-27');
        $this->assertSame(['irt' => 2000, 'native' => 1000], app(DocumentedAccounting::class)->balance($vendor));

        // At the earlier date these 1,000 units carried only 1,000 IRT.
        // Applying that old value now would exhaust native units while leaving a fictitious 1,000 IRT asset.
        $earlier = $this->cost(1000, '2026-10-25');
        $this->rejected($this->data($vendor, $earlier, [
            'original_minor' => 1000, 'bank_reference' => 'SUPPLIER-BACKDATED-CONSUMPTION',
        ]), 'supplier_review_required');
        $this->assertSame(['irt' => 2000, 'native' => 1000], app(DocumentedAccounting::class)->balance($vendor));
    }

}
