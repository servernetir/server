<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * یک آدرسِ دریافت در استخر. کلیدِ خصوصی‌اش **هرگز** این‌جا نیست.
 */
class CryptoWallet extends Model
{
    /** ⚠️ آدرسِ آزادشده تا این مدت دوباره داده نمی‌شود — تلهٔ پرداختِ دیرهنگام */
    public const COOLDOWN_HOURS = 6;

    /**
     * دورهٔ خنک‌شدن — تنظیم‌پذیر (۶ شهریور: کارفرما «چرا هی غیرفعال است؟»).
     *
     * با استخرِ کوچک، ۶ ساعتِ ثابت یعنی هر تلاشِ منقضی روشِ پرداخت را
     * ساعت‌ها می‌بندد. حالا مدیر آگاهانه کوتاهش می‌کند و بهایش را می‌داند:
     * خنک‌شدنِ کوتاه‌تر = ریسکِ بیشترِ «پرداختِ دیرهنگام به آدرسی که به
     * فاکتورِ بعدی داده شده». صفر مجاز است؛ سقف ۴۸.
     */
    public static function cooldownHours(): int
    {
        $v = \App\Models\Setting::get('crypto_cooldown_hours');

        if ($v === null || $v === '' || ! is_numeric($v)) {
            return self::COOLDOWN_HOURS;
        }

        return max(0, min(48, (int) $v));
    }

    protected $fillable = ['chain', 'address', 'label', 'is_active', 'busy_payment_id', 'cooldown_until'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'cooldown_until' => 'datetime'];
    }

    public function scopeFree(Builder $q, string $chain): Builder
    {
        return $q->where('chain', $chain)->where('is_active', true)
            ->whereNull('busy_payment_id')
            ->where(fn ($w) => $w->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now()));
    }

    /**
     * 🔴 اختصاص باید **اتمی** باشد.
     *
     * کرون و درخواستِ کاربر هم‌زمان می‌دوند. بدونِ UPDATE شرطی، دو فاکتور یک
     * آدرس می‌گیرند و اولین واریز به هر دو نسبت داده می‌شود — یعنی یک سرویسِ
     * پرداخت‌نشده فعال می‌شود. این‌جا همان الگوی قفلِ وضعیتیِ `provision:run`
     * تکرار شده: ردیف فقط وقتی برداشته می‌شود که هنوز آزاد باشد.
     */
    public static function claim(string $chain, int $paymentId): ?self
    {
        return DB::transaction(function () use ($chain, $paymentId) {
            $row = static::free($chain)->orderBy('id')->first();

            if ($row === null) {
                return null;
            }

            $taken = static::whereKey($row->id)->whereNull('busy_payment_id')
                ->update(['busy_payment_id' => $paymentId, 'updated_at' => now()]);

            return $taken === 1 ? $row->fresh() : null;
        });
    }

    /** آزادسازی با دورهٔ خنک‌شدن — نه بلافاصله */
    public function release(): void
    {
        $this->forceFill([
            'busy_payment_id' => null,
            'cooldown_until' => now()->addHours(static::cooldownHours()),
        ])->save();
    }
}
