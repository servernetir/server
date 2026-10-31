<?php

namespace App\Services\Cloud;

use App\Models\CloudInstance;
use App\Models\Customer;
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
     * @return bool True when this observation cannot bill elapsed hours.
     */
    public function record(
        Service $service,
        CloudInstance $instance,
        string $status,
        array $instanceFields = [],
        bool $forceNewRun = false,
        ?Carbon $observedAt = null,
    ): bool {
        $at = Carbon::now();
        $restartClock = $this->applies($service)
            && ($forceNewRun || $status !== 'running' || $instance->status !== 'running'
                || HourlyGpuStart::pending($service) > 0);

        DB::transaction(function () use ($service, $instance, $status, $instanceFields, &$restartClock, $forceNewRun, $observedAt, $at): void {
            Customer::whereKey($service->customer_id)->lockForUpdate()->first();
            $current = Service::whereKey($service->id)->lockForUpdate()->first();
            $currentInstance = CloudInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            if ($current !== null && $this->applies($current)) {
                $meta = (array) $current->provision_meta;
                $powerAt = data_get($meta, 'hourly_power_at') ?: data_get($meta, 'hourly_restart.claimed_at');
                $pending = HourlyGpuStart::pending($current) > 0;
                $acceptedAt = data_get($meta, 'hourly_restart.accepted_at');
                $needsAcceptance = $pending && (int) data_get($meta, 'hourly_restart.observation_version', 0) >= 1;
                // Cached or in-flight replies from an older power operation are
                // neither delivery evidence nor permission to advance billing.
                if (! $forceNewRun && ($pending || filled($powerAt))
                    && ($observedAt === null || ($needsAcceptance && blank($acceptedAt))
                        || (filled($powerAt) && $observedAt->lt(Carbon::parse($powerAt)))
                        || (filled($acceptedAt) && $observedAt->lt(Carbon::parse($acceptedAt))))) {
                    $restartClock = true;

                    return;
                }
                if ($forceNewRun) {
                    $meta['hourly_power_at'] = $at->toISOString();
                    if ($pending && $status === 'building') {
                        $meta['hourly_restart']['accepted_at'] = $at->toISOString();
                    }
                    $current->update(['provision_meta' => $meta]);
                }
            }
            $restartClock = $current !== null && $this->applies($current)
                && ($forceNewRun || $status !== 'running' || $currentInstance->status !== 'running'
                    || HourlyGpuStart::pending($current) > 0);
            if ($current !== null && $current->status === 'active' && $this->applies($current)
                && $status === 'running' && ! $forceNewRun && ! $current->hourlyBillingHeld()) {
                app(HourlyGpuStart::class)->settle($current);
            }
            if ($restartClock) {
                Service::whereKey($service->id)->update(['last_metered_at' => $at]);
                $service->last_metered_at = $at;
            }

            $currentInstance->update(array_merge($instanceFields, ['status' => $status]));
            $instance->setRawAttributes($currentInstance->getAttributes(), true);
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
