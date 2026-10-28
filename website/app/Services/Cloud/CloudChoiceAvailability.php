<?php

namespace App\Services\Cloud;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use Illuminate\Support\Collection;

/** Display explanations only; never grants delivery or purchasing eligibility. */
class CloudChoiceAvailability
{
    public function imageReason(CloudPlan $offer, Collection $images, Collection $plans): string
    {
        $pairs = collect();
        foreach ($images as $image) {
            foreach ($plans as $plan) {
                if ($plan->provider !== $image->provider) { continue; }
                if ($plan->provider === 'vpstc' && ! str_starts_with((string) $image->provider_ref, $plan->provider_ref.'|')) { continue; }
                if ($plan->provider === 'lightnode' && explode('|', (string) $image->provider_ref)[0] !== explode('|', (string) $plan->provider_ref)[0]) { continue; }
                if ($plan->provider === 'arvan' && $image->kind === 'app'
                    && ArvanApplicationImages::imageId((string) $plan->provider_location, (string) $image->provider_ref) === null) { continue; }
                if (! CloudImage::providerAccessIsAutomated((string) $plan->provider, $image)) { continue; }
                if ($plan->provider === 'salad' && (SaladClient::salesPaused((string) $image->key)
                    || ! SaladClient::imageSupportsGpu((string) $image->provider_ref, (string) $plan->gpu_model))) { continue; }
                $pairs->push([$plan, $image]);
            }
        }
        if ($pairs->isEmpty()) { return __('ui.choice_image_unavailable'); }
        $pairs = $pairs->filter(fn ($p) => ! filled($p[1]->arch) || ! filled($p[0]->arch) || $p[1]->arch === $p[0]->arch);
        if ($pairs->isEmpty()) { return __('ui.choice_arch'); }
        $ram = fn ($p) => max((int) $p[1]->min_ram_mb, $p[0]->provider === 'lightnode' && $p[1]->family === 'windows' ? 4096 : 0);
        $minimum = $pairs->map($ram)->min();
        $next = $pairs->filter(fn ($p) => $ram($p) <= (int) $p[0]->ram_mb);
        if ($next->isEmpty()) { return __('ui.choice_ram', ['amount' => fa_num($minimum)]); }
        $pairs = $next;
        $minimum = $pairs->min(fn ($p) => (int) $p[1]->min_disk_gb);
        $next = $pairs->filter(fn ($p) => (int) $p[1]->min_disk_gb <= (int) ($p[0]->boot_disk_gb ?? $p[0]->disk_gb));
        if ($next->isEmpty()) { return __('ui.choice_disk', ['amount' => fa_num($minimum)]); }
        $pairs = $next;
        $cpu = fn ($p) => $p[0]->provider === 'lightnode' && $p[1]->family === 'windows' ? 2
            : ($p[0]->provider === 'arvan' && $p[1]->kind === 'app' ? ArvanApplicationImages::minCpu((string) $p[1]->family) : 0);
        if ($pairs->every(fn ($p) => $cpu($p) > (int) $p[0]->vcpu)) {
            return __('ui.choice_cpu', ['amount' => fa_num($pairs->map($cpu)->min())]);
        }
        // Other delivery restrictions stay generic rather than inventing a cause.
        return __('ui.choice_image_unavailable');
    }
}
