<?php

namespace App\Services\Ai;

use App\Models\AiCall;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiReservation;
use App\Models\AiUsage;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\AiProviders\AiProviderCallException;
use App\Services\AiProviders\AiProviderDriver;
use App\Services\AiProviders\OpenAiCompatibleDriver;
use App\Services\Finance\Wallet;
use App\Support\ErrorTracker;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * تنها ارکستراتورِ «یک تماسِ AI ِ پول‌خور» — موتورِ پولیِ M5 (m5-spec §4).
 *
 * ═══ ترتیبِ ثابت ═══
 *
 *   A1 سدها — درِ فروش، مدل، ارائه‌دهنده، قیمت، نرخِ ارز، مالیات (هیچ پولی نگه داشته نمی‌شود)
 *   A2 بدنه — max_tokens اجباری، فهرستِ سفید، سقفِ بایتیِ ورودی (`AiPayload`)
 *   A5 رزرو — H = سقفِ فروش + مالیات، به **تومان** (`AiPricing::hold`)
 *   A6 کیفِ کوچک — اگر مشتری max_tokens نفرستاده و H بیش از موجودی است، O پایین می‌آید
 *   A7 قفلِ مشتری — جاروبِ ردیف‌های کهنهٔ خودش، هم‌ارزی، موجودی، سقفِ هم‌زمانی، بودجه؛
 *      سپس رزرو (بی‌مهلت، pricing_version=1) + ردیفِ مصرف با همهٔ قیمت‌های منجمد
 *   B  ارسال — UPDATE ِ شرطیِ reserved⇒sending؛ صفر ردیف یعنی آشتی‌دهنده آزادش کرده: نفرست
 *   C  تسویه از مصرفِ واقعی (`AiSettlement`)
 *   G  نامعلوم ⇒ نگه‌داشتن، بازیابی، سپس سقف
 *
 * ═══ چه چیزی عوض شد و چرا (B1، B2، B3، B6، B7، B8) ═══
 *
 * نسخهٔ M4 میکرودلار را به‌جای تومان رزرو و کسر می‌کرد (B1)، حاشیه نداشت (B2)، کلِ رزرو
 * را به‌جای مصرفِ واقعی می‌گرفت (B3)، `max_tokens` را به بالادست تحمیل نمی‌کرد (B6)،
 * پرچم‌های فروشِ ارائه‌دهنده را نمی‌خواند (B7) و مهلتِ خواندن را «نرسید» می‌شمرد و
 * هزینهٔ بالادست را می‌بخشید (B8). هر کدام این‌جا یک سدِ صریح دارد.
 *
 * ═══ قاعدهٔ شکست (§5) ═══
 *
 * شارژ به این بسته است که «آیا ارائه‌دهنده ممکن است کارِ پول‌دار انجام داده باشد»، نه
 * اینکه مشتری بدنه‌ای گرفت یا نه. پس از موفقیتِ بالادست هرگز ۵xx برنمی‌گردد (G5).
 */
final class AiCaller
{
    public function __construct(
        private readonly AiModelRegistry $registry,
        private readonly AiPayload $payload,
        private readonly AiPricing $pricing,
        private readonly AiVat $vat,
        private readonly AiSettlement $settlement,
        private readonly Wallet $wallet,
    ) {}

