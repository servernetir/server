<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Services\AiProviders\AiProviderCallException;
use App\Services\AiProviders\AiProviderDriver;
use App\Services\AiProviders\OpenAiCompatibleDriver;

/**
 * صدازدنِ زنده — تنها ارکستراتورِ «یک تماسِ AIِ پول‌خور».
 *
 * ═══ ترتیبِ ثابتِ گیت‌ها ═══
 *
 *   admission (بیرون از این کلاس، در AiAdmission.authorize — این کلاس
 *   فقط حکمِ سبز را می‌پذیرد و حکمِ رد را بی‌واسطه برمی‌گرداند)
 *   → مدل از رجیستری (فعال + ارائه‌دهندهٔ liveCapable)
 *   → قیمت از PriceBook (هر دو واحدِ input و output، غایب = رد)
 *   → رزروِ بدترین‌حالت روی AiReservations (TTLِ کوتاه)
 *   → تماسِ درایور
 *   → settle (موفق) / release (شکست) — پول فقط از همین دو در می‌گذرد.
 *
 * 🔴 مرزهایِ معماری (هرگز داخلِ این کلاس نوشته نمی‌شوند):
 *    - خواندنِ مستقیمِ جدول‌های ai_providers/ai_models — ممنوع؛ فقط
 *      `AiModelRegistry` می‌پرسد و این کلاس حرفش را می‌پذیرد.
 *    - محاسبهٔ قیمت — ممنوع بیرون از PriceBook؛ این کلاس فقط
 *      `chargeMicros` نرخِ منجمد را صدا می‌زند.
 *    - admission — ممنوع؛ `handle` حکمِ `AiAuthContext` را ورودی می‌گیرد
 *      (مسیرِ `/v1` در M4-b خودش `AiAdmission::authorize` را صدا می‌زند).
 *    - جابه‌جاییِ پول — ممنوع بیرون از AiReservations؛ هیچ سطرِ دفتری
 *      مستقیم نوشته نمی‌شود.
 *
 * ═══ برآوردِ رزرو (بدترین‌حالت) ═══
 *
 * رزرو پیش از تماس باز می‌شود، پس نمی‌داند چند توکن مصرف می‌شود؛
 * «بدترینِ معقول» برمی‌داریم:
 *
 *     رزرو = قیمتِ(توکن‌هایِ ورودیِ تخمینی) + قیمتِ(max_tokensِ خواسته‌شده)
 *
 * - ورودی: مجموعِ نویسه‌هایِ پیام‌ها ÷ ۴ (تقریبِ استانداردِ ~۴ نویسه
 *   بر توکن)؛ دستِ‌کم یک توکن. تخمین است نه صورت‌حساب — صورت‌حسابِ
 *   واقعی کارِ usage بالادست است (پایین).
 * - خروجی: `max_tokens` خواسته‌شده، سقف‌خورده به `max_output_tokens`
 *   خودِ مدل؛ نبود = همان سقفِ مدل. این خرجِ «همهٔ خروجیِ ممکن» را
 *   از قبل قفل می‌کند.
 * - گرد: فقط `AiPriceRate::chargeMicros` (MicroMath::scaledCeil) —
 *   هیچ float و هیچ تقسیمِ دستی در این فایل نیست.
 *
 * ═══ تسویه: تمامِ رزرو، نه مصرفِ واقعی ═══
 *
 * 🔴 تصمیمِ M4-a: در مسیرِ سبز **تمامِ مبلغِ رزرو** تسویه می‌شود، حتی
 *    اگر `usage` واقعیِ ارائه‌دهنده کمتر باشد. بازگرداندِ اختلافِ
 *    «رزرو منهایِ مصرفِ واقعی» مسیرِ مستندِ M5 است (نیازمند یک گذارِ
 *    refundِ اتمیک روی رزروِ settled)؛ تا آن گام، مشتریِ مظلومِ
 *    محافظه‌کارانه‌است نه زیرِشکافته — over-settle هرگز موجودی را
 *    منفی نمی‌کند ولی under-reserve می‌کرد.
 *
 * ═══ هم‌ارزی (idempotency) ═══
 *
 * کلیدِ هم‌ارزی مستقیم به رزرو وصل است: تکرارِ کلید همانِ ردیفِ رزروِ
 * قبلی را برمی‌گرداند و این کلاس **تماسِ دومی به بالادست نمی‌زند** —
 * پاسخ با کدِ `duplicate_request` رد می‌شود تا مسیرِ تماس دو بار
 * خرج نکند. بازپخشِ پاسخِ ضبط‌شدهٔ همان کلید کارِ جدولِ `ai_calls`
 * و M4-b است (اینجا فقط «دوبار‌خرج‌نکردن» تضمین می‌شود).
 */
