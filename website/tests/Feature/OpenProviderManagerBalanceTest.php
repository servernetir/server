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

class OpenProviderManagerBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.openprovider.base_url', 'https://api.openprovider.eu/v1beta');
        // These config credentials intentionally differ from injected accounts.
        config()->set('services.openprovider.username', 'unrelated-config-account');
        config()->set('services.openprovider.password', 'unrelated-config-password');
    }

    private function client(string $account): OpenProviderClient
    {
        return new OpenProviderClient('https://api.openprovider.eu/v1beta', $account, 'password-'.$account);
    }

    private function snapshots(OpenProviderClient $client): ProviderBalanceSnapshot
    {
        $ovh = \Mockery::mock(OvhClient::class);
        $ovh->shouldReceive('isConfigured')->andReturn(false);
        $ovh->shouldReceive('accountFingerprint')->andReturn('fixture-ovh-unconfigured');

        return new ProviderBalanceSnapshot($client, new VpsTcApi('https://portal.vps.tc', '', ''), $ovh);
    }

    private function fakeAccounts(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/auth/login')) {
                return Http::response(['code' => 0, 'data' => ['token' => 'token-'.$request['username']]]);
            }
            $token = $request->header('Authorization')[0] ?? '';
            $amount = match ($token) {
                'Bearer token-first' => '12.50', 'Bearer token-second' => '87.25',
                default => '99999.99', // Would reveal a leaked legacy/global token.
            };

            return Http::response(['code' => 0, 'data' => ['balance' => $amount, 'currency' => 'EUR']]);
        });
    }

    public function test_legacy_global_token_cannot_supply_a_fresh_manager_balance_for_new_credentials(): void
    {
        Cache::put('openprovider.token', 'legacy-other-account-token', 21600);
        Cache::put('openprovider.auth_down', 196, 600);
        $this->fakeAccounts();
        $row = $this->snapshots($this->client('second'))->snapshot()['openprovider'];
        $this->assertSame('87.25', $row['value']);
        $this->assertSame('EUR', $row['currency']);
        $this->assertSame('legacy-other-account-token', Cache::get('openprovider.token'));
        $this->assertSame(196, Cache::get('openprovider.auth_down'));
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => ($request->header('Authorization')[0] ?? '') === 'Bearer legacy-other-account-token');
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' && ! str_ends_with($request->url(), '/auth/login'));
    }

    public function test_actual_injected_accounts_keep_tokens_and_observations_separate_even_with_identical_config(): void
    {
        $this->fakeAccounts();
        $firstClient = $this->client('first');
        $secondClient = $this->client('second');
        $this->assertNotSame($firstClient->accountFingerprint(), $secondClient->accountFingerprint());
        $first = $this->snapshots($firstClient)->snapshot()['openprovider'];
        $second = $this->snapshots($secondClient)->snapshot()['openprovider'];
        $firstAgain = $this->snapshots($firstClient)->cachedSnapshot()['openprovider'];
        $this->assertSame('12.5', $first['value']);
        $this->assertSame('87.25', $second['value']);
        $this->assertSame('12.5', $firstAgain['value']);
        $this->assertTrue($firstAgain['cached']);
        $this->assertFalse($second['cached']);
        $this->assertNull(Cache::get('openprovider.token'));
        Http::assertSentCount(4);
    }

    public function test_account_auth_failure_never_falls_back_to_legacy_token_or_repeats_login_until_backoff(): void
    {
        Cache::put('openprovider.token', 'legacy-good-account-token', 21600);
        Http::fake(['*/auth/login' => Http::response(['code' => 196, 'desc' => 'private-marker'], 500)]);
        $client = $this->client('second');
        $first = $client->managerBalance();
        $second = $client->managerBalance();
        $this->assertFalse($first['known']);
        $this->assertFalse($second['known']);
        $this->assertSame('legacy-good-account-token', Cache::get('openprovider.token'));
        $this->assertNull(Cache::get('openprovider.auth_down'));
        $this->assertStringNotContainsString('private-marker', json_encode([$first, $second]));
        Http::assertSentCount(1);
    }

    public function test_expired_scoped_token_gets_one_new_login_without_changing_purchase_auth(): void
    {
        $client = $this->client('first');
        Cache::put('openprovider.token', 'legacy-purchase-token', 21600);
        Cache::put('openprovider.manager-balance:'.$client->accountFingerprint().':token', 'expired-manager-token', 21600);
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/auth/login')) {
                return Http::response(['code' => 0, 'data' => ['token' => 'renewed-manager-token']]);
            }
            if (($request->header('Authorization')[0] ?? '') === 'Bearer expired-manager-token') {
                return Http::response(['code' => 196, 'desc' => 'expired'], 500);
            }

            return Http::response(['code' => 0, 'data' => ['balance' => '34.50', 'currency' => 'EUR']]);
        });
        $row = $client->managerBalance();
        $this->assertTrue($row['known']);
        $this->assertSame(34.5, $row['amount']);
        $this->assertSame('legacy-purchase-token', Cache::get('openprovider.token'));
        $this->assertNull(Cache::get('openprovider.auth_down'));
        Http::assertSentCount(3);
    }

    public function test_old_unbound_observation_is_not_reused_after_the_cache_contract_upgrade(): void
    {
        $client = $this->client('second');
        $old = 'bale:manager:provider-balance:v1:openprovider:'.$client->accountFingerprint();
        Cache::put($old, ['provider' => 'openprovider', 'status' => 'known', 'value' => '9999', 'currency' => 'USD',
            'observed_at' => now()->toIso8601String(), 'checked_at' => now()->toIso8601String()], 21600);
        $row = $this->snapshots($client)->cachedSnapshot()['openprovider'];
        $this->assertSame('unknown', $row['status']);
        $this->assertSame('no_observation', $row['reason']);
        $this->assertNull($row['value']);
        Http::assertNothingSent();
    }

    public function test_provider_business_code_remains_decisive_when_http_status_is_500(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'fixture-account-token']]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['balance' => '22.25', 'currency' => 'EUR']], 500),
        ]);
        $balance = $this->client('second')->managerBalance();
        $this->assertTrue($balance['known']);
        $this->assertSame(22.25, $balance['amount']);
        $this->assertSame('EUR', $balance['currency']);
        Http::assertSentCount(2);
    }

    public function test_documented_settings_currency_is_requested_and_bound_to_the_available_balance(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'fixture-account-token']]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['balance' => '31.25', 'reserved_balance' => 900,
                'settings' => ['currency' => 'EUR', 'payment_methods' => [['settings' => ['limit' => ['USD' => 1000]]]]]]]),
        ]);
        $client = $this->client('second');
        $row = $this->snapshots($client)->snapshot()['openprovider'];
        $this->assertSame('known', $row['status']);
        $this->assertSame('31.25', $row['value']);
        $this->assertSame('EUR', $row['currency']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/resellers?with_settings=true'));
        Http::assertSentCount(2);
        // The protected legacy health/financial reader retains its former parser.
        $legacy = $client->balance();
        $this->assertTrue($legacy['known']);
        $this->assertSame('', $legacy['currency']);
    }

    public function test_conflicting_explicit_account_currencies_are_not_chosen_or_converted(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'fixture-account-token']]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['balance' => '31.25',
                'currency' => 'USD', 'settings' => ['currency' => 'EUR']]]),
        ]);
        $row = $this->snapshots($this->client('second'))->snapshot()['openprovider'];
        $this->assertSame('unknown', $row['status']);
        $this->assertSame('currency_unverified', $row['reason']);
        $this->assertNull($row['value']);
        $this->assertNull($row['currency']);
    }

    public function test_account_currency_is_not_inferred_from_ids_symbols_locale_or_payment_limit_keys(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'fixture-account-token']]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['balance' => '31.25', 'locale' => 'en_US',
                'settings' => ['currency' => ['id' => 1, 'symbol' => '$'], 'payment_methods' => ['USD' => 1000]]]]),
        ]);
        $row = $this->snapshots($this->client('second'))->snapshot()['openprovider'];
        $this->assertSame('unknown', $row['status']);
        $this->assertSame('currency_unverified', $row['reason']);
        $this->assertNull($row['value']);
        $this->assertNull($row['currency']);
    }
}
