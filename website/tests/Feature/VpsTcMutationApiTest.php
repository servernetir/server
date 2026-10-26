<?php

namespace Tests\Feature;

use App\Services\Cloud\VpsTcApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VpsTcMutationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function api(): VpsTcApi
    {
        return new VpsTcApi('https://portal.vps.tc', 'fixture-account', 'fixture-secret');
    }

    private function login(): void
    {
        Http::fake(['portal.vps.tc/api/login' => Http::response(['token' => 'fixture-token', 'refresh' => 'fixture-refresh'])]);
    }

    private function order(): array
    {
        return ['domain' => 'sn-svc-592.example.test', 'cycle' => 'm', 'pay_method' => '120', 'custom' => ['44' => ['123' => 1]]];
    }

    public function test_monthly_order_preserves_dynamic_form_ids_and_parent_receipt(): void
    {
        $this->login();
        Http::fake(['portal.vps.tc/api/order/12' => Http::response(['items' => [['type' => 'Hosting', 'id' => '42']], 'invoice_id' => '75', 'order_num' => 'X123'])]);
        $result = $this->api()->order('12', $this->order());
        $this->assertTrue($result['ok']);
        $this->assertSame('42', $result['data']['items'][0]['id']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://portal.vps.tc/api/order/12'
            && $request->method() === 'POST' && $request['product_id'] === 12
            && $request['cycle'] === 'm' && $request['custom'] === ['44' => ['123' => 1]]
            && $request->hasHeader('Authorization', 'Bearer fixture-token'));
        Http::assertSentCount(2);
    }

    public function test_hourly_unsupported_cycles_and_extra_payloads_never_contact_provider(): void
    {
        $api = $this->api();
        foreach (['h', 'hour', 'q', 'a', ''] as $cycle) {
            $this->assertSame('invalid_order', $api->order('12', array_replace($this->order(), ['cycle' => $cycle]))['code']);
        }
        $this->assertSame('invalid_order', $api->order('12', $this->order() + ['password' => 'should-not-be-sent'])['code']);
        $this->assertSame('invalid_order', $api->order('12', $this->order() + ['product_id' => 13])['code']);
        $this->assertSame('invalid_order', $api->order('12', array_replace($this->order(), ['pay_method' => '../credit']))['code']);
        Http::assertNothingSent();
    }

    public function test_lost_order_response_is_unknown_and_is_never_replayed(): void
    {
        $this->login();
        $submitted = 0;
        Http::fake(['portal.vps.tc/api/order/12' => function () use (&$submitted) {
            $submitted++;
            throw new ConnectionException('fixture-secret fixture-token');
        }]);
        $result = $this->api()->order('12', $this->order());
        $this->assertSame(1, $submitted);
        $this->assertSame('outcome_unknown', $result['code']);
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['data']);
        $this->assertStringNotContainsString('fixture-secret', json_encode($result));
        $this->assertStringNotContainsString('fixture-token', json_encode($result));
    }

    public function test_mutation_401_does_not_refresh_or_replay(): void
    {
        $this->login();
        Http::fake(['portal.vps.tc/api/order/12' => Http::response(['error' => 'fixture-secret'], 401)]);
        $this->assertSame('outcome_unknown', $this->api()->order('12', $this->order())['code']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/token'));
    }

    public function test_redirect_rate_limit_server_error_and_incomplete_receipts_are_unknown(): void
    {
        $this->login();
        Http::fake(['portal.vps.tc/api/order/12' => Http::sequence()
            ->push('', 302, ['Location' => 'https://untrusted.test'])
            ->push(['error' => 'private'], 429)
            ->push(['error' => 'private'], 503)
            ->push('<html>private</html>', 200)
            ->push(['status' => true], 200)
            ->push(['items' => 'invalid'], 200)
            ->push(['items' => [['id' => 4]], 'error' => ['private']], 200)]);
        $api = $this->api();
        for ($i = 0; $i < 7; $i++) {
            $result = $api->order('12', $this->order());
            $this->assertSame('outcome_unknown', $result['code']);
            $this->assertStringNotContainsString('private', json_encode($result));
        }
        Http::assertSentCount(8);
    }

    public function test_explicit_rejection_never_exposes_upstream_error_body(): void
    {
        $this->login();
        Http::fake(['portal.vps.tc/api/order/12' => Http::response(['error' => ['fixture-secret', 'fixture-token']])]);
        $result = $this->api()->order('12', $this->order());
        $this->assertSame('mutation_rejected', $result['code']);
        $this->assertSame([], $result['data']);
        $this->assertStringNotContainsString('fixture-secret', json_encode($result));
        Http::assertSentCount(2);
    }

    public function test_renewal_receipt_identifies_invoice_and_credit_uses_exact_decimal(): void
    {
        $this->login();
        Http::fake([
            'portal.vps.tc/api/service/42/renew' => Http::response(['status' => true, 'invoice_id' => '75']),
            'portal.vps.tc/api/invoice/75' => Http::response(['invoice' => ['id' => '75', 'status' => 'Unpaid', 'total' => '2.79', 'currency' => 'USD']]),
            'portal.vps.tc/api/invoice/75/credit' => Http::response(['success' => true]),
        ]);
        $api = $this->api();
        $this->assertSame('75', $api->renewService('42')['data']['invoice_id']);
        $this->assertSame('Unpaid', $api->invoice('75')['data']['invoice']['status']);
        $this->assertTrue($api->applyInvoiceCredit('75', '2.79')['ok']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/service/42/renew') && $request['id'] === 42);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoice/75/credit') && $request['amount'] === '2.79');
        Http::assertSentCount(4);
    }

    public function test_credit_cannot_use_zero_negative_exponential_or_non_decimal_amounts(): void
    {
        foreach (['0', '0.00', '-1', '1e2', '1,234', 'INF', 'NaN', '1.2.3'] as $amount) {
            $this->assertSame('invalid_amount', $this->api()->applyInvoiceCredit('75', $amount)['code']);
        }
        Http::assertNothingSent();
    }

    public function test_cancel_targets_billed_parent_and_does_not_delete_vm(): void
    {
        $this->login();
        Http::fake(['portal.vps.tc/api/service/42/cancel' => Http::response(['status' => true])]);
        $this->assertTrue($this->api()->cancelService('42', true, 'Customer cancellation')['ok']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/service/42/cancel')
            && $request->method() === 'POST' && $request['immediate'] === 'true' && $request['id'] === 42);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
        Http::assertSentCount(2);
    }

    public function test_current_vcenter_vm_reference_supports_status_power_and_rebuild(): void
    {
        $this->login();
        Http::fake([
            'portal.vps.tc/api/service/42/vms/vm-1056' => Http::response(['vm' => ['status' => 'running']]),
            'portal.vps.tc/api/service/42/vms/vm-1056/start' => Http::response(['status' => true]),
            'portal.vps.tc/api/service/42/vms/vm-1056/rebuild' => Http::response(['success' => true]),
        ]);
        $api = $this->api();
        $this->assertSame('running', $api->vm('42', 'vm-1056')['data']['vm']['status']);
        $this->assertTrue($api->power('42', 'vm-1056', 'start')['ok']);
        $this->assertTrue($api->rebuild('42', 'vm-1056', 'ubuntu-24.04')['ok']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/start') && $request['vmid'] === 'vm-1056');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/rebuild') && $request['template'] === 'ubuntu-24.04');
        Http::assertSentCount(4);
    }

    public function test_vm_path_injection_and_unsupported_actions_cannot_reach_provider(): void
    {
        foreach (['../cancel', 'vm-0', 'vm-1/start', '1?token=x', '0', 'vm-1%2fstart'] as $id) {
            $this->assertSame('invalid_id', $this->api()->vm('42', $id)['code']);
            $this->assertSame('invalid_id', $this->api()->power('42', $id, 'start')['code']);
        }
        $this->assertSame('invalid_action', $this->api()->power('42', 'vm-1056', '../cancel')['code']);
        $this->assertSame('invalid_template', $this->api()->rebuild('42', 'vm-1056', '../private')['code']);
        $this->assertSame('unsupported_console', $this->api()->console('42', 'vm-1056')['code']);
        Http::assertNothingSent();
    }

    public function test_payment_account_methods_and_fees_are_read_without_assuming_method_120(): void
    {
        $this->login();
        Http::fake([
            'portal.vps.tc/api/payment' => Http::response(['payments' => ['7' => 'Account credit']]),
            'portal.vps.tc/api/payment/fees' => Http::response(['payments' => [['id' => 7, 'fixed_fee' => '0', 'percent_fee' => '0']]]),
        ]);
        $api = $this->api();
        $this->assertSame(['7' => 'Account credit'], $api->paymentMethods()['data']['payments']);
        $this->assertSame('0', $api->paymentFees()['data']['payments'][0]['fixed_fee']);
        Http::assertSentCount(3);
    }

    public function test_receipt_fingerprint_changes_with_account_or_credentials_without_exposing_them(): void
    {
        $one = $this->api()->accountFingerprint();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $one);
        $this->assertSame($one, $this->api()->accountFingerprint());
        $this->assertNotSame($one, (new VpsTcApi('https://portal.vps.tc', 'other-account', 'fixture-secret'))->accountFingerprint());
        $this->assertNotSame($one, (new VpsTcApi('https://portal.vps.tc', 'fixture-account', 'other-secret'))->accountFingerprint());
        $this->assertNotSame($one, (new VpsTcApi('https://api.vps.tc', 'fixture-account', 'fixture-secret'))->accountFingerprint());
        Http::assertNothingSent();
    }
}
