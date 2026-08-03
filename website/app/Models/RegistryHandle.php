<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** شناسهٔ ثبت دامنه نزد یک رجیستری (IRNIC، OpenProvider، …). */
class RegistryHandle extends Model
{
    protected $fillable = ['customer_profile_id', 'registry', 'handle', 'role', 'status', 'meta', 'sent_data'];

    /**
     * ⚠️ `sent_data` دادهٔ شخصیِ مالک است (نام، نشانی، تلفن) — از هر JSONای که
     * ممکن است به بیرون برود کنار گذاشته می‌شود.
     */
    protected $hidden = ['sent_data'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'sent_data' => 'array', 'verified_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_profile_id');
    }
}
