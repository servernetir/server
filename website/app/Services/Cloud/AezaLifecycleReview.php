<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Service;
use App\Support\ErrorTracker;

/** Supplier observations are evidence, never a new billing/renewal instruction. */
class AezaLifecycleReview
{
    public function observe(CloudInstance $instance, array $snapshot): ?array
    {
        if ($instance->provider !== 'aeza') { return null; }
        if (! ($snapshot['ok'] ?? false) || data_get($snapshot, 'raw.identity_verified') !== true) {
            return $instance->service ? self::forService($instance->service) : null;
        }
        $raw = (array) ($snapshot['raw'] ?? []);
        $reason = ($snapshot['status'] ?? null) === 'deleted' ? 'deleted'
            : (($raw['current_status'] ?? null) === 'prolong_wait' ? 'expired' : null);
        $instance->refresh();
        $meta = (array) $instance->meta;
        $meta['provider_billing_observation'] = [
            'expires_at' => $raw['expires_at'] ?? null,
            'auto_prolong' => $raw['auto_prolong'] ?? null,
            'observed_at' => now()->toIso8601String(),
        ];
        if ($reason === null) {
            if (! in_array($snapshot['status'] ?? null, ['running', 'off'], true)) {
                return $instance->service ? self::forService($instance->service) : null;
            }
            unset($meta['provider_lifecycle_review']);
            $instance->update(['meta' => $meta]);
            return null;
        }
        $message = $reason === 'deleted'
            ? 'منبع سرویس نزد زیرساخت حذف‌شده است؛ بازیابی یا تمدید نیازمند بررسی است.'
            : 'سرویس نزد زیرساخت در انتظار تمدید است؛ خاموشی یا فعال‌سازی تأیید نشده است.';
        $review = [
            'reason' => $reason, 'message' => $message,
            'first_observed_at' => data_get($meta, 'provider_lifecycle_review.first_observed_at')
                ?? now()->toIso8601String(),
            'observed_at' => now()->toIso8601String(),
        ];
        $meta['provider_lifecycle_review'] = $review;
        if ($reason === 'deleted') { $meta['provider_deleted_verified_at'] = $review['observed_at']; }
        $instance->update(['meta' => $meta, 'status' => $reason === 'deleted' ? 'deleted' : 'unknown',
            'last_error' => $message, 'synced_at' => now()]);
        if ($instance->service && ! $instance->service->isDead()) {
            ErrorTracker::noteOnce('provision', $message, 86400,
                ['service' => $instance->service_id, 'instance' => $instance->id, 'reason' => $reason]);
        }
        return $review;
    }

    public static function forService(Service $service): ?array
    {
        $instance = CloudInstance::where('service_id', $service->id)->where('provider', 'aeza')->first();
        $review = data_get($instance?->meta, 'provider_lifecycle_review');
        return is_array($review) && in_array($review['reason'] ?? null, ['deleted', 'expired'], true)
            ? $review : null;
    }
}
