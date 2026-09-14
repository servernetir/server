<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Support\MicroMath;
use Illuminate\Support\Facades\DB;

/**
 * دفترِ قیمتِ AI — رزولوشنِ سلسله‌مراتبی و ایجادِ نسخهٔ تازه.
 *
 * ═══ دو قرارداد ═══
 *
 * ۱) خواندن — `resolve()` سلسله‌مراتِ تأییدشده را می‌شمرد:
 *        مشتری (M2) ← مدل ← پیش‌فرضِ ارائه‌دهنده ← سراسری
 *    خروجیِ null یعنی «قیمت‌گذاری نشده» = غیرقابلِ صورت‌حساب، نه رایگان
 *    (همان فلسفهٔ `scopeSellable` در ابری: قیمتِ صفر عمدی است؛ نرخِ غایب
 *    هرگز صفر تفسیر نمی‌شود).
 *
 * ۲) نوشتن — `supersede()` سطرِ قدیمی را ویرایش نمی‌کند؛ سطرِ تازه می‌سازد
 *    و قدیمی را با `superseded_at` می‌بندد. بستنِ نسخهٔ فعال با UPDATEِ
 *    شرطیِ `active=true` تحتِ `lockForUpdate` است (الگویِ claim)، پس دو
 *    ادمینِ هم‌زمان هرگز دو نسخهٔ فعالِ موازی نمی‌سازند.
 *
 * هیچ محاسبهٔ پولی float نمی‌بیند — تقسیمِ گرد فقط از `MicroMath`.
 */
final class PriceBook
{
    /** نرخِ مؤثر در سطحِ انتخابی؛ null یعنی آن واحد قیمت‌گذاری نشده است. */
    public function resolve(AiModel $model, string $unit, ?int $customerId = null): ?AiPriceRate
    {
        $row = $this->forCustomer($model, $unit, $customerId)
            ?? $this->forModel($model, $unit)
            ?? $this->forProvider($model, $unit)
            ?? $this->global($unit);

        return $row === null ? null : AiPriceRate::fromRow($row);
    }

    /**
     * نسخهٔ مؤثر در یک نقطهٔ زمانی گذشته — بازتولیدِ مالیِ تاریخی و
     * ممیزیِ «قیمتِ لحظهٔ درخواست». سطرِ قیمت هیچ‌وقت عوض نمی‌شود؛
     * همین پرس‌وجو روی نسخه‌های بسته‌شده جواب می‌دهد.
     *
     * ترتیب، **زمانی-اقتصادی** است نه ترتیبِ درج: `effective_from`
     * مالکِ انتخاب است و `id` فقط شاه‌بیتِ قطعیتِ برابری است — تا
     * جواب، فارغ از ترتیبِ درجِ سطرها، بازتولید‌شدنی بماند.
     */
    public function resolveAt(AiModel $model, string $unit, \DateTimeInterface $at): ?AiModelUnitPrice
    {
        return AiModelUnitPrice::query()
            ->where('ai_model_id', $model->id)
            ->where('unit', $unit)
            ->where('effective_from', '<=', $at)
            ->where(fn ($q) => $q->whereNull('superseded_at')->orWhere('superseded_at', '>', $at))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * ایجادِ نسخهٔ تازه + بستنِ نسخهٔ فعال — در یک تراکنش.
     * تنها مسیرِ مجازِ «عوض‌کردنِ قیمت». `update` مستقیمِ سطرِ قیمت ممنوع.
     *
     * `$currencyCode` کد ISO-۴۲۱۷ِ ۳ نویسهٔ همین نرخ است (مثل USD یا EUR)
     * — ارزِ هر سطرِ قیمتِ صریح می‌ماند تا بازتولیدِ تاریخی دقیق باشد؛
     * نسخهٔ تازه می‌تواند ارزِ متفاوتی از قبلی داشته باشد (ارزِ مسیرِ
     * تهاتر — M5 معماریِ تسویه است، در M1 فقط ذخیره می‌شود). نال =
     * ارزِ پیش‌فرضِ همان ارائه‌دهنده.
     */
    public function supersede(
        AiModel $model,
        string $unit,
        int $rateMicros,
        ?int $createdBy = null,
        ?string $note = null,
        ?string $currencyCode = null,
    ): AiModelUnitPrice {
        if ($rateMicros < 1) {
            throw new \InvalidArgumentException('قیمتِ ردیف باید دستِ‌کم یک میکرو-واحد باشد؛ صفر جای‌گذارِ «رایگان» نیست و واحدِ بی‌قیمت یعنی «غیرِ صورت‌حساب‌پذیر».');
        }

        $currency = $currencyCode !== null
            ? strtoupper($currencyCode)
            : ($model->provider->billing_currency_code ?? 'USD');

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('کدِ ارز باید سه نویسهٔ لاتینِ بزرگ باشد (ISO-۴۲۱۷).');
        }

        $basis = $this->billingUnitFor($unit);

        return DB::transaction(function () use ($model, $unit, $rateMicros, $basis, $createdBy, $note, $currency) {
            /* نسخهٔ فعالِ همین مدل/واحد را (با claimِ قفل‌دار) بسته */
            AiModelUnitPrice::query()
                ->where('ai_model_id', $model->id)
                ->where('unit', $unit)
                ->where('active', true)
                ->lockForUpdate()
                ->update(['active' => false, 'superseded_at' => now()]);

            $lastVersion = (int) AiModelUnitPrice::query()
                ->where('ai_model_id', $model->id)
                ->where('unit', $unit)
                ->max('version');

            return AiModelUnitPrice::create([
                'ai_provider_id'  => $model->ai_provider_id,
                'ai_model_id'     => $model->id,
                'unit'            => $unit,
                'billing_unit'    => $basis,
                'price_micro_units' => $rateMicros,
                'currency_code'   => $currency,
                'version'         => $lastVersion + 1,
                'active'          => true,
                'effective_from'  => now(),
                'created_by'      => $createdBy,
                'note'            => $note,
            ]);
        });
    }

