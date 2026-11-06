<?php

namespace App\Services\Birthday;

use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Service;
use App\Services\Cloud\CloudPricingSnapshot;

class BirthdayCatalog
{
    /** Public projection only. Never put customer-specific values in the shared catalog cache. */
    public function product(Product $product, Customer $customer, ?string $cycle = null, ?string $country = null): ?array
    {
        if (! $reward = app(BirthdayProgram::class)->current($customer)) return null;
        $cycle ??= (string) $product->cycle;
        $server = $country ? $product->pickOrderServer($country)?->id : $product->server_id;
        $cost = app(BirthdayCosts::class)->forProduct($product, $cycle, $server, 'IRT');
        $regular = $product->priceForCycle($cycle, $country);
        if (! $cost || $regular <= $cost['amount']) return null;

        return ['regular' => $regular, 'price' => $cost['amount'], 'savings' => $regular - $cost['amount'],
            'currency' => 'IRT', 'cycle' => $cycle, 'expires_at' => $reward->expires_at->toIso8601String()];
    }

    public function cloud(CloudPlan $plan, Customer $customer, string $cycle = 'monthly', string $currency = 'IRT'): ?array
    {
        if (! $reward = app(BirthdayProgram::class)->current($customer)) return null;
        $months = Service::monthsIn($cycle);
        if ($months < 1 || $months > 36 || (int) $plan->cost_eur_cents < 1
            || ! app(CloudPricingSnapshot::class)->current((string) $plan->provider)) return null;
        $cost = app(BirthdayCosts::class)->convert((int) $plan->cost_eur_cents * $months, 'EUR', $currency);
        $retailIrt = \App\Http\Controllers\Account\CloudStoreController::priceForCycle($plan, $cycle);
        $regular = $currency === 'IRT' ? $retailIrt : app(BirthdayCosts::class)->convert($retailIrt, 'IRT', $currency);
        if (! $cost || ! $regular || $cost >= $regular) return null;

        return ['regular' => $regular, 'price' => $cost, 'savings' => $regular - $cost,
            'currency' => $currency, 'cycle' => $cycle, 'expires_at' => $reward->expires_at->toIso8601String()];
    }
}
