<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\ArvanConsoleBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ArvanConsoleBrokerTest extends TestCase
{
    use RefreshDatabase;

    private Service $fixture;
    private ArvanConsoleBroker $broker;
    private string $secret;
    private string $url = 'https://console.arvaniaas.ir/ir-tbz-sh1/vnc_lite.html?token=00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-14 09:00:00 UTC'));
        Customer::forceCreate(['id' => 1, 'email' => 'owner@example.test', 'phone' => '09120000001', 'password' => 'test-only', 'status' => 'active']);
        $this->fixture = Service::forceCreate(['id' => 456, 'customer_id' => 1, 'name' => 'test-only', 'status' => 'active', 'price' => 0, 'cycle' => 'monthly']);
        CloudInstance::forceCreate(['id' => 293, 'service_id' => 456, 'provider' => 'arvan', 'provider_ref' => 'ir-tbz-sh1:00000000-0000-0000-0000-000000000001',
            'status' => 'running', 'meta' => ['acceptance_test' => true]]);
        $this->secret = str_repeat('a', 64);
        Setting::putSecret('arvan_console_worker_key', $this->secret);
        Setting::put('arvan_console_worker_enabled', '1');
        Setting::put('arvan_console_worker_socket_path', '/ir-tbz-sh1/websockify');
        $this->broker = new ArvanConsoleBroker;
    }

    private function callBroker(string $action, string $token, ?string $key = null): \Illuminate\Http\JsonResponse
    {
        $body = json_encode(compact('action', 'token'));
        $ts = (string) now()->timestamp;
        $request = Request::create('/system/arvan-console-broker', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SN_TIME' => $ts,
            'HTTP_X_SN_SIGNATURE' => hash_hmac('sha256', $ts."\n".$body, $key ?? $this->secret),
        ], $body);

        return $this->broker->handle($request);
    }

    private function ticket(): string
    {
        return parse_url($this->broker->issue($this->fixture, $this->url), PHP_URL_FRAGMENT);
    }

    public function test_redeem_and_claim_are_single_use_and_only_claim_returns_upstream(): void
    {
        $ticket = $this->ticket();
        $r = $this->callBroker('redeem', $ticket);
        $this->assertSame(200, $r->status());
        $this->assertStringNotContainsString('arvaniaas', $r->getContent());
        $session = $r->getData(true)['session'];
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
        $claim = $this->callBroker('claim', $session);
        $this->assertSame(200, $claim->status());
        $this->assertSame('ir-tbz-sh1', $claim->getData(true)['region']);
        $this->assertSame(410, $this->callBroker('claim', $session)->status());
        $this->assertStringContainsString('no-store', $claim->headers->get('Cache-Control'));
    }

    public function test_wrong_signature_does_not_consume_valid_ticket(): void
    {
        $ticket = $this->ticket();
        $this->assertSame(403, $this->callBroker('redeem', $ticket, 'wrong')->status());
        $this->assertSame(200, $this->callBroker('redeem', $ticket)->status());
    }

    public function test_expired_ticket_fails(): void
    {
        $ticket = $this->ticket();
        $this->travel(91)->seconds();
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
    }

    public function test_reassignment_or_suspension_after_issue_revokes_ticket(): void
    {
        $ticket = $this->ticket();
        $this->fixture->update(['status' => 'suspended']);
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
        $this->fixture->update(['status' => 'active']);
        CloudInstance::whereKey(293)->update(['provider_ref' => 'ir-tbz-sh1:other']);
        $this->assertFalse($this->broker->eligible($this->fixture));
    }

    public function test_lock_contention_does_not_allow_a_second_claim(): void
    {
        $ticket = $this->ticket();
        $key = 'arvan-console-worker:ticket:'.hash('sha256', $ticket).':lock';
        $lock = Cache::lock($key, 10);
        $this->assertTrue($lock->get());
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
        $lock->release();
        $this->assertSame(200, $this->callBroker('redeem', $ticket)->status());
    }

    public function test_disabled_setting_fails_closed_but_fixture_deadline_no_longer_applies(): void
    {
        $ticket = $this->ticket();
        Setting::put('arvan_console_worker_enabled', '0');
        $this->assertSame(403, $this->callBroker('redeem', $ticket)->status());
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-16 05:38:00 UTC'));
        $this->assertTrue($this->broker->eligible($this->fixture));
    }

    public function test_arbitrary_upstream_is_rejected_before_ticket_is_issued(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->broker->issue($this->fixture, 'https://evil.invalid/?token=stolen');
    }

    public function test_new_customer_service_works_without_test_metadata(): void
    {
        Customer::forceCreate(['id' => 2, 'email' => 'second@example.test', 'phone' => '09120000002', 'password' => 'test-only', 'status' => 'active']);
        $service = Service::forceCreate(['id' => 501, 'customer_id' => 2, 'name' => 'customer', 'status' => 'active', 'price' => 100, 'cycle' => 'monthly']);
        CloudInstance::forceCreate(['id' => 310, 'service_id' => 501, 'provider' => 'arvan', 'provider_ref' => 'ir-thr-c2:00000000-0000-0000-0000-000000000002', 'status' => 'running', 'meta' => []]);
        $ticket = parse_url($this->broker->issue($service, str_replace('ir-tbz-sh1', 'ir-thr-c2', $this->url)), PHP_URL_FRAGMENT);
        $session = $this->callBroker('redeem', $ticket)->getData(true)['session'];
        $claim = $this->callBroker('claim', $session);
        $this->assertSame(200, $claim->status());
        $this->assertSame('/ir-thr-c2/websockify', $claim->getData(true)['path']);
    }

    public function test_owner_change_between_redeem_and_claim_revokes_access(): void
    {
        $session = $this->callBroker('redeem', $this->ticket())->getData(true)['session'];
        Customer::forceCreate(['id' => 2, 'email' => 'second@example.test', 'phone' => '09120000002', 'password' => 'test-only', 'status' => 'active']);
        $this->fixture->update(['customer_id' => 2]);
        $this->assertSame(410, $this->callBroker('claim', $session)->status());
    }

    public function test_instance_replacement_revokes_existing_grant(): void
    {
        $ticket = $this->ticket();
        CloudInstance::whereKey(293)->update(['provider_ref' => 'ir-tbz-sh1:00000000-0000-0000-0000-000000000002']);
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
    }

    public function test_provider_region_must_match_the_owned_instance(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->broker->issue($this->fixture, str_replace('ir-tbz-sh1', 'ir-thr-c2', $this->url));
    }

    public function test_other_customer_cannot_request_console_ticket(): void
    {
        $other = Customer::forceCreate(['id' => 2, 'email' => 'second@example.test', 'phone' => '09120000002', 'password' => 'test-only', 'status' => 'active']);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->actingAs($other, 'customer')->post(route('account.cloud.console', $this->fixture))->assertNotFound();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_owner_route_uses_real_driver_contract_and_private_relay_ticket(): void
    {
        Setting::putSecret('arvan_api_token', 'fake-not-live');
        \Illuminate\Support\Facades\Http::fake(['napi.arvancloud.ir/*' => \Illuminate\Support\Facades\Http::response(['data' => ['url' => $this->url]])]);
        $response = $this->actingAs($this->fixture->customer, 'customer')->post(route('account.cloud.console', $this->fixture));
        $response->assertRedirect();
        $this->assertStringStartsWith('https://vnc.servernet.cloud/#', $response->headers->get('Location'));
        $this->assertStringNotContainsString('arvaniaas', $response->headers->get('Location'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        \Illuminate\Support\Facades\Http::assertSentCount(1);
    }

    public function test_disabled_broker_never_requests_provider_console(): void
    {
        Setting::putSecret('arvan_api_token', 'fake-not-live');
        Setting::put('arvan_console_worker_enabled', '0');
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->actingAs($this->fixture->customer, 'customer')->post(route('account.cloud.console', $this->fixture))->assertRedirect();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_deleted_instance_cannot_redeem_an_existing_ticket(): void
    {
        $ticket = $this->ticket();
        CloudInstance::whereKey(293)->update(['status' => 'deleted']);
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
    }
}
