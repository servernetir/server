<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only moderation history; saving the status and this row is one transaction. */
class CustomerStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'customer_id', 'actor_id', 'actor_name', 'from_status', 'to_status', 'reason',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
