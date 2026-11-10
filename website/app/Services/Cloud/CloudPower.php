<?php

namespace App\Services\Cloud;

use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/** The panel and customer API share admission, provider dispatch and billing. */
class CloudPower
{
    public function __construct(private CloudManager $manager, private InterruptibleBillingClock $clock) {}

    public function actions(?CloudInstance $instance): array
    {
        $driver = $instance ? $this->manager->forInstance($instance) : null;
        if (! $instance || ! $driver) { return []; }
        if ($instance->provider === 'vpstc'
            && (! $driver instanceof VpsTcClient || ! $driver->canPower($instance))) { return []; }
        return match ($instance->provider) {
            'salad' => ['on', 'off'],
            'hetzner-robot' => ['reboot'],
            default => ['on', 'off', 'reboot'],
        };
    }

    public function execute(Service $service, string $action, ?int $operationId = null): array
    {
        $expectedCustomer = $service->customer_id;
        $lock = Cache::lock('cloud-power:'.$service->id, 300);
        if (! $lock->get()) { return $this->fail('operation_in_progress', __('ui.cx_not_ready'), 409); }
        try {
            $service->refresh();
            if ($service->customer_id !== $expectedCustomer) { return $this->fail('not_found', __('customer-api.not_found'), 404); }
            if (! in_array($action, ['on', 'off', 'reboot'], true)) {
                return $this->fail('invalid_action', __('ui.cx_bad_op'), 422);
            }
            if ($service->status !== 'active' || ($action !== 'off' && $service->hourlyBillingHeld())) {
                return $this->fail('service_not_active', __('panel.cloud_suspended'), 409);
            }
            if (Schema::hasTable('customer_api_operations') && DB::table('customer_api_operations')
                ->where('service_id', $service->id)
                ->where(fn ($q) => $action === 'off'
                    ? $q->where('state', 'processing')->where('created_at', '>=', now()->subMinutes(5))
                    : $q->whereIn('state', ['processing', 'uncertain']))
                ->when($operationId, fn ($q) => $q->where('id', '!=', $operationId))->exists()) {
                return $this->fail('reconciliation_required', __('customer-api.reconcile'), 409);
            }
            $instance = CloudInstance::where('service_id', $service->id)->first();
            if (! in_array($action, $this->actions($instance), true) || blank($instance?->provider_ref)) {
                return $this->fail('operation_unavailable', __('ui.cx_op_na'), 409);
            }
            $identity = [$instance->id, $instance->provider, $instance->provider_ref];
            $rateKey = 'cloud:power:'.$service->id;
            if (RateLimiter::tooManyAttempts($rateKey, 12)) {
                return $this->fail('rate_limited', __('ui.cx_throttle', ['sec' => RateLimiter::availableIn($rateKey)]), 429);
            }
            RateLimiter::hit($rateKey, 60);
            $driver = $this->manager->forInstance($instance);
            if ($action !== 'off' && $this->clock->applies($service)) {
                $observedAt = now();
                try { $live = $driver->serverStatus((string) $instance->provider_ref); }
                catch (\Throwable $e) { report($e); $live = ['ok' => false]; }
                if (! ($live['ok'] ?? false) || ! in_array($live['status'] ?? '', ['running', 'off'], true)) {
                    return $this->fail('status_unavailable', __('ui.cx_not_ready'), 503);
                }
                $service->refresh(); $instance->refresh();
                if ($service->customer_id !== $expectedCustomer || [$instance->id, $instance->provider, $instance->provider_ref] !== $identity) { return $this->fail('not_found', __('customer-api.not_found'), 404); }
                $this->clock->record($service, $instance, $live['status'], observedAt: $observedAt);
                if ($action === 'on' && $live['status'] === 'running') {
                    return ['ok' => true, 'http_status' => 202, 'accepted' => true, 'no_op' => true,
                        'service_id' => $service->id, 'action' => $action, 'status' => 'running', 'message' => __('ui.cx_async_accepted')];
                }
                if ($action === 'reboot' && $live['status'] !== 'running') {
                    return $this->fail('operation_unavailable', __('ui.cx_op_na'), 409);
                }
            }
            $claimed = null;
            if ($action === 'on' && $this->clock->applies($service)) {
                $start = app(HourlyGpuStart::class)->claim($service);
                if ($start['pending'] ?? false) { return $this->fail('operation_in_progress', __('ui.cx_not_ready'), 409); }
                if (isset($start['minimum'])) {
                    return $this->fail('insufficient_credit', __('ui.cx_e_hourly_credit', [
                        'hours' => fa_num(1), 'min' => cloud_price($start['minimum']), 'bal' => cloud_price($start['available']),
                    ]), 402) + ['minimum_irt' => $start['minimum'], 'available_irt' => $start['available']];
                }
                $claimed = $start['claimed'] ?? null;
            }
            if (! Service::whereKey($service->id)->where('customer_id', $expectedCustomer)->exists()
                || ! CloudInstance::whereKey($instance->id)->where('service_id', $service->id)->where('provider_ref', $instance->provider_ref)->exists()) {
                return $this->fail('resource_changed', __('ui.cx_not_ready'), 409);
            }
            Cache::forget('cloud-st:'.$instance->id);
            try {
                $result = $driver->power((string) $instance->provider_ref, $action);
            } catch (\Throwable $e) {
                if ($action === 'off') { $this->markStop($service); }
                // Do not retry an ambiguous remote command or release its funded hour.
                // A later verified Running observation is the only settlement evidence.
                if ($claimed !== null) {
                    $this->clock->record($service, $instance, 'building', forceNewRun: true);
                }
                report($e);
                return $this->fail('provider_result_unknown', __('customer-api.reconcile'), 503) + ['uncertain' => true];
            } finally {
                Cache::forget('cloud-st:'.$instance->id);
            }
            if ($action === 'off') { $this->markStop($service); }
            if (! ($result['ok'] ?? false)) {
                // Drivers may flatten transport errors into ok=false. Keep the API
                // ledger uncertain; never infer that the remote command did not run.
                if ($claimed !== null) {
                    $this->clock->record($service, $instance, 'building', forceNewRun: true);
                }
                return $this->fail('provider_result_unknown', __('customer-api.reconcile'), 503) + ['uncertain' => true];
            }
            if ($claimed !== null) {
                $this->clock->record($service, $instance, 'building', ['last_error' => null], forceNewRun: true);
            } else {
                // Accepted Stop/Reboot is not a fresh observation. Preserve runtime
                // and prepaid funds until status confirms the actual transition.
                $instance->update(['last_error' => null]);
            }
            try { ActivityLog::forService($service, 'power:'.$action, 'power:'.$action, 'customer'); } catch (\Throwable) {}
            return ['ok' => true, 'http_status' => 202, 'accepted' => true,
                'message' => __('ui.cx_async_accepted'), 'service_id' => $service->id,
                'action' => $action, 'status' => $instance->fresh()->status];
        } finally {
            $lock->release();
        }
    }

    private function markStop(Service $service): void
    {
        DB::transaction(function () use ($service) {
            \App\Models\Customer::whereKey($service->customer_id)->lockForUpdate()->firstOrFail();
            $current = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
            $meta = (array) $current->provision_meta;
            $meta['hourly_stop_requested_at'] = now()->toISOString();
            $current->update(['provision_meta' => $meta]);
        });
    }

    private function fail(string $error, string $message, int $status): array
    {
        return ['ok' => false, 'error' => $error, 'message' => $message, 'http_status' => $status];
    }
}
