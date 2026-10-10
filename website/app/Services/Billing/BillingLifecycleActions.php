<?php

namespace App\Services\Billing;

use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;

class BillingLifecycleActions
{
    /** Returns confirmed, pending, failed or deferred. No successful state on a failed API call. */
    public static function run(Service $service, string $action, ProvisioningService $provisioner): string
    {
        $service->refresh();
        if ($service->isInternalInfrastructure()) {
            return 'deferred';
        }
        if ($action === 'suspend' && (RenewalTerms::serviceDeadline($service)?->isFuture()
            || RenewalTerms::openInvoice($service) === null)) {
            return 'deferred';
        }
        if ($action === 'unsuspend' && (RenewalTerms::openInvoice($service) !== null
            || RenewalTerms::serviceDeadline($service)?->isPast())) {
            return 'deferred';
        }
        $key = $action === 'suspend' ? 'billing_suspend' : 'billing_resume';
        $state = (array) ($service->provision_meta[$key] ?? []);
        if (! empty($state['retry_at']) && Carbon::parse($state['retry_at'])->isFuture()) {
            return 'deferred';
        }
        if (($service->pendingManualAction()['kind'] ?? null) === $action) {
            return 'pending';
        }

        try {
            $result = $provisioner->{$action}($service);
            $confirmed = $result->ok && ! $result->manual;
            $manual = $result->manual;
            $error = $result->error;
        } catch (\Throwable $e) {
            $confirmed = false;
            $manual = false;
            $error = $e::class;
        }

        $service->refresh();
        $meta = (array) $service->provision_meta;
        $meta[$key] = [
            'attempted_at' => now()->toIso8601String(),
            'confirmed_at' => $confirmed ? now()->toIso8601String() : null,
            'manual' => $manual,
            'error' => $confirmed ? null : mb_substr((string) $error, 0, 290),
            'retry_at' => $confirmed ? null : now()->addMinutes(max(1, (int) config('billing.lifecycle_retry_minutes', 15)))->toIso8601String(),
        ];
        $service->forceFill(['provision_meta' => $meta])->save();

        if (! $confirmed && ! $manual) {
            ErrorTracker::noteOnce('billing', 'عملیات '.$action.' سرویس #'.$service->id.' تأیید نشد؛ تلاش مجدد زمان‌بندی شد.', 900, ['service' => $service->id]);
        }

        return $confirmed ? 'confirmed' : ($manual ? 'pending' : 'failed');
    }
}
