<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Own uncached settings only. Every poll releases DB locks before UDS I/O,
 * then rechecks the current tuple and exact configuration/checkpoint bytes.
 */
final class ArvanObservationRegistry
{
    public const TRANSPORT = 'arvan_observation_transport_v1';
    public const ENROLLMENT = 'arvan_observation_enrollment:';
    public const GENERATION = 'arvan_observation_generation:';
    public const CHECKPOINT = 'arvan_observation_checkpoint:';
    public const STATUS = 'arvan_observation_status:';
    public const PROGRESS = 'arvan_observation_progress_v1';
    public const RUN = 'arvan_observation_run_v1';

    public function __construct(private string $root) {}

    private function view(string $key, bool $lock = true): array
    {
        $query = DB::table('settings')->where('key', $key);
        if ($lock) { $query->lockForUpdate(); }
        $length = DB::getDriverName() === 'sqlite' ? 'LENGTH(CAST(value AS BLOB))' : 'OCTET_LENGTH(value)';
        $row = $query->selectRaw('CASE WHEN '.$length.' <= ? THEN value ELSE NULL END AS value, '.$length.' AS bytes',
            [ArvanObservationState::MAX_RECORD_BYTES])->first();
        if ($row === null) { return ['kind' => 'missing', 'value' => null]; }
        if ((int) $row->bytes > ArvanObservationState::MAX_RECORD_BYTES) { return ['kind' => 'oversized', 'value' => null]; }
        if (!is_string($row->value)) { return ['kind' => 'invalid', 'value' => null]; }
        return ['kind' => 'bounded', 'value' => $row->value];
    }

    private function raw(string $key, bool $lock = true): ?string
    {
        $view = $this->view($key, $lock);
        if (!in_array($view['kind'], ['missing', 'bounded'], true)) { throw new RuntimeException('observation_record_invalid_or_oversized'); }
        return $view['value'];
    }

