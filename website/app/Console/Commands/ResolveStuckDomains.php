<?php

namespace App\Console\Commands;

use App\Models\CreditEntry;
use App\Models\Domain;
use App\Models\Invoice;
use App\Services\Domain\DomainRegistrar;
use App\Services\Notify\AdminNotifier;
use App\Services\Notify\CustomerNotifier;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `domains:resolve-stuck` — هیچ دامنه‌ای برای همیشه در صفِ دستی نمی‌مانَد.
 *
 * ═══ قاعده‌ای که کارفرما گذاشت ═══
 *
 * «نمی‌خوام هیچ کاری در صفِ ثبتِ دستی بمونه — یا کنسل بشه پولش به مشتری
 * برگرده، یا ثبت بشه.»
 *
 * و این فقط یک ترجیح نیست. `manual` تا امروز یک **حالتِ پایانی** بود: دامنه‌ای
 * که پولش گرفته شده، ثبت نشده، و منتظرِ کاری از یک آدم است که ممکن است هرگز
 * نیاید. مشتری نه دامنه دارد نه پولش را. بدترین حالتِ ممکن، و از دستِ خودش هم
 * کاری برنمی‌آید.
 *
 * حالا `manual` یک حالتِ **گذرا** است با دو خروجی و مهلتِ مشخص:
 *
 *   ۱) مانع برطرف شد   → به `pending` برمی‌گردد و کرونِ ثبت برش می‌دارد
 *   ۲) مهلت تمام شد     → لغو + بازگشتِ کاملِ پول به اعتبارِ مشتری
 *
 * ═══ چرا بازگشت به **اعتبار** و نه به درگاه ═══
 *
 * برگشتِ درگاهی نیازِ تماسِ API با بانک دارد، همیشه ممکن نیست (کارت منقضی،
 * حسابِ بسته)، و شکستش یعنی همان بن‌بستِ اول از درِ دیگر. اعتبارِ داخلی
 * **همیشه** موفق است، فوری است، و مشتری می‌تواند هم خرجش کند هم درخواستِ
 * برداشت بدهد. تصمیمِ برگشتِ نقدی مالِ مدیر است؛ این فرمان فقط تضمین می‌کند
 * پول در حسابِ مشتری بنشیند، نه در برزخ.
 *
 * ⚠️ **این تنها کدِ پروژه است که پول را خودکار برمی‌گرداند.** پس هر محافظی
 * که این‌جا هست عمدی است و برداشتنش یعنی پولِ دوبار برگشته.
 */
class ResolveStuckDomains extends Command
{
    protected $signature = 'domains:resolve-stuck
        {--hours= : مهلت پیش از لغو (پیش‌فرض از config)}
        {--dry-run : فقط بگو چه می‌کردی}';

    protected $description = 'دامنهٔ گیرکرده در صفِ دستی را یا آزاد می‌کند یا لغو و پولش را برمی‌گرداند';

    /** دلیلِ یکتای بازگشت — محافظِ «دو بار برنگردان» روی همین می‌گردد */
    public const REFUND_REASON = 'domain_failed_refund';

    /** بازگشتِ وجهِ تمدیدِ شکست‌خورده — جدا از ثبت، چون دامنه زنده می‌مانَد */
    public const RENEW_REFUND_REASON = 'domain_renewal_refund';

    /** بیش از این تعداد آزادسازیِ بی‌نتیجه یعنی مانعی هست که نمی‌بینیم */
    public const MAX_REQUEUES = 3;

