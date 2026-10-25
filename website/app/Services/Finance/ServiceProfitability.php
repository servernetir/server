<?php

namespace App\Services\Finance;

use App\Models\AiUsage;
use App\Models\BusinessEntry;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\FinanceVoucher;
use App\Models\IdentityRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\SnappPayOrderEvent;
use Illuminate\Support\Facades\Schema;

final class ServiceProfitability
{
    public function calculate(FinanceFilter $scope): array
    {
        $out = [];
        foreach ([...DocumentedAccounting::SERVICES, 'unallocated'] as $line) {
            $out[$line] = ['revenue' => 0, 'expense' => 0, 'other_income' => 0];
        }
        if (! Schema::hasTable('business_ledger')) {
            return $out;
        }
        $q = $scope->apply(BusinessEntry::query())->where('currency_code', 'IRT')->whereIn('kind',
            ['revenue', 'expense', 'expense_reversal', 'ai_cost_adjust', 'ai_rev_adjust', 'identity_refund', 'refund', 'hourly_refund', 'fx_gain', 'fx_gain_rev', 'fx_loss', 'fx_loss_rev']);
        $types = ['invoice' => (new Invoice)->getMorphClass(), 'payment' => (new Payment)->getMorphClass(),
            'credit' => (new CreditEntry)->getMorphClass(), 'voucher' => (new FinanceVoucher)->getMorphClass(),
            'event' => (new SnappPayOrderEvent)->getMorphClass(), 'ai' => (new AiUsage)->getMorphClass(), 'identity' => (new IdentityRequest)->getMorphClass()];
        foreach ($q->orderBy('id')->lazyById(500)->chunk(500) as $entries) {
            $ids = fn ($key) => $entries->where('source_type', $types[$key])->pluck('source_id')->all();
            $payments = Payment::whereIn('id', $ids('payment'))->get(['id', 'invoice_id'])->keyBy('id');
            $events = SnappPayOrderEvent::whereIn('id', $ids('event'))->with('order')->get();
            $eventInvoices = $events->mapWithKeys(fn ($e) => [$e->id => $e->order?->invoice_id]);
            $invoices = Invoice::whereIn('id', array_merge($ids('invoice'), $payments->pluck('invoice_id')->all(), $eventInvoices->filter()->values()->all()))->get(['id', 'kind', 'service_id', 'domain_id'])->keyBy('id');
            $credits = CreditEntry::whereIn('id', $ids('credit'))->get()->keyBy('id');
            $serviceIds = array_merge($invoices->pluck('service_id')->filter()->all(), $credits->where('source_type', (new Service)->getMorphClass())->pluck('source_id')->all());
            $services = Service::whereIn('id', $serviceIds)->get(['id', 'cloud_plan_id', 'is_reseller'])->keyBy('id');
            $plans = CloudPlan::whereIn('id', $services->pluck('cloud_plan_id')->filter()->all())->get(['id', 'provider'])->keyBy('id');
            $vouchers = collect();
            $documented = collect();
            if (Schema::hasTable('finance_vouchers')) {
                $vouchers = FinanceVoucher::whereIn('id', $ids('voucher'))->get()->keyBy('id');
                $documented = FinanceVoucher::whereIn('business_entry_id', $entries->pluck('id'))
                    ->whereNull('reversal_of')->whereNotIn('id', FinanceVoucher::whereNotNull('reversal_of')->select('reversal_of'))->get()->keyBy('business_entry_id');
                $corrections = FinanceVoucher::whereIn('adjusts_entry_id', $entries->pluck('id'))->whereNull('reversal_of')
                    ->whereNotIn('id', FinanceVoucher::whereNotNull('reversal_of')->select('reversal_of'))->get();
                foreach ($corrections as $correction) {
                    $documented->put($correction->adjusts_entry_id, $correction);
                }
            }
            $serviceLine = function ($serviceId) use ($services, $plans) {
                $s = $services->get($serviceId);
                if (! $s) {
                    return 'unallocated';
                }
                if ($s->is_reseller) {
                    return 'reseller_hosting';
                }
                if ($s->cloud_plan_id) {
                    return match ($plans->get($s->cloud_plan_id)?->provider) {
                        'salad' => 'gpu','hetzner-robot' => 'dedicated',default => 'cloud_vps'
                    };
                }

                // No immutable product category exists on old service/invoice rows. Do not guess hosting vs storage.
                return 'unallocated';
            };
            foreach ($entries as $e) {
                $line = $documented->get($e->id)?->service_line;
                if (! $line && $e->source_type === $types['voucher']) {
                    $line = $vouchers->get($e->source_id)?->service_line;
                }
                if (! $line && $e->source_type === $types['ai']) {
                    $line = 'ai';
                }
                if (! $line && $e->source_type === $types['identity']) {
                    $line = 'identity_api';
                }
                if (! $line && in_array($e->source_type, [$types['invoice'], $types['payment'], $types['event']], true)) {
                    $id = $e->source_type === $types['invoice'] ? $e->source_id : ($e->source_type === $types['payment'] ? $payments->get($e->source_id)?->invoice_id : $eventInvoices->get($e->source_id));
                    $inv = $invoices->get($id);
                    $line = $inv?->domain_id || $inv?->kind === 'domain' ? 'domain' : $serviceLine($inv?->service_id);
                }
                if (! $line && $e->source_type === $types['credit']) {
                    $credit = $credits->get($e->source_id);
                    $line = $credit?->source_type === (new Service)->getMorphClass() ? $serviceLine($credit->source_id) : 'unallocated';
                }
                $line ??= match ($e->category) {
                    'domain_wholesale' => 'domain','identity_api','api_kyc' => 'identity_api','api_sms' => 'sms','ai' => 'ai',default => 'unallocated'
                };
                if (! isset($out[$line])) {
                    $line = 'unallocated';
                }
                if (in_array($e->kind, ['fx_gain', 'fx_gain_rev'], true)) {
                    $out[$line]['other_income'] += ($e->kind === 'fx_gain_rev' ? -1 : 1) * $e->amount;
                } elseif ($e->kind === 'revenue') {
                    $out[$line]['revenue'] += $e->amount;
                } elseif (in_array($e->kind, ['identity_refund', 'ai_rev_adjust', 'hourly_refund'], true)) {
                    $out[$line]['revenue'] -= $e->amount;
                } elseif ($e->kind === 'refund') {
                    $out[$line]['revenue'] -= $e->amount - app(BusinessLedger::class)->refundTax(collect([$e]));
                } else {
                    $out[$line]['expense'] += (in_array($e->kind, ['expense_reversal', 'fx_loss_rev'], true) ? -1 : 1) * $e->amount;
                }
            }
        }

        return $out;
    }
}
