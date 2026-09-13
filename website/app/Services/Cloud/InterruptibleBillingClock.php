<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ساعتِ صورت‌حسابِ ماشین‌های قطع‌شدنی را با وضعیتِ واقعی همگام نگه می‌دارد.
 *
 * ماشینِ خاموش نباید «بدهیِ زمانی» بسازد. هر مشاهدهٔ غیرروشن، و هر گذارِ
 * تازه به روشن، لنگر را روی همان لحظه می‌نشاند. در نتیجه فاصلهٔ خاموشی هرگز
 * در اجرای بعدیِ متر به مصرف تبدیل نمی‌شود.
 */
class InterruptibleBillingClock
{
    public function applies(Service $service): bool
    {
        return $service->billing_mode === 'hourly'
            && (bool) $service->cloudPlan?->is_interruptible;
    }

    /**
     * وضعیتِ معتبرِ زیرساخت را ثبت کن و در صورت لزوم دورهٔ Billing را از نو آغاز کن.
     *
     * @param  array<string,mixed>  $instanceFields
     */
    public function record(
        Service $service,
        CloudInstance $instance,
        string $status,
        array $instanceFields = [],
        bool $forceNewRun = false,
    ): bool {
        $at = Carbon::now();
        $restartClock = $this->applies($service)
            && ($forceNewRun || $status !== 'running' || $instance->status !== 'running');

        DB::transaction(function () use ($service, $instance, $status, $instanceFields, $restartClock, $at): void {
            if ($restartClock) {
                Service::whereKey($service->id)->update(['last_metered_at' => $at]);
                $service->last_metered_at = $at;
            }

            $instance->update(array_merge($instanceFields, ['status' => $status]));
        });

        return $restartClock;
    }

    /**
     * وضعیت زنده تأیید نشد: fail-closed، یعنی نه کسر و نه بدهی برای بعد.
     */
    public function pauseUnverified(Service $service): void
    {
        if (! $this->applies($service)) {
            return;
        }

        $at = Carbon::now();
        Service::whereKey($service->id)->update(['last_metered_at' => $at]);
        $service->last_metered_at = $at;
    }
}
