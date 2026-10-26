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

    private function calculatedQuote(): array
    {
        return ['summary' => ['subtotal' => 2.79, 'total' => 0, 'credit' => 3.348,
            'tax' => [['name' => 'KDV', 'tax' => 0.5579999999999999, 'value' => 20]],
            'recurring' => [['title' => 'Monthly', 'price' => 3.348, 'value' => 'm']]],
            'items' => [['valid' => true, 'error' => [], 'product' => ['id' => 351, 'price' => 2.79]]]];
    }

    public function test_quote_only_calculates_one_exact_monthly_configuration_over_verified_transport(): void
    {
        $body = $this->calculatedQuote();
        Http::fake(function (Request $request, array $options) use ($body) {
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(20, $options['timeout']);

            return str_ends_with($request->url(), '/login')
                ? Http::response(['token' => 'access-one']) : Http::response($body);
        });
        $result = $this->api()->quote('351', '120', ['os' => ['template' => 'ubuntu']], 'sn-svc-123.example.invalid');
        $this->assertTrue($result['ok']);
        $this->assertSame($body, $result['data']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.vps.tc/api/quote'
            && $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer access-one')
            && $r->data() === ['pay_method' => 120, 'output' => 'full', 'items' => [[
                'type' => 'product', 'product_id' => 351, 'domain' => 'sn-svc-123.example.invalid',
                'cycle' => 'm', 'custom' => ['os' => ['template' => 'ubuntu']],
            ]]]);
        Http::assertNotSent(fn (Request $r) => ! in_array($r->url(), [
            'https://api.vps.tc/api/login', 'https://api.vps.tc/api/quote',
        ], true));
        Http::assertSentCount(2);
    }

    public function test_quote_defaults_to_a_reserved_hostname_and_never_uses_an_order_endpoint(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one']),
            'https://api.vps.tc/api/quote' => Http::response($this->calculatedQuote()),
        ]);
        $this->assertTrue($this->api()->quote('351', '120')['ok']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/quote')
            && $r['items'][0]['domain'] === 'preflight-351.example.invalid'
            && $r['items'][0]['custom'] === []);
        Http::assertSentCount(2);
    }

    public function test_quote_rejects_invalid_identifiers_hostnames_and_json_before_authentication(): void
    {
        $api = $this->api();
        foreach (['../order/1', '1?currency=USD', '0', '-1', '', '1/credit', '9999999999999999999'] as $id) {
            $this->assertSame('invalid_id', $api->quote($id, '120')['code']);
            $this->assertSame('invalid_id', $api->quote('351', $id)['code']);
        }
        foreach (['', 'https://example.invalid', 'bad/name', "secret\n.example.invalid", str_repeat('a', 254)] as $hostname) {
            $this->assertSame('invalid_quote', $api->quote('351', '120', [], $hostname)['code']);
        }
        foreach ([['x' => str_repeat('a', 200001)], ['x' => INF], ['x' => "\xFF"]] as $custom) {
            $this->assertSame('invalid_quote', $api->quote('351', '120', $custom)['code']);
        }
        $nested = [];
        for ($i = 0; $i < 40; $i++) { $nested = ['x' => $nested]; }
        $this->assertSame('invalid_quote', $api->quote('351', '120', $nested)['code']);
        Http::assertNothingSent();
    }

    public function test_quote_rejects_malformed_or_error_calculations_without_disclosing_them(): void
    {
        $good = $this->calculatedQuote();
        $bad = [
            ['summary' => [], 'items' => $good['items']],
            ['summary' => [1], 'items' => $good['items']],
            ['summary' => 'private-details', 'items' => $good['items']],
            ['summary' => $good['summary']],
            ['summary' => $good['summary'], 'items' => []],
            ['summary' => $good['summary'], 'items' => [[]]],
            ['summary' => $good['summary'], 'items' => ['product' => $good['items'][0]]],
            ['summary' => $good['summary'], 'items' => [$good['items'][0], $good['items'][0]]],
            ['summary' => $good['summary'], 'items' => ['private-details']],
            array_replace($good, ['status' => false]),
            array_replace($good, ['success' => false]),
            array_replace($good, ['error' => 'private-details']),
            array_replace($good, ['errors' => ['private-details']]),
        ];
        foreach ([['valid' => false], ['valid' => 'true'], ['error' => ['private-details']],
            ['errors' => 'private-details'], ['status' => false], ['success' => false]] as $error) {
            $bad[] = array_replace($good, ['items' => [array_replace($good['items'][0], $error)]]);
            $bad[] = array_replace($good, ['summary' => array_replace($good['summary'], $error)]);
        }
        $sequence = Http::sequence();
        foreach ($bad as $body) { $sequence->push($body); }
        $sequence->push('<html>private-details</html>');
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one']),
            'https://api.vps.tc/api/quote' => $sequence,
        ]);
        $api = $this->api();
        for ($i = 0; $i <= count($bad); $i++) {
            $result = $api->quote('351', '120');
            $this->assertSame('invalid_response', $result['code']);
            $this->assertSame([], $result['data']);
            $this->assertStringNotContainsString('private-details', json_encode($result));
        }
        Http::assertSentCount(count($bad) + 2);
    }

    public function test_quote_requires_matching_product_identity_and_rejects_conflicting_identifiers(): void
    {
        $good = $this->calculatedQuote();
        $items = [
            ['valid' => true, 'product' => ['id' => 352]],
            ['valid' => true, 'product' => ['id' => 'private-details']],
            ['valid' => true, 'product' => ['id' => '351.0']],
            ['valid' => true, 'product' => ['id' => 351], 'product_id' => '352'],
            ['valid' => true, 'product' => ['id' => 352], 'product_id' => '351'],
            ['valid' => true, 'product' => 'private-details', 'product_id' => '351'],
            ['valid' => true, 'product' => [], 'product_id' => '351'],
            ['valid' => true, 'product' => ['price' => 2.79]],
            ['valid' => true],
        ];
        $sequence = Http::sequence();
        foreach ($items as $item) { $sequence->push(array_replace($good, ['items' => [$item]])); }
        $sequence->push(array_replace($good, ['items' => [['valid' => true, 'product_id' => '351']]]));
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one']),
            'https://api.vps.tc/api/quote' => $sequence,
        ]);
        $api = $this->api();
        foreach ($items as $unused) {
            $result = $api->quote('351', '120');
            $this->assertSame('invalid_response', $result['code']);
            $this->assertSame([], $result['data']);
            $this->assertStringNotContainsString('private-details', json_encode($result));
        }
        $this->assertTrue($api->quote('351', '120')['ok']);
        Http::assertSentCount(count($items) + 2);
    }

    public function test_quote_can_refresh_once_and_repeat_only_the_same_calculation(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one', 'refresh' => 'refresh-one']),
            'https://api.vps.tc/api/token' => Http::response(['token' => 'access-two', 'refresh' => 'refresh-two']),
            'https://api.vps.tc/api/quote' => Http::sequence()->push([], 401)->push($this->calculatedQuote()),
        ]);
        $this->assertTrue($this->api()->quote('351', '120')['ok']);
        $requests = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/quote'))->pluck(0);
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]->data(), $requests[1]->data());
        $this->assertTrue($requests[0]->hasHeader('Authorization', 'Bearer access-one'));
        $this->assertTrue($requests[1]->hasHeader('Authorization', 'Bearer access-two'));
        Http::assertSentCount(4);
    }

    public function test_quote_refresh_is_bounded_and_transient_failures_do_not_retry(): void
    {
        Http::fake([
            'https://api.vps.tc/api/login' => Http::response(['token' => 'access-one', 'refresh' => 'refresh-one']),
            'https://api.vps.tc/api/token' => Http::response(['token' => 'access-two']),
            'https://api.vps.tc/api/quote' => Http::sequence()->push([], 401)->push([], 401)
                ->push(['error' => 'private-details'], 429)->push(['error' => 'private-details'], 500)
                ->push('', 302, ['Location' => 'https://evil.test/steal']),
        ]);
        $api = $this->api();
        foreach (['access_denied', 'rate_limited', 'provider_error', 'redirect_rejected'] as $code) {
            $result = $api->quote('351', '120');
            $this->assertSame($code, $result['code']);
            $this->assertSame([], $result['data']);
            $this->assertStringNotContainsString('private-details', json_encode($result));
        }
        Http::assertSentCount(7);
    }

    public function test_quote_connection_failure_is_sanitized_and_never_replayed(): void
    {
        $quoteCalls = 0;
        Http::fake(function (Request $request) use (&$quoteCalls) {
            if (str_ends_with($request->url(), '/login')) { return Http::response(['token' => 'access-one']); }
            $quoteCalls++;
            throw new ConnectionException('private-token=test-password');
        });
        $result = $this->api()->quote('351', '120');
        $this->assertSame('connection_failed', $result['code']);
        $this->assertSame([], $result['data']);
        $this->assertStringNotContainsString('test-password', json_encode($result));
        $this->assertStringNotContainsString('private-token', json_encode($result));
        $this->assertSame(1, $quoteCalls);
    }
}
