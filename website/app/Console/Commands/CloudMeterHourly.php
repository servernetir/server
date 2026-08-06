<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\CreditEntry;
use App\Models\Service;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * متر کردنِ سرورهای ابریِ **ساعتی** — هر ساعت از کیفِ پولِ مشتری کم می‌کند.
 *
 * قاعده‌های پول (تأییدِ کارفرما):
 *  • حداقلِ اعتبار برای شروع = ۲۴ ساعت (در checkout بررسی می‌شود، نه این‌جا).
 *  • **بدونِ حداقلِ مصرف** — مشتری می‌تواند بعد از ۱ ساعت لغو کند و اعتبارِ
 *    استفاده‌نشده در کیفش می‌ماند.
 *  • فقط ساعتِ **گذشته** کسر می‌شود؛ ساعتِ اول در لحظهٔ خرید کسر شده است.
 *
 * ایمنیِ پول (سه محافظ):
 *  ۱) **idempotent**: با claimِ اتمی روی `last_metered_at` (UPDATE شرطی) — دو
 *     اجرا در یک ساعت هرگز دوبار کسر نمی‌کند.
 *  ۲) هرگز بدونِ اعتبارِ کافی کسر نمی‌کند (اول موجودی، بعد کسر).
 *  ۳) جبرانِ ساعت‌های ازدست‌رفته سقف دارد (اگر کرون مدتی نخوابید، بی‌نهایت کسر نکند).
 */
class CloudMeterHourly extends Command
{
    protected $signature = 'cloud:meter';

    protected $description = 'کسرِ ساعتیِ سرورهای ابریِ ساعتی از کیفِ پول';

    /** سقفِ جبران در یک اجرا — اگر کرون خوابیده بود، بی‌نهایت کسر نکن. */
    private const CATCHUP_CAP = 48;

    /** مهلتِ نگه‌داشتنِ سرورِ تعلیق‌شده (نبودِ اعتبار) پیش از حذف — ساعت. */
    private const SUSPEND_GRACE_HOURS = 24;