    private function put(string $key, array $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => ArvanObservationState::encode($value)]);
    }

    private function checkpointKey(array $identity): string
    {
        return self::CHECKPOINT.$identity['service_id'].':'.$identity['enrollment_id'];
    }

    /** Current locking reads, never Eloquent's previously loaded state or Setting cache. */
    private function binding(array $identity): bool
    {
        $customer = DB::table('customers')->where('id', $identity['customer_id'])->lockForUpdate()->select('id')->first();
        $service = DB::table('services')->where('id', $identity['service_id'])->lockForUpdate()
            ->select('id', 'customer_id', 'status')->first();
        $instances = DB::table('cloud_instances')->where('service_id', $identity['service_id'])
            ->orderBy('id')->limit(2)->lockForUpdate()->get(['id', 'service_id', 'provider', 'provider_ref', 'status']);
        $owners = DB::table('cloud_instances')->where('provider', 'arvan')->where('provider_ref', $identity['provider_ref'])
            ->orderBy('id')->limit(2)->lockForUpdate()->get(['id', 'service_id']);
        return $customer !== null && $service !== null && (int) $service->customer_id === $identity['customer_id']
            && !in_array($service->status, ['cancelled', 'terminated', 'expired'], true)
            && count($instances) === 1 && count($owners) === 1
            && (int) $instances[0]->id === $identity['instance_id']
            && (int) $owners[0]->id === $identity['instance_id']
            && $instances[0]->provider === 'arvan' && $instances[0]->provider_ref === $identity['provider_ref']
            && !in_array($instances[0]->status, ['deleted', 'deleting', 'terminated'], true);
    }

    public function transport(bool $verify = true): ?array
    {
        $value = ArvanObservationState::decode($this->raw(self::TRANSPORT, false));
        if ($value === null) { return null; }
        if (($value['version'] ?? null) !== 1 || !is_bool($value['enabled'] ?? null)
            || !ArvanObservationState::sha($value['operator_acceptance_sha256'] ?? null)
            || !is_array($value['pin'] ?? null)) { throw new RuntimeException('transport_approval_invalid'); }
        if (!$value['enabled']) { return null; }
        if ($verify) { (new UnixPrometheusTrafficTransport($this->root, $value['pin']))->verify(); }
        return $value;
    }

    /** Operator-only descriptor; no supplied URL, socket path, token or credential. */
    public function approve(array $descriptor): array
    {
        if (($descriptor['version'] ?? null) !== 1 || !ArvanObservationState::sha($descriptor['operator_acceptance_sha256'] ?? null)
            || !in_array($descriptor['kind'] ?? null, ['transport', 'enrollment'], true)
            || !is_bool($descriptor['enabled'] ?? null)) { throw new RuntimeException('approval_descriptor_invalid'); }
        if ($descriptor['kind'] === 'transport') {
            if (array_diff(array_keys($descriptor), ['version', 'kind', 'enabled', 'operator_acceptance_sha256', 'pin']) !== []
                || !is_array($descriptor['pin'] ?? null)) { throw new RuntimeException('approval_descriptor_invalid'); }
            $transport = new UnixPrometheusTrafficTransport($this->root, $descriptor['pin']);
            if ($descriptor['enabled']) { $transport->verify(); }
            return DB::transaction(function () use ($descriptor, $transport): array {
                $old = ArvanObservationState::decode($this->raw(self::TRANSPORT));
                if ($descriptor['enabled']) { $transport->verify(); }
                elseif ($old === null || ($old['pin'] ?? null) !== $descriptor['pin']) {
                    throw new RuntimeException('disable_requires_existing_transport_pin');
                }
                $value = ['version' => 1, 'enabled' => $descriptor['enabled'], 'pin' => $descriptor['pin'],
                    'operator_acceptance_sha256' => $descriptor['operator_acceptance_sha256'], 'approved_at' => time()];
                $this->put(self::TRANSPORT, $value);
                $this->raw(self::RUN);
                $this->put(self::RUN, ['version' => 1, 'checked_at' => time(), 'checked' => 0, 'observed' => 0, 'unknown' => 0,
                    'status' => $value['enabled'] ? 'transport_approved_awaiting_observation' : 'disabled']);
                return ['status' => $value['enabled'] ? 'transport_enabled' : 'transport_disabled'];
            }, 3);
        }
        $identity = $descriptor['identity'] ?? null;
        if (!is_array($identity) || !ArvanTrafficObservation::validIdentity($identity)
            || !array_key_exists('expected_previous_enrollment_id', $descriptor)
            || ($descriptor['expected_previous_enrollment_id'] !== null
                && (!is_string($descriptor['expected_previous_enrollment_id'])
                    || preg_match('/\A[a-f0-9]{32}\z/D', $descriptor['expected_previous_enrollment_id']) !== 1))
            || array_diff(array_keys($descriptor), ['version', 'kind', 'enabled', 'identity',
                'operator_acceptance_sha256', 'expected_previous_enrollment_id']) !== []) {
            throw new RuntimeException('approval_descriptor_invalid');
        }
        return DB::transaction(function () use ($descriptor, $identity): array {
            if (!$this->binding($identity) && $descriptor['enabled']) { throw new RuntimeException('current_resource_binding_unverified'); }
            $key = self::ENROLLMENT.$identity['service_id'];
            $old = ArvanObservationState::decode($this->raw($key));
            if ($old !== null && !ArvanObservationState::enrollment($old, $identity['service_id'])) {
                throw new RuntimeException('existing_enrollment_requires_review');
            }
            if (($old['identity']['enrollment_id'] ?? null) !== $descriptor['expected_previous_enrollment_id']) {
                throw new RuntimeException('previous_enrollment_changed');
            }
            $same = $old !== null && ArvanTrafficObservation::sameIdentity($old['identity'], $identity);
            if ($old !== null && !$same && $old['identity']['enrollment_id'] === $identity['enrollment_id']) {
                throw new RuntimeException('identity_change_requires_new_generation');
            }
            $generationKey = self::GENERATION.$identity['service_id'].':'.$identity['enrollment_id'];
            if (!$same && ($this->raw($generationKey) !== null || $this->raw($this->checkpointKey($identity)) !== null)) {
                throw new RuntimeException('generation_reuse_refused');
            }
            $value = ['version' => 1, 'provider' => 'arvan', 'enabled' => $descriptor['enabled'], 'identity' => $identity,
                'operator_acceptance_sha256' => $descriptor['operator_acceptance_sha256'], 'approved_at' => time(),
                'checkpoint_sha256' => $same ? $old['checkpoint_sha256'] : null];
            if (!$same) { $this->put($generationKey, $value); }
            $this->put($key, $value);
            $this->raw(self::STATUS.$identity['service_id']);
            $this->put(self::STATUS.$identity['service_id'], ['version' => 1, 'identity' => $identity,
                'result' => ArvanObservationState::unknown($value['enabled'] ? 'enrollment_awaiting_fresh_observation' : 'enrollment_disabled', time())]);
            return ['status' => $value['enabled'] ? 'enrollment_enabled' : 'enrollment_disabled', 'service_id' => $identity['service_id']];
        }, 3);
    }

    private function capture(int $serviceId): array
    {
        if (DB::transactionLevel() !== 0) { throw new RuntimeException('observation_requires_outer_transaction_free'); }
        // The hint only chooses the established lock order; it is rechecked under locks.
        $hint = ArvanObservationState::decode($this->raw(self::ENROLLMENT.$serviceId, false));
        if (!ArvanObservationState::enrollment($hint, $serviceId)) { throw new RuntimeException('enrollment_invalid'); }
        return DB::transaction(function () use ($hint, $serviceId): array {
            if (!$this->binding($hint['identity'])) { throw new RuntimeException('current_resource_binding_unverified'); }
            $enrollmentRaw = $this->raw(self::ENROLLMENT.$serviceId);
            $enrollment = ArvanObservationState::decode($enrollmentRaw);
            if (!ArvanObservationState::enrollment($enrollment, $serviceId) || $enrollment['enabled'] !== true
                || !ArvanTrafficObservation::sameIdentity($hint['identity'], $enrollment['identity'])) {
                throw new RuntimeException('enrollment_disabled_or_changed');
            }
            $transportRaw = $this->raw(self::TRANSPORT);
            $transport = ArvanObservationState::decode($transportRaw);
            if (($transport['enabled'] ?? null) !== true || ($transport['version'] ?? null) !== 1
                || !ArvanObservationState::sha($transport['operator_acceptance_sha256'] ?? null) || !is_array($transport['pin'] ?? null)) {
                throw new RuntimeException('transport_approval_invalid');
            }
            $checkpointRaw = $this->raw($this->checkpointKey($enrollment['identity']));
            return ['enrollment_raw' => $enrollmentRaw, 'transport_raw' => $transportRaw, 'enrollment' => $enrollment,
                'transport' => $transport, 'checkpoint_raw' => $checkpointRaw,
                'previous' => ArvanObservationState::previous($enrollment, $checkpointRaw)];
        }, 3);
    }

    public function poll(int $serviceId, ?Closure $sourceFactory = null): array
    {
        $failureToken = null;
        try {
            $failureToken = ['enrollment' => $this->view(self::ENROLLMENT.$serviceId, false),
                'status' => $this->view(self::STATUS.$serviceId, false)];
            $snapshot = $this->capture($serviceId);
            $uds = new UnixPrometheusTrafficTransport($this->root, $snapshot['transport']['pin']);
            $source = $sourceFactory === null ? new PrivatePrometheusTrafficSource(unixTransport: $uds) : $sourceFactory($uds);
            $now = time();
            // No DB transaction, provider, guest, finance or lifecycle operation during this read.
            $read = $source->read($snapshot['enrollment']['identity'], $now);
            $advance = ArvanObservationState::advance($snapshot['enrollment']['identity'], $snapshot['previous'],
                $read['sample'] ?? null, time());
            if (($read['status'] ?? null) !== 'observed') {
                $advance['result'] = ArvanObservationState::unknown('source_evidence_unavailable', time());
                $advance['checkpoint'] = null;
            }
            return DB::transaction(function () use ($snapshot, $advance, $serviceId): array {
                $identity = $snapshot['enrollment']['identity'];
                if (!$this->binding($identity)) { throw new RuntimeException('current_resource_binding_changed'); }
                $enrollmentRaw = $this->raw(self::ENROLLMENT.$serviceId);
                $transportRaw = $this->raw(self::TRANSPORT);
                $checkpointRaw = $this->raw($this->checkpointKey($identity));
                if ($enrollmentRaw !== $snapshot['enrollment_raw'] || $transportRaw !== $snapshot['transport_raw']
                    || $checkpointRaw !== $snapshot['checkpoint_raw']) { throw new RuntimeException('observation_snapshot_changed'); }
                (new UnixPrometheusTrafficTransport($this->root, $snapshot['transport']['pin']))->verify();
                if ($advance['checkpoint'] !== null) {
                    $checkpoint = $advance['checkpoint'];
                    $this->put($this->checkpointKey($identity), $checkpoint);
                    $enrollment = $snapshot['enrollment'];
                    $enrollment['checkpoint_sha256'] = hash('sha256', ArvanObservationState::encode($checkpoint));
                    $this->put(self::ENROLLMENT.$serviceId, $enrollment);
                }
                $this->put(self::STATUS.$serviceId, ['version' => 1, 'identity' => $identity, 'result' => $advance['result']]);
                return ['service_id' => $serviceId] + $advance['result'];
            }, 3);
        } catch (Throwable) {
            $result = ArvanObservationState::unknown('trusted_observation_unavailable', time());
            try {
                DB::transaction(function () use ($serviceId, $result, $failureToken): void {
                    $enrollment = $this->view(self::ENROLLMENT.$serviceId);
                    $status = $this->view(self::STATUS.$serviceId);
                    if ($failureToken === null || $enrollment !== $failureToken['enrollment'] || $status !== $failureToken['status']) {
                        return; // A late failed poll cannot overwrite a newer enrollment/result.
                    }
                    // Failure is explicitly unbound and cannot advance a checkpoint.
                    $this->put(self::STATUS.$serviceId, ['version' => 1, 'service_id' => $serviceId,
                        'enrollment_record_state' => $enrollment['kind'],
                        'enrollment_sha256' => $enrollment['value'] === null ? null : hash('sha256', $enrollment['value']), 'result' => $result]);
                }, 3);
            } catch (Throwable) { /* Unavailable storage cannot be reported as saved evidence. */ }
            return ['service_id' => $serviceId] + $result;
        }
    }

    /** Rotate bounded discovery past invalid/orphan records instead of poisoning the first page. */
    public function run(int $limit = 20): array
    {
        try {
            if ($this->transport(false) === null) { return ['status' => 'disabled', 'checked' => 0, 'observed' => 0, 'unknown' => 0]; }
        } catch (Throwable) {
            $result = ['status' => 'trusted_transport_configuration_unavailable', 'checked' => 0, 'observed' => 0, 'unknown' => 0];
            $this->recordRun($result);
            return $result;
        }
        $limit = max(1, min(20, $limit));
        try {
            $progress = ArvanObservationState::decode($this->raw(self::PROGRESS, false));
            if ($progress !== null && (($progress['version'] ?? null) !== 1 || !is_string($progress['cursor'] ?? null)
                || strlen($progress['cursor']) > 80 || !str_starts_with($progress['cursor'], self::ENROLLMENT))) {
                throw new RuntimeException('progress_record_invalid');
            }
        } catch (Throwable) {
            $result = ['status' => 'observation_progress_requires_review', 'checked' => 0, 'observed' => 0, 'unknown' => 0];
            $this->recordRun($result);
            return $result;
        }
        $cursor = is_string($progress['cursor'] ?? null) ? $progress['cursor'] : '';
        $prefix = str_replace(['!', '_', '%'], ['!!', '!_', '!%'], self::ENROLLMENT).'%';
        $query = fn () => DB::table('settings')
            ->whereRaw(DB::connection()->getQueryGrammar()->wrap('key')." LIKE ? ESCAPE '!'", [$prefix]);
        $keys = $query()->where('key', '>', $cursor)->orderBy('key')->limit($limit)->pluck('key');
        if ($keys->isEmpty()) { $keys = $query()->orderBy('key')->limit($limit)->pluck('key'); }
        $out = ['status' => 'enabled_observation_only', 'checked' => 0, 'observed' => 0, 'unknown' => 0];
        $end = hrtime(true) + 35_000_000_000;
        foreach ($keys as $key) {
            if (hrtime(true) >= $end) { break; }
            $cursor = $key; $out['checked']++;
            $id = substr($key, strlen(self::ENROLLMENT));
            $result = preg_match('/\A[1-9]\d{0,9}\z/D', $id) === 1
                ? $this->poll((int) $id) : ['status' => 'unknown'];
            $out[$result['status'] === 'observed' ? 'observed' : 'unknown']++;
        }
        DB::transaction(function () use ($cursor, $out): void {
            if ($out['checked'] > 0) {
                $this->raw(self::PROGRESS);
                $this->put(self::PROGRESS, ['version' => 1, 'cursor' => $cursor, 'checked_at' => time()]);
            }
            $this->raw(self::RUN);
            $this->put(self::RUN, ['version' => 1, 'checked_at' => time()] + $out);
        }, 3);
        return $out;
    }

    private function recordRun(array $result): void
    {
        DB::transaction(function () use ($result): void {
            $this->raw(self::RUN);
            $this->put(self::RUN, ['version' => 1, 'checked_at' => time()] + $result);
        }, 3);
    }
}
