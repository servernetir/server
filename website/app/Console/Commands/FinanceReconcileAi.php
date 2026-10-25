<?php

namespace App\Console\Commands;

use App\Models\AiUsage;
use App\Models\BusinessEntry;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\DocumentedAccounting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Preview only by default. Historical recovery needs approved dates and private source evidence. */
final class FinanceReconcileAi extends Command
{
    protected $signature = 'finance:reconcile-ai {--from=} {--to=} {--apply : Explicitly post missing snapshots} {--plan-hash=}';

    protected $description = 'Preview missing AI financial entries; never calls providers or charges customers';

    public function handle(BusinessLedger $ledger): int
    {
        if (! $ledger->ready()) {
            $this->error('Ledger unavailable');

            return self::FAILURE;
        }
        $dates = validator(['from' => $this->option('from'), 'to' => $this->option('to')], [
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();
        $q = AiUsage::whereIn('status', AiUsage::CHARGED)->whereDate('day', '>=', $dates['from'])->whereDate('day', '<=', $dates['to'])
            ->whereNotIn('id', BusinessEntry::where('source_type', (new AiUsage)->getMorphClass())->where('kind', 'revenue')->select('source_id'));
        $plan = [];
        $review = 0;
        $snapshot = fn (AiUsage $u) => collect($u->getAttributes())->only(['id', 'status', 'needs_review', 'charged_irt', 'refunded_irt', 'uncollected_irt', 'sell_irt', 'tax_irt', 'cost_irt', 'day', 'settled_at'])->all();
        foreach ($q->orderBy('id')->lazyById(200) as $u) {
            if ($u->needs_review || $u->refunded_irt > 0 || $u->status === AiUsage::STATUS_UNKNOWN_CHARGED || ! $u->settled_at) {
                $review++;

                continue;
            }
            $plan[] = $snapshot($u);
        }
        $hash = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
        if ($this->option('apply') && (! is_string($this->option('plan-hash')) || ! hash_equals($hash, $this->option('plan-hash')))) {
            $this->error('Plan changed or no expected plan hash supplied; preview again.');

            return self::FAILURE;
        }
        $created = 0;
        if ($this->option('apply')) {
            foreach ($plan as $item) {
                DB::transaction(function () use ($item, $snapshot, $ledger, &$created) {
                    $u = AiUsage::whereKey($item['id'])->lockForUpdate()->firstOrFail();
                    if ($snapshot($u) !== $item) {
                        throw new \RuntimeException('AI snapshot changed; remaining batch stopped.');
                    }
                    $query = BusinessEntry::where('source_type', $u->getMorphClass())->where('source_id', $u->id);
                    $before = (clone $query)->count();
                    $ledger->recordAiUsage($u);
                    $created += $query->count() - $before;
                }, 3);
            }
            if (app(DocumentedAccounting::class)->ready()) {
                app(DocumentedAccounting::class)->audit('ai_reconciled', 0, null, ['plan_hash' => $hash, 'from' => $dates['from'], 'to' => $dates['to'], 'created' => $created]);
            }
        }
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'preview', 'eligible_snapshots' => count($plan), 'review_required' => $review, 'created_rows' => $created, 'plan_hash' => $hash], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