    public function handle(CloudProvisioner $prov): int
    {
        // روی سرورِ ازقبل‌مهاجرت‌نکرده بی‌صدا رد شو (نه خطا)
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'billing_mode')) {
            return self::SUCCESS;
        }

        $charged = 0;
        $stopped = 0;

        // ۱) سرویس‌های فعالِ ساعتی که یک ساعت از آخرین کسرشان گذشته
        $due = Service::query()
            ->where('billing_mode', 'hourly')
            ->where('status', 'active')
            ->whereNotNull('hourly_rate_irt')
            ->where('hourly_rate_irt', '>', 0)
            ->where(fn ($q) => $q->whereNull('last_metered_at')
                ->orWhere('last_metered_at', '<=', now()->subHour()))
            ->with('customer')
            ->get();

        foreach ($due as $service) {
            $this->meterOne($service, $prov) ? $charged++ : $stopped++;
        }

        // ۲) سرویس‌های تعلیق‌شدهٔ ساعتی: اگر شارژ کردند دوباره روشن، وگرنه پس از مهلت حذف
        $this->handleSuspended($prov);

        $this->info("متر شد: {$charged} کسر، {$stopped} متوقف/تعلیق.");

        return self::SUCCESS;
    }

    /** کسرِ یک سرویس. true اگر کسر شد، false اگر به مسیرِ اتمامِ اعتبار رفت. */
    private function meterOne(Service $service, CloudProvisioner $prov): bool
    {
        $rate = (int) $service->hourly_rate_irt;
        $customer = $service->customer;

        if ($rate <= 0 || $customer === null) {
            return false;
        }

        $prev = $service->last_metered_at ?? $service->activated_at ?? $service->created_at;
        $prev = $prev instanceof Carbon ? $prev : Carbon::parse((string) $prev);

        // ساعت‌های کاملِ سپری‌شده از آخرین کسر
        $elapsed = max(1, (int) floor($prev->diffInHours(now())));

        $balance = $customer->creditBalance('IRT');
        $affordable = intdiv(max(0, $balance), $rate);

        if ($affordable < 1) {
            $this->creditOut($service, $prov);      // اعتبار برای یک ساعت هم نیست

            return false;
        }

        $hours = min($elapsed, $affordable, self::CATCHUP_CAP);
        $newMetered = $prev->copy()->addHours($hours);

        // claimِ اتمی — دو اجرا هم‌زمان نتوانند یک ساعت را دوبار کسر کنند
        $q = Service::where('id', $service->id);
        $service->last_metered_at === null
            ? $q->whereNull('last_metered_at')
            : $q->where('last_metered_at', $service->last_metered_at);

        if ($q->update(['last_metered_at' => $newMetered]) === 0) {
            return false;                            // اجرای دیگری زودتر کسر کرد
        }

        $amount = -1 * $rate * $hours;

        CreditEntry::create([
            'customer_id'   => $customer->id,
            'currency_code' => 'IRT',
            'amount'        => $amount,
            'balance_after' => $balance + $amount,
            'reason'        => 'cloud_hourly',
            'source_type'   => Service::class,
            'source_id'     => $service->id,
            'note'          => "کسرِ ساعتیِ سرورِ ابری — {$hours} ساعت × ".number_format($rate).' تومان',
        ]);

        ActivityLog::forService($service, 'renew', "کسرِ ساعتی: {$hours} ساعت", 'system');

        return true;
    }

    /** اعتبار تمام شد → طبق انتخابِ مشتری: تبدیل‌به‌ماهانه / حذف / تعلیق. */
    private function creditOut(Service $service, CloudProvisioner $prov): void
    {
        $mode = (string) ($service->on_credit_out ?: 'suspend');

        if ($mode === 'convert' && $this->tryConvertToMonthly($service)) {
            return;
        }

        if ($mode === 'terminate') {
            $prov->terminate($service);
            $service->update(['status' => 'terminated', 'cancelled_at' => now()]);
            ActivityLog::forService($service, 'terminate', 'اتمامِ اعتبارِ ساعتی → حذفِ سرور', 'system');

            return;
        }

        // پیش‌فرض: تعلیق (خاموش‌کردن) + شروعِ مهلت
        $prov->suspend($service);
        $service->update(['status' => 'suspended', 'suspended_at' => now()]);
        ActivityLog::forService($service, 'suspend', 'اتمامِ اعتبارِ ساعتی → تعلیق', 'system');
    }

    /** اگر اعتبارِ یک ماه باشد، به چرخهٔ ماهانه سوییچ کن (کسرِ یک ماه از کیف). */
    private function tryConvertToMonthly(Service $service): bool
    {
        $monthly = (int) $service->price;               // قیمتِ ماهانهٔ قفل‌شده در خرید
        $customer = $service->customer;

        if ($monthly <= 0 || $customer === null || $customer->creditBalance('IRT') < $monthly) {
            return false;
        }

        $balance = $customer->creditBalance('IRT');

        CreditEntry::create([
            'customer_id'   => $customer->id,
            'currency_code' => 'IRT',
            'amount'        => -$monthly,
            'balance_after' => $balance - $monthly,
            'reason'        => 'cloud_hourly_convert',
            'source_type'   => Service::class,
            'source_id'     => $service->id,
            'note'          => 'تبدیلِ سرورِ ساعتی به ماهانه — کسرِ یک ماه',
        ]);

        $service->update([
            'billing_mode' => 'cycle',
            'cycle'        => 'monthly',
            'next_due_at'  => now()->addMonth(),
            'status'       => 'active',
        ]);

        ActivityLog::forService($service, 'renew', 'تبدیلِ ساعتی → ماهانه (اتمامِ اعتبار)', 'system');

        return true;
    }

    /** تعلیق‌شده‌های ساعتی: شارژ کرد → روشن؛ مهلت گذشت و هنوز خالی → حذف. */
    private function handleSuspended(CloudProvisioner $prov): void
    {
        $suspended = Service::query()
            ->where('billing_mode', 'hourly')
            ->where('status', 'suspended')
            ->whereNotNull('hourly_rate_irt')
            ->with('customer')
            ->get();

        foreach ($suspended as $service) {
            $rate = (int) $service->hourly_rate_irt;
            $customer = $service->customer;

            if ($customer === null) {
                continue;
            }

            /*
            | 🔴 فقط سرویسی که **خودِ همین متر** خاموشش کرده.
            |
            | `suspended_at` را تنها این فرمان می‌نویسد؛ مسیرِ مدیر
            | (`ProvisioningService::suspend()`) فقط `status` را عوض می‌کند و
            | آن ستون را نال می‌گذارد. بی‌این شرط، مدیری که سرورِ یک مشتریِ
            | متخلف را می‌بست، ظرفِ **یک ساعت** آن را روشن می‌دید — و لاگِ
            | فعالیت هم توضیحِ دروغ می‌داد: «شارژِ مجدد → روشن‌شدن».
            |
            | یعنی محافظِ سوءاستفاده جلوی *تحویل* را می‌گرفت ولی جلوی
            | *برگشتِ* سرورِ بسته‌شده را نه.
            */
            if ($service->suspended_at === null) {
                continue;
            }

            // دوباره اعتبار دارد → روشن و ادامهٔ متر
            if ($rate > 0 && $customer->creditBalance('IRT') >= $rate) {
                $prov->unsuspend($service);
                $service->update(['status' => 'active', 'suspended_at' => null, 'last_metered_at' => now()]);
                ActivityLog::forService($service, 'reactivate', 'شارژِ مجدد → روشن‌شدنِ سرورِ ساعتی', 'system');

                continue;
            }

            // مهلت گذشت و هنوز خالی → حذف (سرورِ خاموش هم برای ما هزینه دارد)
            $since = $service->suspended_at instanceof Carbon ? $service->suspended_at : null;

            if ($since !== null && $since->diffInHours(now()) >= self::SUSPEND_GRACE_HOURS) {
                $prov->terminate($service);
                $service->update(['status' => 'terminated', 'cancelled_at' => now()]);
                ActivityLog::forService($service, 'terminate', 'پایانِ مهلتِ تعلیقِ ساعتی → حذفِ سرور', 'system');
            }
        }
    }
}
