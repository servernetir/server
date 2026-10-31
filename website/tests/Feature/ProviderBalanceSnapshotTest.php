<?php

namespace Tests\Feature;

use App\Services\Bale\Management\ProviderBalanceSnapshot;
use App\Services\Cloud\OvhClient;
use App\Services\Cloud\VpsTcApi;
use App\Services\Domain\OpenProviderClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderBalanceSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.openprovider.base_url', 'https://api.openprovider.eu/v1beta');
        config()->set('services.openprovider.username', 'fixture-user');
        config()->set('services.openprovider.password', 'fixture-password');
    }

    private function reader(bool $configured = true, ?OvhClient $ovh = null): ProviderBalanceSnapshot
    {
        $ovh ??= \Mockery::mock(OvhClient::class);
        $ovh->shouldReceive('isConfigured')->andReturn(false)->byDefault();
        $ovh->shouldReceive('accountFingerprint')->andReturn('fixture-ovh-unconfigured')->byDefault();

        return new ProviderBalanceSnapshot(new OpenProviderClient(null,
            $configured ? 'fixture-user' : '', $configured ? 'fixture-password' : ''),
            new VpsTcApi('https://portal.vps.tc', $configured ? 'fixture-reseller' : '', $configured ? 'fixture-password' : ''), $ovh);
    }

    private function fake(mixed $credit = '45.60', mixed $currency = 'TRY', mixed $opAmount = '123.45', string $opCurrency = 'USD'): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'fixture-op-token']]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['balance' => $opAmount, 'currency' => $opCurrency]]),
            '*/api/login' => Http::response(['token' => 'fixture-vpstc-token']),
            '*/api/balance' => Http::response(['details' => ['acc_credit' => $credit, 'acc_balance' => '999.99', 'currency' => $currency]]),
        ]);
    }

    public function test_available_credit_and_currency_are_observed_without_any_financial_mutation(): void
    {
        $this->fake();
        $rows = $this->reader()->snapshot();
        $this->assertSame('123.45', $rows['openprovider']['value']);
        $this->assertSame('USD', $rows['openprovider']['currency']);
        $this->assertSame('45.6', $rows['vpstc']['value']);
        $this->assertSame('TRY', $rows['vpstc']['currency']);
        $this->assertSame('available_account_credit', $rows['vpstc']['value_kind']);
        $this->assertNotNull($rows['vpstc']['observed_at']);
        $this->assertFalse($rows['vpstc']['cached']);
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! str_ends_with($r->url(), '/login'));
        Http::assertSentCount(4);
    }

    public function test_unsupported_providers_are_explicitly_unknown_without_guessing_zero_or_network_routes(): void
    {
        $rows = $this->reader(false)->snapshot();
        foreach (['hetzner', 'aeza', 'arvan', 'salad', 'lightnode', 'hetzner-robot'] as $provider) {
            $this->assertSame('unknown', $rows[$provider]['status']);
            $this->assertNull($rows[$provider]['value']);
            $this->assertNull($rows[$provider]['currency']);
            $this->assertSame('balance_api_not_integrated', $rows[$provider]['reason']);
        }
        $this->assertSame('not_applicable', $rows['proxmox']['reason']);
        $this->assertSame('not_configured', $rows['vpstc']['reason']);
        $this->assertSame('not_configured', $rows['ovh']['reason']);
        Http::assertNothingSent();
    }

    public function test_zero_is_valid_only_when_it_is_actually_observed(): void
    {
        $this->fake('0.00', 'USD', 0, 'EUR');
        $rows = $this->reader()->snapshot();
        foreach (['vpstc', 'openprovider'] as $provider) {
            $this->assertSame('known', $rows[$provider]['status']);
            $this->assertSame('0', $rows[$provider]['value']);
        }
    }

    public function test_missing_currency_does_not_use_saved_account_label_or_invent_dollars(): void
    {
        $this->fake('10', null, '15', '');
        $rows = $this->reader()->snapshot();
        foreach (['vpstc', 'openprovider'] as $provider) {
            $this->assertSame('unknown', $rows[$provider]['status']);
            $this->assertNull($rows[$provider]['value']);
            $this->assertSame('currency_unverified', $rows[$provider]['reason']);
        }
        Http::assertSentCount(4);
    }

    public function test_unreadable_balance_does_not_fall_back_to_account_due_amount(): void
    {
        $this->fake('not-a-number');
        $rows = $this->reader()->snapshot();
        $this->assertSame('unknown', $rows['vpstc']['status']);
        $this->assertNull($rows['vpstc']['value']);
        $this->assertSame('amount_unverified', $rows['vpstc']['reason']);
        $this->assertStringNotContainsString('999.99', json_encode($rows));
    }

    public function test_cached_observation_keeps_its_original_time_and_does_not_refetch(): void
    {
        $this->freezeTime();
        $this->fake();
        $reader = $this->reader();
        $first = $reader->snapshot();
        $this->travel(30)->minutes();
        $second = $reader->snapshot();
        $this->assertTrue($second['vpstc']['cached']);
        $this->assertSame(1800, $second['vpstc']['age_seconds']);
        $this->assertSame($first['vpstc']['observed_at'], $second['vpstc']['observed_at']);
        $this->assertFalse($second['vpstc']['stale']);
        Http::assertSentCount(4);
    }

    public function test_expired_cache_fetches_a_new_observation(): void
    {
        $this->freezeTime();
        $this->fake();
        $reader = $this->reader();
        $first = $reader->snapshot();
        $this->travel(7)->hours();
        $second = $reader->snapshot();
        $this->assertFalse($second['vpstc']['cached']);
        $this->assertNotSame($first['vpstc']['observed_at'], $second['vpstc']['observed_at']);
        Http::assertSentCount(7); // VPS.TC client keeps its login; Openprovider token cache expired.
    }

    public function test_failure_after_expiry_does_not_present_old_balance_as_current_or_leak_error(): void
    {
        $this->freezeTime();
        $this->fake();
        $reader = $this->reader();
        $reader->snapshot();
        $this->travel(7)->hours();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['message' => 'private-credential-marker'], 403)]);
        $rows = $reader->snapshot();
        $this->assertSame('unknown', $rows['vpstc']['status']);
        $this->assertNull($rows['vpstc']['value']);
        $this->assertNull($rows['vpstc']['observed_at']);
        $this->assertStringNotContainsString('private-credential-marker', json_encode($rows));
        $this->assertStringNotContainsString('fixture-password', json_encode($rows));
    }

    public function test_ovh_prepaid_balance_is_cached_with_its_explicit_currency_and_account_identity(): void
    {
        $this->freezeTime();
        $ovh = \Mockery::mock(OvhClient::class);
        $ovh->shouldReceive('isConfigured')->andReturn(true);
        $ovh->shouldReceive('accountFingerprint')->andReturn('fixture-ovh-account');
        $ovh->shouldReceive('accountBalance')->once()->andReturn(['known' => true, 'amount' => 21.5, 'currency' => 'EUR']);
        $reader = $this->reader(false, $ovh);
        $first = $reader->snapshot()['ovh'];
        $this->travel(10)->minutes();
        $cached = $reader->snapshot()['ovh'];
        $this->assertSame('21.5', $first['value']);
        $this->assertSame('EUR', $first['currency']);
        $this->assertSame('reported_prepaid_account_balance', $first['value_kind']);
        $this->assertTrue($cached['cached']);
        $this->assertSame($first['observed_at'], $cached['observed_at']);
        $this->assertSame(600, $cached['age_seconds']);
        Http::assertNothingSent();
    }

    public function test_ovh_account_rotation_cannot_reuse_another_accounts_cached_credit(): void
    {
        foreach (['first-account' => '25.00', 'second-account' => '7.00'] as $identity => $amount) {
            $ovh = \Mockery::mock(OvhClient::class);
            $ovh->shouldReceive('isConfigured')->andReturn(true);
            $ovh->shouldReceive('accountFingerprint')->andReturn($identity);
            $ovh->shouldReceive('accountBalance')->once()->andReturn(['known' => true, 'amount' => $amount, 'currency' => 'EUR']);
            $row = $this->reader(false, $ovh)->snapshot()['ovh'];
            $this->assertSame(rtrim(rtrim($amount, '0'), '.'), $row['value']);
            $this->assertFalse($row['cached']);
        }
    }

    public function test_cached_preview_never_refreshes_missing_or_expired_observations(): void
    {
        $this->freezeTime();
        $reader = $this->reader();
        $empty = $reader->cachedSnapshot();
        $this->assertSame('no_observation', $empty['vpstc']['reason']);
        Http::assertNothingSent();
        $this->fake();
        $reader->snapshot();
        $this->travel(7)->hours();
        $expired = $reader->cachedSnapshot();
        $this->assertSame('unknown', $expired['vpstc']['status']);
        $this->assertSame('observation_expired', $expired['vpstc']['reason']);
        $this->assertNull($expired['vpstc']['value']);
        $this->assertNull($expired['vpstc']['observed_at']);
        Http::assertSentCount(4);
    }
}
