<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * یک کارِ منتظرِ اجرا روی روترِ مشتری.
 *
 * ═══ 🔴 معنیِ دقیقِ وضعیت‌ها ═══
 *
 *  • `pending` — ثبت شده، هنوز روی روتر اجرا نشده. **اکانت کار نمی‌کند.**
 *  • `done`    — ایجنت گزارش داد که peer روی روتر نشست.
 *  • `failed`  — یا روتر خطا داد، یا آن‌قدر ماند که دیگر انتظارش بی‌معناست.
 *
 * تمایزِ `pending`/`done` کلِ ارزشِ این جدول است: بی‌آن، APIی که «۲۰۱ Created»
 * می‌دهد دربارهٔ چیزی حرف می‌زند که هنوز اتفاق نیفتاده — همان الگویی که در
 * `provision_status='done'` این پروژه را یک بار گاز گرفت (سرویسِ «تحویل‌شده»
 * بی‌IP و بی‌ایمیل). پس هر جا این وضعیت نشان داده می‌شود، باید همان چیزی را
 * بگوید که مشتری تجربه می‌کند، نه یک برچسبِ داخلی.
 */
class TunnelJob extends Model
{
    public const OP_ADD = 'add';

    public const OP_REMOVE = 'remove';

    /**
     * پس از این مدت، کارِ هنوز اجرانشده `failed` می‌شود.
     *
     * ⚠️ معیار **سن** است نه تعدادِ تلاش. روترِ خاموش هیچ تحویلی نمی‌گیرد، پس
     * شمارندهٔ تلاش بالا نمی‌رود و کارِ سالم هرگز به سقف نمی‌خورد؛ ولی کاری که
     * یک شبانه‌روز مانده، دیگر «در راه» نیست و نشان‌دادنش به‌عنوانِ «منتظر»
     * یعنی مشتری تا ابد منتظرِ چیزی بماند که نمی‌آید.
     */
    public const STALE_HOURS = 24;

    protected $fillable = [
        'service_id', 'op', 'name', 'ip', 'public_key',
        'status', 'attempts', 'last_error', 'delivered_at', 'done_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts'     => 'integer',
            'delivered_at' => 'datetime',
            'done_at'      => 'datetime',
        ];
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', 'pending');
    }

    public function scopeForService(Builder $q, int $serviceId): Builder
    {
        return $q->where('service_id', $serviceId);
    }

    /**
     * ثبتِ کارِ تازه.
     *
     * 🔴 کارِ `pending`ِ هم‌نام **پیش از ثبت** بسته می‌شود. سناریو: مشتری اکانتی
     * می‌سازد، پشیمان می‌شود و حذفش می‌کند، و بلافاصله دوباره با همان نام
     * می‌سازد — همه در فاصلهٔ دو پیمایشِ ایجنت. آن‌وقت صف می‌شود
     * `add(x) · remove(x) · add(x)` و چون ایجنت هر سه را در یک نوبت اجرا
     * می‌کند، ترتیبِ اجرا تعیین‌کننده است. با بستنِ کارهای کهنهٔ هم‌نام، در هر
     * لحظه فقط **آخرین نیتِ کاربر** در صف است.
     */
    public static function enqueue(int $serviceId, string $op, string $name, ?string $ip = null, ?string $publicKey = null): self
    {
        static::query()
            ->forService($serviceId)
            ->pending()
            ->where('name', $name)
            ->update([
                'status'     => 'failed',
                'last_error' => 'superseded',
                'updated_at' => now(),
            ]);

        return static::create([
            'service_id' => $serviceId,
            'op'         => $op,
            'name'       => $name,
            'ip'         => $ip,
            'public_key' => $publicKey,
            'status'     => 'pending',
        ]);
    }

    /**
     * کارهای گیرکرده را می‌بندد و تعدادشان را برمی‌گرداند.
     *
     * ⚠️ عمداً هیچ‌جا خودکار صدا زده نمی‌شود جز در همان مسیرهایی که وضعیت را
     * **نشان می‌دهند**. کرونِ جدا یعنی یک نویسندهٔ دیگر روی همین جدول، و
     * ارزشش به اندازهٔ آن پیچیدگی نیست: کارِ کهنه تا وقتی کسی نگاهش نکند
     * ضرری ندارد.
     */
    public static function expireStale(int $serviceId): int
    {
        return static::query()
            ->forService($serviceId)
            ->pending()
            ->where('created_at', '<', now()->subHours(self::STALE_HOURS))
            ->update([
                'status'     => 'failed',
                'last_error' => 'agent_never_ran',
                'updated_at' => now(),
            ]);
    }
}
