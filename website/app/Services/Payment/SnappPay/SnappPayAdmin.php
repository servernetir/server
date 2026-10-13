<?php

namespace App\Services\Payment\SnappPay;

use App\Models\Invoice;
use App\Models\SnappPayOrder;
use App\Models\SnappPayOrderEvent;
use App\Services\Finance\BusinessLedger;
use Illuminate\Support\Facades\DB;

/**
 * کارهایی که **ادمین** روی یک سفارشِ اسنپ‌پی انجام می‌دهد.
 *
 * ═══ چرا این‌ها از درگاه جدا هستند ═══
 *
 * `SnappPayGateway` مسیرِ پول است و خودکار اجرا می‌شود. این‌جا دو عملِ
 * **برگشت‌ناپذیر** است که فقط با تصمیمِ آدم انجام می‌شوند و مستنداتِ اسنپ‌پی
 * برایشان تأییدیهٔ صریح خواسته:
 *
 *   update — کم‌کردنِ آیتم/مبلغ از سفارشِ نهایی‌شده
 *   cancel — لغوِ کاملِ سفارشِ نهایی‌شده
 *
 * ═══ قاعده‌ای که کارفرما تعیین کرد ═══
 *
 * 🔴 بازگشتِ وجه **فقط** از مسیرِ اسنپ‌پی. بدهیِ مشتری نزدِ اسنپ‌پی کم
 * می‌شود و هیچ اعتباری در سرورنت داده نمی‌شود. دادنِ هر دو یعنی مشتری دو
 * بار پول می‌گیرد — و چون دو سیستمِ جدا هستند، هیچ‌کدام خطا نمی‌دهد.
 *
 * ⚠️ ولی «اعتبار نمی‌دهیم» به معنیِ «ثبت نمی‌کنیم» نیست: پولی که برگشته
 * دیگر درآمدِ ما نیست و باید در دفتر بنشیند، وگرنه سود بیش‌برآورد می‌مانَد.
 *
 * ═══ چرا منبعِ ردیفِ دفتر، رویداد است نه فاکتور ═══
 *
 * 🔴 اسنپ‌پی می‌خواهد یک سفارش **چند بار** کاهش بخورد و بعد هم لغو شود.
 * کلیدِ یکتای دفتر (منبع، نوع) است؛ با منبعِ فاکتور، دومین بازگشت بی‌صدا
 * بلعیده می‌شد. هر عملِ ادمین ردیفِ `snapppay_order_events` خودش را دارد،
 * پس همان منبعِ درست است.
 */
class SnappPayAdmin
{
    public function __construct(
        private SnappPayClient $client,
        private SnappPayCart $cart,
        private BusinessLedger $ledger,
    ) {}

    /**
     * استعلامِ وضعیت — تنها عملِ بی‌خطرِ این کلاس.
     *
     * @return array{ok:bool,status:?string,message:?string}
     */
    public function refreshStatus(SnappPayOrder $order, ?int $userId = null): array
    {
        $token = $order->token();

        if ($token === null || $token === '') {
            return ['ok' => false, 'status' => null, 'message' => 'این سفارش توکنِ پرداخت ندارد.'];
        }

        $st = $this->client->status($token);

        $order->forceFill([
            'remote_status' => $st['status'],
            'checked_at'    => now(),
        ])->save();

        $order->note('status', (bool) $st['ok'], $st['status'] ?: $st['error'], null, $userId);

        return [
            'ok'      => (bool) $st['ok'],
            'status'  => $st['status'],
            'message' => $st['ok'] ? null : ($st['error'] ?: 'استعلام انجام نشد.'),
        ];
    }

    /**
     * لغوِ کاملِ سفارش.
     *
     * @return array{ok:bool,message:string}
     */
    public function cancel(SnappPayOrder $order, ?int $userId = null): array
    {
        if (! $order->isChangeable()) {
            return ['ok' => false, 'message' => 'فقط سفارشِ نهایی‌شده (settled) قابلِ لغو است.'];
        }

        $res = $this->client->cancel($order->token());

        if (! $res['ok']) {
            $order->note('cancel', false, $res['error'], null, $userId);

            return ['ok' => false, 'message' => $this->explain($res['error'])];
        }

        $invoice = $order->invoice;
        // آنچه **الان** نزدِ ما پرداخت‌شده است — پس از کاهش‌های قبلی، نه مبلغِ اولیه
        $refunded = (int) ($invoice?->paid ?? $order->amount);

        DB::transaction(function () use ($order, $invoice) {
            $order->forceFill([
                'state'         => 'canceled',
                'canceled_at'   => now(),
                'remote_status' => 'CANCEL',
            ])->save();

            if ($invoice !== null) {
                /*
                | فاکتور «بازگشتی» می‌شود، نه «پرداخت‌نشده».
                |
                | 🔴 برگرداندنش به unpaid یعنی کرونِ انقضا یا خودِ مشتری
                | دوباره تلاش به پرداخت کند — روی سفارشی که نزدِ اسنپ‌پی مرده.
                */
                $invoice->forceFill(['status' => 'refunded'])->save();
            }
        });

        // رویداد **اول** ثبت می‌شود تا منبعِ یکتای ردیفِ دفتر باشد
        $event = $order->note('cancel', true, 'سفارش لغو شد', null, $userId);

        /*
        | ⚠️ بیرونِ تراکنش و بلعیده: لغو نزدِ اسنپ‌پی **قطعی شده** و خطای دفتر
        | نباید آن واقعیت را برگرداند.
        */
        $this->recordRefund($event, $invoice, $refunded, 'لغوِ کاملِ سفارشِ اسنپ‌پی '.$order->transaction_id);

        return ['ok' => true, 'message' => 'سفارش نزدِ اسنپ‌پی لغو شد و بدهیِ مشتری برگشت خورد.'];
    }

