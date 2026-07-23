<?php

namespace App\Services\Payment;

use App\Models\BaleContact;
use App\Models\Payment;
use App\Services\Bale\BaleSender;

/**
 * درگاه پرداخت بله — از کیف پول بله، بدون کارت بانکی.
 *
 * ═══ چرا با زرین‌پال فرق دارد ═══
 *
 * زرین‌پال کاربر را به یک صفحه هدایت می‌کند و او با کارت می‌پردازد؛ ما با
 * verify سمت-سرور تأیید می‌کنیم. بله این‌طور نیست:
 *
 *   ۱) start(): فاکتور را به **چت بلهٔ کاربر** می‌فرستیم (sendInvoice)
 *   ۲) کاربر داخل بله از کیف پولش می‌پردازد
 *   ۳) بله دو رویداد به وب‌هوک می‌فرستد: PreCheckoutQuery (باید ظرف ۱۰
 *      ثانیه تأیید شود) و بعد SuccessfulPayment
 *   ۴) تسویه در **وب‌هوک** انجام می‌شود، نه در بازگشت مرورگر
 *
 * پس این کلاس فقط «فاکتور را بفرست» را دارد؛ تسویه در BaleWebhookController
 * است. verify() اینجا بی‌استفاده می‌ماند (بله بازگشت مرورگر ندارد) ولی برای
 * پرکردن قرارداد پیاده شده است.
 *
 * ═══ واحد مبلغ ═══
 *
 * بله مثل زرین‌پال **ریال** می‌گیرد. قیمت‌های ما تومان است، پس ×۱۰ — و این
 * تبدیل فقط در همین یک متد (toRial) انجام می‌شود، با تست قفل‌شده.
 *
 * ═══ نیاز به اتصال بله ═══
 *
 * sendInvoice به chat_id کاربر نیاز دارد، نه شماره‌اش. پس فقط مشتری‌ای که
 * بله را وصل کرده می‌تواند با بله بپردازد. availableFor این را نمی‌داند
 * (مشتری‌محور نیست)، پس چک chat_id در start() است و اگر نبود، پیام راهنما.
 */
class BaleGateway implements PaymentGateway
{
    private const MIN_TOMAN = 1000;

    public function __construct(
        private BaleSender $sender,
        private ?string $walletToken,
    ) {}

    public function key(): string
    {
        return 'bale';
    }

    public function enabled(): bool
    {
        // هم ربات هم توکن کیف پول لازم است
        return $this->sender->enabled() && filled($this->walletToken);
    }

    public function currency(): string
    {
        return 'IRT';
    }

    public function minimum(): int
    {
        return self::MIN_TOMAN;
    }

    public function start(Payment $payment, string $callbackUrl): StartResult
    {
        if (! $this->enabled()) {
            return StartResult::fail('پرداخت با بله هنوز پیکربندی نشده است.');
        }

        if ($payment->amount < self::MIN_TOMAN) {
            return StartResult::fail('کمترین مبلغ قابل پرداخت '.number_format(self::MIN_TOMAN).' تومان است.');
        }

        $chatId = BaleContact::chatIdFor((string) $payment->customer?->phone);

        if ($chatId === null) {
            return StartResult::fail(
                'برای پرداخت با بله، اول باید حساب بلهٔ خود را به سرورنت متصل کنید. '
                .'ربات بله را استارت کنید و شماره‌تان را به اشتراک بگذارید، سپس دوباره تلاش کنید.'
            );
        }

        // payload کلید تطبیق است؛ در PreCheckoutQuery و SuccessfulPayment
        // برمی‌گردد. همان external_ref پرداخت را می‌گذاریم.
        $payload = 'pay:'.$payment->id.':'.substr(bin2hex(random_bytes(8)), 0, 16);

        $invoice = $payment->invoice;
        $title   = $invoice?->kind === 'topup' ? 'افزایش اعتبار سرورنت' : 'پرداخت فاکتور';
        $desc    = $invoice ? ('فاکتور '.$invoice->number.' — '.number_format($payment->amount).' تومان') : 'پرداخت';

        $sent = $this->sender->sendInvoice(
            chatId: $chatId,
            title: $title,
            description: $desc,
            payload: $payload,
            providerToken: (string) $this->walletToken,
            amountRial: $this->toRial($payment->amount),
            priceLabel: $title,
        );

        if (! $sent) {
            return StartResult::fail('ارسال فاکتور به بله انجام نشد. کمی بعد دوباره تلاش کنید.');
        }

        // externalRef = payload، تا وب‌هوک بتواند این پرداخت را پیدا کند
        return StartResult::show(
            [
                'channel' => 'bale',
                'message' => 'فاکتور به حساب بلهٔ شما فرستاده شد. برای تکمیل، در بله از کیف پول خود پرداخت کنید.',
            ],
            externalRef: $payload,
        );
    }

    /**
     * بله بازگشت مرورگر ندارد؛ تسویه در وب‌هوک است. این متد فقط برای پرکردن
     * قرارداد است و نباید در جریان عادی صدا زده شود.
     */
    public function verify(Payment $payment, array $callback): VerifyResult
    {
        return VerifyResult::fail('تأیید پرداخت بله از طریق وب‌هوک انجام می‌شود، نه بازگشت مرورگر.');
    }

    /** مبلغ مورد انتظار این پرداخت به ریال — وب‌هوک برای تطبیق از آن استفاده می‌کند */
    public function expectedRial(Payment $payment): int
    {
        return $this->toRial($payment->amount);
    }

    /**
     * تومان → ریال. تنها جای این کلاس که این ضرب انجام می‌شود.
     */
    private function toRial(int $toman): int
    {
        return $toman * 10;
    }
}
