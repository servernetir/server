<?php

namespace App\Services\Cloud;

use App\Models\CloudPlan;
use App\Models\Service;
use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy retention accounting after verified hourly-credit suspension.
 *
 * Owner decision, 2026-10-31: starting credit must remain spendable while a
 * server is running. Do not reserve future retention or stop a funded server.
 * Existing suspended reserves stay protected until resumed, converted,
 * charged or released. Retention grace is unchanged; where no reserve exists,
 * its supplier cost is a business expense, not funded by the start minimum.
 */
final class HourlyHold
{
    /** Retention after verified shutdown; separate from the starting-credit gate. */
    public const GRACE_HOURS = 24;

    /** زیرساخت‌هایی که ماشینِ خاموش برایشان هزینه‌ای ندارد */
    public const STANDBY_FREE_PROVIDERS = ['salad', 'proxmox'];

    /** Only already-suspended servers may still hold a legacy reserve. */
    public const HOLDING_STATUSES = ['suspended'];

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

    /** The amount actually included in Wallet::reservedOf for this service. */
    public static function reservedFor(Service $service): int
    {
        return self::enabled() && $service->isHourly()
            && in_array($service->status, self::HOLDING_STATUSES, true)
            ? max(0, (int) $service->hold_reserve_irt) : 0;
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

    /** Historical full reserve, for legacy records and diagnostics only. */
    public static function fullReserve(int $rate, ?string $onCreditOut): int
    {
        return $onCreditOut === 'terminate' ? 0 : max(0, $rate) * self::GRACE_HOURS;
    }

    /**
     * Keep the cost rate current and clear obsolete running reserves. Wallet
     * ignores them immediately, even before this service's next meter tick.
     */
    public function syncRunning(Service $service): void
    {
        if (! self::enabled() || ! $service->isHourly() || $service->status === 'suspended') {
            return;
        }

        $rate = $this->rateIrt($service);
        // Creation credit must remain spendable while the server is running.
        $reserve = 0;

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
