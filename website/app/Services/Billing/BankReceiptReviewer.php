<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BankTransferReceipt;
use App\Models\Payment;
use App\Services\Payment\PaymentService;

/**
 * بررسیِ رسیدِ واریزِ بانکی — **یک** تعریف، دو فراخوان (پنل و رباتِ بله).
 *
 * ⚠️ استخراج شد چون کنسولِ بله هم باید همین کار را بکند، و «همین کار» ساده
 * نیست: یک `Payment` می‌سازد، تسویه می‌کند، سرویس را به صفِ تحویل می‌برد و
 * ممکن است سرور بخرد. نسخهٔ دوم یعنی دو تعریف که روزی واگرا می‌شوند — و یک
 * طرفِ واگرایی، پولِ مشتری است.
 *
 * ═══ 🔴 دروغی که در نسخهٔ قبل بود و این‌جا بسته شد ═══
 *
 * اگر فاکتور دیگر قابلِ پرداخت نبود (لغو یا از قبل تسویه‌شده)، بلوکِ تسویه
 * **رد می‌شد** ولی رسید همچنان `approved` مهر می‌خورد و پیامِ «فاکتور تسویه و
 * سرویس اعمال شد» نشان داده می‌شد — جمله‌ای که صرفاً درست نبود.
 *
 * مسیرِ واقعی‌اش هم دور از ذهن نیست: مشتری ساعتِ ۷۱ پول می‌فرستد و رسید ثبت
 * می‌کند، پشتیبانی ساعتِ ۷۳ به‌خاطرِ شمارهٔ پیگیریِ اشتباه ردش می‌کند، کرونِ
 * انقضا فاکتورِ حالا-بی‌مانع را لغو می‌کند، و بعد کسی رسید را «تأیید» می‌کند.
 * پولِ واقعی رسیده و هیچ‌جا ننشسته.
 *
 * حالا آن حالت **رد** می‌شود و رسید `pending` می‌مانَد تا آدمی تصمیم بگیرد.
 */
class BankReceiptReviewer
{
    /**
     * @return array{ok:bool,message:string}
     */
    public function approve(BankTransferReceipt $receipt, ?int $byUserId = null, $request = null): array
    {
        if (! $receipt->isPending()) {
            return ['ok' => false, 'message' => 'این رسید قبلاً بررسی شده است.'];
        }

        $invoice = $receipt->invoice;

        /*
        | 🔴 فاکتورِ غیرقابلِ‌پرداخت ⇒ **رد**، نه تأییدِ ساکت.
        |
        | «رسید را ببند و فاکتور را دست نزن» یعنی پولی که مشتری واقعاً فرستاده
        | به هیچ فاکتوری نمی‌نشیند و هیچ‌کس هم خبردار نمی‌شود.
        */
        if ($invoice !== null && ! $invoice->isPayable()) {
            return [
                'ok' => false,
                'message' => 'فاکتورِ این رسید دیگر قابلِ پرداخت نیست (لغو یا تسویه‌شده). '
                    .'رسید دست‌نخورده مانْد — تکلیفِ پول را دستی روشن کنید.',
            ];
        }

        if ($invoice !== null) {
            /*
            | ⚠️ `firstOrCreate` و نه `create`: اگر تلاشِ قبلی وسطِ راه شکسته
            | باشد، ردیفِ `Payment` ساخته و کامیت شده ولی رسید هنوز pending
            | مانده. با `create` هر تلاشِ دوباره به یکتاییِ `external_ref`
            | می‌خورد و ۵۰۰ می‌داد — یعنی تأییدِ آن رسید برای همیشه قفل می‌شد.
            */
            $payment = Payment::firstOrCreate(
                ['external_ref' => $receipt->reference],
                [
                    'invoice_id'    => $invoice->id,
                    'customer_id'   => $invoice->customer_id,
                    'gateway'       => 'bank_transfer',
                    'currency_code' => $invoice->currency_code,
                    'amount'        => $invoice->due(),
                    'status'        => 'redirected',
                ],
            );

            // پرداختِ قبلاً تسویه‌شده دوباره تسویه نمی‌شود (اعتبارِ دوباره ندهد)
            if (! $payment->isPaid()) {
                app(PaymentService::class)->settleConfirmed($payment, $receipt->reference);
            }
        }

        $receipt->forceFill([
            'status'      => 'approved',
            'reviewed_by' => $byUserId,
            'reviewed_at' => now(),
        ])->save();

        ActivityLog::record($receipt->customer_id, 'bank_approved',
            'واریز به حساب با شناسهٔ '.$receipt->reference.' تأیید شد', $request, 'staff');

        return ['ok' => true, 'message' => 'واریز تأیید شد؛ فاکتور تسویه و سرویس/اعتبار مربوطه اعمال شد.'];
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function reject(BankTransferReceipt $receipt, string $reason, ?int $byUserId = null, $request = null): array
    {
        if (! $receipt->isPending()) {
            return ['ok' => false, 'message' => 'این رسید قبلاً بررسی شده است.'];
        }

        $receipt->forceFill([
            'status'        => 'rejected',
            'reject_reason' => mb_substr(trim($reason), 0, 190) ?: 'بدونِ توضیح',
            'reviewed_by'   => $byUserId,
            'reviewed_at'   => now(),
        ])->save();

        ActivityLog::record($receipt->customer_id, 'bank_rejected',
            'واریز به حساب با شناسهٔ '.$receipt->reference.' رد شد', $request, 'staff');

        return ['ok' => true, 'message' => 'رسید رد شد.'];
    }
}
