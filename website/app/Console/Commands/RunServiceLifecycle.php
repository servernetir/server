<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Service;
use App\Services\Notify\AdminNotifier;
use App\Services\Notify\CustomerNotifier;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * چرخهٔ کاملِ تمدیدِ سرویس — یادآوری، تعلیقِ خودکار، مهلتِ ۳۰ روزه.
 *
 * روزی یک‌بار اجرا می‌شود و برای **هر** سرویسِ دوره‌ای (هاست، دامنه، سرورِ
 * اختصاصی، هر چیزی که فاکتورِ دوره‌ای دارد) این مسیر را می‌رود:
 *
 *   ۷ روز مانده  → یادآوری + مطمئن‌شدن از وجودِ فاکتورِ تمدید
 *   ۳ روز مانده  → یادآوری
 *   ۱ روز مانده  → یادآوریِ آخر
 *   سررسید گذشت و پرداخت نشد → **تعلیقِ خودکار** + اعلان به مشتری و مدیر
 *   ۳۰ روز پس از تعلیق → به مدیر می‌گوییم «تصمیم بگیر» (terminate دستیِ خودش)
 *   پرداخت شد          → رفعِ تعلیق روی سرور + صفرکردنِ شمارندهٔ یادآوری
 *
 * ═══ قواعدِ طراحی ═══
 *
 * • **idempotent**: ستونِ reminder_stage جلوی پیامِ تکراری را می‌گیرد. اگر کرون
 *   روزی چند بار اجرا شود، مشتری چند بار «۳ روز مانده» نمی‌گیرد.
 * • terminate **هرگز خودکار نیست**. داده و سایتِ مشتری پاک‌کردنی نیست؛ فقط
 *   مدیر تصمیم می‌گیرد. ما تعلیق می‌کنیم (برگشت‌پذیر) و اطلاع می‌دهیم.
 * • خطای یک سرویس نباید بقیه را زمین بزند: هر سرویس در try/catch خودش است.
 * • تماسِ شبکه‌ای (تعلیق روی WHM) بیرونِ هر تراکنش است.
 */
class RunServiceLifecycle extends Command
{
    protected $signature = 'services:lifecycle
        {--dry : فقط گزارش بده، هیچ‌چیز را عوض نکن}
        {--grace=30 : چند روز پس از تعلیق به مدیر بگوییم تصمیم بگیرد}';

    protected $description = 'یادآوریِ تمدید، تعلیقِ خودکارِ سرویسِ پرداخت‌نشده و اعلانِ مهلتِ پایان‌یافته';

    /** مراحلِ یادآوری — از دور به نزدیک */
    private const STAGES = [7, 3, 1];

