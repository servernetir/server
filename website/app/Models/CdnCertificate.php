<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * گواهیِ TLS یک میزبان.
 *
 * PEM و کلیدِ خصوصی **رمزنگاری‌شده** ذخیره می‌شوند (cast: encrypted). آنها به
 * snapshot می‌روند چون نود باید TLS را ببندد، ولی هر بکاپِ دیتابیس بی‌کلیدِ
 * اپ، برای مهاجم بی‌ارزش می‌مانَد.
 */
class CdnCertificate extends Model
{
    protected $fillable = [
        'cdn_zone_id', 'hostname', 'issuer', 'status',
        'not_after', 'last_error', 'cert_pem', 'key_pem', 'issued_at',
    ];

    protected $hidden = ['cert_pem', 'key_pem'];

    protected $casts = [
        'not_after' => 'datetime',
        'issued_at' => 'datetime',
        'cert_pem'  => 'encrypted',
        'key_pem'   => 'encrypted',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(CdnZone::class, 'cdn_zone_id');
    }

    /**
     * گواهی‌هایی که باید صادر یا تمدید شوند.
     *
     * 🔴 `failed` هم برمی‌گردد. اگر فقط `pending` و منقضی‌شونده‌ها را بگیریم،
     * یک شکستِ گذرا (خطای نرخِ Let's Encrypt، قطعیِ لحظه‌ای PowerDNS) گواهی را
     * برای همیشه در حالتِ شکست‌خورده جا می‌گذارد و هیچ اجرای بعدی سراغش
     * نمی‌رود — همان الگوی `failed` که یک‌بار در تحویلِ WHM دیدیم.
     */
    public function scopeNeedsIssue(Builder $q): Builder
    {
        $renewAt = Carbon::now()->addDays((int) config('edge.acme.renew_days', 21));

        return $q->where(function (Builder $w) use ($renewAt) {
            $w->whereIn('status', ['pending', 'failed', 'expired'])
                ->orWhere(fn (Builder $x) => $x->where('status', 'issued')
                    ->where(fn (Builder $y) => $y->whereNull('not_after')->orWhere('not_after', '<=', $renewAt)));
        });
    }

    /**
     * ⚠️ نال یعنی «تاریخِ انقضا را نمی‌دانیم»، نه «امروز منقضی می‌شود».
     * نسخهٔ قبلی `null * -1` می‌داد که در PHP صفر است — یعنی گواهیِ بی‌تاریخ
     * دقیقاً شبیه گواهیِ منقضی‌شونده گزارش می‌شد.
     */
    public function daysLeft(): ?int
    {
        if ($this->not_after === null) {
            return null;
        }

        return (int) Carbon::now()->diffInDays($this->not_after, false);
    }

    public function isUsable(): bool
    {
        return $this->status === 'issued'
            && filled($this->cert_pem)
            && filled($this->key_pem)
            && ($this->not_after === null || $this->not_after->isFuture());
    }
}
