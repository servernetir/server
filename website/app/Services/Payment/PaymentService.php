<?php

namespace App\Services\Payment;

use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * شروع و تسویهٔ پرداخت.
 *
 * تمام منطقی که «پول را جابه‌جا می‌کند» اینجاست و نه در کنترلر — چون این
 * منطق باید دقیقاً یک بار اجرا شود و کنترلر جای تضمین آن نیست.
 *
 * ═══ سه حمله/حادثه‌ای که این کلاس باید تاب بیاورد ═══
 *
 * ۱) رفرش صفحهٔ بازگشت. کاربر بعد از پرداخت F5 می‌زند. زرین‌پال کد ۱۰۱
 *    («قبلاً تأیید شده») می‌دهد. باید موفق نشان دهیم ولی **دوباره** به
 *    فاکتور اعتبار ندهیم.
 *
 * ۲) دو callback هم‌زمان. مرورگر و یک تب دیگر با هم برمی‌گردند. قفل سطر
 *    پرداخت + بررسی دوبارهٔ وضعیت **داخل** تراکنش، نه بیرونش.
 *
 * ۳) بیش‌پرداخت. تا کاربر در درگاه بود، فاکتور از راه دیگری تسویه شد.
 *    پول را پس نمی‌فرستیم و نادیده هم نمی‌گیریم — به اعتبار حسابش می‌رود.
 */
class PaymentService
{
    /** پنجرهٔ اعتبار یک تلاش پرداخت */
    private const WINDOW_MINUTES = 30;

    public function __construct(private GatewayRegistry $gateways) {}

    /**
     * شروع یک تلاش پرداخت.
     *
     * تلاش‌های نیمه‌کارهٔ قبلی همان فاکتور باطل می‌شوند تا فهرست پرداخت‌ها
     * پر از ردیف‌های سرگردان نشود و مشتری نتواند دو Authority فعال داشته باشد.
     */
    public function begin(Invoice $invoice, string $gatewayKey, Request $request): StartOutcome
    {
        $gateway = $this->gateways->get($gatewayKey);

        if ($gateway === null || ! $gateway->enabled()) {
            return new StartOutcome(false, error: 'این روش پرداخت در دسترس نیست.');
        }

        if (! $invoice->isPayable()) {
            return new StartOutcome(false, error: 'این فاکتور قابل پرداخت نیست.');
        }

        if ($invoice->currency_code !== $gateway->currency()) {
            return new StartOutcome(false, error: 'ارز این فاکتور با این درگاه نمی‌خواند.');
        }

        $amount = $invoice->due();

        if ($amount < $gateway->minimum()) {
            return new StartOutcome(false, error:
                'کمترین مبلغ قابل پرداخت '.number_format($gateway->minimum()).' تومان است.');
        }

        // تلاش‌های باز قبلی را ببند
        Payment::where('invoice_id', $invoice->id)
            ->whereIn('status', ['pending', 'redirected'])
            ->update(['status' => 'canceled', 'updated_at' => now()]);

        $payment = Payment::create([
            'invoice_id'    => $invoice->id,
            'customer_id'   => $invoice->customer_id,
            'gateway'       => $gateway->key(),
            'currency_code' => $invoice->currency_code,
            'amount'        => $amount,
            'status'        => 'pending',
            'expires_at'    => now()->addMinutes(self::WINDOW_MINUTES),
            'ip'            => $request->ip(),
            'user_agent'    => substr((string) $request->userAgent(), 0, 255),
        ]);

        $result = $gateway->start($payment, route('payment.callback', ['gateway' => $gateway->key()]));

        if (! $result->ok) {
            $payment->forceFill([
                'status'        => 'failed',
                'error_code'    => $result->errorCode,
                'error_message' => $result->error,
            ])->save();

            return new StartOutcome(false, error: $result->error);
        }

        $payment->forceFill([
            'status'       => 'redirected',
            'external_ref' => $result->externalRef,
        ])->save();

        return new StartOutcome(true, payment: $payment, redirectUrl: $result->redirectUrl,
            instructions: $result->instructions);
    }

