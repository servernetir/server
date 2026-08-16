<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفترِ حسابرسیِ APIِ نمایندگی — و ماشینِ idempotency.
 *
 * یک جدول دو کار می‌کند و این عمدی است: کلیدِ یکتاسازیِ درخواست باید همان
 * جایی بنشیند که نتیجهٔ درخواست نشسته، وگرنه دو منبعِ حقیقت داریم و روزی
 * یکی می‌گوید «انجام شد» و آن یکی ردیفی ندارد.
 *
 * 🔴 هیچ **بدنهٔ کاملی** ذخیره نمی‌شود. بدنهٔ ثبتِ دامنه نام و نشانی و تلفنِ
 * مالک دارد؛ لاگی که آن را نگه دارد خودش به نشتِ داده تبدیل می‌شود — همان
 * چیزی که قرار بود جلویش را بگیرد. `response` فقط پاسخِ **عمومیِ** ما را
 * نگه می‌دارد، یعنی دقیقاً همان چیزی که تماس‌گیرنده از قبل دیده.
 */
class ResellerApiLog extends Model
{
    protected $fillable = [
        'customer_id', 'token_id', 'action', 'domain', 'ok', 'error_code',
        'amount_irt', 'duration_ms', 'ip', 'idempotency_key', 'response',
    ];

    protected function casts(): array
    {
        return [
            'ok'          => 'boolean',
            'response'    => 'array',
            'amount_irt'  => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(CustomerApiToken::class, 'token_id');
    }

    /**
     * مجموعِ خرجِ امروزِ یک مشتری از راهِ API.
     *
     * ⚠️ فقط ردیفِ **موفق** شمرده می‌شود. درخواستی که رد شده پولی خرج نکرده و
     * شمردنش یعنی نماینده‌ای که ده بار خطای اعتبارسنجی گرفته، سقفِ روزانه‌اش
     * را سوزانده بی‌آنکه چیزی خریده باشد.
     *
     * ⚠️ «امروز» به وقتِ تهران است نه UTC. `config/app.timezone` عمداً UTC
     * است، پس سقفِ روزانه با مرزِ UTC یعنی نماینده‌ای که ساعت ۳ بامدادِ تهران
     * کار می‌کند، وسطِ شبِ کاری‌اش سقفش صفر می‌شود.
     */
    public static function spentToday(int $customerId): int
    {
        $tz = config('calendar.display_timezone', 'Asia/Tehran');

        return (int) static::where('customer_id', $customerId)
            ->where('ok', true)
            ->where('created_at', '>=', now($tz)->startOfDay()->utc())
            ->sum('amount_irt');
    }

    /** پاسخِ قبلیِ همین کلید — پایهٔ idempotency */
    public static function replay(int $customerId, ?string $key): ?self
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        return static::where('customer_id', $customerId)
            ->where('idempotency_key', trim($key))
            ->first();
    }

    public function scopeRecent(Builder $q): Builder
    {
        return $q->orderByDesc('id');
    }
}
