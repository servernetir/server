<?php

namespace App\Services\Ai;

/**
 * قیمتِ منجمدِ یک مدل در یک لحظه — ورودیِ همهٔ محاسبه‌های بعدی (D1).
 *
 * `pIn/pCached/pOut` تومانِ **درست** به ازای یک میلیون توکن‌اند و هم روی
 * صفحهٔ قیمت نشان داده می‌شوند و هم ضریبِ صورت‌حساب‌اند؛ پس مشتری هر شارژ را
 * با `Σ توکن × P ÷ ۱۰⁶` خودش بازتولید می‌کند. `rIn/rCached/rOut` بهای خامِ
 * ارائه‌دهنده (میکرو-ارز به ازای ۱M) است و فقط برای کفِ ضدِ ضرر و هزینه به کار
 * می‌رود. وقتی نرخِ کش‌شده ثبت نشده، `rCached = rIn` و `pCached = pIn` —
 * توکنِ کش‌شده به قیمتِ کامل فروخته می‌شود، هرگز زیرِ بها.
 */
final class AiPriceQuote
{
    public function __construct(
        public readonly int $modelId,
        public readonly int $providerId,
        public readonly string $currency,
        public readonly AiFxQuote $fx,
        public readonly int $feeBp,
        public readonly int $marginBp,
        /** model | global — حاشیه از کجا آمد */
        public readonly string $marginSource,
        public readonly int $rIn,
        public readonly int $rCached,
        public readonly int $rOut,
        public readonly int $inputPriceId,
        public readonly ?int $cachedPriceId,
        public readonly int $outputPriceId,
        public readonly int $pIn,
        public readonly int $pCached,
        public readonly int $pOut,
    ) {}

    public function rate(): int
    {
        return $this->fx->rate;
    }
}
