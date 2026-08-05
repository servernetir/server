<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Notify\AdminNotifier;
use App\Services\Notify\CustomerNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * چرخهٔ عمرِ دامنه — فاکتورِ تمدید، یادآوری، و اعلامِ انقضا.
 *
 * ═══ چرا این فرمان لازم شد ═══
 *
 * 🔴 تا پیش از این، **هیچ کدی در کلِ پروژه یک دامنه را تمدید نمی‌کرد.**
 *
 * `DomainRegistrar::renew()` نوشته و تست شده بود ولی هیچ فراخوانی نداشت؛
 * `services:renew-due` و `services:lifecycle` هر دو فقط جدولِ `services` را
 * می‌خوانند و خریدِ دامنه اصلاً `Service` نمی‌سازد؛ و در ثبت عمداً
 * `autoRenew: false` به رجیسترار می‌دهیم چون «تمدید را خودمان می‌فروشیم» —
 * ولی آن «خودمان» هرگز ساخته نشده بود.
 *
 * نتیجه: هر دامنه‌ای که می‌فروختیم، یک سال بعد بی‌صدا منقضی می‌شد. مشتری
 * دامنه‌اش را از دست می‌داد و ما ۱۰۰٪ درآمدِ تمدید را — که کلِ منطقِ اقتصادیِ
 * فروشِ دامنه است.
 *
 * بدتر: پنل به مشتری می‌گفت «تمدیدِ خودکار روشن شد، پیش از سررسید فاکتور صادر
 * می‌شود» و `auto_renew` هم پیش‌فرض روشن بود. یعنی یک **اطمینانِ دروغ**، که از
 * نبودِ قابلیت بدتر است: مشتری خیالش راحت می‌شد و خودش هم اقدامی نمی‌کرد.
 *
 * ═══ قواعدِ طراحی ═══
 *
 * • **idempotent**: فاکتورِ بازِ همان دامنه یعنی تمدید قبلاً صادر شده.
 * • `meta['exp_stage']` جلوی یادآوریِ تکراری را می‌گیرد (همان الگوی
 *   `reminder_stage` سرویس‌ها، ولی بی‌نیاز به مهاجرت چون `meta` از قبل هست).
 * • انقضا با **مهلت** اعلام می‌شود، نه همان روز — رجیستری دورهٔ بازیابی دارد.
 * • خطای یک دامنه نباید بقیه را زمین بزند.
 * • هیچ تماسی با رجیسترار این‌جا انجام نمی‌شود؛ کارِ واقعیِ تمدید بعد از
 *   **پرداخت** و با `domains:renew` است.
 */
class RunDomainLifecycle extends Command
{
    protected $signature = 'domains:lifecycle
        {--dry : فقط گزارش بده، هیچ‌چیز را عوض نکن}
        {--lead= : چند روز پیش از انقضا فاکتور صادر شود}';

    protected $description = 'صدورِ فاکتورِ تمدیدِ دامنه، یادآوریِ انقضا و علامت‌زدنِ دامنهٔ منقضی';

    /** مراحلِ یادآوری — از دور به نزدیک */
    private const STAGES = [7, 3, 1];

