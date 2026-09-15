<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiProject;
use App\Models\AiProvider;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\Setting;
use App\Services\Ai\PriceBook;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M4-b — رابطِ عمومیِ `POST /v1/chat/completions` (سازگارِ OpenAI).
 *
 * همین‌طور که AiCallerTest شفاف نوشته، SQLite هم‌زمانی را اثبات نمی‌کند —
 * این‌جا **قراردادِ HTTPِ بیرونی** سنجیده می‌شود: نگاشتِ کدِ پایدار به
 * وضعیت، چاپِ بی‌واسطهٔ کد/پیام، و اینکه هیچ تماسِ بالادستیِ دومی برای
 * کلیدِ هم‌ارزیِ تکراری نرود.
 *
 * 🔴 مرزِ مستند: پاسخِ درخواستِ تکراری `duplicate_request` با ۴۰۹ است —
 *    بازپخشِ بدنهٔ ضبط‌شدهٔ ai_calls کارِ M4-c است و این‌جا قفل نمی‌شود.
 */
class V1ChatTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE_INPUT_MICROS = 500_000;
    private const PRICE_OUTPUT_MICROS = 750_000;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'v1chat'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => null, 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /**
     * مشتریِ پول‌دار + کلیدِ AIِ سبز + **متنِ خامِ توکن**. مسیرِ HTTP با
     * هدرِ Bearer کار می‌کند و `CustomerApiToken::issue` متنِ خام را فقط
     * در لحظهٔ صدور برمی‌گرداند، پس همین‌جا مستقیم می‌سازیم و هشِ همان
     * قراردادِ مدل (`sha256` متن) را ذخیره می‌کنیم — منطقِ جدیدی نوشته
     * نمی‌شود، فقط همان کاری که `issue` می‌کند، با نگه‌داشتنِ متن.
     *
     * @return array{0:Customer,1:string,2:\App\Models\CustomerApiToken}
     */
    private function greenKey(int $credit = 1_000_000): array
    {
        $c = $this->customer();

        // کیفِ صفر: credit با مبلغِ صفر خطا می‌دهد، پس فقط مثبت‌ها شارژ می‌شوند
        if ($credit > 0) {
            app(Wallet::class)->credit($c->id, 'IRT', $credit, 'topup', $c, 'شارژِ آزمون');
        }

        $p = $c->aiProjects()->create([
            'name' => 'proj', 'slug' => 'p'.random_int(1, 99999),
            'status' => AiProject::STATUS_ACTIVE,
        ]);
        $p->refreshBudgetWindow();

        $plain = 'sn_'.bin2hex(random_bytes(24));
        $token = CustomerApiToken::create([
            'customer_id' => $c->id,
            'name' => 'ai-key',
            'token_hash' => hash('sha256', $plain),
            'abilities' => ['ai:chat'],
            'allowed_cidrs' => [],
            'ai_project_id' => $p->id,
        ]);

        return [$c, $plain, $token];
    }

    private function catalog(): void
    {
        $provider = AiProvider::create([
            'slug' => 'deepinfra', 'name' => 'DeepInfra', 'driver' => 'OpenAI-Compatible',
            'enabled' => true, 'live_calls_enabled' => true, 'priority' => 1,
            'agreement_status' => AiProvider::STATUS_SIGNED, 'billing_currency_code' => 'USD',
        ]);

        $m = AiModel::create([
            'ai_provider_id' => $provider->id, 'slug' => 'llama-3-8b',
            'upstream_model' => 'meta-llama/Llama-3-8B-Instruct',
            'name' => 'Llama 3 8B', 'category' => AiModel::CATEGORY_CHAT,
            'status' => AiModel::STATUS_ACTIVE, 'max_output_tokens' => 256,
            'provider_priority' => 1,
        ]);

        app(PriceBook::class)->supersede($m, AiModelUnitPrice::UNIT_INPUT, self::PRICE_INPUT_MICROS);
        app(PriceBook::class)->supersede($m, AiModelUnitPrice::UNIT_OUTPUT, self::PRICE_OUTPUT_MICROS);

        Setting::put('ai_provider_deepinfra_base_url', 'https://api.deepinfra.com/v1/openai');
        Setting::putSecret('ai_provider_deepinfra_key', 'sk-test');
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

    /**
     * @param  array<string,string>  $headers
     */
    private function postChat(array $payload, array $headers = [])
    {
        return $this->postJson('/v1/chat/completions', $payload, $headers);
    }

    // ═══════════════ مسیرِ سبز ═══════════════

    public function test_happy_path_returns_upstream_body_verbatim(): void
    {
        [$customer, $plain, $token] = $this->greenKey();
        $this->catalog();
        $this->fakeOk();

        $res = $this->postChat([
            'model' => 'llama-3-8b',
            'messages' => [['role' => 'user', 'content' => 'سلام، جواب کوتاه بده.']],
            'max_tokens' => 50,
        ], ['Authorization' => 'Bearer '.$plain]);

        $res->assertStatus(200);
        $this->assertSame('cmpl-1', $res->json('id'));
        $this->assertSame('سلام!', $res->json('choices.0.message.content'));
        $this->assertSame(10, $res->json('usage.prompt_tokens'));

        // پاسخِ چت هرگز کش نمی‌شود (میدل‌ورهای امنیتی ممکن است هدرهای
        // دیگری هم بنشانند، پس عضویت سنجیده می‌شود نه تساویِ کامل)
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        // تماسِ واقعی: اسلاگِ عمومی هرگز به بالادست نمی‌رود
        Http::assertSent(function ($req) {
            return str_contains($req->url(), '/chat/completions')
                && $req['model'] === 'meta-llama/Llama-3-8B-Instruct';
        });
    }

    // ═══════════════ احراز هویت ═══════════════

    public function test_unknown_token_is_401_invalid_token(): void
    {
        $this->catalog();
        $this->fakeOk();

        $res = $this->postChat([
            'model' => 'llama-3-8b',
            'messages' => [['role' => 'user', 'content' => 'سلام']],
        ], ['Authorization' => 'Bearer sn_totally-unknown']);

        $res->assertStatus(401);
        $this->assertSame('invalid_token', $res->json('code'));
        Http::assertNothingSent();
    }

    public function test_missing_bearer_header_is_401_invalid_token(): void
    {
        $this->catalog();
        $this->fakeOk();

        $res = $this->postChat([
            'model' => 'llama-3-8b',
            'messages' => [['role' => 'user', 'content' => 'سلام']],
        ]);

        $res->assertStatus(401);
        $this->assertSame('invalid_token', $res->json('code'));
        Http::assertNothingSent();
    }

    // ═══════════════ admission — کد بی‌واسطه ═══════════════

    public function test_revoked_token_denies_admission_with_verbatim_code(): void
    {
        [$customer, $plain, $token] = $this->greenKey();
        $this->catalog();
        $this->fakeOk();
        $token->revoke();

        $res = $this->postChat([
            'model' => 'llama-3-8b',
            'messages' => [['role' => 'user', 'content' => 'سلام']],
        ], ['Authorization' => 'Bearer '.$plain]);

        // کدِ admission بی‌واسطه چاپ می‌شود — نه یک invalid_token همه‌کاره
        $res->assertStatus(401);
        $this->assertSame('token_revoked', $res->json('code'));
        Http::assertNothingSent();
    }

    public function test_non_ai_key_denies_with_not_ai_key(): void
    {
        $c = $this->customer();
        app(Wallet::class)->credit($c->id, 'IRT', 1_000_000, 'topup', $c, 'شارژِ آزمون');
        $this->catalog();
        $this->fakeOk();

        $plain = 'sn_'.bin2hex(random_bytes(24));
        CustomerApiToken::create([
            'customer_id' => $c->id, 'name' => 'plain-read-key',
            'token_hash' => hash('sha256', $plain),
            'abilities' => ['read'], 'allowed_cidrs' => [],
        ]);

        $res = $this->postChat([
            'model' => 'llama-3-8b',
            'messages' => [['role' => 'user', 'content' => 'سلام']],
        ], ['Authorization' => 'Bearer '.$plain]);

        $res->assertStatus(403);
        $this->assertSame('not_ai_key', $res->json('code'));
        Http::assertNothingSent();
    }

    public function test_empty_wallet_is_402_insufficient_funds(): void
    {
        [$customer, $plain, $token] = $this->greenKey(credit: 0);
        $this->catalog();
        $this->fakeOk();

        $res = $this->postChat([
            'model' => 'llama-3-8b',
            'messages' => [['role' => 'user', 'content' => 'سلام']],
        ], ['Authorization' => 'Bearer '.$plain]);

        $res->assertStatus(402);
        $this->assertSame('insufficient_funds', $res->json('code'));
        Http::assertNothingSent();
    }

    // ═══════════════ بدنه ═══════════════

    public function test_missing_model_field_is_422_invalid_payload(): void
    {
        [$customer, $plain, $token] = $this->greenKey();
        $this->catalog();
        $this->fakeOk();

        $res = $this->postChat([
            'messages' => [['role' => 'user', 'content' => 'بدونِ مدل']],
        ], ['Authorization' => 'Bearer '.$plain]);

        $res->assertStatus(422);
        $this->assertSame('invalid_payload', $res->json('code'));
        Http::assertNothingSent();
    }

    // ═══════════════ بالادست ═══════════════

    public function test_upstream_5xx_maps_to_502_upstream_error(): void
    {
        [$customer, $plain, $token] = $this->greenKey();
        $this->catalog();

        Http::fake(['*/chat/completions' => Http::response(['error' => 'boom'], 500)]);

        $res = $this->postChat([
            'model' => 'llama-3-8b',
            'messages' => [['role' => 'user', 'content' => 'سلام']],
        ], ['Authorization' => 'Bearer '.$plain]);

        $res->assertStatus(502);
        $this->assertSame('upstream_error', $res->json('code'));
    }

    // ═══════════════ هم‌ارزی ═══════════════

    public function test_duplicate_idempotency_key_calls_upstream_once_and_409(): void
    {
        [$customer, $plain, $token] = $this->greenKey();
        $this->catalog();
        $this->fakeOk();

        $payload = [
            'model' => 'llama-3-8b',
            'messages' => [['role' => 'user', 'content' => 'سلام، جواب کوتاه بده.']],
        ];
        $headers = ['Authorization' => 'Bearer '.$plain, 'Idempotency-Key' => 'chat-key-1'];

        $first = $this->postChat($payload, $headers);
        $dup = $this->postChat($payload, $headers);

        $first->assertStatus(200);
        $this->assertSame('سلام!', $first->json('choices.0.message.content'));

        /*
        | 🔴 مرزِ M4-b: دومی تماسِ بالادستی **ندارد** و `duplicate_request`
        | با ۴۰۹ رد می‌شود. بازپخشِ بدنهٔ ضبط‌شدهٔ ai_calls کارِ M4-c است؛
        | تا آن گام قرارداد همین است و همین تستش را قفل می‌کند.
        */
        $dup->assertStatus(409);
        $this->assertSame('duplicate_request', $dup->json('code'));

        // 🔴 تماسِ دومی به بالادست نرفت
        Http::assertSentCount(1);
    }
}
