<?php

namespace App\Services\Cloud;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use Illuminate\Support\Collection;
use Throwable;

/** Compare customer prices only after the deliverable contracts match. */
class CloudOfferSelection
{
    private ?Collection $images = null;
    private array $imageSets = [];
    private array $capabilities = [];
    private array $prices = [];

    /** Preserve historical slug identities; change the representative only for a proven equivalent. */
    public function representative(Collection $rows, bool $hourly = false): ?CloudPlan
    {
        $best = $rows->first();
        if (! $best instanceof CloudPlan) { return null; }
        foreach ($rows as $candidate) {
            if ($candidate instanceof CloudPlan && $this->sameContract($candidate, $best, true)
                && $this->priceDominates($candidate, $best, $hourly)) {
                $best = $candidate;
            }
        }
        return $best;
    }

    /** CPU/RAM/storage/traffic may improve for Pareto pruning, but their contracts must not change. */
    public function sameContract(CloudPlan $a, CloudPlan $b, bool $exactResources = false): bool
    {
        if ((! is_array($a->network_terms) && $a->network_terms !== null)
            || (! is_array($b->network_terms) && $b->network_terms !== null)) { return false; }
        if ((string) $a->location_code === '' || \App\Models\CloudLocation::canonicalCode((string) $a->location_code) !== \App\Models\CloudLocation::canonicalCode((string) $b->location_code)
            || (string) $a->arch !== (string) $b->arch || (string) $a->cpu_kind !== (string) $b->cpu_kind
            || $a->isMetal() !== $b->isMetal() || (bool) $a->is_interruptible !== (bool) $b->is_interruptible
            || strtolower(trim((string) $a->gpu_model)) !== strtolower(trim((string) $b->gpu_model))
            || (int) $a->gpu_count !== (int) $b->gpu_count || (int) $a->gpu_vram_mb !== (int) $b->gpu_vram_mb
            || (string) $a->disk_type !== (string) $b->disk_type
            || $this->normalize($a->network_terms ?? []) !== $this->normalize($b->network_terms ?? [])
            || $this->billingClass($a) !== $this->billingClass($b)) {
            return false;
        }
        $splitA = (int) $a->data_disk_gb > 0; $splitB = (int) $b->data_disk_gb > 0;
        if ($splitA !== $splitB || ($splitA && ((int) $a->boot_disk_gb !== (int) $b->boot_disk_gb
            || (int) $a->data_disk_gb !== (int) $b->data_disk_gb))) { return false; }
        if ($exactResources) {
            foreach (['vcpu', 'ram_mb', 'disk_gb', 'traffic_gb'] as $field) {
                if ((int) $a->$field !== (int) $b->$field) { return false; }
            }
        }
        $sameProvider = (string) $a->provider === (string) $b->provider;
        $imagesA = $this->imageSet($a); $imagesB = $this->imageSet($b);
        $capsA = $this->capabilities($a); $capsB = $this->capabilities($b);
        if ($imagesA === [] || $imagesA !== $imagesB
            || $capsA === null || $capsB === null || $capsA !== $capsB) { return false; }
        if (! $sameProvider) {
            // No image rows, unmodelled Windows licence terms or unknown
            // capabilities are absence of proof, not equivalent products.
            if ($this->hasWindows($a) || $this->hasWindows($b)) { return false; }
            if (($a->network_terms ?? []) === [] || ($b->network_terms ?? []) === []) { return false; }
        }
        return true;
    }

    /** Both renewal and first payment must be no dearer; setup tradeoffs remain visible. */
    public function priceDominates(CloudPlan $a, CloudPlan $b, bool $hourly = false): bool
    {
        $pa = $this->priceVector($a, $hourly); $pb = $this->priceVector($b, $hourly);
        if ($pa === null || $pb === null || array_keys($pa) !== array_keys($pb)) { return false; }
        $better = false;
        foreach ($pa as $key => $value) {
            if ($value > $pb[$key]) { return false; }
            $better = $better || $value < $pb[$key];
        }
        return $better;
    }

    public function pricesNoWorse(CloudPlan $a, CloudPlan $b): bool
    {
        $pa = $this->priceVector($a, false); $pb = $this->priceVector($b, false);
        if ($pa === null || $pb === null || array_keys($pa) !== array_keys($pb)) { return false; }
        foreach ($pa as $key => $value) { if ($value > $pb[$key]) { return false; } }
        return true;
    }