    public function handle(CustomerNotifier $customers, AdminNotifier $admin): int
    {
        if (! Schema::hasTable('domains')) {
            $this->warn('جدول domains ساخته نشده؛ اول مهاجرت را اجرا کنید.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');
        $lead = max(1, (int) ($this->option('lead') ?: Domain::RENEW_LEAD_DAYS));
        $today = now()->startOfDay();

        // ⚠️ دامنهٔ بی‌تاریخِ انقضا کنار می‌رود: یا هنوز ثبت نشده یا رجیسترار
        //    تاریخ نداده. حدس‌زدنِ تاریخ یعنی فاکتورِ تمدید برای دامنه‌ای که
        //    اصلاً منقضی نمی‌شود.
        $domains = Domain::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->with('customer')
            ->get();

        $stats = ['invoiced' => 0, 'reminded' => 0, 'expired' => 0];

        foreach ($domains as $domain) {
            try {
                $this->handleOne($domain, $today, $lead, $dry, $stats, $customers, $admin);
            } catch (\Throwable $e) {
                Log::error('چرخهٔ عمرِ دامنه خطا داد', [
                    'domain' => $domain->domain,
                    'error'  => $e::class.': '.mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        $this->info(sprintf(
            'فاکتورِ تمدید: %d · یادآوری: %d · منقضی‌شده: %d%s',
            $stats['invoiced'], $stats['reminded'], $stats['expired'],
            $dry ? '  (آزمایشی — چیزی عوض نشد)' : ''
        ));

        return self::SUCCESS;
    }

    private function handleOne(
        Domain $domain,
        \Illuminate\Support\Carbon $today,
        int $lead,
        bool $dry,
        array &$stats,
        CustomerNotifier $customers,
        AdminNotifier $admin,
    ): void {
        $daysLeft = (int) $today->diffInDays($domain->expires_at->copy()->startOfDay(), false);

        // ── ۱) انقضا گذشت و مهلت هم تمام شد → مرده اعلامش کن ─────────────────
        if ($daysLeft < -Domain::EXPIRY_GRACE_DAYS) {
            if (! $dry) {
                $domain->forceFill(['status' => 'expired'])->save();

                $customers->templated($domain->customer, 'domain_expired',
                    ['domain' => $domain->domain],
                    '⛔ دامنهٔ «'.$domain->domain.'» منقضی شد و دورهٔ بازیابی‌اش هم گذشت. '
                    .'اگر هنوز لازمش دارید با پشتیبانی تماس بگیرید.');

                $admin->event('دامنه منقضی شد', [
                    'دامنه'  => $domain->domain,
                    'مشتری'  => $domain->customer?->displayName(),
                    'انقضا'  => sdate($domain->expires_at),
                ], url('/admin/domains'), '⛔');
            }
            $stats['expired']++;

            return;
        }

        // ── ۲) هنوز نرسیده‌ایم به پنجرهٔ تمدید → کاری نداریم ──────────────────
        if ($daysLeft > $lead) {
            return;
        }

        $open = $this->openInvoice($domain);

        // ── ۳) فاکتورِ تمدید ─────────────────────────────────────────────────
        //
        // ⚠️ حتی اگر `auto_renew` خاموش باشد فاکتور صادر می‌شود — چون خاموش‌بودنش
        //    یعنی «خودکار تمدید نکن»، نه «به من نگو دارد منقضی می‌شود». مشتری
        //    باید بتواند تصمیم بگیرد؛ سکوت تصمیم را از او می‌گیرد.
        if ($open === null) {
            if (! $dry) {
                $open = $this->issueRenewalInvoice($domain);
            }
            $stats['invoiced']++;
        }

        // ── ۴) یادآوریِ ۷/۳/۱ روز ────────────────────────────────────────────
        $stage = $this->stageFor($daysLeft);

        if ($stage === null) {
            return;
        }

        $sent = $domain->expiryStage();

        if ($sent !== null && $sent <= $stage) {
            return;                       // این مرحله (یا نزدیک‌ترش) رفته
        }

        if (! $dry) {
            $this->remind($domain, $open, $stage, $customers);
            $domain->putMeta(['exp_stage' => $stage]);
        }

        $stats['reminded']++;
    }

    /**
     * فاکتورِ تمدید — همان شکلِ فاکتورِ ثبت، با مبلغِ `renew_toman`.
     *
     * ⚠️ `renew_toman` در لحظهٔ خرید ذخیره شده و ممکن است کهنه باشد؛ عمداً
     * همان را می‌گیریم و استعلامِ زنده نمی‌زنیم. دلیلش این است که این کرون
     * روزی یک‌بار روی همهٔ دامنه‌ها می‌دود و استعلامِ زنده یعنی صدها تماسِ API
     * در دقیقه به رجیستراری که حسابش قبلاً به‌خاطرِ تماسِ زیاد علامت خورده.
     * اگر قیمت خیلی عقب افتاده باشد، مدیر از `/admin/domains` می‌بیند.
     */
    private function issueRenewalInvoice(Domain $domain): Invoice
    {
        $years = 1;      // تمدیدِ خودکار همیشه یک‌ساله؛ چندساله را مشتری دستی می‌خرد
        $unit = (int) ($domain->renew_toman ?: $domain->price_toman) * $years;

        $taxPct = \App\Http\Controllers\Account\CloudStoreController::taxPercent();
        $tax = (int) round($unit * $taxPct / 100);

        return DB::transaction(function () use ($domain, $years, $unit, $tax, $taxPct) {
            $invoice = Invoice::create([
                'customer_id'   => $domain->customer_id,
                'domain_id'     => $domain->id,
                'kind'          => 'domain',
                'currency_code' => 'IRT',
                'subtotal'      => $unit,
                'tax'           => $tax,
                'total'         => $unit + $tax,
                'paid'          => 0,
                'status'        => 'unpaid',
                'issued_at'     => now(),
                'note'          => 'تمدیدِ دامنهٔ '.$domain->domain,
            ]);

            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'title'       => 'تمدیدِ دامنهٔ '.$domain->domain,
                'description' => $years.' سال',
                'quantity'    => 1,
                'unit_price'  => $unit,
                'line_total'  => $unit,
                'tax_rate_bp' => (int) ($taxPct * 100),
                'tax_amount'  => $tax,
            ]);

            // 🔴 چند سال پرداخت شده را همین‌جا ثبت کن. بعد از پرداخت، کرونِ
            //    تمدید باید بداند چند سال بخرد و راهِ دیگری برای دانستنش
            //    نیست — خواندنش از متنِ آیتمِ فاکتور شکننده است.
            $domain->putMeta(['renew_years' => $years]);

            return $invoice;
        });
    }

    /** فاکتورِ بازِ همین دامنه */
    private function openInvoice(Domain $domain): ?Invoice
    {
        return Invoice::where('domain_id', $domain->id)
            ->whereIn('status', ['unpaid', 'draft', 'partial'])
            ->latest('id')
            ->first();
    }

    private function stageFor(int $daysLeft): ?int
    {
        foreach (self::STAGES as $s) {
            if ($daysLeft === $s) {
                return $s;
            }
        }

        return null;
    }

    private function remind(Domain $domain, ?Invoice $invoice, int $stage, CustomerNotifier $customers): void
    {
        $when = $stage === 1 ? 'فردا' : fa_num($stage).' روز دیگر';
        $amount = $invoice ? fa_num(number_format((int) $invoice->total)).' تومان' : null;

        $text = '⏰ دامنهٔ «'.$domain->domain.'» '.$when.' منقضی می‌شود'
            .($amount ? ' (هزینهٔ تمدید: '.$amount.')' : '').'. '
            .'برای اینکه دامنه‌تان را از دست ندهید، از پنل کاربری پرداخت کنید: '
            .console_lroute('account.invoices');

        // ⚠️ متغیرها حتماً پاس داده می‌شوند: هر دو خوانندهٔ الگو اگر بعد از
        //    جایگزینی هنوز `{چیزی}` ببینند، الگو را کنار می‌گذارند — یعنی
        //    مدیر متن را ویرایش می‌کند و هیچ اتفاقی نمی‌افتد.
        $customers->templated($domain->customer, 'domain_expiring', [
            'domain' => $domain->domain,
            'days'   => fa_num($stage),
            'amount' => $amount ?? '—',
            'link'   => console_lroute('account.invoices'),
        ], $text);
    }
}
