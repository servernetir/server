<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * مشتری — کاملاً جدا از User (که فقط کارکنان است).
 * با guard «customer» احراز می‌شود؛ هرگز به پنل مدیریت دسترسی ندارد.
 */
class Customer extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'code', 'email', 'phone', 'password', 'locale', 'timezone', 'status',
        'ip_restriction_mode',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'       => 'datetime',
            'phone_verified_at'       => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at'           => 'datetime',
            'locked_until'            => 'datetime',
            'password'                => 'hashed',
            // رمزنگاری در سطح مدل: مقدار خام هرگز وارد دیتابیس نمی‌شود
            'two_factor_secret'       => 'encrypted',
            'two_factor_recovery'     => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // شناسهٔ عمومی خودکار. id عددی هرگز به مشتری نشان داده نمی‌شود چون
        // تعداد کل مشتریان را لو می‌دهد.
        static::creating(function (self $c) {
            if (blank($c->code)) {
                $c->code = 'SN-'.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(CustomerProfile::class);
    }

    /** پروفایل پیش‌فرض برای سفارش جدید */
    public function defaultProfile(): ?CustomerProfile
    {
        return $this->profiles()->where('is_default', true)->first()
            ?? $this->profiles()->first();
    }

    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    public function ipRules(): HasMany
    {
        return $this->hasMany(CustomerIpRule::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && ($this->locked_until === null || $this->locked_until->isPast());
    }

    /** آیا این مشتری دست‌کم یک پروفایل تأییدشده دارد؟ */
    public function isVerified(): bool
    {
        return $this->profiles()->where('status', 'verified')->exists();
    }
}
