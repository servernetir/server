<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * پروژهٔ AI — ظرفِ admission، نه جابه‌جاکُنندهٔ پول.
 *
 * توکنِ AI به پروژه گره می‌خورد: کلیدِ بی‌پروژه اصلاً AI-ساز نیست و کلیدِ
 * پروژهٔ غیرفعال رد می‌شود. **M2 هیچ پولی جابه‌جا نمی‌کند** — بودجه فقط
 * ذخیره می‌شود تا سیمِ admission در M3/M4 برنامه و بودجه را بدهد.
 *
 * 🔴 بودجه **صحیح** است: `monthly_budget_irt` به تومانِ صحیح، هم‌سو با
 *    `daily_spend_cap_irt` توکن. هیچ float — سطحِ میکرو در M1 را نگاه کن.
 *
 * 🔴 سیکلِ ماه، **قطعی** است نه کرونی: `budgetWindowFor(لحظه)` بازهٔ [از،
 *    تا) را از `budget_reset_day` حساب می‌کند، بدونِ زمان‌بند. ذخیرهٔ
 *    مِتا زیر (`budget_window_*`) همان مِمِ وکتورِ قطعی می‌شود، و کرونِ
 *    نادرست فقط «به‌روزرسانی» را عقب می‌اندازد، نه درستش را خراب می‌کند.
 */
class AiProject extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ARCHIVED = 'archived';

    /** آرشیو پایانی است — از این پس هیچ تغییرِ وضعیتی به active برنمی‌گردد. */
    public const STATUS_TERMINAL = [self::STATUS_ARCHIVED];

    public const PERIOD_NONE = 'none';
    public const PERIOD_MONTHLY = 'monthly';

    protected $fillable = [
        'customer_id', 'name', 'slug', 'status',
        'monthly_budget_irt', 'budget_period', 'budget_reset_day',
        'budget_window_from', 'budget_window_until', 'budget_window_key',
    ];

    protected function casts(): array
    {
        return [
            'monthly_budget_irt'     => 'integer',
            'budget_reset_day'       => 'integer',
            'budget_window_from'     => 'datetime',
            'budget_window_until'    => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(CustomerApiToken::class, 'ai_project_id');
    }

    public function activeTokens(): HasMany
    {
        return $this->tokens()->usable();
    }

    /** پروژه‌هایی که کلیدِ AI را زنده نگه می‌دارند — فقط active */
    public function scopeAdmissible(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    /**
     * بازهٔ سیکلِ ماهِ نقطهٔ $at — [شروع، پایانِ حصور). null = پروژه بودجه
     * ماهانه ندارد. روزِ شروع `budget_reset_day` است (1..28؛ نال = ۱).
     *
     * قراردادِ مرز: [from, until) — لحظهٔ `until` خودش مالِ سیکلِ بعد است،
     * همان نیم‌بازه‌ای که `resolveAt` M1 روی آن گردید.
     */
    public function budgetWindowFor(\DateTimeInterface $at): ?array
    {
        if ($this->budget_period !== self::PERIOD_MONTHLY) {
            return null;
        }

        $from = \Illuminate\Support\Carbon::parse($at);
        $day = max(1, min(28, (int) ($this->budget_reset_day ?? 1)));
        $today = $from->day;

        if ($today < $day) {
            $from->subMonthNoOverflow();
        }

        $from->setDay($day)->startOfDay();
        $until = $from->copy()->addMonthNoOverflow()->setDay($day)->startOfDay();

        return [
            'key'   => $from->format('Y-m'),
            'from'  => $from->copy(),
            'until' => $until,
        ];
    }

    /** ذخیرهٔ مِتاای بازه در نوشتن — نه در کرون: هر بار تغییرِ تنظیم بودجه
     *  یا صدورِ توکن فراخوانی می‌شود؛ قطعی و تکرار-آمیز. */
    public function refreshBudgetWindow(): void
    {
        $w = $this->budgetWindowFor(now());

        if ($w === null) {
            $this->forceFill([
                'budget_window_from' => null,
                'budget_window_until' => null,
                'budget_window_key'  => null,
            ])->save();

            return;
        }

        $this->forceFill([
            'budget_window_from'  => $w['from'],
            'budget_window_until' => $w['until'],
            'budget_window_key'   => $w['key'],
        ])->save();
    }

    /** آیا لحظهٔ $at در بازهٔ ذخیره‌شدهٔ سیکلستی تازه می‌افتد؟ (بدونِ بازه = بی‌سقف) */
    public function budgetWindowCovers(\DateTimeInterface $at): bool
    {
        if ($this->budget_window_from === null || $this->budget_window_until === null) {
            return true; // سقفِ بودجه تعریف نشده — admission بگذار؛ سقفِ خرجِ توکن را پوشش می‌دهد
        }

        return $at >= $this->budget_window_from && $at < $this->budget_window_until;
    }

    /**
     * غیرفعال‌سازی — برخاستنی. کلیدهای bound **پوستِ خودشان را نگه می‌دارند**
     * ولی admission ردشان می‌کند؛ صدا زدنِ این متُد سطرِ توکن را نمی‌خواند
     * و نمی‌نویسد — منبعِ حقیقتِ دروازه، وضعیتِ پروژه است.
     */
    public function disable(): void
    {
        if ($this->status === self::STATUS_ACTIVE) {
            $this->forceFill(['status' => self::STATUS_DISABLED])->save();
        }
    }

    /**
     * آرشیو — پایانی. در تمایز با disable، کلیدهای boundِ این پروژه **نرم
     * ابطال** می‌شوند تا از فهرستِ صدور و مستندات هم بروند — سطرِ آن‌ها برای
     * حسابرسی می‌مانَد (الگویِ `CustomerApiToken::revoke`).
     */
    public function archive(): void
    {
        $this->forceFill(['status' => self::STATUS_ARCHIVED])->save();

        $this->tokens()->usable()->get()->each(fn (CustomerApiToken $t) => $t->revoke());
    }
}
