<?php

namespace App\Services\Ai;

/**
 * نرخِ ارزِ منجمدِ یک قیمت‌گذاری — همان عددی که روی ردیفِ مصرف ذخیره می‌شود
 * تا هر شارژ بعداً از روی خودش بازتولید شود.
 */
final class AiFxQuote
{
    public function __construct(
        public readonly string $currency,
        /** تومان به ازای یک واحدِ ارز */
        public readonly int $rate,
        /** scraped (نوسان) | ratchet | scraped+stale؛ override فقط در ردیف‌های تاریخی ممکن است */
        public readonly string $source,
        /** زمانِ نرخِ ذخیره‌شدهٔ نوسان؛ در ردیف‌های تاریخی ممکن است null باشد */
        public readonly ?string $at,
        /** نشانِ بالاترین نرخ پیش از این قیمت‌گذاری (برای نمایشِ ضامنِ افت) */
        public readonly ?int $highWater = null,
    ) {}
}
