<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Service;
use App\Services\Cloud\CloudDeliveryWatch;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
    private const SUSPEND_GRACE_HOURS = 24;

    public function handle(CloudProvisioner $prov): int
    {
        // روی سرورِ ازقبل‌مهاجرت‌نکرده بی‌صدا رد شو (نه خطا)
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'billing_mode')) {
            return self::SUCCESS;
        }

        $charged = 0;
        $stopped = 0;
        $skipped = 0;

        // ۱) سرویس‌های فعالِ ساعتی که یک ساعت از آخرین کسرشان گذشته
        $due = Service::query()
            ->where('billing_mode', 'hourly')
            ->where('status', 'active')
            ->whereNotNull('hourly_rate_irt')
            ->where('hourly_rate_irt', '>', 0)
            ->where(fn ($q) => $q->whereNull('last_metered_at')
                ->orWhere('last_metered_at', '<=', now()->subHour()))
            ->with(['customer', 'cloudInstance'])
            ->get();

        foreach ($due as $service) {
            match ($this->meterOne($service, $prov)) {
                'charged' => $charged++,
                'stopped' => $stopped++,
                default   => $skipped++,
            };
        }

        // ۲) سرویس‌های تعلیق‌شدهٔ ساعتی: اگر شارژ کردند دوباره روشن، وگرنه پس از مهلت حذف
        $this->handleSuspended($prov);

        $this->info("متر شد: {$charged} کسر، {$stopped} متوقف/تعلیق، {$skipped} بی‌هزینه (تحویل‌نشده یا کم‌تر از یک ساعت).");

        return self::SUCCESS;
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

    /**
     * اگر نرخِ قفل‌شده از بهایِ تمام‌شدهٔ **امروزِ** همان ردیف کمتر است، فریاد.
     * ردیفِ مرجع = (اسلاگِ خرید + زیرساختِ ماشینِ تحویل‌شده)؛ تحویل می‌تواند
     * روی زیرساختِ گران‌ترِ همان اسلاگ رفته باشد.
     */
    private function alarmIfUnderwater(Service $service, int $rate): void
    {
        try {
            $bought = $service->cloud_plan_id ? \App\Models\CloudPlan::find($service->cloud_plan_id) : null;

            if ($bought === null) {
                return;
            }

            $provider = $service->cloudInstance?->provider;
            $row = ($provider !== null && $provider !== $bought->provider)
                ? (\App\Models\CloudPlan::where('slug', $bought->slug)->where('provider', $provider)->orderByDesc('id')->first() ?? $bought)
                : $bought;

            $costCents = (int) $row->cost_eur_cents;

            if ($costCents <= 0) {
                return;                               // بها نداریم ⇒ ادعا هم نداریم
            }

            $this->eurTomanMemo ??= (int) app(\App\Services\Cloud\CloudPricing::class)->eurToToman();

            if ($this->eurTomanMemo <= 0) {
                return;
            }

            $floorIrt = (int) ceil(($costCents / 100) * $this->eurTomanMemo / 720);

            if ($rate < $floorIrt) {
                \App\Support\ErrorTracker::noteOnce('cloud',
                    "سرورِ ساعتیِ #{$service->id} زیرِ بهایِ تمام‌شده شارژ می‌شود: قفل‌شده "
                    .number_format($rate).' تومان/ساعت، کفِ بها '.number_format($floorIrt)
                    ." تومان/ساعت ({$row->provider}/{$row->slug}). اصلاح: php artisan cloud:hourly-reprice --service={$service->id} --apply",
                    21600);
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
    private function meterOne(Service $service, CloudProvisioner $prov): string
    {
        $rate = (int) $service->hourly_rate_irt;
        $customer = $service->customer;

        if ($rate <= 0 || $customer === null) {
            return 'skipped';
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

        $balance = $customer->creditBalance('IRT');
        $affordable = intdiv(max(0, $balance), $rate);

        if ($affordable < 1) {
            $this->creditOut($service, $prov);      // اعتبار برای یک ساعت هم نیست

            return 'stopped';
        }

        $hours = min($elapsed, $affordable, self::CATCHUP_CAP);
        $newMetered = $prev->copy()->addHours($hours);

        // claimِ اتمی — دو اجرا هم‌زمان نتوانند یک ساعت را دوبار کسر کنند.
        // ⚠️ شرط روی مقدارِ **ذخیره‌شدهٔ قدیم** است، نه روی `$prev`ِ جلوبرده‌شده.
        $q = Service::where('id', $service->id);
        $service->last_metered_at === null
            ? $q->whereNull('last_metered_at')
            : $q->where('last_metered_at', $service->last_metered_at);

        if ($q->update(['last_metered_at' => $newMetered]) === 0) {
            return 'skipped';                        // اجرای دیگری زودتر کسر کرد
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

        /*
        | لاگی که مشتری می‌بیند: به زبانِ خودش، با مبلغِ کسرشده و ماندهٔ اعتبار
        | (خواستِ صریحِ کارفرما). invoice_money داخلِ زبانِ مشتری، تومان/یورو را
        | خودش درست می‌کند.
        */
        $this->asCustomer($customer, fn () => ActivityLog::forService(
            $service, 'renew',
            __('ui.act_hourly_charge', [
                'hours'  => fa_num($hours),
                'amount' => invoice_money(abs($amount)),
                'left'   => invoice_money(max(0, $balance + $amount)),
            ]), 'system'));

        $this->warnIfCreditLow($service, $customer, $rate, $balance + $amount);

        // 🔴 آنچه در این اجرا کسر **نشد** باید ردِ مکتوب داشته باشد. سقفِ ۴۸
        // ساعته پیش از این بی‌صدا بود، و «چرا درآمدِ این ماه کم است» هیچ پاسخی
        // در هیچ لاگی نداشت.
        if ($elapsed > $hours) {
            $this->noteUnderCharge($service, $elapsed, $hours, $hours === $affordable);
        }

        return 'charged';
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
            \App\Support\ErrorTracker::noteOnce('billing', $text, 3600, ['service' => $service->id]);
        } catch (\Throwable) {
        }
    }

    /** اعتبار تمام شد → طبق انتخابِ مشتری: تبدیل‌به‌ماهانه / حذف / تعلیق. */
    private function creditOut(Service $service, CloudProvisioner $prov): void
    {
        $mode = (string) ($service->on_credit_out ?: 'suspend');

        if ($mode === 'convert' && $this->tryConvertToMonthly($service)) {
            return;
        }

        if ($mode === 'terminate') {
            $this->closeAndRelease($service, __('ui.act_hourly_creditout_del', [], $service->customer?->locale ?: 'fa'));

            return;
        }

        /*
        | پیش‌فرض: تعلیق (خاموش‌کردن) + شروعِ مهلت.
        |
        | 🔴 مقدارِ برگشتیِ `suspend()` دیگر دور ریخته نمی‌شود. ماشینی که ما فکر
        | می‌کنیم خاموش است و روشن مانده، اجارهٔ خالص است: مشتری چیزی نمی‌پردازد
        | (اعتبارش تمام شده) و ما هر ساعت می‌پردازیم. وضعیتِ محلی عمداً «تعلیق»
        | نوشته می‌شود — نمی‌خواهیم مشتریِ بی‌اعتبار به‌خاطرِ خطای ما شارژ شود —
        | ولی شکست بی‌صدا نمی‌مانَد.
        */
        $ok = $prov->suspend($service);
        $service->update(['status' => 'suspended', 'suspended_at' => now()]);
        $this->asCustomer($service->customer, fn () => ActivityLog::forService(
            $service, 'suspend', __('ui.act_hourly_suspend'), 'system'));
        $this->notifyCustomer($service, 'hourly_credit_out',
            'اعتبارِ سرویسِ ساعتیِ «'.$service->name.'» تمام شد و سرور موقتاً خاموش شد. '
            .'با شارژِ کیفِ پول، سرور خودکار روشن می‌شود؛ در غیرِ این صورت پس از مهلتِ '
            .self::SUSPEND_GRACE_HOURS.' ساعته حذف خواهد شد.',
            ['grace' => self::SUSPEND_GRACE_HOURS]);

        if (! $ok) {
            $prov->recordSuspendFailure($service);
        }
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
        $service->update(['status' => 'terminated', 'cancelled_at' => now()]);
        ActivityLog::forService($service, 'terminate', $log, 'system');

        app(ProvisioningService::class)->releaseAndTrack($service->fresh() ?? $service);
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

        $this->asCustomer($customer, fn () => ActivityLog::forService(
            $service, 'renew', __('ui.act_hourly_convert', ['amount' => invoice_money($monthly)]), 'system'));

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
                $this->asCustomer($customer, fn () => ActivityLog::forService(
                    $service, 'reactivate', __('ui.act_hourly_resume'), 'system'));

                continue;
            }

            // مهلت گذشت و هنوز خالی → حذف (سرورِ خاموش هم برای ما هزینه دارد)
            $since = $service->suspended_at instanceof Carbon ? $service->suspended_at : null;

            if ($since !== null && $since->diffInHours(now()) >= self::SUSPEND_GRACE_HOURS) {
                $this->closeAndRelease($service, __('ui.act_hourly_grace_del', [], $service->customer?->locale ?: 'fa'));
            }
        }
    }
    /**
     * آستانهٔ هشدارِ «اعتبار رو به اتمام است» — بر حسبِ ساعتِ باقی‌مانده.
     *
     * 🔴 تا امروز مترِ ساعتی **هیچ اعلانی** به مشتری نمی‌داد: نه هشداری پیش از
     * اتمام، نه خبری بعد از تعلیق. سرورِ GPU مشتری بی‌صدا می‌مرد و اولین
     * نشانه‌اش خطای برنامهٔ خودش بود (چکِ اطلاع‌رسانی — شهریور ۱۴۰۵).
     */
    private const LOW_CREDIT_HOURS = 4;

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
            app(\App\Services\Notify\CustomerNotifier::class)
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
    private function asCustomer(?\App\Models\Customer $customer, \Closure $fn): mixed
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
