<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class IdentityRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload', 'result', 'provider_evidence', 'quote', 'fingerprint', 'key_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'result' => 'encrypted:array', 'provider_evidence' => 'encrypted:array',
            'quote' => 'array', 'hold_irt' => 'integer', 'charged_irt' => 'integer', 'discrepancy' => 'boolean',
            'decide_by' => 'datetime', 'retain_until' => 'datetime', 'dispatch_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    public function scopeHolding(Builder $q): Builder
    {
        return $q->whereIn('financial_status', ['reserved', 'pending_reconciliation'])->where('decide_by', '>', now());
    }

    public function receipt(): array
    {
        return ['request_id' => $this->public_id, 'service' => $this->service, 'processing_status' => $this->processing_status,
            'inquiry_outcome' => $this->inquiry_outcome, 'financial_status' => $this->financial_status,
            'charged' => $this->charged_irt, 'reserved' => $this->decide_by->isFuture() && in_array($this->financial_status, ['reserved', 'pending_reconciliation'], true) ? $this->hold_irt : 0,
            'currency' => 'IRT', 'price_version' => $this->quote['version'], 'subtotal' => $this->quote['sell_irt'],
            'tax' => $this->quote['tax_irt'], 'error' => $this->error_code,
            'result' => $this->retain_until->isFuture() ? $this->result : null,
            'result_expires_at' => $this->retain_until->toIso8601String()];
    }
}
