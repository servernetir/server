<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Read-only supplier audit. Local evidence never authorizes a new supplier mutation. */
class ClosedInfrastructureAudit
{
    public const LAST_SCAN_KEY = 'cloud.closed_audit.last';
    public const FRESH_MINUTES = 30;

    public function run(int $limit = 50): array
    {
        $result = ['checked' => 0, 'deleted' => 0, 'stopped_empty' => 0,
            'unknown' => 0, 'present' => 0, 'skipped' => 0, 'at' => now()->toIso8601String()];
        $remaining = max(1, min(200, $limit));
        $instances = CloudInstance::whereIn('provider', ['aeza', 'salad', 'lightnode'])
            ->whereNotNull('provider_ref')->where('provider_ref', '!=', '')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'deleted'))
            ->whereHas('service', fn ($q) => $q->whereIn('status', Service::DEAD_STATUSES)
                ->where(fn ($q) => $q->whereNull('provision_status')->orWhere('provision_status', '!=', 'releasing')))
            ->orderBy('updated_at')->orderBy('id')->cursor();
        foreach ($instances as $instance) {
            if (! $instance->service || $instance->service->isInternalInfrastructure() || $this->ambiguous($instance)) {
                $result['skipped']++;
                continue;
            }
            // Bound supplier reads, not rows inspected: protected or ambiguous
            // oldest identities must never starve later eligible resources.
            if ($remaining-- <= 0) { break; }
            $outcome = 'unknown';
            try {
                if ($instance->provider === 'aeza') {
                    $snapshot = app(AezaClient::class)->serverStatus((string) $instance->provider_ref);
                    if (($snapshot['ok'] ?? false) && data_get($snapshot, 'raw.identity_verified') === true) {
                        $outcome = ($snapshot['status'] ?? null) === 'deleted' ? 'deleted' : 'present';
                    }
                } elseif ($instance->provider === 'salad') {
                    $snapshot = app(SaladClient::class)->closedBillingState((string) $instance->provider_ref);
                    if ($snapshot['ok'] ?? false) { $outcome = $snapshot['outcome']; }
                } else {
                    $snapshot = app(LightNodeClient::class)->confirmRelease((string) $instance->provider_ref);
                    if ($snapshot['ok'] ?? false) { $outcome = 'deleted'; }
                }
            } catch (\Throwable) { $outcome = 'unknown'; }
            $saved = DB::transaction(function () use ($instance, $outcome) {
                $service = Service::whereKey($instance->service_id)->lockForUpdate()->first();
                $current = CloudInstance::whereKey($instance->id)->lockForUpdate()->first();
                if (! $service || ! $service->isDead() || $service->isInternalInfrastructure()
                    || $service->provision_status === 'releasing' || ! $current
                    || $current->provider !== $instance->provider || $current->provider_ref !== $instance->provider_ref
                    || $this->ambiguous($current)) { return false; }
                $meta = (array) $current->meta;
                $meta['closed_audit'] = ['outcome' => $outcome, 'observed_at' => now()->toIso8601String()];
                $values = ['meta' => $meta];
                if ($outcome === 'deleted') {
                    $values['status'] = 'deleted';
                    $values['last_error'] = null;
                    $values['synced_at'] = now();
                    $values['meta']['provider_deleted_verified_at'] = now()->toIso8601String();
                } elseif ($outcome === 'stopped_empty') {
                    $values['status'] = 'off';
                    $values['last_error'] = null;
                    $values['synced_at'] = now();
                }
                $current->update($values);
                return true;
            });
            if (! $saved) { $result['skipped']++; continue; }
            $result['checked']++;
            $result[$outcome]++;
        }
        Cache::forever(self::LAST_SCAN_KEY, $result);
        return $result;
    }

    private function ambiguous(CloudInstance $instance): bool
    {
        return CloudInstance::where('provider', $instance->provider)->where('provider_ref', $instance->provider_ref)
            ->where('id', '!=', $instance->id)->exists();
    }

    public static function confirmedStopped(CloudInstance $instance): bool
    {
        if ($instance->provider !== 'salad' || $instance->status !== 'off'
            || data_get($instance->meta, 'closed_audit.outcome') !== 'stopped_empty') { return false; }
        $observedAt = data_get($instance->meta, 'closed_audit.observed_at');
        if (! is_string($observedAt) || trim($observedAt) === '') { return false; }
        try {
            $at = Carbon::parse($observedAt);
            return $at->lte(now()) && $at->gte(now()->subMinutes(self::FRESH_MINUTES));
        } catch (\Throwable) { return false; }
    }
}
