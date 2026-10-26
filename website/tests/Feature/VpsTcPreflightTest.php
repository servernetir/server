<?php

namespace Tests\Feature;

use App\Services\Cloud\CloudManager;
use App\Services\Cloud\VpsTcPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VpsTcPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.vpstc' => [
            'base_url' => 'https://api.vps.tc',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
        ]]);
    }

    private function fixtures(array $extra = [], mixed $currency = 'USD'): void
    {
        Http::fake($extra + [
            'api.vps.tc/api/login' => Http::response(['token' => 'fixture-token', 'refresh' => 'fixture-refresh']),
            'api.vps.tc/api/balance' => Http::response(['details' => [
                'currency' => $currency, 'acc_credit' => 10, 'acc_balance' => 0,
                'password' => 'upstream-credential-do-not-display',
            ]]),
            'api.vps.tc/api/category' => Http::response(['categories' => [['id' => 1, 'name' => '<script>upstream</script>']]]),
        ]);
    }

    public function test_unconfigured_preflight_makes_no_network_request(): void
    {
        config(['services.vpstc' => ['base_url' => '', 'username' => '', 'password' => '']]);
        $report = app(VpsTcPreflight::class)->inspect();
        $this->assertFalse($report['ok']);
        $this->assertFalse($report['sales_ready']);
        $this->assertContains('credentials_not_configured', $report['blockers']);
        Http::assertNothingSent();
    }

    public function test_successful_account_read_never_enables_sales_or_exposes_secrets(): void
    {
        $this->fixtures();
        $report = app(VpsTcPreflight::class)->inspect();
        $this->assertTrue($report['ok']);
        $this->assertTrue($report['connection_ok']);
        $this->assertFalse($report['sales_ready']);
        $this->assertFalse($report['catalog_complete']);
        $this->assertSame('USD', $report['currency']);
        $this->assertSame(1, $report['categories_seen']);
        $this->assertNull($report['products_seen']);
        $this->assertContains('traffic_cost_control_not_verified', $report['blockers']);
        $json = json_encode($report);
        foreach (['fixture-user', 'fixture-password', 'fixture-token', 'fixture-refresh', 'upstream-credential', '<script>', 'acc_credit'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertArrayHasKey('vpstc', CloudManager::DRIVERS);
        $this->assertFalse(app(\App\Services\Cloud\VpsTcSales::class)->enabled());
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://api.vps.tc/api/category');
    }

    public function test_unknown_currency_is_not_inferred_from_symbols_or_formatted_prices(): void
    {
        $this->fixtures(currency: '$');
        $report = app(VpsTcPreflight::class)->inspect();
        $this->assertTrue($report['connection_ok']);
        $this->assertNull($report['currency']);
        $this->assertContains('account_currency_not_verified', $report['blockers']);
        $this->assertFalse($report['sales_ready']);
    }

    public function test_optional_form_and_monthly_price_sample_are_read_only(): void
    {
        $this->fixtures([
            'api.vps.tc/api/category/1/product' => Http::response(['products' => [
                ['id' => 2, 'periods' => [['value' => 'm', 'price' => '2.79']]],
                ['id' => 3, 'periods' => [['value' => 'a', 'price' => 10]]],
                ['id' => 4, 'periods' => [['value' => 'm', 'price' => 'unknown']]],
                ['id' => 5, 'periods' => [['value' => 'm', 'price' => 0]]],
            ]]),
            'api.vps.tc/api/order/2' => Http::response(['product' => ['config' => ['forms' => []]]]),
        ]);
        $report = app(VpsTcPreflight::class)->inspect('2', '1');
        $this->assertTrue($report['ok']);
        $this->assertTrue($report['order_form_checked']);
        $this->assertSame(4, $report['products_seen']);
        $this->assertSame(1, $report['monthly_offers_seen']);
        $this->assertFalse($report['sales_ready']);
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->method() === 'GET' || $request->url() === 'https://api.vps.tc/api/login');
        }
        Http::assertSentCount(5);
    }

    public function test_invalid_resource_id_rejects_before_authentication(): void
    {
        foreach (['../cancel', '1?pay=true', '0', '-1', '1/2', '1e2'] as $id) {
            $report = app(VpsTcPreflight::class)->inspect($id);
            $this->assertFalse($report['ok']);
            $this->assertContains('invalid_resource_id', $report['blockers']);
        }
        Http::assertNothingSent();
    }

    public function test_upstream_error_content_never_reaches_preflight_output(): void
    {
        $this->fixtures(['api.vps.tc/api/balance' => Http::response(['error' => 'fixture-password fixture-token'], 500)]);
        $report = app(VpsTcPreflight::class)->inspect();
        $this->assertFalse($report['ok']);
        $this->assertContains('account_read_failed', $report['blockers']);
        $this->assertStringNotContainsString('fixture-password', json_encode($report));
        $this->assertStringNotContainsString('fixture-token', json_encode($report));
    }

    public function test_empty_account_details_do_not_count_as_a_verified_account_read(): void
    {
        $this->fixtures(['api.vps.tc/api/balance' => Http::response(['details' => []])]);
        $report = app(VpsTcPreflight::class)->inspect();
        $this->assertFalse($report['ok']);
        $this->assertFalse($report['connection_ok']);
        $this->assertContains('account_read_failed', $report['blockers']);
        Http::assertSentCount(2);
    }

    public function test_cli_reports_read_only_success_but_sales_remain_unavailable(): void
    {
        $this->fixtures();
        $this->artisan('cloud:vpstc-preflight')->expectsOutputToContain('"sales_ready": false')->assertSuccessful();
    }
}
