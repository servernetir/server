<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * یک پکیجِ فروش که مشتری آنلاین می‌خرد.
 */
class Product extends Model
{
    protected $fillable = [
        'name', 'slug', 'category', 'server_id', 'plan', 'currency_code',
        'price', 'setup_fee', 'cycle', 'tax_percent', 'specs', 'description',
        'requires_domain', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price'           => 'integer',
            'setup_fee'       => 'integer',
            'tax_percent'     => 'integer',
            'specs'           => 'array',
            'requires_domain' => 'boolean',
            'is_active'       => 'boolean',
            'sort'            => 'integer',
        ];
    }

    public const CATEGORIES = [
        'shared'      => 'هاست اشتراکی',
        'reseller'    => 'نمایندگی',
        'vps'         => 'سرور مجازی (VPS)',
        'dedicated'   => 'سرور اختصاصی',
        'plesk'       => 'Plesk',
        'directadmin' => 'DirectAdmin',
        'other'       => 'سایر',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            if (blank($p->slug)) {
                $p->slug = Str::slug($p->name) ?: 'pkg-'.Str::random(6);
            }
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** قیمتِ مؤثرِ دوره — پایهٔ تومان پس از اعمالِ ضریبِ نرخِ ارز */
    public function effectivePrice(): int
    {
        return price_toman($this->price);
    }

    /** هزینهٔ راه‌اندازیِ مؤثر */
    public function effectiveSetup(): int
    {
        return price_toman($this->setup_fee);
    }

    /** مالیاتِ هر دوره (روی قیمتِ مؤثر) */
    public function taxAmount(): int
    {
        return (int) round($this->effectivePrice() * $this->tax_percent / 100);
    }

    /** مبلغِ کلِ اولین صورت‌حساب (دوره + راه‌اندازی + مالیاتِ هردو) */
    public function firstTotal(): int
    {
        $base = $this->effectivePrice() + $this->effectiveSetup();

        return $base + (int) round($base * $this->tax_percent / 100);
    }

    /** مبلغِ دوره‌ایِ بعدی (بدونِ راه‌اندازی) */
    public function recurringTotal(): int
    {
        return $this->effectivePrice() + $this->taxAmount();
    }

    public function cycleLabel(): string
    {
        return match ($this->cycle) {
            'monthly'   => 'ماهانه',
            'quarterly' => 'سه‌ماهه',
            'yearly'    => 'سالانه',
            default     => 'یک‌بار',
        };
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
