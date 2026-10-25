<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\ExchangeRate;
use Illuminate\Support\Facades\DB;

/** Reprice existing products; retire only regionally verified unavailable Arvan offers. */
class CloudCatalogRepricer
{
    // These drivers normalize a non-EUR supplier quote to EUR. Reusing their
    // old EUR result would incorrectly make USD/IRT procurement follow EUR.
    private const NORMALIZED_PROVIDERS = ['lightnode', 'salad', 'ovh', 'arvan'];

    private const SETTING_KEYS = [
        'cloud_margin_pct', 'cloud_margin_pct_salad', 'cloud_margin_pct_ovh',
        'ovh_purchase_enabled', 'ovh_previous_order_checked', 'ovh_catalogue_pricing_hash',
        'pricing_fx_fee_pct', 'cloud_disabled_providers',
        'salad_vcpu_usd_hour', 'salad_ram_gb_usd_hour', 'salad_priority', 'salad_org',
        'ovh_region', 'lightnode_manifest', 'arvan_exclude_promo',
        // Account changes also invalidate quotes collected from that account.
        'salad_api_key', 'ovh_app_key', 'ovh_app_secret', 'ovh_consumer_key',
        'lightnode_api_token', 'arvan_api_token',
    ];

    public function __construct(private CloudManager $manager, private CloudPricing $pricing) {}

    public function run(bool $persist = true): int
    {
        $settings = $this->settings();
        $memo = array_filter(Setting::cached(), fn (string $key) =>
            in_array($key, self::SETTING_KEYS, true)
            || preg_match('/^pricing_fx_fee_pct_[a-z0-9_-]+$/', $key), ARRAY_FILTER_USE_KEY);
        ksort($memo);
        if ($settings !== $memo) {
            throw new \RuntimeException('Pricing settings changed before price refresh; retry with a fresh snapshot.');
        }
        $rates = $this->rates();
        $plans = CloudPlan::query()->where('cost_eur_cents', '>', 0)
            ->whereNotIn('provider', CloudPlan::disabledProviders())->get();
        $nativeCosts = [];
        $skip = [];
        $unavailable = [];

        foreach ($plans->whereIn('provider', self::NORMALIZED_PROVIDERS)->groupBy('provider') as $provider => $group) {
            $active = $group->filter(fn (CloudPlan $plan) => $plan->is_active && ! $plan->admin_disabled);
            $driver = $active->isNotEmpty() ? $this->manager->driver($provider) : null;

            if ($driver === null || ! $driver->isConfigured()) {
                $skip[] = $provider;
                continue;
            }

            // fetchCatalog only reads supplier catalogs/quotes. Do not call
            // syncOne: price refresh must not enable plans or alter inventory.
            $regionalArvan = $provider === 'arvan' && $driver instanceof ArvanClient;
            $catalog = $regionalArvan
                ? $driver->fetchExistingPlanCosts($active->values()->all())
                : $driver->fetchCatalog();
            if (($catalog['ok'] ?? false) !== true || ! is_array($catalog['plans'] ?? null)) {
                throw new \RuntimeException('Price refresh could not read supplier costs: '.$provider);
            }

            $quotes = $this->indexQuotes($catalog['plans'], $provider);
            if ($regionalArvan) {
                if (! is_array($catalog['unavailable'] ?? null)) {
                    throw new \RuntimeException('Arvan regional availability evidence is missing.');
                }
                foreach ($catalog['unavailable'] as $identity) {
                    if (! is_array($identity)) {
                        throw new \RuntimeException('Invalid unavailable Arvan product identity.');
                    }
                    $key = $this->key((string) ($identity['provider_ref'] ?? ''), (string) ($identity['location_code'] ?? ''));
                    $matches = $active->filter(fn (CloudPlan $plan) => $this->key($plan->provider_ref, $plan->location_code) === $key
                        && $plan->provider_location === ($identity['provider_location'] ?? null));
                    if ($matches->count() !== 1 || isset($quotes[$key]) || isset($unavailable[$matches->first()->id])) {
                        throw new \RuntimeException('Unverified or conflicting unavailable Arvan product.');
                    }
                    $unavailable[$matches->first()->id] = true;
                }
            }
            if ($provider === 'lightnode' && $driver instanceof LightNodeClient) {
                $missing = $active->filter(fn (CloudPlan $plan) =>
                    ! isset($quotes[$this->key($plan->provider_ref, $plan->location_code)]));
                if ($missing->isNotEmpty()) {
                    // Public quote reads can transiently omit individual plans.
                    // Retry only those exact products, with fresh quotes and specs.
                    $retry = $driver->fetchExistingPlanCosts($missing->values()->all());
                    if (($retry['ok'] ?? false) !== true || ! is_array($retry['plans'] ?? null)) {
                        throw new \RuntimeException('Price refresh could not read supplier costs: '.$provider);
                    }
                    $quotes += $this->indexQuotes($retry['plans'], $provider);
                }
            }

            foreach ($active as $plan) {
                if (isset($unavailable[$plan->id])) {
                    continue;
                }
                $row = $quotes[$this->key($plan->provider_ref, $plan->location_code)] ?? null;
                if (! is_array($row)) {
                    throw new \RuntimeException('Price refresh is missing an existing supplier plan: '.$provider);
                }
                if ($regionalArvan && ($row['provider_location'] ?? null) !== $plan->provider_location) {
                    throw new \RuntimeException('Arvan price belongs to a different region.');
                }
                $cost = $this->amount($row['cost_eur_cents'] ?? null, false, $provider);
                $hourly = $row['cost_hour_eur_micro'] ?? null;
                if ($hourly === null && (int) $plan->cost_hour_eur_micro > 0) {
                    throw new \RuntimeException('Price refresh is missing an hourly supplier cost: '.$provider);
                }
                $setup = $row['setup_eur_cents'] ?? null;
                if ($setup === null && (int) $plan->setup_eur_cents > 0) {
                    throw new \RuntimeException('Price refresh is missing a supplier setup cost: '.$provider);
                }
                $nativeCosts[$plan->id] = [
                    'cost_eur_cents' => $cost,
                    'cost_hour_eur_micro' => $hourly === null ? null : $this->amount($hourly, false, $provider),
                    'setup_eur_cents' => $this->amount($setup ?? 0, true, $provider),
                ];
            }
        }

        // All required quotes are validated before the first product write.
        // Any database failure rolls back the complete product-price batch.
        $this->assertRates($rates);
        $this->assertSettings($settings);
        $apply = function () use ($plans, $nativeCosts, $skip, $unavailable, $persist, $rates, $settings): int {
            // Current reads avoid a MariaDB repeatable-read snapshot; locks last
            // only for product writes, never for slow supplier requests.
            $this->assertSettings($settings, $persist);
            $count = 0;
            foreach (CloudPlan::query()->whereIn('id', $plans->modelKeys())
                ->when($persist, fn ($query) => $query->lockForUpdate())->get() as $plan) {
                if (in_array($plan->provider, $skip, true)
                    || CloudPlan::providerIsDisabled($plan->provider)
                    || (in_array($plan->provider, self::NORMALIZED_PROVIDERS, true)
                        && (! $plan->is_active || $plan->admin_disabled))) {
                    continue;
                }
                $old = $plans->firstWhere('id', $plan->id);
                if ($plan->getRawOriginal() !== $old->getRawOriginal()) {
                    throw new \RuntimeException('Catalog changed during price refresh; retry with a fresh snapshot.');
                }
                if (isset($unavailable[$plan->id])) {
                    // Keep the row, its last price and all existing services.
                    // The exact flavor is absent from its original region;
                    // another region's quote must never make it sellable here.
                    $plan->in_stock = false;
                    if ($plan->isDirty()) {
                        if ($persist) { $plan->save(); }
                        $count++;
                    }
                    continue;
                }
                $costs = $nativeCosts[$plan->id] ?? [];
                $cost = $costs['cost_eur_cents'] ?? (int) $plan->cost_eur_cents;
                $price = $this->pricing->priceFor($cost, $plan->provider);
                if ($price['eur_cents'] <= 0 || $price['irt'] <= 0) {
                    throw new \RuntimeException('Price refresh produced an invalid sale price.');
                }
                if ($cost !== (int) $plan->cost_eur_cents) {
                    $costs['previous_cost_eur_cents'] = (int) $plan->cost_eur_cents;
                    $costs['cost_changed_at'] = now();
                }
                $plan->fill($costs + ['price_eur_cents' => $price['eur_cents'], 'price_irt' => $price['irt']]);
                if ($plan->isDirty()) {
                    if ($persist) {
                        $plan->save();
                    }
                    $count++;
                }
            }
            $this->assertRates($rates);
            $this->assertSettings($settings, $persist);
            if ($persist) {
                app(CloudPricingSnapshot::class)->publish($rates, $skip);
            }
            return $count;
        };

        return $persist ? DB::transaction($apply) : $apply();
    }

