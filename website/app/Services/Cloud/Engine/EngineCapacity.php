<?php

namespace App\Services\Cloud\Engine;

use App\Models\{EngineCapacityHold, EngineNode, EngineVm};

/** Caller holds the node row lock for every admission or release. */
class EngineCapacity
{
    public function usage(EngineNode $node, bool $currentRead = false): array
    {
        $vms = EngineVm::where('node_id', $node->id)->where('status', '!=', 'deleted');
        $holds = EngineCapacityHold::where('node_id', $node->id)->where('status', 'reserved');
        // Admission can be nested inside an API/payment transaction whose
        // REPEATABLE READ snapshot predates the node lock. Locking reads see
        // reservations committed by the previous lock owner. Deadlocks retry
        // the entire enclosing admission, never an isolated capacity write.
        if ($currentRead) { $vms->lockForUpdate(); $holds->lockForUpdate(); }
        $sum = 'COALESCE(SUM(vcpu),0) vcpu, COALESCE(SUM(ram_mb),0) ram_mb, COALESCE(SUM(disk_gb),0) disk_gb';
        $vms = $vms->selectRaw($sum)->first();
        $holds = $holds->selectRaw($sum)->first();
        return collect(['vcpu', 'ram_mb', 'disk_gb'])->mapWithKeys(fn ($key) => [$key => (int) $vms->$key + (int) $holds->$key])->all();
    }
}