class AiCaller
{
    /** مهلتِ رزرو — تماسِ زنده بیش از این نباید طول بکشد؛ پس از آن settle دیرهنگام رد می‌شود */
    public const RESERVATION_TTL_SECONDS = 90;

    public function __construct(
        private readonly AiModelRegistry $registry,
        private readonly PriceBook $priceBook,
        private readonly AiReservations $reservations,
    ) {}

    /**
     * @param  array  $payload  بدنهٔ سازگارِ OpenAI (`messages` الزامی) — بی‌واسطه به درایور می‌رود، فقط `model` بازنویسی می‌شود.
     */
    public function handle(
        AiAuthContext $auth,
        string $modelSlug,
        array $payload,
        ?string $idempotencyKey = null,
    ): AiCallOutcome {
        // ── گیتِ admission: این کلاس قاضی نیست؛ حکمِ رد همان‌جا برمی‌گردد ──
        if (! $auth->ok) {
            return AiCallOutcome::fail($auth->code, $auth->message !== '' ? $auth->message : 'دسترسی پذیرفته نشد.');
        }

        if (empty($payload['messages']) || ! is_array($payload['messages'])) {
            return AiCallOutcome::fail('invalid_payload', 'بدنهٔ درخواست باید `messages` غیرخالی داشته باشد.');
        }

        /*
        | هر خواندنِ «چه چیزی هست» از رجیستری می‌آید — این کلاس هیچ
        | کوئریِ مستقیمی روی ai_models/ai_providers نمی‌زند.
        */
        $model = $this->registry->model($modelSlug);

        if ($model === null) {
            return AiCallOutcome::fail('model_not_found', 'چنین مدلِ AI شناخته نشد.');
        }

        if ($model->status !== AiModel::STATUS_ACTIVE) {
            return AiCallOutcome::fail('model_inactive', 'این مدل فنی فعال نیست.');
        }

        // 🔴 گیتِ زنده: ارائه‌دهنده باید enabled و live_calls_enabled باشد
        if ($model->provider === null || ! $model->provider->isLiveCapable()) {
            return AiCallOutcome::fail('provider_not_live', 'ارائه‌دهندهٔ این مدل تماسِ زنده را روشن نکرده است.');
        }

        // گیتِ سطح برای دستهٔ chat — همان منطقِ توکن، بدونِ بازتعریف
        if ($model->category === AiModel::CATEGORY_CHAT
            && ($auth->token === null || ! $auth->token->can('ai:chat'))) {
            return AiCallOutcome::fail('insufficient_scope', 'کلیدِ این تماس سطحِ «ai:chat» ندارد.');
        }

        $driver = $this->makeDriver($model);

        if ($driver === null) {
            return AiCallOutcome::fail('driver_unsupported',
                'درایورِ ارائه‌دهندهٔ این مدل در این سرویس پیاده نشده است.');
        }

        /*
        | 🔴 قیمت پیش از هر رزرو — هر دو واحدِ مصرفیِ چت باید قیمت‌گذاری
        | شده باشند. نرخِ غایب هرگز «رایگان» تفسیر نمی‌شود (همان فلسفهٔ
        | PriceBook): بی‌قیمت یعنی غیرِ صورت‌حساب‌پذیر یعنی تماس نروید.
        | هیچ رزروی بدونِ نرخِ عددیِ میکرو باز نمی‌شود.
        */
        $inputRate = $this->priceBook->resolve($model, \App\Models\AiModelUnitPrice::UNIT_INPUT, $auth->customer?->id);
        $outputRate = $this->priceBook->resolve($model, \App\Models\AiModelUnitPrice::UNIT_OUTPUT, $auth->customer?->id);

        if ($inputRate === null || $outputRate === null) {
            return AiCallOutcome::fail('not_priced',
                'مدل روی هر دو واحدِ ورودی و خروجی قیمت‌گذاری نشده و صورت‌حساب‌پذیر نیست.');
        }

        // ── برآوردِ بدترین‌حالت (ریاضیاتِ پول فقط از PriceBook) ──
        $inputTokens = $this->estimateInputTokens($payload);
        $outputTokens = $this->capOutputTokens($payload, $model);

        /*
        | 🔴 واحدِ مبلغ: میکرو-واحدِ ارزِ سطرِ قیمت (قیمت‌گذاریِ سراسری
        | USD است). رزرو و تسویهٔ M4-a در همین مقیاسِ میکرو باز می‌شود؛
        | تبدیلِ ارز به تومانِ دفتر (`IRT`) کارِ لایهٔ تسویهٔ M5 است —
        | تا آن‌جا این مقدار «پولِ قفل‌شدهٔ درخواست» است و یکدست.
        */
        $reservedMicros = $inputRate->chargeMicros($inputTokens) + $outputRate->chargeMicros($outputTokens);

        /*
        | رزرو STRICT پس ازِ همهٔ گیت‌ها — admission، مدل، قیمت — تا
        | هیچ کلیدِ ردی، پولی قفل نکند. کلیدِ هم‌ارزی همان کلیدِ رزرو
        | است: تکرار = همانِ ردیف، بدونِ تماسِ دوم.
        */
        $reserved = $this->reservations->reserve(
            $auth->customer->id,
            $reservedMicros,
            $idempotencyKey,
            now()->addSeconds(self::RESERVATION_TTL_SECONDS),
            'ai',
            $modelSlug,
        );

        if (! $reserved->ok) {
            // کدِ رزرو پایدار است (insufficient_funds | …) — بی‌واسطه بالا
            return AiCallOutcome::fail($reserved->code, $reserved->message);
        }

        if ($reserved->already) {
            /*
            | کلیدِ تکراری: ردیفِ قبلی برگشته. اوّل «بازپخش» — اگر تماسِ
            | نخستِ همین کلید سبز بود و سجلش (ai_calls) ضبط شده،
            | **همان بدنه‌ی ضبط‌شده** برمی‌گرداند و هیچ تماسِ دومی به
            | بالادست نمی‌رود (چیدِ M4-c). سپس فقط آن‌وقت که سجلی نبود
            | (کلیدِ جدید است یا تماسِ اول هنوز در جریان) کدِ ردِ
            | «duplicate_request» چاپ می‌شود.
            */
            $replay = null;
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $replay = \App\Models\AiCall::query()
                    ->where('customer_id', $auth->customer->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('ok', true)
                    ->first();
            }

            if ($replay !== null) {
                return new AiCallOutcome(true, 'ok', '',
                    $replay->response_body, $reserved->reservation,
                    $reserved->reservation->amount_irt);
            }

            return new AiCallOutcome(false, 'duplicate_request',
                'این کلیدِ هم‌ارزی قبلاً رزرو باز کرده است؛ پاسخِ همان تماس مبناست.',
                null, $reserved->reservation, $reserved->reservation->amount_irt);
        }

        try {
            $result = $driver->chat($model, $payload);
        } catch (AiProviderCallException $e) {
            /*
            | شکستِ تماس = آزادسازیِ کاملِ نگه‌دارده — پول بی‌دفتر به
            | «در دسترس» برمی‌گردد و تکرارش بی‌اثر است. کدِ درایور
            | پایدار است و بی‌واسطه به پاسخِ API می‌رود.
            */
            $this->reservations->release($reserved->reservation);

            return new AiCallOutcome(false, $e->errorCode, $e->getMessage(),
                null, $reserved->reservation->fresh(), $reservedMicros);
        }

        /*
        | تسویهٔ تمامِ رزرو داخلِ گذارِ قفل‌دارِ AiReservations — تکرار-آمیز
        | و برندهٔ مسابقهٔ settle-vs-release. شکستِ تسویه (نبض‌ِ مرز) پاسخِ
        | را باطل نمی‌کند ولی پول را نیمه‌کاره می‌گذارد: `settle_failed`
        | نشانهٔ درزِ ممیزی است و رزروِ pending برای reconciler می‌ماند.
        */
        $settle = $this->reservations->settle($reserved->reservation);

        if (! $settle->ok) {
            return new AiCallOutcome(false, 'settle_failed',
                'تماس موفق شد ولی تسویهٔ رزرو رد شد: '.$settle->message,
                $result->body, $reserved->reservation->fresh(), $reservedMicros);
        }

        /*
        | ضبطِ سجل — پس ازِ تسویه، فقط برای تماسِ سبز و فقط با کلید. شکستِ
        | ضبط هیچ‌وقت پاسخِ تازه را منقاد نمی‌کند؛ فقط اثرش این است که
        | بازپخشِ همین کلید بعداً `duplicate_request` خواهد داد.
        */
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            try {
                \App\Models\AiCall::create([
                    'customer_id'        => $auth->customer->id,
                    'ai_reservation_id'  => $settle->reservation->id,
                    'model_slug'         => $modelSlug,
                    'idempotency_key'    => $idempotencyKey,
                    'ok'                 => true,
                    'request_body'       => $payload,
                    'response_body'      => $result->body,
                    'upstream_status'    => $result->status,
                ]);
            } catch (\Throwable) {
                // بدنه سازنده برمی‌گردد؛ فقط سجلِ بازپخش نخواهد بود
            }
        }

