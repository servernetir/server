<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProject;
use App\Models\AiProvider;
use App\Models\AiReservation;
use App\Models\AiUsage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiCaller;
use App\Services\Ai\AiPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

/**
 * سدهای پیش از پول (m5-spec §4.A): درِ فروش و پرچم‌های قرارداد، بدنه، کیفِ کوچک،
 * هم‌زمانی، بودجه — و اینکه هیچ ردی هیچ پولی نگه نمی‌دارد و هیچ تماسی نمی‌فرستد.
 */
class AiGatesAndLimitsTest extends TestCase
{
    use AiGatewayFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
        $this->fakeUpstream();
    }

    private function refused(string $plain, string $code, int $status, array $body = []): void
    {
        $this->v1($plain, $body ?: $this->chatBody())->assertStatus($status)->assertJsonPath('code', $code);
        $this->assertSame(0, AiReservation::count(), "{$code}: هیچ رزروی نباید باز شود");
        Http::assertNothingSent();
    }

    /* ═══ B7: پرچم‌های فروش — هر کدام جدا ═══ */

    public function test_each_contract_flag_closes_the_provider(): void
    {
        [, $plain] = $this->greenKey();

        foreach ([
            ['commercial_enabled' => false],
            ['resale_allowed' => false],
            ['agreement_status' => AiProvider::STATUS_REQUESTED],
        ] as $flag) {
            $this->provider->update($flag);
            $this->refused($plain, 'provider_not_sellable', 503);
            $this->sellableCatalogReset();
        }
    }

    public function test_paused_provider_suspended_model_and_null_fee_are_refused(): void
    {
        [, $plain] = $this->greenKey();

        $this->provider->update(['paused_at' => now(), 'paused_reason' => 'x']);
        $this->refused($plain, 'provider_paused', 503);
        $this->provider->update(['paused_at' => null]);

        $this->model->update(['suspended_at' => now()]);
        $this->refused($plain, 'model_suspended', 503);
        $this->model->update(['suspended_at' => null]);

        $this->provider->update(['fx_fee_bp' => null]);
        $this->refused($plain, 'pricing_incomplete', 503);
    }

    public function test_unset_margin_or_missing_fx_closes_sales(): void
    {
        [, $plain] = $this->greenKey();

        Setting::put('ai_margin_pct', null);
        $this->refused($plain, 'pricing_incomplete', 503);

        Setting::put('ai_margin_pct', '25');
        Setting::put('pricing_usd_rate_override', null);
        $this->refused($plain, 'fx_unavailable', 503);
    }

    public function test_non_chat_model_is_not_found_on_the_chat_route(): void
    {
        [, $plain] = $this->greenKey();
        $this->model->update(['category' => AiModel::CATEGORY_EMBEDDING]);

        $this->refused($plain, 'model_not_found', 404);
    }

    public function test_provider_daily_cost_cap_stops_new_calls(): void
    {
        [, $plain] = $this->greenKey();
        $this->provider->update(['daily_cost_cap_micro' => 1_000]);
        AiUsage::create($this->usageRow(['cost_micro' => 1_000, 'sent_at' => now(), 'status' => AiUsage::STATUS_SETTLED]));

        $this->v1($plain, $this->chatBody())->assertStatus(503)->assertJsonPath('code', 'provider_daily_cap');
        Http::assertNothingSent();
    }

    /* ═══ G2: کیفِ کوچک ═══ */

    public function test_small_wallet_without_max_tokens_gets_a_smaller_output_not_a_402(): void
    {
        [$c, $plain] = $this->greenKey(150);

        $this->v1($plain, $this->chatBody())->assertOk();

        $u = AiUsage::sole();
        $this->assertLessThan(4096, (int) $u->max_output_tokens);
        $this->assertGreaterThanOrEqual(256, (int) $u->max_output_tokens);
        $this->assertLessThanOrEqual(150, (int) $u->hold_irt);
        Http::assertSent(fn (HttpRequest $r) => $r['max_tokens'] === (int) $u->max_output_tokens);
    }

    public function test_wallet_too_small_even_for_256_tokens_is_402_with_amounts(): void
    {
        [, $plain] = $this->greenKey(5);

        $this->v1($plain, $this->chatBody())->assertStatus(402)->assertJsonPath('code', 'insufficient_funds')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'تومان رزرو لازم دارد'));
        Http::assertNothingSent();
    }

    public function test_explicit_max_tokens_is_never_silently_lowered(): void
    {
        [, $plain] = $this->greenKey(150);

        $this->v1($plain, $this->chatBody(['max_tokens' => 4096]))->assertStatus(402);
        Http::assertNothingSent();
    }

    /* ═══ هم‌زمانی و بودجه ═══ */

    public function test_fifth_concurrent_call_of_one_customer_is_429(): void
    {
        [$c, $plain] = $this->greenKey();
        foreach (range(1, 4) as $_) {
            AiUsage::create($this->usageRow(['customer_id' => $c->id, 'status' => AiUsage::STATUS_SENDING, 'decide_by' => now()->addHour()]));
        }

        $this->v1($plain, $this->chatBody())->assertStatus(429)->assertJsonPath('code', 'too_many_inflight')->assertHeader('Retry-After', '5');
        Http::assertNothingSent();
    }

    public function test_project_budget_counts_settled_spend_and_open_holds(): void
    {
        [$c, $plain, , $project] = $this->greenKey(1_000_000, 'fa', [
            'monthly_budget_irt' => 500, 'budget_period' => AiProject::PERIOD_MONTHLY, 'budget_reset_day' => 1,
        ]);
        AiUsage::create($this->usageRow([
            'customer_id' => $c->id, 'ai_project_id' => $project->id, 'status' => AiUsage::STATUS_SETTLED,
            'charged_irt' => 400, 'settled_at' => now(),
        ]));

        $this->v1($plain, $this->chatBody())->assertStatus(402)->assertJsonPath('code', 'budget_exceeded');
        Http::assertNothingSent();
    }

    public function test_token_daily_cap_is_enforced_per_tehran_day(): void
    {
        [$c, $plain, $token] = $this->greenKey(1_000_000, 'fa', [], ['daily_spend_cap_irt' => 100]);

        $this->v1($plain, $this->chatBody(['max_tokens' => 4096]))->assertStatus(402)->assertJsonPath('code', 'daily_cap_exceeded');
    }

    /* ═══ بدنه و درایور ═══ */

    public function test_payload_hash_ignores_key_order_and_counts_tools_in_the_bound(): void
    {
        $this->assertSame(
            AiPayload::hash(['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'x']], 'temperature' => 1]),
            AiPayload::hash(['temperature' => 1, 'messages' => [['content' => 'x', 'role' => 'user']], 'model' => 'm']),
        );

        $plain = app(AiPayload::class)->prepare($this->chatBody(), $this->model);
        $withTools = app(AiPayload::class)->prepare($this->chatBody(['tools' => [['type' => 'function',
            'function' => ['name' => 'lookup', 'description' => str_repeat('d', 2_000), 'parameters' => ['type' => 'object']]]]]), $this->model);
        $this->assertGreaterThan($plain->maxInput + 2_000, $withTools->maxInput);

        $mapped = app(AiPayload::class)->prepare($this->chatBody(['max_completion_tokens' => 300]), $this->model);
        $this->assertSame(300, $mapped->maxOutput);
        $this->assertSame(300, $mapped->body()['max_tokens']);
        $this->assertArrayNotHasKey('max_completion_tokens', $mapped->body());
    }

    public function test_every_provider_driver_resolves_after_the_driver_migration(): void
    {
        foreach (AiProvider::all() as $p) {
            $this->assertNotNull(AiCaller::driverFor($p), "درایورِ «{$p->slug}» ({$p->driver}) شناخته نیست");
        }
    }

    public function test_admin_sets_the_api_key_write_only_and_it_never_comes_back(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $url = '/admin/ai/providers/'.$this->provider->id;

        $this->actingAs($admin)->post($url, ['agreement_status' => 'signed', 'api_key' => 'sk-live-SECRET-123',
            'base_url' => 'https://api.deepinfra.com/v1/openai', 'driver' => 'OpenAI-Compatible'])->assertRedirect();

        $this->assertSame('sk-live-SECRET-123', $this->provider->fresh()->apiKey());
        $this->assertStringNotContainsString('sk-live-SECRET-123', (string) Setting::get('ai_provider_deepinfra_key'), 'رمزشده ذخیره شود');

        $html = $this->actingAs($admin)->get('/admin/ai/providers/edit?provider='.$this->provider->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('sk-live-SECRET-123', $html);
        $this->assertStringContainsString('ثبت شده', $html);

        // خالی = بی‌تغییر
        $this->actingAs($admin)->post($url, ['agreement_status' => 'signed', 'api_key' => ''])->assertRedirect();
        $this->assertSame('sk-live-SECRET-123', $this->provider->fresh()->apiKey());

        $this->actingAs($admin)->post($url, ['agreement_status' => 'signed', 'forget_key' => '1'])->assertRedirect();
        $this->assertNull($this->provider->fresh()->apiKey());
    }

    public function test_admin_can_lift_an_automatic_pause(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->provider->update(['paused_at' => now(), 'paused_reason' => 'خودکار: HTTP 402']);

        $this->actingAs($admin)->post('/admin/ai/providers/'.$this->provider->id, ['agreement_status' => 'signed',
            'enabled' => '1', 'live_calls_enabled' => '1', 'commercial_enabled' => '1', 'resale_allowed' => '1', 'unpause' => '1'])
            ->assertRedirect();

        $this->assertNull($this->provider->fresh()->paused_at);
    }

    /* ─────────────────────────────────────────────────────────── */

    private function sellableCatalogReset(): void
    {
        $this->provider->update(['commercial_enabled' => true, 'resale_allowed' => true, 'agreement_status' => AiProvider::STATUS_SIGNED]);
    }

    private function usageRow(array $over): array
    {
        return $over + [
            'customer_id' => 1, 'ai_provider_id' => $this->provider->id, 'ai_model_id' => $this->model->id,
            'model_slug' => $this->model->slug, 'upstream_model' => $this->model->upstream_model,
            'request_sha256' => str_repeat('0', 64), 'status' => AiUsage::STATUS_SETTLED,
            'max_input_tokens' => 1, 'max_output_tokens' => 1, 'price_currency' => 'USD',
            'input_price_id' => 1, 'output_price_id' => 1, 'input_rate_micro' => 1, 'output_rate_micro' => 1,
            'fx_rate_toman' => 100_000, 'fx_source' => 'override', 'fee_bp' => 0, 'margin_bp' => 1, 'vat_bp' => 0,
            'vat_basis' => 'fa_locale', 'p_input_irt_m' => 1, 'p_cached_irt_m' => 1, 'p_output_irt_m' => 1,
            'hold_sell_irt' => 0, 'hold_tax_irt' => 0, 'hold_irt' => 0, 'decide_by' => now()->addHour(),
        ];
    }
}