    /**
     * تسویه بعد از بازگشت از درگاه.
     *
     * @param  array<string,mixed>  $callback  پارامترهای بازگشت — داده، نه حکم
     */
    public function settle(Payment $payment, array $callback): SettleOutcome
    {
        // قبلاً تسویه شده؟ هیچ تماس دوباره‌ای با درگاه لازم نیست
        if ($payment->isPaid()) {
            return new SettleOutcome(true, $payment, alreadySettled: true);
        }

        if (! $payment->isVerifiable()) {
            return new SettleOutcome(false, $payment, error:
                'مهلت این پرداخت تمام شده است. اگر مبلغ از حساب شما کم شده، با پشتیبانی تماس بگیرید.');
        }

        $gateway = $this->gateways->get($payment->gateway);

        if ($gateway === null) {
            return new SettleOutcome(false, $payment, error: 'درگاه این پرداخت شناخته نشد.');
        }

        $result = $gateway->verify($payment, $callback);

        if (! $result->paid) {
            $payment->forceFill([
                'status'        => $result->canceled ? 'canceled' : 'failed',
                'error_code'    => $result->errorCode,
                'error_message' => $result->error,
            ])->save();

            return new SettleOutcome(false, $payment, error: $result->error, canceled: $result->canceled);
        }

        return $this->applyPaid($payment, $result);
    }

