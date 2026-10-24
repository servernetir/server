<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * توقف/رفع توقفِ قابل‌ردیابیِ متر ساعتی برای یک سرویس مشخص.
 *
 * provider یک گاردِ پیش‌شرط است: اگر اپراتور شناسهٔ درست ولی زیرساختِ اشتباه
 * داده باشد، هیچ نوشتنی انجام نمی‌شود. این فرمان ماشین، شبکه و فروش سایر
 * زیرساخت‌ها را تغییر نمی‌دهد.
 */
class CloudHourlyBillingHold extends Command
{
    protected $signature = 'cloud:hourly-billing-hold
        {service : شناسهٔ سرویس}
        {--release : رفع توقف}
        {--provider= : زیرساخت مورد انتظار؛ مثلاً arvan}
        {--reason= : علت عملیاتیِ توقف}';

    protected $description = 'توقف یا رفع توقفِ صورتحساب ساعتیِ یک سرویس، بدون خاموش/حذف‌کردن ماشین';

    public function handle(): int
    {
        $id = (int) $this->argument('service');
        $service = Service::with('cloudInstance')->find($id);

        if ($service === null) {
            $this->error("سرویس #{$id} پیدا نشد.");

            return self::FAILURE;
        }

        if (! $service->isHourly()) {
            $this->error("سرویس #{$id} ساعتی نیست؛ هیچ تغییری انجام نشد.");

            return self::FAILURE;
        }

        $expected = strtolower(trim((string) $this->option('provider')));
        $actual = strtolower(trim((string) $service->cloudInstance?->provider));

        if ($expected !== '' && $actual !== $expected) {
            $this->error("زیرساخت سرویس #{$id} «{$actual}» است، نه «{$expected}»؛ هیچ تغییری انجام نشد.");

            return self::FAILURE;
        }

        $release = (bool) $this->option('release');
        $reason = trim((string) $this->option('reason'));

        if (! $release && $reason === '') {
            $this->error('برای توقف، --reason الزامی است تا علت قابل‌ردیابی بماند.');

            return self::FAILURE;
        }

        $changed = false;

        DB::transaction(function () use ($id, $release, $reason, $actual, &$changed): void {
            $fresh = Service::whereKey($id)->lockForUpdate()->firstOrFail();
            $meta = (array) ($fresh->provision_meta ?? []);
            $state = (array) ($meta['hourly_billing_hold'] ?? []);

            if ($release) {
                if (($state['active'] ?? false) !== true) {
                    return;
                }

                $state['active'] = false;
                $state['released_at'] = now()->toIso8601String();
                $state['released_by'] = 'artisan';
            } else {
                if (($state['active'] ?? false) === true) {
                    return;
                }

                $state = [
                    'active' => true,
                    'reason' => mb_substr($reason, 0, 240),
                    'provider' => $actual !== '' ? $actual : null,
                    'held_at' => now()->toIso8601String(),
                    'held_by' => 'artisan',
                ];
            }

            $meta['hourly_billing_hold'] = $state;

            // هم هنگام توقف و هم هنگام رفع، بازهٔ نامطمئن هرگز catch-up نمی‌شود.
            $fresh->forceFill([
                'provision_meta' => $meta,
                'last_metered_at' => now(),
            ])->save();

            $changed = true;
        });

        if (! $changed) {
            $this->info($release ? "صورتحساب #{$id} از قبل آزاد بود." : "صورتحساب #{$id} از قبل متوقف بود.");

            return self::SUCCESS;
        }

        $fresh = Service::findOrFail($id);
        ActivityLog::forService(
            $fresh,
            'service',
            $release
                ? 'رفع توقف عملیاتی صورتحساب ساعتی؛ لنگر از زمان رفع از سر گرفته شد.'
                : 'توقف عملیاتی صورتحساب ساعتی: '.$reason,
            'system'
        );

        $this->info($release
            ? "صورتحساب ساعتی #{$id} آزاد شد؛ هزینهٔ دورهٔ توقف catch-up نمی‌شود."
            : "صورتحساب ساعتی #{$id} متوقف شد؛ ماشین و سایر سرویس‌ها دست‌نخورده‌اند.");

        return self::SUCCESS;
    }
}
