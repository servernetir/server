<?php

namespace App\Console\Commands;

use App\Models\SnappPayOrder;
use App\Services\Payment\PaymentService;
use App\Services\Payment\SnappPay\SnappPayGateway;
use Illuminate\Console\Command;

/**
 * سفارش‌های نیمه‌کارهٔ اسنپ‌پی را تمام می‌کند.
 *
 * ═══ چرا این کرون اجباری است ═══
 *
 * 🔴 مستنداتِ اسنپ‌پی: «پیاده‌سازی سرویس Get Payment Status طبق توضیحات
 * داکیومنت به صورت **خودکار در کد** به دلیل عدم مغایرت بین اسنپ‌پی و پذیرنده
 * الزامی می‌باشد.»
 *
 * دلیلش هم روشن است: مشتری وسطِ بازگشت مرورگرش را می‌بندد، یا تماسِ verify
 * تایم‌اوت می‌خورد، یا settle نصفه می‌مانَد. در همهٔ این حالت‌ها **پول از
 * حسابِ مشتری کم شده** و سفارش نزدِ ما `pending` یا `verified` مانده. بدونِ
 * این کرون، آن پول یا برمی‌گردد (اگر verify نشود) یا معلق می‌مانَد (اگر
 * settle نشود) — و هیچ‌کدام خطایی تولید نمی‌کنند.
 *
 * ⚠️ سفارشِ `pending` هم برداشته می‌شود، نه فقط `verified`: ممکن است مشتری
 * اصلاً به صفحهٔ بازگشت نرسیده باشد. اگر اسنپ‌پی بگوید پرداخت نشده، همان
 * پاسخ سفارش را می‌بندد و چیزی از دست نمی‌رود.
 */
class SnappPayReconcile extends Command
{
    protected $signature = 'snapppay:reconcile
                            {--minutes=10 : فقط سفارش‌هایی که دست‌کم این‌قدر از شروعشان گذشته}
                            {--limit=50 : سقفِ هر اجرا}';

    protected $description = 'پیگیریِ خودکارِ سفارش‌های نیمه‌کارهٔ اسنپ‌پی (verify/settle/status)';

    public function handle(SnappPayGateway $gateway, PaymentService $payments): int
    {
        if (! $gateway->enabled()) {
            $this->line('اسنپ‌پی فعال نیست — کاری نیست.');

            return self::SUCCESS;
        }

        /*
        | ⚠️ فاصلهٔ زمانی عمدی است: سفارشی که همین الان شروع شده احتمالاً
        | کاربرش هنوز روی صفحهٔ اسنپ‌پی است. پرسیدنِ وضعیتش نه چیزی را حل
        | می‌کند نه چیزی را خراب، ولی بی‌جهت به API فشار می‌آورد.
        */
        $orders = SnappPayOrder::whereIn('state', ['pending', 'verified'])
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($orders->isEmpty()) {
            $this->line('سفارشِ معلقی نیست.');

            return self::SUCCESS;
        }

        $settled = 0;
        $closed = 0;

        foreach ($orders as $order) {
            $token = $order->token();

            if ($token === null || $token === '') {
                // بدونِ توکن هیچ تماسی ممکن نیست؛ ردیف را می‌بندیم تا هر بار برداشته نشود
                $order->forceFill(['state' => 'failed', 'error' => 'توکنِ پرداخت ثبت نشده'])->save();
                $order->note('status', false, 'بدونِ توکن');
                $closed++;

                continue;
            }

            try {
                $result = $gateway->finalize($order, $token);
            } catch (\Throwable $e) {
                \App\Support\ErrorTracker::note('payment', $e,
                    ['area' => 'snapppay-reconcile', 'order' => $order->id]);

                continue;
            }

            if (! $result->paid) {
                $this->line("سفارش #{$order->id} هنوز نهایی نشد ({$order->fresh()->state}).");

                continue;
            }

            /*
            | 🔴 نهایی‌شدن نزدِ اسنپ‌پی کافی نیست — فاکتور هم باید تسویه شود،
            | وگرنه مشتری قسط می‌دهد و سرویسش فعال نمی‌شود. همان مسیرِ رسمیِ
            | `settleConfirmed` صدا زده می‌شود تا فعال‌سازیِ سرویس و ثبتِ درآمد
            | در دفتر هم از یک جا انجام شود (قاعدهٔ «منطقِ تسویهٔ موازی ممنوع»).
            */
            $payment = $order->payment;

            if ($payment !== null && ! $payment->isPaid()) {
                $payments->settleConfirmed($payment, $order->transaction_id);
            }

            $settled++;
            $this->info("سفارش #{$order->id} نهایی شد ({$order->transaction_id}).");
        }

        $this->line("بررسی‌شده: {$orders->count()} · نهایی‌شده: {$settled} · بسته‌شده: {$closed}");

        return self::SUCCESS;
    }
}
