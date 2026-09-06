<?php

namespace App\Services\Payment\SnappPay;

use App\Models\Payment;
use App\Models\SnappPayOrder;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\StartResult;
use App\Services\Payment\VerifyResult;
use Illuminate\Support\Facades\Log;

/**
 * اسنپ‌پی — پرداختِ اقساطی.
 *
 * ═══ تفاوتِ بنیادی با زرین‌پال ═══
 *
 * زرین‌پال دو فعل دارد: شروع و تأیید. اسنپ‌پی **سه** دارد و سومی اجباری است:
 *
 *   token → (کاربر می‌پردازد) → verify → settle
 *
 * مستندات صریح است: «عدمِ فراخوانیِ verify منجر به بازگشتِ مبلغ از حسابِ
 * کاربر می‌شود» و «تمامی سفارشاتی که به مرحلهٔ Verify رسیده‌اند نیازمندِ
 * فراخوانیِ Settle می‌باشند». پس سفارشی که verify شده و settle نشده، پولی
 * است که نه دستِ ماست نه دستِ مشتری.
 *
 * برای همین `verify()` این کلاس هر دو را انجام می‌دهد و فقط وقتی موفق
 * برمی‌گردد که settle هم نشسته باشد.
 *
 * ═══ وقتی پاسخی نمی‌آید ═══
 *
 * 🔴 «اسنپ‌پی گفت نه» و «اسنپ‌پی جواب نداد» دو چیزِ کاملاً متفاوت‌اند.
 * مستندات برای حالتِ دوم مسیرِ مشخص داده: getPaymentStatus را بپرس و بر
 * اساسِ وضعیت تصمیم بگیر (VERIFY ⇒ settle بزن، PENDING ⇒ دوباره verify).
 * ناموفق اعلام کردنِ سفارشی که سمتِ اسنپ‌پی موفق بوده، یعنی مشتری قسط
 * می‌دهد و سرویس نمی‌گیرد.
 */
class SnappPayGateway implements PaymentGateway
{
    public function __construct(
        private SnappPayClient $client,
        private SnappPayCart $cart,
    ) {}

    public function key(): string
    {
        return 'snapppay';
    }

    public function enabled(): bool
    {
        return (bool) config('snapppay.enabled') && $this->client->configured();
    }

    public function currency(): string
    {
        return 'IRT';
    }

    /**
     * کفِ مبلغ.
     *
     * ⚠️ عددِ واقعی را **اسنپ‌پی** تعیین می‌کند و با محیط فرق دارد (در
     * استیجینگ زیر ۴۰ هزار تومان و بالای ۱۰ میلیون رد می‌شود). این عدد فقط
     * یک کفِ محلی است تا درخواستِ بی‌فایده نفرستیم؛ حکمِ واقعی همیشه از
     * سرویسِ eligible می‌آید، نه از این‌جا.
     */
    public function minimum(): int
    {
        return 1000;
    }

