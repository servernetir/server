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
                if ($service !== null && ! $service->isDead()) {
                    // سرویسی که روی سروری تحویل می‌شود و هنوز ساخته نشده →
                    // «در انتظار تحویل»؛ کرونِ provision:run آن را می‌سازد و بعد
                    // فعال می‌کند. تمدیدِ سرویسِ ازقبل‌تحویل‌شده یا سرویسِ صرفاً
                    // مالی (بدونِ سرور) مثلِ قبل مستقیم فعال می‌شود.
                    // نیازِ تحویل: سرور دارد (تحویلِ خودکار)، **پلنِ ابری دارد**
                    // (تحویلِ خودکار با API)، یا دامنه دارد (هاست که هنوز سرور
                    // نخورده → تحویلِ دستیِ ادمین). سرویسِ صرفاً مالی (بدونِ
                    // هیچ‌کدام، مثلِ پشتیبانی) مستقیم فعال می‌شود.
                    //
                    // 🔴 این‌جا سه بار به یک تله خوردیم و هر بار **بی‌صدا**:
                    // کد فرض می‌کرد «مقصدِ تحویل = server_id». سرورِ ابری پیش از
                    // خرید وجود ندارد، پس نه server_id دارد نه دامنه — و همین
                    // شرط، سرویس را مستقیم `active` می‌کرد با provision_status
                    // نال. کرونِ تحویل هم فقط `pending` را برمی‌دارد. نتیجه:
                    // مشتری پول می‌داد، هیچ خطایی در هیچ لاگی نبود، و سرور
                    // **هرگز** ساخته نمی‌شد.
                    //
                    // هر مسیرِ تحویلِ تازه‌ای که اضافه شد، باید **هر سه جا** به‌روز
                    // شود: همین شرط، UPDATEِ خامِ داخلِ catch پایین، و پرس‌وجوی
                    // کرون در RunProvisioning.
                    $autoDelivered = $service->server_id !== null || $service->isCloud();

                    $needsDelivery = $service->provision_status !== 'done'
                        && ($autoDelivered || filled($service->domain));
                    if ($needsDelivery) {
                        $service->status = 'awaiting_provision';
                        $service->provision_status = $autoDelivered ? 'pending' : 'manual';
                    } else {
                        $service->status = 'active';
                    }
                    // پیش از ثبتِ activated_at می‌سنجیم: اگر از قبل فعال بوده،
                    // این پرداخت «تمدید» است؛ وگرنه «فعال‌سازیِ اولِ» خرید.
                    $wasActivated = $service->activated_at !== null;
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

                        // شمارندهٔ یادآوری برای دورهٔ تازه صفر می‌شود، وگرنه
                        // «۱ روز مانده»ِ دورهٔ قبل جلوی یادآوریِ دورهٔ بعد را
                        // می‌گرفت. تعلیقِ خودکار هم با پرداخت برداشته می‌شود
                        // (رفعِ تعلیق روی سرور را کرونِ services:lifecycle می‌زند).
                        if (\Illuminate\Support\Facades\Schema::hasColumn('services', 'reminder_stage')) {
                            $service->reminder_stage = null;
                        }
                    }
                    $service->save();

                    // لاگِ سرویس‌محور: خرید (فعال‌سازیِ اول) یا تمدید. actor از
                    // زمینه: اگر مشتری در نشست باشد 'customer'، وگرنه (تمدیدِ
                    // خودکار از اعتبار/کرون) 'system'.
                    try {
                        \App\Models\ActivityLog::forService($service,
                            $wasActivated ? 'renew' : 'purchase',
                            $wasActivated
                                ? 'سرویس با پرداختِ فاکتور تمدید شد'.($service->next_due_at ? ' (سررسیدِ بعدی: '.$service->next_due_at->format('Y-m-d').')' : '')
                                : 'سرویس با پرداختِ فاکتور فعال شد',
                            auth('customer')->check() ? 'customer' : 'system');
                    } catch (\Throwable) {
                        // لاگ نباید تسویه را بشکند
                    }
                }
                } catch (\Throwable $e) {
                    // پرداخت سرِجایش می‌ماند؛ فقط فعال‌سازی مشکل داشت.
                    Log::error('فعال‌سازیِ سرویس پس از پرداخت خطا داد', [
                        'invoice' => $invoice->id,
                        'service' => $invoice->service_id,
                        'error'   => $e::class.': '.mb_substr($e->getMessage(), 0, 300),
                    ]);

                    // ⚠️ `laravel.log` روی cPanel عملاً خواندنی نیست (نه SSH
                    // داریم نه نمایشگری در پنل). این خط همان رویداد را به
                    // ردیابِ خطا هم می‌دهد تا در /admin/errors دیده شود —
                    // وگرنه «پول گرفته شد، سرویس فعال نشد» جایی ثبت می‌شد که
                    // هیچ‌کس نمی‌بیند.
                    \App\Support\ErrorTracker::note('payment', $e, [
                        'invoice' => $invoice->id,
                        'service' => $invoice->service_id,
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
                                ->whereNotIn('status', \App\Models\Service::DEAD_STATUSES)
                                ->where(fn ($q) => $q->whereNull('provision_status')
                                    ->orWhere('provision_status', '!=', 'done'));

                            // تحویلِ خودکار (سرورِ خودمان **یا** سرورِ ابری) → صفِ
                            // کرون؛ بقیه → صفِ دستیِ ادمین.
                            //
                            // ⚠️ اگر ستونِ ابری روی این نصب نباشد (مهاجرتِ نزده)،
                            // پرس‌وجوی خام خطا می‌دهد و **هیچ** ردیفی به‌روز
                            // نمی‌شود — یعنی همان خرابیِ بی‌صدا از راهِ دیگر. پس
                            // اول وجودِ ستون را می‌سنجیم.
                            $hasCloud = \Illuminate\Support\Facades\Schema::hasColumn('services', 'cloud_plan_id');

                            $base()->where(function ($q) use ($hasCloud) {
                                $q->whereNotNull('server_id');

                                if ($hasCloud) {
                                    $q->orWhereNotNull('cloud_plan_id');
                                }
                            })->update([
                                'status' => 'awaiting_provision', 'provision_status' => 'pending', 'updated_at' => now(),
                            ]);

                            $manual = $base()->whereNull('server_id');

                            if ($hasCloud) {
                                $manual->whereNull('cloud_plan_id');
                            }

                            $manual->update([
                                'status' => 'awaiting_provision', 'provision_status' => 'manual', 'updated_at' => now(),
                            ]);
                        }
                    } catch (\Throwable) {
                    }
                }
            }

            /*
            | فاکتورِ **دامنه** تسویه شد → دامنه به صفِ ثبت می‌رود.
            |
            | 🔴 این‌جا هیچ تماسِ شبکه‌ای انجام نمی‌شود و این عمدی است. اگر ثبت را
            | داخلِ همین تراکنش می‌گذاشتیم، یک کندیِ رجیسترار باعثِ timeoutِ
            | وب‌هوکِ درگاه می‌شد، درگاه پرداخت را ناموفق فرض می‌کرد و پول
            | برمی‌گشت — در حالی که دامنه ثبت شده است. همان درسی که
            | `provision:run` برای سرور داد.
            |
            | فقط پرچمِ وضعیت زده می‌شود؛ کرونِ `domains:provision` کارِ واقعی را
            | می‌کند و خودش هم قفلِ اتمی و استعلامِ «قبلاً ثبت شده؟» دارد.
            |
            | ⚠️ ستون `domain_id` روی نصبِ مهاجرت‌نخورده وجود ندارد. بدونِ این
            | سنجش، هر پرداختِ **سرویس** هم با «ستون ناموجود» می‌ترکید — یعنی
            | یک قابلیتِ تازه، مسیرِ پولِ موجود را می‌خواباند.
            */
            try {
                $hasDomainCol = \Illuminate\Support\Facades\Schema::hasColumn('invoices', 'domain_id');

                if ($hasDomainCol && $invoice->status === 'paid' && $invoice->domain_id !== null) {
                    /*
                    | 🔴 فاکتورِ **تمدید** — مسیری که کاملاً وجود نداشت.
                    |
                    | دامنهٔ فعال `provision_status='done'` دارد، پس شرطِ زیر
                    | (`none` → `pending`) هرگز به آن نمی‌خورد و پرداختِ تمدید
                    | بی‌صدا هیچ کاری نمی‌کرد: پول گرفته می‌شد، فاکتور «پرداخت‌شده»
                    | می‌شد، و دامنه همچنان منقضی می‌شد.
                    |
                    | ⚠️ صفِ تمدید و صفِ ثبت با `status` از هم جدا می‌شوند
                    | (`active` در برابرِ `pending`)، پس `domains:provision` این
                    | ردیف را برنمی‌دارد و دامنه دوباره **خریده** نمی‌شود.
                    |
                    | ⚠️ `done` → `pending` و نه هر وضعیتی: اگر تمدیدی در جریان
                    | باشد (`running`) یا در صفِ دستی (`manual`)، یک پرداختِ دوم
                    | روی همان فاکتور نباید قفل را باز کند.
                    */
                    \Illuminate\Support\Facades\DB::table('domains')
                        ->where('id', $invoice->domain_id)
                        ->where('status', 'active')
                        ->where('provision_status', 'done')
                        ->update([
                            'provision_status' => 'pending',
                            'provision_tries'  => 0,
                            'updated_at'       => now(),
                        ]);

                    \Illuminate\Support\Facades\DB::table('domains')
                        ->where('id', $invoice->domain_id)
                        ->whereNotIn('status', \App\Models\Domain::DEAD_STATUSES)
                        /*
                        | 🔴 فقط از `none` به `pending`. عمداً `!= 'done'` نیست.
                        |
                        | یک پرداختِ دوم روی همان فاکتور (بیش‌پرداخت، تلاشِ دوبارهٔ
                        | درگاه، یا وب‌هوکِ تکراری) با شرطِ قبلی وضعیتِ `running` را
                        | به `pending` برمی‌گرداند — یعنی درست وسطِ ثبت، قفلِ اتمی
                        | باز می‌شود و اجرای بعدیِ کرون **همان دامنه را دوباره
                        | می‌خرد**. و `manual` را هم از صفِ آدم بیرون می‌کشید و به
                        | کرون برمی‌گرداند، که همان دامنهٔ مشکل‌دار را بی‌پایان
                        | تلاش می‌کرد.
                        */
                        ->where('provision_status', 'none')
                        ->update([
                            'provision_status' => 'pending',
                            'updated_at'       => now(),
                        ]);
                }
            } catch (\Throwable $e) {
                // پرداخت سرِجایش می‌ماند؛ فقط صف‌گذاری مشکل داشت.
                \App\Support\ErrorTracker::note('payment', $e, [
                    'invoice' => $invoice->id,
                    'domain'  => $invoice->domain_id ?? null,
                ]);
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

                // اعلان به **مدیر**: پول رسید. بیرونِ تراکنش و در try، تا خطای
                // بله/SMTP نتواند تسویه‌ای که موفق شده را برگرداند.
                try {
                    $inv = $outcome->payment->invoice;

                    app(\App\Services\Notify\AdminNotifier::class)->event('پرداختِ موفق', [
                        'مشتری'  => $customer->displayName().' ('.$customer->code.')',
                        'مبلغ'   => fa_num(number_format((int) $outcome->payment->amount)).' تومان',
                        'درگاه'  => $outcome->payment->gateway,
                        'فاکتور' => $inv?->number,
                        'بابت'   => $inv?->service?->name ?? ($inv?->kind === 'topup' ? 'افزایش اعتبار' : null),
                        'پیگیری' => $outcome->payment->ref_id,
                    ], url('/admin/customers/'.$customer->id), '💰');
                } catch (\Throwable) {
                }
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
