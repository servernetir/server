<?php

namespace Tests\Feature;

use App\Models\AiReservation;
use App\Models\AiUsage;
use App\Models\CreditEntry;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

/**
 * قراردادِ HTTP ِ `POST /v1/chat/completions` با موتورِ پولیِ M5.1b.
 *
 * عددها همان مثالِ کارشدهٔ m5-spec §3 است: ۱۲٬۰۰۰ ورودی (۸٬۰۰۰ کش‌شده) + ۹۰۰ خروجی ⇒
 * دقیقاً **۳۲۷ تومان** از کیف (فروش ۲۹۷ + مالیات ۳۰). نسخهٔ M4 همین تماس را به
 * میکرودلار رزرو و کلِ رزرو را کسر می‌کرد (B1، B3) — این تست‌ها جلوی برگشتنش را می‌گیرند.
 */
class V1ChatTest extends TestCase
{
    use AiGatewayFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
    }

    public function test_worked_example_debits_exactly_327_toman_with_headers(): void
    {
        [$c, $plain] = $this->greenKey(1_000_000);
        $this->fakeUsage(12_000, 900, 8_000);

        $res = $this->v1($plain, $this->bigBody())->assertOk()->assertJsonPath('choices.0.message.content', 'سلام!');

        $u = AiUsage::firstOrFail();
        $res->assertHeader('X-Request-Id', $u->public_id)->assertHeader('X-ServerNet-Charge-Irt', '327');

        $this->assertSame(AiUsage::STATUS_SETTLED, $u->status);
        $this->assertSame([297, 30, 327, 238], [(int) $u->sell_irt, (int) $u->tax_irt, (int) $u->charged_irt, (int) $u->cost_irt]);
        $this->assertSame(1_000_000 - 327, $this->balance($c));
        $this->assertSame(1_000_000 - 327, $this->available($c), 'رزرو باید کامل بسته شده باشد');

        $row = CreditEntry::where('customer_id', $c->id)->where('reason', 'ai_usage')->sole();
        $this->assertSame(-327, (int) $row->amount);
        $this->assertSame(AiReservation::STATUS_SETTLED, AiReservation::sole()->status);
    }

    public function test_forwarded_body_forces_max_tokens_and_strips_user(): void
    {
        [, $plain] = $this->greenKey();
        $this->fakeUsage(10, 5, null);

        $this->v1($plain, $this->chatBody(['max_tokens' => 999_999, 'user' => 'alice@example.com', 'metadata' => ['a' => 1]]))
            ->assertOk()->assertHeader('X-ServerNet-Dropped-Params', 'user,metadata');

        Http::assertSent(fn (HttpRequest $r) => $r['max_tokens'] === 4096
            && ! isset($r['user']) && ! isset($r['metadata'])
            && $r['model'] === 'meta-llama/Llama-3.3-70B-Instruct');
    }

    public function test_sales_closed_is_503_before_any_hold_or_call(): void
    {
        Setting::put('ai_sales_open', null);
        [, $plain] = $this->greenKey();
        Http::fake();

        $this->v1($plain, $this->chatBody())->assertStatus(503)->assertJsonPath('code', 'sales_closed');

        $this->assertSame(0, AiReservation::count());
        Http::assertNothingSent();
    }

    public function test_canary_customer_passes_while_sales_are_closed(): void
    {
        Setting::put('ai_sales_open', null);
        [$c, $plain] = $this->greenKey();
        Setting::put('ai_canary_customer_ids', '999,'.$c->id);
        $this->fakeUsage(10, 5, null);

        $this->v1($plain, $this->chatBody())->assertOk();
    }

    public function test_unknown_or_missing_token_is_401(): void
    {
        $this->v1('sn_nope', $this->chatBody())->assertStatus(401)->assertJsonPath('code', 'invalid_token');
        $this->postJson('/v1/chat/completions', $this->chatBody())->assertStatus(401)->assertJsonPath('code', 'invalid_token');
    }

    public function test_revoked_token_is_denied_with_its_code(): void
    {
        [, $plain, $token] = $this->greenKey();
        $token->update(['revoked_at' => now()]);

        $this->v1($plain, $this->chatBody())->assertStatus(401)->assertJsonPath('code', 'token_revoked');
    }

    public function test_empty_wallet_is_402_and_names_the_amounts(): void
    {
        [, $plain] = $this->greenKey(0);
        Http::fake();

        $this->v1($plain, $this->chatBody(['max_tokens' => 4096]))
            ->assertStatus(402)->assertJsonPath('code', 'insufficient_funds');

        $this->assertSame(0, AiReservation::count());
        Http::assertNothingSent();
    }

    public function test_missing_model_is_422_and_unknown_model_404(): void
    {
        [, $plain] = $this->greenKey();

        $this->v1($plain, ['messages' => [['role' => 'user', 'content' => 'x']]])->assertStatus(422)->assertJsonPath('code', 'invalid_payload');
        $this->v1($plain, $this->chatBody(['model' => 'nope']))->assertStatus(404)->assertJsonPath('code', 'model_not_found');
    }

    public function test_stream_n_and_non_text_parts_are_400_with_no_hold(): void
    {
        [, $plain] = $this->greenKey();
        Http::fake();

        $this->v1($plain, $this->chatBody(['stream' => true]))->assertStatus(400)->assertJsonPath('code', 'stream_unsupported');
        $this->v1($plain, $this->chatBody(['n' => 2]))->assertStatus(400)->assertJsonPath('code', 'unsupported_parameter');
        $this->v1($plain, $this->chatBody(['messages' => [['role' => 'user', 'content' => [
            ['type' => 'image_url', 'image_url' => ['url' => 'https://x/y.png']],
        ]]]]))->assertStatus(400)->assertJsonPath('code', 'unsupported_parameter');

        $this->assertSame(0, AiReservation::count());
        Http::assertNothingSent();
    }

    public function test_upstream_5xx_is_502_releases_and_writes_no_ledger(): void
    {
        [$c, $plain] = $this->greenKey();
        Http::fake(['*/chat/completions' => Http::response(['error' => 'boom'], 503)]);

        $this->v1($plain, $this->chatBody())->assertStatus(502)->assertJsonPath('code', 'upstream_error');

        $this->assertSame(AiUsage::STATUS_RELEASED, AiUsage::sole()->status);
        $this->assertSame(1_000_000, $this->available($c));
        $this->assertSame(0, CreditEntry::where('reason', 'like', 'ai_%')->count());
    }

    public function test_same_key_same_body_replays_without_a_second_call_or_charge(): void
    {
        [$c, $plain] = $this->greenKey();
        $this->fakeUsage(12_000, 900, 8_000);

        $first = $this->v1($plain, $this->bigBody(), ['Idempotency-Key' => 'k-1'])->assertOk();
        $second = $this->v1($plain, $this->bigBody(), ['Idempotency-Key' => 'k-1'])->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        Http::assertSentCount(1);
        $this->assertSame(1_000_000 - 327, $this->balance($c));
    }

    public function test_same_key_other_body_is_422_and_bad_key_is_400(): void
    {
        [, $plain] = $this->greenKey();
        $this->fakeUsage(10, 5, null);

        $this->v1($plain, $this->chatBody(), ['Idempotency-Key' => 'k-2'])->assertOk();
        $this->v1($plain, $this->chatBody(['temperature' => 0.2]), ['Idempotency-Key' => 'k-2'])
            ->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
        $this->v1($plain, $this->chatBody(), ['Idempotency-Key' => str_repeat('a', 81)])
            ->assertStatus(400)->assertJsonPath('code', 'invalid_idempotency_key');
    }
}