    public function handle(
        CustomerNotifier $customers,
        AdminNotifier $admin,
        ProvisioningService $provisioner,
    ): int {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'reminder_stage')) {
            $this->warn('ستون‌های چرخهٔ تمدید ساخته نشده‌اند؛ اول مهاجرت را اجرا کنید.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');
        $graceDays = max(1, (int) $this->option('grace'));

        $cycles = array_keys((array) config('billing.cycles', []));
        $today = now()->startOfDay();

        $services = Service::query()
            ->whereIn('cycle', $cycles)
            ->whereIn('status', ['active', 'suspended'])
            ->whereNotNull('next_due_at')
            ->with('customer')
            ->get();

        $stats = ['reminded' => 0, 'suspended' => 0, 'restored' => 0, 'grace' => 0];

        foreach ($services as $service) {
            try {
                $this->handleOne($service, $today, $graceDays, $dry, $stats, $customers, $admin, $provisioner);
            } catch (\Throwable $e) {
                // یک سرویسِ خراب نباید کلِ کرون را بخواباند
                Log::error('چرخهٔ تمدیدِ سرویس خطا داد', [
                    'service' => $service->id,
                    'error'   => $e::class.': '.mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        $this->info(sprintf(
            'یادآوری: %d · تعلیق: %d · رفعِ تعلیق: %d · اعلانِ مهلت: %d%s',
            $stats['reminded'], $stats['suspended'], $stats['restored'], $stats['grace'],
            $dry ? '  (آزمایشی — چیزی عوض نشد)' : ''
        ));

        return self::SUCCESS;
    }

    private function handleOne(
        Service $service,
        \Illuminate\Support\Carbon $today,
        int $graceDays,
        bool $dry,
        array &$stats,
        CustomerNotifier $customers,
        AdminNotifier $admin,
        ProvisioningService $provisioner,
    ): void {
        $due = $service->next_due_at->copy()->startOfDay();
        $daysLeft = $today->diffInDays($due, false);   // منفی = گذشته
        $unpaid = $this->openInvoice($service);

        // ── ۱) پرداخت شد و سرویس معلق بود → برگردانش ────────────────────────
        if ($unpaid === null && $service->suspended_at !== null && $daysLeft >= 0) {
            if (! $dry) {
                $provisioner->unsuspend($service);
                $service->forceFill([
                    'status'         => 'active',
                    'suspended_at'   => null,
                    'grace_alert_at' => null,
                    'reminder_stage' => null,
                ])->save();

                $customers->templated($service->customer, 'reactivated',
                    ['service' => $service->name],
                    'سرویسِ «'.$service->name.'» شما تمدید شد و دوباره فعال است. ممنون از پرداختتان.');

                \App\Models\ActivityLog::forService($service, 'reactivate',
                    __('ui.act_auto_unsuspend', [], $service->customer?->locale ?: 'fa'), 'system');
            }
            $stats['restored']++;

            return;
        }

        // ── ۲) سررسید نگذشته → یادآوریِ ۷/۳/۱ روز ───────────────────────────
        if ($daysLeft >= 0) {
            $stage = $this->stageFor($daysLeft);

            if ($stage === null) {
                return;
            }

            // فقط اگر این مرحله (یا نزدیک‌ترش) قبلاً نرفته باشد
            $sent = $service->reminder_stage;
            if ($sent !== null && (int) $sent <= $stage) {
                return;
            }

            if (! $dry) {
                // ۷ روز مانده: مطمئن شو فاکتورِ تمدید هست (اگر نبود، بساز)
                if ($stage === 7 && $unpaid === null) {
                    app(\App\Http\Controllers\Admin\ServiceController::class)->issueInvoice($service);
                    $unpaid = $this->openInvoice($service);
                }

                $this->remindCustomer($service, $unpaid, $stage, $customers);

                $service->forceFill(['reminder_stage' => $stage])->save();
            }

            $stats['reminded']++;

            return;
        }

        // ── ۳) سررسید گذشته ────────────────────────────────────────────────
        $daysOverdue = abs($daysLeft);

        // پرداخت‌شده ولی سررسید عقب مانده؟ کارِ ما نیست (پرداخت سررسید را جلو می‌برد)
        if ($unpaid === null) {
            return;
        }

        /*
        | «موعدِ پرداخت رسید» — رویدادی که تا امروز وجود نداشت.
        |
        | یادآوری‌های ۷/۳/۱ روز **پیش** از سررسید می‌روند. ولی لحظه‌ای که سررسید
        | واقعاً گذشت و پرداخت نشد، هیچ پیامی نمی‌رفت تا روزِ تعلیق. یعنی مشتری
        | بینِ «۱ روز مانده» و «سرویس‌تان قطع شد» هیچ خبری نداشت.
        |
        | ⚠️ فقط **یک بار**، در همان روزِ اول. `reminder_stage = 0` نشانهٔ
        |    «اعلانِ سررسید رفته» است — بی‌این، هر اجرای روزانه یک پیام
        |    می‌فرستاد و مشتری روزی یکی می‌گرفت تا روزِ تعلیق.
        */
        if ($daysOverdue === 1 && (int) $service->reminder_stage !== 0) {
            if (! $dry) {
                app(\App\Services\Notify\Notifier::class)->fire(
                    'payment_due',
                    $service->customer,
                    [
                        'number' => (string) $unpaid->number,
                        'amount' => fa_num(number_format((int) $unpaid->total)).' تومان',
                        'days'   => fa_num($daysOverdue),
                        'link'   => console_lroute('account.invoices'),
                    ],
                    '⏳ سررسیدِ سرویسِ «'.$service->name.'» گذشت و فاکتورش هنوز پرداخت نشده. '
                    .'برای جلوگیری از قطعِ سرویس، همین حالا پرداخت کنید: '.console_lroute('account.invoices'),
                    ['سرویس' => $service->name],
                    $service->customer ? url('/admin/customers/'.$service->customer->id) : null,
                    '⏳',
                );

                $service->forceFill(['reminder_stage' => 0])->save();
            }
            $stats['reminded']++;
        }

        // ۳-الف) هنوز معلق نشده → تعلیقِ خودکار
        if ($service->suspended_at === null) {
            if (! $dry) {
                $provisioner->suspend($service);

                $service->forceFill([
                    'status'       => 'suspended',
                    'suspended_at' => now(),
                ])->save();

                $customers->templated($service->customer, 'suspended',
                    ['service' => $service->name],
                    '⚠️ سرویسِ «'.$service->name.'» به‌دلیلِ پرداخت‌نشدنِ فاکتورِ تمدید موقتاً غیرفعال شد. '
                    .'اطلاعات و فایل‌هایتان محفوظ است؛ با پرداختِ فاکتور بلافاصله برمی‌گردد.');

                $this->alertAdmin($admin, $service, 'سرویس به‌خاطرِ عدمِ تمدید غیرفعال شد', '⛔');

                \App\Models\ActivityLog::forService($service, 'suspend',
                    __('ui.act_auto_suspend', ['days' => $daysOverdue], $service->customer?->locale ?: 'fa'), 'system');
            }
            $stats['suspended']++;

            return;
        }

        // ۳-ب) مهلتِ ۳۰ روزه تمام شد → یک‌بار به مدیر بگو تصمیم بگیرد
        $suspendedDays = $service->suspended_at->copy()->startOfDay()->diffInDays($today);

        if ($suspendedDays >= $graceDays && $service->grace_alert_at === null) {
            if (! $dry) {
                /*
                | 🔴 حساس‌ترین اعلانِ کلِ سامانه: پس از حذف، دادهٔ مشتری
                |    **برنمی‌گردد**.
                |
                | تا امروز فقط مدیر خبردار می‌شد و مشتری هیچ هشداری نمی‌گرفت —
                | یعنی کسی که فاکتورش را فراموش کرده بود، بی‌آنکه بداند، در
                | آستانهٔ ازدست‌دادنِ همیشگیِ سایتش بود. آخرین فرصتِ او همین
                | پیام است.
                */
                app(\App\Services\Notify\Notifier::class)->fire(
                    'data_deletion_due',
                    $service->customer,
                    ['service' => $service->name, 'days' => fa_num($graceDays)],
                    '🗑 سرویسِ «'.$service->name.'» '.fa_num($graceDays).' روز است معلق مانده. '
                    .'اگر تا اطلاعِ ثانوی تمدید نشود، **داده‌هایش برای همیشه حذف می‌شود** و '
                    .'قابلِ بازگردانی نخواهد بود. برای جلوگیری، همین حالا فاکتور را پرداخت کنید: '
                    .console_lroute('account.invoices'),
                    ['وضعیت' => 'مهلتِ '.fa_num($graceDays).' روزه تمام شد — تصمیمِ حذف با شماست'],
                    $service->customer ? url('/admin/customers/'.$service->customer->id) : null,
                    '🗑',
                );

                $service->forceFill(['grace_alert_at' => now()])->save();
            }
            $stats['grace']++;
        }
    }

    /** فاکتورِ بازِ همین سرویس (پرداخت‌نشده) */
    private function openInvoice(Service $service): ?Invoice
    {
        return Invoice::where('service_id', $service->id)
            ->whereIn('status', ['unpaid', 'draft', 'partial'])
            ->latest('id')
            ->first();
    }

    /** کدام مرحلهٔ یادآوری به این تعدادِ روزِ باقی‌مانده می‌خورد؟ */
    private function stageFor(int $daysLeft): ?int
    {
        foreach (self::STAGES as $s) {
            if ($daysLeft === $s) {
                return $s;
            }
        }

        return null;
    }

    /** یادآوری به مشتری از هر سه کانال (پیامک/بله + ایمیل) */
    private function remindCustomer(Service $service, ?Invoice $invoice, int $stage, CustomerNotifier $customers): void
    {
        $when = $stage === 1 ? 'فردا' : $stage.' روز دیگر';
        $amount = $invoice ? fa_num(number_format((int) $invoice->total)).' تومان' : null;

        $text = '⏰ سرویسِ «'.$service->name.'» '.$when.' سررسید می‌شود'
            .($amount ? ' (مبلغِ تمدید: '.$amount.')' : '').'. '
            .'برای جلوگیری از قطعِ سرویس، از پنل کاربری پرداخت کنید: '
            .console_lroute('account.invoices');

        // متنِ الگو (اگر مدیر نوشته باشد) + ایمیلِ برنددار. پیش از این، این
        // مهم‌ترین ایمیلِ چرخهٔ مالی — «پرداخت کن وگرنه سرورت می‌خوابد» — با
        // `Mail::raw` می‌رفت: بی‌لوگو، بی‌RTL، شبیهِ اسپم.
        $mailed = $customers->templated($service->customer, 'expiring', [
            'service' => $service->name,
            'days'    => fa_num($stage),
            'amount'  => $amount ?? '—',
            'link'    => console_lroute('account.invoices'),
        ], $text);

        // اگر الگویی برای ایمیل نبود (یا ارسالش نشد)، همان یادآوریِ سادهٔ قبلی
        // می‌رود. یادآوریِ مالی نباید به وجودِ یک ردیف در دیتابیس بند باشد —
        // نرسیدنش یعنی مشتری بی‌خبر می‌مانَد و سرویسش قطع می‌شود.
        try {
            if (! $mailed && filled($service->customer?->email)) {
                Mail::mailer('smtp')->raw($text, fn ($m) => $m
                    ->to($service->customer->email)
                    ->subject('یادآوریِ تمدید — '.$service->name));
            }
        } catch (\Throwable) {
        }
    }

    /** اعلانِ مدیر با جزئیاتی که برای تصمیم لازم دارد */
    private function alertAdmin(AdminNotifier $admin, Service $service, string $title, string $emoji): void
    {
        $c = $service->customer;

        $admin->event($title, [
            'مشتری'  => trim(($c?->displayName() ?? '—').' ('.($c?->code ?? '—').')'),
            'سرویس'  => $service->name,
            'دامنه'  => $service->domain,
            'دوره'   => $service->cycleLabel(),
            'مبلغ'   => fa_num(number_format((int) $service->total())).' تومان',
            'سررسید' => sdate($service->next_due_at),
            'تلفن'   => $c?->phone,
        ], $c ? url('/admin/customers/'.$c->id) : null, $emoji);
    }
}
