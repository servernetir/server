<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * رویدادهای لبه — پرج، دیپلویِ snapshot، صدورِ گواهی، نمونهٔ درخواستِ مسدودشده.
 *
 * ⚠️ این جدول رشدِ سریع دارد (هر مسدودسازی یک ردیف). `trim()` سقف می‌گذارد،
 * وگرنه همان بلایی سرِ دیتابیس می‌آید که سیلِ ۴۰۴ سرِ ردیابِ خطا آورد.
 */
class CdnEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['cdn_zone_id', 'cdn_node_id', 'type', 'message', 'payload', 'created_at'];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    /** سقفِ نگهداری به ازای هر zone */
    private const KEEP_PER_ZONE = 500;

    /**
     * ثبتِ رویداد — **هرگز استثنا نمی‌دهد**.
     *
     * 🔴 چرا: این متد از داخلِ مسیرِ دریافتِ مصرف و پرج صدا زده می‌شود. اگر
     * جدول روی سرورِ مهاجرت‌نخورده نباشد یا دیتابیس یک لحظه قطع شود، نباید
     * پرجِ مشتری یا ثبتِ مصرف بشکند. ثبتِ نشدنِ یک خطِ تاریخچه بی‌خطر است؛
     * از دست رفتنِ دادهٔ صورت‌حساب نه.
     */
    public static function note(string $type, ?int $zoneId, ?string $message = null, array $payload = [], ?int $nodeId = null): void
    {
        try {
            if (! Schema::hasTable('cdn_events')) {
                return;
            }

            self::create([
                'cdn_zone_id' => $zoneId,
                'cdn_node_id' => $nodeId,
                'type'        => $type,
                'message'     => $message !== null ? mb_substr($message, 0, 250) : null,
                'payload'     => $payload ?: null,
                'created_at'  => now(),
            ]);
        } catch (\Throwable) {
            // عمداً خاموش — بالا را بخوان
        }
    }

    /** ردیف‌های کهنهٔ یک zone را می‌برد. از کرونِ روزانه صدا زده می‌شود. */
    public static function trim(int $zoneId): int
    {
        $keepFrom = self::where('cdn_zone_id', $zoneId)
            ->orderByDesc('id')
            ->skip(self::KEEP_PER_ZONE)
            ->take(1)
            ->value('id');

        if ($keepFrom === null) {
            return 0;
        }

        return self::where('cdn_zone_id', $zoneId)->where('id', '<=', $keepFrom)->delete();
    }
}