    private function settings(bool $lock = false): array
    {
        // Setting::get/cached keeps a request memo: an admin edit in another
        // request must be detected without mixing new settings into old quotes.
        // The dynamic key family covers provider-specific transfer fees.
        return DB::table('settings')->where(function ($query) {
            $query->whereIn('key', self::SETTING_KEYS)->orWhere('key', 'like', 'pricing_fx_fee_pct_%');
        })->orderBy('key')->when($lock, fn ($query) => $query->lockForUpdate())
            ->pluck('value', 'key')->all();
    }

    private function assertSettings(array $settings, bool $lock = false): void
    {
        if ($settings !== $this->settings($lock)) {
            throw new \RuntimeException('Pricing settings changed during price refresh; retry with a fresh snapshot.');
        }
    }

    private function rates(): array
    {
        $fx = app(ExchangeRate::class);
        return array_map(fn (string $currency) => $fx->current($currency)['rate_toman'] ?? null,
            ['USD', 'EUR', 'USDT']);
    }

    private function assertRates(array $rates): void
    {
        if ($rates !== $this->rates()) {
            throw new \RuntimeException('Exchange rates changed during price refresh; retry with a fresh snapshot.');
        }
    }

    private function key(string $reference, string $location): string
    {
        return json_encode([$reference, $location], JSON_THROW_ON_ERROR);
    }

    private function indexQuotes(array $rows, string $provider): array
    {
        $quotes = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new \RuntimeException('Price refresh received an invalid supplier row: '.$provider);
            }
            $key = $this->key((string) ($row['provider_ref'] ?? ''), (string) ($row['location_code'] ?? ''));
            if (isset($quotes[$key])) {
                throw new \RuntimeException('Price refresh received duplicate supplier rows: '.$provider);
            }
            $quotes[$key] = $row;
        }
        return $quotes;
    }

    private function amount(mixed $value, bool $zeroAllowed, string $provider): int
    {
        if (! is_numeric($value) || ! is_finite((float) $value)
            || (float) $value !== floor((float) $value)
            || (float) $value < ($zeroAllowed ? 0 : 1) || (float) $value > PHP_INT_MAX) {
            throw new \RuntimeException('Price refresh received an invalid supplier amount: '.$provider);
        }
        return (int) $value;
    }
}