        return new AiCallOutcome(true, 'ok', '',
            $result->body, $settle->reservation, $reservedMicros);
    }

    /**
     * ساختِ درایور از شناسهٔ `ai_providers.driver`.
     *
     * رجیستری/مدل هرگز کلاس نمی‌سازد؛ همین‌جا جدولِ کوچکِ شناسه → کلاس
     * است و درایورِ ناشناخته «پشتیبانی‌نشده» است نه خطایِ خام.
     */
    private function makeDriver(AiModel $model): ?AiProviderDriver
    {
        return match ($model->provider->driver) {
            'OpenAI-Compatible' => new OpenAiCompatibleDriver($model->provider),
            default => null,
        };
    }

    /**
     * تخمینِ توکنِ ورودی — Σ نویسه‌هایِ پیام‌ها ÷ ۴، دستِ‌کم ۱.
     * فقط برای «قفلِ بدترین‌حالت» است؛ صورت‌حساب از usage بالادست می‌آید.
     */
    private function estimateInputTokens(array $payload): int
    {
        $chars = 0;

        foreach ($payload['messages'] as $message) {
            $chars += strlen((string) ($message['content'] ?? ''));
        }

        return max(1, (int) ceil($chars / 4));
    }

    /**
     * سقفِ توکنِ خروجی — خواستهٔ مشتری، قفل‌شده به سقفِ خودِ مدل.
     * نبود = سقفِ مدل؛ نبودِ آن هم = سقفِ محافظه‌کارانهٔ ۴۰۹۶.
     */
    private function capOutputTokens(array $payload, AiModel $model): int
    {
        $modelCap = max(1, (int) ($model->max_output_tokens ?: 4096));
        $wanted = (int) ($payload['max_tokens'] ?? $modelCap);

        return max(1, min($wanted, $modelCap));
    }
}
