<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Billing\BillingRunMonitor;
use App\Services\Billing\ComplimentaryServices;
use App\Services\Cloud\CloudDeliveryWatch;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudPricing;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Cloud\HourlyHold;
use App\Services\Cloud\HourlyCycleConverter;
use App\Services\Cloud\InterruptibleBillingClock;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use App\Services\Notify\CustomerNotifier;
use App\Services\Provisioning\ProvisioningService;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * متر کردنِ سرورهای ابریِ **ساعتی** — هر ساعت از کیفِ پولِ مشتری کم می‌کند.
 *
 * قاعده‌های پول (تأییدِ کارفرما، مرداد ۱۴۰۵ — «شارژ ساعتی باید به حق باشد»):
 *  • حداقلِ اعتبار برای شروع = `CloudPlan::HOURLY_START_MIN_HOURS` ساعت
 *    (در checkout بررسی می‌شود، نه این‌جا).
 *  • واحد = **یک ساعتِ کامل**، پیش‌پرداخت. ساعتِ اول در لحظهٔ خرید کسر می‌شود.
 *  • **بدونِ حداقلِ مصرف** و بدونِ خُردکردنِ ساعت — نه کسرِ کسری، نه بازگشتِ کسری.
 *  • 🔴 **فقط ماشینی که واقعاً تحویل شده صورت‌حساب می‌شود.** سرویسی که در پنلِ ما
 *    «done» است ولی نه IP دارد نه ایمیلش رفته، هیچ هزینه‌ای ندارد.
 *  • 🔴 **ساعت‌های انتظارِ تحویل رایگان‌اند** — لنگرِ متر روی لحظهٔ تحویل جلو
 *    می‌رود، پس سفارشی که ۵ ساعت گیر کرده بود در اولین تیک ۵ ساعت یک‌جا کسر
 *    نمی‌شود (رخدادی که کارفرما گزارش کرد).
 *  • 🔴 **لحظه‌ای که مشتری حذف را می‌خواهد، ساعت می‌ایستد** — وضعیتِ صورت‌حسابی
 *    پیش از هر تماس با زیرساخت بسته می‌شود و به نتیجهٔ آن تماس بند نیست.
 *  • 🔴 **نگهداریِ ۲۴ساعتهٔ پس از اتمامِ اعتبار از پیش ذخیره شده است**
 *    (`HourlyHold`): کسرِ ساعتی فقط از مازادِ ذخیره می‌خورد، و ساعت‌های مهلت
 *    به بهای تمام‌شدهٔ نگهداری از همان ذخیره کسر می‌شوند. زیرساختی که ماشینِ
 *    خاموش برایش هزینه ندارد نرخِ صفر دارد (رایگان، مثلِ قبل).
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
    /** عمومی چون `HourlyCycleConverter::unmeteredHours()` هم همین سقف را می‌خوانَد — یک منبع */
    public const CATCHUP_CAP = 48;

    /**
     * مهلتِ نگه‌داشتنِ سرورِ تعلیق‌شده (نبودِ اعتبار) پیش از حذف — ساعت.
     *
     * 🔴 این عدد باید همیشه با `CloudPlan::HOURLY_START_MIN_HOURS` **برابر**
     * بمانَد — تصمیمِ صریحِ کارفرما (شهریور ۱۴۰۵): «جفتشو ۲۴ ساعت، یجوری که نه
     * مشتری ضرر کنه نه ما.»
     *
     * منطقش: این مهلت ساعت‌هایی است که پس از تمام‌شدنِ اعتبار، ماشین را
     * تعلیق‌شده نگه می‌داریم و اجاره‌اش را **ما** می‌دهیم. اگر کفِ خرید از این
     * مهلت کمتر شود، مشتری کمتر از آنچه رایگان نگهش می‌داریم پرداخت کرده و
     * تفاوت از جیبِ ما می‌رود؛ اگر بیشتر شود، پولی گرفته‌ایم که بابتش سرویسی
     * نداده‌ایم. برابری تنها نقطه‌ای است که هیچ‌کدام ضرر نمی‌کنند.
     * **هر تغییرِ یکی، باید همان لحظه روی دیگری هم اعمال شود.**
     */
    private const SUSPEND_GRACE_HOURS = HourlyHold::GRACE_HOURS;

    public function handle(
        CloudProvisioner $prov,
        CloudManager $manager,
        InterruptibleBillingClock $billingClock,
    ): int {
        // روی سرورِ ازقبل‌مهاجرت‌نکرده بی‌صدا رد شو (نه خطا)
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'billing_mode')) {
            return self::SUCCESS;
        }

        $this->errors = 0;
        $this->pending = 0;
        $charged = 0;
        $stopped = 0;
        $skipped = 0;

        // یک نمونه برای کلِ اجرا — نرخِ یورو یک بار خوانده می‌شود
        $this->hold = app(HourlyHold::class);

        // ۱) سرویس‌های فعالِ ساعتی که یک ساعت از آخرین کسرشان گذشته
        $due = Service::query()
            ->whereNotIn('id', (array) config('billing.internal_service_ids', [20]))
            ->where('billing_mode', 'hourly')
            ->where('status', 'active')
            ->whereNotNull('hourly_rate_irt')
            ->where('hourly_rate_irt', '>', 0)
            ->where(fn ($q) => $q->whereNull('last_metered_at')
                ->orWhere('last_metered_at', '<=', now()->subHour()))
            ->with(['customer', 'cloudInstance', 'cloudPlan'])
            ->get();

        foreach ($due as $service) {
            try {
                match ($this->meterOne($service, $prov, $manager, $billingClock)) {
                    'charged' => $charged++,
                    'stopped' => $stopped++,
                    default => $skipped++,
                };
            } catch (\Throwable $e) {
                $this->noteFailure($service, $e);
            }
        }

        // ۲) سرویس‌های تعلیق‌شدهٔ ساعتی: اگر شارژ کردند دوباره روشن، وگرنه پس از مهلت حذف
        $this->handleSuspended($prov);

        // ۳) درخواست‌های «تبدیل به دوره‌ای» که پولشان از مسیری جز درگاه نشسته
        //    (مثلاً واریزِ دستیِ تأییدشده). مسیرِ درگاه خودش همان لحظه اجرا می‌کند.
        app(HourlyCycleConverter::class)->runPending();

        $this->info("متر شد: {$charged} کسر، {$stopped} متوقف/تعلیق، {$skipped} بی‌هزینه (تحویل‌نشده یا کم‌تر از یک ساعت).");

        BillingRunMonitor::record('cloud:meter', $this->errors, compact('charged', 'stopped', 'skipped') + ['pending' => $this->pending]);

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private int $errors = 0;

    private int $pending = 0;

    private function noteFailure(Service $service, \Throwable $e): void
    {
        $this->errors++;
        ErrorTracker::noteOnce('billing', 'Hourly lifecycle failed for service #'.$service->id.' ('.$e::class.')', 900);
    }

    /**
     * 🔴 **تنها تعریفِ «این سرویس صورت‌حساب‌شدنی است»** — و عمداً از همان
     * تعریفی می‌پرسد که ناظرهای تحویل می‌پرسند، نه از یک شرطِ دست‌نویسِ موازی.
     *
     *   `CloudInstance::isDelivered()`      ماشین هست، روشن یا خاموش، و IP دارد
     *   `CloudDeliveryWatch::reasonFor()`   شناسهٔ واقعی (نه خالی، نه `order:`) + ایمیلِ تحویل رفته
     *
     * ⚠️ هر دو لازم است. `reasonFor()` به‌تنهایی برای نمونهٔ **حذف‌شده** `null`
     * می‌دهد (عمداً — «آگاهانه پاک شده، هشدار نده») و همان تنها راهی بود که
     * ماشینِ ازقبل‌حذف‌شده باز هم صورت‌حساب می‌شد.
     *
     * ⚠️ `provision_status` این‌جا **پرسیده نمی‌شود**: دقیقاً در همان خرابی‌ای که
     * داریم می‌گیریمش مقدارش `done` است (`CloudProvisioner::finalize()` لحظهٔ
     * پذیرشِ سفارش می‌نویسدش، پیش از IP و ایمیل).
     *
     * ⚠️ توقفِ متر نباید به «سواریِ مجانی» تبدیل شود: همین ردیف‌ها از قبل در
     * `CloudDeliveryWatch::stalled()` (آستانهٔ ۲۰ دقیقه) می‌نشینند و
     * `SystemHealth::undeliveredCloud()` قرمزشان می‌کند. هشدارِ تازه لازم نیست.
     */
    private function billable(Service $service): bool
    {
        $instance = $service->cloudInstance;      // eager-loaded در پرس‌وجوی due

        if (! $instance instanceof CloudInstance || ! $instance->isDelivered()) {
            return false;
        }

        /*
        | 🔴 پلنِ **قطع‌شدنی** (GPU روی ظرفیتِ توزیع‌شده) فقط ساعتِ «روشن» را
        | می‌پردازد — این عینِ وعدهٔ صفحهٔ فروش است («فقط ساعت‌هایی که روشن
        | بوده») و عینِ صورت‌حسابِ خودِ زیرساخت به ما (مصرفی؛ ماشینِ خاموش
        | هیچ هزینه‌ای ندارد).
        |
        | ⚠️ فقط برای is_interruptible. سرورِ ابریِ معمولی عمداً «خاموش» را هم
        | می‌پردازد، چون اجارهٔ ماشینِ رزروشده را ما همچنان می‌دهیم — قاعدهٔ
        | ثبت‌شدهٔ LIVE_STATUSES دست‌نخورده می‌مانَد.
        */
        if ($instance->status !== 'running' && (bool) $service->cloudPlan?->is_interruptible) {
            return false;
        }

        return CloudDeliveryWatch::reasonFor($service) === null;
    }

    /** نرخِ یورو یک بار در هر اجرا — همان قاعدهٔ BusinessReport::rateFor. */
    private ?int $eurTomanMemo = null;

    private ?HourlyHold $hold = null;

    private function hold(): HourlyHold
    {
        return $this->hold ??= app(HourlyHold::class);
    }

    /**
     * اگر نرخِ قفل‌شده از بهایِ تمام‌شدهٔ **امروزِ** همان ردیف کمتر است، فریاد.
     * ردیفِ مرجع = (اسلاگِ خرید + زیرساختِ ماشینِ تحویل‌شده)؛ تحویل می‌تواند
     * روی زیرساختِ گران‌ترِ همان اسلاگ رفته باشد.
     */
    private function alarmIfUnderwater(Service $service, int $rate): void
    {
        try {
            $bought = $service->cloud_plan_id ? CloudPlan::find($service->cloud_plan_id) : null;

            if ($bought === null) {
                return;
            }

            $provider = $service->cloudInstance?->provider;
            $row = ($provider !== null && $provider !== $bought->provider)
                ? (CloudPlan::where('slug', $bought->slug)->where('provider', $provider)->orderByDesc('id')->first() ?? $bought)
                : $bought;

            // بهایِ ساعتیِ واقعیِ زیرساخت مقدم بر «ماهانه ÷ ۷۲۰» (درسِ sn-svc-76)
            $hourMicro = (int) ($row->cost_hour_eur_micro ?? 0);
            $costCents = (int) $row->cost_eur_cents;

            if ($hourMicro <= 0 && $costCents <= 0) {
                return;                               // بها نداریم ⇒ ادعا هم نداریم
            }

            $this->eurTomanMemo ??= (int) app(CloudPricing::class)->eurToToman();

            if ($this->eurTomanMemo <= 0) {
                return;
            }

            $floorIrt = $hourMicro > 0
                ? (int) ceil(($hourMicro / 1_000_000) * $this->eurTomanMemo)
                : (int) ceil(($costCents / 100) * $this->eurTomanMemo / 720);

            // نرخ ارز در متن تغییر می‌کند؛ هویت هشدار همان سرویس و وضعیت است.
            $underwater = $rate < $floorIrt;
            $report = ErrorTracker::throttlePassed('hourly-meter-underwater-'.$service->id,
                21600, $underwater ? 'underwater' : 'covered');

            if ($underwater && $report) {
                ErrorTracker::note('cloud',
                    "سرورِ ساعتیِ #{$service->id} زیرِ بهایِ تمام‌شده شارژ می‌شود: قفل‌شده "
                    .number_format($rate).' تومان/ساعت، کفِ بها '.number_format($floorIrt)
                    ." تومان/ساعت ({$row->provider}/{$row->slug}). اصلاح: php artisan cloud:hourly-reprice --service={$service->id} --apply",
                    ['service_id' => $service->id, 'diagnostic' => 'hourly-meter-underwater']);
            }
        } catch (\Throwable) {
            // آژیر هرگز خودِ متر را نمی‌شکند
        }
    }

    /**
     * کسرِ یک سرویس.
     *
     * @return 'charged'|'stopped'|'skipped'
     */
    private function meterOne(
        Service $service,
        CloudProvisioner $prov,
        CloudManager $manager,
        InterruptibleBillingClock $billingClock,
    ): string {
        /*
        | 🔴 فهرستِ متر یک بار اولِ اجرا خوانده می‌شود و هر سرویس بعد از تماسِ
        | شبکه‌ایِ سرویس‌های قبلی نوبت می‌گیرد. مشتری می‌تواند در همین فاصله
        | سرور را دوره‌ای کند (`HourlyCycleConverter`). با شیءِ کهنه، `syncRunning`
        | ذخیرهٔ نگهداری را دوباره روی سرویسِ دوره‌ای می‌گذاشت (پولِ قفل‌شدهٔ
        | ابدی)، کسرِ ساعتی دوباره می‌رفت و «اعتبار تمام شد» سرورِ تازه‌پرداخت‌شده
        | را خاموش می‌کرد. claim و `creditOut` هم جداگانه محافظ دارند.
        */
        $service->refresh();

        if ($service->billing_mode !== 'hourly' || $service->status !== 'active') {
            return 'skipped';
        }

        if (app(ComplimentaryServices::class)->applies($service)) {
            $service->update(['last_metered_at' => now()]);

            return 'skipped';
        }

        $rate = (int) $service->hourly_rate_irt;
        $customer = $service->customer;

        if ($rate <= 0 || $customer === null) {
            return 'skipped';
        }

        /*
        | ذخیرهٔ نگهداری با بهای **امروز** (نرخِ یورو جابه‌جا می‌شود) — پیش از
        | خواندنِ «در دسترس»، تا کسرِ این ساعت هرگز پولِ نگهداری را نخورد.
        */
        $this->hold()->syncRunning($service);

        /*
        | برای GPU قطع‌شدنی، مقدارِ محلی ممکن است چند روز کهنه باشد. پیش از پول
        | گرفتن وضعیتِ واقعی را می‌پرسیم. شکستِ API هم fail-closed است: لنگر تا
        | اکنون جلو می‌آید تا بازهٔ نامطمئن بعداً یک‌جا از مشتری کسر نشود.
        */
        if ($billingClock->applies($service)) {
            $instance = $service->cloudInstance;
            $driver = $instance ? $manager->forInstance($instance) : null;

            if ($instance === null || $driver === null || blank($instance->provider_ref)) {
                $billingClock->pauseUnverified($service);
                $this->noteUnverifiedStatus($service, 'نمونه یا شناسهٔ زیرساخت در دسترس نیست');

                return 'skipped';
            }

            try {
                $live = $driver->serverStatus((string) $instance->provider_ref);
            } catch (\Throwable $e) {
                $billingClock->pauseUnverified($service);
                $this->noteUnverifiedStatus($service, mb_substr($e->getMessage(), 0, 160));

                return 'skipped';
            }

            if (! ($live['ok'] ?? false) || ! in_array($live['status'] ?? null, ['running', 'off', 'building', 'error', 'deleted'], true)) {
                $billingClock->pauseUnverified($service);
                $this->noteUnverifiedStatus($service);

                return 'skipped';
            }

            $clockRestarted = $billingClock->record($service, $instance, (string) $live['status'], [
                'ipv4' => $live['ipv4'] ?: $instance->ipv4,
                'ipv6' => $live['ipv6'] ?: $instance->ipv6,
                'hostname' => filled($live['hostname'] ?? null) ? $live['hostname'] : $instance->hostname,
                'synced_at' => now(),
                'last_error' => null,
            ]);

            // record() روی خاموشی و شروعِ تازه لنگر را «اکنون» می‌گذارد.
            if ($live['status'] !== 'running' || $clockRestarted) {
                return 'skipped';
            }
        }

        // 🔴 تحویل‌نشده = بی‌هزینه. هیچ کسری، هیچ نوشتنی، لنگر دست‌نخورده.
        if (! $this->billable($service)) {
            return 'skipped';
        }

        /*
        | 🔴 آژیرِ «زیرِ بها» — درسِ sn-svc-76 (۶ شهریور ۱۴۰۵).
        |
        | نرخ در لحظهٔ خرید قفل می‌شود؛ اگر آن روز کاتالوگ خراب بوده یا بهایِ
        | زیرساخت بعداً بالا رفته باشد، هر ساعتِ روشن ضررِ نقد است و تا امروز
        | **هیچ‌جا دیده نمی‌شد** — کارفرما اتفاقی در پنلِ زیرساخت دید.
        | کسر عوض نمی‌شود (تصمیمِ مالی = cloud:hourly-reprice به دستِ مدیر)؛
        | فقط فریادِ پایدار در /admin/errors. گلوگاه ۶ساعته و امضا شاملِ شناسه.
        */
        $this->alarmIfUnderwater($service, $rate);

        $prev = $service->last_metered_at ?? $service->activated_at ?? $service->created_at;
        $prev = $prev instanceof Carbon ? $prev : Carbon::parse((string) $prev);

        /*
        | 🔴 لنگر را روی **لحظهٔ تحویل** جلو می‌بریم.
        |
        | `last_metered_at` در لحظهٔ خرید نوشته می‌شود و `finalize()` هرگز
        | بازنشانی‌اش نمی‌کند؛ پس سفارشی که ۵ ساعت گیر کرده بود، در اولین تیکِ
        | بعد از تحویل ۵ ساعت **یک‌جا** کسر می‌شد — بابتِ ماشینی که مشتری اصلاً
        | ندیده بود. `max()` فقط می‌تواند لنگر را **جلو** ببرد، پس این قاعده
        | هرگز نمی‌تواند اضافه‌کسر کند؛ فقط می‌تواند ببخشد. جهتِ درستِ پیش‌فرض
        | برای پول.
        |
        | `ready_notified_at` هر وقت `billable()` درست باشد نال نیست
        | (`reasonFor()` نبودِ ایمیلِ تحویل را خودش رد می‌کند) و مهاجرتِ
        | 2026_09_21_000101 ردیف‌های قدیمی را هم پر کرده است.
        */
        $ready = $service->cloudInstance?->ready_notified_at;

        if ($ready instanceof Carbon && $ready->gt($prev)) {
            $prev = $ready->copy();
        }

        /*
        | ساعت‌های **کاملِ** سپری‌شده. کف‌گیرِ `max(1, …)`ِ قبلی حذف شد: به هر
        | ردیفی با `last_metered_at`ِ نال یک ساعتِ کامل می‌بست بی‌آنکه سنِ
        | واقعی‌اش را بپرسد، و می‌توانست ساعتی را که هنوز نگذشته کسر کند.
        */
        $elapsed = (int) floor($prev->diffInHours(now()));

        if ($elapsed < 1) {
            return 'skipped';                        // هنوز یک ساعتِ کامل نشده
        }

        /*
        | 🔴 از M3-correct، متر از «در دسترس» حساب می‌کند (منهایِ رزروهایِ
        | زندهٔ AI) و کسر از `Wallet` می‌گذرد. کلِ «قفلِ مشتری → claim →
        | کسر» داخلِ یک تراکنش است تا ردِ Wallet کلِ claim را هم با خودش
        | برگرداند — لنگر بدونِ پول جلو نمی‌رود. ترتیبِ قفل: مشتری اول
        | (همانندِ payCredit)، بعدِ claim خودِ سرویس — یکه و بدونِ چرخه.
        */
        $wallet = app(Wallet::class);

        /*
        | کد نتیجه: ۰ = اعتبار نیست (stopped) · -۱ = claim را دیگری برد
        *  (skipped) · >۰ = ساعت‌های کسرشده (charged).
        */
        $charged = null;
        $creditBound = false;

        try {
            DB::transaction(function () use ($wallet, $customer, $service, $rate, $elapsed, $prev, &$charged, &$creditBound): void {
                Customer::whereKey($customer->id)->lockForUpdate()->first();

                $available = $wallet->availableOf($customer->id);
                $affordable = intdiv(max(0, $available), $rate);

                if ($affordable < 1) {
                    $charged = 0;                     // بیرونِ تراکنش creditOut می‌زنیم

                    return;
                }

                $hours = min($elapsed, $affordable, self::CATCHUP_CAP);
                $creditBound = $hours === $affordable;
                $newMetered = $prev->copy()->addHours($hours);

                // claimِ اتمی — دو اجرا هم‌زمان نتوانند یک ساعت را دوبار کسر کنند.
                // ⚠️ شرط روی مقدارِ **ذخیره‌شدهٔ قدیم** است، نه روی `$prev`ِ جلوبرده‌شده.
                // 🔴 و روی `billing_mode`: فهرستِ متر یک بار اولِ اجرا خوانده
                //    می‌شود؛ اگر مشتری در این فاصله سرور را دوره‌ای کرده باشد
                //    (`HourlyCycleConverter`)، `last_metered_at` عوض نشده و claim
                //    بی‌این شرط می‌گرفت ⇒ کلِ دوره **و** ساعت‌ها کسر می‌شد.
                $q = Service::where('id', $service->id)->where('billing_mode', 'hourly');
                $service->last_metered_at === null
                    ? $q->whereNull('last_metered_at')
                    : $q->where('last_metered_at', $service->last_metered_at);

                if ($q->update(['last_metered_at' => $newMetered]) === 0) {
                    $charged = -1;                    // اجرای دیگری زودتر کسر کرد

                    return;
                }

                $wallet->debit($customer->id, 'IRT', $rate * $hours, 'cloud_hourly', $service,
                    "کسرِ ساعتیِ سرورِ ابری — {$hours} ساعت × ".number_format($rate).' تومان');

                $charged = $hours;
            });
        } catch (WalletException) {
            // گاردِ در دسترس ردش کرد — مثلِ «اعتبار نیست» رفتار می‌کنیم:
            // claim هم برگشته (همان تراکنش)، لنگر دست‌نخورده.
            $charged = 0;
        }

        if ($charged === 0) {
            $this->creditOut($service, $prov);      // اعتبار برای یک ساعت هم نیست

            return 'stopped';
        }

        if ($charged === -1) {
            return 'skipped';
        }

        $hours = $charged;
        $amount = -1 * $rate * $hours;

        $balance = $customer->creditBalance('IRT');   // فقط برای پیام — حقیقت در Wallet بود

        /*
        | لاگی که مشتری می‌بیند: به زبانِ خودش، با مبلغِ کسرشده و ماندهٔ اعتبار
        | (خواستِ صریحِ کارفرما). invoice_money داخلِ زبانِ مشتری، تومان/یورو را
        | خودش درست می‌کند.
        */
        $this->asCustomer($customer, fn () => ActivityLog::forService(
            $service, 'renew',
            __('ui.act_hourly_charge', [
                'hours' => fa_num($hours),
                'amount' => invoice_money(abs($amount)),
                'left' => invoice_money(max(0, $balance)),
            ]), 'system'));

        // ساعتِ باقی از «در دسترس» — ذخیرهٔ نگهداری خرجِ کار نمی‌شود، پس
        // هشدار باید پیش از آن‌که واقعاً سرور بایستد برسد، نه پس از آن.
        $this->warnIfCreditLow($service, $customer, $rate, $wallet->availableOf($customer->id));

        // 🔴 آنچه در این اجرا کسر **نشد** باید ردِ مکتوب داشته باشد. سقفِ ۴۸
        // ساعته پیش از این بی‌صدا بود، و «چرا درآمدِ این ماه کم است» هیچ پاسخی
        // در هیچ لاگی نداشت.
        if ($elapsed > $hours) {
            $this->noteUnderCharge($service, $elapsed, $hours, $creditBound);
        }

        return 'charged';
    }

    private function noteUnverifiedStatus(Service $service, ?string $detail = null): void
    {
        try {
            $suffix = filled($detail) ? ': '.$detail : '.';
            ErrorTracker::noteOnce('billing',
                "وضعیتِ زندهٔ GPU سرویس #{$service->id} تأیید نشد؛ کسر متوقف شد{$suffix}",
                3600, ['service' => $service->id]);
        } catch (\Throwable) {
            // خرابیِ کانالِ هشدار هرگز محافظِ پول را از کار نمی‌اندازد.
        }
    }

    /** ثبتِ ساعت‌هایی که در این اجرا کسر نشد — سقفِ جبران یا کمبودِ اعتبار. */
    private function noteUnderCharge(Service $service, int $elapsed, int $hours, bool $creditBound): void
    {
        $missed = $elapsed - $hours;
        $why = $creditBound ? 'کمبودِ اعتبار' : 'سقفِ جبرانِ '.self::CATCHUP_CAP.' ساعت';

        $text = "مترِ ساعتی: از {$elapsed} ساعتِ سپری‌شده فقط {$hours} ساعت کسر شد؛ "
            ."{$missed} ساعت در این اجرا کسر نشد ({$why}).";

        try {
            ActivityLog::forService($service, 'renew', $text, 'system');
        } catch (\Throwable) {
        }

        try {
            ErrorTracker::noteOnce('billing', $text, 3600, ['service' => $service->id]);
        } catch (\Throwable) {
        }
    }

    /** اعتبار تمام شد → طبق انتخابِ مشتری: تبدیل‌به‌ماهانه / حذف / تعلیق. */
    private function creditOut(Service $service, CloudProvisioner $prov): void
    {
        if ($service->isInternalInfrastructure()) {
            return;
        }

        /*
        | 🔴 شیءِ `$service` از فهرستِ اولِ اجراست و ممکن است کهنه باشد: اگر مشتری
        | در این فاصله سرور را دوره‌ای کرده، کسرِ همان تبدیل کیف را خالی کرده و
        | «اعتبار تمام شد» دروغ است. بی‌این بازخوانی، سرورِ تازه‌پرداخت‌شده تعلیق
        | یا حتی (on_credit_out=terminate) **حذف** می‌شد.
        */
        $service = $service->fresh(['customer', 'cloudInstance', 'cloudPlan']);

        if ($service === null || $service->billing_mode !== 'hourly' || $service->status !== 'active') {
            return;
        }
        $mode = (string) ($service->on_credit_out ?: 'suspend');

        if ($mode === 'convert' && $this->tryConvertToMonthly($service)) {
            return;
        }

        if ($mode === 'terminate') {
            $this->closeAndRelease($service, __('ui.act_hourly_creditout_del', [], $service->customer?->locale ?: 'fa'));

            return;
        }

        // Stop billing immediately; start retention only after a verified shutdown.
        $service->update(['status' => 'suspended', 'suspended_at' => null, 'last_metered_at' => now()]);
        $this->confirmHourlySuspension($service, $prov);
    }

    private function confirmHourlySuspension(Service $service, CloudProvisioner $prov): bool
    {
        $state = (array) ($service->provision_meta['hourly_suspend'] ?? []);
        if (! empty($state['retry_at']) && Carbon::parse($state['retry_at'])->isFuture()) {
            $this->pending++;

            return false;
        }
        try {
            $ok = $prov->suspend($service);
        } catch (\Throwable $e) {
            $ok = false;
        }
        $meta = (array) $service->fresh()->provision_meta;
        $meta['hourly_suspend'] = [
            'attempted_at' => now()->toIso8601String(),
            'confirmed_at' => $ok ? now()->toIso8601String() : null,
            'retry_at' => $ok ? null : now()->addMinutes(15)->toIso8601String(),
        ];
        $service->forceFill([
            'provision_meta' => $meta,
            'suspended_at' => $ok ? now() : null,
            'last_metered_at' => now(),
        ])->save();
        if (! $ok) {
            if ($prov->lifecyclePending($service, 'off')) {
                $this->pending++;

                return false;
            }
            $this->errors++;
            $prov->recordSuspendFailure($service);

            return false;
        }
        $this->asCustomer($service->customer, fn () => ActivityLog::forService(
            $service, 'suspend', __('ui.act_hourly_suspend'), 'system'));
        $holdRate = HourlyHold::enabled() ? (int) $service->hold_rate_irt : 0;
        $this->notifyCustomer($service, 'hourly_credit_out',
            'اعتبار سرویس ساعتی «'.$service->name.'» تمام شد و خاموش‌شدن تأیید شد. '
            .'با شارژ کیف پول دوباره فعال می‌شود. مهلت نگهداری '.self::SUSPEND_GRACE_HOURS.' ساعت است'
            .($holdRate > 0 ? ' (هزینهٔ نگهداری از اعتبار ذخیره‌شده کسر می‌شود)' : '')
            .'؛ پس از آن سرور و داده‌هایش حذف می‌شوند.',
            ['grace' => self::SUSPEND_GRACE_HOURS]);

        return true;
    }

    /**
     * 🔴 بستنِ صورت‌حساب **پیش از** تماس با زیرساخت، و بعد آزادسازی.
     *
     * ترتیب عمدی است: `status`ِ مرده تنها چیزی است که هم‌زمان مترِ ساعتی،
     * `provision:run`، `PaymentService::applyPaid` و دکمهٔ «تلاشِ دوباره»ی مدیر
     * را می‌بندد. اگر منتظرِ نتیجهٔ زیرساخت بمانیم، حذفِ ناموفق یعنی مشتری
     * ساعت‌ها بابتِ سروری که خواسته پاک شود پول می‌دهد.
     */
    private function closeAndRelease(Service $service, string $log): void
    {
        if ($service->isInternalInfrastructure()) {
            return;
        }
        $service->update(['status' => 'terminated', 'cancelled_at' => now()]);
        ActivityLog::forService($service, 'terminate', $log, 'system');

        app(ProvisioningService::class)->releaseAndTrack($service->fresh() ?? $service);
    }

    /**
     * اگر اعتبارِ یک ماه باشد، به چرخهٔ ماهانه سوییچ کن (کسرِ یک ماه از کیف).
     *
     * 🔴 کارِ واقعی در `HourlyCycleConverter::convert()` است — همان تعریفی که دکمهٔ
     *    «تبدیل به دوره‌ای»ِ مشتری صدا می‌زند. دو پیاده‌سازی یعنی روزی دو قیمت یا
     *    دو سررسید برای یک کار.
     *
     * قیمت: **امروزِ** پلن (تصمیمِ کارفرما، مهر ۱۴۰۵). اگر پلن یا نرخ نباشد،
     * همان قیمتِ قفل‌شدهٔ خرید — رفتارِ پیشین — تا نبودِ نرخ، سرور را به تعلیق نبرد.
     * ⚠️ گیتِ زیرساخت این‌جا **عمداً نیست**: این انتخابِ لحظهٔ خریدِ خودِ مشتری است
     *    و پیش از این قابلیت هم روی همهٔ زیرساخت‌ها کار می‌کرد.
     */
    private function tryConvertToMonthly(Service $service): bool
    {
        $converter = app(HourlyCycleConverter::class);

        $monthly = $service->isCloud() ? $converter->quote($service, 'monthly') : 0;

        if ($monthly <= 0) {
            $monthly = (int) $service->price;           // قیمتِ ماهانهٔ قفل‌شده در خرید
        }

        $customer = $service->customer;

        if ($monthly <= 0 || $customer === null) {
            return false;
        }

        // settleHours=false: این مسیر درست بعد از آن صدا زده می‌شود که خودِ متر
        // نتوانست همان ساعت‌ها را کسر کند — رفتارِ پیشینِ تبدیلِ خودکار دست‌نخورده.
        return $this->asCustomer($customer, fn () => $converter->convert(
            $service, 'monthly', $monthly, 'system',
            __('ui.act_hourly_convert', ['amount' => invoice_money($monthly)]),
            false,
        ));
    }

    /** تعلیق‌شده‌های ساعتی: شارژ کرد → روشن؛ مهلت گذشت و هنوز خالی → حذف. */
    private function handleSuspended(CloudProvisioner $prov): void
    {
        $suspended = Service::query()
            ->whereNotIn('id', (array) config('billing.internal_service_ids', [20]))
            ->where('billing_mode', 'hourly')
            ->where('status', 'suspended')
            ->whereNotNull('hourly_rate_irt')
            ->with('customer')
            ->get();

        foreach ($suspended as $service) {
            try {
                // No wallet-driven resume, suspension or deletion for the owner.
                if (app(ComplimentaryServices::class)->applies($service)) {
                    continue;
                }
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
                $state = (array) ($service->provision_meta['hourly_suspend'] ?? []);
                if ($service->suspended_at === null && $state === []) {
                    continue; // Staff suspension: never resume automatically.
                }
                if (empty($state['confirmed_at'])) {
                    if (! $this->confirmHourlySuspension($service, $prov)) {
                        continue;
                    }
                }

                // ساعت‌های نگهداریِ سپری‌شده — از ذخیرهٔ خودِ همین سرور
                $this->chargeHold($service);

                // دوباره اعتبار دارد → روشن و ادامهٔ متر
                if ($rate > 0 && $this->resume($service, $prov)) {
                    continue;
                }

                $fresh = $service->fresh();
                if ($fresh === null || $fresh->status !== 'suspended') {
                    continue;
                }
                $service = $fresh;

                // An eligible customer must not lose the server merely because power-on failed.
                if ($rate > 0) {
                    $holdRate = $this->hold()->rateIrt($service);
                    $fullReserve = HourlyHold::enabled()
                        ? HourlyHold::fullReserve($holdRate, $service->on_credit_out) : 0;
                    $ownReserve = HourlyHold::enabled() ? (int) $service->hold_reserve_irt : 0;
                    $available = app(Wallet::class)->availableOf($customer->id);
                    if ($available + $ownReserve - $fullReserve >= $rate) {
                        continue;
                    }
                }

                // مهلت گذشت و هنوز خالی → حذف (سرورِ خاموش هم برای ما هزینه دارد)
                $since = $service->suspended_at instanceof Carbon ? $service->suspended_at : null;

                if ($since !== null && $since->diffInHours(now()) >= self::SUSPEND_GRACE_HOURS) {
                    $this->closeAndRelease($service, __('ui.act_hourly_grace_del', [], $service->customer?->locale ?: 'fa'));
                }
            } catch (\Throwable $e) {
                $this->noteFailure($service, $e);
            }
        }
    }

    /**
     * روشن‌کردنِ دوبارهٔ سرورِ تعلیق‌شده پس از شارژ.
     *
     * 🔴 شرط «اعتبارِ یک ساعت» روی مازادِ **ذخیرهٔ کاملِ نگهداریِ این سرور**
     * است، نه روی جمعِ خام؛ وگرنه ساعتِ بعد دوباره خاموش می‌شد و ذخیره‌ای
     * برای نگهداریِ بعدی نمی‌ماند.
     *
     * 🔴 ساعتِ اولِ پس از روشن‌شدن **پیش‌پرداخت** است — عینِ لحظهٔ خرید. پیش‌تر
     * لنگر فقط «اکنون» می‌شد و کسر یک ساعت بعد می‌آمد؛ یعنی اگر اعتبار دوباره
     * تمام می‌شد، آخرین ساعتِ کارکرد هرگز پرداخت نمی‌شد.
     */
    private function resume(Service $service, CloudProvisioner $prov): bool
    {
        $rate = (int) $service->hourly_rate_irt;
        $customer = $service->customer;
        $wallet = app(Wallet::class);

        $holdRate = $this->hold()->rateIrt($service);
        $fullReserve = HourlyHold::enabled() ? HourlyHold::fullReserve($holdRate, $service->on_credit_out) : 0;
        $ownReserve = HourlyHold::enabled() ? (int) $service->hold_reserve_irt : 0;

        if ($wallet->availableOf($customer->id) + $ownReserve - $fullReserve < $rate) {
            return false;
        }

        $powered = false;
        $resumed = false;

        try {
            DB::transaction(function () use ($wallet, $customer, $service, $rate, $holdRate, $fullReserve, $ownReserve, $prov, &$powered, &$resumed): void {
                Customer::whereKey($customer->id)->lockForUpdate()->first();
                $current = Service::whereKey($service->id)->lockForUpdate()->first();

                if ($current?->status !== 'suspended'
                    || $wallet->availableOf($customer->id) + $ownReserve - $fullReserve < $rate) {
                    return;
                }

                // Provider acceptance must precede the first-hour debit and local activation.
                if (! $prov->unsuspend($service)) {
                    if ($prov->lifecyclePending($service, 'running')) {
                        $this->pending++;

                        return;
                    }
                    $this->errors++;
                    ErrorTracker::noteOnce('billing', 'فعال‌سازی مجدد سرویس ساعتی #'.$service->id.' تأیید نشد؛ هزینه ساعت شروع کسر نشد.', 3600);

                    return;
                }
                $powered = true;

                $meta = (array) $service->provision_meta;
                unset($meta['hourly_suspend']);
                $service->update([
                    'provision_meta' => $meta,
                    'status' => 'active', 'suspended_at' => null, 'last_metered_at' => now(),
                ] + (HourlyHold::enabled() ? ['hold_rate_irt' => $holdRate, 'hold_reserve_irt' => $fullReserve] : []));

                $wallet->debit($customer->id, 'IRT', $rate, 'cloud_hourly', $service,
                    'روشن‌شدنِ دوبارهٔ سرورِ ساعتی — ساعتِ اول');
                $resumed = true;
            });
        } catch (\Throwable $e) {
            // If the charge failed after power-on, restore the provider state.
            if ($powered && ! $prov->suspend($service) && ! $prov->lifecyclePending($service, 'off')) {
                $prov->recordSuspendFailure($service);
            }
            $service->refresh();

            if ($e instanceof WalletException) {
                return false;
            }

            throw $e;
        }

        if (! $resumed) {
            $service->refresh();

            return false;
        }

        $this->asCustomer($customer, fn () => ActivityLog::forService(
            $service, 'reactivate', __('ui.act_hourly_resume'), 'system'));

        return true;
    }

    /**
     * کسرِ ساعت‌های نگهداریِ سپری‌شده از ذخیرهٔ همین سرور.
     *
     * 🔴 کاهشِ ذخیره و کسر در **یک تراکنش** و با claimِ اتمی روی لنگر: دو اجرا
     * یک ساعت را دوبار کسر نمی‌کنند و ذخیره هرگز بی‌کسر (یا کسر بی‌ذخیره)
     * جابه‌جا نمی‌شود. کسر هرگز از ساعت‌های باقیِ ذخیره بیشتر نیست، پس سقفش
     * خودبه‌خود همان ۲۴ ساعت است.
     */
    private function chargeHold(Service $service): void
    {
        $rate = HourlyHold::enabled() ? (int) $service->hold_rate_irt : 0;
        $since = $service->suspended_at;

        if ($rate <= 0 || ! $since instanceof Carbon || $service->customer === null) {
            return;
        }

        $stored = $service->last_metered_at;
        $anchor = $stored instanceof Carbon && $stored->gt($since) ? $stored->copy() : $since->copy();

        $due = (int) floor($anchor->diffInHours(now()));
        $hours = min($due, HourlyHold::hoursRemaining($service));

        if ($hours < 1) {
            return;
        }

        $wallet = app(Wallet::class);
        $customerId = $service->customer->id;
        $short = 0;

        try {
            DB::transaction(function () use ($wallet, $service, $customerId, $rate, $hours, $anchor, $stored, &$short): void {
                Customer::whereKey($customerId)->lockForUpdate()->first();

                $q = Service::where('id', $service->id)->where('status', 'suspended');
                $stored === null ? $q->whereNull('last_metered_at') : $q->where('last_metered_at', $stored);

                $claimed = $q->update([
                    'last_metered_at' => $anchor->copy()->addHours($hours),
                    'hold_reserve_irt' => max(0, (int) $service->hold_reserve_irt - $hours * $rate),
                ]);

                if ($claimed === 0) {
                    return;                                   // اجرای دیگری زودتر برد
                }

                /*
                | ذخیره همین حالا کم شد، پس «در دسترس» دقیقاً همان‌قدر بالا رفته.
                | اگر دفتر از بیرونِ Wallet خالی شده باشد (تنظیمِ دستی)، فقط
                | ساعت‌های قابلِ پرداخت کسر می‌شوند و کمبود فریاد می‌زند.
                */
                $payable = min($hours, intdiv(max(0, $wallet->availableOf($customerId)), $rate));
                $short = $hours - $payable;

                if ($payable > 0) {
                    $wallet->debit($customerId, 'IRT', $payable * $rate, 'cloud_hourly_hold', $service,
                        "نگهداریِ سرورِ ساعتیِ خاموش — {$payable} ساعت × ".number_format($rate).' تومان');
                }
            });
        } catch (WalletException) {
            return;
        }

        $service->refresh();

        if ($short > 0) {
            ErrorTracker::noteOnce('billing',
                "نگهداریِ سرورِ ساعتیِ #{$service->id}: {$short} ساعت کسر نشد (ذخیره در دفتر نبود).",
                3600, ['service' => $service->id]);
        }
    }

    /**
     * آستانهٔ هشدارِ «اعتبار رو به اتمام است» — بر حسبِ ساعتِ باقی‌مانده.
     *
     * 🔴 تا امروز مترِ ساعتی **هیچ اعلانی** به مشتری نمی‌داد: نه هشداری پیش از
     * اتمام، نه خبری بعد از تعلیق. سرورِ GPU مشتری بی‌صدا می‌مرد و اولین
     * نشانه‌اش خطای برنامهٔ خودش بود (چکِ اطلاع‌رسانی — شهریور ۱۴۰۵).
     */
    public const LOW_CREDIT_HOURS = 4;

    private function warnIfCreditLow(Service $service, $customer, int $rate, int $balanceAfter): void
    {
        if ($rate < 1) {
            return;
        }

        $hoursLeft = intdiv(max(0, $balanceAfter), $rate);

        $meta = (array) ($service->provision_meta ?? []);

        if ($hoursLeft >= self::LOW_CREDIT_HOURS * 2) {
            // اعتبار برگشت بالا — هشدارِ بعدی دوباره مجاز شود
            if (isset($meta['low_credit_warned_at'])) {
                unset($meta['low_credit_warned_at']);
                $service->forceFill(['provision_meta' => $meta])->save();
            }

            return;
        }

        if ($hoursLeft >= self::LOW_CREDIT_HOURS) {
            return;
        }

        // یک بار به‌ازای هر افتِ اعتبار، نه هر ساعت — ۹۶ پیامِ تکراری در روز
        // همان «توهمِ پایش»ِ ثبت‌شده است.
        if (isset($meta['low_credit_warned_at'])) {
            return;
        }

        $meta['low_credit_warned_at'] = now()->toIso8601String();
        $service->forceFill(['provision_meta' => $meta])->save();

        $this->notifyCustomer($service, 'hourly_low_credit',
            'اعتبارِ سرویسِ ساعتیِ «'.$service->name.'» فقط برای حدودِ '
            .$hoursLeft.' ساعتِ دیگر کافی است. برای جلوگیری از خاموش‌شدن، کیفِ پول را شارژ کنید.',
            ['hours' => $hoursLeft]);
    }

    /** اعلان به زبانِ خودِ مشتری؛ شکستِ اعلان هرگز متر را نمی‌شکند. */
    private function notifyCustomer(Service $service, string $key, string $text, array $vars = []): void
    {
        try {
            $customer = $service->customer;

            if ($customer === null) {
                return;
            }

            app()->setLocale($customer->locale ?: 'fa');
            app(CustomerNotifier::class)
                ->templated($customer, $key, ['service' => $service->name] + $vars, $text);
        } catch (\Throwable) {
            // اعلان تزئینِ متر است، نه شرطِ آن
        } finally {
            app()->setLocale(config('app.locale'));
        }
    }

    /**
     * اجرای یک بلوک با زبانِ خودِ مشتری — تا __() و invoice_money هر دو به
     * زبان/ارزِ درست دربیایند. لاگِ فعالیت و یادداشتِ دفترِ اعتبار را **مشتری**
     * می‌خوانَد (گزارشِ کارفرما، ۶ شهریور: «کسر ساعتی ۱ ساعت» فارسی روی حسابِ
     * انگلیسی)، پس زبانِ نوشتنشان زبانِ اوست، نه زبانِ کرون.
     */
    private function asCustomer(?Customer $customer, \Closure $fn): mixed
    {
        $prev = app()->getLocale();
        app()->setLocale($customer?->locale ?: 'fa');

        try {
            return $fn();
        } finally {
            app()->setLocale($prev);
        }
    }
}
