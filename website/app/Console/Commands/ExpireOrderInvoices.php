<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Billing\InvoiceCanceller;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * پیش‌فاکتورِ سفارشِ پرداخت‌نشده بعد از ۷۲ ساعت منقضی می‌شود.
 *
 * کارفرما: «بعداً دوباره سفارش بدهد اگر خواست، چون قیمت‌ها نوسان دارد.» بهای
 * تمام‌شدهٔ ما یورویی است و پیش‌فاکتورِ هفته‌پیش می‌تواند زیرِ قیمتِ خرید باشد.
 *
 * ═══ چه چیزی **هرگز** لمس نمی‌شود ═══
 *
 * 🔴 **فاکتورِ تمدید.** جداکننده وضعیتِ خودِ سرویس است نه نوعِ فاکتور:
 * سفارشِ تازه سرویسی با `status='pending'` و `activated_at=null` دارد؛ تمدید
 * روی سرویسِ `active` صادر می‌شود. لغوِ فاکتورِ تمدید یعنی قطعِ سرویسی که
 * مشتری بابتش پول داده.
 *
 * 🔴 **هر فاکتوری که پول رویش حرکت کرده.** سنجه ستونِ `paid` است نه وضعیت —
 * پرداختِ نیمه هم `unpaid` است.
 *
 * 🔴 **پول در راه.** پرداختِ بازِ درگاه (پنجرهٔ ۳۰ دقیقه‌ای)، رمزارزِ منتظر، و
 * رسیدِ بانکیِ در انتظارِ بررسی. رسیدِ بانکی سقفِ جدا دارد چون پایا روزها طول
 * می‌کشد — ولی سقف دارد، چون شمارهٔ پیگیری راستی‌آزمایی نمی‌شود.
 *
 * 🔴 **پیش‌فاکتورِ صادرشدهٔ مدیر** (`services.created_by` پر است). ممکن است
 * تخفیفِ مذاکره‌شده داشته باشد که بازسازی‌شدنی نیست، و مدیر می‌تواند تاریخِ
 * صدور را عقب بزند پس بعضی‌شان از لحظهٔ تولد از ۷۲ ساعت قدیمی‌ترند.
 *
 * ⚠️ **لغو، نه حذفِ فیزیکی.** چرایش در `InvoiceCanceller`.
 */
class ExpireOrderInvoices extends Command
{
    protected $signature = 'invoices:expire-orders
        {--hours= : بازنویسیِ مهلت (پیش‌فرض از config)}
        {--limit=200 : سقفِ ردیف در هر اجرا}
        {--dry : فقط نشان بده، چیزی ننویس}';

    protected $description = 'پیش‌فاکتورِ سفارشِ مشتری را که ۷۲ ساعت پرداخت نشده لغو می‌کند (تمدیدها را هرگز)';

    public function handle(InvoiceCanceller $canceller): int
    {
        /*
        | 🔴 کلِ بدنه در try است. این فرمان هر ساعت داخلِ `schedule:run` می‌دود و
        | یک استثنا — مثلاً ستونی که هنوز روی پروداکشن مهاجرت نشده — **کلِ آن
        | دقیقهٔ کرون** را می‌کشد: تحویلِ سرویس، ثبتِ دامنه، مترِ ساعتی، همه.
        */
        try {
            return $this->expire($canceller);
        } catch (\Throwable $e) {
            ErrorTracker::note('billing', $e, ['cmd' => 'invoices:expire-orders']);
            $this->error('اجرا ناتمام ماند: '.mb_substr($e->getMessage(), 0, 160));

            return self::SUCCESS;      // هرگز کدِ خطا نمی‌دهد؛ بقیهٔ کرون باید بدود
        }
    }

