<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * کوپنِ هدیه — قفل به یک مشتری، یک‌بارمصرف، مدت‌دار.
 *
 * دلیلِ کوپن‌بودن (به‌جای اعتبارِ کیفِ پول) در مهاجرتِ
 * `create_gift_coupons_table` مفصل نوشته شده.
 */
class GiftCoupon extends Model
{
    protected $fillable = [
        'customer_id', 'code', 'currency_code', 'amount',
        'min_invoice', 'reason', 'expires_at', 'used_at', 'used_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'integer',
            'min_invoice' => 'integer',
            'expires_at'  => 'datetime',
            'used_at'     => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * کدِ خوانا برای پیامک.
     *
     * ⚠️ بدونِ حروفِ مبهم: `0/O` و `1/I/L` در پیامک و پشتِ تلفن قابلِ تفکیک
     * نیستند و مشتری کدِ درست را «نامعتبر» می‌گیرد. حرف‌ها بزرگ‌اند چون
     * ورودیِ کاربر هم بزرگ می‌شود (`normalize`).
     */
    public static function freshCode(string $prefix = 'HB'): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $body = '';

            for ($i = 0; $i < 8; $i++) {
                $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $code = $prefix.'-'.$body;
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * یکسان‌سازیِ ورودیِ کاربر.
     *
     * 🔴 ارقامِ فارسی/عربی تبدیل می‌شوند. بی‌این، مشتریِ ایرانی که کد را از
     * پیامک کپی می‌کند یا با صفحه‌کلیدِ فارسی تایپ می‌کند، کدِ کاملاً درست را
     * وارد می‌کند و «نامعتبر» می‌گیرد — همان تله‌ای که `Totp::normalizeCode`
     * برای کدِ دوعاملی بسته بود.
     */
    public static function normalize(string $input): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $s = str_replace($fa, $en, $input);
        $s = str_replace($ar, $en, $s);

        return strtoupper(trim(preg_replace('/\s+/u', '', $s) ?? ''));
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isLive(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired();
    }

    /** ساعت‌های باقی‌مانده — برای نمایش، هرگز منفی */
    public function hoursLeft(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, (int) ceil(Carbon::now()->diffInMinutes($this->expires_at, false) / 60));
    }

    /**
     * مصرفِ اتمیِ کوپن.
     *
     * 🔴 شرطِ `used_at IS NULL` داخلِ خودِ `UPDATE` است، نه یک `if` پیش از آن.
     * دو کلیکِ هم‌زمان روی «اعمالِ کد» دو پرس‌وجوی موازی می‌سازد و با گاردِ
     * کوئری‌محور **هر دو** سبز می‌شوند — یعنی یک کوپن دو فاکتور را می‌بندد و
     * ما دو برابرِ هدیه ضرر می‌کنیم. همان قاعدهٔ ثبت‌شدهٔ `Idempotency-Key`:
     * اول claim، بعد کار.
     *
     * @return bool آیا همین فراخوان کوپن را گرفت؟
     */
    public function claim(int $invoiceId): bool
    {
        $taken = static::whereKey($this->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update([
                'used_at'         => now(),
                'used_invoice_id' => $invoiceId,
                'updated_at'      => now(),
            ]);

        return $taken === 1;
    }
}
