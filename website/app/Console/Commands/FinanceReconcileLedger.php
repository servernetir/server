<?php

namespace App\Console\Commands;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\DocumentedAccounting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Source-backed historical reporting recovery. No customer balances, invoices or provider operations are changed. */
final class FinanceReconcileLedger extends Command
{
    protected $signature = 'finance:reconcile-ledger {--from=} {--to=} {--apply} {--plan-hash=}';

    protected $description = 'Preview or recover missing report entries from actual paid payments and hourly wallet events';

    public function handle(BusinessLedger $ledger): int
    {
        if (! $ledger->ready()) {
            $this->error('Ledger unavailable');

            return self::FAILURE;
        }
        $d = validator(['from' => $this->option('from'), 'to' => $this->option('to')], [
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']])->validate();
        $from = Carbon::parse($d['from'], 'Asia/Tehran')->startOfDay()->utc();
        $to = Carbon::parse($d['to'], 'Asia/Tehran')->endOfDay()->utc();
        $plan = [];
        $skipped = 0;
        $wallet = CreditEntry::whereBetween('created_at', [$from, $to])->where(function ($q) {
            $q->where(fn ($w) => $w->where('amount', '<', 0)->whereIn('reason', BusinessLedger::CREDIT_SPEND_REASONS))
                ->orWhere(fn ($w) => $w->where('amount', '>', 0)->whereIn('reason', BusinessLedger::CREDIT_REFUND_REASONS));
        })->orderBy('id');
        foreach ($wallet->lazyById(300) as $e) {
            $kind = $e->amount < 0 ? 'revenue' : 'hourly_refund';
            if ($e->amount > 0 && ! $ledger->isHourlyRefund($e)) {
                $skipped++;

                continue;
            }
            if (BusinessEntry::where('source_type', $e->getMorphClass())->where('source_id', $e->id)->where('kind', $kind)->exists()) {
                continue;
            }
            $plan[] = ['type' => 'credit', 'id' => $e->id, 'amount' => $e->amount, 'currency' => $e->currency_code, 'date' => $e->created_at->toIso8601String(), 'reason' => $e->reason, 'source_type' => $e->source_type, 'source_id' => $e->source_id];
        }
        $payments = Payment::where('status', 'paid')->whereBetween('paid_at', [$from, $to])->whereHas('invoice', fn ($i) => $i->where('kind', '!=', 'topup'))
            ->whereNotIn('id', BusinessEntry::where('source_type', (new Payment)->getMorphClass())->where('kind', 'revenue')->select('source_id'));
        foreach ($payments->orderBy('id')->lazyById(300) as $p) {
            if (BusinessEntry::where('source_type', (new Invoice)->getMorphClass())->where('source_id', $p->invoice_id)->where('kind', 'revenue')->exists()) {
                $skipped++;

                continue;
            }
            $plan[] = ['type' => 'payment', 'id' => $p->id, 'amount' => $p->amount, 'currency' => $p->currency_code, 'date' => $p->paid_at->toIso8601String(), 'invoice_total' => (int) $p->invoice->total, 'invoice_tax' => (int) $p->invoice->tax, 'invoice_kind' => $p->invoice->kind];
        }
        $hash = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
        if ($this->option('apply') && (! is_string($this->option('plan-hash')) || ! hash_equals($hash, $this->option('plan-hash')))) {
            $this->error('Plan changed or no expected plan hash supplied; preview again.');

            return self::FAILURE;
        }
        $created = 0;
        if ($this->option('apply')) {
            foreach ($plan as $item) {
                DB::transaction(function () use ($item, $ledger, &$created) {
                    $source = $item['type'] === 'credit' ? CreditEntry::whereKey($item['id'])->lockForUpdate()->firstOrFail() : Payment::whereKey($item['id'])->lockForUpdate()->firstOrFail();
                    if ((int) $source->amount !== (int) $item['amount'] || $source->currency_code !== $item['currency']) {
                        throw new \RuntimeException('Source changed; remaining batch stopped.');
                    }
                    $before = BusinessEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->id)->count();
                    if ($item['type'] === 'credit') {
                        if ($source->reason !== $item['reason'] || $source->source_type !== $item['source_type'] || (int) $source->source_id !== (int) $item['source_id'] || $source->created_at->toIso8601String() !== $item['date'] || ($source->amount > 0 && ! $ledger->isHourlyRefund($source))) {
                            throw new \RuntimeException('Wallet source changed; remaining batch stopped.');
                        }
                        $ledger->recordCreditSpend($source);
                    } elseif ($source->status === 'paid' && $source->paid_at->toIso8601String() === $item['date'] && (int) $source->invoice->total === $item['invoice_total'] && (int) $source->invoice->tax === $item['invoice_tax'] && $source->invoice->kind === $item['invoice_kind']) {
                        $ledger->recordPayment($source);
                    } else {
                        throw new \RuntimeException('Payment state changed; remaining batch stopped.');
                    }
                    $created += BusinessEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->id)->count() - $before;
                }, 3);
            }
            if (app(DocumentedAccounting::class)->ready()) {
                app(DocumentedAccounting::class)->audit('ledger_reconciled', 0, null, ['plan_hash' => $hash, 'from' => $d['from'], 'to' => $d['to'], 'created' => $created]);
            }
        }
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'preview', 'from' => $d['from'], 'to' => $d['to'],
            'events' => count($plan), 'by_type' => array_count_values(array_column($plan, 'type')), 'ambiguous_refunds_skipped' => $skipped,
            'created_rows' => $created, 'plan_hash' => $hash], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
