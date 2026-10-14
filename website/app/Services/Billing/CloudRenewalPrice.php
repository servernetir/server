<?php

namespace App\Services\Billing;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudPlan;
use App\Models\Service;

/** Shared, read-only quote for invoices and forecasts. Never lowers a contract. */
class CloudRenewalPrice
{
    public function quote(Service $service): int
    {
        $current = (int) $service->price;
        if (app(ComplimentaryServices::class)->applies($service)) {
            return 0;
        }
        if ($service->isHourly() || Service::monthsIn((string) $service->cycle) <= 0
            || ($service->currency_code ?: 'IRT') !== 'IRT'
            || (! filled($service->cloud_plan_id) && ! filled($service->cloud_image_key))) {
            return $current;
        }

        // Query fresh: a loaded relation may predate a catalogue price update.
        $plan = CloudPlan::find($service->cloud_plan_id);
        if (! $plan || (int) $plan->price_irt <= 0) {
            throw new \DomainException('قیمت معتبر پلن ابری برای تمدید سرویس #'.$service->id.' موجود نیست.');
        }

        return max($current, CloudStoreController::priceForCycle(
            $plan, (string) $service->cycle, (array) ($service->cloud_addons ?? [])
        ));
    }
}
