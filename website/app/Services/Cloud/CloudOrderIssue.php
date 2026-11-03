<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Service;

/** Customer presentation only: never retry, cancel, refund or contact a provider. */
final class CloudOrderIssue
{
    public static function kind(Service $service, ?CloudInstance $instance = null): ?string
    {
        if (! $service->isCloud() || $service->isDead() || $service->provision_status !== 'failed') {
            return null;
        }

        if ($instance?->isDelivered()) {
            return null;
        }

        // An existing resource/receipt or an ambiguous failure must be reconciled
        // by support. Do not encourage another purchase after a timeout.
        if (blank($instance?->provider_ref)
            && preg_match('/\[resource_unavailable\]/i', (string) $service->provision_error) === 1) {
            return 'capacity';
        }

        return 'review';
    }
}
