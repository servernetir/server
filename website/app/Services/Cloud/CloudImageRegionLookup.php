<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;

/** Reuse identical regional image checks within one catalog render only. */
class CloudImageRegionLookup
{
    private array $checks = [];

    public function exists(CloudPlan $offer, string $provider, string $region): bool
    {
        $key = json_encode([(string) $offer->slug, $provider, $region], JSON_THROW_ON_ERROR);

        return $this->checks[$key] ??= CloudPlan::query()->sellable()
            ->where('slug', $offer->slug)->where('provider', $provider)
            ->when($provider === 'lightnode',
                fn ($query) => $query->where('provider_location', 'like', $region.'|%'),
                fn ($query) => $query->where('provider_location', $region))
            ->exists();
    }
}