    /**
     * تسویهٔ پرداختی که ارائه‌دهنده خودش تأیید کرده — بدون verify.
     *
     * برای درگاه‌های وب‌هوکی مثل بله: وقتی SuccessfulPayment می‌رسد، بله
     * قبلاً پول را از کیف پول کاربر برداشته. پس verify معنی ندارد؛ همان
     * رویداد، خودِ تأیید است. همان applyPaid و همان ثبت درآمد استفاده می‌شود
     * تا هیچ منطق تسویهٔ موازی‌ای نباشد.
     */
    public function settleConfirmed(Payment $payment, ?string $refId, ?string $cardMask = null): SettleOutcome
    {
        if ($payment->isPaid()) {
            return new SettleOutcome(true, $payment, alreadySettled: true);
        }

        $outcome = $this->applyPaid($payment, VerifyResult::paid($refId, $cardMask));

        if ($outcome->ok && $outcome->payment !== null) {
            try {
                app(\App\Services\Finance\BusinessLedger::class)->recordPayment($outcome->payment);
            } catch (\Throwable $e) {
                Log::warning('ثبت درآمد بله در دفتر مالی انجام نشد', [
                    'payment' => $outcome->payment->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $outcome;
    }

    /**
     * ثبت پرداخت موفق — تنها جایی که وضعیت فاکتور و اعتبار عوض می‌شود.
     *
     * همه‌چیز داخل یک تراکنش با قفل سطر است. بررسی «قبلاً paid شده؟» عمداً
     * **داخل** قفل تکرار می‌شود: بین بررسی بیرونی و رسیدن به اینجا، یک
     * درخواست موازی می‌تواند همین کار را کرده باشد.
     */
    private function applyPaid(Payment $payment, VerifyResult $result): SettleOutcome
    {
        $outcome = DB::transaction(function () use ($payment, $result) {
            /** @var Payment $fresh */
            $fresh = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($fresh->isPaid()) {
                return new SettleOutcome(true, $fresh, alreadySettled: true);
            }

            $fresh->forceFill([
                'status'    => 'paid',
                'ref_id'    => $result->refId,
                'card_mask' => $result->cardMask,
                'fee'       => $result->fee,
                'fee_type'  => $result->feeType,
                'paid_at'   => now(),
            ])->save();

            /** @var Invoice $invoice */
            $invoice = Invoice::whereKey($fresh->invoice_id)->lockForUpdate()->first();

            $due       = $invoice->due();
            $toInvoice = min($fresh->amount, $due);
            $surplus   = $fresh->amount - $toInvoice;

            if ($toInvoice > 0) {
                $invoice->paid += $toInvoice;

                if ($invoice->due() === 0) {
                    $invoice->status  = 'paid';
                    $invoice->paid_at = now();
                }

                $invoice->save();
            }

            // فاکتور «افزایش اعتبار» خودش پول را به دفتر می‌برد
            if ($invoice->kind === 'topup' && $toInvoice > 0) {
                $this->credit($invoice->customer_id, $invoice->currency_code, $toInvoice,
                    'topup', $invoice, 'افزایش اعتبار با پرداخت آنلاین');
            }

            // فاکتورِ یک سرویس تسویه شد → سرویس فعال/تمدید می‌شود. داخل همین
            // تراکنش، چون مشتری پول داده و سرویس باید حتماً فعال شود — نه
            // best-effort. تمدید سررسید را یک دوره جلو می‌برد.
            if ($invoice->status === 'paid' && $invoice->service_id !== null) {
                $service = \App\Models\Service::whereKey($invoice->service_id)->lockForUpdate()->first();

                // ⚠️ کلِ فعال‌سازی در try است: مشتری پول داده و پرداخت **باید** ثبت
                // شود. اگر این بخش خطا بدهد (مثلاً پس از یک دپلوی، بایت‌کدِ کهنهٔ
                // opcache باعثِ «متدِ ناموجود» شود)، قبلاً کلِ تراکنش برمی‌گشت:
                // ۵۰۰ به مشتری، فاکتور پرداخت‌نشده، سرویس ساخته‌نشده — بدترین حالت.
                // حالا خطا بلعیده و لاگ می‌شود و سرویس دستِ‌کم در صفِ تحویل می‌نشیند.
                try {
                if ($service !== null && $service->status !== 'cancelled') {
                    // سرویسی که روی سروری تحویل می‌شود و هنوز ساخته نشده →
                    // «در انتظار تحویل»؛ کرونِ provision:run آن را می‌سازد و بعد
                    // فعال می‌کند. تمدیدِ سرویسِ ازقبل‌تحویل‌شده یا سرویسِ صرفاً
                    // مالی (بدونِ سرور) مثلِ قبل مستقیم فعال می‌شود.
                    // نیازِ تحویل: سرور دارد (تحویلِ خودکار) یا دامنه دارد (هاست که
                    // هنوز سرور نخورده → تحویلِ دستیِ ادمین). سرویسِ صرفاً مالی
                    // (بدونِ سرور و دامنه، مثلِ پشتیبانی) مستقیم فعال می‌شود.
                    $needsDelivery = $service->provision_status !== 'done'
                        && ($service->server_id !== null || filled($service->domain));
                    if ($needsDelivery) {
                        $service->status = 'awaiting_provision';
                        $service->provision_status = $service->server_id !== null ? 'pending' : 'manual';
                    } else {
                        $service->status = 'active';
                    }
                    $service->activated_at ??= now();
                    if ($service->isRecurring()) {
                        // سررسیدِ بعدی از «پایانِ دورهٔ خریداری‌شده» جلو می‌رود، نه از
                        // لحظهٔ پرداخت. فاکتورِ تمدید چند روز پیش از سررسید صادر
                        // می‌شود؛ اگر از now() حساب می‌کردیم هر دوره چند روز کوتاه‌تر
                        // می‌شد و مشتری در سال بیش از ۱۲ ماه پول می‌داد (روی دورهٔ
                        // شش‌ماهه/سالانه این خطا ۶ و ۱۲ برابر می‌شد).
                        $anchor = $service->next_due_at ?? $service->activated_at ?? now();
                        $next = $service->nextDueFrom($anchor);

                        // اگر سرویس مدتی معلق/عقب‌افتاده بوده، تا آینده جلو می‌آید
                        // (حلقه کران‌دار است چون هر گام حداقل یک ماه جلو می‌رود)
                        $guard = 0;
                        while ($next !== null && $next->isPast() && $guard++ < 120) {
                            $next = $service->nextDueFrom($next);
                        }

                        $service->next_due_at = $next;
                    }
                    $service->save();
                }
                } catch (\Throwable $e) {
                    // پرداخت سرِجایش می‌ماند؛ فقط فعال‌سازی مشکل داشت.
                    Log::error('فعال‌سازیِ سرویس پس از پرداخت خطا داد', [
                        'invoice' => $invoice->id,
                        'service' => $invoice->service_id,
                        'error'   => $e::class.': '.mb_substr($e->getMessage(), 0, 300),
                    ]);

                    // حداقلِ لازم تا کرونِ provision:run سرویس را بسازد و مشتری
                    // معطل نماند — با UPDATEِ خام تا هیچ متد/کستی نتواند باز خطا بدهد.
                    try {
                        if ($invoice->service_id !== null) {
                            // NULL != 'done' در SQL نتیجه‌اش NULL است، نه true —
                            // بدونِ whereNull ردیفِ تازه (با provision_status نال)
                            // هرگز به‌روزرسانی نمی‌شد.
                            $base = fn () => \Illuminate\Support\Facades\DB::table('services')
                                ->where('id', $invoice->service_id)
                                ->whereNotIn('status', ['cancelled'])
                                ->where(fn ($q) => $q->whereNull('provision_status')
                                    ->orWhere('provision_status', '!=', 'done'));

                            // با سرور → صفِ تحویلِ خودکار؛ بی‌سرور → صفِ دستیِ ادمین
                            $base()->whereNotNull('server_id')->update([
                                'status' => 'awaiting_provision', 'provision_status' => 'pending', 'updated_at' => now(),
                            ]);
                            $base()->whereNull('server_id')->update([
                                'status' => 'awaiting_provision', 'provision_status' => 'manual', 'updated_at' => now(),
                            ]);
                        }
                    } catch (\Throwable) {
                    }
                }
            }

            // بیش‌پرداخت: تا کاربر در درگاه بود فاکتور از راه دیگری بسته شد.
            // پول نه برمی‌گردد نه گم می‌شود — به اعتبارش می‌نشیند.
            if ($surplus > 0) {
                $this->credit($fresh->customer_id, $fresh->currency_code, $surplus,
                    'adjustment', $fresh, 'مازاد پرداخت فاکتور '.$invoice->number);

                Log::info('بیش‌پرداخت به اعتبار منتقل شد', [
                    'payment' => $fresh->id, 'surplus' => $surplus,
                ]);
            }

            return new SettleOutcome(true, $fresh, alreadySettled: $result->alreadyVerified);
        });

        // ثبت درآمد در دفتر مالی کسب‌وکار — بیرون از تراکنش پرداخت، چون
        // نباید بتواند تسویه‌ای که موفق شده را برگرداند. idempotent است، پس
        // اگر این settle قبلاً هم اجرا شده باشد، درآمد دوباره ثبت نمی‌شود.
        // نگهبان ready() یعنی اگر جدول هنوز ساخته نشده، پرداخت نمی‌شکند.
        if ($outcome->ok && $outcome->payment !== null) {
            try {
                app(\App\Services\Finance\BusinessLedger::class)->recordPayment($outcome->payment);
            } catch (\Throwable $e) {
                Log::warning('ثبت درآمد در دفتر مالی انجام نشد', [
                    'payment' => $outcome->payment->id, 'error' => $e->getMessage(),
                ]);
            }

            // اعلان تأیید پرداخت — پیامک و بله. فقط تسویهٔ واقعی، نه تکرار
            // (رفرش صفحه یا رویداد دوبارهٔ بله) — alreadySettled این را می‌گیرد.
            if (! $outcome->alreadySettled && ($customer = $outcome->payment->customer) !== null) {
                try {
                    app(\App\Services\Notify\CustomerNotifier::class)->event(
                        $customer,
                        'paid',
                        ['amount' => number_format($outcome->payment->amount)],
                        'پرداخت شما به مبلغ '.number_format($outcome->payment->amount).' تومان با موفقیت ثبت شد.',
                    );
                } catch (\Throwable) {
                    // اعلان هرگز تسویه را نمی‌شکند
                }

                \App\Models\ActivityLog::record($customer->id, 'payment',
                    'پرداخت '.number_format($outcome->payment->amount).' تومان از طریق '
                    .($outcome->payment->gateway ?? '').' انجام شد', null, 'customer');
            }
        }

        return $outcome;
    }

    /** یک سطر دفتر اعتبار با موجودیِ پس از آن */
    private function credit(int $customerId, string $currency, int $amount, string $reason, $source, string $note): void
    {
        $balance = (int) CreditEntry::where('customer_id', $customerId)
            ->where('currency_code', $currency)
            ->sum('amount');

        CreditEntry::create([
            'customer_id'   => $customerId,
            'currency_code' => $currency,
            'amount'        => $amount,
            'balance_after' => $balance + $amount,
            'reason'        => $reason,
            'source_type'   => $source::class,
            'source_id'     => $source->id,
            'note'          => $note,
        ]);
    }
}

final readonly class StartOutcome
{
    public function __construct(
        public bool $ok,
        public ?Payment $payment = null,
        public ?string $redirectUrl = null,
        public ?array $instructions = null,
        public ?string $error = null,
    ) {}
}

final readonly class SettleOutcome
{
    public function __construct(
        public bool $ok,
        public ?Payment $payment = null,
        public ?string $error = null,
        /** پرداخت از قبل تسویه بود — موفق است ولی چیزی دوباره اعمال نشد */
        public bool $alreadySettled = false,
        public bool $canceled = false,
    ) {}
}
