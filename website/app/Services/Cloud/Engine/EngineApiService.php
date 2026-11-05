<?php

namespace App\Services\Cloud\Engine;

use App\Models\Customer;
use App\Models\EngineApiRequest;
use App\Models\EngineOperation;
use App\Models\EngineVm;
use App\Models\Service;
use App\Services\Billing\UndeliveredRefund;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Support\Facades\DB;

class EngineApiService
{
    public function owned(Customer $customer, string $uuid): EngineVm
    {
        return EngineVm::with(['service.cloudInstance', 'address', 'image'])
            ->where('uuid', $uuid)->whereHas('service', fn ($q) => $q->where('customer_id', $customer->id))->firstOrFail();
    }

    public function create(Customer $customer, int $serviceId, string $key): EngineOperation
    {
        return $this->request($customer, $key, ['create', $serviceId], function () use ($customer, $serviceId) {
            $service = Service::where('customer_id', $customer->id)->whereKey($serviceId)->lockForUpdate()->firstOrFail();
            if (! app(EnginePaidGate::class)->allows($service)) {
                throw new EngineApiError('confirmed_payment_required', 422);
            }
            $vm = EngineVm::where('service_id', $service->id)->first();
            if (! $vm) {
                // The existing provisioner owns fraud review, instance records,
                // deterministic service identity and customer delivery notices.
                app(CloudProvisioner::class)->provision($service);
                $vm = EngineVm::where('service_id', $service->id)->first();
            }
            if (! $vm) {
                throw new EngineApiError('provisioning_pending_review');
            }
            $operation = EngineOperation::where('vm_id', $vm->id)->where('action', 'create')->oldest('id')->first();
            if (! $operation) {
                throw new EngineApiError('provisioning_pending_review');
            }

            return $operation;
        });
    }

    public function mutate(Customer $customer, string $uuid, string $action, array $payload, string $key): EngineOperation
    {
        if ($action === 'metadata') $payload = app(EnginePublicMetadata::class)->validate($payload);
        ksort($payload);

        return $this->request($customer, $key, [$action, $uuid, $payload], function () use ($customer, $uuid, $action, $payload, $key) {
            $vm = $this->owned($customer, $uuid);
            $service = Service::whereKey($vm->service_id)->lockForUpdate()->firstOrFail();
            $vm->refresh(); // The service lock also serializes metadata commits and customer cancellation.
            if (app(EnginePublicMetadata::class)->protected($vm, $action)) throw new EngineApiError('server_protected', 403);
            if (! app(EngineBillingPolicy::class)->accepts($service) && ! in_array($action, ['delete', 'metadata'], true)) {
                throw new EngineApiError('service_not_available');
            }
            if ($service->cancelled_at !== null || ($action !== 'metadata' && ($service->isDead()
                || ($service->next_due_at !== null && $service->next_due_at->copy()->endOfDay()->isPast())))) {
                throw new EngineApiError('service_not_available');
            }
            if ($service->isInternalInfrastructure()) {
                throw new EngineApiError('service_protected', 403);
            }
            if (($action === 'metadata' && ! app(EnginePublicMetadata::class)->editable($service, $vm))
                || (! in_array($action, ['delete', 'metadata'], true) && ($service->status !== 'active' || ! in_array($vm->status, ['running', 'off'], true)))) {
                throw new EngineApiError('server_not_ready');
            }
            if (! in_array($action, EngineOperations::ACTIONS, true)) {
                throw new EngineApiError('unsupported_operation', 422);
            }
            try {
                $operation = app(EngineOperations::class)->submit($vm, $action, $payload, 'api:'.$customer->id.':'.$key);
            } catch (\RuntimeException $e) {
                if (in_array($e->getMessage(), ['unsupported_operation', 'destructive_confirmation_required',
                    'resize_requires_paid_off_server', 'resize_target_unavailable', 'resize_currency_unavailable', 'insufficient_capacity'], true)) {
                    throw new EngineApiError($e->getMessage(), 422);
                }
                if (in_array($e->getMessage(), ['idempotency_conflict', 'operation_in_progress'], true)) {
                    throw new EngineApiError($e->getMessage());
                }
                throw $e;
            }
            if ($action === 'delete') {
                // Match existing customer cancellation: stop billing before
                // infrastructure release, and use its existing refund service.
                app(UndeliveredRefund::class)->maybeRefund($service, 'customer');
                $service->update(['status' => 'terminated', 'cancelled_at' => now(), 'provision_status' => Service::PROVISION_RELEASING]);
            }

            return $operation;
        });
    }

