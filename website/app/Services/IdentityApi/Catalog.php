<?php

namespace App\Services\IdentityApi;

use App\Models\IdentityPrice;
use App\Models\Customer;

class Catalog
{
    public function all(): array
    {
        return json_decode(file_get_contents(resource_path('identity/catalog.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function find(string $slug): array
    {
        foreach ($this->all() as $s) {
            if ($s['slug'] === $slug) {
                return $s;
            }
        }
        throw new IdentityError('service_not_found', 404);
    }

    public function price(string $slug, ?Controls $controls = null): ?IdentityPrice
    {
        if (! ($controls ?? app(Controls::class))->ready()) {
            return null;
        }

        return IdentityPrice::where('service', $slug)->where('effective_at', '<=', now())
            ->orderByDesc('effective_at')->orderByDesc('id')->first();
    }

    public function publicList(): array
    {
        $controls = app(Controls::class);
        $enabled = $controls->dispatchEnabled() && $controls->fundingReady();

        return array_map(function ($s) use ($controls, $enabled) {
            $price = $this->price($s['slug'], $controls);
            $active = $enabled && ! $s['contract_blocker'] && $price && $price->valid_until->isFuture()
                && $controls->get('service:'.$s['slug'], false) === true;
            $quote = null;
            if ($active) {
                try {
                    $quote = app(Pricing::class)->quote($price);
                } catch (IdentityError) {
                    $active = false;
                }
            }
            $input = $s['contract_blocker'] ? null : $s['operations'][0]['input'];
            if (isset($input['properties']['birth_date'])) {
                unset($input['properties']['birth_date']['format']);
                $input['properties']['birth_date']['x-calendar'] = 'persian';
            }

            return ['slug' => $s['slug'], 'name' => __('identity.service_'.$s['slug']), 'category' => $s['category'], 'active' => (bool) $active,
                'status' => $active ? 'available' : 'unavailable', 'inputs' => $input,
                'price' => $active ? $quote['sell_irt'] : null, 'price_version' => $active ? $price->id : null, 'currency' => 'IRT'];
        }, $this->all());
    }

    /** A purchase quote exposes customer amounts only, never wholesale costs or evidence. */
    public function checkoutQuote(string $slug, ?Customer $customer = null): array
    {
        $service = $this->find($slug);
        $controls = app(Controls::class);
        $price = $this->price($slug, $controls);
        if ($service['contract_blocker'] || ! $controls->dispatchEnabled() || ! $controls->fundingReady()
            || $controls->get('service:'.$slug, false) !== true || ! $price) {
            throw new IdentityError('service_unavailable', 503);
        }
        $q = app(Pricing::class)->quote($price, $customer);

        return ['service' => $slug, 'currency' => 'IRT', 'price_version' => $q['version'],
            'subtotal_irt' => $q['sell_irt'], 'tax_bps' => $q['tax_bps'], 'tax_irt' => $q['tax_irt'],
            'total_irt' => $q['total_irt'], 'quoted_at' => $q['quoted_at']];
    }
}
