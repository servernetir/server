<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Financial attempt journal. A timeout is unknown, never permission to retry. */
class DomainSupplierOperation extends Model
{
    public const STATUSES = ['prepared', 'submitting', 'queued', 'succeeded', 'failed', 'unknown', 'manual_review'];

    protected $fillable = [
        'domain_id', 'invoice_id', 'supplier_account_id', 'operation', 'idempotency_key',
        'fqdn', 'active_fqdn', 'period_months', 'status', 'object_id', 'process_id', 'tracking_id',
        'request_hash', 'approved_at', 'approved_by', 'approval_hash', 'request_context', 'payment_snapshot', 'quote_snapshot',
        'error_code', 'sent_at', 'reconciled_at', 'attempts',
    ];

    protected $hidden = ['supplier_account_id', 'object_id', 'process_id', 'tracking_id', 'request_context', 'payment_snapshot', 'quote_snapshot', 'request_hash', 'approval_hash', 'approved_by', 'idempotency_key', 'active_fqdn'];

    protected function casts(): array
    {
        return ['request_context' => 'encrypted:array', 'payment_snapshot' => 'array',
            'quote_snapshot' => 'array', 'sent_at' => 'datetime', 'reconciled_at' => 'datetime',
            'period_months' => 'integer', 'attempts' => 'integer', 'approved_at' => 'datetime', 'approved_by' => 'integer'];
    }

    public function domain(): BelongsTo { return $this->belongsTo(Domain::class); }
    public function account(): BelongsTo { return $this->belongsTo(DomainSupplierAccount::class, 'supplier_account_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
