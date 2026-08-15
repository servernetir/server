<?php

namespace App\Services\Billing;

use App\Models\BankTransferReceipt;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «لغوِ فاکتور» — **یک** تعریف، دو فراخوان (مشتری و کرونِ انقضا).
 *
 * ═══ چرا استخراج شد ═══
 *
 * تا امروز این منطق داخلِ `Account\PaymentController::cancel()` بود. کرونِ
 * انقضای ۷۲ ساعته اگر نسخهٔ خودش را می‌نوشت، **تعریفِ دومِ لغو** می‌شد — و
 * قاعدهٔ ثبت‌شدهٔ همین پروژه می‌گوید دو تعریف روزی واگرا می‌شوند. این‌جا یک
 * طرفِ واگرایی «سابقهٔ مالی» است.
 *
 * ═══ چرا لغو و نه حذفِ فیزیکی ═══
 *
 * کارفرما گفت «پاک شود». `canceled` **همهٔ** چیزی را که خواسته می‌دهد:
 * `isPayable()` رد می‌شود پس دیگر قابلِ پرداخت نیست، از شمارنده‌های
 * «پرداخت‌نشده» بیرون می‌رود، و مشتری برای خرید باید سفارشِ تازه با قیمتِ روز
 * بدهد. ولی حذفِ فیزیکی سه چیزِ اضافه می‌کند که هیچ‌کدام خواسته نشده‌اند:
 *
 *   • `payments` با cascade پاک می‌شود — از جمله ردیفِ `redirected`ِ مشتری‌ای
 *     که همین حالا جلوی درگاه ایستاده. برگشتش «این پرداخت شناسایی نشد» است.
 *   • `crypto_payments` و `bank_transfer_receipts` هیچ کلیدِ خارجی ندارند ⇒
 *     یتیم می‌شوند و پولِ رسیده به هیچ فاکتوری وصل نمی‌شود.
 *   • لینکی که خودمان در ایمیلِ سفارش فرستاده‌ایم ۴۰۴ می‌دهد.
 *
 * و موضعِ خودِ پروژه دو بار صریح نوشته شده: «فاکتور حذف نمی‌شود؛ سابقهٔ مالی و
 * مالیاتی باید بماند» (`ResolveStuckDomains`). اگر روزی حسابدار تأیید کرد که
 * پاک‌سازیِ فیزیکی اشکالی ندارد، باید کارِ **جدا** روی افقِ خیلی بلندتر باشد،
 * نه در همین کرونِ ۷۲ ساعته.
 */
class InvoiceCanceller
{
    /**
     * @param  string  $reason  دلیلِ ردِ رسیدِ بانکیِ در انتظار (اگر رد شود)
     * @param  bool  $rejectPendingReceipt  رسیدِ بانکیِ در انتظار رد شود؟
     *                کرون **نه** می‌دهد: رسیدِ در انتظار یعنی آدمی ادعا کرده پولِ
     *                واقعی فرستاده، و ردِ خودکارِ آن یعنی گم‌کردنِ پول.
     * @return bool آیا واقعاً لغو شد
     */
    public function cancel(Invoice $invoice, string $reason, bool $rejectPendingReceipt = true): bool
    {
        return (bool) DB::transaction(function () use ($invoice, $reason, $rejectPendingReceipt) {
            /*
            | 🔴 زیرِ قفل دوباره خوانده می‌شود.
            |
            | بینِ انتخاب و نوشتن، مشتری می‌تواند پرداخت را تمام کند. بی‌این
            | خواندنِ تازه، فاکتورِ **پرداخت‌شده** لغو می‌شد.
            */
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($fresh === null
                || $fresh->paid > 0
                || ! in_array($fresh->status, ['unpaid', 'draft'], true)) {
                return false;
            }

            $fresh->forceFill(['status' => 'canceled'])->save();

            Payment::where('invoice_id', $fresh->id)
                ->whereIn('status', ['pending', 'redirected'])
                ->update(['status' => 'canceled', 'updated_at' => now()]);

            if ($rejectPendingReceipt && Schema::hasTable('bank_transfer_receipts')) {
                BankTransferReceipt::where('invoice_id', $fresh->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'rejected', 'reject_reason' => $reason, 'updated_at' => now()]);
            }

            /*
            | ⚠️ خواهرهای پرداخت‌نشدهٔ همین سرویس هم لغو می‌شوند.
            |
            | مدیر می‌تواند برای سرویسی که هنوز پرداخت نشده فاکتورِ دومی صادر کند
            | («یادآوری»). اگر فقط یکی را لغو کنیم و سرویس را بکشیم، دومی هنوز
            | `isPayable()` است و در پنلِ مشتری دکمهٔ پرداخت دارد — پول می‌آید و
            | چون سرویس مرده است هیچ اتفاقی نمی‌افتد.
            */
            if ($fresh->service_id !== null && Schema::hasTable('services')) {
                Invoice::where('service_id', $fresh->service_id)
                    ->where('id', '!=', $fresh->id)
                    ->where('paid', '<=', 0)
                    ->whereIn('status', ['unpaid', 'draft'])
                    ->update(['status' => 'canceled', 'updated_at' => now()]);

                // سرویسِ هنوز-فعال‌نشده → لغو. سرویسِ فعال (تمدید) → دست‌نخورده.
                Service::whereKey($fresh->service_id)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
            }

            /*
            | 🔴 دامنه هم باید آزاد شود، وگرنه آن نام برای **همیشه** قفل می‌مانَد:
            | `domains` قیدِ یکتاییِ (domain, registrar) دارد و `order()` سفارشِ
            | دوباره را رد می‌کند. یعنی مشتری با یک لغو، آن نام را برای خودش **و
            | هر مشتریِ دیگری** می‌سوزاند.
            |
            | ⚠️ فقط ردیفی که هرگز پول نگرفته و هرگز ثبت نشده.
            */
            if ($fresh->domain_id !== null && Schema::hasTable('domains')) {
                Domain::where('id', $fresh->domain_id)
                    ->where('status', 'pending')
                    ->where('provision_status', 'none')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }

            return true;
        });
    }
}
