<?php

namespace App\Services\Cloud;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Customer\IranSalesGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/** A country/city shelf using the same monthly and hourly representatives as checkout. */
class PublicVpsCatalog
{
    public function rows(?string $country = null, ?Collection $offers = null, ?Collection $hourlyOffers = null, ?Collection $locations = null, array $hourlyRates = []): array
    {
        if (($offers === null || $locations === null)
            && (! Schema::hasTable('cloud_plans') || ! Schema::hasTable('cloud_locations'))) {
            return [];
        }
        $locations ??= CloudLocation::where('is_active', true)
            ->when($country, fn ($q) => $q->where('country', $country))->get()->keyBy('code');
        $hourlyOffers ??= CloudPlan::offers(hourly: true);
        $offers ??= CloudPlan::offers();
        // Pareto pruning keeps the established sales policy. Its contract check
        // includes the canonical city, so one city can never remove another.
        $iran = $offers->filter(fn ($p) => strtoupper(substr($p->location_code, 0, 2)) === 'IR');
        $offers = $iran->merge(CloudDominance::prune($offers->reject(fn ($p) => strtoupper(substr($p->location_code, 0, 2)) === 'IR')));
        $quoteService = app(CloudPublicQuote::class);
        $rows = [];
        foreach ($offers as $plan) {
            $loc = $locations->get($plan->location_code);
            if (! $loc || $loc->country === 'XX' || $plan->isMetal()
                || CloudLocation::isGpuCode($loc->code)) {
                continue;
            }
            // The deployed visibility policy also covers authenticated foreign visitors.
            if (class_exists(\App\Services\Customer\IranSalesVisibility::class)
                && ! \App\Services\Customer\IranSalesVisibility::allowsCountry($loc->country)) {
                continue;
            }
            $quote = $quoteService->quote($plan);
            $monthly = (int) ($quote['irt'] ?? 0);
            if ($monthly <= 0) {
                continue;
            }
            $hourlyPlan = $hourlyOffers->get($plan->slug);
            $hourly = $hourlyPlan && ! $hourlyPlan->isMetal()
                ? ($hourlyRates[(int) $hourlyPlan->getKey()] ?? $hourlyPlan->hourlyIrt()) : 0;
            $hourlyLabel = $hourly > 0 ? (app()->getLocale() === 'fa' ? cloud_price($hourly)
                : '€'.rtrim(rtrim(number_format($hourlyPlan->hourlyEurCents() / 100, 3, '.', ''), '0'), '.')) : null;
            $base = lroute('account.cloud.store').'?'.http_build_query([
                'location' => CloudLocation::canonicalCode($loc->code), 'plan' => $plan->slug,
            ], '', '&', PHP_QUERY_RFC3986);
            $restricted = $loc->country === 'IR' && IranSalesGate::blocksIranFor(Auth::guard('customer')->user());
            $rows[] = [
                'slug' => $plan->slug, 'name' => $plan->public_name,
                'country' => $loc->country, 'country_label' => $loc->countryLabel(),
                'flag' => $loc->flagEmoji(), 'city' => $loc->cityLabel(),
                'location' => CloudLocation::canonicalCode($loc->code),
                'cpu' => (int) $plan->vcpu, 'cpu_kind' => $plan->cpuKindLabel(), 'arch' => strtoupper((string) $plan->arch),
                'ram_mb' => (int) $plan->ram_mb, 'ram' => $plan->ramLabel(),
                'disk' => $plan->diskLabel(), 'traffic' => $plan->trafficLabel(),
                'monthly' => $monthly, 'monthly_label' => cloud_price($monthly),
                'hourly' => $hourly, 'hourly_label' => $hourlyLabel,
                'unavailable_label' => __('vps-catalog.'.(isset($quote['yearly_irt']) ? 'hourly_unavailable' : 'monthly_only')),
                'setup_label' => $plan->setupIrt() > 0 ? cloud_price($plan->setupIrt()) : null,
                'monthly_url' => $restricted ? null : $base.'&cycle=monthly',
                'hourly_url' => $hourly > 0 && ! $restricted ? $base.'&billing_mode=hourly' : null,
            ];
        }
        usort($rows, fn ($a, $b) => [$a['monthly'], $a['country'], $a['city'], $a['cpu'], $a['ram_mb'], $a['slug']]
            <=> [$b['monthly'], $b['country'], $b['city'], $b['cpu'], $b['ram_mb'], $b['slug']]);

        return $rows;
    }
}
