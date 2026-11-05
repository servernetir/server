<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BankTransferReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;

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
        // A stale panel/bot request must not review the same receipt twice.
        return DB::transaction(function () use ($receipt, $byUserId, $request) {
            $receipt = BankTransferReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            return $this->approveLocked($receipt, $byUserId, $request);
        });
    }

    private function approveLocked(BankTransferReceipt $receipt, ?int $byUserId, $request): array
    {
        if (! $receipt->isPending()) {
            return ['ok' => false, 'message' => 'این رسید قبلاً بررسی شده است.'];
        }

        $invoice = Invoice::whereKey($receipt->invoice_id)->lockForUpdate()->first();

        if ($invoice === null || (int) $invoice->customer_id !== (int) $receipt->customer_id) {
            return ['ok' => false, 'message' => 'فاکتور معتبر و متعلق به مشتری این رسید پیدا نشد؛ رسید تأیید نشد.'];
        }

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

        if ((int) $receipt->amount <= 0 || (int) $receipt->amount !== $invoice->due()) {
            return ['ok' => false, 'message' => 'مبلغ رسید با ماندهٔ فاکتور برابر نیست؛ رسید برای تطبیق دستی باز ماند.'];
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

            // A reference can already belong to another invoice or gateway.
            // Never settle that payment, or treat its paid status as this receipt's success.
            if ((int) $payment->invoice_id !== (int) $invoice->id
                || (int) $payment->customer_id !== (int) $receipt->customer_id
                || $payment->gateway !== 'bank_transfer'
                || $payment->currency_code !== $invoice->currency_code
                || (int) $payment->amount !== (int) $receipt->amount) {
                return ['ok' => false, 'message' => 'شناسهٔ پیگیری این رسید با پرداخت دیگری تعارض دارد؛ فاکتور و اعتبار تغییر نکرد و رسید تأیید نشد.'];
            }

            // پرداختِ قبلاً تسویه‌شده دوباره تسویه نمی‌شود (اعتبارِ دوباره ندهد)
            if (! $payment->isPaid()) {
                $outcome = app(PaymentService::class)->settleConfirmed($payment, $receipt->reference);

                if (! $outcome->ok) {
                    return ['ok' => false, 'message' => 'تسویهٔ این پرداخت تأیید نشد؛ رسید برای بررسی باز ماند.'];
                }
            }

            if (! $payment->fresh()->isPaid() || $invoice->fresh()->status !== 'paid') {
                return ['ok' => false, 'message' => 'تسویهٔ فاکتور این رسید کامل نشده است؛ رسید تأیید نشد.'];
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
