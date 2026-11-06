<?php

namespace App\Services\Cloud;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use Illuminate\Support\Collection;
use Throwable;

/** Compare customer prices only after the deliverable contracts match. */
class CloudOfferSelection
{
    private ?Collection $imagesByProvider = null;
    private array $imageFactsByProvider = [];
    private array $regionalImages = [];
    private array $imageSets = [];
    private array $capabilities = [];
    private array $prices = [];

    /** Preserve historical slug identities; change the representative only for a proven equivalent. */
    public function representative(Collection $rows, bool $hourly = false): ?CloudPlan
    {
        $best = $rows->first();
        if (! $best instanceof CloudPlan) { return null; }
        foreach ($rows as $candidate) {
            // Comparing an offer to itself cannot select another supplier.
            if ($candidate === $best) { continue; }
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
        // Plan inputs are fixed for this predicate call; do not resolve the
        // same Eloquent attributes for every image in the catalogue.
        $provider = (string) $plan->provider;
        $reference = (string) $plan->provider_ref;
        $arch = $plan->arch;
        $ram = (int) $plan->ram_mb;
        $bootDiskValue = $plan->boot_disk_gb;
        $bootDisk = (int) ($bootDiskValue ?? $plan->disk_gb);
        $cpu = (int) $plan->vcpu;
        $location = (string) $plan->provider_location;
        $gpu = (string) $plan->gpu_model;
        $prefix = match ($provider) {
            'lightnode' => explode('|', $reference)[0].'|',
            'vpstc' => $reference.'|',
            default => null,
        };
        $out = [];
        foreach ($this->candidateImages($provider, $prefix) as $image) {
            if ($image['provider'] !== $provider
                // This access gate remains live and precedes compatibility checks.
                || ! CloudImage::providerAccessIsAutomated($provider, $image['model'])
                || (filled($image['arch']) && filled($arch) && $image['arch'] !== $arch)
                || $image['min_ram'] > $ram
                || $image['min_disk'] > $bootDisk) { continue; }
            if ($provider === 'vpstc' && ! str_starts_with($image['reference'], $prefix)) { continue; }
            if ($provider === 'lightnode') {
                if (! str_starts_with($image['reference'], $prefix)
                    || ($image['family'] === 'windows' && ($cpu < 2 || $ram < 4096))) { continue; }
            }
            if ($provider === 'arvan' && $image['kind'] === 'app'
                && (ArvanApplicationImages::imageId($location, $image['reference']) === null
                    || $cpu < ArvanApplicationImages::minCpu((string) $image['family']))) { continue; }
            if ($provider === 'salad' && ! SaladClient::imageSupportsGpu($image['reference'], $gpu)) { continue; }
            $out[] = $image['key'];
        }
        sort($out, SORT_STRING);
        return $this->imageSets[$key] = array_values(array_unique($out));
    }

    /** Narrow the once-loaded image catalogue before applying the full contract predicate. */
    private function candidateImages(string $provider, ?string $prefix): Collection
    {
        $this->imagesByProvider ??= CloudImage::query()->usable()->get()
            ->groupBy(fn ($image) => (string) $image->provider);
        // Snapshot scalar fields only for providers this selection visits;
        // unrelated suppliers need no additional attribute resolution.
        $images = $this->imageFactsByProvider[$provider] ??= $this->imagesByProvider->get($provider, collect())
            ->map(static fn (CloudImage $image) => [
                'model' => $image,
                'provider' => (string) $image->provider,
                'reference' => (string) $image->provider_ref,
                'arch' => $image->arch,
                'min_ram' => (int) $image->min_ram_mb,
                'min_disk' => (int) $image->min_disk_gb,
                'kind' => $image->kind,
                'family' => $image->family,
                'key' => implode('|', [(string) $image->kind, (string) $image->key, (string) $image->family, (string) $image->version]),
            ]);
        if ($prefix === null) {
            return $images;
        }

        // LightNode uses a region prefix; VPS.TC's prefix is the entire
        // product reference and can itself contain a separator. Keep those
        // existing prefix semantics, including empty/malformed references.
        return $this->regionalImages[$provider][$prefix] ??= $images
            ->filter(fn ($image) => str_starts_with($image['reference'], $prefix))
            ->values();
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
