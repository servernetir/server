<?php

namespace App\Services\Billing;

use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Service;

/**
 * بازگشتِ خودکارِ وجه برای سرویسِ «پول‌گرفته و هرگز تحویل‌نشده» در لحظهٔ لغو.
 *
 * ═══ چرا (گزارشِ کارفرما — ۵ شهریور ۱۴۰۵) ═══
 *
 * هاستِ پایتونِ مشتری به‌خاطرِ سرورِ در دسترس‌نبودن تحویل نشد؛ مشتری لغو کرد و
 * مدیر مجبور شد **دستی** سرویس را لغو و **دستی** اعتبار بدهد. «چرا اتوماتیک
 * ریفاند نداریم؟»
 *
 * ═══ چرا در لحظهٔ «لغو» و نه لحظهٔ «شکستِ تحویل» ═══
 *
 * درسِ ثبت‌شدهٔ zhina.shop: خیلی از شکست‌های تحویل «نشنیدیم» هستند نه «نه» —
 * حساب آن‌طرف واقعاً ساخته شده و `provision:verify-failed` بعداً حلش می‌کند.
 * ریفاند در لحظهٔ شکست یعنی جبرانِ دوباره برای سرویسی که چند دقیقه بعد تحویل
 * می‌شود. لغو (تصمیمِ صریحِ مشتری یا مدیر) نقطهٔ برگشت‌ناپذیرِ درست است.
 *
 * ═══ قواعدِ پول ═══
 *
 * - فقط سرویسی که **هرگز** تحویل نشده: provision_status در
 *   {null, '', none, pending, failed, manual}. done/running/releasing یعنی
 *   چیزی آن‌طرف هست یا در جریان است — تصمیمِ مدیر، نه ریفاندِ خودکار.
 * - سرویسِ ساعتی (کیفِ پولی) جداست: پولش از اول در کیفِ پول است و متر فقط
 *   ساعتِ تحویل‌شده را کم می‌کند؛ مسیرِ خودش (cloud_hourly_refund) را دارد.
 * - مبلغ = `paid`ِ فاکتورهای همان سرویس، به همان ارز. هیچ عددِ حدسی.
 * - idempotent با ردیفِ CreditEntry به ازای هر فاکتور (reason=undelivered_refund،
 *   source=Invoice) — دو بار لغو/دو اجرای هم‌زمان، دو بار برنمی‌گردانَد.
 * - فاکتور «refunded» می‌خورَد و در دفترِ مالی recordInvoiceRefund می‌نشیند —
 *   وگرنه گزارشِ مالی درآمدی را نشان می‌دهد که برگشته.
 */
class UndeliveredRefund
{
    public const REASON = 'undelivered_refund';

    private const UNDELIVERED = [null, '', 'none', 'pending', 'failed', 'manual'];

    /** آیا این سرویس مشمولِ بازگشتِ خودکار است؟ (بدونِ هیچ نوشتنی) */
    public function eligible(Service $service): bool
    {
        if ($service->billing_mode === 'hourly') {
            return false;
        }

        return in_array($service->provision_status, self::UNDELIVERED, true);
    }

    /**
     * بازگشتِ وجهِ فاکتورهای پرداخت‌شدهٔ سرویسِ تحویل‌نشده به کیفِ پول.
     *
     * @return int جمعِ مبلغِ برگشتی (واحدِ فرعیِ همان ارزها؛ برای گزارشِ متنی)
     */
    public function maybeRefund(Service $service, string $actor = 'system'): int
    {
        if (! $this->eligible($service) || $service->customer === null) {
            return 0;
        }

        $total = 0;

        $invoices = Invoice::query()
            ->where('service_id', $service->id)
            ->where('paid', '>', 0)
            ->whereNotIn('status', ['refunded'])
            ->get();

        foreach ($invoices as $invoice) {
            $amount = (int) $invoice->paid;

            if ($amount <= 0) {
                continue;
            }

            // idempotency روی خودِ دیتابیس، نه «قبلاً چک کردم»
            $already = CreditEntry::where('reason', self::REASON)
                ->where('source_type', Invoice::class)
                ->where('source_id', $invoice->id)
                ->exists();

            if ($already) {
                continue;
            }

            $currency = (string) ($invoice->currency_code ?: 'IRT');

            CreditEntry::create([
                'customer_id'   => $service->customer_id,
                'currency_code' => $currency,
                'amount'        => $amount,
                'balance_after' => $service->customer->creditBalance($currency) + $amount,
                'reason'        => self::REASON,
                'source_type'   => Invoice::class,
                'source_id'     => $invoice->id,
                'note'          => 'بازگشتِ خودکار — سرویسِ #'.$service->id.' («'.$service->name.'») تحویل نشد و لغو شد.',
            ]);

            $invoice->forceFill(['status' => 'refunded'])->save();

            try {
                app(\App\Services\Finance\BusinessLedger::class)
                    ->recordInvoiceRefund($invoice, $amount,
                        'بازگشتِ خودکارِ سرویسِ تحویل‌نشدهٔ #'.$service->id);
            } catch (\Throwable) {
                // دفترداری نباید بازگشتِ پولِ مشتری را بشکند؛ ردش در CreditEntry هست
            }

            $total += $amount;
        }

        if ($total > 0) {
            try {
                \App\Models\ActivityLog::forService($service, 'terminate',
                    __('ui.act_auto_refund', ['amount' => number_format($total)], $service->customer?->locale ?: 'fa'), $actor);
            } catch (\Throwable) {
            }

            try {
                $c = $service->customer;
                app()->setLocale($c->locale ?: 'fa');
                app(\App\Services\Notify\CustomerNotifier::class)->templated(
                    $c, 'undelivered_refund', ['service' => $service->name],
                    'سرویسِ «'.$service->name.'» تحویل نشد و لغو شد؛ مبلغِ پرداختی به‌صورت خودکار به کیفِ پولِ شما برگشت.'
                );
            } catch (\Throwable) {
            } finally {
                app()->setLocale(config('app.locale'));
            }
        }

        return $total;
    }
}
