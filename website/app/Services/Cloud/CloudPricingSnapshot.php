<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use App\Services\ExchangeRate;

/** A stored cloud quote is sellable only for the Navasan rates that priced it. */
class CloudPricingSnapshot
{
    public const KEY = 'cloud_pricing_navasan_snapshot';

    public function rates(): ?array
    {
        $rates = [];
        foreach (['USD', 'EUR', 'USDT'] as $currency) {
            $rate = app(ExchangeRate::class)->current($currency)['rate_toman'] ?? null;
            if (! is_int($rate) || $rate <= 0) {
                return null;
            }
            $rates[] = $rate;
        }
        return $rates;
    }

    public function current(?string $provider = null): bool
    {
        $rates = $this->rates();
        $stored = json_decode((string) Setting::get(self::KEY, ''), true);

        return $rates !== null && is_array($stored)
            && $this->validPlanExclusions($stored['excluded_plans'] ?? [])
            && ($stored['source'] ?? null) === 'navasan.net'
            && ($stored['rates'] ?? null) === $rates
            && ($provider === null || ! in_array($provider, $stored['excluded_providers'] ?? [], true))
            && ($provider !== 'vpstc' || $this->vpsTcCurrent($stored));
    }

    public function excludedProviders(): array
    {
        $stored = json_decode((string) Setting::get(self::KEY, ''), true);
        $excluded = is_array($stored['excluded_providers'] ?? null) ? $stored['excluded_providers'] : [];
        if (! is_array($stored) || ! $this->vpsTcCurrent($stored)) {
            $excluded[] = 'vpstc';
        }
        return array_values(array_unique($excluded));
    }

    /** Safe reason codes only; never raw provider errors or credentials. */
    public function exclusionReasons(): array
    {
        $stored = json_decode((string) Setting::get(self::KEY, ''), true);
        return is_array($stored['exclusion_reasons'] ?? null) ? $stored['exclusion_reasons'] : [];
    }

    /** Preserve peer quotes when a catalogue read fails without changing stock or admin decisions. */
    public function excludeProvider(string $provider, string $reason): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($provider, $reason) {
            $value = \Illuminate\Support\Facades\DB::table('settings')->where('key', self::KEY)->lockForUpdate()->value('value');
            $stored = json_decode((string) $value, true);
            if (! is_array($stored)) { return; } // Missing snapshot already fails closed.
            $stored['excluded_providers'] = array_values(array_unique([...(array) ($stored['excluded_providers'] ?? []), $provider]));
            $stored['exclusion_reasons'][$provider] = $reason;
            Setting::put(self::KEY, json_encode($stored, JSON_THROW_ON_ERROR));
        });
    }

    /** Quarantine exact products without changing supplier or manual switches. */
    public function excludePlans(array $ids): void
    {
        if (! $this->validPlanExclusions($ids)) {
            throw new \InvalidArgumentException('Invalid product quarantine identities.');
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($ids) {
            $value = \Illuminate\Support\Facades\DB::table('settings')->where('key', self::KEY)->lockForUpdate()->value('value');
            $stored = json_decode((string) $value, true);
            if (! is_array($stored)) { return; } // A missing snapshot already blocks sales.
            $merged = array_values(array_unique([...(array) ($stored['excluded_plans'] ?? []), ...$ids]));
            if (! $this->validPlanExclusions($merged)) {
                throw new \RuntimeException('Invalid product quarantine snapshot.');
            }
            $stored['excluded_plans'] = $merged;
            Setting::put(self::KEY, json_encode($stored, JSON_THROW_ON_ERROR));
        });
    }

    /** Read-only plan quarantine; old snapshots without this field remain compatible. */
    public static function excludedPlanIds(): array
    {
        $stored = json_decode((string) Setting::get(self::KEY, ''), true);
        $ids = $stored['excluded_plans'] ?? [];
        return is_array($ids) && array_is_list($ids)
            && count($ids) <= 50000 && collect($ids)->every(fn ($id) => is_int($id) && $id > 0)
            ? array_values(array_unique($ids)) : [];
    }

    private function validPlanExclusions(mixed $ids): bool
    {
        return is_array($ids) && array_is_list($ids) && count($ids) <= 50000
            && collect($ids)->every(fn ($id) => is_int($id) && $id > 0);
    }

    private function vpsTcCurrent(array $stored): bool
    {
        $pricing = app(VpsTcPricing::class);
        return $pricing->configured()
            && ($stored['provider_inputs']['vpstc'] ?? null) === $pricing->fingerprint();
    }

    /** Called inside the same transaction as the complete catalog price batch. */
    public function publish(array $rates, array $excludedProviders = [], array $providerInputs = [], ?array $excludedPlans = null, ?array $exclusionReasons = null): void
    {
        if ($rates !== $this->rates()) {
            throw new \RuntimeException('Navasan rates changed before cloud quote publication.');
        }
        $excludedPlans ??= self::excludedPlanIds();
        if (! $this->validPlanExclusions($excludedPlans)) {
            throw new \RuntimeException('Invalid excluded plan identities.');
        }
        $vpstc = app(VpsTcPricing::class);
        // A global refresh can skip disabled/unconfigured suppliers. It cannot
        // bless their old converted costs with today's account inputs or rates.
        $vpstcProof = $vpstc->configured()
            && ($providerInputs['vpstc'] ?? null) === $vpstc->fingerprint();
        if (! $vpstcProof) {
            $excludedProviders[] = 'vpstc';
        }
        $exclusionReasons ??= $this->exclusionReasons();
        $exclusionReasons = array_intersect_key($exclusionReasons, array_flip($excludedProviders));
        Setting::put(self::KEY, json_encode([
            'source' => 'navasan.net', 'rates' => $rates,
            'excluded_providers' => array_values(array_unique($excludedProviders)),
            'excluded_plans' => array_values(array_unique($excludedPlans)),
            'exclusion_reasons' => $exclusionReasons,
            'provider_inputs' => $vpstcProof ? ['vpstc' => $providerInputs['vpstc']] : [],
            'at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }
}
