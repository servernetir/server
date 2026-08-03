<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * یک سرویس فروخته‌شده به مشتری (اشتراک یا خرید یک‌بار).
 */
class Service extends Model
{
    protected $fillable = [
        'customer_id', 'name', 'description', 'currency_code', 'price',
        'tax_percent', 'cycle', 'status', 'next_due_at', 'activated_at',
        'cancelled_at', 'created_by',
        // تحویل/فراهم‌سازی
        'server_id', 'plan', 'username', 'domain', 'password', 'panel_url',
        'provision_status', 'provision_error', 'provisioned_at', 'provision_meta',
        // سرورِ ابری — به پلن اشاره می‌کند نه به سرور (پیش از خرید وجود ندارد)
        'cloud_plan_id', 'cloud_image_key', 'cloud_ssh_key_id', 'cloud_addons',
        // چرخهٔ تمدید (یادآوری/تعلیق/مهلت)
        'reminder_stage', 'suspended_at', 'grace_alert_at',
        // فروشِ ساعتیِ سرورِ ابری (پیش‌پرداخت از کیفِ پول)
        'billing_mode', 'hourly_rate_irt', 'hourly_rate_eur', 'last_metered_at', 'on_credit_out',
    ];

    protected function casts(): array
    {
        return [
            'price'          => 'integer',
            'tax_percent'    => 'integer',
            'next_due_at'    => 'date',
            'activated_at'   => 'datetime',
            'cancelled_at'   => 'datetime',
            'password'       => 'encrypted',   // رمزِ کنترل‌پنل — هرگز خام
            'provisioned_at' => 'datetime',
            'provision_meta' => 'array',
            'cloud_addons'   => 'array',
            'reminder_stage' => 'integer',
            'suspended_at'   => 'datetime',
            'grace_alert_at' => 'datetime',
            'hourly_rate_irt' => 'integer',
            'hourly_rate_eur' => 'integer',
            'last_metered_at' => 'datetime',
        ];
    }

    // ───────────────────────── فروشِ ساعتی ─────────────────────────

    /** آیا این سرویس ساعتی است (متر-محور، نه فاکتور-محور)؟ */
    public function isHourly(): bool
    {
        return $this->billing_mode === 'hourly';
    }

    /**
     * ساعتِ باقی‌مانده بر اساس اعتبارِ فعلیِ مشتری (کفِ عدد).
     * برای نمایشِ «~۴۸ ساعت مانده» در پنل.
     */
    public function hoursLeft(): int
    {
        $rate = (int) $this->hourly_rate_irt;

        if (! $this->isHourly() || $rate <= 0 || $this->customer === null) {
            return 0;
        }

        return intdiv(max(0, $this->customer->creditBalance('IRT')), $rate);
    }

    /**
     * دوره‌های مجاز — از config/billing.php می‌آید تا افزودنِ یک دوره (مثلِ
     * «شش‌ماهه») در یک جا انجام شود، نه پخش در مدل و کنترلر و کرون و Blade.
     * «once» دورهٔ صورت‌حساب نیست (سررسیدِ بعدی ندارد) و همیشه آخر می‌آید.
     *
     * @return list<string>
     */
    public static function cycles(): array
    {
        $fromConfig = array_keys((array) config('billing.cycles', []));

        // اگر config در دسترس نبود، به نقشهٔ پشتیبان برگرد تا اعتبارسنجیِ
        // فرم‌ها و dropdownها خالی نشوند
        return [...($fromConfig ?: array_keys(self::MONTHS_FALLBACK)), 'once'];
    }