    /**
     * @param  array  $client  بدنهٔ خامِ مشتری، **همراهِ** `model`
     */
    public function handle(AiAuthContext $auth, array $client, ?string $idempotencyKey = null): AiCallOutcome
    {
        if (! $auth->ok) {
            return AiCallOutcome::fail($auth->code, $auth->message !== '' ? $auth->message : 'دسترسی پذیرفته نشد.', 403);
        }

        $customer = $auth->customer;

        // ── A1: درِ فروش — پیش از هر چیزِ دیگر، تا با فروشِ بسته هیچ مسیری به پول نرسد ──
        if (! self::salesOpenFor((int) $customer->id)) {
            return AiCallOutcome::fail('sales_closed', 'فروشِ سرویسِ هوش مصنوعی هنوز باز نشده است.', 503);
        }

        if ($idempotencyKey !== null && ! AiPayload::validIdempotencyKey($idempotencyKey)) {
            return AiCallOutcome::fail('invalid_idempotency_key',
                'Idempotency-Key حداکثر ۸۰ نویسه از A-Z a-z 0-9 . _ : - است.', 400);
        }

        $slug = is_string($client['model'] ?? null) ? trim($client['model']) : '';
        $model = $slug === '' ? null : $this->registry->model($slug);

        if ($model === null || $model->category !== AiModel::CATEGORY_CHAT) {
            return AiCallOutcome::fail('model_not_found', 'چنین مدلِ چتی شناخته نشد.', 404);
        }
        if ($model->status !== AiModel::STATUS_ACTIVE) {
            return AiCallOutcome::fail('model_inactive', 'این مدل فعال نیست.', 503);
        }
        if ($model->suspended_at !== null) {
            return AiCallOutcome::fail('model_suspended', 'این مدل موقتاً معلق است.', 503);
        }

        if (($gate = $this->providerGate($model->provider)) !== null) {
            return $gate;
        }

        if ($auth->token === null || ! $auth->token->can('ai:chat')) {
            return AiCallOutcome::fail('insufficient_scope', 'کلیدِ این تماس سطحِ «ai:chat» ندارد.', 403);
        }

        $driver = self::driverFor($model->provider);
        if ($driver === null) {
            return AiCallOutcome::fail('driver_unsupported', 'درایورِ ارائه‌دهندهٔ این مدل پیاده نشده است.', 503);
        }

        // ── A2–A3: بدنه ──
        try {
            $prepared = $this->payload->prepare($client, $model);
        } catch (AiRequestException $e) {
            return AiCallOutcome::fail($e->errorCode, $e->getMessage(), $e->status);
        }

        // ── A4: قیمت و نرخِ ارز — فقط از کش؛ ناقص ⇒ فروش بسته، هرگز حدس ──
        try {
            $quote = $this->pricing->quote($model);
        } catch (AiPricingException $e) {
            return $this->pricingFailure($e);
        }

        $vat = $this->vat->resolve($customer);

        // ── A5–A6: رزرو ──
        try {
            [$prepared, $hold] = $this->sizeHold($prepared, $quote, $vat['bp'], (int) $customer->id);
        } catch (AiPricingException $e) {
            return $this->pricingFailure($e);
        } catch (AiGateRefusal $r) {
            return $r->outcome;
        }

        // ── A7: قفلِ مشتری ──
        $opened = $this->open($auth, $model, $quote, $vat, $prepared, $hold, $idempotencyKey);
        if ($opened instanceof AiCallOutcome) {
            return $opened;
        }
        if ($opened['replay'] ?? null) {
            return $this->replay($opened['replay'], $idempotencyKey);
        }

        /** @var AiUsage $usage */
        $usage = $opened['usage'];

        return $this->send($usage, $model, $driver, $prepared, $idempotencyKey);
    }

    /** درِ فروش: `ai_sales_open = 1` یا مشتری در فهرستِ آزمایشی */
    public static function salesOpenFor(int $customerId): bool
    {
        if (Setting::get('ai_sales_open') === '1') {
            return true;
        }

        $ids = array_map('intval', array_filter(explode(',', (string) Setting::get('ai_canary_customer_ids', ''))));

        return in_array($customerId, $ids, true);
    }

    /** شناسهٔ درایور ⇒ کلاس. ناشناخته = «پیاده نشده»، نه خطای خام (D16) */
    public static function driverFor(?AiProvider $provider): ?AiProviderDriver
    {
        return match ($provider?->driver) {
            'OpenAI-Compatible' => new OpenAiCompatibleDriver($provider),
            default => null,
        };
    }

    /* ═══════════════════════ A1: ارائه‌دهنده ═══════════════════════ */

    private function providerGate(?AiProvider $p): ?AiCallOutcome
    {
        if ($p === null || ! $p->isLiveCapable()) {
            return AiCallOutcome::fail('provider_not_live', 'ارائه‌دهندهٔ این مدل فعال نیست.', 503);
        }
        // B7: پرچم‌های قرارداد — بی‌این‌ها فروشِ دوباره مجاز نیست
        if (! $p->commercial_enabled || ! $p->resale_allowed || ! $p->isAgreedTo()) {
            return AiCallOutcome::fail('provider_not_sellable', 'فروشِ این ارائه‌دهنده روشن نیست.', 503);
        }
        if ($p->isPaused()) {
            return AiCallOutcome::fail('provider_paused', 'ارائه‌دهنده موقتاً متوقف است.', 503);
        }

        $cap = (int) ($p->daily_cost_cap_micro ?? 0);
        if ($cap > 0) {
            $spent = (int) AiUsage::where('ai_provider_id', $p->id)
                ->where('sent_at', '>=', now()->utc()->startOfDay())
                ->sum('cost_micro');
            if ($spent >= $cap) {
                return AiCallOutcome::fail('provider_daily_cap', 'سقفِ روزانهٔ هزینهٔ این ارائه‌دهنده پر شده است.', 503);
            }
        }

        return null;
    }

