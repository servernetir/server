<?php

namespace App\Services\Cloud;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Support\Facades\Schema;

/** Real country stock for public marketing and sitemaps, independent of visitor access. */
class CountryVpsEligibility
{
    /** @return array<string, true> */
    public function countries(): array
    {
        if (! Schema::hasTable('cloud_locations') || ! Schema::hasTable('cloud_plans')) {
            return [];
        }

        // A raw sellable row is insufficient: bare metal, GPU locations and
        // unusable monthly quotes are not VPS shelves. Never use gated rows here.
        $monthlyQuotes = app(CloudPublicQuote::class);
        $sellableLocs = CloudPlan::query()->sellable()->get()
            ->filter(fn (CloudPlan $plan) => ! $plan->isMetal()
                && $monthlyQuotes->monthlyPrice($plan) > 0)
            ->pluck('location_code')->unique()->all();

        $countries = [];
        foreach (CloudLocation::where('is_active', true)
            ->whereIn('code', $sellableLocs)->get(['code', 'country']) as $location) {
            if (CloudLocation::isGpuCode((string) $location->code)) {
                continue;
            }
            $iso = strtoupper(trim((string) $location->country));
            if (CloudCountry::marketingSlug($iso) !== null) {
                $countries[$iso] = true;
            }
        }

        return $countries;
    }
}
