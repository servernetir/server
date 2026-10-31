<?php

namespace App\Services\Cloud;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudPlan;
use App\Models\Service;

/** Public prices use the complete-cycle checkout quote; unsupported cycles have no price. */
class CloudPublicQuote
{
    public function monthlyPrice(CloudPlan $plan): int
    {
        return CloudStoreController::priceForCycle($plan, 'monthly');
    }

    public function quote(?CloudPlan $plan): array
    {
        $monthly = $plan ? $this->monthlyPrice($plan) : 0;
        if ($monthly <= 0) {
            return ['contact' => true, 'quote' => true];
        }

        $quote = ['irt' => $monthly, 'final_irt' => true];
        $annual = CloudStoreController::priceForCycle($plan, 'yearly');
        $months = Service::monthsIn('yearly');
        if ($annual > 0 && $months > 0) {
            $undiscounted = $monthly * $months;
            $quote += [
                'yearly_irt' => (int) ceil($annual / $months),
                'yearly_savings_pct' => $undiscounted > $annual
                    ? (int) floor(($undiscounted - $annual) * 100 / $undiscounted) : 0,
            ];
        }

        return $quote;
    }
}
