<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * وضعیتِ یک zone روی یک نیم‌سرورِ عمومی (قراردادِ §3.3 و §4.3).
 *
 * 🔴 «منتشر شد» برای مشتری فقط از این جدول می‌آید، نه از پاسخِ API primary:
 * primary گفتنِ «ذخیره شد» یعنی هیچ نودی هنوز چیزی ندیده. فقط پرس‌وجوی
 * مستقیمِ SOA از خودِ نود (AA + سریال) حقیقت را می‌گوید.
 */
class DnsZoneNode extends Model
{
    public const UNKNOWN = 'unknown';
    public const IN_SYNC = 'in_sync';
    public const LAGGING = 'lagging';
    public const MISSING = 'missing';
    public const UNREACHABLE = 'unreachable';

    public const STATES = [self::UNKNOWN, self::IN_SYNC, self::LAGGING, self::MISSING, self::UNREACHABLE];

    protected $table = 'dns_zone_nodes';

    protected $fillable = ['cdn_zone_id', 'node', 'serial', 'state', 'checked_at', 'last_error'];

    protected $casts = [
        'cdn_zone_id' => 'int',
        'serial'      => 'int',
        'checked_at'  => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(CdnZone::class, 'cdn_zone_id');
    }

    /**
     * نامِ نود همیشه کوچک و بی‌نقطهٔ پایانی.
     *
     * ⚠️ همین رشته با `DnsConfig::nameserverHosts()` تطبیق داده می‌شود؛
     * `NS1.X.NET.` و `ns1.x.net` اگر دو ردیف شوند، ایندکسِ یکتا را دور می‌زنند
     * و zone هرگز «روی همه» دیده نمی‌شود.
     */
    protected function node(): Attribute
    {
        return Attribute::make(set: fn ($v) => rtrim(strtolower(trim((string) $v)), '.'));
    }

    /** خطای داخلی، کوتاه (ستون ۱۲۰) — متنِ خام به مشتری نمایش داده نمی‌شود */
    protected function lastError(): Attribute
    {
        return Attribute::make(set: fn ($v) => $v === null ? null : mb_substr((string) $v, 0, 120));
    }

    public function scopeInSync(Builder $q): Builder
    {
        return $q->where('state', self::IN_SYNC);
    }

    public function scopeNotInSync(Builder $q): Builder
    {
        return $q->where('state', '!=', self::IN_SYNC);
    }

    public function scopeForZone(Builder $q, $zoneId): Builder
    {
        return $q->where('cdn_zone_id', $zoneId);
    }

    /** ردیف‌های zoneهای یک مشتری — مالکیت از zone می‌آید، نه از این جدول */
    public function scopeForCustomer(Builder $q, $customerId): Builder
    {
        return $q->whereIn('cdn_zone_id', CdnZone::query()->select('id')->where('customer_id', $customerId));
    }

    /**
     * ردیف‌هایی که هنوز «هم‌گام» تأیید نشده‌اند (ورودیِ بررسیِ دوبارهٔ نودها).
     * `unknown` هم این‌جاست: نودی که هنوز پرسیده نشده، منتشر حساب نمی‌شود.
     */
    public function scopePending(Builder $q): Builder
    {
        return $q->where('state', '!=', self::IN_SYNC);
    }

    public function isInSync(): bool
    {
        return $this->state === self::IN_SYNC;
    }
}
