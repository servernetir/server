<?php

namespace App\Services\Finance;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SnappPayOrderEvent;
use Illuminate\Support\Facades\Schema;

/** Read-only coverage checks. Counts are reconciliation candidates, never an authorization to debit/refund. */
final class FinanceAudit
{
    public function inspect(FinanceFilter $scope): array
    {
        $out = [];
        if (! Schema::hasTable('business_ledger')) {
            return ['ledger_missing' => 1];
        }
        $instant = function ($q, string $column = 'created_at') use ($scope) {
            if ($scope->from) {
                $q->where($column, '>=', $scope->from->copy()->startOfDay()->utc());
            }
            if ($scope->to) {
                $q->where($column, '<=', $scope->to->copy()->endOfDay()->utc());
            }

            return $q;
        };
        $payments = $instant(Payment::where('status', 'paid'), 'paid_at');
        $out['payment_unposted'] = (clone $payments)->whereHas('invoice', fn ($q) => $q->where('kind', '!=', 'topup'))
            ->whereNotIn('id', BusinessEntry::where('source_type', (new Payment)->getMorphClass())->where('kind', 'revenue')->select('source_id'))->count();
        $out['promotional_gift_payments'] = (clone $payments)->where('gateway', 'gift')->count();
        $out['hourly_unposted'] = $instant(CreditEntry::where('amount', '<', 0)->whereIn('reason', BusinessLedger::CREDIT_SPEND_REASONS))
            ->whereNotIn('id', BusinessEntry::where('source_type', (new CreditEntry)->getMorphClass())->where('kind', 'revenue')->select('source_id'))->count();
        $out['domain_cost_missing'] = $instant(Invoice::where('kind', 'domain')->where('paid', '>', 0), 'paid_at')
            ->whereIn('domain_id', Domain::where('status', 'active')->select('id'))
            ->whereNotIn('id', BusinessEntry::where('source_type', (new Invoice)->getMorphClass())->where('kind', 'expense')->where('category', 'domain_wholesale')->select('source_id'))->count();
        $out['wallet_refunds_unreviewed'] = $instant(CreditEntry::where('amount', '>', 0)->where('reason', 'refund'))->whereNotIn('id', BusinessEntry::where('source_type', (new CreditEntry)->getMorphClass())->whereIn('kind', ['hourly_refund', 'refund'])->select('source_id'))->count();
        $out['legacy_tax_payments'] = $scope->apply(BusinessEntry::query())->where('kind', 'tax_paid')->count();
        $out['unclassified_refunds'] = $scope->apply(BusinessEntry::query())->where('kind', 'refund')
            ->whereNotIn('source_type', [(new Payment)->getMorphClass(), (new Invoice)->getMorphClass(), (new SnappPayOrderEvent)->getMorphClass()])->count();
        $out['unallocated_costs'] = $scope->apply(BusinessEntry::query())->where('kind', 'expense')->whereNull('category')->count();

        if (Schema::hasTable('finance_vouchers')) {
            $documented = \App\Models\FinanceVoucher::whereNull('reversal_of')
                ->whereNotIn('id', \App\Models\FinanceVoucher::whereNotNull('reversal_of')->select('reversal_of'));
            $documentedCosts = (clone $documented)->whereNotNull('business_entry_id')->pluck('business_entry_id')
                ->merge((clone $documented)->whereNotNull('adjusts_entry_id')->pluck('adjusts_entry_id'))->unique();
            // Review candidates only: an automatic cost estimate is not proof of a supplier debit.
            $out['supplier_costs_unreconciled'] = $scope->apply(BusinessEntry::query())
                ->where('kind', 'expense')->whereIn('category', ['server', 'domain_wholesale', 'api_kyc', 'api_sms'])
                ->whereNotIn('id', $documentedCosts)->count();
        }

        return $out;
    }
}
