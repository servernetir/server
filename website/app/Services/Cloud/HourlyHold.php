<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use App\Models\Service;
use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * نگهداریِ ۲۴ساعتهٔ سرورِ ساعتیِ خاموش پس از اتمامِ اعتبار — **بدونِ ضرر**.
 *
 * ═══ رخداد و تصمیم (شهریور ۱۴۰۵) ═══
 *
 * تا امروز مهلتِ ۲۴ساعته رایگان بود و استدلالش «کفِ شروعِ ۲۴ ساعت = مهلتِ ۲۴
 * ساعت» بود. آن برابری فقط **لحظهٔ خرید** را می‌پوشاند: مشتری همان ۲۴ ساعت را
 * مصرف می‌کند، اعتبار صفر می‌شود، و ۲۴ ساعتِ نگهداریِ بعدی را روی
 * هتزنر/aeza/OVH/آروان — که ماشینِ خاموش را کامل صورت‌حساب می‌کنند — **ما**
 * می‌پردازیم. مشتری‌ای که برنگردد، خالص ضرر است.
 *
 * کارفرما: «جایی که برای ما هزینه دارد ما هم هزینه بگیریم، جایی که ندارد
 * نمی‌گیریم… تعداد روز را تأیید می‌کنم ولی متضرر نشویم اصلاً.»
 *
 * ═══ قاعده ═══
 *
 *  • نرخِ نگهداری = **بهای تمام‌شدهٔ** ساعتیِ همان ماشین نزدِ زیرساخت (سربارِ
 *    ارزی را درایور از قبل در بها نشانده)، گرد به بالا تا ۱۰۰ تومان، و هرگز
 *    بیشتر از نرخِ فروش. روی نگهداری سود نمی‌گیریم.
 *  • زیرساختی که ماشینِ خاموش برایش هزینه ندارد (`salad`، `proxmox` و هر پلنِ
 *    `is_interruptible`) ⇒ نرخِ صفر ⇒ نگهداری مثلِ قبل رایگان.
 *  • **ذخیره** (`services.hold_reserve_irt`): برای هر سرورِ ساعتیِ زنده
 *    `GRACE_HOURS × نرخ` کنار گذاشته می‌شود و `Wallet::reservedOf` آن را از
 *    «در دسترس» کم می‌کند. پس نه مترِ ساعتی، نه فاکتور، نه دامنه، نه AI
 *    نمی‌توانند پولِ نگهداری را خرج کنند — لحظهٔ خاموشی پولش از پیش در کیف است.
 *  • در مهلت، هر ساعتِ سپری‌شده به نرخِ نگهداری کسر می‌شود و **همان‌قدر** از
 *    ذخیره کم می‌شود (در یک تراکنش). شارژ ⇒ روشن‌شدن؛ مصرف‌نشده هرگز خرج نشده.
 *  • سیاستِ «حذف هنگام اتمامِ اعتبار» (`terminate`) نگهداری ندارد ⇒ ذخیرهٔ صفر.
 *
 * 🔴 بهای ناشناخته ⇒ نرخِ صفر + آژیرِ پایدار (همان قاعدهٔ `alarmIfUnderwater`:
 *    «بها نداریم ⇒ ادعا هم نداریم»). عددِ حدسی از جیبِ مشتری ممنوع.
 */
final class HourlyHold
{
    /**
     * مهلتِ نگهداری پیش از حذف — ساعت.
     *
     * 🔴 تصمیمِ کارفرما: برابر با `CloudPlan::HOURLY_START_MIN_HOURS`
     * (`HourlyHoldTest` برابری را قفل می‌کند).
     */
    public const GRACE_HOURS = 24;

    /** زیرساخت‌هایی که ماشینِ خاموش برایشان هزینه‌ای ندارد */
    public const STANDBY_FREE_PROVIDERS = ['salad', 'proxmox'];

    /** وضعیت‌هایی که ذخیرهٔ نگهداری را نگه می‌دارند */
    public const HOLDING_STATUSES = ['active', 'awaiting_provision', 'suspended'];

    private ?int $eurToman = null;

    private static ?bool $columns = null;

    public function __construct(private readonly CloudPricing $pricing) {}

    /** سرورِ مهاجرت‌نخورده: همه‌چیز صفر و رفتار عینِ قبل */
    public static function enabled(): bool
    {
        return self::$columns ??= Schema::hasTable('services') && Schema::hasColumn('services', 'hold_reserve_irt');
    }

    /** برای تست پس از مهاجرت در همان پروسه */
    public static function flush(): void
    {
        self::$columns = null;
    }

    /**
     * مجموعِ ذخیرهٔ نگهداریِ مشتری — تومان. `Wallet::reservedOf` این را می‌خواند.
     */
    public static function heldOf(int $customerId): int
    {
        if (! self::enabled()) {
            return 0;
        }

        return (int) Service::query()
            ->where('customer_id', $customerId)
            ->where('billing_mode', 'hourly')
            ->whereIn('status', self::HOLDING_STATUSES)
            ->sum('hold_reserve_irt');
    }