    /**
     * پشتیبانِ سخت‌کد برای طولِ دوره‌ها.
     *
     * ⚠️ اگر فقط به config تکیه کنیم، یک غلطِ تایپی یا فایلِ آپلودنشدهٔ config
     * باعث می‌شود monthsIn صفر برگردد، next_due_at نال شود و کرونِ تمدید
     * (که whereNotNull('next_due_at') دارد) آن اشتراک را **برای همیشه** بی‌صدا
     * رها کند. این نقشه آخرین سنگر است.
     */
    public const MONTHS_FALLBACK = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'yearly' => 12];

    /** تعدادِ ماهِ یک دوره؛ 0 برای «یک‌بار» */
    public static function monthsIn(string $cycle): int
    {
        $months = (int) (config('billing.cycles.'.$cycle.'.months') ?? 0);

        return $months > 0 ? $months : (self::MONTHS_FALLBACK[$cycle] ?? 0);
    }

    /** برچسبِ دوره به زبانِ جاری */
    public static function labelFor(string $cycle): string
    {
        $label = config('billing.cycles.'.$cycle.'.label');

        return is_array($label)
            ? ($label[app()->getLocale()] ?? $label['fa'] ?? $cycle)
            : (string) ($label ?? __('ui.cycle_once'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** پلنِ ابریِ سفارش‌شده (اگر این سرویس سرورِ مجازی باشد) */
    public function cloudPlan(): BelongsTo
    {
        return $this->belongsTo(CloudPlan::class, 'cloud_plan_id');
    }

    /** سرورِ ابریِ ساخته‌شده — ۱:۱ */
    public function cloudInstance()
    {
        return $this->hasOne(CloudInstance::class, 'service_id');
    }

    /** آیا این سرویس سرورِ ابری است؟ */
    public function isCloud(): bool
    {
        return filled($this->cloud_plan_id);
    }

    /** سرویسی که باید روی سروری تحویل شود (نه یک خدمتِ صرفاً مالی مثل پشتیبانی) */
    public function needsProvisioning(): bool
    {
        return $this->server_id !== null && $this->provision_status !== 'done';
    }

    /** برچسبِ وضعیتِ تحویل برای نمایش @return array{0:string,1:string} */
    public function provisionBadge(): array
    {
        return match ($this->provision_status) {
            'done'    => ['تحویل شد', '#34d399'],
            'running' => ['در حال ساخت', '#22d3ee'],
            'pending' => ['در صف تحویل', '#fbbf24'],
            'manual'  => ['در انتظار تحویل دستی', '#fbbf24'],
            'failed'  => ['خطا در تحویل', '#ff6b6b'],
            default   => ['—', '#96a3ba'],
        };
    }

    /** مالیات این دوره بر حسب واحد فرعی */
    public function taxAmount(): int
    {
        return (int) round($this->price * $this->tax_percent / 100);
    }

    /** مبلغ کل هر دوره (خدمت + مالیات) */
    public function total(): int
    {
        return $this->price + $this->taxAmount();
    }

    public function isRecurring(): bool
    {
        return $this->cycle !== 'once';
    }

    /**
     * سررسید بعدی از یک مبدأ، بر اساس دوره.
     * برای «یک‌بار» سررسیدی نیست (null).
     */
    public function nextDueFrom(Carbon $from): ?Carbon
    {
        $months = self::monthsIn((string) $this->cycle);

        // NoOverflow لازم است: ۳۱ فروردین + ۱ ماه نباید به دو ماه بعد بپرد.
        return $months > 0 ? $from->copy()->addMonthsNoOverflow($months) : null;
    }

    public function cycleLabel(): string
    {
        return self::labelFor((string) $this->cycle);
    }

    /** @return array{0:string,1:string} برچسب و رنگ */
    /**
     * وضعیت‌هایی که یعنی «این سرویس مرده است؛ زنده‌اش نکن».
     *
     * 🔴 چرا ثابت و نه رشتهٔ `'cancelled'`ِ پراکنده: دو وضعیتِ پایانی داریم —
     * `cancelled` (مدیر بست) و `terminated` (مشتری خودش با کدِ یک‌بارمصرف حذف
     * کرد). هر جای کد که فقط `cancelled` را می‌سنجید، `terminated` بی‌صدا از
     * کنارش رد می‌شد. بدترین نمونه‌اش `PaymentService::applyPaid` بود: فاکتورِ
     * تمدیدِ بازِ یک سرویسِ حذف‌شده هنوز قابلِ پرداخت است، و پرداختش سرویسی را
     * «دوباره فعال» می‌کرد که سرورش واقعاً نزدِ زیرساخت پاک شده بود — مشتری پول
     * می‌داد و چیزی تحویل نمی‌گرفت.
     *
     * @var array<int,string>
     */
    public const DEAD_STATUSES = ['cancelled', 'terminated'];

    public function isDead(): bool
    {
        return in_array((string) $this->status, self::DEAD_STATUSES, true);
    }

    public function statusBadge(): array
    {
        return match ($this->status) {
            'active'             => ['فعال', '#34d399'],
            'pending'            => ['منتظر پرداخت', '#fbbf24'],
            'awaiting_provision' => ['در حال آماده‌سازی', '#22d3ee'],
            'provision_failed'   => ['خطا در تحویل', '#ff6b6b'],
            'suspended'          => ['معلق', '#ff6b6b'],
            'cancelled'          => ['لغو شده', '#5f6c82'],
            'terminated'         => ['حذف شده', '#5f6c82'],
            'expired'            => ['منقضی', '#96a3ba'],
            default              => [$this->status, '#96a3ba'],
        };
    }
}
