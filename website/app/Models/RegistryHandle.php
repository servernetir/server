<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** شناسهٔ ثبت دامنه نزد یک رجیستری (IRNIC، OpenProvider، …). */
class RegistryHandle extends Model
{
    protected $fillable = ['customer_profile_id', 'registry', 'handle', 'role', 'status', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'verified_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }
}
