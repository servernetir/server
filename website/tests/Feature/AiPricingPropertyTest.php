<?php

namespace Tests\Feature;

use App\Services\Ai\AiFxQuote;
use App\Services\Ai\AiPriceQuote;
use App\Services\Ai\AiPricing;
use Tests\TestCase;

/**
 * خطِ قرمز به‌صورتِ ویژگی — ۱۰٬۰۰۰ حالتِ تصادفیِ بذرگذاری‌شده (m5-spec §3 «Red-line proof»).
 *
 *   ۱) S ≥ cost_irt        هرگز زیرِ بها — بی‌قید و شرط
 *   ۲) S_book ≥ S_floor    قیمتِ اعلام‌شده خودش کفِ ضدِ ضرر را می‌پوشاند
 *   ۳) charged ≤ H         وقتی p ≤ I و o ≤ O، شارژ هرگز از رزرو بیرون نمی‌زند
 *
 * بذر ثابت است تا شکستِ احتمالی بازتولیدپذیر باشد؛ پیامِ خطا همهٔ ورودی‌ها را
 * چاپ می‌کند.
 */
class AiPricingPropertyTest extends TestCase
{
    private const CASES = 10_000;

    public function test_never_below_cost_and_never_above_the_hold(): void
    {
        mt_srand(20260923);
        $pricing = app(AiPricing::class);

        for ($i = 0; $i < self::CASES; $i++) {
            $R = mt_rand(20_000, 5_000_000);
            $f = mt_rand(0, 2_500);
            $m = mt_rand(1, 50_000);
            $t = mt_rand(0, 2_000);

            // نرخ‌ها گاهی ریز (۱ میکرو) و گاهی نزدیکِ سقف — لبه‌ها بیشترین باگ را دارند
            $rIn = mt_rand(0, 3) === 0 ? mt_rand(1, 50) : mt_rand(1, 1_000_000_000);
            $rCached = mt_rand(1, $rIn);
            $rOut = mt_rand(0, 3) === 0 ? mt_rand(1, 50) : mt_rand(1, 1_000_000_000);

            $I = mt_rand(1, 200_000);
            $O = mt_rand(1, 32_768);
            $p = mt_rand(0, $I);
            $c = mt_rand(0, $p);
            $o = mt_rand(0, $O);

            $q = new AiPriceQuote(
                modelId: 1, providerId: 1, currency: 'USD',
                fx: new AiFxQuote('USD', $R, 'scraped', null),
                feeBp: $f, marginBp: $m, marginSource: 'global',
                rIn: $rIn, rCached: $rCached, rOut: $rOut,
                inputPriceId: 1, cachedPriceId: 2, outputPriceId: 3,
                pIn: AiPricing::perMillion($rIn, $R, $f, $m),
                pCached: AiPricing::perMillion($rCached, $R, $f, $m),
                pOut: AiPricing::perMillion($rOut, $R, $f, $m),
            );

            $charge = $pricing->charge($q, $p, $c, $o, $t);
            $hold = $pricing->hold($q, $I, $O, $t);

            $ctx = json_encode(compact('i', 'R', 'f', 'm', 't', 'rIn', 'rCached', 'rOut', 'I', 'O', 'p', 'c', 'o') + [
                'charge' => $charge, 'hold' => $hold,
            ]);

            $this->assertGreaterThanOrEqual($charge['cost_irt'], $charge['sell'], "زیرِ بها: {$ctx}");
            $this->assertGreaterThanOrEqual($charge['sell_floor'], $charge['sell_book'], "قیمتِ دفتر زیرِ کف: {$ctx}");
            $this->assertLessThanOrEqual($hold['total'], $charge['charged'], "شارژ بیش از رزرو: {$ctx}");
            $this->assertLessThanOrEqual($hold['tax'], $charge['tax'], "مالیات بیش از مالیاتِ رزرو: {$ctx}");
        }
    }
}
