<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * ═══ قیمت‌گذاریِ فروشِ AI — یک فرمول، یک مسیر (m5-spec §3) ═══
 *
 * همین کلاس قیمتِ صفحهٔ /ai، `/v1/models`، پنلِ مشتری **و** شارژِ هر تماس را
 * می‌سازد. جدولِ قیمتِ فروشِ ذخیره‌شده و کارِ زمان‌بندی‌شدهٔ «بازقیمت‌گذاری»
 * عمداً وجود ندارد: کارِ زمان‌بندی‌شده‌ای که بی‌صدا بمیرد، قیمتِ منتشرشده را
 * زیرِ بها جا می‌گذارد (همان اتفاقِ نرخِ ساعتیِ ابری).
 *
 *   K     = (10⁴ + f) · (10⁴ + m)                        f سربارِ ارز، m حاشیه (bp)
 *   P_x   = ⌈ r_x · R · K / 10¹⁴ ⌉                       تومان به ازای ۱M توکن
 *   S     = max(⌈Σ n·P / 10⁶⌉ , ⌈N_b · R · K / 10²⁰⌉)     درآمد، بی‌مالیات
 *   T     = ⌈ S · t / 10⁴ ⌉                               مالیات، روی قیمت
 *   cost  = ⌈ N_b · R · (10⁴ + f) / 10¹⁶ ⌉               بهای تمام‌شده
 *
 * ═══ خطِ قرمز: هرگز زیرِ بها ═══
 *
 * هر گام **به بالا** گرد می‌شود و فقط با عددِ صحیحِ `BigInteger` — هیچ float
 * در هیچ جا. چون P_x ≥ r_x·R·K/10¹⁴ دقیقاً، داریم S_book ≥ S_floor؛ و چون
 * K ≥ (10⁴+f)·10⁴ برای m ≥ 0، داریم S_floor ≥ cost. پس S ≥ cost برای **هر**
 * تماس، بی‌قید و شرط — `AiPricingPropertyTest` این را روی ۱۰٬۰۰۰ حالتِ تصادفی
 * می‌آزماید. مالیات روی S سوار است و در این مقایسه نیست، پس حاشیهٔ نازک هرگز
 * خرجِ مالیات نمی‌شود.
 *
 * ═══ فروش بسته می‌ماند تا مالک عدد بدهد ═══
 *
 * حاشیه (`ai_margin_pct`) پیش‌فرض ندارد — نه ۴۵ِ ابری، نه هیچ عددِ دیگر. خالی ⇒
 * `pricing_incomplete` ⇒ فروش بسته. سربارِ ارزِ ارائه‌دهنده (`fx_fee_bp`) هم
 * NULL ⇒ همان. عددِ حدسی یعنی فروش با حاشیه‌ای که کسی تأییدش نکرده.
 */
final class AiPricing
{
    /** بالاترین حاشیهٔ قابلِ ثبت: ۵۰۰٪ */
    public const MAX_MARGIN_BP = 50_000;

    /** بالاترین سربارِ ارز: ۲۵٪ */
    public const MAX_FEE_BP = 2_500;

    public function __construct(
        private readonly AiFx $fx,
        private readonly PriceBook $book,
    ) {}

    /* ════════════════════ قیمتِ یک مدل ════════════════════ */