    public function handle(DomainRegistrar $registrar, CustomerNotifier $customers, AdminNotifier $admin): int
    {
        $hours = (int) ($this->option('hours') ?: config('services.openprovider.manual_grace_hours', 24));
        $dry = (bool) $this->option('dry-run');

        $stuck = Domain::where('provision_status', 'manual')
            ->where('status', 'pending')
            ->with('customer')
            ->orderBy('updated_at')
            ->get();

        /*
        | 🔴 تمدیدهای شکست‌خورده هم صفِ دستی دارند — و تا ممیزیِ شهریور ۱۴۰۵
        | هیچ‌کس آن صف را نمی‌دید: `failRenewal()` دامنهٔ **فعال** را در
        | `manual` پارک می‌کند، ولی پرس‌وجوی بالا فقط `status='pending'` را
        | می‌گیرد. نتیجه: پولِ تمدید گرفته شده بود، تمدید نشده بود، هیچ
        | بازگشتی هم در کار نبود — نقضِ همان قاعدهٔ کارفرما، فقط در لباسِ
        | تمدید به‌جای ثبت.
        */
        $stuckRenewals = Domain::where('provision_status', 'manual')
            ->where('status', 'active')
            ->with('customer')
            ->orderBy('updated_at')
            ->get();

        if ($stuck->isEmpty() && $stuckRenewals->isEmpty()) {
            $this->line('هیچ دامنه‌ای در صفِ دستی نیست.');

            return self::SUCCESS;
        }

        $freed = 0;
        $refunded = 0;
        $waiting = 0;

        foreach ($stuck as $domain) {
            /*
            | ── گام ۱: هنوز مانعی هست؟ ──
            |
            | 🔴 سؤال باید **کامل** باشد. نسخهٔ قبلی فقط کامل‌بودنِ پروفایل را
            | می‌پرسید و ممیزی دو خرابیِ متضادش را پیدا کرد: مانعِ غیرپروفایلی
            | (قراردادِ امضانشده…) هر ساعت «آزاد» می‌شد و شکست می‌خورد — و چون
            | هر دور `updated_at` را تازه می‌کرد، مهلتِ بازگشتِ وجه هرگز
            | نمی‌رسید؛ و با مالکِ ثابتِ شرکت، دامنهٔ قابلِ ثبت «مسدود» شمرده
            | و بعد از مهلت لغو/رفاند می‌شد.
            |
            | حالا همان گیت‌های خودِ ثبت پرسیده می‌شوند (`registrationBlocker`)
            | — یک منبع، بدونِ تماسِ API.
            */
            $blocker = $registrar->registrationBlocker($domain);

            /*
            | ⚠️ ترمزِ حلقه: اگر چند بار آزاد شد و هر بار دوباره به صفِ دستی
            | برگشت، مانعی هست که ما نمی‌بینیم (مثلاً رجیسترار همین نام را
            | ساختاری رد می‌کند). آزادسازیِ بی‌پایان یعنی تماسِ بی‌فایدهٔ
            | ساعتی + مهلتی که هرگز تمام نمی‌شود؛ از این‌جا به بعد بگذار
            | مهلت بدود و پولِ مشتری برگردد.
            */
            $requeues = (int) ($domain->meta['stuck_requeues'] ?? 0);

            if ($blocker === null && $requeues >= self::MAX_REQUEUES) {
                $blocker = 'پس از '.$requeues.' بار بازگشت به صف، هر بار دوباره شکست خورد.';
            }

            if ($blocker === null) {
                $this->line(($dry ? '[خشک] ' : '').'↻ آزاد شد: '.$domain->domain);
                $freed++;

                if (! $dry) {
                    $domain->forceFill([
                        'provision_status' => 'pending',
                        'provision_tries'  => 0,
                        'provision_error'  => null,
                    ])->save();

                    $domain->putMeta(['stuck_requeues' => $requeues + 1]);
                }

                continue;
            }

            // ── گام ۲: مهلت هنوز تمام نشده؟ ──
            $parkedFor = $domain->updated_at?->diffInHours(now()) ?? 0;

            if ($parkedFor < $hours) {
                $waiting++;
                $this->warn('… در مهلت ('.$parkedFor.'/'.$hours.' ساعت): '.$domain->domain);

                continue;
            }

            // ── گام ۳: مهلت تمام — لغو و بازگشتِ پول ──
            $this->error(($dry ? '[خشک] ' : '').'✗ لغو و بازگشتِ پول: '.$domain->domain);
            $refunded++;

            if (! $dry) {
                $this->cancelAndRefund($domain, $customers, $admin);
            }
        }

        /*
        | ── صفِ دوم: تمدیدهای شکست‌خورده ──
        |
        | این‌جا «آزادسازی» معنا ندارد: تمدید ۵ بار تلاش شده و شکست خورده؛
        | تلاشِ ششم همان تماسِ بی‌فایده با حسابِ علامت‌خورده است. دو خروجی:
        | یا مدیر در مهلت دستی حلش می‌کند، یا وجهِ **فاکتورِ تمدید** به
        | اعتبارِ مشتری برمی‌گردد و دامنه به حالتِ عادی (`done`) بازمی‌گردد
        | تا چرخهٔ عمر دوباره یادآوری بفرستد — دامنه هنوز زنده است و نباید
        | بی‌صدا به‌سمتِ انقضا برود.
        */
        foreach ($stuckRenewals as $domain) {
            $parkedFor = $domain->updated_at?->diffInHours(now()) ?? 0;

            if ($parkedFor < $hours) {
                $waiting++;
                $this->warn('… تمدید در مهلت ('.$parkedFor.'/'.$hours.' ساعت): '.$domain->domain);

                continue;
            }

            $this->error(($dry ? '[خشک] ' : '').'↩ بازگشتِ وجهِ تمدید: '.$domain->domain);
            $refunded++;

            if (! $dry) {
                $this->refundFailedRenewal($domain, $customers, $admin);
            }
        }

        $this->newLine();
        $this->line("آزادشده: {$freed} · لغو و برگشت: {$refunded} · در مهلت: {$waiting}");

        return self::SUCCESS;
    }

