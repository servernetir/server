<?php

namespace Tests\Feature;

use App\Models\AiProject;
use App\Models\AiUsage;
use App\Models\CreditEntry;
use App\Models\CustomerApiToken;
use App\Services\Studio\StudioAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class StudioSsoTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
        $this->verifier = str_repeat('v', 43);
        config(['studio.enabled' => true, 'studio.client_secret' => str_repeat('s', 48),
            'studio.client_id' => 'servernet-studio', 'studio.redirect_uri' => 'https://studio.servernet.test/auth/callback']);
    }

    private function requestData(): array
    {
        return ['client_id' => 'servernet-studio', 'redirect_uri' => config('studio.redirect_uri'),
            'state' => str_repeat('a', 43), 'code_challenge' => StudioAuthorization::challenge($this->verifier),
            'code_challenge_method' => 'S256'];
    }

    private function code($customer): string
    {
        $service = app(StudioAuthorization::class);
        return $service->issue($customer, $service->validateRequest($this->requestData()));
    }

    private function exchange(string $code, array $over = [])
    {
        return $this->postJson('/api/studio/token', $over + ['client_id' => 'servernet-studio',
            'client_secret' => config('studio.client_secret'), 'redirect_uri' => config('studio.redirect_uri'),
            'code' => $code, 'code_verifier' => $this->verifier]);
    }

    public function test_disabled_feature_has_no_public_exchange(): void
    {
        config(['studio.enabled' => false]);
        $this->exchange(str_repeat('c', 43))->assertNotFound();
        $this->get('/login/studio?'.http_build_query($this->requestData()))->assertNotFound();
    }

    public function test_login_entry_preserves_only_validated_internal_destination(): void
    {
        foreach (['' => '', '/en' => 'en.', '/tr' => 'tr.'] as $path => $name) {
            $data = $this->requestData();
            $this->get($path.'/login/studio?'.http_build_query($data))
                ->assertRedirect($path.'/login')->assertSessionHas('url.intended',
                    route($name.'account.studio.authorize', $data, false));
            $this->get($path.'/login/studio?'.http_build_query($data + ['login_method' => 'register']))
                ->assertRedirect($path.'/register');
            config(['google-auth.enabled' => false]);
            $this->get($path.'/login/studio?'.http_build_query($data + ['login_method' => 'google']))
                ->assertRedirect($path.'/login');
        }
    }

    public function test_malformed_entry_never_replaces_the_intended_destination(): void
    {
        $this->withSession(['url.intended' => '/account/invoices']);
        foreach ([['redirect_uri' => 'https://attacker.test/callback'], ['login_method' => 'evil'],
            ['login_method' => ['google']], ['code_challenge_method' => 'plain']] as $over) {
            $this->get('/login/studio?'.http_build_query($over + $this->requestData()))->assertStatus(400)
                ->assertSessionHas('url.intended', '/account/invoices');
        }
        $this->assertSame(0, DB::table('studio_authorization_codes')->count());
    }

    public function test_existing_console_session_goes_to_consent_without_google_roundtrip(): void
    {
        [$customer] = $this->greenKey();
        $data = $this->requestData();
        $this->actingAs($customer, 'customer')->get('/login/studio?'.http_build_query($data + ['login_method' => 'google']))
            ->assertRedirect(route('account.studio.authorize', $data, false));
        $this->assertNull(session('google_oauth'));
        $this->assertSame(0, DB::table('studio_authorization_codes')->count());
    }

    public function test_console_login_and_explicit_consent_are_required(): void
    {
        $this->get('/account/studio/authorize?'.http_build_query($this->requestData()))->assertRedirect();
        [$customer] = $this->greenKey();
        $consent = $this->actingAs($customer, 'customer')->get('/account/studio/authorize?'.http_build_query($this->requestData()))
            ->assertOk()->assertSee('ServerNet Studio')->assertSee('code_challenge');
        $this->assertMatchesRegularExpression(
            '~form-action [^;]+ https://studio\.servernet\.test(?: |;)~',
            $consent->headers->get('Content-Security-Policy') ?? ''
        );
        $this->assertSame(0, DB::table('studio_authorization_codes')->count());
        $response = $this->post('/account/studio/authorize', $this->requestData())->assertRedirect();
        $this->assertStringStartsWith('https://studio.servernet.test/auth/callback?code=', $response->headers->get('Location'));
        $this->assertSame(1, DB::table('studio_authorization_codes')->count());
    }

    public function test_arbitrary_redirect_and_plain_pkce_are_rejected(): void
    {
        [$customer] = $this->greenKey();
        $this->actingAs($customer, 'customer')->get('/account/studio/authorize?'.http_build_query(
            ['redirect_uri' => 'https://attacker.test/callback'] + $this->requestData()))->assertStatus(400);
        $this->getJson('/account/studio/authorize?'.http_build_query(
            ['code_challenge_method' => 'plain'] + $this->requestData()))->assertStatus(400);
        $this->assertSame(0, DB::table('studio_authorization_codes')->count());
    }

    public function test_exchange_is_bound_to_client_pkce_expiry_and_customer(): void
    {
        [$customer] = $this->greenKey();
        $code = $this->code($customer);
        $this->exchange($code, ['client_secret' => 'wrong'])->assertUnauthorized();
        $this->exchange($code, ['code_verifier' => str_repeat('z', 43)])->assertStatus(400);
        $res = $this->exchange($code)->assertOk()
            ->assertJsonPath('customer.id', (string) $customer->id);
        $plain = $res->json('access_token');
        $token = CustomerApiToken::findByPlain($plain);
        $this->assertSame(StudioAuthorization::SCOPES, $token->abilities);
        $this->assertSame($customer->id, $token->customer_id);
        $this->assertSame('servernet-studio', $token->aiProject->slug);
        $this->assertFalse($token->can('domains:write'));
        $this->assertTrue($token->can('ai:native'));
        $this->assertNotSame($plain, $token->token_hash);
        $this->exchange($code)->assertStatus(400);
        $this->assertSame(2, $customer->apiTokens()->count());
    }

    public function test_expired_and_disabled_accounts_cannot_exchange(): void
    {
        [$customer] = $this->greenKey();
        $code = $this->code($customer);
        $this->travel(61)->seconds();
        $this->exchange($code)->assertStatus(400);
        $this->travelBack();
        $code = $this->code($customer);
        $customer->update(['status' => 'suspended']);
        $this->exchange($code)->assertStatus(400);
    }

    public function test_inactive_project_is_not_reactivated_and_active_token_limit_is_preserved(): void
    {
        [$customer] = $this->greenKey();
        $customer->aiProjects()->create(['name'=>'Studio', 'slug'=>'servernet-studio', 'status'=>AiProject::STATUS_DISABLED,
            'budget_period'=>AiProject::PERIOD_NONE]);
        $this->exchange($this->code($customer))->assertForbidden();
        $this->assertSame(AiProject::STATUS_DISABLED, $customer->aiProjects()->where('slug','servernet-studio')->value('status'));
        for ($i=1; $i<CustomerApiToken::MAX_ACTIVE; $i++) {
            $customer->apiTokens()->create(['name'=>'fixture '.$i, 'token_hash'=>hash('sha256','fixture-'.$i), 'abilities'=>['read']]);
        }
        $this->exchange($this->code($customer))->assertStatus(409);
    }

    public function test_wallet_scope_is_explicit_and_customer_id_is_not_accepted_from_input(): void
    {
        [$customer, $plain, $token] = $this->greenKey();
        $this->withToken($plain)->getJson('/v1/studio/account')->assertForbidden();
        $token->update(['abilities'=>StudioAuthorization::SCOPES]);
        $before = CreditEntry::count();
        $this->withToken($plain)->getJson('/v1/studio/account?customer_id=99999')->assertOk()
            ->assertJsonPath('customer.id',(string)$customer->id)->assertJsonPath('wallet.unit','toman')
            ->assertJsonPath('wallet.available',1_000_000);
        $this->assertSame($before,CreditEntry::count());
        $this->withToken($plain)->postJson('/v1/studio/revoke')->assertOk();
        $this->withToken($plain)->getJson('/v1/studio/account')->assertUnauthorized();
    }

    public function test_ai_cost_is_still_settled_by_the_existing_gateway(): void
    {
        [$customer, $plain, $token] = $this->greenKey();
        $token->update(['abilities'=>StudioAuthorization::SCOPES]);
        $this->fakeUsage(10,5,null);
        $this->v1($plain,$this->chatBody())->assertOk();
        $balance = $this->balance($customer);
        $this->assertLessThan(1_000_000,$balance);
        $this->withToken($plain)->getJson('/v1/studio/account')->assertOk()
            ->assertJsonPath('wallet.available',$balance)->assertJsonPath('ai.prompt_tokens_month',10)
            ->assertJsonPath('ai.completion_tokens_month',5);
    }
    public function test_wallet_rate_limit_is_per_key_behind_shared_studio_egress(): void
    {
        [, $plain, $token] = $this->greenKey();
        $token->update(['abilities'=>StudioAuthorization::SCOPES]);
        for ($i=0; $i<60; $i++) {
            $this->withToken($plain)->getJson('/v1/studio/account')->assertOk();
        }
        $this->withToken($plain)->getJson('/v1/studio/account')->assertStatus(429);
        [, $other, $otherToken] = $this->greenKey();
        $otherToken->update(['abilities'=>StudioAuthorization::SCOPES]);
        $this->withToken($other)->getJson('/v1/studio/account')->assertOk();
    }

    public function test_media_quote_creates_no_usage_and_the_ceiling_blocks_a_charge(): void
    {
        [, $key, $token] = $this->greenKey();
        $name = 'black-forest-labs/FLUX-1-schnell';
        $contracts = json_decode(file_get_contents(resource_path('content/ai-native-contracts.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->model->update(['category'=>'image','upstream_model'=>$name,
            'capabilities'=>['native_tariff_hash'=>$contracts[$this->provider->slug.':'.$name]['tariff_hash']]]);
        $body = ['model'=>$this->model->slug,'prompt'=>'A blue square.','width'=>1024,'height'=>1024];
        Http::fake();
        $this->withToken($key)->postJson('/v1/studio/media/quote', $body)->assertForbidden();
        $token->update(['abilities'=>StudioAuthorization::SCOPES]);
        $quote = $this->withToken($key)->postJson('/v1/studio/media/quote', $body)->assertOk()->json('max_hold_irt');
        $this->assertGreaterThan(0, $quote);
        $this->withToken($key)->postJson('/v1/inference', $body, ['X-ServerNet-Max-Hold-Irt'=>(string) ($quote - 1)])
            ->assertConflict()->assertJsonPath('code','quote_changed');
        $this->assertSame(0, AiUsage::count());
        Http::assertNothingSent();
    }

    public function test_free_chat_has_no_wallet_charge_or_paid_provider_fallback(): void
    {
        [$customer, $key, $token] = $this->greenKey();
        $token->update(['abilities'=>StudioAuthorization::SCOPES]);
        $body = ['model'=>'studio-free-chat','messages'=>[['role'=>'user','content'=>'Hi']]];
        $this->withToken($key)->postJson('/v1/studio/free/chat', $body)->assertNotFound();
        config(['studio.free_openrouter_key'=>str_repeat('k', 36)]);
        Http::fake(['https://openrouter.ai/api/v1/chat/completions'=>Http::response([
            'choices'=>[['message'=>['content'=>'Hello']]], 'usage'=>['cost'=>0,'prompt_tokens'=>4,'completion_tokens'=>2],
        ])]);
        $before = $this->balance($customer);
        $this->withToken($key)->postJson('/v1/studio/free/chat', $body)->assertOk()
            ->assertJsonPath('charge_irt',0)->assertJsonPath('text','Hello');
        Http::assertSent(fn ($request) => $request['model']==='openrouter/free' && $request['provider']['max_price']['completion']===0);
        $this->assertSame($before,$this->balance($customer));
        $this->assertSame(0,AiUsage::count());
    }

    public function test_mcp_is_bearer_authenticated_and_only_offers_read_only_models(): void
    {
        $init = ['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['protocolVersion'=>'2025-11-25']];
        $this->postJson('/v1/studio/mcp', $init)->assertUnauthorized();
        [, $key, $token] = $this->greenKey();
        $token->update(['abilities'=>StudioAuthorization::SCOPES]);
        $this->withToken($key)->postJson('/v1/studio/mcp',$init,['Origin'=>'https://untrusted.test'])->assertForbidden();
        $this->withToken($key)->postJson('/v1/studio/mcp',$init)->assertOk()->assertJsonPath('result.protocolVersion','2025-11-25');
        $this->withToken($key)->postJson('/v1/studio/mcp',['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/list'])
            ->assertOk()->assertJsonPath('result.tools.0.name','list_models')->assertJsonPath('result.tools.0.annotations.readOnlyHint',true);
        $this->withToken($key)->postJson('/v1/studio/mcp',['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call',
            'params'=>['name'=>'list_models','arguments'=>['category'=>'chat']]])->assertOk()->assertJsonPath('result.content.0.type','text');
        $this->assertSame(0,AiUsage::count());
    }
}
