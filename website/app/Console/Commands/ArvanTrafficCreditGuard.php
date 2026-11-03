<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Finance\ArvanTrafficCreditGuard as Guard;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;

final class ArvanTrafficCreditGuard extends Command
{
    protected $signature = 'cloud:traffic-credit-guard {--limit=20}';

    protected $description = 'Reconcile pending traffic-credit stop intents without billing or deleting servers';

    public function handle(Guard $guard): int
    {
        try {
            $result = $guard->process((int) $this->option('limit'));
            Setting::put('arvan_traffic_credit_guard_progress', json_encode([
                'at' => now()->toIso8601String(), 'result' => $result,
            ], JSON_THROW_ON_ERROR));
            if ($result['pending'] > 0 || $result['stale'] > 0 || $result['review_required'] > 0) {
                ErrorTracker::noteOnce('billing', 'Traffic credit stops still require confirmation or reconciliation.',
                    900, $result);
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            ErrorTracker::noteOnce('billing', 'Traffic credit stop worker failed; durable intents remain pending.', 900);
            $this->error('Traffic credit stop worker failed ('.$error::class.').');

            return self::FAILURE;
        }
    }
}
