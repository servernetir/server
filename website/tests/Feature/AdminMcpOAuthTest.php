<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\Mcp\AdminAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AdminMcpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://chatgpt.com/connector/oauth/test-callback';
    private const RESOURCE = 'https://console.servernet.cloud/api/mcp/admin';
    private static ?string $keyDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (self::$keyDir === null) {
            self::$keyDir = sys_get_temp_dir().'/servernet-mcp-oauth-'.getmypid();
            mkdir(self::$keyDir, 0700, true);
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            openssl_pkey_export($key, $private);
            file_put_contents(self::$keyDir.'/oauth-private.key', $private);
            file_put_contents(self::$keyDir.'/oauth-public.key', openssl_pkey_get_details($key)['key']);
            chmod(self::$keyDir.'/oauth-private.key', 0600);
            chmod(self::$keyDir.'/oauth-public.key', 0600);
            register_shutdown_function(function () {
                unlink(self::$keyDir.'/oauth-private.key'); unlink(self::$keyDir.'/oauth-public.key'); rmdir(self::$keyDir);
            });
        }
        Passport::loadKeysFrom(self::$keyDir);
    }

    private function enable(?User $admin = null): User
    {
        $admin ??= User::factory()->create(['role' => 'admin']);
        Setting::put('mcp_admin_enabled', '1');
        Setting::put('mcp_admin_owner_user_id', (string) $admin->id);
        Setting::put('mcp_admin_user_ids', json_encode([$admin->id]));
        return $admin;
    }

    private function registerClient(): string
    {
        return $this->postJson('/api/mcp/oauth/register', [
            'client_name' => 'ChatGPT test', 'redirect_uris' => [self::CALLBACK],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');
    }

    private function authorizationQuery(string $client, string $scope = AdminAccess::READ): array
    {
        return ['client_id' => $client, 'redirect_uri' => self::CALLBACK, 'response_type' => 'code',
            'scope' => $scope, 'state' => 'random-state-for-fixture', 'resource' => self::RESOURCE,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256'];
    }

    private function issue(?User $admin = null, string $scope = AdminAccess::READ): array
    {
        $admin ??= $this->enable();
        $client = $this->registerClient();
        $this->actingAs($admin, 'web');
        $this->get('/admin/mcp/oauth/authorize?'.http_build_query($this->authorizationQuery($client, $scope)))
            ->assertOk()->assertSee('ChatGPT test');
        $authToken = session('authToken');
        $location = $this->post('/admin/mcp/oauth/authorize', ['auth_token' => $authToken])->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('random-state-for-fixture', $query['state']);
        $data = ['client_id' => $client, 'redirect_uri' => self::CALLBACK,
            'grant_type' => 'authorization_code', 'code' => $query['code'],
            'code_verifier' => str_repeat('a', 64), 'resource' => self::RESOURCE];
        $tokens = $this->post('/api/mcp/oauth/token', $data, ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertArrayHasKey('access_token', $tokens);
        return [$admin, $client, $tokens, $data];
    }

    private function rpc(string $token, string $method = 'tools/list', array $params = [])
    {
        Auth::forgetGuards();
        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/mcp/admin', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ]);
    }

    public function test_metadata_is_canonical_and_available_on_console_without_a_session(): void
    {
        $this->getJson('https://console.servernet.cloud/.well-known/oauth-authorization-server')
            ->assertOk()->assertJsonPath('issuer', 'https://console.servernet.cloud')
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256');
        $this->getJson('https://untrusted.example/.well-known/oauth-protected-resource/api/mcp/admin')
            ->assertOk()->assertJsonPath('resource', self::RESOURCE)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_disabled_gateway_and_registration_cannot_grant_access(): void
    {
        $this->postJson('/api/mcp/admin', [])->assertStatus(503);
        $this->postJson('/api/mcp/oauth/register', ['redirect_uris' => [self::CALLBACK]])->assertStatus(503);
    }

    public function test_missing_token_has_a_discovery_challenge_and_session_is_not_a_token(): void
    {
        $admin = $this->enable();
        $this->actingAs($admin, 'web')->postJson('/api/mcp/admin', [])->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="https://console.servernet.cloud/.well-known/oauth-protected-resource/api/mcp/admin"');
    }

    public function test_registration_allows_only_exact_chatgpt_https_callbacks(): void
    {
        $this->enable();
        foreach (['http://chatgpt.com/connector/oauth/id', 'https://chatgpt.com.evil.test/connector/oauth/id',
            'https://chatgpt.com@evil.test/connector/oauth/id', self::CALLBACK.'?next=evil',
            'https://chatgpt.com/other', 'https://127.0.0.1/callback'] as $uri) {
            $this->postJson('/api/mcp/oauth/register', ['redirect_uris' => [$uri]])->assertBadRequest();
        }
        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function test_authorization_rejects_customer_staff_and_non_allowlisted_admin(): void
    {
        $this->enable();
        $client = $this->registerClient();
        $url = '/admin/mcp/oauth/authorize?'.http_build_query($this->authorizationQuery($client));
        foreach (['author', 'support', 'admin'] as $role) {
            Auth::forgetGuards();
            $this->actingAs(User::factory()->create(['role' => $role]), 'web')->get($url)->assertForbidden();
        }
        Auth::forgetGuards();
        $this->actingAs(Customer::create(['email' => 'mcp-customer@example.test', 'status' => 'active']), 'customer')->get($url)->assertRedirect('/admin/login');
    }

    public function test_pkce_resource_and_scopes_are_required_before_consent(): void
    {
        $admin = $this->enable(); $client = $this->registerClient();
        $this->actingAs($admin, 'web');
        foreach ([['code_challenge_method' => 'plain'], ['resource' => 'https://evil.test/mcp'],
            ['scope' => '*'], ['scope' => AdminAccess::EXECUTE], ['scope' => AdminAccess::RELEASE_REVIEW], ['code_challenge' => 'short']] as $bad) {
            $this->getJson('/admin/mcp/oauth/authorize?'.http_build_query(array_replace($this->authorizationQuery($client), $bad)))
                ->assertBadRequest();
        }
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_real_pkce_code_exchange_binds_issuer_and_audience_and_lists_read_tools_only(): void
    {
        [$admin, $client, $tokens] = $this->issue();
        $claims = json_decode(base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/')), true);
        $this->assertSame('https://console.servernet.cloud', $claims['iss']);
        $this->assertContains(self::RESOURCE, $claims['aud']);
        $tools = $this->rpc($tokens['access_token'])->assertOk()->json('result.tools');
        $this->assertSame(['infrastructure_inventory', 'infrastructure_status', 'ssh_diagnostic'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
        }
        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'request_ssh_command', 'arguments' => ['target' => 'x', 'command' => 'reboot']])
            ->assertJsonStructure(['error']);
        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'release_manifest_review', 'arguments' => [
            'release' => 'fixture-release', 'commit' => str_repeat('a', 40), 'manifest_sha256' => str_repeat('b', 64),
        ]])->assertJsonStructure(['error']);
    }

    public function test_release_review_requires_separate_consent_and_does_not_grant_execution(): void
    {
        [, , $tokens] = $this->issue(scope: AdminAccess::READ.' '.AdminAccess::RELEASE_REVIEW);
        $tools = $this->rpc($tokens['access_token'])->assertOk()->json('result.tools');
        $names = array_column($tools, 'name');
        $this->assertContains('release_manifest_review', $names);
        $this->assertNotContains('request_ssh_command', $names);
        $this->assertNotContains('execute_ssh_command', $names);
        $this->assertNotContains('admin_ticket_reply', $names);
        $review = collect($tools)->firstWhere('name', 'release_manifest_review');
        $this->assertTrue($review['annotations']['readOnlyHint']);
        $this->assertFalse($review['inputSchema']['additionalProperties']);
        $this->assertSame([AdminAccess::RELEASE_REVIEW], $review['securitySchemes'][0]['scopes']);
        $this->assertSame(['release', 'commit', 'manifest_sha256'], array_keys($review['inputSchema']['properties']));
        $commit = str_repeat('a', 40);
        $manifest = json_encode([
            'schema' => 'servernet-web-release/v1', 'release' => 'fixture-release',
            'git_source_commit' => str_repeat('b', 40), 'deployment_authorized' => false,
            'files' => [[
                'source' => 'website/public/assets/css/mcp-review-fixture.css',
                'destination' => 'public_html/assets/css/mcp-review-fixture.css',
                'operation' => 'add', 'before' => null, 'after' => str_repeat('c', 64),
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        config(['mcp_admin.release_app_root' => base_path(), 'mcp_admin.release_public_root' => public_path()]);
        Http::fake(['https://raw.githubusercontent.com/servernetir/server/'.$commit.'/scripts/releases/fixture-release/manifest.json' => Http::response($manifest)]);
        $response = $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'release_manifest_review', 'arguments' => [
            'release' => 'fixture-release', 'commit' => $commit, 'manifest_sha256' => hash('sha256', $manifest),
        ]])->assertOk()->assertJsonStructure(['result' => ['content']]);
        $this->assertStringContainsString('review_only', $response->getContent());
        $this->assertStringContainsString('deployment_authorized', $response->getContent());
        Http::assertSentCount(1);
        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'request_ssh_command', 'arguments' => ['target' => 'fixture', 'command' => 'false']])
            ->assertJsonStructure(['error']);
        $this->assertDatabaseCount('mcp_admin_actions', 0);
    }

    public function test_management_scopes_are_explicit_and_do_not_expand_infrastructure_grants(): void
    {
        [, , $tokens] = $this->issue(scope: AdminAccess::READ.' '.AdminAccess::MANAGEMENT_READ);
        $names = array_column($this->rpc($tokens['access_token'])->assertOk()->json('result.tools'), 'name');
        $this->assertContains('admin_overview', $names);
        $this->assertContains('admin_ticket', $names);
        $this->assertNotContains('admin_ticket_reply', $names);
        $this->rpc($tokens['access_token'], 'tools/call', ['name' => 'admin_ticket_reply', 'arguments' => []])->assertJsonStructure(['error']);
    }

    public function test_management_reply_scope_must_be_granted_separately(): void
    {
        [, , $tokens] = $this->issue(scope: AdminAccess::READ.' '.AdminAccess::MANAGEMENT_READ.' '.AdminAccess::TICKET_REPLY);
        $this->assertContains('admin_ticket_reply', array_column($this->rpc($tokens['access_token'])->assertOk()->json('result.tools'), 'name'));
    }

    public function test_authorization_code_cannot_be_replayed_or_exchanged_for_another_resource(): void
    {
        [, , , $data] = $this->issue();
        $this->post('/api/mcp/oauth/token', $data, ['Accept' => 'application/json'])->assertStatus(400);
        $this->post('/api/mcp/oauth/token', array_replace($data, ['resource' => 'https://other.test']),
            ['Accept' => 'application/json'])->assertBadRequest()->assertJsonPath('error', 'invalid_target');
    }

    public function test_signed_token_is_denied_immediately_after_role_or_allowlist_changes(): void
    {
        [$admin, , $tokens] = $this->issue();
        $admin->update(['role' => 'support']);
        $this->rpc($tokens['access_token'])->assertForbidden();
        $admin->update(['role' => 'admin']);
        Setting::put('mcp_admin_user_ids', '[]');
        $this->rpc($tokens['access_token'])->assertForbidden();
    }

    public function test_refresh_rotates_and_console_revocation_blocks_access_and_refresh(): void
    {
        [$admin, $client, $tokens] = $this->issue();
        $data = ['grant_type' => 'refresh_token', 'client_id' => $client,
            'refresh_token' => $tokens['refresh_token'], 'resource' => self::RESOURCE];
        $new = $this->post('/api/mcp/oauth/token', $data, ['Accept' => 'application/json'])->assertOk()->json();
        $this->post('/api/mcp/oauth/token', $data, ['Accept' => 'application/json'])->assertBadRequest();
        Auth::forgetGuards();
        $this->actingAs($admin, 'web')->post('/admin/mcp/connections/'.$client.'/revoke')->assertRedirect('/admin/mcp');
        $this->rpc($new['access_token'])->assertUnauthorized();
        $data['refresh_token'] = $new['refresh_token'];
        $this->post('/api/mcp/oauth/token', $data, ['Accept' => 'application/json'])->assertBadRequest();
    }

    public function test_connect_dashboard_is_admin_only_and_enable_is_explicit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::put('mcp_admin_owner_user_id', (string) $admin->id);
        $this->actingAs($admin, 'web')->get('/admin/mcp')->assertOk()->assertSee('MCP');
        $this->post('/admin/mcp/enable')->assertSessionHasErrors('accept');
        $this->post('/admin/mcp/enable', ['accept' => '1'])->assertRedirect('/admin/mcp');
        $this->assertTrue(AdminAccess::allowed($admin));
        $this->post('/admin/mcp/disable')->assertRedirect('/admin/mcp');
        $this->assertFalse(AdminAccess::allowed($admin));
    }
}