    /** یکای صورت‌حساب از نوع مصرف — اعلامِ صریح، بدونِ حدس. */
    private function billingUnitFor(string $unit): string
    {
        return match ($unit) {
            AiModelUnitPrice::UNIT_INPUT,
            AiModelUnitPrice::UNIT_OUTPUT,
            AiModelUnitPrice::UNIT_CACHED_INPUT,
            AiModelUnitPrice::UNIT_REASONING,
            AiModelUnitPrice::UNIT_EMBEDDING,
            AiModelUnitPrice::UNIT_RERANK => AiModelUnitPrice::BASIS_1M_TOKENS,
            AiModelUnitPrice::UNIT_IMAGE  => AiModelUnitPrice::BASIS_IMAGE,
            AiModelUnitPrice::UNIT_AUDIO  => AiModelUnitPrice::BASIS_AUDIO_MINUTE,
            AiModelUnitPrice::UNIT_REQUEST => AiModelUnitPrice::BASIS_REQUEST,
            default => throw new \InvalidArgumentException("واحدِ بی‌یکا: {$unit}"),
        };
    }

    /* ═══ هر لایه یک پرس‌وجوی صریح — نه شرطِ درهم ═══ */

    private function forModel(AiModel $model, string $unit): ?AiModelUnitPrice
    {
        return AiModelUnitPrice::query()
            ->where('ai_model_id', $model->id)
            ->where('unit', $unit)
            ->where('active', true)
            ->first();
    }

    private function forProvider(AiModel $model, string $unit): ?AiModelUnitPrice
    {
        return AiModelUnitPrice::query()
            ->whereNull('ai_model_id')
            ->whereNull('customer_id')
            ->where('ai_provider_id', $model->ai_provider_id)
            ->where('unit', $unit)
            ->where('active', true)
            ->first();
    }

    private function global(string $unit): ?AiModelUnitPrice
    {
        return AiModelUnitPrice::query()
            ->whereNull('ai_model_id')
            ->whereNull('ai_provider_id')
            ->whereNull('customer_id')
            ->where('unit', $unit)
            ->where('active', true)
            ->first();
    }

    private function forCustomer(AiModel $model, string $unit, ?int $customerId): ?AiModelUnitPrice
    {
        // سربارِ M2 — دُمِ سلسله‌مراتبِ مشتری از همین‌جا وصل می‌شود.
        return null;
    }
}

/**
 * نرخِ مؤثرِ منجمد — بدونِ وابستگی به مدل/سطر (`priceMicroUnits` و
 * `billingUnit` و `currencyCode` از سطر خوانده و سپس فقط از همین آبجکت
 * خوانده می‌شوند).
 */
final class AiPriceRate
{
    public function __construct(
        public readonly int $priceId,
        public readonly int $priceMicroUnits,
        public readonly string $unit,
        public readonly string $billingUnit,
        public readonly string $currencyCode,
        public readonly int $version,
    ) {}

    public static function fromRow(AiModelUnitPrice $r): self
    {
        return new self($r->id, $r->price_micro_units, $r->unit, $r->billing_unit, $r->currency_code, (int) $r->version);
    }

    /**
     * هزینهٔ دقیقِ میکرو-واحد (از ارزِ همین نرخ — ثابت و بی‌گردِ زود).
     * برای `1m_tokens`، `units` = تعدادِ توکن؛ تقسیم به 1e6 یک بار و با
     * گردِ **به بالا** انجام می‌شود (کاهشِ سایزِ واحدِ صورت‌حساب نه، نه گردِ
     * دو‌مرحله‌ای). برای بقیهٔ یکاها units = تعدادِ همان یکا.
     */
    public function chargeMicros(int $units): int
    {
        return MicroMath::scaledCeil($this->priceMicroUnits, $units, 1_000_000);
    }
}
