<?php

namespace App\Services\Payment;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * زرین‌پال — درگاه ریالی.
 *
 * ═══ واحد مبلغ: مهم‌ترین نکتهٔ این فایل ═══
 *
 * قیمت‌های ما تومان‌اند. زرین‌پال **ریال** می‌گیرد. پارامتر currency اختیاری
 * است و IRT را هم می‌پذیرد، ولی عمداً از آن استفاده نمی‌کنیم:
 *
 *   اگر IRT بفرستیم و به هر دلیل نادیده گرفته شود → عدد تومانی ما ریال
 *   خوانده می‌شود و مشتری **یک‌دهم** می‌پردازد. ضرر مستقیم ما.
 *
 *   اگر IRR بفرستیم (که پیش‌فرض هم هست) و نادیده گرفته شود → هیچ اتفاقی
 *   نمی‌افتد، چون همان پیش‌فرض بود.
 *
 * پس تبدیل تومان→ریال اینجا و فقط اینجا انجام می‌شود، با تست قفل‌شده. هیچ
 * جای دیگر برنامه حق ندارد در ۱۰ ضرب کند.
 *
 * ═══ verify منبع حقیقت است ═══
 *
 * پارامتر Status در بازگشت مرورگر قابل جعل است: کاربر می‌تواند دستی
 * ?Status=OK&Authority=… را باز کند. تنها چیزی که پرداخت را قطعی می‌کند،
 * پاسخ verify.json به یک درخواست سمت-سرور است — و مبلغی که در verify
 * می‌فرستیم از دیتابیس خودمان می‌آید نه از درخواست.
 *
 * ═══ کد ۱۰۱ ═══
 *
 * یعنی «قبلاً تأیید شده». اگر کاربر صفحهٔ بازگشت را refresh کند این را
 * می‌گیریم. موفق است، ولی نباید دوباره به فاکتور اعتبار بدهیم — این تمایز
 * در VerifyResult::alreadyVerified منتقل می‌شود.
 */
class ZarinPalGateway implements PaymentGateway
{
    private const API     = 'https://api.zarinpal.com/pg/v4/payment/';
    private const START   = 'https://www.zarinpal.com/pg/StartPay/';
    private const SANDBOX_API   = 'https://sandbox.zarinpal.com/pg/v4/payment/';
    private const SANDBOX_START = 'https://sandbox.zarinpal.com/pg/StartPay/';

    /** کمترین مبلغ زرین‌پال ۱٬۰۰۰ ریال است؛ ما ۱٬۰۰۰ تومان می‌گذاریم */
    private const MIN_TOMAN = 1000;

    public function __construct(
        private ?string $merchantId,
        private bool $sandbox = false,
    ) {}

    public function key(): string
    {
        return 'zarinpal';
    }

    public function enabled(): bool
    {
        // شناسهٔ پذیرنده یک UUID است؛ رشتهٔ کوتاه یعنی کسی مقدار اشتباه گذاشته
        return filled($this->merchantId) && strlen((string) $this->merchantId) === 36;
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
            return StartResult::fail('درگاه پرداخت هنوز پیکربندی نشده است.');
        }

        if ($payment->amount < self::MIN_TOMAN) {
            return StartResult::fail('کمترین مبلغ قابل پرداخت '.number_format(self::MIN_TOMAN).' تومان است.');
        }

        $customer = $payment->customer;

        $body = [
            'merchant_id'  => $this->merchantId,
            'amount'       => $this->toRial($payment->amount),
            'callback_url' => $callbackUrl,
            'description'  => $payment->description(),
            'metadata'     => array_filter([
                'mobile' => $customer?->phone,
                'email'  => $customer?->email,
            ]),
        ];

        $json = $this->post('request.json', $body);

        if ($json === null) {
            return StartResult::fail('ارتباط با درگاه برقرار نشد. کمی بعد دوباره تلاش کنید.');
        }

        $code      = (int) data_get($json, 'data.code');
        $authority = (string) data_get($json, 'data.authority');

        if ($code !== 100 || $authority === '') {
            [$errCode, $errMessage] = $this->readError($json);

            Log::warning('زرین‌پال درخواست پرداخت را رد کرد', [
                'payment' => $payment->id, 'code' => $errCode, 'message' => $errMessage,
            ]);

            return StartResult::fail($this->explain($errCode, $errMessage), (string) $errCode);
        }

