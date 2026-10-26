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

    private function vpsTcCurrent(array $stored): bool
    {
        $pricing = app(VpsTcPricing::class);
        return $pricing->configured()
            && ($stored['provider_inputs']['vpstc'] ?? null) === $pricing->fingerprint();
    }

    /** Called inside the same transaction as the complete catalog price batch. */
    public function publish(array $rates, array $excludedProviders = [], array $providerInputs = []): void
    {
        if ($rates !== $this->rates()) {
            throw new \RuntimeException('Navasan rates changed before cloud quote publication.');
        }
        $vpstc = app(VpsTcPricing::class);
        // A global refresh can skip disabled/unconfigured suppliers. It cannot
        // bless their old converted costs with today's account inputs or rates.
        $vpstcProof = $vpstc->configured()
            && ($providerInputs['vpstc'] ?? null) === $vpstc->fingerprint();
        if (! $vpstcProof) {
            $excludedProviders[] = 'vpstc';
        }
        Setting::put(self::KEY, json_encode([
            'source' => 'navasan.net', 'rates' => $rates,
            'excluded_providers' => array_values(array_unique($excludedProviders)),
            'provider_inputs' => $vpstcProof ? ['vpstc' => $providerInputs['vpstc']] : [],
            'at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }
}