    private function pricingFailure(AiPricingException $e): AiCallOutcome
    {
        return match ($e->errorCode) {
            AiPricingException::TOO_LARGE => AiCallOutcome::fail('request_too_large', 'درخواست بیش از حدِ مجاز بزرگ است.', 413),
            AiPricingException::FX_UNAVAILABLE => AiCallOutcome::fail('fx_unavailable', 'نرخِ ارز در دسترس نیست؛ کمی بعد دوباره تلاش کنید.', 503),
            default => AiCallOutcome::fail('pricing_incomplete', 'قیمت‌گذاریِ این مدل کامل نیست.', 503),
        };
    }

    /* ═══════════════════════ A5–A6: اندازهٔ رزرو ═══════════════════════ */

    /**
     * @return array{0:AiPreparedRequest,1:array{sell:int,tax:int,total:int}}
     *
     * @throws AiGateRefusal|AiPricingException
     */
    private function sizeHold(AiPreparedRequest $prepared, AiPriceQuote $q, int $vatBp, int $customerId): array
    {
        $hold = $this->pricing->hold($q, $prepared->maxInput, $prepared->maxOutput, $vatBp);
        $available = $this->wallet->availableOf($customerId, 'IRT');

        if ($hold['total'] <= $available || ! $prepared->clientOmittedMaxTokens) {
            return [$prepared, $hold];
        }

        /*
        | G2 — کیفِ کوچک: مشتری سقفِ خروجی نخواسته و سقفِ مدل بیش از موجودی است؛ به‌جای
        | ۴۰۲، خروجی را تا جایی که کیف می‌خرد پایین می‌آوریم (کف ۲۵۶ توکن).
        |   net = ⌊avail·10⁴/(10⁴+t)⌋ ،  O' = ⌊(net·10⁶·10⁴/(10⁴+b) − I·P_in) / P_out⌋
        | ⌊⌋ هر دو رو به پایین‌اند تا H ِ حاصل هرگز از موجودی بیشتر نشود.
        */
        $b = (int) config('ai.hold_buffer_bp', 1000);
        $net = intdiv(max(0, $available) * 10_000, 10_000 + $vatBp);
        $budget = BigInteger::of($net)->multipliedBy(1_000_000)->multipliedBy(10_000)
            ->dividedBy(10_000 + $b, RoundingMode::Down)
            ->minus(BigInteger::of($prepared->maxInput)->multipliedBy($q->pIn));
        $output = $budget->isNegative() ? 0 : $budget->dividedBy(max(1, $q->pOut), RoundingMode::Down)->toInt();
        $output = min($output, $prepared->outputCap);

        if ($output >= (int) config('ai.min_balance_aware_output', 256)) {
            $prepared = $prepared->withOutput($output);
            $hold = $this->pricing->hold($q, $prepared->maxInput, $output, $vatBp);
            if ($hold['total'] <= $available) {
                return [$prepared, $hold];
            }
        }

        throw new AiGateRefusal(AiCallOutcome::fail('insufficient_funds',
            'اعتبارِ در دسترس کافی نیست: این درخواست '.number_format($hold['total'])
            .' تومان رزرو لازم دارد و '.number_format(max(0, $available)).' تومان در دسترس است.', 402));
    }

    /* ═══════════════════════ A7: قفلِ مشتری ═══════════════════════ */

