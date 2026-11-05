<?php

namespace App\Services\Cloud\Engine;

use App\Models\Service;

/** Read existing payment evidence; never create another charge or invoice. */
class EnginePaidGate
{
    /** Only the trusted billing path may restore a suspended service. */
    public function allowsBillingResume(Service $service): bool
    {
        if ($service->billing_mode === 'hourly' || $service->status !== 'suspended' || $service->suspended_at === null
            || $service->next_due_at === null || $service->next_due_at->copy()->endOfDay()->isPast()
            || $service->cancelled_at !== null || $service->hasUnverifiedBillingTerms()
            || $service->cloudPlan?->provider !== 'cloud-engine'
            || ! $service->customer()->where('status', 'active')->exists()) {
            return false;
        }

        return $service->invoices()->where('customer_id', $service->customer_id)
            ->where('status', 'paid')->where('paid_at', '>=', $service->suspended_at)->whereColumn('paid', '>=', 'total')->exists();
    }

    public function allows(Service $service): bool
    {
        if ($service->billing_mode === 'hourly' || ! in_array($service->status, ['active', 'awaiting_provision', 'provision_failed'], true)
            || $service->cancelled_at !== null || $service->hasUnverifiedBillingTerms()
            || $service->cloudPlan?->provider !== 'cloud-engine' || ! $service->customer()->where('status', 'active')->exists()
            || ($service->next_due_at !== null && $service->next_due_at->copy()->endOfDay()->isPast())) {
            return false;
        }

        return $service->invoices()->where('customer_id', $service->customer_id)
            ->where('status', 'paid')->whereNotNull('paid_at')->whereColumn('paid', '>=', 'total')->exists();
    }
}