    /**
     * کاهشِ سفارش — حذفِ آیتم یا کم‌کردنِ تعداد.
     *
     * @param  array<int,int>  $quantities  شناسهٔ ردیفِ فاکتور ⇒ تعدادِ تازه (صفر = حذف)
     * @return array{ok:bool,message:string}
     */
    public function update(SnappPayOrder $order, array $quantities, ?int $userId = null): array
    {
        if (! $order->isChangeable()) {
            return ['ok' => false, 'message' => 'فقط سفارشِ نهایی‌شده (settled) قابلِ تغییر است.'];
        }

        $invoice = $order->invoice;

        if ($invoice === null) {
            return ['ok' => false, 'message' => 'فاکتورِ این سفارش پیدا نشد.'];
        }

        $before = (int) $invoice->total;

        // سبدِ تازه **بدونِ نوشتن** سنجیده می‌شود تا اگر نامعتبر بود چیزی خراب نشود
        $preview = $this->applyQuantities($invoice, $quantities, dryRun: true);

        if ($preview['error'] !== null) {
            return ['ok' => false, 'message' => $preview['error']];
        }

        if ($preview['total'] <= 0) {
            return ['ok' => false, 'message' => 'برای حذفِ کاملِ سفارش از «لغو» استفاده کنید، نه کاهش.'];
        }

        if ($preview['total'] >= $before) {
            /*
            | 🔴 مستندات: «درخواست آپدیت باید مبلغی کمتر از مبلغ کل سفارش
            | باشد.» افزایش اصلاً وجود ندارد — مشتری قسطِ بیشتر را قبول نکرده.
            */
            return ['ok' => false, 'message' => 'مبلغِ تازه باید کمتر از مبلغِ فعلیِ سفارش باشد.'];
        }

        /*
        | 🔴 نوشتن و تماس در یک تراکنش: اگر اسنپ‌پی نپذیرفت، کاهشِ فاکتور
        | برمی‌گردد. بدونِ این، فاکتورِ ما کم‌شده می‌ماند در حالی که بدهیِ
        | مشتری نزدِ اسنپ‌پی دست‌نخورده است — دو عددی که دیگر هرگز نمی‌خوانند.
        |
        | ⚠️ تماسِ شبکه‌ای داخلِ تراکنش معمولاً بد است، ولی این‌جا عمدی است:
        | سبد باید از فاکتورِ **تغییرکرده** ساخته شود، و این تنها راهِ داشتنِ
        | هر دو (سبدِ درست + برگشت‌پذیری) است. پنجره فقط به‌اندازهٔ یک تماس است.
        */
        try {
            $outcome = $this->reduceInTransaction($order, $invoice, $quantities);
        } catch (SnappPayRejected $e) {
            // تراکنش برگشت خورده: فاکتور دست‌نخورده است و با بدهیِ مشتری می‌خواند
            $order->note('update', false, $e->remoteError ?? $e->getMessage(), $e->body, $userId);

            return ['ok' => false, 'message' => $e->getMessage().' — فاکتور تغییری نکرد.'];
        }

        return $this->afterUpdate($order, $invoice->refresh(), $before, $outcome, $userId);
    }

    /**
     * @param  array<int,int>  $quantities
     * @return array{after:int,body:array}
     *
     * @throws SnappPayRejected
     */
    private function reduceInTransaction(SnappPayOrder $order, Invoice $invoice, array $quantities): array
    {
        return DB::transaction(function () use ($order, $invoice, $quantities) {
            $this->applyQuantities($invoice, $quantities, dryRun: false);
            $invoice->refresh()->load('items');

            try {
                $body = $this->cart->forUpdate($invoice, $order->token());
            } catch (\Throwable $e) {
                throw new SnappPayRejected('سبدِ تازه ساخته نشد: '.$e->getMessage());
            }

            $res = $this->client->update($body);

            if (! $res['ok']) {
                throw new SnappPayRejected($this->explain($res['error']), $body, $res['error']);
            }

            $after = (int) $invoice->total;

            // پرداختی هم به همان اندازه کم می‌شود: بخشی از پول واقعاً برگشته
            $invoice->forceFill(['paid' => $after])->save();
            $order->forceFill(['amount' => $after, 'cart' => $body])->save();

            return ['after' => $after, 'body' => $body];
        }, attempts: 1);
    }

