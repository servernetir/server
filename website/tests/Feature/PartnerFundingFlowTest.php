<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceLine;
use App\Models\FinanceVoucher;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\DocumentedAccounting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PartnerFundingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 08:00:00', 'UTC'));
        Http::fake();
        Mail::fake();
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

    private function account(string $type, string $currency, ?string $partnerKey = 'SYNTHETIC_PARTNER', string $owner = 'Synthetic Partner'): FinanceAccount
    {
        return FinanceAccount::create(['name' => 'Synthetic '.$type.' '.$currency, 'type' => $type,
            'currency_code' => $currency, 'owner_name' => $owner,
            'partner_key' => $type === 'partner' ? $partnerKey : null, 'created_by' => 1]);
    }

    private function data(FinanceAccount $funding, array $override = []): array
    {
        return array_replace(['idempotency_key' => (string) Str::uuid(), 'type' => 'expense',
            'occurred_at' => '2026-10-08', 'service_line' => 'domain', 'category' => 'domain_wholesale',
            'vendor' => 'Synthetic Supplier', 'invoice_number' => (string) Str::uuid(),
            'bank_reference' => 'SYNTHETIC-'.Str::uuid(), 'fx_basis' => 'Documented synthetic conversion',
            'note' => 'Synthetic business transaction', 'amount_irt' => 1001, 'original_currency' => 'TRY',
            'original_minor' => 3, 'settlement_currency' => $funding->currency_code, 'settlement_minor' => 3,
            'funding_account_id' => $funding->id, 'target_account_id' => null, 'funding_mode' => 'personal',
            'related_voucher_id' => null, 'tax_status' => 'pending', 'tax_reference' => null,
            'input_vat_irt' => 0, 'vat_status' => 'none'], $override);
    }

    private function post(FinanceAccount $funding, array $override = []): FinanceVoucher
    {
        return $this->journal()->post($this->data($funding, $override), 1);
    }

    private function bank(int $amount = 2000, string $currency = 'IRR'): FinanceAccount
    {
        $bank = $this->account('bank', $currency, null, 'Synthetic Company');
        $this->post($bank, ['type' => 'opening_balance', 'occurred_at' => '2026-10-01',
            'amount_irt' => $amount, 'original_currency' => $currency,
            'original_minor' => $currency === 'IRR' ? $amount * 10 : $amount,
            'funding_account_id' => null, 'target_account_id' => $bank->id,
            'settlement_currency' => null, 'settlement_minor' => 0]);

        return $bank;
    }

    private function advance(FinanceAccount $bank, FinanceAccount $partner, int $amount = 1001, string $date = '2026-10-05'): FinanceVoucher
    {
        return $this->post($bank, ['type' => 'partner_advance', 'occurred_at' => $date,
            'amount_irt' => $amount, 'original_currency' => $partner->currency_code,
            'original_minor' => $partner->currency_code === 'IRR' ? $amount * 10 : $amount,
            'settlement_minor' => $bank->currency_code === 'IRR' ? $amount * 10 : $amount,
            'target_account_id' => $partner->id]);
    }

    private function transferData(FinanceAccount $source, FinanceAccount $target, FinanceVoucher $advance, array $override = []): array
    {
        return $this->data($source, array_replace(['type' => 'partner_advance_transfer',
            'occurred_at' => '2026-10-06', 'funding_mode' => 'advance',
            'related_voucher_id' => $advance->id, 'target_account_id' => $target->id,
            'amount_irt' => 1001, 'original_currency' => $target->currency_code, 'original_minor' => 3,
            'settlement_minor' => 10010], $override));
    }

    private function chain(): array
    {
        $bank = $this->bank();
        $domestic = $this->account('partner', 'IRR', 'SYNTHETIC_PARTNER', 'Synthetic Partner');
        $foreign = $this->account('partner', 'TRY', 'SYNTHETIC_PARTNER', 'شریک آزمایشی');
        $advance = $this->advance($bank, $domestic);
        $transfer = $this->journal()->post($this->transferData($domestic, $foreign, $advance), 1);

        return compact('bank', 'domestic', 'foreign', 'advance', 'transfer');
    }

    private function rejectedWithoutPosting(array $payload): void
    {
        $before = [FinanceVoucher::count(), FinanceLine::count(), BusinessEntry::count(), DB::table('finance_audit_events')->count()];
        try {
            $this->journal()->post($payload, 1);
            $this->fail('An invalid business funding transaction was accepted');
        } catch (ValidationException) {
            $this->assertSame($before, [FinanceVoucher::count(), FinanceLine::count(), BusinessEntry::count(), DB::table('finance_audit_events')->count()]);
        }
    }

    private function assertBalanced(): void
    {
        foreach (FinanceVoucher::all() as $voucher) {
            $lines = FinanceLine::where('voucher_id', $voucher->id)->get();
            $this->assertSame((int) $lines->sum('debit_irt'), (int) $lines->sum('credit_irt'));
        }
    }

    public function test_company_advance_moves_between_partner_currencies_then_only_supplier_consumption_is_expense(): void
    {
        $c = $this->chain();
        $vendor = $this->account('vendor', 'TRY');
        $topup = $this->post($c['foreign'], ['type' => 'vendor_topup', 'target_account_id' => $vendor->id,
            'funding_mode' => 'advance', 'related_voucher_id' => $c['transfer']->id]);
        $this->assertSame(['irt' => 999, 'native' => 9990], $this->journal()->balance($c['bank']));
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($c['domestic']));
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($c['foreign']));
        $this->assertSame(['irt' => 1001, 'native' => 3], $this->journal()->balance($vendor));
        $this->assertSame(0, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame(0, app(BusinessLedger::class)->summary()['revenue']);

        $this->post($vendor, ['amount_irt' => 333, 'original_minor' => 1, 'settlement_minor' => 1,
            'related_voucher_id' => $topup->id]);
        $this->post($vendor, ['amount_irt' => 668, 'original_minor' => 2, 'settlement_minor' => 2,
            'related_voucher_id' => $topup->id]);
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($vendor));
        $this->assertSame(1001, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame(0, app(BusinessLedger::class)->summary()['revenue']);
        $this->assertSame($c['advance']->id, (int) $c['transfer']->related_voucher_id);
        $this->assertSame($c['transfer']->id, (int) $topup->related_voucher_id);
        $this->assertBalanced();
    }

    public function test_advance_consumption_uses_historical_cost_closes_rounding_residual_and_separates_fx(): void
    {
        $c = $this->chain();
        $vendor = $this->account('vendor', 'TRY');
        $first = $this->post($c['foreign'], ['type' => 'vendor_topup', 'target_account_id' => $vendor->id,
            'amount_irt' => 400, 'original_minor' => 1, 'settlement_minor' => 1,
            'funding_mode' => 'advance', 'related_voucher_id' => $c['transfer']->id]);
        $this->assertSame(['irt' => -668, 'native' => -2], $this->journal()->balance($c['foreign']));
        $this->assertSame(67, (int) $first->fx_gain_irt);
        $last = $this->post($c['foreign'], ['type' => 'vendor_topup', 'target_account_id' => $vendor->id,
            'amount_irt' => 800, 'original_minor' => 2, 'settlement_minor' => 2,
            'funding_mode' => 'advance', 'related_voucher_id' => $c['transfer']->id]);
        $this->assertSame(132, (int) $last->fx_gain_irt);
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($c['foreign']));
        $this->assertSame(['irt' => 1200, 'native' => 3], $this->journal()->balance($vendor));
        $summary = app(BusinessLedger::class)->summary();
        $this->assertSame(0, $summary['expense']);
        $this->assertSame(199, $summary['other_income']);
        $this->assertSame(0, $summary['revenue']);
        $this->post($vendor, ['amount_irt' => 1200]);
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($vendor));
        $this->assertSame(1200, app(BusinessLedger::class)->summary()['expense']);
        $this->assertBalanced();
    }

    public function test_legacy_personal_supplier_funding_without_a_related_voucher_still_creates_a_real_partner_claim(): void
    {
        $partner = $this->account('partner', 'TRY');
        $vendor = $this->account('vendor', 'TRY');
        $data = $this->data($partner, ['type' => 'vendor_topup', 'target_account_id' => $vendor->id]);
        unset($data['funding_mode'], $data['related_voucher_id']);
        $this->journal()->post($data, 1);
        $this->assertSame(['irt' => 1001, 'native' => 3], $this->journal()->balance($partner));
        $this->assertSame(['irt' => 1001, 'native' => 3], $this->journal()->balance($vendor));
        $this->assertSame(0, app(BusinessLedger::class)->summary()['expense']);
        $this->post($vendor);
        $this->assertSame(1001, app(BusinessLedger::class)->summary()['expense']);
        $this->assertBalanced();
    }

    public function test_personal_mode_cannot_ignore_an_existing_business_advance(): void
    {
        $bank = $this->bank();
        $partner = $this->account('partner', 'IRR');
        $this->advance($bank, $partner);
        $this->rejectedWithoutPosting($this->data($partner, ['amount_irt' => 100,
            'original_currency' => 'IRT', 'original_minor' => 100, 'settlement_minor' => 1000]));
        $this->assertSame(['irt' => -1001, 'native' => -10010], $this->journal()->balance($partner));
        $this->assertSame(['irt' => 999, 'native' => 9990], $this->journal()->balance($bank));
    }

    public function test_private_currency_conversion_without_a_documented_company_advance_is_rejected(): void
    {
        $domestic = $this->account('partner', 'IRR');
        $foreign = $this->account('partner', 'TRY');
        $this->rejectedWithoutPosting($this->data($domestic, ['type' => 'partner_advance_transfer',
            'funding_mode' => 'advance', 'target_account_id' => $foreign->id,
            'settlement_minor' => 10010]));
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($domestic));
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($foreign));
    }

    public function test_advance_transfer_requires_the_same_explicit_partner_identity(): void
    {
        $bank = $this->bank();
        $domestic = $this->account('partner', 'IRR');
        $advance = $this->advance($bank, $domestic);
        foreach (['OTHER_SYNTHETIC_PARTNER', null] as $key) {
            $target = $this->account('partner', 'TRY', $key);
            $this->rejectedWithoutPosting($this->transferData($domestic, $target, $advance));
        }
        $this->assertSame(['irt' => -1001, 'native' => -10010], $this->journal()->balance($domestic));
    }

    public function test_advance_related_voucher_must_have_the_correct_source_account_and_business_purpose(): void
    {
        $bank = $this->bank(4000);
        $source = $this->account('partner', 'IRR');
        $other = $this->account('partner', 'IRR');
        $target = $this->account('partner', 'TRY');
        $advance = $this->advance($bank, $source);
        $wrongAccount = $this->advance($bank, $other);
        $wrongPurpose = FinanceVoucher::where('type', 'opening_balance')->firstOrFail();
        foreach ([$wrongAccount, $wrongPurpose] as $related) {
            $this->rejectedWithoutPosting($this->transferData($source, $target, $related));
        }
        $this->rejectedWithoutPosting($this->transferData($source, $target, $advance, ['related_voucher_id' => null]));
        $this->rejectedWithoutPosting($this->transferData($source, $target, $advance, ['funding_mode' => 'personal']));
        $this->rejectedWithoutPosting($this->data($source, ['type' => 'vendor_topup',
            'target_account_id' => $this->account('vendor', 'TRY')->id, 'funding_mode' => 'advance',
            'related_voucher_id' => $wrongAccount->id, 'settlement_minor' => 10010]));
        $this->assertSame(['irt' => -1001, 'native' => -10010], $this->journal()->balance($source));
        $this->assertSame('partner_advance', $advance->type);
    }

    public function test_a_partner_opening_claim_is_not_a_company_advance_source_even_after_it_is_repaid(): void
    {
        $bank = $this->bank(4000);
        $source = $this->account('partner', 'IRR');
        $target = $this->account('partner', 'TRY');
        $opening = $this->post($bank, ['type' => 'opening_balance', 'occurred_at' => '2026-10-02',
            'amount_irt' => 100, 'original_currency' => 'IRR', 'original_minor' => 1000,
            'funding_account_id' => null, 'target_account_id' => $source->id,
            'settlement_currency' => null, 'settlement_minor' => 0]);
        $this->post($bank, ['type' => 'partner_repayment', 'occurred_at' => '2026-10-03',
            'target_account_id' => $source->id, 'amount_irt' => 100,
            'original_currency' => 'IRR', 'original_minor' => 1000, 'settlement_minor' => 1000]);
        $this->advance($bank, $source);
        $this->rejectedWithoutPosting($this->transferData($source, $target, $opening));
        $this->assertSame(['irt' => -1001, 'native' => -10010], $this->journal()->balance($source));
    }

    public function test_future_or_reversed_advance_cannot_be_used_as_the_source_link(): void
    {
        $bank = $this->bank(4000);
        $source = $this->account('partner', 'IRR');
        $target = $this->account('partner', 'TRY');
        $this->advance($bank, $source, 1001, '2026-10-05');
        $advance = $this->advance($bank, $source, 1001, '2026-10-07');
        $this->rejectedWithoutPosting($this->transferData($source, $target, $advance));
        $this->journal()->reverse($advance, '2026-10-08', 'Synthetic unused advance returned', 1);
        $this->rejectedWithoutPosting($this->transferData($source, $target, $advance,
            ['occurred_at' => '2026-10-10']));
    }

    public function test_idempotent_transfer_retry_does_not_repeat_debits_and_changed_replay_is_rejected(): void
    {
        $bank = $this->bank();
        $source = $this->account('partner', 'IRR');
        $target = $this->account('partner', 'TRY');
        $advance = $this->advance($bank, $source);
        $payload = $this->transferData($source, $target, $advance);
        $first = $this->journal()->post($payload, 1);
        $counts = [FinanceVoucher::count(), FinanceLine::count(), BusinessEntry::count()];
        $retry = $this->journal()->post($payload, 1);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame($counts, [FinanceVoucher::count(), FinanceLine::count(), BusinessEntry::count()]);
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($source));
        $this->assertSame(['irt' => -1001, 'native' => -3], $this->journal()->balance($target));
        $this->rejectedWithoutPosting(array_replace($payload, ['original_minor' => 4]));
    }

    public function test_advance_cannot_be_consumed_beyond_its_native_amount_or_again_by_backdating(): void
    {
        $bank = $this->bank();
        $source = $this->account('partner', 'IRR');
        $target = $this->account('partner', 'TRY');
        $advance = $this->advance($bank, $source);
        $this->rejectedWithoutPosting($this->transferData($source, $target, $advance,
            ['amount_irt' => 1002, 'settlement_minor' => 10020]));
        $this->journal()->post($this->transferData($source, $target, $advance,
            ['occurred_at' => '2026-10-10']), 1);
        $this->rejectedWithoutPosting($this->data($source, ['occurred_at' => '2026-10-09',
            'funding_mode' => 'advance', 'related_voucher_id' => $advance->id,
            'amount_irt' => 100, 'original_currency' => 'IRT', 'original_minor' => 100, 'settlement_minor' => 1000]));
        $this->rejectedWithoutPosting($this->data($source, ['occurred_at' => '2026-10-09',
            'funding_mode' => 'personal', 'amount_irt' => 100, 'original_currency' => 'IRT',
            'original_minor' => 100, 'settlement_minor' => 1000]));
    }

    public function test_company_advance_cannot_exceed_the_documented_company_bank_balance(): void
    {
        $bank = $this->bank();
        $target = $this->account('partner', 'IRR');
        $this->rejectedWithoutPosting($this->data($bank, ['type' => 'partner_advance',
            'target_account_id' => $target->id, 'amount_irt' => 2001,
            'original_currency' => 'IRR', 'original_minor' => 20010, 'settlement_minor' => 20010]));
        $this->assertSame(['irt' => 2000, 'native' => 20000], $this->journal()->balance($bank));
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($target));
    }

    public function test_closed_accounting_period_applies_to_partner_advance_transfers(): void
    {
        $bank = $this->bank();
        $source = $this->account('partner', 'IRR');
        $target = $this->account('partner', 'TRY');
        $advance = $this->advance($bank, $source);
        $this->journal()->closeThrough('2026-10-06', 'Synthetic closed accounting period', 1);
        $this->rejectedWithoutPosting($this->transferData($source, $target, $advance));
    }

    public function test_a_new_advance_does_not_replace_repayment_or_mix_with_an_existing_payable(): void
    {
        $bank = $this->bank();
        $partner = $this->account('partner', 'IRR');
        $this->rejectedWithoutPosting($this->data($bank, ['type' => 'partner_repayment',
            'target_account_id' => $partner->id, 'amount_irt' => 100,
            'original_currency' => 'IRR', 'original_minor' => 1000, 'settlement_minor' => 1000]));
        $this->post($partner, ['amount_irt' => 100, 'original_currency' => 'IRT',
            'original_minor' => 100, 'settlement_minor' => 1000]);
        $this->rejectedWithoutPosting($this->data($bank, ['type' => 'partner_advance',
            'target_account_id' => $partner->id, 'amount_irt' => 100,
            'original_currency' => 'IRR', 'original_minor' => 1000, 'settlement_minor' => 1000]));
        $this->rejectedWithoutPosting($this->data($bank, ['type' => 'partner_repayment',
            'target_account_id' => $partner->id, 'amount_irt' => 101,
            'original_currency' => 'IRR', 'original_minor' => 1010, 'settlement_minor' => 1010]));
        $this->assertSame(['irt' => 100, 'native' => 1000], $this->journal()->balance($partner));
        $this->assertSame(['irt' => 2000, 'native' => 20000], $this->journal()->balance($bank));
    }

    public function test_advance_transfer_does_not_offset_a_different_existing_payable(): void
    {
        $bank = $this->bank();
        $source = $this->account('partner', 'IRR');
        $target = $this->account('partner', 'TRY');
        $advance = $this->advance($bank, $source);
        $this->post($target, ['amount_irt' => 100, 'original_minor' => 1, 'settlement_minor' => 1]);
        $this->rejectedWithoutPosting($this->transferData($source, $target, $advance));
        $this->assertSame(['irt' => 100, 'native' => 1], $this->journal()->balance($target));
    }

    public function test_irr_original_and_settlement_amounts_require_exact_toman_conversion(): void
    {
        $bank = $this->bank();
        $this->rejectedWithoutPosting($this->data($bank, ['amount_irt' => 100,
            'original_currency' => 'IRR', 'original_minor' => 999, 'settlement_minor' => 1000]));
        $this->rejectedWithoutPosting($this->data($bank, ['amount_irt' => 100,
            'original_currency' => 'IRT', 'original_minor' => 100, 'settlement_minor' => 999]));
        $this->assertSame(['irt' => 2000, 'native' => 20000], $this->journal()->balance($bank));
    }

    public function test_documenting_an_existing_supplier_cost_consumes_prepaid_without_a_second_expense(): void
    {
        $c = $this->chain();
        $vendor = $this->account('vendor', 'TRY');
        $topup = $this->post($c['foreign'], ['type' => 'vendor_topup', 'target_account_id' => $vendor->id,
            'funding_mode' => 'advance', 'related_voucher_id' => $c['transfer']->id]);
        $existing = BusinessEntry::create(['currency_code' => 'IRT', 'direction' => 'out',
            'kind' => 'expense', 'category' => 'domain_wholesale', 'amount' => 1001,
            'occurred_at' => '2026-10-08']);
        $payload = $this->data($vendor, ['business_entry_id' => $existing->id, 'related_voucher_id' => $topup->id]);
        $voucher = $this->journal()->post($payload, 1);
        $this->journal()->post($payload, 1);
        $this->assertFalse($voucher->owns_business_entry);
        $this->assertSame($existing->id, (int) $voucher->business_entry_id);
        $this->assertSame(1001, app(BusinessLedger::class)->summary()['expense']);
        $this->assertSame(['irt' => 0, 'native' => 0], $this->journal()->balance($vendor));
        $this->rejectedWithoutPosting(array_replace($payload, ['idempotency_key' => (string) Str::uuid()]));
    }

    public function test_posted_funding_identity_is_frozen_when_accounts_are_renamed(): void
    {
        $bank = $this->bank();
        $source = $this->account('partner', 'IRR');
        $target = $this->account('partner', 'TRY');
        $advance = $this->advance($bank, $source);
        $payload = $this->transferData($source, $target, $advance);
        $voucher = $this->journal()->post($payload, 1);
        $event = DB::table('finance_audit_events')->where('subject_id', $voucher->id)->where('action', 'voucher_posted')->first();
        $before = json_decode($event->details, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('advance', $before['funding_mode']);
        $this->assertSame($advance->id, $before['related_voucher_id']);
        $this->assertSame($source->id, $before['funding_account_snapshot']['id']);
        $this->assertSame($target->id, $before['target_account_snapshot']['id']);
        $this->assertSame($source->name, $before['funding_account_snapshot']['name']);
        $this->assertSame($target->owner_name, $before['target_account_snapshot']['owner_name']);
        $source->update(['name' => 'Renamed synthetic source', 'owner_name' => 'Renamed synthetic owner']);
        $target->update(['name' => 'Renamed synthetic target']);
        $this->journal()->post($payload, 1);
        $this->assertSame($before, json_decode(DB::table('finance_audit_events')->where('id', $event->id)->value('details'), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('finance_audit_events')->where('subject_id', $voucher->id)->where('action', 'voucher_posted')->count());
    }
}