    /**
     * قیمتِ منجمدِ مدل در همین لحظه.
     *
     * @throws AiPricingException pricing_incomplete (با دلیل‌ها) یا fx_unavailable
     */
    public function quote(AiModel $model): AiPriceQuote
    {
        $model->loadMissing('provider');
        $provider = $model->provider;
        $reasons = [];

        if ($provider === null) {
            throw new AiPricingException(AiPricingException::PRICING_INCOMPLETE, ['provider_missing']);
        }

        $fee = $provider->getAttribute('fx_fee_bp');
        if ($fee === null) {
            $reasons[] = 'fee_unset';
        } elseif ((int) $fee < 0 || (int) $fee > self::MAX_FEE_BP) {
            $reasons[] = 'fee_invalid';
        }

        [$margin, $marginSource, $marginReason] = $this->marginFor($model);
        if ($marginReason !== null) {
            $reasons[] = $marginReason;
        }

        try {
            $rows = $this->book->sellRates($model);
        } catch (AiPricingException $e) {
            $reasons = array_merge($reasons, $e->reasons);
            $rows = null;
        }

        if ($reasons !== []) {
            throw new AiPricingException(AiPricingException::PRICING_INCOMPLETE, array_values(array_unique($reasons)));
        }

        $currency = strtoupper((string) $provider->billing_currency_code);
        $fx = $this->fx->quote($currency);

        if ($fx === null) {
            throw new AiPricingException(AiPricingException::FX_UNAVAILABLE, ['fx_'.strtolower($currency)]);
        }

        $fee = (int) $fee;
        $rIn = (int) $rows['input']->price_micro_units;
        $rOut = (int) $rows['output']->price_micro_units;
        // نرخِ کش‌شدهٔ ثبت‌نشده ⇒ به قیمتِ کاملِ ورودی؛ هرگز تخفیفِ فرضی
        $rCached = $rows['cached'] !== null ? (int) $rows['cached']->price_micro_units : $rIn;

        return new AiPriceQuote(
            modelId: (int) $model->id,
            providerId: (int) $provider->id,
            currency: $currency,
            fx: $fx,
            feeBp: $fee,
            marginBp: $margin,
            marginSource: $marginSource,
            rIn: $rIn,
            rCached: $rCached,
            rOut: $rOut,
            inputPriceId: (int) $rows['input']->id,
            cachedPriceId: $rows['cached']?->id,
            outputPriceId: (int) $rows['output']->id,
            pIn: self::perMillion($rIn, $fx->rate, $fee, $margin),
            pCached: self::perMillion($rCached, $fx->rate, $fee, $margin),
            pOut: self::perMillion($rOut, $fx->rate, $fee, $margin),
        );
    }

    /**
     * سدهای **فروش** (نه قیمت) — برای پیش‌نمایشِ مدیر. خالی یعنی از نظرِ
     * پرچم‌ها فروختنی است؛ M5.1b همین شرط‌ها را در مسیرِ /v1 اجباری می‌کند.
     *
     * @return list<string>
     */
    public function saleGates(AiModel $model): array
    {
        $model->loadMissing('provider');
        $p = $model->provider;
        $gates = [];

        if ($model->status !== AiModel::STATUS_ACTIVE) {
            $gates[] = 'model_inactive';
        }
        if ($model->category !== AiModel::CATEGORY_CHAT) {
            $gates[] = 'model_not_chat';
        }
        if ($p === null) {
            return [...$gates, 'provider_missing'];
        }
        foreach ([
            'enabled' => 'provider_disabled',
            'live_calls_enabled' => 'provider_not_live',
            'commercial_enabled' => 'provider_not_commercial',
            'resale_allowed' => 'resale_not_allowed',
        ] as $flag => $code) {
            if (! $p->{$flag}) {
                $gates[] = $code;
            }
        }
        if (! $p->isAgreedTo()) {
            $gates[] = 'agreement_unsigned';
        }
        if (Setting::get('ai_sales_open') !== '1') {
            $gates[] = 'sales_closed';
        }

        return $gates;
    }

    /* ════════════════════ حاشیه ════════════════════ */

    /**
     * حاشیهٔ سراسری به bp، یا null یعنی «مالک هنوز عدد نداده» ⇒ فروش بسته.
     */
    public static function globalMarginBp(): ?int
    {
        $bp = self::percentToBp(Setting::get('ai_margin_pct'));

        return $bp !== null && $bp >= 1 && $bp <= self::MAX_MARGIN_BP ? $bp : null;
    }