    /**
     * بازگشتِ وجهِ تمدیدِ شکست‌خورده — بدونِ دست‌زدن به خودِ دامنه.
     *
     * ═══ تفاوت با `cancelAndRefund()` ═══
     *
     * آن‌جا دامنه‌ای است که هرگز ثبت نشد؛ لغوش درست است. این‌جا دامنهٔ
     * **زندهٔ** مشتری است که فقط تمدیدش شکست خورده — لغو یعنی نابودکردنِ
     * دارایی مشتری به‌خاطرِ خطای ما. پس: وجهِ فاکتورِ تمدید برمی‌گردد،
     * `provision_status` به `done` می‌رود (حالتِ عادی)، و چرخهٔ عمر دوباره
     * فاکتور و یادآوری می‌فرستد؛ اگر مانع برطرف شده باشد پرداختِ بعدی موفق
     * می‌شود و اگر نه، دوباره همین‌جا برمی‌گردد — پرصداتر از گم‌شدنِ پول.
     *
     * 🔴 فقط فاکتوری برمی‌گردد که `meta['renew_invoice_id']` نشانش کرده.
     * «آخرین فاکتورِ پرداخت‌شده» ممکن است فاکتورِ **ثبتِ** سالِ پیش باشد؛
     * برگرداندنش یعنی پولِ ثبتِ دامنه‌ای که مشتری دارد و استفاده می‌کند.
     * ردیفِ بی‌نشان دست نمی‌خورد و فقط به مدیر گزارش می‌شود.
     */
    private function refundFailedRenewal(Domain $domain, CustomerNotifier $customers, AdminNotifier $admin): void
    {
        $invoiceId = (int) ($domain->meta['renew_invoice_id'] ?? 0);

        $invoice = $invoiceId > 0
            ? Invoice::where('id', $invoiceId)
                ->where('domain_id', $domain->id)
                ->where('kind', 'domain')
                ->where('status', 'paid')
                ->where('paid', '>', 0)
                ->first()
            : null;

        if ($invoice === null) {
            // نشانی نیست یا فاکتور در وضعیتِ قابلِ برگشت نیست → کارِ آدم.
            ErrorTracker::noteOnce('domain',
                'تمدیدِ شکست‌خورده بدونِ فاکتورِ قابلِ بازگشت: '.$domain->domain, 21600, [
                    'domain'  => $domain->domain,
                    'invoice' => $invoiceId ?: null,
                ]);

            return;
        }

        $amount = (int) $invoice->paid;

        try {
            DB::transaction(function () use ($domain, $invoice, $amount) {
                /*
                | ⚠️ محافظِ «دو بار برنگردان» — این‌جا روی **فاکتور** بسته
                | می‌شود نه دامنه: دامنه سال‌های بعد باز هم تمدید می‌شود و
                | ممکن است دوباره شکست بخورد؛ هر فاکتور فقط یک بار برمی‌گردد.
                */
                $already = CreditEntry::where('customer_id', $domain->customer_id)
                    ->where('reason', self::RENEW_REFUND_REASON)
                    ->where('source_type', Invoice::class)
                    ->where('source_id', $invoice->id)
                    ->exists();

                if (! $already && $domain->customer !== null) {
                    CreditEntry::create([
                        'customer_id'   => $domain->customer_id,
                        'currency_code' => (string) ($invoice->currency_code ?: 'IRT'),
                        'amount'        => $amount,
                        'balance_after' => $domain->customer->creditBalance((string) ($invoice->currency_code ?: 'IRT')) + $amount,
                        'reason'        => self::RENEW_REFUND_REASON,
                        'source_type'   => Invoice::class,
                        'source_id'     => $invoice->id,
                        'note'          => 'بازگشتِ وجه — تمدیدِ دامنهٔ '.$domain->domain.' ممکن نشد',
                    ]);
                }

                // ⚠️ فاکتور حذف نمی‌شود: سابقهٔ مالی و مالیاتی باید بماند.
                $invoice->forceFill(['status' => 'refunded'])->save();

                $domain->forceFill([
                    'provision_status' => 'done',
                    'provision_tries'  => 0,
                    'provision_error'  => 'تمدید ممکن نشد؛ وجهِ فاکتور به اعتبارِ مشتری بازگشت.',
                ])->save();

                $domain->putMeta(['renew_invoice_id' => null, 'renew_years' => null]);
            });
        } catch (\Throwable $e) {
            ErrorTracker::note('domain', $e, ['area' => 'renewal-refund', 'domain' => $domain->domain]);
            $this->error('   بازگشتِ وجهِ تمدید انجام نشد: '.$e->getMessage());

            return;
        }

        $money = fa_num(number_format($amount)).' تومان';

        try {
            if ($domain->customer !== null) {
                $customers->event($domain->customer, 'domain_refunded', [
                    'domain' => $domain->domain,
                    'amount' => $money,
                ], 'تمدیدِ دامنهٔ '.$domain->domain.' انجام نشد و مبلغ '.$money
                    .' به اعتبارِ حسابِ شما بازگشت. دامنه تا تاریخِ انقضای فعلی برقرار است؛ '
                    .'می‌توانید دوباره تمدید کنید یا با پشتیبانی تماس بگیرید.');
            }

            $admin->event('تمدیدِ دامنه ناموفق — وجه بازگشت',
                ['دامنه' => $domain->domain, 'مبلغ' => $money],
                url('/admin/domains'), '↩️');
        } catch (\Throwable $e) {
            ErrorTracker::note('notify', $e, ['area' => 'renewal-refund', 'domain' => $domain->domain]);
        }
    }

