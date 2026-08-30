<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ایجنتِ روترِ مشتری — کسی که کارهای صف را واقعاً اجرا می‌کند.
 *
 * ═══ شکلِ توکن و چرا ═══
 *
 *   sna_{service_id}_{۴۸ رقمِ hex}
 *
 * پیشوندِ شناسهٔ سرویس تزئینی نیست: بی‌آن، احرازِ هر پیمایشِ ایجنت یک پیمایشِ
 * کاملِ جدول لازم داشت (هش را نمی‌شود از توکن حدس زد ولی می‌شود جست‌وجو کرد —
 * و همان جست‌وجو هر ۳۰ ثانیه، ضربدرِ تعدادِ روترها). با پیشوند، ردیف مستقیم
 * پیدا می‌شود و مقایسه یک `hash_equals` است.
 *
 * ⚠️ پیشوند **هیچ چیزی را اثبات نمی‌کند** — هر کسی می‌تواند `sna_49_…` بسازد.
 * تنها چیزی که احراز می‌کند همان ۴۸ رقمِ تصادفی است. پیشوند فقط می‌گوید
 * «کدام ردیف را باز کن»، نه «این درست است».
 */
class TunnelAgent extends Model
{
    protected $fillable = ['service_id', 'token_hash', 'last_seen_at', 'last_ip'];

    /** ⚠️ هرگز در JSON نرود — دلیلش همان دلیلِ `CustomerApiToken::$hidden`. */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /**
     * ایجنتِ تازه برای یک سرویس. خروجی: [مدل، توکنِ خام].
     *
     * صدورِ دوباره همان ردیف را بازنویسی می‌کند و توکنِ قبلی را می‌کُشد. این
     * عمدی است: «صدورِ دوباره» تنها راهِ ابطالی است که مشتری بدونِ ما دارد،
     * و اگر توکنِ قدیمی زنده می‌ماند، روترِ فروخته‌شده یا لپ‌تاپِ گم‌شده تا
     * ابد به صف دسترسی داشت.
     *
     * @return array{0:self,1:string}
     */
    public static function issueFor(int $serviceId): array
    {
        $plain = 'sna_'.$serviceId.'_'.bin2hex(random_bytes(24));

        $agent = static::updateOrCreate(
            ['service_id' => $serviceId],
            ['token_hash' => hash('sha256', $plain), 'last_seen_at' => null, 'last_ip' => null],
        );

        return [$agent, $plain];
    }

    /**
     * ایجنتِ متناظرِ یک توکنِ خام، یا `null`.
     *
     * 🔴 شناسهٔ سرویس از **خودِ توکن** درمی‌آید و نه از پارامترِ درخواست. اگر
     * تماس‌گیرنده هر دو را می‌داد، اولین فراموشیِ تطبیق یعنی روترِ یک مشتری
     * کارهای مشتریِ دیگر را بردارد — و آن peer روی روترِ اشتباه می‌نشیند و
     * دسترسیِ یک غریبه به شبکهٔ داخلیِ کسِ دیگری می‌دهد.
     */
    public static function findByPlain(?string $plain): ?self
    {
        $plain = trim((string) $plain);

        if (! preg_match('~^sna_(\d+)_[0-9a-f]{48}$~', $plain, $m)) {
            return null;
        }

        $agent = static::where('service_id', (int) $m[1])->first();

        if ($agent === null) {
            return null;
        }

        return hash_equals((string) $agent->token_hash, hash('sha256', $plain)) ? $agent : null;
    }

    /**
     * ضربان.
     *
     * ⚠️ `saveQuietly` عمداً: این ردیف رویدادِ مدل ندارد و نباید داشته باشد،
     * ولی اگر روزی کسی observerی رویش بگذارد، هر ۳۰ ثانیه صدا زدنش یک
     * غافلگیریِ گران است.
     */
    public function seen(?string $ip): void
    {
        $this->forceFill(['last_seen_at' => now(), 'last_ip' => $ip])->saveQuietly();
    }

    /** ایجنتی که بیش از دو دقیقه خبری ازش نیست، عملاً خاموش است. */
    public function isAlive(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(2));
    }
}
