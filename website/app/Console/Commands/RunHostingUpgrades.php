<?php

namespace App\Console\Commands;

use App\Models\HostingUpgrade;
use App\Services\Billing\HostingUpgradeFulfillment;
use App\Services\Billing\HostingUpgrades;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;

class RunHostingUpgrades extends Command
{
    protected $signature = 'hosting:upgrades {--limit=100}';

    protected $description = 'Reconcile and fulfill paid hosting upgrades without extending the renewal date';

    public function handle(HostingUpgrades $upgrades, HostingUpgradeFulfillment $fulfillment): int
    {
        if (! $upgrades->ready()) {
            return self::SUCCESS;
        }
        HostingUpgrade::whereNotNull('open_service_id')
            ->where(fn ($q) => $q->whereIn('status', ['awaiting_payment', 'applying'])
                ->orWhere(fn ($review) => $review->where('status', 'review')->whereNotNull('attempted_at')))
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subMinutes(5)))
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', ['paid', 'canceled', 'refunded']))
            ->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get()
            ->each(function ($u) use ($fulfillment) {
                try {
                    $fulfillment->process($u);
                } catch (\Throwable $e) {
                    ErrorTracker::note('hosting-upgrade', $e, ['upgrade' => $u->id]);
                }
            });

        return self::SUCCESS;
    }
}
