<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Public catalog prices derived only from native product prices and Navasan. */
class CatalogPricing
{
    private const TTL = 300;

    /** @return array<string, array<string, mixed>> */
    public function map(): array
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'base_price_eur')) {
            return [];
        }

        return Cache::remember('catalog.prices', self::TTL, function () {
            return Product::query()->where('is_active', true)->get()
                ->mapWithKeys(fn (Product $product) => [$product->slug => $this->quote($product)])
                ->all();
        });
    }

    /** A price that can be passed directly to site_price(). */
    public function quote(Product $product): array
    {
        $monthly = $product->monthlyEquivalent((string) $product->cycle);
        $yearly = $product->monthlyEquivalent('yearly');

        if ($monthly <= 0 || $yearly <= 0) {
            return ['contact' => true, 'quote' => true];
        }

        return [
            'irt' => $monthly,
            'yearly_irt' => $yearly,
            'final_irt' => true,
        ];
    }

    /** Cheapest live monthly equivalent in a product group. */
    public function minimumQuote(string $group): array
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'base_price_eur')) {
            return ['contact' => true, 'quote' => true];
        }

        $quotes = Product::query()->where('is_active', true)->where('group', $group)->get()
            ->map(fn (Product $product) => $this->quote($product))
            ->filter(fn (array $quote) => (int) ($quote['irt'] ?? 0) > 0);

        return $quotes->sortBy('irt')->first() ?? ['contact' => true, 'quote' => true];
    }

    /** Replace config placeholders. Missing database products become unavailable. */
    public function applyToPlans(string $group, array $plans): array
    {
        $map = $this->map();

        foreach ($plans as $i => $plan) {
            $slug = $group.'-'.($i + 1);
            unset($plans[$i]['irt'], $plans[$i]['eur'], $plans[$i]['base_eur'], $plans[$i]['yearly_irt']);
            $plans[$i] += $map[$slug] ?? ['contact' => true, 'quote' => true];
        }

        return $plans;
    }

    public static function forget(): void
    {
        Cache::forget('catalog.prices');
    }
}
