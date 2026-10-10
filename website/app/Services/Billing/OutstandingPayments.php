<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;

class OutstandingPayments
{
    /** Deliberately independent of the selected calendar month and layer preferences. */
    public function query(): Builder
    {
        return Invoice::query()->outstanding()
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '<', now()))
            ->where(fn ($q) => $q->whereNull('service_id')->orWhereNotIn('service_id', (array) config('billing.internal_service_ids', [])));
    }

    public function summary(): array
    {
        $query = $this->query();

        return [
            'count' => (clone $query)->count(),
            'missing_due' => (clone $query)->whereNull('due_at')->count(),
            'rows' => $query->orderByRaw('CASE WHEN due_at IS NULL THEN 0 ELSE 1 END')->orderBy('due_at')->orderBy('id')->limit(100)->get(),
        ];
    }
}