    private function expire(InvoiceCanceller $canceller): int
    {
        $hours  = (int) ($this->option('hours') ?: config('billing.order_expiry_hours', 72));
        $limit  = max(1, (int) $this->option('limit'));
        $dry    = (bool) $this->option('dry');
        $cutoff = now()->subHours(max(1, $hours));

        if (! Schema::hasTable('invoices')) {
            return self::SUCCESS;
        }

        $rows = collect()
            ->merge($this->serviceOrders($cutoff, $limit))
            ->merge($this->domainOrders($cutoff, $limit));

        $expired = 0;
        $blocked = ['payment' => 0, 'bank' => 0, 'crypto' => 0];

        foreach ($rows as $invoice) {
            if (($why = $this->moneyInFlight($invoice)) !== null) {
                $blocked[$why]++;
                $this->line('⏸ '.$invoice->number.' — '.$why);

                continue;
            }

            if ($dry) {
                $this->line('🔎 لغو می‌شد: '.$invoice->number.' ('.$invoice->kind.')');
                $expired++;

                continue;
            }

            try {
                if ($canceller->cancel($invoice, 'مهلتِ پرداختِ پیش‌فاکتور تمام شد', rejectPendingReceipt: false)) {
                    $expired++;
                    $this->notifyCustomer($invoice);
                    $this->line('✔ '.$invoice->number.' لغو شد.');
                }
            } catch (\Throwable $e) {
                // یک ردیفِ خراب نباید بقیه را زمین بزند
                ErrorTracker::note('billing', $e, ['invoice' => $invoice->number]);
            }
        }

        /*
        | ⚠️ رسیدِ بانکیِ در انتظار، فاکتور را **بلاک** می‌کند و خودکار رد نمی‌شود.
        | پس اگر مدیر سراغش نرود، آن ردیف تا سقفِ مهلت می‌مانَد. یک جایی باید
        | صدا بدهد وگرنه صفِ /admin/bank-transfers بی‌صدا پر می‌شود.
        */
        if ($blocked['bank'] > 0) {
            ErrorTracker::noteOnce('billing',
                'رسیدِ بانکیِ بررسی‌نشده جلوی انقضای فاکتور را گرفته — /admin/bank-transfers را ببینید.',
                3600, ['count' => $blocked['bank']]);
        }

        $this->info('منقضی: '.$expired.' · بلاک‌شده — درگاه '.$blocked['payment']
            .'، بانکی '.$blocked['bank'].'، رمزارز '.$blocked['crypto']);

        return self::SUCCESS;
    }

    /**
     * سفارشِ سرویس (هاست و ابری).
     *
     * ⚠️ هر ستونی که ممکن است روی سروری مهاجرت نشده باشد، با `hasColumn` گارد
     * می‌شود: زیرپرس‌وجو روی ستونِ ناموجود استثنا می‌دهد و کلِ دقیقهٔ کرون را
     * می‌کشد. مهاجرت‌های پروداکشن این پروژه دستی اجرا می‌شوند.
     */
    private function serviceOrders(\Illuminate\Support\Carbon $cutoff, int $limit)
    {
        if (! Schema::hasTable('services')) {
            return collect();
        }

        return Invoice::query()
            ->where('kind', 'service')
            ->where('paid', '<=', 0)
            ->whereIn('status', ['unpaid', 'draft'])
            ->whereNotNull('service_id')
            ->where('created_at', '<', $cutoff)
            ->whereExists(function ($q) {
                $q->from('services')
                    ->whereColumn('services.id', 'invoices.service_id')
                    // ← جداکنندهٔ سفارشِ تازه از تمدید
                    ->where('services.status', 'pending');

                if (Schema::hasColumn('services', 'activated_at')) {
                    $q->whereNull('services.activated_at');
                }

                // ← «از سمت کاربر»: پیش‌فاکتورِ مدیر مستثناست
                if (Schema::hasColumn('services', 'created_by')) {
                    $q->whereNull('services.created_by');
                }
            })
            // خواهرِ پرداخت‌شده یعنی این سرویس واقعاً خریده شده
            ->whereNotExists(fn ($q) => $q->from('invoices as sib')
                ->whereColumn('sib.service_id', 'invoices.service_id')
                ->where('sib.paid', '>', 0))
            ->orderBy('id')->limit($limit)->get();
    }