    private function request(Customer $customer, string $key, array $input, callable $execute): EngineOperation
    {
        if (! preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/D', $key)) {
            throw new EngineApiError('idempotency_key_required', 422);
        }
        $hash = hash('sha256', $key);
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        $operation = DB::transaction(function () use ($customer, $hash, $fingerprint, $execute) {
            // One customer row serializes admission even when the request row
            // does not exist yet; the unique index remains the final guard.
            $current = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($current->status !== 'active') {
                throw new EngineApiError('account_not_available', 403);
            }
            $prior = EngineApiRequest::where('customer_id', $customer->id)->where('key_hash', $hash)->first();
            if ($prior) {
                if (! hash_equals($prior->fingerprint, $fingerprint)) {
                    throw new EngineApiError('idempotency_conflict');
                }

                return EngineOperation::whereKey($prior->operation_id)
                    ->whereHas('vm.service', fn ($q) => $q->where('customer_id', $customer->id))->firstOrFail();
            }
            try {
                $operation = $execute();
            } catch (EngineApiError $e) {
                if ($e->publicCode === 'provisioning_pending_review') {
                    // Keep the existing fraud/review/retry outcome durable even
                    // when the pipeline could not create an engine operation.
                    return null;
                }
                throw $e;
            }
            EngineApiRequest::create(['customer_id' => $customer->id, 'key_hash' => $hash,
                'fingerprint' => $fingerprint, 'operation_id' => $operation->id]);

            return $operation;
        }, 3);
        if (! $operation) {
            throw new EngineApiError('provisioning_pending_review');
        }

        return $operation;
    }

    public function serverDto(EngineVm $vm): array
    {
        return [
            'id' => $vm->uuid, 'service_id' => (int) $vm->service_id,
            'name' => (string) $vm->service->name,
            'hostname' => $vm->service->cloudInstance?->hostname,
            'status' => match ($vm->status) {
                'running' => 'running', 'off' => 'off', 'deleted' => 'deleted',
                'reserved', 'building' => 'building', 'deleting' => 'deleting', default => 'pending_review',
            },
            'ipv4' => $vm->status === 'deleted' ? null : $vm->address?->address,
            'resources' => ['vcpu' => (int) $vm->vcpu, 'ram_mb' => (int) $vm->ram_mb, 'disk_gb' => (int) $vm->disk_gb],
            'image' => ['key' => $vm->image?->key, 'family' => $vm->image?->family, 'version' => $vm->image?->version],
            'billing' => ['cycle' => $vm->service->cycle, 'months' => Service::monthsIn($vm->service->cycle),
                'currency' => $vm->service->currency_code ?: 'IRT', 'contract_amount' => (string) $vm->service->price,
                'next_due_at' => $vm->service->next_due_at?->toDateString()],
            'created_at' => $vm->created_at?->toIso8601String(),
            ...app(EnginePublicMetadata::class)->present($vm),
        ];
    }

    public function operationDto(EngineOperation $operation): array
    {
        return ['id' => (string) $operation->id, 'server_id' => $operation->vm->uuid,
            'action' => $operation->action,
            'status' => in_array($operation->status, ['pending', 'running', 'succeeded', 'failed'], true) ? $operation->status : 'pending_review',
            'error' => $operation->error_code ? 'operation_requires_review' : null,
            'created_at' => $operation->created_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String()];
    }

    public function metricsDto(array $result): array
    {
        return ['series' => array_intersect_key((array) ($result['series'] ?? []), array_flip([
            'cpu', 'ram_bytes', 'disk_bytes', 'net_in', 'net_out', 'disk_read', 'disk_write',
        ])), 'current' => array_intersect_key((array) ($result['current'] ?? []), array_flip([
            'status', 'uptime_seconds', 'last_boot_at', 'boot_time_estimated', 'cpu_fraction',
            'memory_bytes', 'memory_capacity_bytes', 'disk_allocated_bytes',
            'guest_filesystem_used_bytes', 'observed_at',
        ])), 'traffic_billing_source' => false];
    }

    public function trafficDto(array $report): array
    {
        $safe = array_intersect_key($report, array_flip(['month', 'timezone', 'in_bytes', 'out_bytes',
            'unit_bytes', 'samples', 'coverage', 'gaps', 'gap_reasons', 'billable', 'last_sample_at', 'stale', 'alerts']));
        // Decimal strings preserve exact 64-bit counters in JavaScript clients.
        $safe['in_bytes'] = (string) $report['in_bytes'];
        $safe['out_bytes'] = (string) $report['out_bytes'];

        return $safe;
    }
}
