<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiProject;
use App\Models\AiProvider;
use App\Models\AiReservation;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\Setting;
use App\Services\Ai\AiAdmission;
use App\Services\Ai\AiCaller;
use App\Services\Ai\AiAuthContext;
use App\Services\Ai\PriceBook;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M4-a — صدازدنِ زندهٔ AiCaller با Http::fake.
 *
 * همان‌طور که AiReservationsTest شفاف نوشته: SQLite تک‌رشته‌ای
 * «هم‌زمانی» را اثبات نمی‌کند — این‌جا **معنایِ پول و ترتیبِ گیت‌ها**
 * سنجیده می‌شود: رزرو فقط پس ازِ همهٔ ردِها، تسویه در سبز، آزادسازی
 * در قرمز، هیچ تماسِ دومی برای کلیدِ تکراری.
 */
class AiCallerTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE_INPUT_MICROS = 500_000;   // ۰٫۵ واحد بر ۱M توکن
    private const PRICE_OUTPUT_MICROS = 750_000; // ۰٫۷۵ واحد بر ۱M توکن

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'email' => 'caller'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => null, 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    private function provider(array $over = []): AiProvider
    {
        return AiProvider::create(array_merge([
            'slug' => 'deepinfra', 'name' => 'DeepInfra', 'driver' => 'OpenAI-Compatible',
            'enabled' => true, 'live_calls_enabled' => true, 'priority' => 1,
            'agreement_status' => AiProvider::STATUS_SIGNED, 'billing_currency_code' => 'USD',
        ], $over));
    }

    private function model(AiProvider $p, array $over = []): AiModel
    {
        return AiModel::create(array_merge([
            'ai_provider_id' => $p->id, 'slug' => 'llama-3-8b',
            'upstream_model' => 'meta-llama/Llama-3-8B-Instruct',
            'name' => 'Llama 3 8B', 'category' => AiModel::CATEGORY_CHAT,
            'status' => AiModel::STATUS_ACTIVE, 'max_output_tokens' => 256,
            'provider_priority' => 1,
        ], $over));
    }

    private function price(AiModel $m, string $unit, int $micros): void
    {
        app(PriceBook::class)->supersede($m, $unit, $micros);
    }

    /** مشتریِ پول‌دار + کلیدِ AIِ سبز — حکمِ admission واقعی، نه ساختگی */
    private function auth(Customer $c, int $credit = 1_000_000): AiAuthContext
    {
        app(Wallet::class)->credit($c->id, 'IRT', $credit, 'topup', $c, 'شارژِ آزمون');

        $p = $c->aiProjects()->create([
            'name' => 'proj', 'slug' => 'p'.random_int(1, 99999),
            'status' => AiProject::STATUS_ACTIVE,
        ]);
        $p->refreshBudgetWindow();

        [$t] = CustomerApiToken::issue($c->id, 'ai', ['ai:chat'], [], null, $p->id);

        $ctx = AiAdmission::authorize($t, 'ai:chat', '1.2.3.4');
        $this->assertTrue($ctx->ok, 'آماده‌سازیِ تست باید admission سبز بگیرد');

        return $ctx;
    }

    private function chatPayload(array $over = []): array
    {
        return array_merge([
            'messages' => [['role' => 'user', 'content' => 'سلام، جواب کوتاه بده.']],
            'max_tokens' => 50,
        ], $over);
    }

    private function fakeOk(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'id' => 'cmpl-1',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'سلام!']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);
    }

    // ═══════════════ مسیرِ سبز ═══════════════

    public function test_success_settles_full_reservation(): void
    {
        $c = $this->customer();
        $auth = $this->auth($c, 1_000_000);
        $p = $this->provider();
        $m = $this->model($p);
        $this->price($m, AiModelUnitPrice::UNIT_INPUT, self::PRICE_INPUT_MICROS);
        $this->price($m, AiModelUnitPrice::UNIT_OUTPUT, self::PRICE_OUTPUT_MICROS);
        Setting::put('ai_provider_deepinfra_base_url', 'https://api.deepinfra.com/v1/openai');
        Setting::putSecret('ai_provider_deepinfra_key', 'sk-test');
        $this->fakeOk();
        $wallet = app(Wallet::class);

        $out = app(AiCaller::class)->handle($auth, 'llama-3-8b', $this->chatPayload(), 'key-1');

        $this->assertTrue($out->ok, $out->message);
        $this->assertSame('ok', $out->code);
        $this->assertSame('سلام!', $out->response['choices'][0]['message']['content']);

        // رزروِ بدترین‌حالت: تخمینِ ورودی + تمامِ max_tokens — ریاضیاتش فقط PriceBook
        $inputTokens = (int) ceil(strlen('سلام، جواب کوتاه بده.') / 4);
        $expect = (int) ceil(self::PRICE_INPUT_MICROS * $inputTokens / 1_000_000)
            + (int) ceil(self::PRICE_OUTPUT_MICROS * 50 / 1_000_000);
        $this->assertSame($expect, $out->reservedMicros, 'برآوردِ بدترین‌حالت باید دقیقاً همین باشد');

        // settle تمامِ رزرو: دفتر دقیقاً یک بار خرج شد و نگه‌دارده خالی است
        $this->assertSame(AiReservation::STATUS_SETTLED, $out->reservation->fresh()->status);
        $this->assertSame(1_000_000 - $expect, $wallet->balanceOf($c->id));
        $this->assertSame(0, app(\App\Services\Ai\AiReservations::class)->heldOf($c->id));

        // تماسِ واقعی: اسلاگِ عمومی هرگز به بالادست نمی‌رود
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/chat/completions')
                && $req['model'] === 'meta-llama/Llama-3-8B-Instruct'
                && $req->hasHeader('Authorization', 'Bearer sk-test');
        });
    }

    // ═══════════════ مسیرِ قرمزِ بالادست ═══════════════

    public function test_upstream_5xx_releases_reservation(): void
    {
        $c = $this->customer();
        $auth = $this->auth($c);
        $p = $this->provider();
        $m = $this->model($p);
        $this->price($m, AiModelUnitPrice::UNIT_INPUT, self::PRICE_INPUT_MICROS);
        $this->price($m, AiModelUnitPrice::UNIT_OUTPUT, self::PRICE_OUTPUT_MICROS);
        Setting::put('ai_provider_deepinfra_base_url', 'https://api.deepinfra.com/v1/openai');
        Setting::putSecret('ai_provider_deepinfra_key', 'sk-test');

        Http::fake(['*/chat/completions' => Http::response(['error' => 'boom'], 500)]);

        $out = app(AiCaller::class)->handle($auth, 'llama-3-8b', $this->chatPayload(), 'key-2');

        $this->assertFalse($out->ok);
        $this->assertSame('upstream_error', $out->code);

        // آزادسازی: پول بی‌دفتر برگشت و رزرو پایانیِ released است
        $this->assertSame(AiReservation::STATUS_RELEASED, $out->reservation->fresh()->status);
        $this->assertSame(1_000_000, app(Wallet::class)->balanceOf($c->id));
        $this->assertSame(1_000_000, app(\App\Services\Ai\AiReservations::class)->availableOf($c->id));
        $this->assertSame(0, \App\Models\CreditEntry::where('customer_id', $c->id)->where('reason', 'ai_reservation')->count());
    }

    // ═══════════════ گیت‌هایِ پیش از رزرو ═══════════════

    public function test_unpriced_model_never_reserves_and_never_calls(): void
    {
        $auth = $this->auth($this->customer());
        $p = $this->provider();
        $this->model($p);
        Setting::put('ai_provider_deepinfra_base_url', 'https://api.deepinfra.com/v1/openai');
        $this->fakeOk();

        $out = app(AiCaller::class)->handle($auth, 'llama-3-8b', $this->chatPayload());

        $this->assertFalse($out->ok);
        $this->assertSame('not_priced', $out->code);
        $this->assertSame(0, AiReservation::count(), 'مدلِ بی‌قیمت هیچ پولی قفل نمی‌کند');
        Http::assertNothingSent();
    }

    public function test_provider_without_live_calls_is_rejected_before_any_http(): void
    {
        $auth = $this->auth($this->customer());
        $p = $this->provider(['live_calls_enabled' => false]);
        $m = $this->model($p);
        $this->price($m, AiModelUnitPrice::UNIT_INPUT, self::PRICE_INPUT_MICROS);
        $this->price($m, AiModelUnitPrice::UNIT_OUTPUT, self::PRICE_OUTPUT_MICROS);
        $this->fakeOk();

        $out = app(AiCaller::class)->handle($auth, 'llama-3-8b', $this->chatPayload());

        $this->assertFalse($out->ok);
        $this->assertSame('provider_not_live', $out->code);
        $this->assertSame(0, AiReservation::count());
        Http::assertNothingSent();
    }

    public function test_insufficient_funds_fails_before_any_http(): void
    {
        $auth = $this->auth($this->customer(), 1); // یک تومان — برآورد قطعاً بالاتر
        $p = $this->provider();
        $m = $this->model($p);
        $this->price($m, AiModelUnitPrice::UNIT_INPUT, self::PRICE_INPUT_MICROS);
        $this->price($m, AiModelUnitPrice::UNIT_OUTPUT, self::PRICE_OUTPUT_MICROS);
        $this->fakeOk();

        $out = app(AiCaller::class)->handle($auth, 'llama-3-8b', $this->chatPayload());

        $this->assertFalse($out->ok);
        $this->assertSame('insufficient_funds', $out->code);
        $this->assertSame(0, AiReservation::count());
        Http::assertNothingSent();
    }

    public function test_denied_admission_passes_through_without_side_effects(): void
    {
        $denied = AiAuthContext::denied('token_revoked', 'کلید باطل است.');

        $out = app(AiCaller::class)->handle($denied, 'llama-3-8b', $this->chatPayload());

        $this->assertFalse($out->ok);
        $this->assertSame('token_revoked', $out->code, 'AiCaller قاضی نیست؛ کدِ admission بی‌واسطه بالا می‌رود');
        Http::assertNothingSent();
    }

    // ═══════════════ هم‌ارزی ═══════════════

    public function test_duplicate_idempotency_key_never_calls_upstream_twice(): void
    {
        $auth = $this->auth($this->customer());
        $p = $this->provider();
        $m = $this->model($p);
        $this->price($m, AiModelUnitPrice::UNIT_INPUT, self::PRICE_INPUT_MICROS);
        $this->price($m, AiModelUnitPrice::UNIT_OUTPUT, self::PRICE_OUTPUT_MICROS);
        Setting::put('ai_provider_deepinfra_base_url', 'https://api.deepinfra.com/v1/openai');
        Setting::putSecret('ai_provider_deepinfra_key', 'sk-test');
        $this->fakeOk();

        $caller = app(AiCaller::class);
        $first = $caller->handle($auth, 'llama-3-8b', $this->chatPayload(), 'same-key');
        $dup = $caller->handle($auth, 'llama-3-8b', $this->chatPayload(), 'same-key');

        $this->assertTrue($first->ok);
        $this->assertFalse($dup->ok);
        $this->assertSame('duplicate_request', $dup->code);

        // همانِ ردیفِ رزروِ اول — نه رزروِ دوم
        $this->assertSame($first->reservation->id, $dup->reservation->id);
        $this->assertSame(1, AiReservation::count(), 'یک کلید = یک رزرو');

        // 🔴 تماسِ دومی به بالادست نرفت
        Http::assertSentCount(1);
    }
}
