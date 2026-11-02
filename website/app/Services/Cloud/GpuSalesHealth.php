<?php

namespace App\Services\Cloud;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudPlan;
use Illuminate\Support\Facades\Schema;

/** Probe the real public offer and deliverable-app rules; never contact a supplier. */
class GpuSalesHealth
{
    public function snapshot(): array
    {
        if (! Schema::hasTable('cloud_plans') || ! Schema::hasColumn('cloud_plans', 'gpu_model')) {
            return ['enabled' => false, 'ok' => true, 'offers' => 0, 'reason' => 'not_installed'];
        }
        $known = CloudPlan::whereNotNull('gpu_model')->where('gpu_model', '!=', '')->exists();
        if (! $known && ! app(SaladClient::class)->isConfigured()) {
            return ['enabled' => false, 'ok' => true, 'offers' => 0, 'reason' => 'not_configured'];
        }
        $offers = 0;
        foreach (CloudPlan::offers(SaladClient::LOCATION, true) as $plan) {
            if (filled($plan->gpu_model) && $plan->hourlyIrt() > 0
                && CloudStoreController::imageKeysFor($plan, 'app') !== []) {
                $offers++;
            }
        }
        $current = app(CloudPricingSnapshot::class)->current();
        return ['enabled' => true, 'ok' => $offers > 0, 'offers' => $offers,
            'reason' => $offers > 0 ? 'available' : ($current ? 'no_orderable_offer' : 'stale_price_snapshot')];
    }
}
