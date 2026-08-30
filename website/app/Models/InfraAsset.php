<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * یک ماشین در ناوگانِ ما — عکسِ آخرین اسکن، به‌علاوهٔ حافظهٔ زمانی و یادداشتِ مدیر.
 *
 * ⚠️ منبعِ حقیقت نیست. برای شرحِ کامل، سرِ مهاجرتِ `infra_assets` را ببینید.
 */
class InfraAsset extends Model
{
    protected $fillable = [
        'provider', 'provider_ref', 'name', 'ipv4', 'ipv6', 'plan_ref', 'location_ref',
        'provider_status', 'provider_created_at', 'service_id', 'customer_id', 'service_status',
        'link_state', 'ip_mismatch', 'cost_eur_cents', 'cost_source',
        'role', 'note', 'acknowledged_at', 'acknowledged_by',
        'first_seen_at', 'last_seen_at', 'unlinked_since', 'missing_since', 'meta',
    ];

    /** نامِ زیرساخت هرگز در JSON بیرون نمی‌رود — همان قاعدهٔ سفیدبرچسبیِ کلِ این حوزه */
    protected $hidden = ['provider', 'provider_ref'];

    protected function casts(): array
    {
        return [
            'ip_mismatch'         => 'bool',
            'meta'                => 'array',
            'provider_created_at' => 'datetime',
            'acknowledged_at'     => 'datetime',
            'first_seen_at'       => 'datetime',
            'last_seen_at'        => 'datetime',
            'unlinked_since'      => 'datetime',
            'missing_since'       => 'datetime',
        ];
    }

    // ───────────────────────── واژگان ─────────────────────────

    public const STATE_ATTACHED = 'attached';
    public const STATE_ORPHAN   = 'orphan';
    public const STATE_ZOMBIE   = 'zombie';
    public const STATE_GHOST    = 'ghost';

    /** حالت‌هایی که یعنی «ماشین واقعاً هست و پولش را ما می‌دهیم» */
    public const BILLABLE_STATES = [self::STATE_ATTACHED, self::STATE_ORPHAN, self::STATE_ZOMBIE];

    /** حالت‌هایی که پولِ ما را بی‌درآمد می‌سوزانند */
    public const LEAKING_STATES = [self::STATE_ORPHAN, self::STATE_ZOMBIE];

    public const ROLES = [
        'unknown'      => 'نامشخص',
        'customer'     => 'مشتری',
        'internal'     => 'داخلی (خودمان)',
        'staging'      => 'آزمایشی',
        'reserve'      => 'ذخیره',
        'decommission' => 'در صفِ حذف',
    ];

    /**
     * نقش‌هایی که «بی‌درآمد بودن» را **توجیه‌شده** می‌کنند.
     *
     * 🔴 توجیه‌شده ≠ رایگان. این ماشین‌ها از فهرستِ «باید تصمیم بگیری» بیرون
     * می‌روند ولی هزینه‌شان همچنان در جمعِ ماهانه شمرده می‌شود. اگر از جمع هم
     * بیرونشان می‌بردیم، «هزینهٔ زیرساختِ داخلی» نامرئی می‌شد — و نامرئی‌شدنِ
     * هزینه دقیقاً همان چیزی است که این صفحه علیه‌اش ساخته شده.
     */
    public const OWNED_ROLES = ['internal', 'staging', 'reserve'];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    // ───────────────────────── تصمیم‌ها ─────────────────────────

    /**
     * آیا این ردیف واقعاً «کارِ نکرده» است؟
     *
     * سه شرط، و هر سه لازم: بی‌سرویسِ زنده باشد، مدیر تأییدش نکرده باشد، و
     * نقشش آن را توجیه نکند.
     */
    public function needsDecision(): bool
    {
        return in_array($this->link_state, self::LEAKING_STATES, true)
            && $this->acknowledged_at === null
            && ! in_array($this->role, self::OWNED_ROLES, true);
    }

    /** چند روز است که بی‌صاحب مانده؟ null یعنی نمی‌دانیم (هنوز اسکنِ دومی نبوده) */
    public function idleDays(): ?int
    {
        return $this->unlinked_since?->diffInDays(now());
    }

    /**
     * پولی که تا امروز بابتِ این ماشینِ بی‌درآمد داده‌ایم — به سنتِ یورو.
     *
     * ⚠️ عمداً از `unlinked_since` حساب می‌شود نه از `provider_created_at`:
     * ماشینی که سه ماه به مشتری فروخته شده و هفتهٔ پیش رها شده، هفته‌ای ضرر
     * داده نه سه ماهی. عددِ بزرگ‌ترِ نادرست، گزارش را غیرقابلِ استناد می‌کند.
     */
    public function wastedEurCents(): int
    {
        $days = $this->idleDays();

        if ($days === null || $this->cost_eur_cents <= 0) {
            return 0;
        }

        return (int) round($this->cost_eur_cents * $days / 30);
    }

    public function stateLabel(): string
    {
        return match ($this->link_state) {
            self::STATE_ATTACHED => 'متصل به مشتری',
            self::STATE_ORPHAN   => 'بی‌صاحب',
            self::STATE_ZOMBIE   => 'سرویس بسته، ماشین باز',
            self::STATE_GHOST    => 'ماشین ناپدید',
            default              => (string) $this->link_state,
        };
    }

    public function stateColor(): string
    {
        return match ($this->link_state) {
            self::STATE_ATTACHED => '#34d399',
            self::STATE_ORPHAN   => '#fbbf24',
            self::STATE_ZOMBIE   => '#ff6b6b',
            self::STATE_GHOST    => '#a78bfa',
            default              => '#64748b',
        };
    }

    public function statusColor(): string
    {
        return match ($this->provider_status) {
            'running'  => '#34d399',
            'off'      => '#94a3b8',
            'building' => '#fbbf24',
            'error'    => '#ff6b6b',
            'deleted'  => '#a78bfa',
            default    => '#64748b',
        };
    }

    // ───────────────────────── جست‌وجو ─────────────────────────

    /**
     * جست‌وجوی آزاد — همان یک کادری که مدیر واقعاً استفاده می‌کند.
     *
     * ⚠️ چرا `service_id` جداگانه: مدیر «۷۶» را می‌زند و منظورش سرویسِ ۷۶ است،
     * ولی `LIKE '%76%'` روی آی‌پی هم می‌خورَد و نتیجه پر از نویز می‌شود. عددِ
     * خالص هم به‌عنوانِ شناسه و هم به‌عنوانِ متن جست‌وجو می‌شود، ولی تطبیقِ
     * دقیقِ شناسه اول می‌آید.
     */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $q;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $q->where(function (Builder $w) use ($term, $like) {
            $w->where('ipv4', 'like', $like)
                ->orWhere('ipv6', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('provider_ref', 'like', $like)
                ->orWhere('plan_ref', 'like', $like)
                ->orWhere('location_ref', 'like', $like)
                ->orWhere('note', 'like', $like);

            if (ctype_digit($term)) {
                $w->orWhere('service_id', (int) $term)
                    ->orWhere('customer_id', (int) $term);
            }

            // کدِ مشتری (مثلِ SN-1042) و ایمیلش در جدولِ خودمان نیستند؛
            // زیرپرس‌وجو ارزان‌تر از join روی هر ردیف است.
            $w->orWhereIn('customer_id', Customer::query()
                ->where('code', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->limit(200)
                ->pluck('id'));

            $w->orWhereIn('service_id', Service::query()
                ->where('name', 'like', $like)
                ->limit(200)
                ->pluck('id'));
        });
    }
}
