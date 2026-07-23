<?php

namespace App\Services\Payment;

use App\Models\Payment;

/**
 * درگاه پرداخت.
 *
 * قرارداد عمداً فقط دو فعل دارد، چون هر درگاهی — ریالی یا رمزارز — همین دو
 * را دارد: «شروع کن» و «بگو واقعاً پول آمد یا نه».
 *
 * تفاوت کلیدی بین درگاه‌ها در شکل شروع است: زرین‌پال کاربر را می‌فرستد
 * (redirect)، رمزارز آدرس و QR نشان می‌دهد (instructions). برای همین
 * StartResult هر دو حالت را می‌پذیرد.
 *
 * قاعده‌ای که هیچ پیاده‌سازی نباید بشکند: **verify منبع حقیقت است.**
 * هرچه در بازگشتِ مرورگر آمد — پارامتر Status، مبلغ، هر چیز — داده است نه
 * حکم. تنها چیزی که پرداخت را قطعی می‌کند، پاسخ خود درگاه به یک درخواست
 * سمت-سرور است.
 */
interface PaymentGateway
{
    public function key(): string;

    /** آیا پیکربندی شده؟ */
    public function enabled(): bool;

    /** ارزی که این درگاه می‌پذیرد */
    public function currency(): string;

    /** کمترین مبلغ قابل پرداخت، در واحد فرعی */
    public function minimum(): int;

    public function start(Payment $payment, string $callbackUrl): StartResult;

    /**
     * @param  array<string,mixed>  $callback  پارامترهای بازگشت مرورگر — داده، نه حکم
     */
    public function verify(Payment $payment, array $callback): VerifyResult;
}

final readonly class StartResult
{
    private function __construct(
        public bool $ok,
        public ?string $redirectUrl = null,
        /** برای درگاه‌هایی که به‌جای هدایت، دستور نمایش می‌دهند (آدرس رمزارز، QR) */
        public ?array $instructions = null,
        public ?string $externalRef = null,
        public ?string $error = null,
        public ?string $errorCode = null,
    ) {}

    public static function redirect(string $url, string $externalRef): self
    {
        return new self(true, redirectUrl: $url, externalRef: $externalRef);
    }

    public static function show(array $instructions, string $externalRef): self
    {
        return new self(true, instructions: $instructions, externalRef: $externalRef);
    }

    public static function fail(string $error, ?string $code = null): self
    {
        return new self(false, error: $error, errorCode: $code);
    }
}

final readonly class VerifyResult
{
    private function __construct(
        public bool $paid,
        /** پرداخت قبلاً تأیید شده بود — موفق است ولی نباید دوباره اعتبار داده شود */
        public bool $alreadyVerified = false,
        public ?string $refId = null,
        public ?string $cardMask = null,
        public int $fee = 0,
        public ?string $feeType = null,
        public ?string $error = null,
        public ?string $errorCode = null,
        /** کاربر خودش منصرف شد — با «خطا» فرق دارد و نباید مثل خطا نشان داده شود */
        public bool $canceled = false,
    ) {}

    public static function paid(
        ?string $refId,
        ?string $cardMask = null,
        int $fee = 0,
        ?string $feeType = null,
        bool $already = false,
    ): self {
        return new self(true, alreadyVerified: $already, refId: $refId,
            cardMask: $cardMask, fee: $fee, feeType: $feeType);
    }

    public static function canceled(string $message = 'پرداخت توسط شما لغو شد.'): self
    {
        return new self(false, error: $message, canceled: true);
    }

    public static function fail(string $error, ?string $code = null): self
    {
        return new self(false, error: $error, errorCode: $code);
    }
}
