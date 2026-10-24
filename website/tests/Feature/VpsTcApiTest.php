<?php

namespace Tests\Feature;

use App\Services\Cloud\VpsTcApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VpsTcApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function api(): VpsTcApi
    {
        return new VpsTcApi('https://api.vps.tc', 'test-reseller', 'test-password');
    }

    public function test_only_approved_bare_https_origins_are_accepted(): void
    {
        foreach (['https://vps.tc', 'https://api.vps.tc/', 'https://whitelabelservices.us', 'https://portal.whitelabelservices.us'] as $origin) {
            $this->assertTrue(VpsTcApi::validOrigin($origin), $origin);
        }
        foreach (['http://api.vps.tc', 'https://vps.tc.evil.test', 'https://evilvps.tc', 'https://api.vps.tc:443', 'https://user:pass@api.vps.tc', 'https://user@api.vps.tc', 'https://api.vps.tc/api', 'https://api.vps.tc?token=x', 'https://api.vps.tc#fragment', 'https://127.0.0.1', 'https://localhost', 'https://api.vps.tc////', "https://api.vps.tc\n.evil.test"] as $origin) {
            $this->assertFalse(VpsTcApi::validOrigin($origin), $origin);
        }
    }

    public function test_missing_explicit_credentials_never_borrow_another_configuration(): void
    {
        config(['services.vpstc.base_url' => 'https://other.vps.tc', 'services.vpstc.username' => 'other-account', 'services.vpstc.password' => 'other-password']);
        $api = new VpsTcApi('https://api.vps.tc');
        $this->assertFalse($api->isConfigured());
        $this->assertSame('not_configured', $api->balance()['code']);
        Http::assertNothingSent();
    }

    public function test_login_and_bearer_read_keep_the_full_envelope_and_zero_balance(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one', 'refresh' => 'refresh-one']),
            'https://api.vps.tc/api/balance' => Http::response(['details' => ['acc_credit' => '0.00', 'acc_balance' => 0, 'currency' => 'USD'], 'extra' => false]),
        ]);
        $result = $this->api()->balance();
        $this->assertTrue($result['ok']);
        $this->assertSame('0.00', $result['data']['details']['acc_credit']);
        $this->assertFalse($result['data']['extra']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.vps.tc/api/login' && $r->method() === 'POST' && $r['username'] === 'test-reseller' && $r['password'] === 'test-password' && ! $r->hasHeader('Authorization'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.vps.tc/api/balance' && $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer access-one'));
        Http::assertSentCount(2);
    }

    public function test_empty_catalog_is_valid_and_login_is_reused_in_memory(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one', 'refresh' => 'refresh-one']),
            'https://api.vps.tc/api/category' => Http::response(['categories' => [], 'available' => false]),
            'https://api.vps.tc/api/category/8/product' => Http::response(['products' => []]),
        ]);
        $api = $this->api();
        $this->assertSame([], $api->categories()['data']['categories']);
        $this->assertTrue($api->products('8')['ok']);
        Http::assertSentCount(3);
    }

    public function test_authentication_refresh_is_bounded_even_when_read_remains_unauthorized(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one', 'refresh' => 'refresh-one']),
            'https://api.vps.tc/api/token' => Http::response(['token' => 'access-two', 'refresh' => 'refresh-two']),
            'https://api.vps.tc/api/category' => Http::sequence()->push([], 401)->push([], 401),
        ]);
        $result = $this->api()->categories();
        $this->assertFalse($result['ok']);
        $this->assertSame('access_denied', $result['code']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.vps.tc/api/token' && $r['refresh_token'] === 'refresh-one' && ! $r->hasHeader('Authorization'));
        Http::assertSentCount(4);
    }

    public function test_redirects_are_rejected_without_contacting_another_host(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one']),
            'https://api.vps.tc/api/category' => Http::response('', 302, ['Location' => 'https://evil.test/steal']),
        ]);
        $this->assertSame('redirect_rejected', $this->api()->categories()['code']);
        Http::assertSentCount(2);
    }

    public function test_transport_verifies_tls_and_disables_redirects_and_has_timeouts(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(20, $options['timeout']);

            return str_ends_with($request->url(), '/login')
                ? Http::response(['token' => 'access-one'])
                : Http::response(['categories' => []]);
        });
        $this->assertTrue($this->api()->categories()['ok']);
        Http::assertSentCount(2);
    }

    public function test_rate_limit_is_returned_without_retries(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one']),
            'https://api.vps.tc/api/category' => Http::response(['error' => 'limited'], 429, ['Retry-After' => '60']),
        ]);
        $this->assertSame('rate_limited', $this->api()->categories()['code']);
        Http::assertSentCount(2);
    }

    public function test_non_json_and_unknown_envelopes_fail_without_exposing_response_bodies(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one']),
            'https://api.vps.tc/api/category' => Http::sequence()->push('<html>provider-private-details</html>')->push(['unexpected' => ['token' => 'provider-private-details']]),
        ]);
        $api = $this->api();
        foreach ([$api->categories(), $api->categories()] as $result) {
            $this->assertFalse($result['ok']);
            $this->assertSame('invalid_response', $result['code']);
            $this->assertSame([], $result['data']);
            $this->assertStringNotContainsString('provider-private-details', json_encode($result));
        }
    }

    public function test_error_flag_with_expected_key_cannot_be_mistaken_for_success(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one']),
            'https://api.vps.tc/api/category' => Http::response(['status' => false, 'categories' => []]),
        ]);
        $this->assertSame('invalid_response', $this->api()->categories()['code']);
    }

    public function test_resource_ids_cannot_change_the_fixed_read_only_paths(): void
    {
        $api = $this->api();
        foreach (['../service/1/cancel', '1?send=true', '0', '-1', '1/2', '1%2f2', ''] as $id) {
            $this->assertSame('invalid_id', $api->products($id)['code']);
            $this->assertSame('invalid_id', $api->productOrderForm($id)['code']);
            $this->assertSame('invalid_id', $api->vm('1', $id)['code']);
        }
        Http::assertNothingSent();
    }

    public function test_transport_errors_never_return_raw_exception_credentials(): void
    {
        Http::fake(function () { throw new ConnectionException('private-token=test-password'); });
        $result = $this->api()->balance();
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['data']);
        $this->assertStringNotContainsString('test-password', json_encode($result));
        $this->assertStringNotContainsString('private-token', json_encode($result));
    }
}
