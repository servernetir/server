<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * یک نودِ لبه یا DNS.
 *
 * نودها خودشان را با توکن معرفی می‌کنند و snapshot را **می‌کشند**؛ ما هرگز
 * به آنها وصل نمی‌شویم مگر برای پرجِ تک‌آدرس. یعنی نودِ پشتِ NAT هم کار
 * می‌کند و هیچ پورتِ ورودیِ مدیریتی لازم نیست.
 */
class CdnNode extends Model
{
    protected $fillable = [
        'name', 'role', 'country', 'label', 'hostname', 'public_ip', 'public_ip6',
        'status', 'token_hash', 'last_seen_at', 'snapshot_version',
        'cost_per_gb_micro', 'capacity_mbps', 'note',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function scopeEdge(Builder $q): Builder
    {
        return $q->where('role', 'edge');
    }

    /**
     * نودهایی که باید ترافیک بگیرند.
     *
     * `drain` عمداً از `active` جداست: هنگام نگهداری می‌خواهیم نود snapshot
     * بگیرد و اتصال‌های باز را تمام کند، ولی DNS دیگر به آن اشاره نکند.
     * یکی‌کردنشان یعنی تنها راهِ خالی‌کردنِ یک نود، خاموش‌کردنِ ناگهانی‌اش.
     */
    public function scopeServing(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function isStale(): bool
    {
        if ($this->last_seen_at === null) {
            return true;
        }

        return $this->last_seen_at->lt(
            Carbon::now()->subSeconds((int) config('edge.snapshot.stale_after', 90))
        );
    }

    /**
     * توکنِ تازه می‌سازد، هشش را می‌نشاند و **متنِ خام** را برمی‌گرداند.
     *
     * ⚠️ متنِ خام فقط همین یک بار در دسترس است — دقیقاً مثلِ رمزِ root سرورِ
     * ابری که `password_seen` دارد. اگر مدیر یادداشتش نکند باید توکن را
     * دوباره بسازد، و این عمدی است: توکنِ همیشه‌خواندنی روی صفحه‌ای که
     * اسکرین‌شات می‌شود، دیگر راز نیست.
     */
    public function issueToken(): string
    {
        $raw = Str::random(48);
        $this->forceFill(['token_hash' => hash('sha256', $raw)])->save();

        return $raw;
    }

    /**
     * نودِ متناظر با یک توکنِ خام.
     *
     * ⚠️ جستجو روی **هشِ** توکن است، نه روی خودش. پس مقایسهٔ ستونیِ دیتابیس
     * چیزی از توکن نشت نمی‌دهد (هشِ حدسِ غلط هیچ پیشوندِ مشترکی با هشِ درست
     * ندارد) و ایندکس هم کارش را می‌کند. توکنِ خام هرگز در هیچ کوئری‌ای نیست.
     */
    public static function byToken(?string $raw): ?self
    {
        if (blank($raw) || strlen((string) $raw) < 16) {
            return null;
        }

        return self::where('token_hash', hash('sha256', $raw))->first();
    }

    /** بهایِ تمام‌شدهٔ یک گیگابایت روی این نود، به یورو */
    public function costPerGbEur(): float
    {
        return ((int) $this->cost_per_gb_micro) / 1_000_000;
    }
}
