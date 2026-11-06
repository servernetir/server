<?php

namespace App\Console\Commands;

use App\Services\Hermes\{HermesBusinessAudit, HermesOperations, HermesOperationsPolicy, HermesOperationsStore, HermesReleaseKnowledge};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class HermesBusinessReview extends Command
{
    protected $signature = 'hermes:business-review';
    protected $description = 'Read-only receipts, cost quotes, traffic and installed knowledge review';

    public function handle(HermesBusinessAudit $audit, HermesOperations $ops, HermesOperationsStore $store, HermesReleaseKnowledge $knowledge): int
    {
        if (! $ops->enabled() || ! ($owner = $ops->owner())) { $this->line('disabled'); return self::SUCCESS; }
        $lock = Cache::lock('hermes:business-review', 2700);
        if (! $lock->get()) { $this->line('busy'); return self::SUCCESS; }
        try {
            $summary = $audit->run(); $observation = $knowledge->capture();
            $store->change($owner, static function (array &$s) use ($observation): void { $s['release_knowledge'] = $observation; });
            if (\App\Services\Notify\BaleManagerPolicy::enabled()) {
                $summary['owner_alert'] = $ops->digest(false);
            }
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR)); return self::SUCCESS;
        } catch (\Throwable) {
            try { $store->change($owner, static function (array &$s): void { HermesOperationsPolicy::alert($s, 'business:review', 'business_review_unavailable', ['severity' => 'critical']); }); } catch (\Throwable) {}
            $this->error('Business review unavailable; previous dated evidence retained'); return self::FAILURE;
        } finally { $lock->release(); }
    }
}

