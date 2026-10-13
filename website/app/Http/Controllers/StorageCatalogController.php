<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ObjectStorage\HetznerObjectCosts;
use App\Services\Provisioning\HetznerStorageCosts;
use Illuminate\View\View;

class StorageCatalogController extends Controller
{
    public function show(string $kind): View
    {
        abort_unless(in_array($kind, ['storage-box', 'object-storage'], true), 404);
        $costs = $kind === 'storage-box' ? app(HetznerStorageCosts::class) : app(HetznerObjectCosts::class);
        $rows = Product::query()->with('server')->where('group', $kind)->orderBy('sort')->get()
            ->map(function (Product $product) use ($costs) {
                $meta = $costs->metadataForPlan((string) $product->plan);
                if (! $meta) return null;
                $quote = $product->storageQuote();
                $ready = $product->is_active && $product->storageConfigurationError() === null
                    && $product->availableCountries() !== [] && ($quote['irt'] ?? 0) > 0;
                return compact('product', 'meta', 'quote', 'ready');
            })->filter()->values();
        $locations = $rows->pluck('meta.location')->filter()->unique()->sort()->values();
        $selected = (string) request('location', $locations->first() ?? '');
        if (! $locations->contains($selected)) $selected = (string) ($locations->first() ?? '');
        return view('pages.storage-catalog', [
            'kind' => $kind, 'rows' => $rows->where('meta.location', $selected)->values(),
            'locations' => $locations, 'selected' => $selected,
        ]);
    }
}
