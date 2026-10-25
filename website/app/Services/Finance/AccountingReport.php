<?php

namespace App\Services\Finance;

use App\Models\AiUsage;
use App\Models\BusinessEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceTaxProfile;
use App\Models\FinanceVoucher;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AccountingReport
{
    public function __construct(private BusinessLedger $ledger, private DocumentedAccounting $journal) {}

    public function report(FinanceFilter $filter): array
    {
        // Report scope is dates. Register search and category filters never silently narrow tax totals.
        $scope = new FinanceFilter([], $filter->from, $filter->to);
        $s = $this->ledger->summary(filter: $scope);
        $result = ['summary' => $s, 'profile' => null, 'eligible_costs' => 0, 'pending_costs' => $s['expense'], 'input_vat' => 0,
            'vat_paid' => 0, 'income_tax_paid' => 0, 'taxable_income' => null, 'income_tax_estimate' => null, 'income_tax_due' => null,
            'vat_movement' => $s['tax_liability'], 'vat_due' => null, 'vat_credit' => null, 'accounts' => collect(),
            'payments' => [], 'coverage' => app(FinanceAudit::class)->inspect($scope), 'unconverted' => $s['by_currency'], 'services' => app(ServiceProfitability::class)->calculate($scope)];
        if (Schema::hasTable('payments')) {
            $p = Payment::where('status', 'paid')->where('currency_code', 'IRT')->with('invoice');
            // paid_at is a UTC instant; journal occurred_at is already a Tehran calendar date.
            if ($scope->from) {
                $p->where('paid_at', '>=', $scope->from->copy()->startOfDay()->utc());
            }
            if ($scope->to) {
                $p->where('paid_at', '<=', $scope->to->copy()->endOfDay()->utc());
            }
            $total = 0;
            $topup = 0;
            $wallet = 0;
            foreach ($p->lazyById(500) as $pay) {
                if (in_array($pay->gateway, ['credit', 'gift'], true)) {
                    $wallet += $pay->amount;
                } else {
                    $total += $pay->amount;
                    if ($pay->invoice?->kind === 'topup') {
                        $topup += $pay->amount;
                    }
                }
            }
            $result['payments'] = ['external_receipts_irt' => $total, 'wallet_spend_irt' => $wallet, 'topups_irt' => $topup];
        }
        if (Schema::hasTable('ai_usage')) {
            $ai = DB::table('ai_usage')->whereIn('status', ['settled', 'unknown_charged']);
            if ($scope->from) {
                $ai->where('day', '>=', $scope->from->toDateString());
            }
            if ($scope->to) {
                $ai->where('day', '<=', $scope->to->toDateString());
            }
            $source = (new AiUsage)->getMorphClass();
            $result['coverage']['ai_unposted'] = (clone $ai)->whereNotIn('id', BusinessEntry::where('source_type', $source)->where('kind', 'revenue')->select('source_id'))->count();
            $result['coverage']['ai_review'] = (clone $ai)->where('needs_review', true)->count();
        }
        if (! $this->journal->ready()) {
            return $result;
        }
        $v = FinanceVoucher::query();
        if ($scope->from) {
            $v->whereDate('occurred_at', '>=', $scope->from->toDateString());
        }
        if ($scope->to) {
            $v->whereDate('occurred_at', '<=', $scope->to->toDateString());
        }
        $reviewIds = DB::table('finance_tax_reviews')->selectRaw('MAX(id) as id')->groupBy('voucher_id');
        $reviews = DB::table('finance_tax_reviews')->whereIn('id', $reviewIds)->get()->keyBy('voucher_id');
        $eligible = 0;
        $input = 0;
        $vatPaid = 0;
        $incomePaid = 0;
        $pendingCount = 0;
        $crossPeriodReversals = 0;
        foreach ($v->lazyById(500) as $row) {
            $sign = $row->reversal_of ? -1 : 1;
            $status = $reviews->get($row->reversal_of ?: $row->id)?->status ?? 'pending';
            if ($row->reversal_of && $scope->from && FinanceVoucher::whereKey($row->reversal_of)->whereDate('occurred_at', '<', $scope->from->toDateString())->exists()) {
                $crossPeriodReversals++;
            }
            if (! $row->reversal_of && in_array($row->type, ['expense', 'cost_adjustment', 'depreciation'], true) && $status === 'pending') {
                $pendingCount++;
            }
            if (in_array($row->type, ['expense', 'cost_adjustment', 'depreciation'], true) && $status === 'eligible') {
                $eligible += $sign * ($row->amount_irt - $row->input_vat_irt);
            }
            if ($row->vat_status === 'eligible' && $status === 'eligible') {
                $input += $sign * $row->input_vat_irt;
            }
            if ($row->type === 'vat_payment') {
                $vatPaid += $sign * $row->amount_irt;
            }
            if ($row->type === 'income_tax_payment') {
                $incomePaid += $sign * $row->amount_irt;
            }
        }
        $result['eligible_costs'] = $eligible;
        $result['pending_costs'] = $s['expense'] - $eligible;
        $result['input_vat'] = $input;
        $result['vat_paid'] = $vatPaid;
        $result['income_tax_paid'] = $incomePaid;
        $result['vat_movement'] = $s['tax_collected'] - $s['tax_paid'] - $input - $vatPaid;
        $result['accounts'] = FinanceAccount::orderBy('id')->get()->map(fn ($a) => ['account' => $a, 'balance' => $this->journal->balance($a, $scope->to?->toDateString())]);
        if ($scope->from && $scope->to) {
            $profile = FinanceTaxProfile::whereDate('from_date', $scope->from->toDateString())->whereDate('to_date', $scope->to->toDateString())->latest('id')->first();
            $result['profile'] = $profile;
            if ($profile && $profile->reviewed && $crossPeriodReversals === 0) {
                $result['vat_due'] = max(0, $result['vat_movement'] - (int) $profile->vat_carry_irt);
                $result['vat_credit'] = max(0, (int) $profile->vat_carry_irt - $result['vat_movement']);
                if ($profile->entity_type === 'corporate') {
                    $result['taxable_income'] = max(0, $s['revenue'] + ($s['other_income'] ?? 0) - $eligible + (int) $profile->other_addbacks_irt - (int) $profile->loss_relief_irt);
                    $result['income_tax_estimate'] = AccountingMoney::percent($result['taxable_income'], (int) $profile->income_rate_bp);
                    $result['income_tax_due'] = max(0, $result['income_tax_estimate'] - $incomePaid);
                }
            }
        }
        $result['coverage']['unreviewed_vouchers'] = $pendingCount;
        $result['coverage']['cross_period_reversals'] = $crossPeriodReversals;
        $result['coverage']['unbalanced_vouchers'] = DB::query()->fromSub(DB::table('finance_lines')->select('voucher_id')->groupBy('voucher_id')->havingRaw('SUM(debit_irt) <> SUM(credit_irt)'), 'unbalanced')->count();

        return $result;
    }
}
