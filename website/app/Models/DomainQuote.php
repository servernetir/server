<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * استعلام قیمت دامنه با پنجرهٔ اعتبار.
 * قیمتی که honour_until آن گذشته باشد، دیگر قابل استناد نیست.
 */
class DomainQuote extends Model
{
    protected $fillable = [
        'domain', 'tld', 'registrar', 'customer_id', 'is_premium',
        'cost_amount', 'cost_renew_amount', 'cost_currency', 'sell_toman', 'renew_toman',
        'honour_until', 'raw',
    ];

    /**
     * ⚠️ بهای تمام‌شده دادهٔ داخلی است — همان قاعدهٔ `Domain::$hidden`.
     * این مدل امروز مستقیم serialize نمی‌شود، ولی روزی که بشود نباید حاشیهٔ
     * سودمان با خودش بیرون برود.
     */
    protected $hidden = ['cost_amount', 'cost_renew_amount', 'cost_currency', 'raw'];

    protected function casts(): array
    {
        return [
            'is_premium'   => 'boolean',
            'honour_until' => 'datetime',
            'raw'          => 'array',
        ];
    }

    public function isHonourable(): bool
    {
        return $this->honour_until !== null && $this->honour_until->isFuture();
    }

    /**
     * ادعای مالکیتِ استعلام — «اولین بیننده، مالک».
     *
     * 🔴 چرا لازم شد: شناسهٔ عددیِ ترتیبی + نبودِ مالکیت یعنی هر مشتری
     * می‌توانست جستجوهای دامنهٔ بقیه را بپیماید (ممیزیِ شهریور ۱۴۰۵).
     * استعلام از جستجوی عمومی بی‌مالک زاده می‌شود؛ اولین مشتریِ واردشده‌ای
     * که به تسویه می‌بردش مالکش می‌شود و برای بقیه دیگر وجود ندارد.
     *
     * ⚠️ گاردِ hasColumn: پیش از اجرای مهاجرت روی سرور، رفتار مثل قبل است —
     * مسیرِ فروش هرگز به‌خاطرِ ستونِ غایب نمی‌شکند.
     */
    public function claimFor(int $customerId): bool
    {
        static $has = null;
        $has ??= \Illuminate\Support\Facades\Schema::hasColumn('domain_quotes', 'customer_id');

        if (! $has || $customerId <= 0) {
            return true;
        }

        if ($this->customer_id === null) {
            $this->forceFill(['customer_id' => $customerId])->save();

            return true;
        }

        return (int) $this->customer_id === $customerId;
    }

    /** آخرین استعلام معتبر برای یک دامنه */
    public static function valid(string $domain): ?self
    {
        return static::where('domain', $domain)
            ->where('honour_until', '>', now())
            ->latest('id')
            ->first();
    }
}