    /** سفارشِ ثبتِ دامنه — فقط ردیفی که هرگز ثبت نشده */
    private function domainOrders(\Illuminate\Support\Carbon $cutoff, int $limit)
    {
        if (! Schema::hasTable('domains')) {
            return collect();
        }

        return Invoice::query()
            ->where('kind', 'domain')
            ->where('paid', '<=', 0)
            ->whereIn('status', ['unpaid', 'draft'])
            ->whereNotNull('domain_id')
            ->where('created_at', '<', $cutoff)
            ->whereExists(fn ($q) => $q->from('domains')
                ->whereColumn('domains.id', 'invoices.domain_id')
                ->where('domains.status', 'pending')
                ->where('domains.provision_status', 'none'))
            ->orderBy('id')->limit($limit)->get();
    }

    /**
     * پولی در راه است؟ برمی‌گرداند `payment` / `bank` / `crypto` یا null.
     *
     * 🔴 این‌جا محافظه‌کاری درست است: اگر شک داریم، لغو نمی‌کنیم. بدترین حالتِ
     * نگه‌داشتن یک فاکتورِ اضافه است؛ بدترین حالتِ لغو، گم‌شدنِ پولِ واقعی.
     */
    private function moneyInFlight(Invoice $invoice): ?string
    {
        if (Schema::hasTable('payments')) {
            $open = \App\Models\Payment::where('invoice_id', $invoice->id)
                ->whereIn('status', ['pending', 'redirected'])
                ->where('updated_at', '>=', now()->subMinutes(30))
                ->exists();

            if ($open) {
                return 'payment';
            }
        }

        if (Schema::hasTable('bank_transfer_receipts')) {
            // پایا روزها طول می‌کشد ⇒ مهلتِ جدا. ولی سقف دارد، چون شمارهٔ
            // پیگیریِ رسید متنِ آزاد است و راستی‌آزمایی نمی‌شود.
            $grace = (int) config('billing.order_expiry_bank_grace_days', 14);

            $pending = \App\Models\BankTransferReceipt::where('invoice_id', $invoice->id)
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subDays(max(1, $grace)))
                ->exists();

            if ($pending) {
                return 'bank';
            }
        }

        if (Schema::hasTable('crypto_payments')) {
            $live = \Illuminate\Support\Facades\DB::table('crypto_payments')
                ->where('invoice_id', $invoice->id)
                ->where(function ($q) {
                    $q->where(fn ($w) => $w->whereIn('status', ['pending', 'seen'])->where('expires_at', '>', now()))
                        ->orWhere('received_atomic', '>', 0);
                })
                ->exists();

            if ($live) {
                return 'crypto';
            }
        }

        return null;
    }

    /**
     * مشتری باید بداند چرا فاکتورش رفت.
     *
     * ⚠️ ما خودمان لحظهٔ سفارش ایمیلی با **لینکِ زندهٔ همان فاکتور** فرستاده‌ایم.
     * انقضای بی‌صدا یعنی آن ایمیل به قولی شکسته تبدیل شود: مشتری کلیک می‌کند و
     * فاکتوری می‌بیند که نه توضیحی دارد نه دکمه‌ای.
     *
     * best-effort محض: اعلان هرگز نباید خودِ لغو را برگرداند.
     */
    private function notifyCustomer(Invoice $invoice): void
    {
        try {
            $customer = $invoice->customer;

            if ($customer === null) {
                return;
            }

            app(\App\Services\Notify\CustomerNotifier::class)->templated(
                $customer,
                'invoice_expired',
                ['number' => (string) $invoice->number],
                'پیش‌فاکتورِ '.fa_num((string) $invoice->number).' پرداخت نشد و منقضی شد. '
                .'قیمت‌ها نوسان دارند، پس برای خرید کافی است سفارشِ تازه با قیمتِ روز ثبت کنید: '
                .console_lroute('account.services'),
            );
        } catch (\Throwable) {
            // اعلان هرگز جریان اصلی را نمی‌شکند
        }
    }
}