        return StartResult::redirect($this->startUrl().$authority, $authority);
    }

    public function verify(Payment $payment, array $callback): VerifyResult
    {
        // کاربر روی «انصراف» زده — این خطا نیست و نباید مثل خطا نشان داده شود
        if (strtoupper((string) ($callback['Status'] ?? '')) === 'NOK') {
            return VerifyResult::canceled();
        }

        // Authority بازگشتی باید همانی باشد که خودمان ثبت کرده‌ایم؛ اگر نه،
        // یعنی کسی دارد بازگشتِ یک پرداخت را به فاکتور دیگری می‌چسباند
        $authority = (string) ($callback['Authority'] ?? '');

        if ($authority !== '' && $authority !== $payment->external_ref) {
            return VerifyResult::fail('اطلاعات بازگشت با این پرداخت نمی‌خواند.');
        }

        $json = $this->post('verify.json', [
            'merchant_id' => $this->merchantId,
            // مبلغ از دیتابیس، نه از درخواست — و همان مبلغی که موقع شروع فرستادیم
            'amount'      => $this->toRial($payment->amount),
            'authority'   => $payment->external_ref,
        ]);

        if ($json === null) {
            return VerifyResult::fail('تأیید پرداخت انجام نشد. اگر مبلغ کم شده، با پشتیبانی تماس بگیرید.');
        }

        $code = (int) data_get($json, 'data.code');

        if ($code === 100 || $code === 101) {
            return VerifyResult::paid(
                refId:    (string) data_get($json, 'data.ref_id') ?: null,
                cardMask: (string) data_get($json, 'data.card_pan') ?: null,
                // ⚠️ کارمزد هم **ریال** برمی‌گردد، چون مبلغ را ریالی فرستادیم.
                // خام ذخیره‌کردنش یعنی عددِ ریالی در ستونی که واحدش تومان است
                // ⇒ هزینهٔ درگاه ده برابر. تبدیل همین‌جاست، کنارِ همان ضرب در ۱۰،
                // نه در دفترِ مالی — وگرنه واحد دو جا تفسیر می‌شود.
                fee:      $this->toToman((int) data_get($json, 'data.fee', 0)),
                feeType:  (string) data_get($json, 'data.fee_type') ?: null,
                already:  $code === 101,
            );
        }

        [$errCode, $errMessage] = $this->readError($json);

        Log::warning('زرین‌پال تأیید نکرد', [
            'payment' => $payment->id, 'data_code' => $code,
            'code' => $errCode, 'message' => $errMessage,
        ]);

        return VerifyResult::fail($this->explain($errCode ?: $code, $errMessage), (string) ($errCode ?: $code));
    }

    // ───────────────────────────── درونی ─────────────────────────────

    /**
     * تومان → ریال.
     * تنها جای برنامه که این ضرب انجام می‌شود.
     */
    private function toRial(int $toman): int
    {
        return $toman * 10;
    }

    /**
     * ریال → تومان، برای عددهایی که درگاه برمی‌گرداند (کارمزد).
     *
     * رو به بالا گرد می‌شود: کارمزدِ واقعی دستِ‌کم همین‌قدر بوده، و هزینهٔ
     * کم‌برآوردشده سود را متورم می‌کند — همان چیزی که این تغییر برای رفعش آمد.
     */
    private function toToman(int $rial): int
    {
        return $rial > 0 ? intdiv($rial + 9, 10) : 0;
    }

    private function post(string $path, array $body): ?array
    {
        try {
            $res = Http::withHeaders([
                    'Accept'     => 'application/json',
                    'User-Agent' => 'ServerNet/1.0',
                ])
                ->timeout(20)
                ->asJson()
                ->post($this->apiUrl().$path, $body);
        } catch (\Throwable $e) {
            Log::warning('زرین‌پال در دسترس نبود', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        $json = $res->json();

        return is_array($json) ? $json : null;
    }

    /**
     * خواندن خطا.
     *
     * زرین‌پال گاهی errors را شیء می‌دهد ({code,message}) و گاهی آرایهٔ خالی
     * وقتی خطایی نیست. خواندن کورکورانهٔ errors.code روی آرایهٔ خالی، خطای
     * PHP می‌دهد و کل بازگشت پرداخت را با ۵۰۰ می‌شکند — یعنی مشتری پول داده
     * و صفحهٔ خطا می‌بیند. پس هر دو شکل پوشش داده می‌شود.
     *
     * @return array{0:int|string|null,1:string|null}
     */
    private function readError(array $json): array
    {
        $errors = $json['errors'] ?? null;

        if (is_array($errors) && array_is_list($errors)) {
            $errors = $errors[0] ?? null;   // آرایه‌ای از خطاها
        }

        return [
            data_get($errors, 'code') ?? data_get($json, 'data.code'),
            data_get($errors, 'message'),
        ];
    }

    /**
     * پیام فارسی برای کدهای رایج.
     *
     * پیام خام زرین‌پال گاهی انگلیسی و برای مشتری بی‌معنی است. کدهایی که
     * اینجا ترجمه شده‌اند همان‌هایی‌اند که واقعاً به چشم کاربر می‌خورند؛
     * بقیه با پیام خود درگاه نشان داده می‌شوند تا چیزی از دست نرود.
     */
    private function explain(int|string|null $code, ?string $fallback): string
    {
        return match ((int) $code) {
            -9   => 'اطلاعات ارسالی به درگاه معتبر نیست.',
            -10  => 'شناسهٔ پذیرنده یا آی‌پی سرور با تنظیمات درگاه نمی‌خواند.',
            -11  => 'درخواست یافت نشد یا منقضی شده است.',
            -12  => 'تلاش بیش از حد. کمی بعد دوباره امتحان کنید.',
            -15  => 'درگاه پرداخت غیرفعال است. با پشتیبانی تماس بگیرید.',
            -16  => 'سطح تأیید پذیرنده کافی نیست.',
            -30  => 'این درگاه اجازهٔ تراکنش با این مبلغ را ندارد.',
            -33  => 'مبلغ پرداخت‌شده با مبلغ فاکتور یکی نیست.',
            -34  => 'سقف تراکنش رد شده است.',
            -40  => 'دسترسی لازم برای این عملیات وجود ندارد.',
            -50  => 'مبلغ پرداخت‌شده با مبلغ درخواست‌شده برابر نیست.',
            -51  => 'پرداخت ناموفق بود.',
            -53  => 'این پرداخت متعلق به پذیرندهٔ دیگری است.',
            -54  => 'شناسهٔ پرداخت نامعتبر است.',
            default => $fallback ?: 'پرداخت انجام نشد. اگر مبلغ از حساب شما کم شده، تا ۷۲ ساعت برمی‌گردد.',
        };
    }

    private function apiUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_API : self::API;
    }

    private function startUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_START : self::START;
    }
}
