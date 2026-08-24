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
        'domain', 'tld', 'registrar', 'is_premium',
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

    /** آخرین استعلام معتبر برای یک دامنه */
    public static function valid(string $domain): ?self
    {
        return static::where('domain', $domain)
            ->where('honour_until', '>', now())
            ->latest('id')
            ->first();
    }
}
