<?php

namespace App\Services\Payment\SnappPay;

use App\Models\Invoice;
use App\Models\Payment;

/**
 * ترجمهٔ فاکتورِ سرورنت به سبدِ خریدِ اسنپ‌پی.
 *
 * ═══ فرمولی که اسنپ‌پی صریحاً اعلام کرده ═══
 *
 *   به‌ازای هر سبد:
 *     totalAmount = Σ(count × item amount)
 *                 + shippingAmount (اگر isShipmentIncluded نباشد)
 *                 + taxAmount      (اگر isTaxIncluded نباشد)
 *
 *   به‌ازای هر سفارش:
 *     amount = Σ totalAmount − discountAmount − externalSourceAmount
 *
 * ═══ نگاشتِ ما ═══
 *
 * · یک سبد به‌ازای هر فاکتور؛ `cartId` همان شناسهٔ فاکتور است.
 * · حمل‌ونقل نداریم ⇒ `isShipmentIncluded = true` و `shippingAmount = 0`.
 * · مالیات **بیرونِ** قیمتِ ردیف‌هاست ⇒ `isTaxIncluded = false` و
 *   `taxAmount = invoice.tax`.
 * · تخفیف در سطحِ سفارش می‌نشیند، همان‌جا که فرمولِ اسنپ‌پی می‌خواهد.
 *
 * پس: amount = subtotal + tax − discount = `invoice.total` ✓
 *
 * ⚠️ **نکتهٔ ظریفِ مالیات:** مالیاتِ ما روی مبلغِ *خالص* (پس از تخفیف) حساب
 * می‌شود، ولی فرمولِ اسنپ‌پی تخفیف را *بعد از* مالیات کم می‌کند. این تناقض
 * نیست: ما عددِ مالیاتِ واقعیِ خودمان را به‌عنوان `taxAmount` می‌فرستیم و
 * جمع‌ها دقیقاً می‌خوانند. اسنپ‌پی نرخ را بازمحاسبه نمی‌کند، فقط جمع را
 * می‌سنجد.
 *
 * 🔴 و اگر روزی نخواند، این کلاس **پرداخت را شروع نمی‌کند**. سبدی که با
 * فاکتور یکی نباشد یا همان‌جا رد می‌شود یا — بدتر — پذیرفته می‌شود و مبلغی
 * غیر از فاکتور از مشتری می‌گیرد.
 */
class SnappPayCart
{
    public function __construct(private SnappPayClient $client) {}

    /**
     * شناسهٔ تراکنش — یکتا در سیستمِ ما، و در قالبی که اسنپ‌پی می‌پذیرد.
     *
     * ⚠️ مستندات: بین ۵ تا ۱۰ کاراکتر، و برای بیش از ۱۰ رقم حتماً یک حرف
     * داشته باشد. شمارهٔ فاکتورِ ما (`INV-260906-1234`) پانزده کاراکتر است و
     * قبول نمی‌شود، پس شناسهٔ جدا از روی شناسهٔ **پرداخت** ساخته می‌شود —
     * هر تلاشِ پرداخت ردیفِ Payment خودش را دارد، پس یکتایی رایگان به دست
     * می‌آید و تلاشِ دوباره شناسهٔ تازه می‌گیرد (که اسنپ‌پی هم همین را
     * می‌خواهد: «به ازای هر خرید متفاوت باشد»).
     */
    public function transactionId(Payment $payment): string
    {
        // حرفِ ثابتِ ابتدایی هم قالب را تضمین می‌کند هم منشأ را خوانا نگه می‌دارد
        return 'S'.str_pad(strtoupper(base_convert((string) $payment->id, 10, 36)), 4, '0', STR_PAD_LEFT);
    }

    /**
     * بدنهٔ درخواستِ دریافتِ توکنِ پرداخت.
     *
     * @return array<string,mixed>
     *
     * @throws \RuntimeException وقتی جمعِ سبد با فاکتور نمی‌خوانَد
     */
    public function forToken(Invoice $invoice, Payment $payment, string $mobile, string $returnUrl): array
    {
        return $this->body($invoice, [
            'mobile'        => $this->normalizeMobile($mobile),
            'returnURL'     => $returnUrl,
            'transactionId' => $this->transactionId($payment),
        ]);
    }

