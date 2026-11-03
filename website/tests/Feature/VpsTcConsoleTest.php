<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudProviderOperation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcConsole;
use App\Services\Cloud\VpsTcConsoleBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VpsTcConsoleTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;
    private CloudInstance $instance;
    private VpsTcConsoleBroker $broker;
    private string $secret;
    private array $resources;
    private string $pveTicket = 'PVE:fixture@pve:ABC::fixture-cookie';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.vpstc' => ['base_url' => 'https://portal.vps.tc', 'username' => 'fixture', 'password' => 'fixture-parent']]);
        Customer::forceCreate(['id' => 1, 'code' => 'SN-457136', 'email' => 'owner@example.test',
            'phone' => '09120000001', 'password' => 'fixture', 'status' => 'active']);
        $this->service = Service::forceCreate(['id' => 7, 'customer_id' => 1, 'name' => 'Owner fixture',
            'status' => 'active', 'cycle' => 'monthly', 'price' => 1]);
        $this->instance = CloudInstance::forceCreate(['service_id' => 7, 'provider' => 'vpstc', 'provider_ref' => '83',
            'status' => 'running', 'ipv4' => '192.0.2.10']);
        CloudProviderOperation::create(['operation_key' => hash('sha256', 'fixture'), 'provider' => 'vpstc',
            'action' => 'attach', 'state' => 'complete', 'resource_ref' => '83', 'payload' => [
                '_account' => app(VpsTcApi::class)->accountFingerprint(), '_spec' => ['name' => 'sn-svc-7'],
                '_native' => ['service_id' => '83', 'vm_id' => '123', 'local_service_id' => 7,
                    'customer_id' => 1, 'ipv4' => '192.0.2.10']]]);
        $this->secret = str_repeat('a', 64);
        Setting::putSecret('vpstc_console_worker_key', $this->secret);
        Setting::put('vpstc_console_owner_pilot_enabled', '1');
        Setting::put('vpstc_console_owner_service_id', '7');
        Setting::put('vpstc_console_pve_origin', 'https://console.whitelabelservices.us');
        $this->resources = [['vmid' => 123, 'node' => 'fixture-node', 'type' => 'qemu']];
        $this->fake();
        $this->broker = app(VpsTcConsoleBroker::class);
    }

    private function fake(array $extra = []): void
    {
        // Factory::fake appends callbacks. Replace the factory so an earlier
        // happy-path callback cannot mask this test's deliberate failure.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake($extra + [
            'https://portal.vps.tc/api/login' => Http::response(['token' => 'fixture-api-token']),
            'https://portal.vps.tc/api/service/83' => Http::response(['service' => ['id' => 83, 'status' => 'Active',
                'username' => 'fixture', 'password' => 'fixture-service-password']]),
            'https://portal.vps.tc/api/service/83/vms' => Http::response(['vms' => [['vmid' => 123]]]),
            '*/api2/json/access/ticket' => Http::response(['data' => ['ticket' => $this->pveTicket,
                'CSRFPreventionToken' => 'ABC:fixture-csrf', 'username' => 'fixture@pve']]),
            '*/api2/json/cluster/resources?type=vm' => fn () => Http::response(['data' => $this->resources]),
            '*/api2/json/nodes/fixture-node/qemu/123/vncproxy' => Http::response(['data' => [
                'port' => 5901, 'ticket' => 'PVEVNC:fixture-console-ticket']]),
        ]);
    }

    private function ticket(string $locale = 'fa'): string
    {
        return parse_url($this->broker->issue($this->service, $locale), PHP_URL_FRAGMENT);
    }

    private function callBroker(string $action, string $token, ?string $secret = null): \Illuminate\Http\JsonResponse
    {
        $body = json_encode(compact('action', 'token')); $time = (string) now()->timestamp;
        return $this->broker->handle(Request::create('/system/vpstc-console-broker', 'POST', [], [], [], [
            'HTTP_X_SN_TIME' => $time, 'HTTP_X_SN_SIGNATURE' => hash_hmac('sha256', $time."\n".$body, $secret ?? $this->secret),
        ], $body));
    }

    public function test_launch_keeps_login_credentials_private_and_each_grant_is_consumed_once(): void
    {
        $target = $this->broker->issue($this->service, 'tr');
        $this->assertStringStartsWith('https://vps-vnc.servernet.cloud/tr/#', $target);
        Http::assertNothingSent();
        $ticket = parse_url($target, PHP_URL_FRAGMENT);
        $redeem = $this->callBroker('redeem', $ticket);
        $this->assertSame(200, $redeem->status());
        $data = $redeem->getData(true);
        $this->assertSame(['session', 'password'], array_keys($data));
        $this->assertSame('PVEVNC:fixture-console-ticket', $data['password']);
        foreach (['fixture-service-password', 'fixture-parent', $this->pveTicket, 'fixture-csrf'] as $private) {
            $this->assertStringNotContainsString($private, $target.$redeem->getContent());
        }
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
        $claim = $this->callBroker('claim', $data['session']);
        $this->assertSame(200, $claim->status());
        $this->assertSame($this->pveTicket, $claim->getData(true)['cookie']);
        $this->assertSame(410, $this->callBroker('claim', $data['session'])->status());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/vncproxy') && $r['websocket'] === 1
            && $r->hasHeader('Cookie', 'PVEAuthCookie='.$this->pveTicket));
        Http::assertSentCount(6);
    }

    public function test_bad_hmac_does_not_consume_a_valid_ticket(): void
    {
        $ticket = $this->ticket();
        $this->assertSame(403, $this->callBroker('redeem', $ticket, str_repeat('b', 64))->status());
        Http::assertNothingSent();
        $this->assertSame(200, $this->callBroker('redeem', $ticket)->status());
    }

    public function test_current_proxmox_password_is_separate_from_the_websocket_ticket(): void
    {
        $password = '!"#:$%&=';
        $vnc = 'PVEVNC:fixture-modern-ticket';
        $this->fake(['*/vncproxy' => Http::response(['data' => [
            'port' => '5901', 'ticket' => $password.':'.$vnc, 'password' => $password]])]);
        $redeem = $this->callBroker('redeem', $this->ticket());
        $this->assertSame(200, $redeem->status());
        $this->assertSame($password, $redeem->getData(true)['password']);
        $this->assertStringNotContainsString($vnc, $redeem->getContent());
        $upstream = $this->callBroker('claim', $redeem->getData(true)['session'])->getData(true);
        $this->assertSame(5901, $upstream['port']);
        $this->assertSame($vnc, $upstream['vnc']);
        $this->assertArrayNotHasKey('password', $upstream);
    }

    public function test_malformed_modern_grants_cannot_be_redeemed(): void
    {
        foreach ([['port' => '5901evil'], ['port' => '05901'], ['port' => 5901.5],
            ['ticket' => 'wrong123:PVEVNC:fixture'], ['password' => "bad\npass"],
            ['password' => null]] as $delta) {
            $this->fake(['*/vncproxy' => Http::response(['data' => $delta + [
                'port' => '5901', 'ticket' => '12345678:PVEVNC:fixture', 'password' => '12345678']])]);
            $this->assertSame(502, $this->callBroker('redeem', $this->ticket())->status());
        }
    }

    public function test_account_exposing_another_vm_is_rejected_before_starting_vnc(): void
    {
        $this->resources[] = ['vmid' => 124, 'node' => 'other', 'type' => 'qemu'];
        $this->assertSame(502, $this->callBroker('redeem', $this->ticket())->status());
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/vncproxy'));
    }

    public function test_supplier_vm_mismatch_cannot_open_a_different_server(): void
    {
        $this->fake(['*/api/service/83/vms' => Http::response(['vms' => [['vmid' => 124]]])]);
        $this->assertSame(502, $this->callBroker('redeem', $this->ticket())->status());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api2/json/'));
    }

    public function test_provider_redirect_never_follows_or_leaks_credentials(): void
    {
        $this->fake(['*/api2/json/access/ticket' => Http::response('secret-response', 302, ['Location' => 'https://outside.example/'])]);
        $response = $this->callBroker('redeem', $this->ticket());
        $this->assertSame(502, $response->status());
        $this->assertSame(['ok' => false], $response->getData(true));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'outside.example'));
    }

    public function test_expired_ticket_and_session_cannot_reconnect(): void
    {
        $ticket = $this->ticket(); $this->travel(91)->seconds();
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
        Http::assertNothingSent();
        $ticket = $this->ticket(); $session = $this->callBroker('redeem', $ticket)->getData(true)['session'];
        $this->travel(31)->seconds();
        $this->assertSame(410, $this->callBroker('claim', $session)->status());
    }

    public function test_owner_or_instance_change_after_launch_revokes_access(): void
    {
        $ticket = $this->ticket();
        $this->instance->update(['provider_ref' => '84']);
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
        Http::assertNothingSent();
    }

    public function test_disabling_pilot_or_suspending_service_revokes_an_issued_session(): void
    {
        $session = $this->callBroker('redeem', $this->ticket())->getData(true)['session'];
        $this->service->update(['status' => 'suspended']);
        $this->assertSame(410, $this->callBroker('claim', $session)->status());
        $this->service->update(['status' => 'active']);
        $ticket = $this->ticket();
        Setting::put('vpstc_console_owner_pilot_enabled', '0');
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
    }

    public function test_parent_account_switch_fails_closed(): void
    {
        $ticket = $this->ticket();
        config(['services.vpstc.username' => 'different-account']);
        $this->assertSame(410, app(VpsTcConsoleBroker::class)->handle($this->requestFor('redeem', $ticket))->status());
        Http::assertNothingSent();
    }

    private function requestFor(string $action, string $token): Request
    {
        $body = json_encode(compact('action', 'token')); $ts = (string) now()->timestamp;
        return Request::create('/system/vpstc-console-broker', 'POST', [], [], [], [
            'HTTP_X_SN_TIME' => $ts, 'HTTP_X_SN_SIGNATURE' => hash_hmac('sha256', $ts."\n".$body, $this->secret),
        ], $body);
    }

    public function test_customer_route_preserves_locale_and_blocks_other_customers(): void
    {
        $response = $this->actingAs($this->service->customer, 'customer')->post(route('en.account.cloud.console', $this->service));
        $response->assertRedirectContains('https://vps-vnc.servernet.cloud/en/#');
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        Http::assertNothingSent();
        $other = Customer::forceCreate(['email' => 'other@example.test', 'phone' => '09120000002', 'password' => 'fixture', 'status' => 'active']);
        $this->actingAs($other, 'customer')->post(route('account.cloud.console', $this->service))->assertNotFound();
    }

    public function test_broker_http_route_is_not_accessible_with_a_customer_cookie_alone(): void
    {
        $this->actingAs($this->service->customer, 'customer')->postJson('/system/vpstc-console-broker', [
            'action' => 'redeem', 'token' => $this->ticket(),
        ])->assertForbidden()->assertExactJson(['ok' => false]);
        Http::assertNothingSent();
    }

    private function cspFor(string $path): string
    {
        $request = Request::create('https://console.servernet.cloud/'.$path);
        $route = new \Illuminate\Routing\Route(['GET'], $path, fn () => null);
        $route->bind($request);
        $route->setParameter('service', $this->service->fresh());
        $request->setRouteResolver(fn () => $route);
        return (new \App\Http\Middleware\SecurityHeaders)->handle($request, fn () => response('<p>Fixture</p>'))
            ->headers->get('Content-Security-Policy');
    }

    public function test_browser_form_policy_allows_the_relay_only_on_an_eligible_owned_service_page(): void
    {
        $this->actingAs($this->service->customer, 'customer');
        foreach (['account/cloud/7', 'en/account/cloud/7', 'tr/account/cloud/7'] as $path) {
            $this->assertStringContainsString(' https://vps-vnc.servernet.cloud', $this->cspFor($path));
        }
        foreach (['account', 'account/cloud/7/status', 'account/cloud/7/console', 'account/invoices'] as $path) {
            $this->assertStringNotContainsString('vps-vnc.servernet.cloud', $this->cspFor($path));
        }
        Setting::put('vpstc_console_owner_pilot_enabled', '0');
        $this->assertStringNotContainsString('vps-vnc.servernet.cloud', $this->cspFor('account/cloud/7'));
        Http::assertNothingSent();
    }

    public function test_browser_form_policy_does_not_allow_a_console_redirect_for_a_different_customer(): void
    {
        $other = Customer::forceCreate(['email' => 'csp@example.test', 'phone' => '09120000003', 'password' => 'fixture', 'status' => 'active']);
        $this->actingAs($other, 'customer');
        $this->assertStringNotContainsString('vps-vnc.servernet.cloud', $this->cspFor('account/cloud/7'));
        Http::assertNothingSent();
    }

    public function test_unapproved_origin_and_non_owner_service_never_enable_console(): void
    {
        Setting::put('vpstc_console_pve_origin', 'https://console.whitelabelservices.us.attacker.example');
        $this->assertFalse($this->broker->enabledFor($this->service));
        Setting::put('vpstc_console_pve_origin', 'https://console.whitelabelservices.us');
        $this->service->update(['customer_id' => 2]);
        $this->assertFalse($this->broker->enabledFor($this->service));
        Http::assertNothingSent();
    }

    public function test_duplicate_local_supplier_reference_revokes_pending_launch(): void
    {
        $ticket = $this->ticket();
        $other = Service::forceCreate(['customer_id' => 1, 'name' => 'Duplicate fixture', 'status' => 'active', 'price' => 1, 'cycle' => 'monthly']);
        CloudInstance::forceCreate(['service_id' => $other->id, 'provider' => 'vpstc', 'provider_ref' => '83', 'status' => 'running']);
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
        Http::assertNothingSent();
    }

    public function test_connection_error_is_sanitized_and_never_retried(): void
    {
        $this->fake(['*/api2/json/access/ticket' => fn () => throw new \Illuminate\Http\Client\ConnectionException('fixture-service-password')]);
        $ticket = $this->ticket(); $response = $this->callBroker('redeem', $ticket);
        $this->assertSame(502, $response->status());
        $this->assertSame(['ok' => false], $response->getData(true));
        $this->assertSame(410, $this->callBroker('redeem', $ticket)->status());
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/vncproxy'));
    }

    public function test_owner_probe_prints_no_credentials_and_does_not_enable_pilot(): void
    {
        Setting::put('vpstc_console_owner_pilot_enabled', '0');
        $this->artisan('cloud:vpstc-console-probe', ['service' => 7])
            ->expectsOutput('Console API handshake verified; browser framebuffer and input acceptance are still required.')
            ->assertSuccessful();
        $this->assertSame('0', Setting::get('vpstc_console_owner_pilot_enabled'));
    }
}