    // ───────────────────────────── درونی ─────────────────────────────

    /**
     * @param  array{after:int,body:array}  $outcome
     * @return array{ok:bool,message:string}
     */
    private function afterUpdate(SnappPayOrder $order, Invoice $invoice, int $before, array $outcome, ?int $userId): array
    {
        $after = $outcome['after'];

        $event = $order->note('update', true,
            'کاهش از '.number_format($before).' به '.number_format($after), $outcome['body'], $userId);

        $this->recordRefund($event, $invoice, $before - $after,
            'کاهشِ سفارشِ اسنپ‌پی '.$order->transaction_id);

        return [
            'ok'      => true,
            'message' => 'سفارش به '.number_format($after).' تومان کاهش یافت و مابه‌التفاوت به مشتری برگشت.',
        ];
    }

    /**
     * اعمالِ تعدادهای تازه روی ردیف‌های فاکتور.
     *
     * ⚠️ ردیفی که تعدادش صفر شود **حذف** می‌شود، نه اینکه با count صفر
     * بمانَد — مستندات: «اگر یک محصول کاملا حذف میشود، لازم است از بین
     * کارت آیتم ها نیز حذف شود.»
     *
     * @param  array<int,int>  $quantities
     * @return array{total:int,error:?string}
     */
    private function applyQuantities(Invoice $invoice, array $quantities, bool $dryRun): array
    {
        $items = $invoice->items()->get();
        $subtotal = 0;
        $tax = 0;
        $discount = 0;
        $kept = 0;

        foreach ($items as $item) {
            $new = array_key_exists($item->id, $quantities)
                ? max(0, (int) $quantities[$item->id])
                : (int) $item->quantity;

            if ($new > (int) $item->quantity) {
                return ['total' => 0, 'error' => 'تعدادِ ردیفِ «'.$item->title.'» را نمی‌شود زیاد کرد.'];
            }

            if ($new === 0) {
                if (! $dryRun) {
                    $item->delete();
                }

                continue;
            }

            $kept++;

            // قیمتِ واحد ثابت است؛ تخفیفِ ردیف به نسبتِ تعداد کم می‌شود
            $line = $new * (int) $item->unit_price;
            $itemDiscount = (int) $item->quantity > 0
                ? intdiv((int) $item->discount * $new, (int) $item->quantity)
                : 0;
            $itemTax = intdiv(max(0, $line - $itemDiscount) * (int) $item->tax_rate_bp, 10000);

            $subtotal += $line;
            $discount += $itemDiscount;
            $tax += $itemTax;

            if (! $dryRun) {
                $item->forceFill([
                    'quantity'   => $new,
                    'line_total' => $line,
                    'discount'   => $itemDiscount,
                    'tax_amount' => $itemTax,
                ])->save();
            }
        }

        if ($kept === 0) {
            return ['total' => 0, 'error' => null];
        }

        $total = $subtotal - $discount + $tax;

        if (! $dryRun) {
            $invoice->forceFill([
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax'      => $tax,
                'total'    => $total,
            ])->save();
        }

        return ['total' => $total, 'error' => null];
    }

    /**
     * ثبتِ بازگشتِ وجه در دفتر — هرگز مسیرِ اصلی را نمی‌شکند.
     *
     * ⚠️ اگر ثبتِ رویداد شکست خورده باشد (`$event === null`)، منبعِ یکتا نداریم
     * و ردیفِ دفتر **ثبت نمی‌شود** — ولی بی‌صدا هم نه: ردیاب می‌گیردش. ثبت با
     * منبعِ فاکتور همان باگی را برمی‌گرداند که این ساختار برای رفعش ساخته شد.
     */
    private function recordRefund(?SnappPayOrderEvent $event, ?Invoice $invoice, int $amount, string $note): void
    {
        if ($invoice === null || $amount <= 0) {
            return;
        }

        if ($event === null) {
            \App\Support\ErrorTracker::noteOnce('finance',
                'بازگشتِ وجهِ اسنپ‌پی در دفتر ثبت نشد — رویدادِ منبع ساخته نشد', 3600,
                ['invoice' => $invoice->id, 'amount' => $amount]);

            return;
        }

        try {
            $this->ledger->recordRefundFor($event, $amount, (string) $invoice->currency_code, $note);
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('finance', $e,
                ['area' => 'snapppay-refund', 'invoice' => $invoice->id]);
        }
    }

    private function explain(?string $error): string
    {
        return match ($error) {
            'no-response' => 'اسنپ‌پی پاسخ نداد. وضعیت را استعلام کنید و دوباره تلاش کنید.',
            null, ''      => 'اسنپ‌پی درخواست را نپذیرفت.',
            default       => 'اسنپ‌پی: '.$error,
        };
    }
}
