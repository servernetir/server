<?php

namespace App\Services\Cloud\Engine;

use App\Models\EngineImage;
use App\Models\EngineOperation;
use App\Models\EngineVm;
use App\Models\Service;

class EngineAccountPresenter
{
    public function snapshot(Service $service, ?string $month = null): ?array
    {
        $vm = EngineVm::where('service_id', $service->id)->with(['image', 'node'])->first();
        if (! $vm) {
            return null;
        }
        $latest = EngineOperation::where('vm_id', $vm->id)->latest('id')->first();
        $history = EngineOperation::where('vm_id', $vm->id)->latest('id')->limit(10)->get()
            ->map(fn ($op) => app(EngineApiService::class)->operationDto($op))->all();
        $busy = EngineOperation::where('vm_id', $vm->id)->whereIn('status', ['pending', 'running', 'uncertain', 'blocked'])->exists();
        $writable = ! $busy && app(EngineBillingPolicy::class)->accepts($service) && ! $service->isInternalInfrastructure() && $service->status === 'active' && ! $service->cancelled_at
            && in_array($vm->status, ['running', 'off'], true)
            && (! $service->next_due_at || ! $service->next_due_at->copy()->endOfDay()->isPast());
        $guest = $vm->image->accepted() && ($vm->image->metadata['guest_agent_verified'] ?? false) === true;
        $images = EngineImage::where('node_id', $vm->node_id)->orderBy('family')->orderBy('version')->get()
            ->filter(fn ($image) => $image->accepted() && $image->arch === $vm->node->arch
                && $image->min_ram_mb <= $vm->ram_mb && $image->min_disk_gb <= $vm->disk_gb)
            ->map(fn ($image) => ['key' => $image->key, 'label' => trim($image->family.' '.$image->version)])->values()->all();
        $traffic = app(EngineApiService::class)->trafficDto(app(EngineTrafficService::class)->report($vm, $month));
        $resizePlans = $writable ? app(EngineResize::class)->options($vm) : [];
        $publicMetadata = app(EnginePublicMetadata::class)->present($vm);
        // Browser JSON must preserve 64-bit byte totals exactly; never pass
        // these counters through JavaScript floating-point arithmetic.

        return ['timezone' => $service->customer?->timezone ?: 'Asia/Tehran',
            'billing' => ['cycle' => $service->cycle, 'label' => $service->cycleLabel(),
                'months' => Service::monthsIn($service->cycle), 'amount' => (string) $service->price,
                'currency' => $service->currency_code ?: 'IRT', 'next_due_at' => $service->next_due_at?->toDateString()],
            'operation' => $latest ? app(EngineApiService::class)->operationDto($latest) : null, 'history' => $history,
            ...$publicMetadata,
            'traffic' => $traffic, 'images' => $images, 'busy' => $busy, 'resize_plans' => $resizePlans,
            'hostname' => (string) ($service->cloudInstance?->hostname ?? ''),
            'capabilities' => ['power' => $writable, 'rebuild' => $writable && $images !== [] && ! $publicMetadata['protection']['rebuild'], 'resize' => $resizePlans !== [],
                'metadata' => ! $busy && app(EnginePublicMetadata::class)->editable($service, $vm),
                'reset_password' => $writable && $guest,
                'hostname' => $writable && $guest && strtolower($vm->image->family) !== 'windows',
                'console' => ! $busy && app(EngineConsoleService::class)->available($vm, (int) $service->customer_id)]];
    }
}
