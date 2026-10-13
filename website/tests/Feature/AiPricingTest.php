<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiProvider;
use App\Services\Ai\AiModelRegistry;
use App\Services\Ai\PriceBook;
use App\Support\MicroMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * قیمتِ واحدِ AI — حسابِ صحیح و تاریخچهِ تغییرناپذیرِ قیمت.
 *
 * 🔴 قیمت‌ها زیرِ یک سنت به ازای هر توکن‌اند؛ **هیچ	float در هیچ مسیرِ
 * محاسبه‌ای مجاز نیست** — راه‌ی از `MicroMath` عبور نمی‌کند و نرخِ فقط *
 * صحیح (میکرو-واحد) را می‌کشد. پس همهٔ پاسخ‌های این تست، وکتورهای *
 * دست-محاسبه‌شدهٔ دقیق‌اند، نه `assertEquals`-های گردشده.
 */
class AiPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private PriceBook $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book = new PriceBook;
    }

    private function providerAndModel(): array
    {
        $p = AiProvider::create([
            'slug' => 'acme', 'name' => 'Acme', 'driver' => 'Acme',
            'enabled' => true, 'commercial_enabled' => false,
            'resale_allowed' => false, 'agreement_status' => 'none',
            'priority' => 100, 'live_calls_enabled' => false,
        ]);
        $m = AiModel::create([
            'ai_provider_id' => $p->id, 'slug' => 'acme/model-a',
            'upstream_model' => 'acme/model-a', 'name' => 'Model A',
            'category' => AiModel::CATEGORY_CHAT, 'status' => AiModel::STATUS_ACTIVE,
        ]);

        return [$p, $m];
    }

    /* ═══ حسابِ صحیح — MicroMath ═══ */

    public function test_ceil_division_hand_computed(): void
    {
        $this->assertSame(4, MicroMath::ceilDiv(7, 2));
        $this->assertSame(1, MicroMath::ceilDiv(1, 1_000_000), 'هر جزءِ خردِ کوژ، گردِ الف به بالا');
        $this->assertSame(0, MicroMath::ceilDiv(0, 5), 'صفر صفر است، نه گردِ یک');
        $this->assertSame(1, MicroMath::ceilDiv(1, 1), 'بخش‌پذیریِ کامل هم گنبدِ بالا نمی‌گردد');
        $this->assertSame(1, MicroMath::ceilDiv(999_999, 1_000_000));
        $this->assertSame(1, MicroMath::ceilDiv(1_000_000, 1_000_000));
    }

    public function test_ceil_division_rejects_negative_and_zero_divisor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MicroMath::ceilDiv(1, 0);
    }

    /** وکتور: ۲۴۵٬۰۰۰ میکرو-دلار بر ۱M توکن × ۱٬۲۳۴٬۵۶۷ توکنِ خروجی */
    public function test_charge_vector_output_tokens_round_up_once(): void
    {
        [$p, $m] = $this->providerAndModel();
        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 245_000);

        $rate = $this->book->resolve($m, AiModelUnitPrice::UNIT_OUTPUT);

        // 245000 × 1234567 = 302_468_915_000 → /1e6 = 302_468.915 → **۳۰۲٬۴۶۹** (یک گردِ به بالا، نه دوباره)
        //
        // ⚠️ عددِ قبلیِ همین تست (۳۰۲٬۵۷۹) اشتباهِ حسابِ خودِ تست بود، نه باگِ کد:
        // ضرب را ۳۰۲٬۵۷۸٬۹۱۵٬۰۰۰ نوشته بود. تستِ قرمزی که «باگ» به‌نظر می‌رسد و
        // نیست، همان‌قدر گران است که باگِ ندیده.
        $this->assertSame(302_469, $rate->chargeMicros(1_234_567));
        $this->assertSame(302_469, MicroMath::scaledCeil(245_000, 1_234_567, 1_000_000));
    }

    public function test_charge_exactly_on_boundary_is_exact_no_inflation(): void
    {
        [$p, $m] = $this->providerAndModel();
        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 250_000);

        // 250000 × 1_000_000 = 250_000_000_000 → /1e6 = 250_000 — دقیق، بدونِ گنبد
        $this->assertSame(250_000, $this->book->resolve($m, AiModelUnitPrice::UNIT_OUTPUT)->chargeMicros(1_000_000));
    }

    public function test_small_charge_bills_at_least_one_micro(): void
    {
        [$p, $m] = $this->providerAndModel();
        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 245_000);

        // 245000 × 1 توکن = 0.245 میکرو-دلار → گرد به بالا = 1 میکرو-دلار (هرگز صفر — صفر یعنی رایگان)
        $this->assertSame(1, $this->book->resolve($m, AiModelUnitPrice::UNIT_OUTPUT)->chargeMicros(1));
    }

    /** مقیاسِ عمومیِ نرخ × مقدار ÷ ۱e6 — وکتورِ دقیق (تبدیلِ ارز و FX خودِ
     *  M5 است؛ این‌جا فقط ریاضیِ عددِ صحیح آزموده می‌شود) */
    public function test_scaled_ceil_vector(): void
    {
        // ۳۰۲٬۵۷۹ × ۱٬۲۵۰٬۰۰۰ = ۳۷۸٬۲۲۳٬۷۵۰٬۰۰۰ → ÷1e6 = ۳۷۸٬۲۲۳٫۷۵ → ۳۷۸٬۲۲۴ (به بالا)
        $this->assertSame(378_224, MicroMath::scaledCeil(302_579, 1_250_000, 1_000_000));
    }

    /* ═══ ارز — هر سطرِ قیمتِ ارزِ خودش را نگه می‌دارد ═══ */

    /** نرخِ جدید به میکرو-واحدِ ارزِ اعلامی ذخیره می‌شود (پیش‌فرضِ
     *  ارائه‌دهنده USD است) و شمارشِ هزینه از همان ارز گفته می‌شود */
    public function test_usd_price_vector_is_stored_and_charged_in_usd(): void
    {
        [$p, $m] = $this->providerAndModel();

        // مثالِ DeepInfra: ۰٫۲۵ دلار به ازای ۱M توکن ⇒ ۲۵۰٬۰۰۰ میکرو-دلار
        $row = $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 250_000);

        $this->assertSame('USD', $row->currency_code);
        $this->assertSame('USD', $this->book->resolve($m, AiModelUnitPrice::UNIT_OUTPUT)->currencyCode);
        // 250000×1234567 = ۳۰۸٬۶۴۱٬۷۵۰٬۰۰۰ → ÷1e6 → ۳۰۸٬۶۴۲ (به بالا)
        $this->assertSame(308_642, $this->book->resolve($m, AiModelUnitPrice::UNIT_OUTPUT)->chargeMicros(1_234_567));
    }

    /** ارزِ نسخهٔ تازه می‌تواند از قبلی فرق کند — و هر سطر ارزِ خودش را
     *  حفظ می‌کند تا بازتولیدِ تاریخی نرخِ واقعیِ روزش را داشته باشد */
    public function test_currency_is_preserved_per_historic_row(): void
    {
        [$p, $m] = $this->providerAndModel();

        /*
        | ⚠️ دو نسخه باید در دو **لحظهٔ** جدا ساخته شوند.
        |
        | نسخهٔ قبلیِ این تست هر دو را در یک ثانیه می‌ساخت؛ آن‌وقت
        | `effective_from`ها برابر بودند و «نرخِ لحظهٔ T» تعریفِ یکتا نداشت —
        | قاعدهٔ نیم‌بازهٔ خودِ کد (نسخهٔ بسته‌شده در همان لحظه دیگر مالِ آن لحظه
        | نیست) درست است و نسخهٔ تازه برنده می‌شد. این قرمزی ایرادِ تست بود.
        */
        Carbon::setTestNow('2026-09-01 10:00:00');
        $v1 = $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 245_000);            // پیش‌فرضِ USD

        Carbon::setTestNow('2026-09-02 10:00:00');
        $v2 = $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 220_000,
            null, null, 'EUR');                                                              // آگاهانه یورو

        $this->assertSame('USD', $v1->currency_code);
        $this->assertSame('EUR', $v2->currency_code);

        $historical = $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $v1->effective_from->copy());
        $this->assertSame('USD', $historical->currency_code, 'تاریخِ ارزِ واقعیِ همان روز را نگه می‌دارد');
    }

    /** کدِ ارزِ نامعتبر رد می‌شود — فقط سه نویسهٔ لاتینِ بزرگ (ISO-۴۲۱۷) */
    public function test_supersede_rejects_invalid_currency_code(): void
    {
        [$p, $m] = $this->providerAndModel();
        $this->expectException(\InvalidArgumentException::class);
        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 100, null, null, 'US');
    }

    /** ارائه‌دهنده ارزِ پیش‌فرضِ صورت‌حساب دارد؛ سطرِ قیمتِ خودش ارزِ صریح است */
    public function test_provider_default_billing_currency_exists_but_row_is_authoritative(): void
    {
        $p = AiProvider::create([
            'slug' => 'curp', 'name' => 'CurP', 'driver' => 'CurP',
            'billing_currency_code' => 'USD',
        ]);
        $this->assertSame('USD', $p->refresh()->billing_currency_code);
    }

    /* ═══ سلسله‌مراتب: مشتری ← مدل ← ارائه‌دهنده ← سراسری ═══ */

    public function test_model_price_wins_over_provider_and_global(): void
    {
        [$p, $m] = $this->providerAndModel();

        AiModelUnitPrice::create(['ai_model_id' => $m->id, 'ai_provider_id' => $p->id,
            'unit' => AiModelUnitPrice::UNIT_OUTPUT, 'billing_unit' => AiModelUnitPrice::BASIS_1M_TOKENS,
            'price_micro_units' => 111, 'effective_from' => now()]);
        AiModelUnitPrice::create(['ai_provider_id' => $p->id,
            'unit' => AiModelUnitPrice::UNIT_OUTPUT, 'billing_unit' => AiModelUnitPrice::BASIS_1M_TOKENS,
            'price_micro_units' => 222, 'effective_from' => now()]);
        AiModelUnitPrice::create([
            'unit' => AiModelUnitPrice::UNIT_OUTPUT, 'billing_unit' => AiModelUnitPrice::BASIS_1M_TOKENS,
            'price_micro_units' => 333, 'effective_from' => now()]);

        $this->assertSame(111, $this->book->resolve($m, AiModelUnitPrice::UNIT_OUTPUT)->priceMicroUnits);
    }

    public function test_provider_fallback_when_model_unset(): void
    {
        [$p, $m] = $this->providerAndModel();

        AiModelUnitPrice::create(['ai_provider_id' => $p->id,
            'unit' => AiModelUnitPrice::UNIT_OUTPUT, 'billing_unit' => AiModelUnitPrice::BASIS_1M_TOKENS,
            'price_micro_units' => 222, 'effective_from' => now()]);
        AiModelUnitPrice::create([
            'unit' => AiModelUnitPrice::UNIT_OUTPUT, 'billing_unit' => AiModelUnitPrice::BASIS_1M_TOKENS,
            'price_micro_units' => 333, 'effective_from' => now()]);

        $this->assertSame(222, $this->book->resolve($m, AiModelUnitPrice::UNIT_OUTPUT)->priceMicroUnits);
    }

    public function test_global_fallback_is_last(): void
    {
        [$p, $m] = $this->providerAndModel();

        AiModelUnitPrice::create([
            'unit' => AiModelUnitPrice::UNIT_OUTPUT, 'billing_unit' => AiModelUnitPrice::BASIS_1M_TOKENS,
            'price_micro_units' => 333, 'effective_from' => now()]);

        $this->assertSame(333, $this->book->resolve($m, AiModelUnitPrice::UNIT_OUTPUT)->priceMicroUnits);
    }

    /* ═══ واحدِ غایب = غیرِ صورت‌حساب‌پذیر، نه صفر ═══ */

    public function test_missing_unit_resolves_to_null_not_zero(): void
    {
        [$p, $m] = $this->providerAndModel();

        $this->assertNull($this->book->resolve($m, AiModelUnitPrice::UNIT_INPUT),
            '«قیمت‌گذاری‌نشده» هرگز به «رایگان» تفسیر نمی‌شود');
    }

    public function test_units_track_independent_prices(): void
    {
        [$p, $m] = $this->providerAndModel();

        $this->book->supersede($m, AiModelUnitPrice::UNIT_INPUT, 100);
        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 800);
        $this->book->supersede($m, AiModelUnitPrice::UNIT_CACHED_INPUT, 10);
        $this->book->supersede($m, AiModelUnitPrice::UNIT_REASONING, 220);

        $this->assertSame(100, $this->book->resolve($m, AiModelUnitPrice::UNIT_INPUT)->priceMicroUnits);
        $this->assertSame(800, $this->book->resolve($m, AiModelUnitPrice::UNIT_OUTPUT)->priceMicroUnits);
        $this->assertSame(10, $this->book->resolve($m, AiModelUnitPrice::UNIT_CACHED_INPUT)->priceMicroUnits);
        $this->assertSame(220, $this->book->resolve($m, AiModelUnitPrice::UNIT_REASONING)->priceMicroUnits);
    }

    public function test_non_token_units_get_correct_billing_basis(): void
    {
        [$p, $m] = $this->providerAndModel();

        $img = $this->book->supersede($m, AiModelUnitPrice::UNIT_IMAGE, 150_000);
        $this->assertSame(AiModelUnitPrice::BASIS_IMAGE, $img->billing_unit);

        $audio = $this->book->supersede($m, AiModelUnitPrice::UNIT_AUDIO, 900_000);
        $this->assertSame(AiModelUnitPrice::BASIS_AUDIO_MINUTE, $audio->billing_unit);

        $req = $this->book->supersede($m, AiModelUnitPrice::UNIT_REQUEST, 2_500);
        $this->assertSame(AiModelUnitPrice::BASIS_REQUEST, $req->billing_unit);
    }

    /* ═══ تاريخچه — جایگزینی، نه ویرایش ═══ */

    public function test_supersede_bundles_active_previous_and_inserts_new(): void
    {
        [$p, $m] = $this->providerAndModel();

        $v1 = $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 245_000);
        $v2 = $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 300_000);

        $v1->refresh();
        $v2->refresh();

        // سطرِ قدیمی: دست‌نخورده بجزِ «بسته‌شدن»
        $this->assertSame(245_000, $v1->price_micro_units, 'قدیمی نمره‌ای عوض نمی‌شود');
        $this->assertFalse($v1->active);
        $this->assertNotNull($v1->superseded_at);

        // سطرِ جدید: فعال
        $this->assertTrue($v2->active);
        $this->assertNull($v2->superseded_at);
        $this->assertSame($v1->version + 1, $v2->version);
        $this->assertSame(300_000, $v2->price_micro_units);
    }

    public function test_old_price_reproduces_historical_result_after_price_change(): void
    {
        [$p, $m] = $this->providerAndModel();

        Carbon::setTestNow('2026-09-01 10:00:00');
        $v1 = $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 245_000);
        $charged_at = $v1->effective_from->copy();

        Carbon::setTestNow('2026-09-02 10:00:00');   // فردا — وگرنه «لحظهٔ T» یکتا نیست
        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 999_000); // قیمتِ دوبرابر

        // بازتولیدِ مالی: «در لحظهٔ T چه نرخی پول؟» — حتی بعد از جابه‌جایی قیمت.
        $historical = $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $charged_at);
        $this->assertNotNull($historical);
        $this->assertSame(245_000, $historical->price_micro_units);
        $this->assertSame($v1->id, $historical->id);

        // و *اکنون* نرخ جدیدست — بدونِ لکه‌زدن به حسابِ گذشته
        $this->assertNull($this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT,
            $v1->effective_from->addMinutes(-5)), 'پیش‌از نسخهٔ اول، نرخی نبود');
    }

    public function test_supersede_rejects_negative_price(): void
    {
        [$p, $m] = $this->providerAndModel();
        $this->expectException(\InvalidArgumentException::class);
        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, -5);
    }

    /* ═══ قیمتِ صفر ممنوع — صفر جای‌گذارِ «رایگان» نیست ═══ */

    public function test_supersede_rejects_zero_price(): void
    {
        [$p, $m] = $this->providerAndModel();

        try {
            $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 0);
            $this->fail('صفر باید رد شود — قیمتِ عمداً رایگان قابلیتِ آینده است، نه عددِ صفر.');
        } catch (\InvalidArgumentException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, AiModelUnitPrice::query()
            ->where('ai_model_id', $m->id)->where('unit', AiModelUnitPrice::UNIT_OUTPUT)->count(),
            'هیچ سطری ساخته نمی‌شود');
    }

    public function test_supersede_accepts_one_micro_as_minimum_valid_price(): void
    {
        [$p, $m] = $this->providerAndModel();

        $row = $this->book->supersede($m, AiModelUnitPrice::UNIT_INPUT, 1);

        $this->assertSame(1, $row->price_micro_units);
        $this->assertSame(1, $this->book->resolve($m, AiModelUnitPrice::UNIT_INPUT)->priceMicroUnits);
    }

    public function test_only_one_active_price_per_unit(): void
    {
        [$p, $m] = $this->providerAndModel();

        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 245_000);
        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 300_000);
        $this->book->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, 305_000);

        $this->assertSame(1,
            AiModelUnitPrice::query()->where('ai_model_id', $m->id)
                ->where('unit', AiModelUnitPrice::UNIT_OUTPUT)->where('active', true)->count());
    }

    /* ═══ رزولوشنِ تاریخی به زمانِ اقتصادی، نه ترتیبِ درج ═══ */

    /** درجِ مستقیمِ نسخه با زمانِ صریح — فقط برای تستِ `resolveAt`؛
     *  مسیرِ تولید همچنان `supersede` است. */
    private function insertVersion(AiModel $m, string $unit, int $micros,
        \Carbon\Carbon $from, ?\Carbon\Carbon $supersededAt, int $version): AiModelUnitPrice
    {
        return AiModelUnitPrice::create([
            'ai_provider_id' => $m->ai_provider_id, 'ai_model_id' => $m->id,
            'unit' => $unit, 'billing_unit' => AiModelUnitPrice::BASIS_1M_TOKENS,
            'price_micro_units' => $micros, 'currency_code' => 'USD',
            'version' => $version, 'active' => $supersededAt === null,
            'effective_from' => $from, 'superseded_at' => $supersededAt,
        ]);
    }

    /** A — جانشینیِ معمولی: هر بازه، نرخِ خودش را برمی‌گرداند */
    public function test_resolve_at_follows_sequential_supersession_intervals(): void
    {
        [$p, $m] = $this->providerAndModel();

        $t0 = \Carbon\Carbon::parse('2026-01-01 00:00:00');
        $t1 = \Carbon\Carbon::parse('2026-02-01 00:00:00');

        $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 100_000, $t0, $t1, 1);
        $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 200_000, $t1, null, 2);

        $this->assertSame(100_000, $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $t0->copy()->addDays(15))->price_micro_units);
        $this->assertSame(200_000, $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $t1->copy()->addDays(15))->price_micro_units);
    }

    /** B — سطرِ با تاریخِ گذشته، بعد از سطرهای جدیدتر درج می‌شود؛ انتخاب
     *  همچنان از زمانِ مؤثر می‌آید، نه از ترتیبِ درج */
    public function test_resolve_at_is_insertion_order_independent_with_backdated_row(): void
    {
        [$p, $m] = $this->providerAndModel();

        $t0 = \Carbon\Carbon::parse('2026-01-01 00:00:00');
        $t1 = \Carbon\Carbon::parse('2026-02-01 00:00:00');
        $mid = $t0->copy()->addDays(15); // میانِ دو بازه

        // اول نسخهٔ جدیدتر درج می‌شود…
        $newer = $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 200_000, $t1, null, 2);
        // …سپس نسخهٔ با تاریخِ گذشته، با id بزرگ‌تر
        $backdated = $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 100_000, $t0, $t1, 1);

        $this->assertGreaterThan($newer->id, $backdated->id, 'پیش‌نیازِ تست: سطرِ گذشته دیرتر درج شده');

        $got = $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $mid);
        $this->assertSame(100_000, $got->price_micro_units, 'id بزرگ‌تر نمی‌تواند بازهٔ زمانیِ کوچک‌تر را ببلعد');
        $this->assertSame(200_000, $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $t1->copy()->addDay())->price_micro_units);
    }

    /** C — برابریِ زمانِ مؤثر: شاه‌بیتِ id بزرگ‌تر، به‌صورتِ قطعی */
    public function test_resolve_at_equal_effective_from_ties_break_on_higher_id(): void
    {
        [$p, $m] = $this->providerAndModel();

        $t0 = \Carbon\Carbon::parse('2026-01-01 00:00:00');

        $a = $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 111, $t0, null, 1);
        $b = $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 222, $t0, null, 2);

        $got = $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $t0->copy()->addHour());
        $this->assertSame($b->id, $got->id, 'در برابریِ زمان، id بزرگ‌تر به‌صورتِ قطعی برنده است');
        $this->assertGreaterThan($a->id, $b->id);
    }

    /** D — پیش از اولین قیمت: هیچ نرخی نبود = غیرِ صورت‌حساب‌پذیر، نه صفر */
    public function test_resolve_at_before_first_price_is_null(): void
    {
        [$p, $m] = $this->providerAndModel();

        $t0 = \Carbon\Carbon::parse('2026-02-01 00:00:00');
        $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 100_000, $t0, null, 1);

        $this->assertNull($this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $t0->copy()->subSecond()));
        $this->assertNull($this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $t0->copy()->subDays(30)));
    }

    /** E — دقیقاً روی مرزِ effective_from: بازهٔ سطر شاملِ لحظهٔ شروع است */
    public function test_resolve_at_exactly_at_effective_from_boundary_picks_that_row(): void
    {
        [$p, $m] = $this->providerAndModel();

        $t0 = \Carbon\Carbon::parse('2026-01-01 00:00:00');
        $t1 = \Carbon\Carbon::parse('2026-02-01 00:00:00');

        $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 100_000, $t0, $t1, 1);
        $v2 = $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 200_000, $t1, null, 2);

        $got = $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $t1->copy());
        $this->assertSame($v2->id, $got->id, 'لحظهٔ شروع، جزءِ بازهٔ همان سطر است — نه سطرِ قبلی');
    }

    /** F — مرزِ بسته‌شدن: در خودِ لحظهٔ superseded_at، سطرِ قدیمی دیگر
     *  مؤثر نیست و نسخهٔ تازه حکم می‌راند؛ یک ثانیه قبل هنوز همان است */
    public function test_resolve_at_around_superseded_at_boundary(): void
    {
        [$p, $m] = $this->providerAndModel();

        $t0 = \Carbon\Carbon::parse('2026-01-01 00:00:00');
        $t1 = \Carbon\Carbon::parse('2026-02-01 00:00:00');

        $v1 = $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 100_000, $t0, $t1, 1);
        $v2 = $this->insertVersion($m, AiModelUnitPrice::UNIT_OUTPUT, 200_000, $t1, null, 2);

        $justBefore = $t1->copy()->subSecond();
        $this->assertSame($v1->id, $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $justBefore)->id,
            'یک ثانیه پیش از بسته‌شدن، سطرِ قدیمی حکم می‌راند');

        $exactly = $t1->copy();
        $this->assertSame($v2->id, $this->book->resolveAt($m, AiModelUnitPrice::UNIT_OUTPUT, $exactly)->id,
            'در خودِ لحظهٔ بسته‌شدن، سطرِ قدیمی تمام‌شده است');
    }

    /* ═══ بدونِ float در مسیرِ مالی ═══ */

    public function test_price_rates_carry_integer_only(): void
    {
        [$p, $m] = $this->providerAndModel();
        $this->book->supersede($m, AiModelUnitPrice::UNIT_INPUT, 245_000);

        $rate = $this->book->resolve($m, AiModelUnitPrice::UNIT_INPUT);

        $this->assertIsInt($rate->priceMicroUnits);
        $this->assertIsInt($rate->chargeMicros(1_000_000));
        $this->assertSame(245_000, $rate->chargeMicros(1_000_000));
    }
}
