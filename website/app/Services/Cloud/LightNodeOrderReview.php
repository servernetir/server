<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Refresh only the reviewed offer; price changes require customer reconfirmation. */
class LightNodeOrderReview
{
    public function __construct(private LightNodeClient $client, private CloudPricing $pricing) {}

    private function settings(bool $lock = false): array
    {
        return DB::table('settings')->whereIn('key', [
            'cloud_margin_pct', 'pricing_fx_fee_pct', 'pricing_fx_fee_pct_lightnode',
            'cloud_disabled_providers',
            'fx_last_usd', 'fx_last_eur',
        ])->orderBy('key')->when($lock, fn ($q) => $q->lockForUpdate())->pluck('value', 'key')->all();
    }

    public function review(CloudPlan $plan, string $imageKey): array
    {
        $fail = ['ok' => false, 'changed' => false];
        try {
            $snapshot = $this->settings();
            foreach (['cloud_margin_pct', 'pricing_fx_fee_pct', 'pricing_fx_fee_pct_lightnode', 'cloud_disabled_providers'] as $key) {
                if (($snapshot[$key] ?? null) !== Setting::get($key)) {
                    return $fail;
                }
            }
            $review = $this->client->reviewPurchase($plan, $imageKey);
            if (! ($review['ok'] ?? false)) {
                return $fail;
            }
            $cost = $review['cost'];
            $price = $this->pricing->priceFor($cost['cost_eur_cents'], 'lightnode');
            if ($price['irt'] <= 0 || $this->settings() !== $snapshot) {
                return $fail;
            }
            $changed = DB::transaction(function () use ($plan, $snapshot, $cost, $price) {
                $locked = CloudPlan::query()->sellable()->where('provider', 'lightnode')->whereKey($plan->id)->lockForUpdate()->first();
                if ($locked === null || $this->settings(true) !== $snapshot
                    || $locked->provider_ref !== $plan->provider_ref
                    || $locked->provider_location !== $plan->provider_location
                    || $locked->network_terms !== $plan->network_terms
                    || $locked->only(['vcpu', 'ram_mb', 'disk_gb', 'boot_disk_gb', 'data_disk_gb', 'cpu_kind', 'slug'])
                        !== $plan->only(['vcpu', 'ram_mb', 'disk_gb', 'boot_disk_gb', 'data_disk_gb', 'cpu_kind', 'slug'])) {
                    throw new \RuntimeException('Quote changed during review');
                }
                $changed = (int) $locked->price_irt !== $price['irt'] || (int) $locked->price_eur_cents !== $price['eur_cents']
                    || (int) $locked->cost_hour_eur_micro !== $cost['cost_hour_eur_micro'];
                $locked->fill($cost + ['price_irt' => $price['irt'], 'price_eur_cents' => $price['eur_cents']]);
                if ($locked->isDirty()) {
                    $locked->save();
                }
                return $changed;
            });
            $plan->refresh();
            return ['ok' => true, 'changed' => $changed];
        } catch (\Throwable) {
            return $fail;
        }
    }
}
