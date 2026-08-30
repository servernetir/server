<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

            /*
            | نمایندگی دامنه.
            |
            | ⚠️ بدونِ این cast، `reseller_level_locked_until` یک **رشته**
            | برمی‌گردد و `->isFuture()` روی آن خطا می‌دهد — یعنی مهلتِ تنزل
            | اصلاً کار نمی‌کند. و چون آن کد داخلِ کرون است، خطایش فقط در لاگِ
            | کرون می‌نشیند نه در سایت: نماینده‌ها بی‌صدا سطحشان را از دست
            | می‌دهند یا برای همیشه نگه می‌دارند، بسته به اینکه استثنا کجا
            | گرفته شود.
            */
            'is_reseller'                 => 'boolean',
            'reseller_joined_at'          => 'datetime',
            'reseller_level_reviewed_at'  => 'datetime',
            'reseller_level_locked_until' => 'datetime',
            'reseller_volume'             => 'integer',
            'reseller_bonus_pct'          => 'integer',
            'reseller_daily_cap_irt'      => 'integer',
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

    public function apiTokens(): HasMany
    {
        return $this->hasMany(CustomerApiToken::class);
    }

    /**
     * آیا قوانینِ IP این ورود/درخواست را مسدود می‌کنند؟
     *
     * فقط در حالتِ «enforce»: قاعدهٔ deny که بخورد → مسدود؛ اگر قاعدهٔ allow
     * وجود داشته باشد و IP با هیچ‌کدام نخورد → مسدود. «off»/«warn» هرگز مسدود
     * نمی‌کنند (پیش‌فرض off). این هم در ورود و هم در هر درخواستِ پنل (میدل‌ور)
     * استفاده می‌شود تا نشستِ فعال/کوکیِ «مرا به‌خاطر بسپار» هم دور نزنند.
     */
    public function ipBlocks(?string $ip): bool
    {
        if (($this->ip_restriction_mode ?? 'off') !== 'enforce' || ! $ip) {
            return false;
        }

        $rules = $this->ipRules()->where('is_active', true)->get();
        if ($rules->isEmpty()) {
            return false;
        }

        $match = fn ($cidr) => \Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, (string) $cidr);

        foreach ($rules->where('action', 'deny') as $r) {
            if ($match($r->cidr)) {
                return true;
            }
        }

        $allow = $rules->where('action', 'allow');
        if ($allow->isNotEmpty()) {
            foreach ($allow as $r) {
                if ($match($r->cidr)) {
                    return false;
                }
            }

            return true;
        }

        return false;
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

    public function identityVerification(): HasOne
    {
        return $this->hasOne(IdentityVerification::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(CreditEntry::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** موجودی اعتبار — جمع دفتر، نه ستون ذخیره‌شده */
    public function creditBalance(string $currency = 'IRT'): int
    {
        return (int) $this->creditEntries()->where('currency_code', $currency)->sum('amount');
    }

    /**
     * نام رسمی — از ثبت احوال، نه از فرم.
     * برای خارجی‌ها هنوز نام نداریم، پس ایمیل جای آن می‌نشیند.
     */
    public function displayName(): string
    {
        $iv = $this->identityVerification;

        if ($iv !== null && filled($iv->first_name)) {
            return trim($iv->first_name.' '.$iv->last_name);
        }

        // مشتریِ خارجی استعلامِ هویتی ندارد؛ نامش از فرمِ ثبت‌نام در پروفایل است
        $p = $this->profiles()->where('is_default', true)->first();

        if ($p !== null && filled($p->first_name)) {
            return trim($p->first_name.' '.$p->last_name);
        }

        return Str::before((string) $this->email, '@');
    }

    /** بعد از ثبت حساب بانکی تأییدشده، نام قابل تغییر نیست */
    public function isNameLocked(): bool
    {
        return $this->bankAccounts()->where('status', 'verified')->exists();
    }
}
