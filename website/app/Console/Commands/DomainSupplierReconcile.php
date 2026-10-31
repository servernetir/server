<?php

namespace App\Console\Commands;

use App\Models\DomainSupplierOperation;
use App\Services\Domain\DomainSupplierLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Read-only provider reconciliation continues even while new sales are disabled. */
class DomainSupplierReconcile extends Command
{
    protected $signature = 'domains:supplier-reconcile {--limit=25} {--id= : Exact operation journal ID}';
    protected $description = 'Reconcile queued or uncertain domain supplier operations without resubmitting them';

    public function handle(DomainSupplierLifecycle $lifecycle): int
    {
        if (! Schema::hasTable('domain_supplier_operations')) {
            return self::SUCCESS;
        }
        $operations = DomainSupplierOperation::whereIn('status', ['submitting', 'queued', 'unknown'])
            ->when($this->option('id'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('reconciled_at')->orderBy('id')
            ->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        $states = ['succeeded' => 0, 'queued' => 0, 'failed' => 0, 'unknown' => 0, 'manual_review' => 0];
        foreach ($operations as $operation) {
            try {
                $result = str_starts_with($operation->operation, 'manage_')
                    ? app(\App\Services\Domain\DomainManagement::class)->reconcile($operation)
                    : $lifecycle->reconcile($operation);
                $state = $result['status'] ?? 'unknown';
            } catch (\Throwable) {
                $state = 'unknown';
            }
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        $this->line(json_encode(['checked' => $operations->count(), 'states' => $states], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
