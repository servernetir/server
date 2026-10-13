<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مبدأ — جایی که لبه در حالتِ MISS سراغش می‌رود.
 *
 * چند مبدأ به ازای هر zone ممکن است تا failover بعداً بدونِ مهاجرت اضافه شود.
 */
class CdnOrigin extends Model
{
    protected $fillable = [
        'cdn_zone_id', 'host', 'port', 'scheme', 'host_header',
        'verify_tls', 'weight', 'health', 'checked_at',
    ];

    protected $casts = [
        'verify_tls' => 'bool',
        'checked_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(CdnZone::class, 'cdn_zone_id');
    }

    /**
     * هدرِ Host ای که به مبدأ فرستاده می‌شود.
     *
     * 🔴 پیش‌فرض باید نامِ **دامنهٔ مشتری** باشد نه نامِ مبدأ. اکثرِ وب‌سرورها
     * vhost را از همین هدر انتخاب می‌کنند؛ اگر IP یا نامِ داخلی بفرستیم،
     * مشتری به‌جای سایتِ خودش صفحهٔ پیش‌فرضِ سرور را می‌بیند — با کدِ ۲۰۰ و
     * بی‌هیچ خطایی، که بدترین شکلِ خرابی است.
     */
    public function hostHeaderFor(CdnZone $zone): string
    {
        return filled($this->host_header) ? $this->host_header : $zone->domain;
    }
}