    /**
     * «۱۲.۵» ⇒ 1250 — از رشته، رقم به رقم، **بی float**.
     *
     * float این‌جا ممنوع است چون ۰.۱+۰.۲ در دودویی ۰.۳ نیست و یک bp گم‌شده
     * روی میلیون‌ها تماس یعنی پولِ واقعی. بیش از دو رقمِ اعشار رد می‌شود نه گرد:
     * مالک باید همان عددی را ببیند که تایپ کرده.
     */
    public static function percentToBp(?string $value): ?int
    {
        $v = strtr(trim((string) $value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.',
        ]);

        if (preg_match('/^(\d{1,5})(?:\.(\d{1,2}))?$/', $v, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** bp ⇒ «۱۲.۵» برای نمایش در فرم (برعکسِ percentToBp، بی float) */
    public static function bpToPercent(?int $bp): string
    {
        if ($bp === null) {
            return '';
        }
        $frac = rtrim(str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT), '0');

        return intdiv($bp, 100).($frac !== '' ? '.'.$frac : '');
    }

    /** @return array{0:int,1:string,2:?string} [bp, source, blockingReason] */
    private function marginFor(AiModel $model): array
    {
        $own = $model->getAttribute('margin_bp');

        if ($own !== null) {
            $own = (int) $own;

            return $own >= 1 && $own <= self::MAX_MARGIN_BP
                ? [$own, 'model', null]
                : [0, 'model', 'margin_invalid'];
        }

        $global = self::globalMarginBp();

        return $global === null ? [0, 'global', 'margin_unset'] : [$global, 'global', null];
    }

    /* ════════════════════ فرمول‌ها — همه عددِ صحیح ════════════════════ */

    /** P = ⌈ r · R · (10⁴+f)(10⁴+m) / 10¹⁴ ⌉ — تومان به ازای یک میلیون توکن */
    public static function perMillion(int $rateMicro, int $rate, int $feeBp, int $marginBp): int
    {
        return self::toInt(
            BigInteger::of($rateMicro)->multipliedBy($rate)->multipliedBy(self::k($feeBp, $marginBp))
                ->dividedBy(BigInteger::ten()->power(14), RoundingMode::Up)
        );
    }

    /**
     * شارژِ یک تماس از مصرفِ واقعی (m5-spec §4.C، گام‌های C2 تا C5).
     *
     * `$estimatedCost` رشتهٔ `usage.estimated_cost` ارائه‌دهنده است (به واحدِ
     * ارز، مثل "0.0022"). اگر از بهای دفترِ ما بیشتر بود، مبنای کف همان می‌شود:
     * سطرِ قیمتِ کهنه محتمل‌ترین ضررِ بی‌صداست و ارائه‌دهنده بهتر از ما می‌داند
     * چقدر از ما کم می‌کند.
     *
     * @return array{u:int,c:int,o:int,cost_micro:int,estimated_micro:?int,cost_irt:int,
     *               sell_book:int,sell_floor:int,sell:int,tax:int,charged:int,cost_drift:bool}
     *
     * @throws AiPricingException request_too_large اگر عدد از int64 بیرون بزند
     */
    public function charge(AiPriceQuote $q, int $prompt, int $cached, int $completion, int $vatBp, ?string $estimatedCost = null): array
    {
        $prompt = max(0, $prompt);
        $c = max(0, min($cached, $prompt));      // کش‌شده هرگز بیشتر از کلِ ورودی نیست
        $u = $prompt - $c;
        $o = max(0, $completion);

        // N — دقیق، میکرو-ارز × توکن
        $n = BigInteger::of($u)->multipliedBy($q->rIn)
            ->plus(BigInteger::of($c)->multipliedBy($q->rCached))
            ->plus(BigInteger::of($o)->multipliedBy($q->rOut));

        $costMicro = self::toInt($n->dividedBy(1_000_000, RoundingMode::Up));

        $e = self::estimatedMicros($estimatedCost);
        $nb = $e !== null ? BigInteger::max($n, BigInteger::of($e)->multipliedBy(1_000_000)) : $n;
        // رانشِ بیش از ۱٪ ⇒ سطرِ قیمتِ ما احتمالاً کهنه است
        $drift = $e !== null && BigInteger::of($e)->multipliedBy(100)->isGreaterThan(BigInteger::of($costMicro)->multipliedBy(101));

        $r = $q->rate();
        $k = self::k($q->feeBp, $q->marginBp);

        $book = self::toInt(
            BigInteger::of($u)->multipliedBy($q->pIn)
                ->plus(BigInteger::of($c)->multipliedBy($q->pCached))
                ->plus(BigInteger::of($o)->multipliedBy($q->pOut))
                ->dividedBy(1_000_000, RoundingMode::Up)
        );
        $floor = self::toInt($nb->multipliedBy($r)->multipliedBy($k)->dividedBy(BigInteger::ten()->power(20), RoundingMode::Up));
        $costIrt = self::toInt($nb->multipliedBy($r)->multipliedBy(10_000 + $q->feeBp)->dividedBy(BigInteger::ten()->power(16), RoundingMode::Up));

        $sell = max($book, $floor);
        $tax = self::tax($sell, $vatBp);

        return [
            'u' => $u, 'c' => $c, 'o' => $o,
            'cost_micro' => $costMicro,
            'estimated_micro' => $e,
            'cost_irt' => $costIrt,
            'sell_book' => $book,
            'sell_floor' => $floor,
            'sell' => $sell,
            'tax' => $tax,
            'charged' => self::toInt(BigInteger::of($sell)->plus($tax)),
            'cost_drift' => $drift,
        ];
    }

    /**
     * سقفِ رزرو برای I توکنِ ورودی و O توکنِ خروجی (m5-spec §3 «Hold»).
     *
     *   H_s = ⌈ (I·P_in + O·P_out) · (10⁴ + b) / 10¹⁰ ⌉ ,  H_t = ⌈ H_s · t / 10⁴ ⌉
     *
     * چون P_cached ≤ P_in، هر مصرفی با p ≤ I و o ≤ O شارژی ≤ H دارد.
     *
     * @return array{sell:int,tax:int,total:int}
     */
    public function hold(AiPriceQuote $q, int $maxInput, int $maxOutput, int $vatBp): array
    {
        $b = (int) config('ai.hold_buffer_bp', 1000);

        $sell = self::toInt(
            BigInteger::of(max(0, $maxInput))->multipliedBy($q->pIn)
                ->plus(BigInteger::of(max(0, $maxOutput))->multipliedBy($q->pOut))
                ->multipliedBy(10_000 + $b)
                ->dividedBy(BigInteger::ten()->power(10), RoundingMode::Up)
        );
        $tax = self::tax($sell, $vatBp);

        // جمع هم از BigInteger: `+` ِ PHP در سرریز بی‌صدا float می‌شود، نه خطا
        return ['sell' => $sell, 'tax' => $tax, 'total' => self::toInt(BigInteger::of($sell)->plus($tax))];
    }

    /** T = ⌈ S · t / 10⁴ ⌉ */
    public static function tax(int $sell, int $vatBp): int
    {
        return self::toInt(BigInteger::of($sell)->multipliedBy(max(0, $vatBp))->dividedBy(10_000, RoundingMode::Up));
    }

    /**
     * یورو به ازای ۱M توکن **فقط برای نمایش** (D9)، به ده‌هزارمِ یورو:
     * ⌈ P · 10⁴ / R_eur ⌉. به بالا گرد می‌شود تا عددِ نمایش هرگز کمتر از
     * تومانی که واقعاً کم می‌شود نباشد.
     */
    public static function eurTenThousandths(int $toman, int $eurRate): ?int
    {
        if ($eurRate <= 0) {
            return null;
        }

        return self::toInt(BigInteger::of($toman)->multipliedBy(10_000)->dividedBy($eurRate, RoundingMode::Up));
    }

    /* ─────────────────────────────────────────────────────────── */

    private static function k(int $feeBp, int $marginBp): BigInteger
    {
        return BigInteger::of(10_000 + $feeBp)->multipliedBy(10_000 + $marginBp);
    }

    /** ⌈estimated_cost · 10⁶⌉ از رشته؛ منفی یا نامعتبر ⇒ null (نادیده، نه صفر) */
    private static function estimatedMicros(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        try {
            $d = BigDecimal::of(trim($raw));
        } catch (MathException) {
            return null;
        }

        if ($d->isNegative()) {
            return null;
        }

        return self::toInt($d->multipliedBy(1_000_000)->toScale(0, RoundingMode::Up)->toBigInteger());
    }

    private static function toInt(BigInteger $v): int
    {
        try {
            return $v->toInt();
        } catch (MathException) {
            throw new AiPricingException(AiPricingException::TOO_LARGE);
        }
    }
}
