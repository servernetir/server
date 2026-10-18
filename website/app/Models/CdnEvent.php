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

    protected $fillable = ['cdn_zone_id', 'cdn_node_id', 'type', 'message', 'payload', 'actor', 'ip', 'created_at'];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    /** نوعِ رویدادهای ممیزیِ DNSِ مدیریت‌شده (`audit()`) */
    public const AUDIT = 'audit';

    /** سقفِ نگهداری به ازای هر zone */
    private const KEEP_PER_ZONE = 500;

    /**
     * ثبتِ رویداد — **هرگز استثنا نمی‌دهد**.
     *
     * 🔴 چرا: این متد از داخلِ مسیرِ دریافتِ مصرف و پرج صدا زده می‌شود. اگر
     * جدول روی سرورِ مهاجرت‌نخورده نباشد یا دیتابیس یک لحظه قطع شود، نباید
     * پرجِ مشتری یا ثبتِ مصرف بشکند. ثبتِ نشدنِ یک خطِ تاریخچه بی‌خطر است؛
     * از دست رفتنِ دادهٔ صورت‌حساب نه.
     *
     * `$actor` (`customer:{id}` | `admin:{id}` | `system`) و `$ip` از v2‌اند. اگر
     * ستون‌هایشان هنوز نیست (000200 اجرا نشده) داخلِ payload با کلیدِ `_actor`/
     * `_ip` می‌نشینند: ممیزی بدونِ «چه کسی» بی‌ارزش است، و INSERT با ستونِ
     * ناموجود کلِ ردیف را از دست می‌داد.
     *
     * ⚠️ فهرستِ ستون‌ها جای `hasTable` قبلی است (همان یک پرس‌وجو، هم وجودِ جدول و
     * هم ستون‌ها) و حافظه نمی‌شود — مهاجرت می‌تواند وسطِ همان درخواست اجرا شود.
     */
    public static function note(
        string $type,
        ?int $zoneId,
        ?string $message = null,
        array $payload = [],
        ?int $nodeId = null,
        ?string $actor = null,
        ?string $ip = null,
    ): void {
        try {
            $cols = array_flip(Schema::getColumnListing('cdn_events'));

            if ($cols === []) {
                return;
            }

            $actor = $actor !== null && trim($actor) !== '' ? mb_substr(trim($actor), 0, 40) : null;
            $ip = $ip !== null && filter_var(trim($ip), FILTER_VALIDATE_IP) !== false ? trim($ip) : null;

            $row = [
                'cdn_zone_id' => $zoneId,
                'cdn_node_id' => $nodeId,
                'type'        => mb_substr($type, 0, 40),
                'message'     => $message !== null ? mb_substr($message, 0, 250) : null,
                'created_at'  => now(),
            ];

            foreach (['actor' => $actor, 'ip' => $ip] as $col => $val) {
                if ($val === null) {
                    continue;
                }
                if (isset($cols[$col])) {
                    $row[$col] = $val;
                } else {
                    $payload['_'.$col] = $val;
                }
            }

            $row['payload'] = $payload ?: null;

            self::create($row);
        } catch (\Throwable) {
            // عمداً خاموش — بالا را بخوان
        }
    }

    /**
     * ثبتِ ممیزیِ یک تغییرِ مشتری/مدیر (§3.4) — **هرگز استثنا نمی‌دهد**.
     *
     * پیام یک **کد** است (`zone_added`، `record_deleted`، …) و پارامترها در
     * payload؛ متن هنگامِ نمایش با lang ساخته می‌شود. متنِ فارسیِ ذخیره‌شده
     * همان چیزی بود که نسخهٔ یک را از نمایشِ انگلیسی/ترکی محروم کرد.
     *
     * ⚠️ `domain` اگر در پارامترها نباشد افزوده می‌شود: پس از حذفِ قطعیِ zone،
     * `cdn_zone_id` به هیچ ردیفی اشاره نمی‌کند و این تنها ردِ «کدام دامنه» است.
     */
    public static function audit(string $code, ?CdnZone $zone, array $params = [], ?string $actor = null, ?string $ip = null): void
    {
        try {
            if ($zone !== null && ! array_key_exists('domain', $params) && filled($zone->domain)) {
                $params['domain'] = $zone->domain;
            }

            self::note(self::AUDIT, $zone?->getKey() !== null ? (int) $zone->getKey() : null, $code, $params, null, $actor, $ip);
        } catch (\Throwable) {
            // همان قراردادِ note()
        }
    }

    public function scopeAudits(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('type', self::AUDIT);
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