    /**
     * @return AiCallOutcome|array{usage?:AiUsage,replay?:AiUsage}
     */
    private function open(AiAuthContext $auth, AiModel $model, AiPriceQuote $q, array $vat,
        AiPreparedRequest $prepared, array $hold, ?string $key): AiCallOutcome|array
    {
        $customerId = (int) $auth->customer->id;
        $tHttp = self::httpTimeout($prepared->maxOutput);

        try {
            return DB::transaction(function () use ($auth, $model, $q, $vat, $prepared, $hold, $key, $customerId, $tHttp) {
                Customer::whereKey($customerId)->lockForUpdate()->first();

                $this->sweepOwn($customerId);

                if ($key !== null) {
                    $prev = AiUsage::where('customer_id', $customerId)->where('idempotency_key', $key)
                        ->where('status', '!=', AiUsage::STATUS_RELEASED)->orderByDesc('id')->first();

                    if ($prev !== null) {
                        return match (true) {
                            $prev->request_sha256 !== $prepared->sha256 => AiCallOutcome::fail('idempotency_key_reused',
                                'این Idempotency-Key پیش‌تر با بدنهٔ دیگری به کار رفته است.', 422),
                            $prev->status === AiUsage::STATUS_SETTLED => ['replay' => $prev],
                            $prev->status === AiUsage::STATUS_UNKNOWN_CHARGED => AiCallOutcome::fail('upstream_outcome_unknown',
                                'نتیجهٔ تماسِ قبلی با این کلید نامعلوم ماند و شارژ شد؛ تکرار یعنی تماسِ تازه با کلیدِ تازه.', 409,
                                ['x-should-retry' => 'false', 'X-Request-Id' => $prev->public_id]),
                            default => AiCallOutcome::fail('request_in_progress', 'تماسِ قبلی با همین کلید هنوز در جریان است.', 409,
                                ['Retry-After' => '5']),
                        };
                    }
                }

                $available = $this->wallet->availableOf($customerId, 'IRT');
                if ($available < $hold['total']) {
                    return AiCallOutcome::fail('insufficient_funds', 'اعتبارِ در دسترس کافی نیست: این درخواست '
                        .number_format($hold['total']).' تومان رزرو لازم دارد و '.number_format(max(0, $available)).' تومان در دسترس است.', 402);
                }

                $open = AiUsage::where('customer_id', $customerId)->whereIn('status', AiUsage::OPEN)->count();
                if ($open >= (int) config('ai.inflight_per_customer', 4)) {
                    return AiCallOutcome::fail('too_many_inflight', 'تماس‌های هم‌زمانِ این حساب بیش از حد است.', 429, ['Retry-After' => '5']);
                }
                $global = AiUsage::whereIn('status', [AiUsage::STATUS_SENDING, AiUsage::STATUS_STREAMING])->count();
                if ($global >= (int) config('ai.inflight_global', 8)) {
                    return AiCallOutcome::fail('too_many_inflight', 'سرویس همین حالا پرکار است؛ چند ثانیه بعد دوباره تلاش کنید.', 429, ['Retry-After' => '5']);
                }

                if (($refusal = $this->budgetRefusal($auth, $hold['total'])) !== null) {
                    return $refusal;
                }

                $reservation = AiReservation::create([
                    'customer_id' => $customerId,
                    'currency_code' => 'IRT',
                    'amount_irt' => $hold['total'],
                    'status' => AiReservation::STATUS_PENDING,
                    'idempotency_key' => $key,
                    'purpose' => 'ai',
                    'reference' => $model->slug,
                    'expires_at' => null,                            // M5: تصمیم با decide_by، نه با ساعت
                    'pricing_version' => AiReservation::PRICING_M5,
                    'ai_project_id' => $auth->project?->id,
                    'customer_api_token_id' => $auth->token?->id,
                ]);

                $usage = AiUsage::create([
                    'customer_id' => $customerId,
                    'ai_reservation_id' => $reservation->id,
                    'ai_project_id' => $auth->project?->id,
                    'customer_api_token_id' => $auth->token?->id,
                    'ai_provider_id' => $model->ai_provider_id,
                    'ai_model_id' => $model->id,
                    'model_slug' => $model->slug,
                    'upstream_model' => $model->upstream_model,
                    'idempotency_key' => $key,
                    'request_sha256' => $prepared->sha256,
                    'stream' => false,
                    'status' => AiUsage::STATUS_RESERVED,
                    'max_input_tokens' => $prepared->maxInput,
                    'max_output_tokens' => $prepared->maxOutput,
                    'price_currency' => $q->currency,
                    'input_price_id' => $q->inputPriceId,
                    'cached_price_id' => $q->cachedPriceId,
                    'output_price_id' => $q->outputPriceId,
                    'input_rate_micro' => $q->rIn,
                    'cached_rate_micro' => $q->cachedPriceId === null ? null : $q->rCached,
                    'output_rate_micro' => $q->rOut,
                    'fx_rate_toman' => $q->rate(),
                    'fx_source' => $q->fx->source,
                    'fx_at' => $q->fx->at,
                    'fee_bp' => $q->feeBp,
                    'margin_bp' => $q->marginBp,
                    'vat_bp' => $vat['bp'],
                    'vat_basis' => $vat['basis'],
                    'p_input_irt_m' => $q->pIn,
                    'p_cached_irt_m' => $q->pCached,
                    'p_output_irt_m' => $q->pOut,
                    'hold_sell_irt' => $hold['sell'],
                    'hold_tax_irt' => $hold['tax'],
                    'hold_irt' => $hold['total'],
                    'decide_by' => now()->addSeconds($tHttp + (int) config('ai.decide_by_grace_s', 300)),
                ]);

                return ['usage' => $usage];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // دو درخواستِ هم‌زمان با یک کلید: برنده رزرو کرد، این یکی منتظر بماند
            return AiCallOutcome::fail('request_in_progress', 'تماسِ دیگری با همین کلید هم‌زمان در جریان است.', 409, ['Retry-After' => '5']);
        }
    }

    /**
     * جاروبِ درون‌خطی (MS-2): ردیف‌های کهنهٔ **همین** مشتری داخلِ همان قفل تصمیم
     * می‌گیرند، پس حتی اگر زمان‌بندِ سرور مرده باشد پولِ نگه‌داشتهٔ او گیر نمی‌ماند.
     */
    private function sweepOwn(int $customerId): void
    {
        $stale = AiUsage::where('customer_id', $customerId)
            ->where('decide_by', '<', now())
            ->whereIn('status', [AiUsage::STATUS_RESERVED, AiUsage::STATUS_SENDING, AiUsage::STATUS_STREAMING])
            ->get();

        foreach ($stale as $u) {
            $u->status === AiUsage::STATUS_RESERVED
                ? $this->settlement->release($u, 'decide_by_passed')
                : $this->settlement->markUnknown($u, 'decide_by_passed');
        }
    }

    /** بودجهٔ ماهانهٔ پروژه و سقفِ روزانهٔ کلید (D12) — شامِ رزروهای باز، پس هم‌زمانی رد نمی‌شود */
    private function budgetRefusal(AiAuthContext $auth, int $hold): ?AiCallOutcome
    {
        $project = $auth->project;
        $budget = (int) ($project?->monthly_budget_irt ?? 0);
        $window = $budget > 0 ? $project->budgetWindowFor(now()) : null;

        if ($window !== null) {
            $spent = (int) AiUsage::where('ai_project_id', $project->id)->whereIn('status', AiUsage::CHARGED)
                ->where('settled_at', '>=', $window['from'])->where('settled_at', '<', $window['until'])
                ->sum(DB::raw('charged_irt - refunded_irt'));
            $held = (int) AiUsage::where('ai_project_id', $project->id)->whereIn('status', AiUsage::OPEN)->sum('hold_irt');

            if ($spent + $held + $hold > $budget) {
                return AiCallOutcome::fail('budget_exceeded', 'بودجهٔ ماهانهٔ این پروژه پر شده است.', 402);
            }
        }

        $cap = (int) ($auth->token?->daily_spend_cap_irt ?? 0);   // عمداً بی پشتوانهٔ سقفِ نمایندگی
        if ($cap > 0) {
            $today = now()->setTimezone('Asia/Tehran')->toDateString();
            $spent = (int) AiUsage::where('customer_api_token_id', $auth->token->id)->whereIn('status', AiUsage::CHARGED)
                ->whereDate('day', $today)->sum(DB::raw('charged_irt - refunded_irt'));
            $held = (int) AiUsage::where('customer_api_token_id', $auth->token->id)->whereIn('status', AiUsage::OPEN)->sum('hold_irt');

            if ($spent + $held + $hold > $cap) {
                return AiCallOutcome::fail('daily_cap_exceeded', 'سقفِ خرجِ روزانهٔ این کلید پر شده است.', 402);
            }
        }

        return null;
    }

    /* ═══════════════════════ B–G: ارسال و تصمیم ═══════════════════════ */

    private function send(AiUsage $usage, AiModel $model, AiProviderDriver $driver, AiPreparedRequest $prepared, ?string $key): AiCallOutcome
    {
        $headers = ['X-Request-Id' => $usage->public_id];
        if ($prepared->dropped !== []) {
            $headers['X-ServerNet-Dropped-Params'] = implode(',', $prepared->dropped);
        }

        // B1: آشتی‌دهنده پیش‌دستی کرده و آزادش کرده ⇒ **نفرست**
        if (! $this->settlement->markSending($usage)) {
            return AiCallOutcome::fail('reservation_released', 'رزرو پیش از ارسال آزاد شد؛ دوباره تلاش کنید.', 503, $headers);
        }

        // B2: مشتری که قطع کند، PHP باید تا تسویه برسد
        ignore_user_abort(true);
        $tHttp = self::httpTimeout($prepared->maxOutput);
        // گاردِ استانداردِ پروژه (TimeLimitIsConsoleGuardedTest): در کنسول/تست، set_time_limit
        // کلِ فرایند را روی همان عدد می‌بندد و مجموعهٔ تست را روی ویندوز بی‌صدا می‌کُشد
        if (! app()->runningInConsole()) {
            @set_time_limit($tHttp + 60);
        }

        $started = hrtime(true);

        try {
            $result = $driver->chat($model, $prepared->body(), $tHttp);
        } catch (AiProviderCallException $e) {
            return $this->failure($usage, $model, $e, $headers);
        } catch (\Throwable $e) {
            // پس از علامتِ «ارسال» — شاید رسیده باشد: نگه‌داشتن، نه آزادسازی
            $this->settlement->markUnknown($usage, 'internal_after_send');
            ErrorTracker::noteOnce('ai', 'خطای داخلی پس از ارسالِ تماسِ AI: '.$e->getMessage(), 900);

            return AiCallOutcome::fail('upstream_bad_body', 'پاسخِ ارائه‌دهنده قابلِ خواندن نبود.', 502,
                $headers + ['x-should-retry' => 'false'], $usage);
        }

        $latency = (int) intdiv(hrtime(true) - $started, 1_000_000);
        $parsed = AiUsageParser::parse($result->body, $result->raw);

        if ($parsed === null) {
            // ۲xx بی مصرفِ قابلِ خواندن ⇒ نامعلوم، نه صفر
            $this->settlement->markUnknown($usage, 'upstream_bad_body', $result->status);

            return AiCallOutcome::fail('upstream_bad_body', 'پاسخِ ارائه‌دهنده مصرفِ توکن را گزارش نکرد.', 502,
                $headers + ['x-should-retry' => 'false'], $usage);
        }

        try {
            $settled = $this->settlement->settleFromUsage($usage, $parsed, $result->requestId, $latency);
            if (in_array($settled->status, AiUsage::CHARGED, true)) {
                $headers['X-ServerNet-Charge-Irt'] = (string) ((int) $settled->charged_irt - (int) $settled->refunded_irt);
            }
        } catch (\Throwable $e) {
            // G5: خدمت داده شد — ردیف settle_pending با پولِ نگه‌داشته برای آشتی‌دهنده می‌مانَد
            ErrorTracker::noteOnce('ai', 'تسویهٔ تماسِ AI '.$usage->public_id.' به آشتی‌دهنده سپرده شد: '.$e->getMessage(), 900);
            $settled = $usage->fresh();
        }

        if ($key !== null) {
            $this->remember($usage, $key, $result->body, $result->status);
        }

        return new AiCallOutcome(true, 'ok', '', $result->body, 200,
            $headers + ['Cache-Control' => 'no-store'], $settled);
    }

    /** طبقه‌بندیِ شکست — جدولِ §5 */
    private function failure(AiUsage $usage, AiModel $model, AiProviderCallException $e, array $headers): AiCallOutcome
    {
        if (! $e->sent) {
            $this->settlement->release($usage, $e->errorCode);

            return $e->errorCode === 'provider_unconfigured'
                ? AiCallOutcome::fail('provider_unconfigured', 'ارائه‌دهنده پیکربندی نشده است.', 503, $headers)
                : AiCallOutcome::fail('upstream_unreachable', 'تماس با ارائه‌دهنده برقرار نشد؛ دوباره تلاش کنید.', 502, $headers);
        }

        if ($e->errorCode === 'upstream_http') {
            $status = (int) $e->status;
            $this->settlement->release($usage, 'upstream_'.$status, $status);

            if (in_array($status, [401, 402, 403], true)) {
                // کلید/اعتبار/مجوزِ ما نزدِ ارائه‌دهنده خراب است: هر تماسِ بعدی هم همین می‌شد
                AiProvider::whereKey($model->ai_provider_id)->whereNull('paused_at')->update([
                    'paused_at' => now(), 'paused_reason' => "خودکار: HTTP {$status} از ارائه‌دهنده", 'updated_at' => now(),
                ]);
                ErrorTracker::noteOnce('ai', "ارائه‌دهندهٔ AI با HTTP {$status} پاسخ داد و خودکار متوقف شد — کلید/موجودیِ حساب را بررسی کنید.", 900);

                return AiCallOutcome::fail('provider_paused', 'ارائه‌دهنده موقتاً در دسترس نیست.', 503, $headers);
            }

            $detail = in_array($status, [400, 413, 422], true) && $e->detail !== null ? ': '.$e->detail : '';

            return AiCallOutcome::fail('upstream_error', 'ارائه‌دهنده درخواست را نپذیرفت (HTTP '.$status.')'.$detail, 502, $headers);
        }

        // رسید و پاسخِ قابلِ اتکا نیامد ⇒ نامعلوم: پول نگه داشته می‌شود تا بازیابی یا سقف
        $this->settlement->markUnknown($usage, $e->errorCode, $e->status);

        return $e->errorCode === 'upstream_timeout'
            ? AiCallOutcome::fail('upstream_timeout', 'پاسخِ ارائه‌دهنده در مهلت نیامد؛ مصرفِ واقعی بررسی و تسویه می‌شود.', 504,
                $headers + ['x-should-retry' => 'false'], $usage)
            : AiCallOutcome::fail('upstream_bad_body', 'پاسخِ ارائه‌دهنده قابلِ خواندن نبود.', 502,
                $headers + ['x-should-retry' => 'false'], $usage);
    }

    /** بازپخش برای کلیدِ تکراری با همان بدنه — بی تماسِ بالادست و بی شارژ */
    private function replay(AiUsage $prev, ?string $key): AiCallOutcome
    {
        $call = AiCall::where('customer_id', $prev->customer_id)->where('idempotency_key', $key)
            ->where('ok', true)->where('created_at', '>=', now()->subHours((int) config('ai.replay_ttl_h', 24)))->first();

        if ($call === null || ! is_array($call->response_body)) {
            return AiCallOutcome::fail('idempotency_key_expired', 'پاسخِ ذخیره‌شدهٔ این کلید دیگر در دسترس نیست.', 409,
                ['X-Request-Id' => $prev->public_id]);
        }

        return new AiCallOutcome(true, 'ok', '', $call->response_body, 200, [
            'X-Request-Id' => $prev->public_id, 'Idempotent-Replayed' => 'true', 'Cache-Control' => 'no-store',
        ], $prev);
    }

    /** پاسخِ موفقِ کلیددار برای بازپخش — **بی متنِ درخواست** (D14) */
    private function remember(AiUsage $usage, string $key, array $body, int $status): void
    {
        try {
            AiCall::create([
                'customer_id' => $usage->customer_id,
                'ai_reservation_id' => $usage->ai_reservation_id,
                'model_slug' => $usage->model_slug,
                'idempotency_key' => $key,
                'ok' => true,
                'request_body' => null,
                'response_body' => $body,
                'upstream_status' => $status,
            ]);
        } catch (\Throwable) {
            // نبودِ سجلِ بازپخش فقط یعنی تکرارِ بعدی «منقضی» می‌گیرد؛ پول درست است
        }
    }

    /** مهلتِ پاسخ: clamp(30 + ⌈O/20⌉, 60, 240) ثانیه */
    public static function httpTimeout(int $output): int
    {
        $t = (int) config('ai.http_timeout_base_s', 30)
            + (int) ceil($output / 20) * (int) config('ai.http_timeout_per_20_out', 1);

        return max((int) config('ai.http_timeout_min_s', 60), min((int) config('ai.http_timeout_max_s', 240), $t));
    }
}
