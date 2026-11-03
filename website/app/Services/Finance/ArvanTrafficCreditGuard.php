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
    public const KEY = 'arvan_traffic_credit_hold:';

    public static function active(Service $service): bool
    {
        return (self::state($service)['active'] ?? null) === true;
    }

    /** No Setting cache: callers inside a transaction have already locked Customer -> Service -> Instance. */
    public static function state(Service $service): array
    {
        $query = DB::table('settings')->where('key', self::KEY.$service->id);
        $row = (DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query)->first();

        if (! $row) {
            return [];
        }
        $state = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($state) || ($state['version'] ?? null) !== 1 || ! is_bool($state['active'] ?? null)
            || ($state['service_id'] ?? null) !== (int) $service->id
            || ! in_array($state['stop']['state'] ?? null, ['pending', 'confirmed', 'review_required', 'quarantined', 'cancelled'], true)) {
            throw new \RuntimeException('Traffic credit control record requires reconciliation.');
        }

        return $state;
    }

    /** Indexed key range confines discovery to this dedicated state, independently of all service metadata writers. */
    private function records()
    {
        $own = DB::table('settings')->where('key', '>=', self::KEY)->where('key', '<', self::KEY.'~')
            ->select('key')->selectRaw("CASE WHEN json_valid(value) THEN value ELSE '{}' END AS payload");

        return DB::query()->fromSub($own, 'traffic_holds');
    }

    private function invalidIdentitySql(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "COALESCE(json_type(payload, '$.version'), 'missing') != 'integer' OR COALESCE(json_type(payload, '$.service_id'), 'missing') != 'integer'"
            : "COALESCE(JSON_TYPE(JSON_EXTRACT(payload, '$.version')), 'MISSING') != 'INTEGER' OR COALESCE(JSON_TYPE(JSON_EXTRACT(payload, '$.service_id')), 'MISSING') != 'INTEGER'";
    }

    private function invalidActiveSql(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "COALESCE(json_type(payload, '$.active'), 'missing') NOT IN ('true', 'false')"
            : "COALESCE(JSON_TYPE(JSON_EXTRACT(payload, '$.active')), 'MISSING') != 'BOOLEAN'";
    }

    private function canonicalKeySql(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "traffic_holds.key = ? || CAST(json_extract(payload, '$.service_id') AS TEXT)"
            : "traffic_holds.`key` = CONCAT(?, json_extract(payload, '$.service_id'))";
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
        if (self::active($service)) {
            return false; // Preserve the original incident/receipt on subsequent traffic postings.
        }
        $hold = ['version' => 1, 'active' => true, 'reason' => 'traffic_credit_exhausted',
            'token' => (string) Str::uuid(), 'service_id' => (int) $service->id, 'customer_id' => (int) $service->customer_id,
            'instance_id' => (int) $instance->id, 'resource' => (string) $instance->provider_ref,
            'trigger_invoice_id' => $invoiceId, 'held_at' => now()->toIso8601String(),
            'stop' => ['state' => 'pending', 'attempts' => 0, 'retry_at' => null, 'inflight' => false, 'generation' => 1]];
        $this->save($service, $hold);

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
            $hold = self::state($service);
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
            $hold = ($service ? self::state($service) : []);
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
        $ids = $this->records()->join('services', 'services.id', '=', DB::raw("json_extract(payload, '$.service_id')"))
            ->whereRaw($this->canonicalKeySql(), [self::KEY])->where('payload->version', 1)
            ->whereRaw('NOT ('.$this->invalidIdentitySql().')')
            ->whereRaw('NOT ('.$this->invalidActiveSql().')')->where('payload->active', true)
            ->whereIn('payload->stop->state', ['pending', 'confirmed', 'review_required'])
            ->where(fn ($q) => $q->whereNull('payload->stop->retry_at')
                ->orWhere('payload->stop->retry_at', '<=', now()->toIso8601String()))
            ->orderByRaw("CASE WHEN json_extract(payload, '$.stop.attempted_at') IS NULL THEN 0 ELSE 1 END")
            ->orderBy('payload->stop->retry_at')->orderBy('key')
            ->limit(max(1, min(100, $limit)))->pluck('key');
        $deadline = microtime(true) + 45;
        foreach ($ids as $key) {
            $id = substr($key, strlen(self::KEY));
            if (! ctype_digit($id)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            $counts[$this->reconcile((int) $id)]++;
        }

        $review = $this->records()->leftJoin('services', 'services.id', '=', DB::raw("json_extract(payload, '$.service_id')"))
            ->where(fn ($q) => $q->whereNull('payload->version')->orWhere('payload->version', '!=', 1)
                ->orWhereRaw($this->invalidIdentitySql())->orWhereRaw($this->invalidActiveSql())->orWhereNull('payload->stop->state')
                ->orWhereNotIn('payload->stop->state', ['pending', 'confirmed', 'review_required', 'quarantined', 'cancelled'])
                ->orWhereNull('services.id')->orWhereRaw('NOT ('.$this->canonicalKeySql().')', [self::KEY])
                ->orWhere(fn ($q) => $q->where('payload->active', true)
                    ->whereIn('payload->stop->state', ['quarantined', 'review_required'])))->count();
        $counts['review_required'] = max($counts['review_required'], $review);

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
            $hold = self::state($service);
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
            if (! $service) {
                return 'stale';
            }
            $fresh = self::state($service);
            if (! $current) {
                $this->quarantine($service, $fresh, 'resource_binding_became_ambiguous');

                return 'review_required';
            }
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
            $hold = ($service ? self::state($service) : []);
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
        return ($hold['version'] ?? null) === 1 && ($hold['service_id'] ?? null) === (int) $service->id
            && ($hold['reason'] ?? null) === 'traffic_credit_exhausted'
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
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Traffic hold writes require the customer/service/instance transaction.');
        }
        DB::table('settings')->upsert([['key' => self::KEY.$service->id,
            'value' => json_encode($hold, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]],
            ['key'], ['value', 'updated_at']);
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

            $instance = $instances->count() === 1 ? $instances[0] : null;
            if ($instance?->provider === 'arvan' && filled($instance->provider_ref)) {
                $owners = CloudInstance::where('provider', 'arvan')->where('provider_ref', $instance->provider_ref)
                    ->orderBy('id')->lockForUpdate()->get();
                if ($owners->count() !== 1 || (int) $owners[0]->id !== (int) $instance->id) {
                    $instance = null;
                }
            }

            return $callback($service, $instance);
        }, 3);
    }
}
