<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use Illuminate\Support\Facades\Schema;

/** Detect partially unavailable catalogues even while a valid peer plan is still on sale. */
class CatalogueQuarantineHealth
{
    /** @return array<string,array{quarantined:int,sellable:int}> Local data only; never reopens sales. */
    public function snapshot(): array
    {
        if (! Schema::hasTable('cloud_plans') || ! app(CloudPricingSnapshot::class)->current()) {
            return [];
        }

        $ids = CloudPricingSnapshot::excludedPlanIds();
        if ($ids === []) {
            return [];
        }

        $quarantined = CloudPlan::query()->whereIntegerInRaw('id', $ids)
            ->where('admin_disabled', false)
            ->whereNotIn('provider', CloudPlan::disabledProviders())
            ->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider');
        if ($quarantined->isEmpty()) {
            return [];
        }

        $sellable = CloudPlan::sellable()->whereIn('provider', $quarantined->keys()->all())
            ->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider');
        $manager = app(CloudManager::class);
        $result = [];
        foreach ($quarantined as $provider => $count) {
            // Full outages have their own alert. A partial failure must not be
            // lost merely because one independently verified peer is still valid.
            $peers = (int) ($sellable[$provider] ?? 0);
            if ($peers > 0 && $manager->driver($provider)?->isConfigured()) {
                $result[$provider] = ['quarantined' => (int) $count, 'sellable' => $peers];
            }
        }
        ksort($result);

        return $result;
    }
}
