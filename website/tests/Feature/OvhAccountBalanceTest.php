<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\OvhClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OvhAccountBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::put('ovh_region', 'eu');
        Setting::putSecret('ovh_app_key', 'fixture-ak');
        Setting::putSecret('ovh_app_secret', 'fixture-as');
        Setting::putSecret('ovh_consumer_key', 'fixture-ck');
    }

    private function fake(array $ids = ['fixture-prepaid'], array $account = [], int $status = 200): void
    {
        Http::fake([
            '*/1.0/auth/time' => Http::response((string) time()),
            '*/1.0/me/ovhAccount' => Http::response($ids),
            '*/1.0/me/ovhAccount/fixture-prepaid' => Http::response($account, $status),
        ]);
    }

    public function test_only_the_active_prepaid_balance_is_read_and_requests_are_get_only_and_signed(): void
    {
        $this->fake(account: ['isActive' => true,
            'balance' => ['value' => 24.5, 'currencyCode' => 'EUR'],
            'availableAfterCredit' => ['value' => 999, 'currencyCode' => 'EUR']]);
        $result = app(OvhClient::class)->accountBalance();
        $this->assertSame(['known' => true, 'amount' => 24.5, 'currency' => 'EUR'], $result);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/me/ovhAccount/')) { return false; }
            $timestamp = $request->header('X-Ovh-Timestamp')[0] ?? '';
            $expected = '$1$'.sha1(implode('+', ['fixture-as', 'fixture-ck', 'GET', $request->url(), '', $timestamp]));

            return ($request->header('X-Ovh-Signature')[0] ?? null) === $expected;
        });
    }

    public function test_multiple_prepaid_accounts_are_not_guessed_or_added_together(): void
    {
        $this->fake(ids: ['first', 'second']);
        $this->assertSame(['known' => false, 'reason' => 'ambiguous_accounts'], app(OvhClient::class)->accountBalance());
        Http::assertSentCount(2);
    }

    public function test_inactive_account_does_not_publish_a_balance(): void
    {
        $this->fake(account: ['isActive' => false, 'balance' => ['value' => 500, 'currencyCode' => 'EUR']]);
        $this->assertSame(['known' => false, 'reason' => 'prepaid_account_inactive'], app(OvhClient::class)->accountBalance());
    }

    public function test_error_does_not_return_raw_provider_payload_or_a_zero(): void
    {
        $this->fake(account: ['message' => 'private-provider-marker'], status: 403);
        $result = app(OvhClient::class)->accountBalance();
        $this->assertSame(['known' => false, 'reason' => 'balance_unavailable'], $result);
        $this->assertArrayNotHasKey('amount', $result);
        $this->assertStringNotContainsString('private-provider-marker', json_encode($result));
    }
}
