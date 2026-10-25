<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletRefundRequest extends Model
{
    public const OPEN = ['requested', 'approved'];

    protected $guarded = [];

    protected $hidden = ['bank_snapshot', 'payload_hash', 'payout_hash', 'payout_fingerprint', 'source_note'];

    protected function casts(): array
    {
        return ['bank_snapshot' => 'encrypted:array', 'amount_irt' => 'integer',
            'reviewed_at' => 'datetime', 'paid_at' => 'datetime', 'payout_date' => 'date'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
