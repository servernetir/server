<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * پروفایل حقیقی/حقوقی.
 *
 * کد ملی هرگز خام ذخیره نمی‌شود: مقدار رمزنگاری‌شده در ستون *_enc و یک
 * hash جداگانه برای ایندکس یکتا و جستجو. اگر فقط رمزنگاری می‌کردیم، جستجو
 * یعنی رمزگشایی کل جدول.
 */
class CustomerProfile extends Model
{
    protected $fillable = [
        'customer_id', 'type', 'is_default', 'status',
        'mobile', 'email', 'country', 'province', 'city', 'address', 'postal_code',
        'first_name', 'last_name', 'birth_date',
        'company_name', 'registration_number', 'economic_code',
        'rep_first_name', 'rep_last_name', 'rep_position',
    ];

    protected function casts(): array
    {
        return [
            'is_default'  => 'boolean',
            'birth_date'  => 'date',
            'verified_at' => 'datetime',
            'expires_at'  => 'datetime',
        ];
    }

    /** فیلدهای حساسی که جفت enc/hash دارند */
    private const SECURE = [
        'national_id'         => ['national_id_enc', 'national_id_hash'],
        'company_national_id' => ['company_national_id_enc', 'company_national_id_hash'],
        'rep_national_id'     => ['rep_national_id_enc', 'rep_national_id_hash'],
    ];

    /**
     * نوشتن یک شناسهٔ ملی: هم‌زمان رمزنگاری و hash می‌شود.
     * hash با کلید اپ نمک می‌خورد تا rainbow table روی ۱۰ رقم بی‌اثر شود —
     * فضای کد ملی کوچک است و hash خام قابل brute-force بود.
     */
    public function setSecure(string $field, ?string $value): void
    {
        [$encCol, $hashCol] = self::SECURE[$field];

        if (blank($value)) {
            $this->{$encCol} = null;
            $this->{$hashCol} = null;
            return;
        }

        $value = $this->normalizeDigits(trim($value));
        $this->{$encCol}  = Crypt::encryptString($value);
        $this->{$hashCol} = hash_hmac('sha256', $value, config('app.key'));
    }

    /** خواندن مقدار خام — فقط جایی که واقعاً لازم است */
    public function getSecure(string $field): ?string
    {
        $encCol = self::SECURE[$field][0];

        return filled($this->{$encCol}) ? Crypt::decryptString($this->{$encCol}) : null;
    }

    /** پیدا کردن پروفایل با کد ملی بدون رمزگشایی */
    public static function findBySecure(string $field, string $value): ?self
    {
        $hashCol = self::SECURE[$field][1];
        $norm = (new self)->normalizeDigits(trim($value));

        return static::where($hashCol, hash_hmac('sha256', $norm, config('app.key')))->first();
    }

    /** ارقام فارسی/عربی به لاتین — کاربر با هر صفحه‌کلیدی تایپ می‌کند */
    private function normalizeDigits(string $s): string
    {
        return strtr($s, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function registryHandles(): HasMany
    {
        return $this->hasMany(RegistryHandle::class);
    }

    public function isCompany(): bool
    {
        return $this->type === 'company';
    }

    public function displayName(): string
    {
        return $this->isCompany()
            ? (string) $this->company_name
            : trim($this->first_name.' '.$this->last_name);
    }
}
