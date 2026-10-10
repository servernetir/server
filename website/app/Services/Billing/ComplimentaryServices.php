<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/** Owner-authorized renewal waiver. Provider costs remain business expenses. */
class ComplimentaryServices
{
    public function customerExempt(?Customer $customer): bool
    {
        $owner = (array) config('billing.complimentary_services', []);

        return $customer !== null
            && (int) ($owner['customer_id'] ?? 0) > 0
            && (int) $customer->id === (int) $owner['customer_id']
            && filled($owner['customer_code'] ?? null)
            && $customer->code === $owner['customer_code'];
    }

    public function applies(Service $service): bool
    {
        return $this->customerExempt($service->customer);
    }

    /** Advance the existing billing anchor; never reactivate a closed service. */
    public function renew(Service $service, int $days = 5): bool
    {
        if (! $this->applies($service) || $service->isHourly()
            || Service::monthsIn((string) $service->cycle) <= 0) {
            return false;
        }

        return DB::transaction(function () use ($service, $days) {
            $fresh = Service::whereKey($service->id)->lockForUpdate()->first();
            if ($fresh === null || $fresh->status !== 'active'
                || ! $this->applies($fresh) || $fresh->isHourly()) {
                return false;
            }

            $threshold = now()->startOfDay()->addDays(max(0, $days));
            $old = $fresh->next_due_at?->toDateString();
            $due = $fresh->next_due_at?->copy() ?? now()->startOfDay();
            $months = Service::monthsIn((string) $fresh->cycle);
            if ($months <= 0) {
                return false;
            }
            while ($due->lte($threshold)) {
                $due->addMonthsNoOverflow($months);
            }

            $fresh->forceFill([
                'price' => 0, 'tax_percent' => 0, 'next_due_at' => $due,
                'suspended_at' => null, 'reminder_stage' => null, 'grace_alert_at' => null,
            ])->save();
            if ($old !== $due->toDateString()) {
                ActivityLog::forService($fresh, 'renew',
                    'تمدید خودکار رایگان حساب مالک تا '.$due->toDateString().'؛ بدون برداشت اعتبار یا ثبت پرداخت.', 'system');
            }

            $service->refresh();

            return true;
        });
    }
}
