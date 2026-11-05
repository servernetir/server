<?php

namespace App\Services\Cloud\Engine;

use App\Models\EngineOperation;
use App\Models\EngineImage;
use App\Models\EngineVm;
use Illuminate\Support\Facades\DB;

class EngineOperations
{
    public const ACTIONS = ['create', 'power', 'delete', 'reset_password', 'hostname', 'rebuild', 'resize', 'metadata'];

    public function submit(EngineVm $vm, string $action, array $payload, string $idempotencyKey): EngineOperation
    {
        if (! in_array($action, self::ACTIONS, true) || $idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new \InvalidArgumentException('unsupported_operation');
        }
        if ($action === 'power' && ! in_array($payload['action'] ?? '', ['on', 'off', 'reboot', 'reset', 'shutdown'], true)) {
            throw new \InvalidArgumentException('invalid_power_action');
        }
        if ($action === 'hostname' && (! is_string($payload['hostname'] ?? null)
            || ! preg_match('/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*\z/D', $payload['hostname']))) {
            throw new \InvalidArgumentException('invalid_hostname');
        }
        if ($action === 'metadata') $payload = app(EnginePublicMetadata::class)->validate($payload);
        ksort($payload);
        $fingerprint = hash('sha256', json_encode([$vm->uuid, $action, $payload], JSON_THROW_ON_ERROR));
        $key = hash('sha256', $vm->service_id.'|'.$idempotencyKey);

        return DB::transaction(function () use ($vm, $action, $payload, $fingerprint, $key) {
            // The VM row is the serialization point for all operations on this guest.
            \App\Models\Service::whereKey($vm->service_id)->lockForUpdate()->firstOrFail();
            $vm = EngineVm::whereKey($vm->id)->lockForUpdate()->firstOrFail();
            $existing = EngineOperation::where('idempotency_key', $key)->first();
            if ($existing) {
                if (! hash_equals($existing->fingerprint, $fingerprint)) {
                    throw new \RuntimeException('idempotency_conflict');
                }
                return $existing;
            }
            if (in_array($action, ['reset_password', 'hostname'], true)
                && ($vm->status !== 'running' || ! $vm->image->accepted() || ($vm->image->metadata['guest_agent_verified'] ?? false) !== true
                    || ($action === 'hostname' && strtolower($vm->image->family) === 'windows'))) {
                throw new \RuntimeException('unsupported_operation');
            }
            // A deadline is not evidence that a previously submitted remote mutation never happened.
            // Blocked intents retain their reservations until the same operation is explicitly reconciled.
            $active = EngineOperation::where('vm_id', $vm->id)->whereIn('status', ['pending', 'running', 'uncertain', 'blocked'])
                ->lockForUpdate()->first();
            if ($active) {
                // Cancellation before any remote intent is safe only after fencing out an absent/expired worker.
                if ($action === 'delete' && $active->action === 'create'
                    && in_array($active->phase, ['queued', 'clone_prepare'], true)
                    && ($active->lease_until === null || $active->lease_until->isPast())) {
                    $active->update(['status' => 'cancelled', 'completed_at' => now(), 'lease_token' => null, 'lease_until' => null]);
                    DB::table('cloud_engine_operation_events')->insert(['operation_id' => $active->id,
                        'phase' => $active->phase, 'status' => 'cancelled', 'error_code' => null, 'created_at' => now()]);
                } else {
                    throw new \RuntimeException('operation_in_progress');
                }
            }
            if ($action === 'rebuild') {
                if (($payload['confirmed'] ?? false) !== true || ! is_string($payload['image'] ?? null)) {
                    throw new \RuntimeException('destructive_confirmation_required');
                }
                $image = EngineImage::where('node_id', $vm->node_id)->where('key', $payload['image'])->first();
                if (! $image || ! $image->accepted() || $image->arch !== $vm->node->arch
                    || (int) $image->min_ram_mb > $vm->ram_mb || (int) $image->min_disk_gb > $vm->disk_gb) {
                    throw new \RuntimeException('unsupported_operation');
                }
                $payload['image_id'] = $image->id;
                $payload['image_snapshot'] = ['key' => $image->key, 'template_vmid' => $image->template_vmid,
                    'checksum_sha256' => $image->checksum_sha256, 'arch' => $image->arch];
                $payload['password'] = bin2hex(random_bytes(24));
            }
            if ($action === 'reset_password') { $payload['password'] = bin2hex(random_bytes(24)); }
            if ($action === 'resize') { $payload = app(EngineResize::class)->prepare($vm, $payload); }
            $operation = EngineOperation::create([
                'vm_id' => $vm->id, 'action' => $action, 'idempotency_key' => $key,
                'fingerprint' => $fingerprint, 'payload' => $payload, 'phase' => 'queued',
                'status' => 'pending', 'deadline_at' => now()->addMinutes(30),
            ]);
            DB::table('cloud_engine_operation_events')->insert([
                'operation_id' => $operation->id, 'phase' => 'queued', 'status' => 'pending',
                'error_code' => null, 'created_at' => now(),
            ]);
            return $operation;
        }, 3);
    }
}
