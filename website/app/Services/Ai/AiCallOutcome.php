<?php

namespace App\Services\Ai;

use App\Models\AiUsage;

/**
 * نتیجهٔ یک تماسِ /v1 — حکمِ نهاییِ `AiCaller::handle` (M5.1b).
 *
 * `status` وضعیتِ HTTP ِ پاسخ است و `code` کدِ پایدارِ API؛ کنترلر هیچ‌کدام را
 * بازنویسی نمی‌کند. `headers` شاملِ `X-Request-Id` (شناسهٔ عمومیِ ردیفِ مصرف) و، پس از
 * تسویه، `X-ServerNet-Charge-Irt` است. روی هر خطایی که پولی برداشته شده یا ممکن است
 * برداشته شود، `x-should-retry: false` می‌آید: SDK ها ۵xx را خودکار تکرار می‌کنند و
 * تکرار این‌جا یعنی خریدنِ همان پاسخ برای بارِ دوم (G5).
 */
final class AiCallOutcome
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly bool $ok,
        public readonly string $code,
        public readonly string $message = '',
        public readonly ?array $response = null,
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly ?AiUsage $usage = null,
    ) {}

    public static function fail(string $code, string $message, int $status, array $headers = [], ?AiUsage $usage = null): self
    {
        return new self(false, $code, $message, null, $status, $headers, $usage);
    }
}
