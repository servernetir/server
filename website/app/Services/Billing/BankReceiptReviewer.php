<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BankTransferReceipt;
use App\Models\Payment;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
    /** @return array{ok:bool,message:string} */
    public function approve(BankTransferReceipt $receipt, ?int $byUserId = null, $request = null): array
    {
        return $this->review($receipt, $byUserId, $request, false);
    }

    /** Explicit, single-receipt recovery of the historical reference collision. */
    public function reconcileApproved(BankTransferReceipt $receipt): array
    {
        return $this->review($receipt, null, null, true);
    }

    /** The external bank reference is evidence, never the identity of a new payment. */
    public static function paymentKey(BankTransferReceipt $receipt): string
    {
        return 'bank-receipt:'.$receipt->id;
    }

    private function matches(Payment $payment, BankTransferReceipt $receipt, Invoice $invoice): bool
    {
        return $payment->gateway === 'bank_transfer'
            && (int) $payment->invoice_id === (int) $invoice->id
            && (int) $payment->customer_id === (int) $receipt->customer_id
            && $payment->currency_code === $invoice->currency_code
            && (int) $payment->amount === (int) $receipt->amount;
    }

    private function assertValid(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['receipt' => $message]);
        }
    }

    private function review(BankTransferReceipt $input, ?int $byUserId, $request, bool $repair): array
    {
        try {
            return DB::transaction(function () use ($input, $byUserId, $request, $repair) {
                // Re-read under lock: two reviewers (including Bale) cannot settle twice.
                $receipt = BankTransferReceipt::whereKey($input->id)->lockForUpdate()->firstOrFail();
                $this->assertValid($repair ? $receipt->status === 'approved' : $receipt->isPending(),
                    'این رسید قبلاً بررسی شده است یا قابل تطبیق نیست.');
                $invoice = Invoice::whereKey($receipt->invoice_id)->lockForUpdate()->first();
                $this->assertValid($invoice !== null && (int) $invoice->customer_id === (int) $receipt->customer_id,
                    'فاکتور معتبرِ متعلق به صاحب رسید پیدا نشد؛ رسید اعمال نشد.');
                $this->assertValid((int) $receipt->amount > 0, 'مبلغ رسید معتبر نیست؛ نیازمند بررسی است.');

                $payment = Payment::where('external_ref', self::paymentKey($receipt))->lockForUpdate()->first();
                $legacy = Payment::where('external_ref', $receipt->reference)->lockForUpdate()->first();
                if ($repair) {
                    // No mass replay, no already-paid invoice, no uncertain same-invoice money.
                    $this->assertValid($invoice->isPayable() && $payment === null && $legacy !== null
                        && $legacy->isPaid() && (int) $legacy->invoice_id !== (int) $invoice->id,
                        'این رسید شرایط ترمیمِ برخورد شناسه را ندارد؛ تطبیق دستی لازم است.');
                }
                if ($payment === null && $legacy !== null
                    && (int) $legacy->invoice_id === (int) $invoice->id) {
                    // Preserve unfinished payments created by the old implementation.
                    $this->assertValid($this->matches($legacy, $receipt, $invoice),
                        'پرداخت قبلی با مشخصات رسید مطابقت ندارد؛ تطبیق دستی لازم است.');
                    $payment = $legacy;
                }
                if ($payment !== null) {
                    $this->assertValid($this->matches($payment, $receipt, $invoice),
                        'پرداخت با فاکتور، مشتری، ارز یا مبلغ رسید مطابقت ندارد.');
                }
                if ($payment?->isPaid()) {
                    // A prior partial attempt may have committed settlement before receipt approval.
                    $this->assertValid($invoice->status === 'paid' && $invoice->due() === 0,
                        'پرداخت موفق است اما فاکتور تسویه نیست؛ تطبیق دستی لازم است.');
                } else {
                    $this->assertValid($invoice->isPayable(),
                        'فاکتور این رسید دیگر قابل پرداخت نیست؛ رسید دست‌نخورده ماند.');
                    $this->assertValid($invoice->due() === (int) $receipt->amount,
                        'مانده فاکتور با مبلغ رسید برابر نیست؛ مبلغ را پیش از تأیید تطبیق دهید.');
                    $payment ??= Payment::create([
                        'external_ref' => self::paymentKey($receipt),
                        'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
                        'gateway' => 'bank_transfer', 'currency_code' => $invoice->currency_code,
                        'amount' => (int) $receipt->amount, 'status' => 'redirected',
                    ]);
                    $outcome = app(PaymentService::class)->settleConfirmed($payment, $receipt->reference);
                    $this->assertValid($outcome->ok && $payment->fresh()->isPaid()
                        && $invoice->fresh()->status === 'paid' && $invoice->fresh()->due() === 0,
                        'تسویه فاکتور تأیید نشد؛ رسید تأیید نشد و نیازمند بررسی است.');
                }
                if (! $repair) {
                    $receipt->forceFill([
                        'status' => 'approved', 'reviewed_by' => $byUserId, 'reviewed_at' => now(),
                    ])->save();
                }
                ActivityLog::record($receipt->customer_id, $repair ? 'bank_reconciled' : 'bank_approved',
                    'تسویه رسید #'.$receipt->id.' با پرداخت #'.$payment->id.' و فاکتور #'.$invoice->id,
                    $request, $repair ? 'system' : 'staff');

                // Payment confirmation does not claim a completed provider renewal/provisioning.
                return ['ok' => true, 'message' => 'واریز ثبت و فاکتور تسویه شد؛ وضعیت تحویل یا تمدید را در سرویس پیگیری کنید.'];
            });
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => (string) collect($e->errors())->flatten()->first()];
        }
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public function reject(BankTransferReceipt $receipt, string $reason, ?int $byUserId = null, $request = null): array
    {
        return DB::transaction(function () use ($receipt, $reason, $byUserId, $request) {
            $receipt = BankTransferReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if (! $receipt->isPending()) {
                return ['ok' => false, 'message' => 'این رسید قبلاً بررسی شده است.'];
            }
            $receipt->forceFill([
                'status' => 'rejected',
                'reject_reason' => mb_substr(trim($reason), 0, 190) ?: 'بدونِ توضیح',
                'reviewed_by' => $byUserId, 'reviewed_at' => now(),
            ])->save();
            ActivityLog::record($receipt->customer_id, 'bank_rejected',
                'واریز به حساب با شناسهٔ '.$receipt->reference.' رد شد', $request, 'staff');
            return ['ok' => true, 'message' => 'رسید رد شد.'];
        });
    }
}
