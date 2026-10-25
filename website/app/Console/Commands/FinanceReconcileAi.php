<?php

namespace App\Console\Commands;

use App\Models\AiUsage;
use App\Models\BusinessEntry;
use App\Services\Finance\BusinessLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Preview only by default. Historical recovery needs approved dates and private source evidence. */
final class FinanceReconcileAi extends Command
{
    protected $signature = 'finance:reconcile-ai {--from=} {--to=} {--apply : Explicitly post missing snapshots}';

    protected $description = 'Preview missing AI financial entries; never calls providers or charges customers';

    public function handle(BusinessLedger $ledger): int
    {
        if (! $ledger->ready()) {
            $this->error('Ledger unavailable');

            return self::FAILURE;
        }
        $from = $this->option('from');
        $to = $this->option('to');
        if (! $from || ! $to || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) || $from > $to) {
            $this->error('Explicit --from and --to (YYYY-MM-DD) are required.');

            return self::FAILURE;
        }
        $q = AiUsage::whereIn('status', AiUsage::CHARGED)->whereBetween('day', [$from, $to])
            ->whereNotIn('id', BusinessEntry::where('source_type', (new AiUsage)->getMorphClass())->where('kind', 'revenue')->select('source_id'));
        $count = 0;
        $review = 0;
        foreach ($q->lazyById(200) as $u) {
            $count++;
            // A refund or uncertain cap has no immutable historical cost/tax timeline; require manual reconciliation.
            if ($u->needs_review || $u->refunded_irt > 0 || $u->status === AiUsage::STATUS_UNKNOWN_CHARGED || ! $u->settled_at) {
                $review++;

                continue;
            }
            if ($this->option('apply')) {
                DB::transaction(function () use ($u, $ledger) {
                    $locked = AiUsage::whereKey($u->id)->lockForUpdate()->firstOrFail();
                    if (! $locked->needs_review && ! $locked->refunded_irt) {
                        $ledger->recordAiUsage($locked);
                    }
                }, 3);
            }
        }
        $this->table(['mode', 'missing rows', 'manual review required'], [[$this->option('apply') ? 'apply' : 'preview', $count, $review]]);

        return self::SUCCESS;
    }
}
