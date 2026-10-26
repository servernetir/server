<?php

namespace App\Services\Finance;

use App\Models\BusinessEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceAsset;
use App\Models\FinanceDocument;
use App\Models\FinanceLine;
use App\Models\FinanceVoucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Supplemental double entry journal. No historical backfill or fabricated opening balances. */
final class DocumentedAccounting
{
    public const SERVICES = ['shared_hosting', 'reseller_hosting', 'cloud_vps', 'dedicated', 'gpu', 'domain', 'ai', 'identity_api', 'storage', 'sms', 'network', 'email_hosting', 'transactional_email', 'physical_sales', 'remote_access', 'automation', 'bpmn_erp', 'managed_services', 'seo_services', 'web_design', 'cloud_phone', 'other'];

    public const TYPES = ['expense', 'cost_adjustment', 'vendor_topup', 'asset_purchase', 'partner_funding', 'partner_repayment', 'opening_balance', 'vat_payment', 'income_tax_payment', 'bank_collection'];

    public function ready(): bool
    {
        return Schema::hasTable('finance_vouchers') && Schema::hasTable('finance_controls');
    }

    private function check(bool $condition, string $key = 'invalid_voucher'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['accounting' => __('finance.'.$key)]);
        }
    }

    private function guardDate(string $date): void
    {
        $control = DB::table('finance_controls')->where('id', 1)->lockForUpdate()->first();
        $this->check($control !== null && (! $control->locked_through || $date > $control->locked_through), 'period_locked');
        $this->check($date <= now('Asia/Tehran')->toDateString(), 'future_date');
    }

    public function audit(string $action, int $actor, ?int $subject, array $details = []): void
    {
        DB::table('finance_audit_events')->insert(['action' => $action, 'actor_id' => $actor, 'subject_id' => $subject,
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function post(array $d, int $actor): FinanceVoucher
    {
        $this->check($this->ready(), 'not_ready');
        $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($d, $actor, $hash) {
            $this->guardDate($d['occurred_at']);
            $existing = FinanceVoucher::where('idempotency_key', $d['idempotency_key'])->first();
            if ($existing) {
                $this->check(hash_equals($existing->payload_hash, $hash), 'duplicate_mismatch');

                return $existing;
            }
            $this->check(isset(AccountingMoney::EXPONENTS[$d['original_currency']]));
            $type = $d['type'];
            $this->check(in_array($d['tax_status'] ?? 'pending', ['pending', 'rejected'], true), 'cost_proof');
            $gross = (int) $d['amount_irt'];
            $this->check(in_array($type, self::TYPES, true) && $gross > 0 && $gross <= AccountingMoney::MAX);
            $this->check(in_array($d['service_line'], self::SERVICES, true));
            $original = (int) $d['original_minor'];
            $native = (int) ($d['settlement_minor'] ?? 0);
            $this->check($original > 0 && $original <= AccountingMoney::MAX && $native >= 0 && $native <= AccountingMoney::MAX);
            $funding = empty($d['funding_account_id']) ? null : FinanceAccount::whereKey($d['funding_account_id'])->lockForUpdate()->first();
            $target = empty($d['target_account_id']) ? null : FinanceAccount::whereKey($d['target_account_id'])->lockForUpdate()->first();
            if (! in_array($type, ['opening_balance', 'bank_collection'], true)) {
                $this->check($funding !== null && $native > 0 && $d['settlement_currency'] === $funding->currency_code);
                $this->check(in_array($funding->type, ['bank', 'partner', 'vendor'], true));
            }
            if (! in_array($type, ['vendor_topup', 'partner_funding', 'partner_repayment', 'opening_balance', 'bank_collection'], true)) {
                $this->check($target === null);
            }
            if (in_array($type, ['opening_balance', 'bank_collection'], true)) {
                $this->check($funding === null && $native === 0);
            }
            if ($d['original_currency'] === 'IRT') {
                $this->check($original === $gross);
            }
            if (($d['settlement_currency'] ?? null) === 'IRT' && ! in_array($type, ['opening_balance', 'bank_collection'], true)) {
                $this->check($native === $gross);
            }
            $inputVat = (int) ($d['input_vat_irt'] ?? 0);
            $this->check($inputVat >= 0 && $inputVat < $gross);
            $this->check($inputVat === 0 || (in_array($type, ['expense', 'cost_adjustment', 'asset_purchase'], true)
                && ($d['vat_status'] ?? 'none') === 'eligible' && $d['original_currency'] === 'IRT'
                && ! empty($d['tax_reference']) && ! empty($d['invoice_number']) && ! empty($d['vendor'])), 'vat_proof');
            $net = $gross - $inputVat;
            if (in_array($type, ['vendor_topup', 'partner_funding', 'partner_repayment', 'opening_balance'], true)) {
                $this->check($target !== null && (! $funding || $target->id !== $funding->id));
            }
            if ($type === 'vendor_topup') {
                $this->check($target->type === 'vendor' && $target->currency_code === $d['original_currency']);
            }
            if ($type === 'partner_funding') {
                $this->check($funding->type === 'partner' && $target->type === 'bank' && $target->currency_code === $d['original_currency']);
            }
            if ($type === 'partner_repayment') {
                $this->check($funding->type === 'bank' && $target->type === 'partner' && $target->currency_code === $d['original_currency']);
            }
            if (in_array($type, ['vat_payment', 'income_tax_payment'], true)) {
                $this->check($funding->type !== 'vendor');
            }
            $this->check(in_array($type, ['opening_balance', 'bank_collection'], true) || ! $target || $funding->type !== 'vendor');
            $fundingCarrying = $gross;
            $targetCarrying = $net;
            $fxGain = 0;
            if ($funding && in_array($funding->type, ['bank', 'vendor'], true)) {
                $balance = $this->balance($funding, $d['occurred_at']);
                $current = $this->balance($funding);
                $this->check($current['native'] >= $native, 'insufficient_account');
                $this->check($balance['native'] >= $native, 'insufficient_account');
                if ($funding->type === 'bank' && ! in_array($funding->currency_code, ['IRT', 'IRR'], true)) {
                    $fundingCarrying = (int) bcdiv(bcmul((string) $balance['irt'], (string) $native, 0), (string) $balance['native'], 0);
                } else {
                    $this->check($balance['irt'] >= $gross && $current['irt'] >= $gross, 'insufficient_account');
                }
                if ($funding->type === 'vendor') {
                    $this->check($d['original_currency'] === $funding->currency_code && $original === $native && $inputVat === 0);
                    // Consume supplier credit at its recorded weighted carrying cost, never today's FX.
                    $carrying = (int) bcdiv(bcmul((string) $balance['irt'], (string) $native, 0), (string) $balance['native'], 0);
                    $this->check($gross === $carrying, 'carrying_cost');
                }
            }
            if ($type === 'partner_repayment') {
                $claim = $this->balance($target, $d['occurred_at']);
                $currentClaim = $this->balance($target);
                $this->check($claim['native'] > 0 && $claim['native'] >= $original && $currentClaim['native'] >= $original, 'insufficient_account');
                $targetCarrying = (int) bcdiv(bcmul((string) $claim['irt'], (string) $original, 0), (string) $claim['native'], 0);
            }
            if ($type === 'bank_collection') {
                $bank = DB::table('finance_bank_transactions')->where('id', $d['bank_transaction_id'] ?? 0)->lockForUpdate()->first();
                $this->check($bank && $target && $target->type === 'bank' && (int) $bank->account_id === (int) $target->id
                    && $bank->currency_code === $d['original_currency'] && (int) $bank->credit_minor === $original && $bank->occurred_at === $d['occurred_at']);
                $this->check(! FinanceVoucher::where('bank_transaction_id', $bank->id)->whereNull('reversal_of')
                    ->whereNotIn('id', FinanceVoucher::whereNotNull('reversal_of')->select('reversal_of'))->exists(), 'already_documented');
                $this->check(in_array($d['original_currency'], ['IRT', 'IRR'], true) || ! empty($d['fx_basis']), 'cost_proof');
            }
            $fxGain = $type === 'bank_collection' || $type === 'opening_balance' ? 0 : $targetCarrying + $inputVat - $fundingCarrying;
            $linked = null;
            if (! empty($d['business_entry_id'])) {
                $this->check(in_array($type, ['expense', 'cost_adjustment'], true) && $inputVat === 0);
                $linked = BusinessEntry::whereKey($d['business_entry_id'])->lockForUpdate()->first();
                $this->check($linked && $linked->kind === 'expense' && $linked->currency_code === 'IRT'
                    && ($type === 'cost_adjustment' || (int) $linked->amount === $net) && $linked->occurred_at->toDateString() === $d['occurred_at'], 'link_mismatch');
                $this->check(! FinanceVoucher::where(fn ($q) => $q->where('business_entry_id', $linked->id)->orWhere('adjusts_entry_id', $linked->id))->whereNull('reversal_of')->whereNotIn('id', FinanceVoucher::whereNotNull('reversal_of')->select('reversal_of'))->exists(), 'already_documented');
            }
            $this->check($type !== 'cost_adjustment' || $linked !== null, 'link_mismatch');
            if (! empty($d['vendor']) && ! empty($d['invoice_number']) && in_array($type, ['expense', 'cost_adjustment', 'asset_purchase', 'vendor_topup'], true)) {
                $invoiceKey = hash('sha256', mb_strtolower(trim($d['vendor'])).'|'.mb_strtolower(trim($d['invoice_number'])));
                $this->check(! FinanceVoucher::where('vendor_invoice_key', $invoiceKey)->whereNull('reversal_of')->whereNotIn('id', FinanceVoucher::whereNotNull('reversal_of')->select('reversal_of'))->exists(), 'duplicate_invoice');
            } else {
                $invoiceKey = null;
            }
            if ($type === 'asset_purchase') {
                $this->check(! empty($d['asset_name']) && ! empty($d['in_service_at']) && (int) ($d['useful_life_months'] ?? 0) > 0
                    && (int) $d['useful_life_months'] <= 1200 && (int) ($d['residual_irt'] ?? 0) >= 0 && (int) ($d['residual_irt'] ?? 0) < $net);
                $this->check($d['in_service_at'] >= $d['occurred_at']);
            }
            $allowed = ['idempotency_key', 'type', 'occurred_at', 'service_line', 'category', 'vendor', 'invoice_number', 'bank_reference',
                'fx_basis', 'note', 'original_currency', 'original_minor', 'settlement_currency', 'settlement_minor', 'funding_account_id',
                'target_account_id', 'bank_transaction_id', 'tax_status', 'tax_reference', 'vat_status'];
            $v = FinanceVoucher::create(array_intersect_key($d, array_flip($allowed)) + [
                'payload_hash' => $hash, 'vendor_invoice_key' => $invoiceKey, 'amount_irt' => $gross, 'input_vat_irt' => $inputVat, 'created_by' => $actor,
                'business_entry_id' => $type === 'cost_adjustment' ? null : $linked?->id, 'adjusts_entry_id' => $type === 'cost_adjustment' ? $linked?->id : null, 'owns_business_entry' => false, 'book_delta_irt' => 0, 'fx_gain_irt' => $fxGain]);
            $creditCode = $funding?->type === 'partner' ? 'partner_current' : ($funding?->type === 'vendor' ? 'vendor_prepaid' : 'bank');
            if ($type === 'opening_balance') {
                $this->check($d['original_currency'] === $target->currency_code && ! empty($d['bank_reference']), 'opening_proof');
                $this->check(! DB::table('finance_lines')->where('account_id', $target->id)->exists(), 'opening_exists');
                if ($target->type === 'partner') {
                    $this->line($v, 'opening_equity', $gross, 0);
                    $this->line($v, 'partner_current', 0, $gross, $target->id, 0, $original);
                } else {
                    $this->line($v, $target->type === 'bank' ? 'bank' : 'vendor_prepaid', $gross, 0, $target->id, $original);
                    $this->line($v, 'opening_equity', 0, $gross);
                }
            } elseif ($type === 'bank_collection') {
                $this->line($v, 'bank', $gross, 0, $target->id, $original);
                $this->line($v, 'receipts_clearing', 0, $gross);
            } else {
                $debitCode = match ($type) {
                    'expense','cost_adjustment' => 'expense','asset_purchase' => 'fixed_asset','vendor_topup' => 'vendor_prepaid',
                    'partner_funding' => 'bank','partner_repayment' => 'partner_current','vat_payment' => 'vat_payable',
                    'income_tax_payment' => 'income_tax_payable',
                };
                $this->line($v, $debitCode, $targetCarrying, 0, $target?->id, $target ? $original : 0);
                if ($inputVat > 0) {
                    $this->line($v, 'vat_input', $inputVat, 0);
                }
                $this->line($v, $creditCode, 0, $fundingCarrying, $funding->id, 0, $native);
                if ($fxGain > 0) {
                    $this->line($v, 'fx_gain', 0, $fxGain);
                }
                if ($fxGain < 0) {
                    $this->line($v, 'fx_loss', -$fxGain, 0);
                }
            }
            if (($type === 'expense' && ! $linked) || $type === 'cost_adjustment') {
                $delta = $type === 'cost_adjustment' ? $net - (int) $linked->amount : $net;
                if ($delta !== 0) {
                    $e = app(BusinessLedger::class)->recordDocumentedCost($v, abs($delta), $delta < 0);
                    $this->check($e !== null, 'not_ready');
                    $v->forceFill(['business_entry_id' => $e->id, 'owns_business_entry' => true, 'book_delta_irt' => $delta])->save();
                }
            }
            if ($fxGain !== 0) {
                app(BusinessLedger::class)->recordForex($v, $fxGain);
            }
            if ($type === 'asset_purchase') {
                FinanceAsset::create(['voucher_id' => $v->id, 'name' => $d['asset_name'],
                    'serial' => $d['asset_serial'] ?? null, 'in_service_at' => $d['in_service_at'], 'useful_life_months' => $d['useful_life_months'],
                    'cost_irt' => $net, 'residual_irt' => $d['residual_irt'] ?? 0]);
            }
            $this->audit('voucher_posted', $actor, $v->id, ['type' => $type, 'base_currency' => 'IRT']);

            return $v;
        }, 3);
    }

    public function line(FinanceVoucher $v, string $code, int $debit, int $credit, ?int $account = null, int $nativeDebit = 0, int $nativeCredit = 0): void
    {
        FinanceLine::create(['voucher_id' => $v->id, 'account_id' => $account, 'code' => $code, 'debit_irt' => $debit, 'credit_irt' => $credit,
            'native_debit' => $nativeDebit, 'native_credit' => $nativeCredit]);
    }

    public function balance(FinanceAccount $a, ?string $through = null): array
    {
        $q = DB::table('finance_lines as l')->join('finance_vouchers as v', 'v.id', '=', 'l.voucher_id')->where('l.account_id', $a->id);
        if ($through) {
            $q->whereDate('v.occurred_at', '<=', $through);
        }
        $r = $q->selectRaw('COALESCE(SUM(l.debit_irt-l.credit_irt),0) as irt, COALESCE(SUM(l.native_debit-l.native_credit),0) as native')->first();
        $sign = $a->type === 'partner' ? -1 : 1;

        return ['irt' => (int) $r->irt * $sign, 'native' => (int) $r->native * $sign];
    }

    public function reverse(FinanceVoucher $voucher, string $date, string $reason, int $actor): FinanceVoucher
    {
        return DB::transaction(function () use ($voucher, $date, $reason, $actor) {
            $this->guardDate($date);
            $v = FinanceVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            $this->check($v->type !== 'wallet_refund_payout', 'wallet_refund_no_reverse');
            $this->check($date >= $v->occurred_at->toDateString());
            $this->check(! $v->reversal_of && ! FinanceVoucher::where('reversal_of', $v->id)->exists(), 'already_reversed');
            $this->check(mb_strlen(trim($reason)) >= 5);
            // Reversing a prepaid topup or opening after consumption would make fictitious negative balances.
            foreach (FinanceLine::where('voucher_id', $v->id)->whereNotNull('account_id')->get() as $l) {
                $a = FinanceAccount::whereKey($l->account_id)->lockForUpdate()->firstOrFail();
                if ($a->type !== 'partner' && $l->native_debit > 0) {
                    $bal = $this->balance($a, $date);
                    $current = $this->balance($a);
                    $this->check($current['native'] >= $l->native_debit && $current['irt'] >= $l->debit_irt, 'insufficient_account');
                    $this->check($bal['native'] >= $l->native_debit && $bal['irt'] >= $l->debit_irt, 'insufficient_account');
                }
            }
            if ($v->type === 'asset_purchase') {
                $asset = FinanceAsset::where('voucher_id', $v->id)->first();
                $posted = FinanceVoucher::where('asset_period', 'like', 'asset-'.$asset->id.'-%')
                    ->whereNotIn('id', FinanceVoucher::whereNotNull('reversal_of')->select('reversal_of'))->exists();
                $this->check(! $posted, 'asset_depreciated');
            }
            $attrs = array_diff_key($v->getAttributes(), array_flip(['id', 'created_at', 'updated_at', 'business_entry_id', 'vendor_invoice_key', 'asset_period']));
            $attrs['idempotency_key'] = (string) Str::uuid();
            $attrs['payload_hash'] = hash('sha256', $v->id.'|'.$date.'|'.$reason);
            $attrs['reversal_of'] = $v->id;
            $attrs['occurred_at'] = $date;
            $attrs['note'] = $reason;
            $attrs['created_by'] = $actor;
            $attrs['book_delta_irt'] = -(int) $v->book_delta_irt;
            $attrs['fx_gain_irt'] = -(int) $v->fx_gain_irt;
            $r = FinanceVoucher::create($attrs);
            foreach (FinanceLine::where('voucher_id', $v->id)->get() as $l) {
                $this->line($r, $l->code, (int) $l->credit_irt, (int) $l->debit_irt, $l->account_id, (int) $l->native_credit, (int) $l->native_debit);
            }
            if ($v->owns_business_entry && in_array($v->type, ['expense', 'cost_adjustment', 'depreciation'], true)) {
                $delta = -(int) $v->book_delta_irt;
                $entry = app(BusinessLedger::class)->recordDocumentedCost($r, abs($delta), $delta < 0);
                $this->check($entry !== null, 'not_ready');
                $r->forceFill(['business_entry_id' => $entry->id])->save();
            } elseif ($v->business_entry_id) {
                $r->forceFill(['business_entry_id' => $v->business_entry_id, 'owns_business_entry' => false])->save();
            }
            if ((int) $v->fx_gain_irt !== 0) {
                app(BusinessLedger::class)->recordForex($r, -(int) $v->fx_gain_irt, true);
            }
            $this->audit('voucher_reversed', $actor, $r->id, ['original' => $v->id, 'reason' => $reason]);

            return $r;
        }, 3);
    }

    public function depreciate(FinanceAsset $asset, string $period, int $actor): FinanceVoucher
    {
        return DB::transaction(function () use ($asset, $period, $actor) {
            $date = Carbon::createFromFormat('!Y-m', $period, 'Asia/Tehran')->endOfMonth()->toDateString();
            $this->guardDate($date);
            $a = FinanceAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $purchase = FinanceVoucher::findOrFail($a->voucher_id);
            $this->check(! FinanceVoucher::where('reversal_of', $purchase->id)->exists(), 'already_reversed');
            $start = $a->in_service_at->copy()->startOfMonth();
            $month = Carbon::createFromFormat('!Y-m', $period, 'Asia/Tehran')->startOfMonth();
            $index = ((int) $month->format('Y') - (int) $start->format('Y')) * 12 + (int) $month->format('m') - (int) $start->format('m');
            $this->check($index >= 0 && $index < $a->useful_life_months);
            $key = 'asset-'.$a->id.'-'.$period;
            if ($v = FinanceVoucher::where('asset_period', $key)->whereNull('reversal_of')->whereNotIn('id', FinanceVoucher::whereNotNull('reversal_of')->select('reversal_of'))->first()) {
                return $v;
            }
            $depreciable = $a->cost_irt - $a->residual_irt;
            $monthly = intdiv($depreciable, $a->useful_life_months);
            $amount = $index === $a->useful_life_months - 1 ? $depreciable - $monthly * ($a->useful_life_months - 1) : $monthly;
            $this->check($amount > 0);
            // Book schedule is not automatically an accepted Iranian tax depreciation schedule.
            $v = FinanceVoucher::create(['idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $key),
                'asset_period' => $key, 'type' => 'depreciation', 'occurred_at' => $date, 'service_line' => $purchase->service_line,
                'category' => 'depreciation', 'note' => $a->name.' / '.$period, 'vendor' => $purchase->vendor,
                'invoice_number' => $purchase->invoice_number, 'bank_reference' => $purchase->bank_reference,
                'amount_irt' => $amount, 'original_currency' => 'IRT', 'original_minor' => $amount,
                'tax_status' => 'pending', 'vat_status' => 'none', 'created_by' => $actor]);
            $this->line($v, 'expense', $amount, 0);
            $this->line($v, 'accumulated_depreciation', 0, $amount);
            $entry = app(BusinessLedger::class)->recordDocumentedCost($v, $amount);
            $this->check($entry !== null, 'not_ready');
            $v->forceFill(['business_entry_id' => $entry->id, 'owns_business_entry' => true, 'book_delta_irt' => $amount])->save();
            $this->audit('depreciation_posted', $actor, $v->id, ['asset' => $a->id, 'period' => $period]);

            return $v;
        }, 3);
    }

    public function reviewTax(FinanceVoucher $voucher, string $status, string $reference, int $actor): void
    {
        DB::transaction(function () use ($voucher, $status, $reference, $actor) {
            $v = FinanceVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            $this->check(! $v->reversal_of && in_array($v->type, ['expense', 'cost_adjustment', 'asset_purchase', 'depreciation'], true));
            $this->check(in_array($status, ['pending', 'eligible', 'rejected'], true) && mb_strlen($reference) >= 10);
            $proofVoucher = $v;
            if ($v->type === 'depreciation' && preg_match('/^asset-(\d+)-/', $v->asset_period, $m)) {
                $asset = FinanceAsset::findOrFail((int) $m[1]);
                $proofVoucher = FinanceVoucher::findOrFail($asset->voucher_id);
            }
            if ($status === 'eligible') {
                $this->check(! empty($proofVoucher->invoice_number) && ! empty($proofVoucher->bank_reference)
                    && ! empty($proofVoucher->vendor) && ($proofVoucher->original_currency === 'IRT' || ! empty($proofVoucher->fx_basis))
                    && FinanceDocument::whereIn('voucher_id', [$v->id, $proofVoucher->id])->exists(), 'cost_proof');
            }
            $id = DB::table('finance_tax_reviews')->insertGetId(['voucher_id' => $v->id, 'status' => $status, 'reference' => $reference,
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('tax_review_added', $actor, $id, ['voucher' => $v->id, 'status' => $status]);
        }, 3);
    }

    public function closeThrough(string $date, string $reason, int $actor): void
    {
        DB::transaction(function () use ($date, $reason, $actor) {
            $c = DB::table('finance_controls')->where('id', 1)->lockForUpdate()->first();
            $this->check($date <= now('Asia/Tehran')->toDateString() && (! $c->locked_through || $date > $c->locked_through));
            $this->check(mb_strlen(trim($reason)) >= 5);
            DB::table('finance_controls')->where('id', 1)->update(['locked_through' => $date, 'updated_at' => now()]);
            $this->audit('period_closed', $actor, null, ['through' => $date, 'reason' => $reason]);
        }, 3);
    }
}
