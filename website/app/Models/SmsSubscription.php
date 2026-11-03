<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsSubscription extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['identity', 'initial_password', 'operation_id'];

    protected function casts(): array
    {
        return ['identity' => 'encrypted:array', 'initial_password' => 'encrypted', 'quote' => 'array', 'pending_quote' => 'array',
            'consented_at' => 'datetime', 'attempted_at' => 'datetime', 'checked_at' => 'datetime',
            'confirmed_until' => 'date', 'credit_irr' => 'decimal:6', 'upstream_user_id' => 'integer'];
    }

    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
