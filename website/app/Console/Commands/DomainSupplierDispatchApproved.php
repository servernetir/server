<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\DomainSupplierOperation;
use App\Services\Domain\DomainSupplierLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Financial worker: exact pilot approval or bounded production authorization. */
class DomainSupplierDispatchApproved extends Command
{
    protected $signature = 'domains:supplier-dispatch-approved {--limit=10} {--id= : Exact approved operation ID}';
    protected $description = 'Dispatch only individually approved prepared domain supplier operations';

    public function handle(DomainSupplierLifecycle $lifecycle): int
    {
        if (! config('domain_suppliers.enabled', false) || ! Schema::hasTable('domain_supplier_operations')) {
            return self::SUCCESS;
        }
        $operations = DomainSupplierOperation::where('status', 'prepared')->whereNull('sent_at')
            ->where(function ($query) {
                $query->where(fn ($approved) => $approved->whereNotNull('approved_at')->whereColumn('approval_hash', 'request_hash'));
                if (config('domain_suppliers.authorization_mode') === 'production') {
                    $query->orWhereIn('supplier_account_id', (array) config('domain_suppliers.production_account_ids', []));
                }
            })
            ->when($this->option('id'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id');
        $limit = max(1, min(100, (int) $this->option('limit')));
        $states = [];
        $approved = 0;
        $reviewed = 0;
        // Keyset pagination passes ineligible prepared rows. Only dispatched
        // work consumes the batch allowance; no offset can skip mutated rows.
        foreach ($operations->lazyById(100) as $operation) {
            $reviewed++;
            $domain = Domain::find($operation->domain_id);
            if (! $domain) { continue; }
            $account = \App\Models\DomainSupplierAccount::find($operation->supplier_account_id);
            if ((int) data_get($operation->request_context, 'approval.actor_id') <= 0
                && (! $account || ! app(\App\Services\Domain\DomainSaleAuthorization::class)->allows($operation, $domain, $account))) continue;
            $approved++;
            // execute checks current owner rights, exact hash, invoice settlement,
            // quote, account, and the durable one-send claim before any financial I/O.
            $result = $lifecycle->execute($domain, $operation->operation, $operation->period_months);
            $state = $result['status'] ?? 'manual_review';
            $states[$state] = ($states[$state] ?? 0) + 1;
            if ($approved >= $limit) break;
        }
        $this->line(json_encode(['reviewed' => $reviewed, 'approved' => $approved, 'states' => $states], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
