<?php

namespace App\Services\Cloud;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudPlan;
use App\Models\Service;

/** Public monthly equivalents from the same complete-cycle quote as checkout. */
class CloudPublicQuote
{
    public function monthlyPrice(CloudPlan $plan): int
    {
        return CloudStoreController::priceForCycle($plan, 'monthly');
    }

    public function quote(?CloudPlan $plan): array
    {
        if ($plan === null) {
            return ['contact' => true, 'quote' => true];
        }

        $monthly = $this->monthlyPrice($plan);
        $annual = CloudStoreController::priceForCycle($plan, 'yearly');
        $months = Service::monthsIn('yearly');
        if ($monthly <= 0 || $annual <= 0 || $months <= 0) {
            return ['contact' => true, 'quote' => true];
        }

        // Derive savings after supplier floors and complete-cycle rounding.
        // A configured discount is only a ceiling, never a public promise.
        $undiscounted = $monthly * $months;

        return [
            'irt' => $monthly,
            'yearly_irt' => (int) ceil($annual / $months),
            'yearly_savings_pct' => $undiscounted > $annual
                ? (int) floor(($undiscounted - $annual) * 100 / $undiscounted) : 0,
            'final_irt' => true,
        ];
    }
}