    /**
     * بدنهٔ درخواستِ update — همان سبد، ولی با ردیف‌های تغییریافته.
     *
     * ⚠️ آیتمی که کاملاً حذف می‌شود باید از `cartItems` **برداشته** شود، نه
     * اینکه `count` صفر بگیرد (مستندات صریح است). چون سبد از روی ردیف‌های
     * فعلیِ فاکتور ساخته می‌شود، حذفِ ردیف از فاکتور خودش این را می‌دهد.
     *
     * @return array<string,mixed>
     */
    public function forUpdate(Invoice $invoice, string $paymentToken): array
    {
        return $this->body($invoice, ['paymentToken' => $paymentToken]);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function body(Invoice $invoice, array $extra): array
    {
        $items = $invoice->relationLoaded('items') ? $invoice->items : $invoice->items()->get();

        if ($items->isEmpty()) {
            throw new \RuntimeException('فاکتور '.$invoice->number.' هیچ ردیفی ندارد؛ سبدِ اسنپ‌پی ساخته نمی‌شود.');
        }

        $cartItems = [];
        $itemsToman = 0;

        foreach ($items as $item) {
            $count = max(1, (int) $item->quantity);
            $unit  = (int) $item->unit_price;

            $itemsToman += $count * $unit;

            $cartItems[] = [
                'id'             => (int) $item->id,
                'name'           => mb_substr((string) $item->title, 0, 120),
                'category'       => $this->categoryOf($invoice),
                'count'          => $count,
                'amount'         => $this->client->toRial($unit),
                'commissionType' => (int) config('snapppay.commission_type', 100),
            ];
        }

        $taxToman      = (int) $invoice->tax;
        $discountToman = (int) $invoice->discount;

        // طبق فرمول: چون مالیات «included» نیست، به جمعِ سبد اضافه می‌شود
        $cartTotalToman = $itemsToman + $taxToman;
        $orderToman     = $cartTotalToman - $discountToman;

        $this->assertMatchesInvoice($invoice, $itemsToman, $orderToman);

        return $extra + [
            'amount'   => $this->client->toRial($orderToman),
            'cartList' => [[
                'cartId'              => (int) $invoice->id,
                'cartItems'           => $cartItems,
                // حمل‌ونقل نداریم؛ «included» با مبلغِ صفر صادق‌ترین حالت است
                'isShipmentIncluded'  => true,
                'shippingAmount'      => 0,
                // مالیات جدا محاسبه و جدا نشان داده می‌شود
                'isTaxIncluded'       => false,
                'taxAmount'           => $this->client->toRial($taxToman),
                'totalAmount'         => $this->client->toRial($cartTotalToman),
            ]],
            'discountAmount'       => $this->client->toRial($discountToman),
            // امروز صفر است: پرداختِ ترکیبیِ اعتبار + اقساط را عمداً نمی‌پذیریم
            'externalSourceAmount' => 0,
        ];
    }

    /**
     * 🔴 گاردی که نبودش یعنی مشتری مبلغی غیر از فاکتور می‌پردازد.
     *
     * دو چیز سنجیده می‌شود:
     *  ۱) Σ(count × unit_price) باید با `subtotal` یکی باشد. اگر صادرکننده‌ای
     *     `line_total` را دستی چیزِ دیگری گذاشته باشد، سبد فاکتور را درست
     *     توصیف نمی‌کند حتی اگر جمعِ کل تصادفاً درست دربیاید.
     *  ۲) مبلغِ نهایی باید دقیقاً `invoice.total` باشد.
     */
    private function assertMatchesInvoice(Invoice $invoice, int $itemsToman, int $orderToman): void
    {
        if ($itemsToman !== (int) $invoice->subtotal) {
            throw new \RuntimeException(
                'سبدِ اسنپ‌پی با فاکتور '.$invoice->number.' نمی‌خوانَد: جمعِ ردیف‌ها '
                .$itemsToman.' ولی subtotal فاکتور '.$invoice->subtotal.' است.'
            );
        }

        if ($orderToman !== (int) $invoice->total) {
            throw new \RuntimeException(
                'سبدِ اسنپ‌پی با فاکتور '.$invoice->number.' نمی‌خوانَد: مبلغِ سبد '
                .$orderToman.' ولی total فاکتور '.$invoice->total.' است.'
            );
        }
    }

    /**
     * دستهٔ کالا. تا وقتی در قرارداد دسته‌بندیِ اختصاصی تعریف نشده، نوعِ
     * فاکتور خواناترین چیزی است که داریم.
     */
    private function categoryOf(Invoice $invoice): string
    {
        return match ((string) $invoice->kind) {
            'domain' => 'domain',
            'topup'  => 'wallet',
            default  => 'hosting',
        };
    }

    /**
     * موبایل در قالبی که اسنپ‌پی می‌شناسد.
     *
     * ⚠️ ارقامِ فارسی/عربی و پیش‌شماره‌های گوناگون (۰۹۱۲…، ۹۸۹۱۲…، +۹۸۹۱۲…)
     * همگی در فرم‌های ما دیده می‌شوند. یک شکلِ واحد بیرون می‌دهیم تا خطای
     * «کاربر یافت نشد» به‌خاطرِ قالب رخ ندهد.
     */
    public function normalizeMobile(string $mobile): string
    {
        $digits = strtr(trim($mobile), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        if (str_starts_with($digits, '98')) {
            $digits = '0'.substr($digits, 2);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return $digits;
    }
}
