<?php

namespace App\Console\Commands;

use App\Models\BankTransferReceipt;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices:expire` — پیش‌فاکتورِ پرداخت‌نشده پس از مهلت، لغو می‌شود.
 *
 * کارفرما: «اگر تا ۴۸ ساعت پرداختش نکرد، خودِ پیش‌فاکتور باید لغو و پاک بشود؛
 * نمی‌خوام سیستم شلوغ بشه از پیش‌فاکتورهای معلقی که هیچ‌موقع پرداخت نمی‌شوند.»
 *
 * ═══ 🔴 چه چیزی **هرگز** لغو نمی‌شود ═══
 *
 * این‌جا خطرناک‌ترین بخشِ کار است: یک شرطِ زیادی-گشاد، پول و سرویسِ زندهٔ مشتری
 * را می‌بلعد. سه استثنا، و هرکدام یک خرابیِ متفاوت را می‌بندد:
 *
 * ۱) **فاکتورِ تمدیدِ سرویسِ فعال.** `services:lifecycle` دقیقاً روی وجودِ همان
 *    فاکتورِ پرداخت‌نشده کار می‌کند: یادآوری می‌فرستد، «سررسید گذشت» می‌گوید و
 *    در نهایت سرویس را تعلیق می‌کند. اگر لغوش کنیم، آن زنجیره **کور** می‌شود:
 *    سرویس نه فاکتور دارد نه تعلیق می‌شود، یعنی مشتری برای همیشه رایگان
 *    سرویس می‌گیرد و هیچ‌کس خبردار نمی‌شود. پس فاکتوری که `service_id` دارد و
 *    سرویسش **مرده نیست** دست نمی‌خورد.
 *
 * ۲) **رسیدِ بانکیِ در انتظارِ بررسی.** مشتری پول را واریز کرده و منتظرِ تأیید
 *    مدیر است. لغوِ خودکار یعنی پولِ واریزشده و فاکتورِ لغوشده — بدترین حالتِ
 *    ممکن برای اعتماد.
 *
 * ۳) **هر پرداختِ جزئی** (`paid > 0`). پولی گرفته‌ایم؛ تصمیمش با آدم است.
 *
 * ═══ لغو یعنی آزادسازی، نه فقط تغییرِ یک ستون ═══
 *
 * اگر فقط `status` فاکتور عوض شود، ردیفِ `Service`/`Domain`ِ رزروشده تا ابد
 * `pending` می‌مانَد — یعنی همان شلوغی‌ای که قرار بود کم شود، فقط از جدولِ
 * دیگری سر درمی‌آورد. و بدتر: دامنهٔ `pending` در `/admin/domains` مثلِ یک
 * سفارشِ گیرکردهٔ واقعی دیده می‌شود.
 *
 * ⚠️ هیچ چیزی **حذف** نمی‌شود. کارفرما گفت «لغو و پاک»، ولی حذفِ فیزیکیِ
 * فاکتور تاریخچهٔ مالی را از بین می‌برد و شماره‌های فاکتور را سوراخ می‌کند.
 * `canceled` از فهرست‌های پیش‌فرضِ پنل بیرون است، پس اثرِ دیده‌شدنی همان
 * «پاک شدن» است، بی‌آنکه دفتر دروغ بگوید.
 */
class ExpireStaleInvoices extends Command
{
    protected $signature = 'invoices:expire
        {--limit=200 : بیشینهٔ فاکتور در هر اجرا}
        {--dry : فقط گزارش بده، چیزی عوض نکن}';

    protected $description = 'لغوِ پیش‌فاکتورهای پرداخت‌نشده‌ای که مهلتشان گذشته';

    public function handle(): int
    {
        if (! Schema::hasTable('invoices')) {
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');

        $rows = Invoice::query()
            ->where('status', 'unpaid')
            ->where('paid', 0)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($rows->isEmpty()) {
            $this->line('پیش‌فاکتورِ منقضی‌شده‌ای نیست.');

            return self::SUCCESS;
        }

        $done = 0;
        $kept = 0;

        foreach ($rows as $invoice) {
            $why = $this->mustKeep($invoice);

            if ($why !== null) {
                $kept++;
                $this->line('… نگه داشته شد #'.$invoice->id.' — '.$why);

                continue;
            }

            if ($dry) {
                $done++;
                $this->line('(آزمایشی) لغو می‌شد: #'.$invoice->id);

                continue;
            }

            $this->cancel($invoice);
            $done++;
            $this->info('✓ لغو شد #'.$invoice->id.' — '.$invoice->total);
        }

        $this->line("جمع: {$done} لغوشده · {$kept} نگه‌داشته‌شده");

        return self::SUCCESS;
    }

    /** دلیلِ نگه‌داشتن، یا null اگر لغو مجاز است */
    private function mustKeep(Invoice $invoice): ?string
    {
        // ۱) فاکتورِ تمدیدِ سرویسی که هنوز زنده است
        if ($invoice->service_id !== null && Schema::hasTable('services')) {
            $service = Service::find($invoice->service_id);

            /*
            | ⚠️ مرزِ درست: «سفارشی که هرگز فعال نشده» در برابرِ «سرویسی که
            | تحویل شده».
            |
            | نسخهٔ اول `! isDead()` را می‌سنجید و **غلط** بود: `pending` هم مرده
            | نیست، پس سفارشِ پرداخت‌نشده‌ای که اصلاً تحویل نشده هم «سرویسِ زنده»
            | خوانده می‌شد و هرگز آزاد نمی‌شد — یعنی دقیقاً همان ردیف‌های معلقی
            | که این فرمان برای پاک‌کردنشان نوشته شد، از دستش در می‌رفتند.
            | تستِ `test_cancelling_releases_the_reserved_order` گرفتش.
            |
            | `suspended` عمداً نگه داشته می‌شود: مشتری با پرداختِ همان فاکتور
            | برمی‌گردد، پس لغوش راهِ برگشتش را می‌بندد.
            */
            if ($service !== null && ! $service->isDead() && $service->status !== 'pending') {
                return 'فاکتورِ تمدیدِ سرویسِ تحویل‌شده';
            }
        }

        // ۲) رسیدِ بانکیِ در انتظارِ بررسی — مشتری پول را فرستاده
        if (Schema::hasTable('bank_transfer_receipts')) {
            $waiting = BankTransferReceipt::where('invoice_id', $invoice->id)
                ->where('status', 'pending')
                ->exists();

            if ($waiting) {
                return 'رسیدِ بانکی در انتظارِ بررسی است';
            }
        }

        return null;
    }

    private function cancel(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            /*
            | ⚠️ شرطِ وضعیت **در خودِ UPDATE** تکرار می‌شود.
            |
            | بینِ خواندنِ فهرست و رسیدن به این‌جا ممکن است وب‌هوکِ درگاه همان
            | فاکتور را پرداخت‌شده کرده باشد. بی‌این شرط، یک فاکتورِ **پرداختِ
            | همین لحظه** لغو می‌شد و سرویسِ مشتری هرگز تحویل نمی‌گرفت.
            */
            $changed = Invoice::whereKey($invoice->id)
                ->where('status', 'unpaid')
                ->where('paid', 0)
                ->update([
                    'status'     => 'canceled',
                    'note'       => trim((string) $invoice->note.' — به‌دلیلِ پرداخت‌نشدن در مهلتِ مقرر خودکار لغو شد'),
                    'updated_at' => now(),
                ]);

            if ($changed === 0) {
                return;
            }

            // ── آزادسازیِ چیزی که این فاکتور رزرو کرده بود ──
            if ($invoice->service_id !== null && Schema::hasTable('services')) {
                Service::whereKey($invoice->service_id)
                    ->whereNotIn('status', Service::DEAD_STATUSES)
                    // فقط سفارشی که هرگز فعال نشده — گاردِ دومِ همان قاعدهٔ بالا
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

            if ($invoice->domain_id !== null && Schema::hasTable('domains')) {
                Domain::whereKey($invoice->domain_id)
                    ->whereNotIn('status', Domain::DEAD_STATUSES)
                    /*
                    | ⚠️ فقط دامنه‌ای که هنوز به رجیسترار نرفته.
                    | `provision_status = none` یعنی هیچ تماسی انجام نشده؛
                    | هر مقدارِ دیگری یعنی ممکن است همین حالا در حالِ ثبت باشد
                    | و لغوِ ردیف، دامنهٔ واقعاً ثبت‌شده را از پنل غیب می‌کند.
                    */
                    ->where('provision_status', 'none')
                    ->update([
                        'status'     => 'cancelled',
                        'updated_at' => now(),
                    ]);
            }
        });
    }
}
