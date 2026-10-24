<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ادعای مالکیتِ یک دامنه (قراردادِ §3.1 و §5.2).
 *
 * 🔴 ادعا هیچ چیزی منتشر نمی‌کند و جلوی هیچ‌کس را نمی‌گیرد: چند حساب
 * می‌توانند هم‌زمان ادعای در انتظارِ یک دامنه را داشته باشند. فقط اثباتِ
 * TXT (یا ثبتِ دامنه نزدِ خودمان) zone می‌سازد. اگر ادعا خودش رزرو بود،
 * هرکسی زودتر دامنهٔ دیگری را «قفل» می‌کرد — همان حفرهٔ v1.
 */
class DnsOwnershipClaim extends Model
{
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING, self::VERIFIED, self::EXPIRED, self::CANCELLED];

    /** `txt` = رکوردِ چالش، `registered` = دامنه نزدِ خودمان ثبت است */
    public const VIA = ['txt', 'registered'];

    protected $table = 'dns_ownership_claims';

    protected $fillable = [
        'customer_id', 'domain', 'token', 'status', 'expires_at',
        'verified_at', 'verified_via', 'attempts', 'last_checked_at', 'last_result', 'zone_id',
    ];

    protected $casts = [
        'customer_id'     => 'int',
        'zone_id'         => 'int',
        'attempts'        => 'int',
        'expires_at'      => 'datetime',
        'verified_at'     => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    /* ───────────────────────── روابط ───────────────────────── */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(CdnZone::class, 'zone_id');
    }

    /* ───────────────────────── اسکوپ ───────────────────────── */

    /**
     * ادعاهای واقعاً در انتظار.
     *
     * ⚠️ زمانِ انقضا هم سنجیده می‌شود، نه فقط ستونِ status: `dns:expire-claims`
     * روزی یک بار می‌چرخد و تا آن موقع ادعای سوخته نباید در سهمیهٔ ۲۰تایی
     * حساب شود یا قابلِ تأیید بماند.
     */
    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::PENDING)
            ->where(fn (Builder $w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** در انتظار ولی زمانش گذشته — ورودیِ `dns:expire-claims` */
    public function scopeStale(Builder $q): Builder
    {
        return $q->where('status', self::PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    public function scopeForCustomer(Builder $q, $customerId): Builder
    {
        return $q->where('customer_id', $customerId);
    }

    /* ───────────────────────── رفتار ───────────────────────── */

    public function isPending(): bool
    {
        return $this->status === self::PENDING && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->status === self::EXPIRED
            || ($this->expires_at !== null && $this->expires_at->lte(now()));
    }

    /** توکنِ تازه: ۳۲ نویسهٔ hex از منبعِ رمزنگاریِ امن (ستونِ char(32)) */
    public static function freshToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
