<?php

namespace App\Console\Commands;

use App\Models\IdentityRequest;
use App\Services\IdentityApi\BalanceSync;
use App\Services\IdentityApi\Controls;
use App\Services\IdentityApi\Engine;
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class IdentityReconcile extends Command
{
    protected $signature = 'identity:reconcile';

    protected $description = 'Settle recorded inquiry evidence, release ambiguous holds at deadline, and erase expired sensitive data';

    public function handle(Controls $controls, Engine $engine): int
    {
        if (! $controls->ready()) {
            return self::SUCCESS;
        }
        $failed = 0;
        IdentityRequest::whereIn('financial_status', ['reserved', 'pending_reconciliation'])->orderBy('id')->chunkById(100, function ($rows) use ($engine, &$failed) {
            foreach ($rows as $r) {
                try {
                    if (! $r->decide_by->isFuture()) {
                        $engine->release($r);
                    } elseif ($r->financial_status === 'pending_reconciliation') {
                        $engine->capture($r);
                    }
                } catch (\Throwable) {
                    $failed++;
                }
            }
        });
        // Permanent financial metadata and keyed fingerprints remain as idempotency tombstones.
        IdentityRequest::where('retain_until', '<=', now())->where(function ($q) {
            $q->whereNotNull('payload')->orWhereNotNull('result')->orWhereNotNull('provider_evidence');
        })
            ->update(['payload' => null, 'result' => null, 'provider_evidence' => null]);
        $unknown = IdentityRequest::where('discrepancy', true)->where('created_at', '>=', now()->subDay())->count();
        if ($unknown || $failed) {
            ErrorTracker::noteOnce('identity_api', 'Inquiry reconciliation requires administrator review', 900, ['discrepancies' => $unknown, 'failed' => $failed]);
        }
        try {
            app(BalanceSync::class)->refreshIfDue();
        } catch (\Throwable) {
            ErrorTracker::noteOnce('identity_api', 'Provider balance observation failed; previous funding evidence retained', 900);
        }
        $fundingStale = $controls->dispatchEnabled() && ! $controls->fundingReady();
        if (($unknown || $failed || $fundingStale) && $controls->get('notifications_enabled', false) === true && Cache::add('identity:admin-alert', true, now()->addMinutes(15))) {
            app(AdminNotifier::class)->event(__('identity.menu'), [
                'discrepancies' => $unknown, 'settlement_failures' => $failed, 'funding_evidence_stale' => (int) $fundingStale,
            ], 'https://console.servernet.cloud/admin/identity-api');
        }
        Cache::put('identity:reconcile:heartbeat', now()->toIso8601String(), now()->addHours(2));
        $this->line('failed='.$failed.' discrepancies='.$unknown);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
