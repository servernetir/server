<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * یک مقصدِ دریافتِ آفلاین: حسابِ بانکیِ ارزی یا کیفِ رمزارز.
 *
 * ⚠️ همهٔ خواننده‌ها از `Schema::hasTable` رد می‌شوند تا صفحهٔ فاکتور روی
 * سروری که هنوز مهاجرت نخورده ۵۰۰ ندهد — مشتریِ وسطِ پرداخت نباید صفحهٔ خطا
 * ببیند فقط چون یک جدولِ تازه هنوز ساخته نشده.
 */
class PaymentAccount extends Model
{
    public const KINDS = ['bank', 'crypto'];

    /** ارزهایی که پنل پیشنهاد می‌دهد — فهرست باز است، این فقط راهنماست */
    public const SUGGESTED = ['EUR', 'GBP', 'TRY', 'USD', 'USDT'];

    /** شبکه‌های رایجِ USDT. ⚠️ شبکهٔ اشتباه = پولِ برنگشتنی */
    public const NETWORKS = ['TRC20', 'ERC20', 'BEP20', 'TON', 'SOL'];

    protected $fillable = [
        'kind', 'currency_code', 'label', 'holder', 'bank_name', 'iban', 'swift',
        'account_no', 'country', 'network', 'address', 'note', 'sort', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort')->orderBy('id');
    }

    public function isCrypto(): bool
    {
        return $this->kind === 'crypto';
    }

    /**
     * 🔴 حسابِ ناقص **نمایش داده نمی‌شود**.
     *
     * ردیفی که مدیر نیمه‌کاره ذخیره کرده (شبا خالی، یا آدرسِ رمزارز بدونِ شبکه)
     * روی صفحهٔ فاکتور یعنی مشتری پول را به جایی می‌فرستد که وجود ندارد — یا
     * روی شبکهٔ اشتباه، که برگشت‌ناپذیر است. «نبودنِ گزینه» از «گزینهٔ خراب»
     * به‌مراتب بهتر است.
     */
    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->isCrypto()
            ? filled($this->address) && filled($this->network)
            : filled($this->iban) || filled($this->account_no);
    }

    /**
     * مقصدهایی که برای یک فاکتور به مشتری نشان داده می‌شوند.
     *
     * ⚠️ رمزارز به **ارزِ فاکتور وابسته نیست**: تتر مقصدِ همه‌کاره است و اگر
     * فیلترِ ارز رویش بخورد، فاکتورِ یورویی هیچ گزینهٔ رمزارزی نمی‌بیند —
     * یعنی همان قابلیتی که کارفرما صریح خواست، بی‌صدا ناپدید می‌شود.
     */
    public static function forInvoiceCurrency(string $currency): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('payment_accounts')) {
            return collect();
        }

        return static::active()->ordered()->get()
            ->filter(fn (self $a) => $a->isUsable())
            ->filter(fn (self $a) => $a->isCrypto() || strcasecmp($a->currency_code, $currency) === 0)
            ->values();
    }

    /** برچسبِ خوانا وقتی مدیر اسم نگذاشته */
    public function displayLabel(): string
    {
        if (filled($this->label)) {
            return $this->label;
        }

        return $this->isCrypto()
            ? strtoupper($this->currency_code).' · '.strtoupper((string) $this->network)
            : strtoupper($this->currency_code).($this->bank_name ? ' · '.$this->bank_name : '');
    }
}
