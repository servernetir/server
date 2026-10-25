<?php

namespace App\Services\Marketing;

use App\Models\CloudPlan;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\Schema;

class InvoiceContext
{
    /** Explicitly attached to the service line only; setup/add-on/legacy lines remain ineligible. */
    public function attributes(Service $service, string $operation, ?Product $product = null, ?CloudPlan $cloud = null): array
    {
        if (! Schema::hasColumn('invoice_items', 'marketing_context') || $service->billing_mode === 'hourly') {
            return [];
        }
        $cloud ??= $service->cloud_plan_id ? CloudPlan::find($service->cloud_plan_id) : null;
        if (! $product && ! $cloud) {
            $product = Product::where('plan', $service->plan)->where('server_id', $service->server_id)->first();
        }
        if (! $product && ! $cloud) {
            return [];
        }
        $context = ['eligible' => true, 'operation' => $operation, 'product_id' => $product?->id,
            'family' => $cloud ? (filled($cloud->gpu_model) ? 'gpu' : 'vps') : $product?->category, 'plan' => $cloud?->slug ?: $product?->plan,
            'provider' => $cloud?->provider ?: $service->server?->type,
            'country' => $cloud?->location?->country_code ?: $service->server?->country];

        // No currency/cycle guess for supplier cost. Unknown remains unknown and needs bounded subsidy approval.
        return ['marketing_context' => $context];
    }
}
