<?php

namespace App\Services\Ai;

use App\Models\AiReservation;

/**
 * نتیجهٔ یک تماسِ زندهٔ AI — حکمِ نهاییِ `AiCaller::handle`.
 *
 * `ok` یعنی: تماس رفت، پاسخِ ارائه‌دهنده آمد و پولِ نگه‌دارده تسویه شد.
 * هر چیزِ دیگر `ok=false` است و `code` — کدِ پایدارِ API — علتش را
 * بدونِ جزئیاتِ داخلی می‌گوید؛ مسیرِ `/v1` (M4-b) همین کد و پیام را
 * بی‌واسطه چاپ می‌کند (همان قراردادِ کدهایِ admission).
 *
 * `reservation` در مسیرِ سبز رزروِ settled و در مسیرِ شکستِ بالادست
 * رزروِ released است — برای ممیزی/بازسازیِ نتیجه با کلیدِ هم‌ارزی.
 */
final class AiCallOutcome
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly string $message = '',
        public readonly ?array $response = null,        // بدنهٔ خامِ ارائه‌دهنده (فقط مسیرِ سبز)
        public readonly ?AiReservation $reservation = null,
        public readonly int $reservedMicros = 0,        // مبلغِ رزروشده — میکرو-واحدِ سطرِ قیمت
    ) {}

    public static function fail(string $code, string $message, ?AiReservation $r = null): self
    {
        return new self(false, $code, $message, null, $r, 0);
    }
}
