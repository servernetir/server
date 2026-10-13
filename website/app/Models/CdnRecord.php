<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک رکوردِ DNS. منبعِ حقیقت این‌جاست و PowerDNS **آینه** است — با
 * `dns:reconcile` هر وقت لازم شد دوباره از روی همین ساخته می‌شود.
 *
 * چرا این جهت و نه برعکس: اگر PowerDNS منبع باشد، هر خرابیِ آن سرور یعنی
 * از دست رفتنِ دادهٔ مشتری. این‌طوری بدترین حالت «سرورِ DNS را دوباره پر کن» است.
 */
class CdnRecord extends Model
{
    protected $fillable = [
        'cdn_zone_id', 'type', 'name', 'content', 'ttl', 'prio', 'proxied', 'disabled', 'comment',
    ];

    protected $casts = [
        'proxied'  => 'bool',
        'disabled' => 'bool',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(CdnZone::class, 'cdn_zone_id');
    }

    /**
     * آیا این رکورد می‌تواند از لبه رد شود؟
     *
     * 🔴 MX هرگز. پروکسی‌کردنِ MX یعنی ایمیلِ مشتری به یک وب‌سرور می‌رود و
     * بی‌هیچ خطایی گم می‌شود — همان دلیلی که `CloudflareDns` هم برای
     * زیردامنهٔ رایگان `proxied=false` می‌گذارد.
     */
    public function canProxy(): bool
    {
        return in_array($this->type, (array) config('edge.proxiable', []), true);
    }

    /** برچسبِ نمایشی: نامِ نسبی به‌جای FQDN کامل (`www` به‌جای `www.example.com`) */
    public function shortName(string $zone): string
    {
        if ($this->name === $zone) {
            return '@';
        }

        return preg_replace('~\.'.preg_quote($zone, '~').'$~', '', $this->name) ?? $this->name;
    }

    /**
     * نامِ نسبیِ ورودیِ کاربر را به FQDN تبدیل می‌کند.
     *
     * ⚠️ `@` و رشتهٔ خالی هر دو یعنی apex. اگر یکی‌شان جا بیفتد، کاربری که
     * فیلد را خالی می‌گذارد رکوردی به نامِ `.example.com` می‌سازد که هرگز
     * پاسخ نمی‌دهد و هیچ خطایی هم نمی‌گیرد.
     */
    public static function fqdn(string $input, string $zone): string
    {
        $n = mb_strtolower(trim($input));
        $n = rtrim($n, '.');

        if ($n === '' || $n === '@') {
            return $zone;
        }

        return str_ends_with($n, '.'.$zone) || $n === $zone ? $n : $n.'.'.$zone;
    }
}
