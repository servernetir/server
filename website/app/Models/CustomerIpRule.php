<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** قاعدهٔ محدودسازی IP برای ورود. */
class CustomerIpRule extends Model
{
    protected $fillable = ['customer_id', 'cidr', 'action', 'label', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
