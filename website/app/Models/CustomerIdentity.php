<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ورود با گوگل/اپل/شماره. */
class CustomerIdentity extends Model
{
    protected $fillable = ['customer_id', 'provider', 'provider_uid', 'email_at_link', 'linked_at'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
