<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * نتیجهٔ احراز هویت ایرانی.
 * نام از ثبت احوال می‌آید نه از فرم کاربر — پس اینجا منبع رسمی نام است.
 */
class IdentityVerification extends Model
{
    protected $fillable = [
        'customer_id', 'national_id_enc', 'national_id_hash', 'birth_date', 'mobile',
        'shahkar_matched', 'shahkar_at', 'first_name', 'last_name', 'father_name',
        'status', 'fail_reason', 'provider', 'verified_at',
    ];

    protected $hidden = ['national_id_enc', 'national_id_hash'];

    protected function casts(): array
    {
        return [
            'birth_date'      => 'date',
            'shahkar_matched' => 'boolean',
            'shahkar_at'      => 'datetime',
            'verified_at'     => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** کد ملی خام — فقط جایی که واقعاً لازم است */
    public function nationalId(): ?string
    {
        return filled($this->national_id_enc) ? Crypt::decryptString($this->national_id_enc) : null;
    }

    /** برای نمایش: ۱۲۳****۸۹ */
    public function maskedNationalId(): ?string
    {
        $id = $this->nationalId();

        return $id ? substr($id, 0, 3).str_repeat('*', 4).substr($id, -3) : null;
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
