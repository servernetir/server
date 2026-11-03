<?php

namespace App\Services\Finance;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A new traffic debit creates its stop intent in the same transaction; provider work never does. */
final class ArvanTrafficCreditGuard
{
    public const KEY = 'traffic_credit_hold';

    public static function active(Service $service): bool
    {
        return data_get($service->provision_meta, self::KEY.'.active') === true;
    }

    /** Caller owns Customer -> Service -> Instance locks and has just posted a positive validated traffic debit. */
    public function holdLocked(Service $service, CloudInstance $instance, int $invoiceId, int $available): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Traffic stop intent must commit with its debit.');
        }
        if ($available > 0 || $invoiceId <= 0 || $service->isInternalInfrastructure()
            || in_array($service->status, ['cancelled', 'terminated', 'expired'], true)) {
            return false;
        }
        $meta = (array) $service->provision_meta;
        if (self::active($service)) {
            return false; // Preserve the original incident/receipt on subsequent traffic postings.
        }
        $meta[self::KEY] = ['version' => 1, 'active' => true, 'reason' => 'traffic_credit_exhausted',
            'token' => (string) Str::uuid(), 'customer_id' => (int) $service->customer_id,
            'instance_id' => (int) $instance->id, 'resource' => (string) $instance->provider_ref,
            'trigger_invoice_id' => $invoiceId, 'held_at' => now()->toIso8601String(),
            'stop' => ['state' => 'pending', 'attempts' => 0, 'retry_at' => null, 'inflight' => false, 'generation' => 1]];
        $service->forceFill(['provision_meta' => $meta])->save();

        return true;
    }

    /** A funded explicit restart releases ONLY this hold. No provider call or automatic restart. */
    public function allowPower(string $ref): bool
    {
        $instances = CloudInstance::where('provider', 'arvan')->where('provider_ref', $ref)->get();
        if ($instances->isEmpty()) {
            return true; // New resources without a service cannot have a traffic-debit hold.
        }
        if ($instances->count() !== 1) {
            return false;
        }

        return $this->locked((int) $instances[0]->service_id, function ($service, $instance) use ($ref): bool {
            if (! $service || ! $instance || $instance->provider !== 'arvan' || $instance->provider_ref !== $ref) {
                return false;
            }
            if (! self::active($service)) {
                return true;
            }
            $hold = (array) data_get($service->provision_meta, self::KEY);
            if (! $this->matches($service, $instance, $hold)) {
                return false;
            }
            if (($hold['stop']['inflight'] ?? false) === true
                || app(Wallet::class)->availableOf((int) $service->customer_id, currentRead: true) <= 0) {
                return false;
            }
            $this->release($service, $hold);

            return true;
        }) === true;
    }

    /** A start already in flight when the debit commits must not leave a confirmed-off receipt behind. */
    public function rearmAfterPower(string $ref): void
    {
        $instances = CloudInstance::where('provider', 'arvan')->where('provider_ref', $ref)->get();
        if ($instances->count() !== 1) {
            return;
        }
        $this->locked((int) $instances[0]->service_id, function ($service, $instance) use ($ref): void {
            $hold = (array) data_get($service?->provision_meta, self::KEY, []);
            if (! $service || ! $instance || $instance->provider_ref !== $ref || ! self::active($service)
                || ! $this->matches($service, $instance, $hold)) {
                return;
            }
            $hold['stop']['state'] = 'pending';
            $hold['stop']['generation'] = (int) ($hold['stop']['generation'] ?? 1) + 1;
            $hold['stop']['retry_at'] = null;
            unset($hold['stop']['confirmed_at']);
            $this->save($service, $hold);
        });
    }

    /** Bounded reconciliation of existing active intents only: a zero-balance monthly account is never enrolled here. */
    public function process(int $limit = 20): array
    {
        $counts = ['confirmed' => 0, 'pending' => 0, 'released' => 0, 'stale' => 0, 'review_required' => 0];
        $ids = Service::where('provision_meta->'.self::KEY.'->active', true)
            ->whereIn('provision_meta->'.self::KEY.'->stop->state', ['pending', 'confirmed', 'review_required'])
            ->where(fn ($q) => $q->whereNull('provision_meta->'.self::KEY.'->stop->retry_at')
                ->orWhere('provision_meta->'.self::KEY.'->stop->retry_at', '<=', now()->toIso8601String()))
            ->orderBy('provision_meta->'.self::KEY.'->stop->retry_at')->orderBy('id')
            ->limit(max(1, min(100, $limit)))->pluck('id');
        $deadline = microtime(true) + 45;
        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $counts[$this->reconcile((int) $id)]++;
        }

        $counts['review_required'] = max($counts['review_required'], Service::where('provision_meta->'.self::KEY.'->active', true)
            ->whereIn('provision_meta->'.self::KEY.'->stop->state', ['quarantined', 'review_required'])->count());

        return $counts;
    }

    public function reconcile(int $serviceId): string
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Traffic stop worker cannot run inside a transaction.');
        }
        $prepared = $this->locked($serviceId, function ($service, $instance): array|string {
            if (! $service || ! self::active($service)) {
                return 'stale';
            }
            $hold = (array) data_get($service->provision_meta, self::KEY);
            if (! $instance || ! $this->matches($service, $instance, $hold)
                || $service->isInternalInfrastructure()
                || in_array($service->status, ['cancelled', 'terminated', 'expired'], true)) {
                $this->quarantine($service, $hold, 'resource_owner_or_service_protection_changed');

                return 'review_required';
            }
            if (($hold['stop']['inflight'] ?? false) !== true
                && app(Wallet::class)->availableOf((int) $service->customer_id, currentRead: true) > 0) {
                $this->release($service, $hold);

                return 'released';
            }
            if (! in_array($hold['stop']['state'] ?? null, ['pending', 'confirmed', 'review_required'], true)) {
                return 'stale';
            }
            $retry = $hold['stop']['retry_at'] ?? null;
            if (is_string($retry) && $retry > now()->toIso8601String()) {
                return 'pending';
            }
            if (($hold['stop']['state'] ?? null) === 'confirmed') {
                // A prior OFF is settled; periodic checks also catch a delayed, previously accepted ON.
                unset($hold['stop']['issued_at']);
            }
            $hold['stop']['state'] = 'pending';
            $hold['stop']['inflight'] = true;
            $hold['stop']['attempts'] = (int) ($hold['stop']['attempts'] ?? 0) + 1;
            $hold['stop']['attempted_at'] = now()->toIso8601String();
            $hold['stop']['retry_at'] = now()->addMinutes(5)->toIso8601String();
            $this->save($service, $hold);

            return ['hold' => $hold, 'instance' => $instance];
        });
        if (! is_array($prepared)) {
            return $prepared ?? 'stale';
        }
        // All financial/customer/service/instance transactions have committed before provider I/O.
        $hold = $prepared['hold'];
        $instance = $prepared['instance'];
        $driver = app(CloudManager::class)->forInstance($instance);
        $confirmed = false;
        try {
            if ($driver) {
                $actual = $driver->serverStatus($hold['resource']);
                $confirmed = $this->off($actual, $hold['resource']);
                if (! $confirmed && ($actual['ok'] ?? false) === true
                    && ($actual['provider_ref'] ?? null) === $hold['resource']
                    && in_array($actual['status'] ?? null, ['running', 'building'], true)
                    && $this->mayIssueStop($serviceId, $hold)) {
                    // An accepted POST, exception or timeout is never proof of shutdown.
                    try {
                        $driver->power($hold['resource'], 'off');
                    } catch (\Throwable) {
                    }
                    $confirmed = $this->off($driver->serverStatus($hold['resource']), $hold['resource']);
                }
            }
        } catch (\Throwable) {
            // The durable intent remains pending; no additional charge is made on retries.
        }

        return $this->locked($serviceId, function ($service, $current) use ($hold, $confirmed): string {
            if (! $service || ! $current) {
                return 'stale';
            }
            $fresh = (array) data_get($service->provision_meta, self::KEY);
            if (($fresh['token'] ?? null) !== $hold['token']) {
                return 'stale';
            }
            if (! $this->matches($service, $current, $fresh) || $this->protected($service)) {
                $this->quarantine($service, $fresh, 'resource_owner_or_service_protection_changed');

                return 'review_required';
            }
            if (($fresh['stop']['state'] ?? null) === 'quarantined') {
                return 'review_required';
            }
            if (! self::active($service)) {
                return filled($fresh['released_at'] ?? null) ? 'released' : 'stale';
            }
            if (($fresh['stop']['generation'] ?? 1) !== ($hold['stop']['generation'] ?? 1)) {
                return 'pending';
            }
            if ($confirmed) {
                $current->forceFill(['status' => 'off', 'synced_at' => now()])->save();
            }
            if (empty($fresh['stop']['issued_at'])) {
                $fresh['stop']['inflight'] = false;
                $this->save($service, $fresh);
            }
            if (($confirmed || empty($fresh['stop']['issued_at']))
                && app(Wallet::class)->availableOf((int) $service->customer_id, currentRead: true) > 0) {
                $fresh['stop']['inflight'] = false;
                $this->release($service, $fresh);

                return 'released';
            }
            if ($confirmed) {
                $fresh['stop']['state'] = 'confirmed';
                $fresh['stop']['inflight'] = false;
                $fresh['stop']['confirmed_at'] = now()->toIso8601String();
                $fresh['stop']['retry_at'] = now()->addMinutes(5)->toIso8601String();
                unset($fresh['stop']['review_required_at'], $fresh['stop']['review_reason']);
                $this->save($service, $fresh);
            }

            if (! $confirmed && filled($fresh['stop']['issued_at'] ?? null)
                && $fresh['stop']['issued_at'] <= now()->subMinutes(15)->toIso8601String()
                && app(Wallet::class)->availableOf((int) $service->customer_id, currentRead: true) > 0) {
                $fresh['stop']['state'] = 'review_required';
                $fresh['stop']['review_required_at'] ??= now()->toIso8601String();
                $fresh['stop']['review_reason'] = 'funded_account_with_unresolved_issued_stop';
                $this->save($service, $fresh);

                return 'review_required';
            }

            return $confirmed ? 'confirmed' : 'pending';
        }) ?? 'stale';
    }

    /** Recheck funding immediately before POST. An uncertain earlier POST must be observed off before any restart. */
    private function mayIssueStop(int $serviceId, array $claim): bool
    {
        return $this->locked($serviceId, function ($service, $instance) use ($claim): bool {
            $hold = (array) data_get($service?->provision_meta, self::KEY, []);
            if (! $service || ! $instance || ! self::active($service) || ! $this->matches($service, $instance, $hold)
                || ($hold['token'] ?? null) !== $claim['token']
                || ($hold['stop']['generation'] ?? 1) !== ($claim['stop']['generation'] ?? 1)) {
                return false;
            }
            if ($this->protected($service)) {
                $this->quarantine($service, $hold, 'service_protection_changed_before_stop');

                return false;
            }
            if (app(Wallet::class)->availableOf((int) $service->customer_id, currentRead: true) > 0) {
                if (empty($hold['stop']['issued_at'])) {
                    $hold['stop']['inflight'] = false;
                    $this->release($service, $hold);
                }

                return false;
            }
            // Persist uncertainty BEFORE the request; a crash cannot make a possibly issued OFF disappear.
            $hold['stop']['issued_at'] ??= now()->toIso8601String();
            $this->save($service, $hold);

            return true;
        }) === true;
    }

    private function protected(Service $service): bool
    {
        return $service->isInternalInfrastructure()
            || in_array($service->status, ['cancelled', 'terminated', 'expired'], true);
    }

    private function quarantine(Service $service, array $hold, string $reason): void
    {
        $hold['stop']['state'] = 'quarantined';
        $hold['stop']['review_required_at'] ??= now()->toIso8601String();
        $hold['stop']['review_reason'] = $reason;
        $this->save($service, $hold);
    }

    private function off(array $actual, string $ref): bool
    {
        return ($actual['ok'] ?? false) === true && ($actual['provider_ref'] ?? null) === $ref
            && ($actual['status'] ?? null) === 'off' && ($actual['confirmed_power_off'] ?? false) === true;
    }

    private function matches(Service $service, CloudInstance $instance, array $hold): bool
    {
        return ($hold['version'] ?? null) === 1 && ($hold['reason'] ?? null) === 'traffic_credit_exhausted'
            && is_string($hold['token'] ?? null) && Str::isUuid($hold['token'])
            && ($hold['customer_id'] ?? null) === (int) $service->customer_id
            && ($hold['instance_id'] ?? null) === (int) $instance->id
            && $instance->provider === 'arvan' && filled($instance->provider_ref)
            && ($hold['resource'] ?? null) === $instance->provider_ref;
    }

    private function release(Service $service, array $hold): void
    {
        $hold['active'] = false;
        $hold['released_at'] = now()->toIso8601String();
        $hold['stop']['state'] = 'cancelled';
        $hold['stop']['inflight'] = false;
        $hold['stop']['retry_at'] = null;
        $this->save($service, $hold);
    }

    private function save(Service $service, array $hold): void
    {
        $meta = (array) $service->provision_meta;
        $meta[self::KEY] = $hold;
        $service->forceFill(['provision_meta' => $meta])->save();
    }

    private function locked(int $serviceId, callable $callback): mixed
    {
        $owner = Service::whereKey($serviceId)->value('customer_id');
        if ($owner === null) {
            return null;
        }

        return DB::transaction(function () use ($serviceId, $owner, $callback) {
            Customer::whereKey($owner)->lockForUpdate()->firstOrFail();
            $service = Service::whereKey($serviceId)->lockForUpdate()->first();
            if (! $service || (int) $service->customer_id !== (int) $owner) {
                return null;
            }
            $instances = CloudInstance::where('service_id', $serviceId)->lockForUpdate()->get();

            return $callback($service, $instances->count() === 1 ? $instances[0] : null);
        }, 3);
    }
}