    /**
     * نرخِ نگهداریِ امروزِ یک سرویس — تومان/ساعت. صفر = برای ما رایگان (یا بها
     * نامعلوم است و آژیر زده شد).
     */
    public function rateIrt(Service $service): int
    {
        if (! $service->isHourly()) {
            return 0;
        }

        $plan = $service->cloudPlan;
        $provider = (string) ($service->cloudInstance?->provider ?? $plan?->provider ?? '');

        if ((bool) $plan?->is_interruptible || in_array($provider, self::STANDBY_FREE_PROVIDERS, true)) {
            return 0;
        }

        if ($plan === null) {
            return 0;    // سرویسِ بی‌پلن (قدیمی/دستی) — بها نداریم، ادعا هم نه
        }

        /*
        | تحویل می‌تواند روی زیرساختِ دیگرِ همان اسلاگ رفته باشد؛ بهای **همان**
        | ماشین مرجع است (همان قاعدهٔ `CloudMeterHourly::alarmIfUnderwater`).
        */
        $row = ($provider !== '' && $provider !== $plan->provider)
            ? (CloudPlan::where('slug', $plan->slug)->where('provider', $provider)->orderByDesc('id')->first() ?? $plan)
            : $plan;

        return $this->fromCost($row, (int) $service->hourly_rate_irt, $service->id);
    }

    /** نرخِ نگهداری برای **پلن** — پیش از خرید (فروشگاه و صفحهٔ فروش) */
    public function rateForPlan(CloudPlan $plan, int $sellRateIrt): int
    {
        if ((bool) $plan->is_interruptible || in_array((string) $plan->provider, self::STANDBY_FREE_PROVIDERS, true)) {
            return 0;
        }

        return $this->fromCost($plan, $sellRateIrt);
    }

    /** ذخیرهٔ کاملِ یک سرورِ **در حالِ کار** با این نرخ و سیاستِ اتمامِ اعتبار */
    public static function fullReserve(int $rate, ?string $onCreditOut): int
    {
        return $onCreditOut === 'terminate' ? 0 : max(0, $rate) * self::GRACE_HOURS;
    }

    /**
     * نرخ و ذخیرهٔ سرورِ در حالِ کار را با بهای امروز هم‌سو می‌کند (خرید، هر
     * تیکِ متر، تغییرِ سیاست، روشن‌شدنِ دوباره). فقط ستون‌ها را می‌نویسد.
     */
    public function syncRunning(Service $service): void
    {
        if (! self::enabled() || ! $service->isHourly() || $service->status === 'suspended') {
            return;
        }

        $rate = $this->rateIrt($service);
        $reserve = self::fullReserve($rate, $service->on_credit_out);

        if ((int) $service->hold_rate_irt !== $rate || (int) $service->hold_reserve_irt !== $reserve) {
            $service->forceFill(['hold_rate_irt' => $rate, 'hold_reserve_irt' => $reserve])->save();
        }
    }

    /** ساعت‌های نگهداریِ باقی — از خودِ ذخیره، نه از ساعتِ دیوار */
    public static function hoursRemaining(Service $service): int
    {
        $rate = (int) $service->hold_rate_irt;

        return $rate > 0 ? intdiv((int) $service->hold_reserve_irt, $rate) : 0;
    }

    /** لحظهٔ حذفِ سرورِ تعلیق‌شده — برای نمایش به مشتری */
    public static function deletesAt(Service $service): ?Carbon
    {
        return $service->suspended_at instanceof Carbon
            ? $service->suspended_at->copy()->addHours(self::GRACE_HOURS)
            : null;
    }

    private function fromCost(CloudPlan $row, int $sellRateIrt, ?int $serviceId = null): int
    {
        $micro = (int) ($row->cost_hour_eur_micro ?? 0);

        if ($micro <= 0 && (int) $row->cost_eur_cents > 0) {
            $micro = (int) ceil((int) $row->cost_eur_cents * 10_000 / 720);
        }

        $this->eurToman ??= (int) $this->pricing->eurToToman();

        if ($micro <= 0 || $this->eurToman <= 0) {
            if ($serviceId !== null) {
                ErrorTracker::noteOnce('billing',
                    "نرخِ نگهداریِ سرورِ ساعتیِ #{$serviceId} نامعلوم است (بها یا نرخِ یورو نیست) — مهلتِ ۲۴ساعتهٔ این سرور رایگان حساب می‌شود.",
                    21600, ['service' => $serviceId]);
            }

            return 0;
        }

        $irt = (int) (ceil(($micro / 1_000_000) * $this->eurToman / 100) * 100);

        return $sellRateIrt > 0 ? min($irt, $sellRateIrt) : $irt;
    }
}