    private function billingClass(CloudPlan $plan): array
    {
        return ['hourly' => $plan->supportsHourly(), 'monthly_only' => $plan->provider === 'vpstc',
            'cycle_discount' => ! in_array($plan->provider, ['salad', 'lightnode', 'vpstc'], true)];
    }

    private function priceVector(CloudPlan $plan, bool $hourly): ?array
    {
        $key = spl_object_id($plan).':'.($hourly ? 'h' : 'm');
        if (array_key_exists($key, $this->prices)) { return $this->prices[$key]; }
        try {
            if ($hourly) {
                $values = ['hour_irt' => $plan->hourlyIrt(), 'hour_eur' => $plan->hourlyEurCents()];
            } else {
                $irt = (int) $plan->price_irt; $eur = (int) $plan->price_eur_cents;
                $setupIrt = $plan->setupIrt();
                $setupEur = app(CloudPricing::class)->sellEurCents((int) $plan->setup_eur_cents,
                    $plan->provider === 'vpstc' ? 'vpstc' : null);
                if ((int) $plan->setup_eur_cents > 0 && ($setupIrt <= 0 || $setupEur <= 0)) {
                    return $this->prices[$key] = null;
                }
                $values = ['recurring_irt' => $irt, 'first_irt' => $irt + $setupIrt,
                    'recurring_eur' => $eur, 'first_eur' => $eur + $setupEur];
                if ($plan->supportsHourly() && (int) $plan->cost_hour_eur_micro > 0) {
                    $values['hour_irt'] = $plan->hourlyIrt(); $values['hour_eur'] = $plan->hourlyEurCents();
                }
            }
            return $this->prices[$key] = (min($values) > 0 ? $values : null);
        } catch (Throwable) { return $this->prices[$key] = null; }
    }

    private function imageSet(CloudPlan $plan): array
    {
        $key = spl_object_id($plan);
        if (isset($this->imageSets[$key])) { return $this->imageSets[$key]; }
        $this->images ??= CloudImage::query()->usable()->get();
        $out = [];
        foreach ($this->images as $image) {
            if ((string) $image->provider !== (string) $plan->provider
                || ! CloudImage::providerAccessIsAutomated((string) $plan->provider, $image)
                || (filled($image->arch) && filled($plan->arch) && $image->arch !== $plan->arch)
                || (int) $image->min_ram_mb > (int) $plan->ram_mb
                || (int) $image->min_disk_gb > (int) ($plan->boot_disk_gb ?? $plan->disk_gb)) { continue; }
            if ($plan->provider === 'vpstc' && ! str_starts_with((string) $image->provider_ref, $plan->provider_ref.'|')) { continue; }
            if ($plan->provider === 'lightnode') {
                $region = explode('|', (string) $plan->provider_ref)[0];
                if (! str_starts_with((string) $image->provider_ref, $region.'|')
                    || ($image->family === 'windows' && ((int) $plan->vcpu < 2 || (int) $plan->ram_mb < 4096))) { continue; }
            }
            if ($plan->provider === 'arvan' && $image->kind === 'app'
                && (ArvanApplicationImages::imageId((string) $plan->provider_location, (string) $image->provider_ref) === null
                    || (int) $plan->vcpu < ArvanApplicationImages::minCpu((string) $image->family))) { continue; }
            if ($plan->provider === 'salad' && ! SaladClient::imageSupportsGpu((string) $image->provider_ref, (string) $plan->gpu_model)) { continue; }
            $out[] = implode('|', [(string) $image->kind, (string) $image->key, (string) $image->family, (string) $image->version]);
        }
        sort($out, SORT_STRING);
        return $this->imageSets[$key] = array_values(array_unique($out));
    }

    private function hasWindows(CloudPlan $plan): bool
    {
        return collect($this->imageSet($plan))->contains(fn ($key) => str_contains($key, '|windows'));
    }

    private function capabilities(CloudPlan $plan): ?array
    {
        $provider = (string) $plan->provider;
        if (array_key_exists($provider, $this->capabilities)) { return $this->capabilities[$provider]; }
        try {
            $caps = app(CloudManager::class)->capabilitiesForPlan($plan);
            if ($caps === []) { return $this->capabilities[$provider] = null; }
            ksort($caps);
            return $this->capabilities[$provider] = $caps;
        } catch (Throwable) { return $this->capabilities[$provider] = null; }
    }

    private function normalize(array $value): array
    {
        foreach ($value as $key => $item) { if (is_array($item)) { $value[$key] = $this->normalize($item); } }
        if (! array_is_list($value)) { ksort($value); }
        return $value;
    }
}