    /**
     * لغوِ دامنه + بازگرداندنِ پولِ پرداخت‌شده به اعتبارِ مشتری.
     *
     * ترتیب عمدی است و مثلِ خاتمهٔ سرویسِ ابری: **اول دفترِ مالی، بعد اعلان.**
     * اگر اعلان شکست بخورد، پول برگشته و ردیف بسته است؛ برعکسش یعنی مشتری
     * خبرِ برگشتی را می‌گیرد که هرگز انجام نشده.
     */
    private function cancelAndRefund(Domain $domain, CustomerNotifier $customers, AdminNotifier $admin): void
    {
        $amount = 0;

        try {
            DB::transaction(function () use ($domain, &$amount) {
                /*
                | مبلغ از **فاکتورِ واقعاً پرداخت‌شده** می‌آید، نه از
                | `price_toman`ِ روی دامنه.
                |
                | 🔴 آن ستون قیمتِ لحظهٔ سفارش است و مالیات را هم ندارد؛
                | برگرداندنش یعنی مشتری کمتر از آنچه داده پس می‌گیرد و
                | اختلافش در هیچ دفتری دیده نمی‌شود.
                */
                $invoice = Invoice::where('domain_id', $domain->id)
                    ->where('kind', 'domain')
                    ->where('paid', '>', 0)
                    ->orderByDesc('id')
                    ->first();

                $amount = (int) ($invoice?->paid ?? 0);

                /*
                | ⚠️ محافظِ «دو بار برنگردان».
                |
                | این فرمان ساعتی می‌دود. بی‌این، یک دامنهٔ لغوشده هر ساعت یک
                | برگشتِ تازه می‌خورد و اعتبارِ مشتری تا ابد بالا می‌رفت — با
                | پولِ ما. همان محافظی که `refundIfPrepaid()` سرورِ ابری دارد.
                */
                $already = $amount > 0 && CreditEntry::where('customer_id', $domain->customer_id)
                    ->where('reason', self::REFUND_REASON)
                    ->where('source_type', Domain::class)
                    ->where('source_id', $domain->id)
                    ->exists();

                if ($amount > 0 && ! $already && $domain->customer !== null) {
                    CreditEntry::create([
                        'customer_id'   => $domain->customer_id,
                        'currency_code' => (string) ($invoice->currency_code ?: 'IRT'),
                        'amount'        => $amount,
                        'balance_after' => $domain->customer->creditBalance((string) ($invoice->currency_code ?: 'IRT')) + $amount,
                        'reason'        => self::REFUND_REASON,
                        'source_type'   => Domain::class,
                        'source_id'     => $domain->id,
                        'note'          => 'بازگشتِ وجه — ثبتِ دامنهٔ '.$domain->domain.' ممکن نشد',
                    ]);
                }

                if ($invoice !== null) {
                    // ⚠️ فاکتور **حذف نمی‌شود**: سابقهٔ مالی و مالیاتی باید بماند.
                    $invoice->forceFill(['status' => 'refunded'])->save();
                }

                $domain->forceFill([
                    'status'           => 'cancelled',
                    'provision_status' => 'none',
                    'provision_error'  => 'لغو شد و وجه به اعتبارِ مشتری بازگشت (ثبت ممکن نشد).',
                ])->save();
            });
        } catch (\Throwable $e) {
            ErrorTracker::note('domain', $e, ['area' => 'auto-refund', 'domain' => $domain->domain]);
            $this->error('   بازگشتِ وجه انجام نشد: '.$e->getMessage());

            return;
        }

        $money = fa_num(number_format($amount)).' تومان';

        try {
            if ($domain->customer !== null) {
                $customers->event($domain->customer, 'domain_refunded', [
                    'domain' => $domain->domain,
                    'amount' => $money,
                ], 'ثبتِ دامنهٔ '.$domain->domain.' ممکن نشد و مبلغ '.$money
                    .' به اعتبارِ حسابِ شما بازگشت. می‌توانید دوباره اقدام کنید یا با پشتیبانی تماس بگیرید.');
            }

            $admin->event('دامنه لغو شد و وجه بازگشت',
                ['دامنه' => $domain->domain, 'مبلغ' => $money],
                url('/admin/domains'), '↩️');
        } catch (\Throwable $e) {
            // پول برگشته و ردیف بسته است؛ اعلانِ نرفته نباید آن را باطل کند
            ErrorTracker::note('notify', $e, ['area' => 'domain-refund', 'domain' => $domain->domain]);
        }
    }
}
