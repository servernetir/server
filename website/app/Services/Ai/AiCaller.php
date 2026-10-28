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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
    public function handle(AiAuthContext $auth, array $client, ?string $idempotencyKey = null, string $operation = 'chat', bool $deferNative = false): AiCallOutcome
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

        if ($model === null || ($operation === 'native' ? ! AiNative::isNative($model)
            : ! in_array($operation, ['chat', 'embedding', 'rerank'], true) || $model->category !== $operation)) {
            return AiCallOutcome::fail('model_not_found', 'چنین مدلی شناخته نشد.', 404);
        }
        if ($model->status !== AiModel::STATUS_ACTIVE) {
            return AiCallOutcome::fail('model_inactive', 'این مدل فعال نیست.', 503);
        }
        if ($model->suspended_at !== null) {
            return AiCallOutcome::fail('model_suspended', 'این مدل موقتاً معلق است.', 503);
        }
        if (! DeepInfraCatalog::isFresh($model)) {
            return AiCallOutcome::fail('catalogue_unavailable', 'Model catalogue or pricing needs a refresh.', 503);
        }

        if (($gate = $this->providerGate($model->provider)) !== null) {
            return $gate;
        }

        if ($auth->token === null || ! $auth->token->can('ai:'.$operation)) {
            return AiCallOutcome::fail('insufficient_scope', 'کلیدِ این تماس سطحِ «ai:'.$operation.'» ندارد.', 403);
        }

        $driver = self::driverFor($model->provider);
        if ($driver === null) {
            return AiCallOutcome::fail('driver_unsupported', 'درایورِ ارائه‌دهندهٔ این مدل پیاده نشده است.', 503);
        }

        // ── A2–A3: بدنه ──
        try {
            $prepared = $operation === 'native' ? app(AiNative::class)->prepare($client,$model)
                : ($operation === 'chat' ? $this->payload->prepare($client, $model)
                    : $this->payload->prepareInputOnly($client, $model, $operation));
        } catch (AiRequestException $e) {
            return AiCallOutcome::fail($e->errorCode, $e->getMessage(), $e->status);
        }

        // ── A4: قیمت و نرخِ ارز — فقط از کش؛ ناقص ⇒ فروش بسته، هرگز حدس ──
        try {
            $quote = app(\App\Services\Birthday\BirthdayProgram::class)->enabled()
                ? $this->pricing->quoteFor($model, $customer) : $this->pricing->quote($model);
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

        if ($deferNative && $operation === 'native' && in_array($model->category,['video','music'],true)) {
            // Return the receipt before inference; the public proxy need not wait for rendering.
            // Process loss before sending leaves a reserved row for the existing reconciler.
            app()->terminating(function () use ($usage,$model,$driver,$prepared,$idempotencyKey) {
                $this->send($usage,$model,$driver,$prepared,$idempotencyKey ?? '__native-'.$usage->public_id);
            });
            return self::pendingNative($usage);
        }

        return $this->send($usage, $model, $driver, $prepared, $idempotencyKey ?? ($operation==='native' ? '__native-'.$usage->public_id : null));
    }

    private static function pendingNative(AiUsage $usage): AiCallOutcome
    {
        return new AiCallOutcome(true,'accepted',response:['id'=>$usage->public_id,'status'=>'processing',
            'result_url'=>url('/v1/inference/'.$usage->public_id)],status:202,
            headers:['X-Request-Id'=>$usage->public_id,'Cache-Control'=>'no-store','Retry-After'=>'5'],usage:$usage);
    }

    public function nativeResult(AiAuthContext $auth,string $id): AiCallOutcome
    {
        $usage=AiUsage::where('public_id',$id)->where('customer_id',$auth->customer->id)
            ->where('ai_project_id',$auth->token->ai_project_id)->where('operation','native')->first();
        if (! $usage) return AiCallOutcome::fail('request_not_found','Request not found.',404);
        if (in_array($usage->status,[AiUsage::STATUS_RESERVED,AiUsage::STATUS_SENDING,AiUsage::STATUS_STREAMING],true)) return self::pendingNative($usage);
        $call=AiCall::where('ai_reservation_id',$usage->ai_reservation_id)->where('ok',true)
            ->where('created_at','>=',AiNativeResponseRecovery::expiresBefore())->first();
        if (is_array($call?->response_encrypted)) return new AiCallOutcome(true,'ok',response:$call->response_encrypted,
            headers:['X-Request-Id'=>$id,'X-ServerNet-Charge-Irt'=>(string)($usage->charged_irt-$usage->refunded_irt),'Cache-Control'=>'no-store'],usage:$usage);
        return AiCallOutcome::fail($usage->error_code ?: 'result_unavailable','The request result is unavailable; inspect its billing status.',409,
            ['X-Request-Id'=>$id,'x-should-retry'=>'false'], $usage);
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
                ->sum(DB::raw('CASE WHEN provider_cost_micro > cost_micro THEN provider_cost_micro ELSE cost_micro END'));
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
        if ($prepared->native !== null) return [$prepared,$this->pricing->holdNative($q,$prepared->native['max_cost_micro'],$vatBp)];
        $hold = $this->pricing->hold($q, $prepared->maxInput, $prepared->maxOutput, $vatBp);
        $available = $this->wallet->availableOf($customerId, 'IRT');

        if ($hold['total'] <= $available || ! $prepared->clientOmittedMaxTokens) {
            return [$prepared, $hold];
        }

        // Find the largest affordable output using the actual rounded hold.
        $low=(int)config('ai.min_balance_aware_output',256);
        $high=min($prepared->maxOutput,$prepared->outputCap);$best=null;
        while($low<=$high){
            $output=$low+intdiv($high-$low,2);
            $candidate=$this->pricing->hold($q,$prepared->maxInput,$output,$vatBp);
            if($candidate['total']<=$available){$best=[$output,$candidate];$low=$output+1;}
            else $high=$output-1;
        }
        if($best!==null)return [$prepared->withOutput($best[0]),$best[1]];

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
        $tHttp = $prepared->operation === 'native' ? 300 : self::httpTimeout($prepared->maxOutput);

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
                            $prepared->operation === 'native' && $prev->status === AiUsage::STATUS_UNKNOWN_CHARGED
                                && $prev->usage_source !== 'cap' => ['replay' => $prev],
                            $prepared->operation === 'native' && in_array($prev->status,[AiUsage::STATUS_RESERVED,AiUsage::STATUS_SENDING,AiUsage::STATUS_STREAMING],true) => self::pendingNative($prev),
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
                    'stream' => $prepared->stream,
                    'operation' => $prepared->operation,
                    'native_snapshot' => $prepared->native === null ? null : ['max_cost_micro'=>$prepared->native['max_cost_micro'],
                        'contract'=>array_intersect_key($prepared->native['contract'],array_flip(['pricing','tariff_hash','protocol','ceiling_cents','ceiling_basis','call_units','schema_date','task','response_schema']))],
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
        $tHttp = $prepared->operation === 'native' ? 300 : self::httpTimeout($prepared->maxOutput);
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

        if ($result->requestId !== null) {
            AiUsage::whereKey($usage->id)->whereIn('status', [AiUsage::STATUS_SENDING, AiUsage::STATUS_STREAMING])
                ->update(['upstream_request_id' => mb_substr($result->requestId, 0, 120)]);
        }
        if ($prepared->stream && $result->stream !== null) {
            AiUsage::whereKey($usage->id)->where('status', AiUsage::STATUS_SENDING)
                ->update(['status' => AiUsage::STATUS_STREAMING, 'updated_at' => now()]);
            return new AiCallOutcome(true, 'ok', headers: $headers + [
                'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no', 'x-should-retry' => 'false',
            ], usage: $usage, stream: function () use ($result, $usage, $model, $key, $tHttp, $started) {
                $usageEvent = null;
                $parsed = null;
                $replay = [];
                $bytes = 0;
                $replayable = true;
                $requestId = $result->requestId;
                try {
                    foreach (AiSseReader::events($result->stream, $tHttp) as [$chunk, $raw]) {
                        if ($requestId === null && is_string($chunk['id'] ?? null)) {
                            $requestId = $chunk['id'];
                            AiUsage::whereKey($usage->id)->where('status', AiUsage::STATUS_STREAMING)
                                ->update(['upstream_request_id' => mb_substr($requestId, 0, 120)]);
                        }
                        if (($candidate = AiUsageParser::parse($chunk, $raw)) !== null) {
                            if ($parsed !== null) {
                                throw new \RuntimeException('Duplicate final usage event.');
                            }
                            $parsed = $candidate;
                            $usageEvent = self::publicBody($chunk, $usage);
                            continue; // Emit final usage only after the money decision.
                        }
                        $public = self::publicBody($chunk, $usage);
                        self::emit($public);
                        if ($key !== null && $replayable) {
                            $bytes += strlen($raw);
                            if ($bytes <= 4194304) {
                                $replay[] = $public;
                            } else {
                                $replay = [];
                                $replayable = false;
                            }
                        }
                    }
                    if ($parsed === null || $usageEvent === null) {
                        throw new \RuntimeException('Final usage event missing.');
                    }
                    $settled = $this->settlement->settleFromUsage($usage, $parsed, $requestId,
                        (int) intdiv(hrtime(true) - $started, 1000000));
                    $usageEvent['servernet_charge_irt'] = in_array($settled->status, AiUsage::CHARGED, true)
                        ? (int) $settled->charged_irt - (int) $settled->refunded_irt : null;
                    if ($key !== null && $replayable) {
                        $replay[] = $usageEvent;
                        $this->remember($usage, $key, ['_servernet_stream' => $replay], 200);
                    }
                    self::emit($usageEvent);
                    echo "data: [DONE]\n\n";
                    self::flushStream();
                } catch (\Throwable $e) {
                    $this->settlement->markUnknown($usage, 'stream_incomplete', 200);
                    ErrorTracker::noteOnce('ai', 'Incomplete AI stream '.$usage->public_id.' ('.get_class($e).')', 900);
                    self::emit(['error' => ['message' => 'Stream interrupted; usage is being reconciled. Do not automatically retry.',
                        'type' => 'server_error', 'code' => 'stream_incomplete'], 'request_id' => $usage->public_id]);
                    echo "data: [DONE]\n\n";
                    self::flushStream();
                }
            });
        }

        $latency = (int) intdiv(hrtime(true) - $started, 1_000_000);
        $parsed = AiUsageParser::parse($result->body, $result->raw, $prepared->operation,$prepared->native);

        if ($parsed === null) {
            // ۲xx بی مصرفِ قابلِ خواندن ⇒ نامعلوم، نه صفر
            $this->settlement->markUnknown($usage, 'upstream_bad_body', $result->status);
            if ($prepared->operation === 'native' && $key !== null) {
                AiNativeResponseRecovery::capture($usage, $key, $result->raw, $result->status);
            }

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
            $this->remember($usage, $key, self::publicBody($result->body, $usage), $result->status);
        }

        return new AiCallOutcome(true, 'ok', '', self::publicBody($result->body, $usage), 200,
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
                // GapGPT aggregates independent backends. Prove the account still works
                // before isolating a denied backend; an unverified account remains closed.
                if (in_array($status, [401, 403], true) && $this->gapAccountIsAuthenticated($model)) {
                    AiModel::whereKey($model->id)->whereNull('suspended_at')->update([
                        'suspended_at' => now(), 'suspended_reason' => "خودکار: HTTP {$status} از مسیر مدل؛ حساب GapGPT معتبر است", 'updated_at' => now(),
                    ]);
                    ErrorTracker::noteOnce('ai', "مسیر مدل AI {$model->slug} با HTTP {$status} بسته شد؛ سایر مسیرهای معتبر GapGPT برقرارند.", 900);

                    return AiCallOutcome::fail('model_suspended', 'این مدل موقتاً در دسترس نیست.', 503, $headers);
                }
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

    private function gapAccountIsAuthenticated(AiModel $model): bool
    {
        $provider = $model->provider;
        if ($provider?->slug !== 'gapgpt' || ! $provider->apiKey()) {
            return false;
        }
        try {
            $response = Http::withToken($provider->apiKey())->acceptJson()->connectTimeout(3)->timeout(5)
                ->get('https://api.gapgpt.app/v1/models');
            $models = $response->json('data');

            return $response->status() === 200 && is_array($models) && array_is_list($models)
                && count($models) > 0 && is_string($models[0]['id'] ?? null) && $models[0]['id'] !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /** بازپخش برای کلیدِ تکراری با همان بدنه — بی تماسِ بالادست و بی شارژ */
    private function replay(AiUsage $prev, ?string $key): AiCallOutcome
    {
        $call = AiCall::where('customer_id', $prev->customer_id)->where('idempotency_key', $key)
            ->where('ok', true)->where('created_at', '>=', now()->subHours((int) config('ai.replay_ttl_h', 24)))->first();

        $body = $call?->response_encrypted;
        if (! is_array($body)) {
            return AiCallOutcome::fail('idempotency_key_expired', 'پاسخِ ذخیره‌شدهٔ این کلید دیگر در دسترس نیست.', 409,
                ['X-Request-Id' => $prev->public_id]);
        }

        $headers = [
            'X-Request-Id' => $prev->public_id, 'Idempotent-Replayed' => 'true', 'Cache-Control' => 'no-store',
        ];
        if ($prev->stream && isset($body['_servernet_stream'])) {
            return new AiCallOutcome(true, 'ok', headers: $headers + ['X-Accel-Buffering' => 'no'], usage: $prev,
                stream: function () use ($body) {
                    foreach ($body['_servernet_stream'] as $chunk) {
                        self::emit($chunk);
                    }
                    echo "data: [DONE]\n\n";
                    self::flushStream();
                });
        }
        return new AiCallOutcome(true, 'ok', '', $body, 200, $headers, $prev);
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
                'response_body' => null,
                'response_encrypted' => $body,
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

    public static function publicBody(array $body, AiUsage $usage): array
    {
        $body['id'] = ($usage->operation === 'chat' ? 'chatcmpl-' : 'ai-').$usage->public_id;
        $body['model'] = $usage->model_slug;
        unset($body['usage']['estimated_cost'], $body['usage']['cost'], $body['usage']['cost_details'],
            $body['estimated_cost'], $body['inference_status']['cost'], $body['request_id']);
        return $body;
    }

    private static function emit(array $body): void
    {
        echo 'data: '.json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
        self::flushStream();
    }

    private static function flushStream(): void
    {
        // Test output capture must remain intact. Production streams continue to settle on disconnect.
        if (! app()->runningInConsole()) {
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
        }
    }
}