    public function start(Payment $payment, string $callbackUrl): StartResult
    {
        if (! $this->enabled()) {
            return StartResult::fail('پرداخت اقساطی در دسترس نیست.');
        }

        $invoice = $payment->invoice;

        if ($invoice === null) {
            return StartResult::fail('فاکتور این پرداخت پیدا نشد.');
        }

        /*
        | 🔴 فقط فاکتورِ دست‌نخورده.
        |
        | سبدی که به اسنپ‌پی می‌رود کلِ فاکتور را توصیف می‌کند، پس مبلغش باید
        | کلِ فاکتور باشد. اگر بخشی از فاکتور قبلاً پرداخت شده، `due()` کمتر
        | از `total` است و سبد چیزی را توصیف می‌کند که با پول نمی‌خواند.
        | خودِ اسنپ‌پی هم می‌گوید سبد بعد از پرداخت بسته حساب می‌شود.
        */
        if ((int) $payment->amount !== (int) $invoice->total) {
            return StartResult::fail('پرداخت اقساطی فقط برای کلِ فاکتور ممکن است، نه بخشی از آن.');
        }

        $mobile = $this->cart->normalizeMobile((string) ($payment->customer?->phone ?? ''));

        if (strlen($mobile) !== 11) {
            return StartResult::fail('برای پرداخت اقساطی، شمارهٔ موبایلِ حسابتان باید ثبت شده باشد.');
        }

        try {
            $body = $this->cart->forToken($invoice, $payment, $mobile, $callbackUrl);
        } catch (\Throwable $e) {
            // سبدی که با فاکتور نمی‌خوانَد هرگز فرستاده نمی‌شود
            Log::warning('سبدِ اسنپ‌پی ساخته نشد', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return StartResult::fail('سبدِ خرید ساخته نشد. با پشتیبانی تماس بگیرید.');
        }

        $res = $this->client->paymentToken($body);

        if (blank($res['token']) || blank($res['url'])) {
            return StartResult::fail($this->explain($res['error']), $res['error']);
        }

        /*
        | سفارش **پیش از** هدایتِ کاربر ساخته می‌شود.
        |
        | 🔴 اگر بعدش ساخته می‌شد، کاربری که وسطِ راه برگردد یا کالبک زودتر
        | از ما برسد، سفارشی نداشت که به آن وصل شود — و تراکنشی که اسنپ‌پی
        | می‌شناسد و ما نمی‌شناسیم، یعنی پولِ گرفته‌شدهٔ بی‌صاحب.
        */
        SnappPayOrder::create([
            'payment_id'     => $payment->id,
            'invoice_id'     => $invoice->id,
            'customer_id'    => $payment->customer_id,
            'transaction_id' => $body['transactionId'],
            'state'          => 'pending',
            'amount'         => (int) $payment->amount,
            'mobile'         => $mobile,
            'cart'           => $body,
        ])->note('token', true, 'توکنِ پرداخت گرفته شد');

        return StartResult::redirect($res['url'], $res['token']);
    }

    /**
     * تأیید + نهایی‌سازی.
     *
     * ⚠️ `$callback` داده است نه حکم. `state=OK` در فرمِ بازگشتی قابلِ جعل
     * است؛ تنها چیزی که پرداخت را قطعی می‌کند پاسخِ سرور-به-سرورِ verify است.
     */
    public function verify(Payment $payment, array $callback): VerifyResult
    {
        $order = SnappPayOrder::where('payment_id', $payment->id)->first();
        $token = (string) $payment->external_ref;

        if ($order === null || $token === '') {
            return VerifyResult::fail('این پرداخت پیدا نشد.');
        }

        // انصرافِ صریحِ کاربر خطا نیست و نباید مثلِ خطا نشان داده شود
        if (strtoupper((string) ($callback['state'] ?? '')) === 'FAILED') {
            $order->forceFill(['state' => 'failed', 'error' => 'کاربر پرداخت را کامل نکرد'])->save();
            $order->note('verify', false, 'بازگشت با state=FAILED');

            return VerifyResult::canceled();
        }

        return $this->finalize($order, $token);
    }

    /**
     * verify و بعد settle — با مسیرِ رفعِ مغایرتی که مستندات اجباری کرده.
     *
     * این متد از دو جا صدا زده می‌شود: کالبکِ مرورگر، و کرونِ مغایرت‌گیری.
     * پس باید روی سفارشی که قبلاً نیمه‌کاره مانده هم درست کار کند.
     */
    public function finalize(SnappPayOrder $order, string $token): VerifyResult
    {
        // ── مرحلهٔ ۱: verify، مگر اینکه قبلاً انجام شده باشد ──
        if ($order->state === 'pending') {
            $res = $this->client->verify($token);

            if ($res['ok']) {
                $order->forceFill(['state' => 'verified', 'verified_at' => now()])->save();
                $order->note('verify', true);
            } else {
                /*
                | 🔴 پاسخی نیامد ⇒ حکم را از getPaymentStatus بگیر، نه از حدس.
                | مستندات: تایم‌اوتِ ۳۰ ثانیه، بعد status؛ VERIFY ⇒ settle،
                | PENDING ⇒ دوباره verify، بقیه ⇒ ناموفق.
                */
                $decided = $this->reconcile($order, $token);

                if ($decided !== null) {
                    return $decided;
                }
            }
        }

        // ── مرحلهٔ ۲: settle — اجباری، وگرنه پول معلق می‌مانَد ──
        if (in_array($order->state, ['verified'], true)) {
            $res = $this->client->settle($token);

            if ($res['ok']) {
                $order->forceFill(['state' => 'settled', 'settled_at' => now(), 'remote_status' => 'SETTLE'])->save();
                $order->note('settle', true);
            } else {
                $decided = $this->reconcile($order, $token);

                if ($decided !== null) {
                    return $decided;
                }
            }
        }

        if ($order->fresh()?->state === 'settled') {
            return VerifyResult::paid($order->transaction_id);
        }

        return VerifyResult::fail('تأیید پرداخت اقساطی کامل نشد. اگر مبلغ کم شده، با پشتیبانی تماس بگیرید.');
    }

    /**
     * وقتی verify/settle پاسخِ روشن ندادند: از خودِ اسنپ‌پی بپرس.
     *
     * @return VerifyResult|null  null یعنی «ادامه بده»، غیرِ null یعنی حکمِ نهایی
     */
    private function reconcile(SnappPayOrder $order, string $token): ?VerifyResult
    {
        $st = $this->client->status($token);

        $order->forceFill([
            'remote_status' => $st['status'],
            'checked_at'    => now(),
        ])->save();

        $order->note('status', (bool) $st['ok'], $st['status'] ?: $st['error']);

        switch (strtoupper((string) $st['status'])) {
            case 'SETTLE':
                // اسنپ‌پی می‌گوید نهایی شده — پس نهایی است، هرچه verify گفته باشد
                $order->forceFill([
                    'state'      => 'settled',
                    'settled_at' => $order->settled_at ?? now(),
                ])->save();

                return VerifyResult::paid($order->transaction_id);

            case 'VERIFY':
                /*
                | تأیید شده ولی نهایی نشده. `null` یعنی «ادامه بده» تا مرحلهٔ
                | settle در همین درخواست تلاش کند. اگر آن هم نگرفت، سفارش
                | «verified» می‌مانَد و کرونِ مغایرت‌گیری برش می‌دارد — که
                | بهتر از حلقهٔ تلاشِ فوری در یک درخواستِ وب است.
                */
                $order->forceFill([
                    'state'       => 'verified',
                    'verified_at' => $order->verified_at ?? now(),
                ])->save();

                return null;

            case 'PENDING':
                // نه موفق نه ناموفق. سفارش عمداً باز می‌مانَد.
                return VerifyResult::fail('پرداخت هنوز نهایی نشده است. چند دقیقهٔ دیگر دوباره بررسی می‌شود.');

            case 'CANCEL':
            case 'REVERT':
                $order->forceFill([
                    'state' => 'failed',
                    'error' => 'وضعیتِ اسنپ‌پی: '.$st['status'],
                ])->save();

                return VerifyResult::fail('این پرداخت لغو شده است.');

            default:
                /*
                | وضعیتِ ناشناخته یا بی‌پاسخ.
                |
                | 🔴 سفارش **بسته نمی‌شود**. ناموفق اعلام کردنِ چیزی که سمتِ
                | اسنپ‌پی موفق بوده یعنی مشتری قسط می‌دهد و سرویس نمی‌گیرد.
                */
                return VerifyResult::fail('وضعیت پرداخت هنوز روشن نیست؛ پیگیری خودکار انجام می‌شود.');
        }
    }

    private function explain(?string $error): string
    {
        return match ($error) {
            'no-response' => 'ارتباط با اسنپ‌پی برقرار نشد. کمی بعد دوباره تلاش کنید.',
            null, ''      => 'شروع پرداخت اقساطی انجام نشد.',
            default       => 'اسنپ‌پی این پرداخت را نپذیرفت: '.$error,
        };
    }
}
