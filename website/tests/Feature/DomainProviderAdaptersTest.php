<?php

namespace Tests\Feature;

use App\Models\DomainSupplierAccount;
use App\Models\Domain;
use App\Services\Domain\DomainManagement;
use App\Services\Domain\DomainSupplierLifecycle;
use App\Services\Domain\OpenProviderClient;
use App\Services\Domain\Providers\DomainProviderException;
use App\Services\Domain\Providers\DomainProviderFactory;
use App\Services\Domain\Providers\JokerAdapter;
use App\Services\Domain\Providers\OpenProviderAdapter;
use App\Services\Domain\Providers\ProviderMoney;
use App\Services\Domain\Providers\LegacyAccountIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainProviderAdaptersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function account(string $provider = 'joker', string $environment = 'ote', string $key = 'fixture-api-key'): DomainSupplierAccount
    {
        return new DomainSupplierAccount(['provider' => $provider, 'environment' => $environment,
            'credentials' => ['api_key' => $key, 'username' => 'fixture-user', 'password' => 'fixture-password'], 'settings' => []]);
    }

    private function reply(string $body = '', string $headers = '')
    {
        return Http::response("Status-Code: 1000\nStatus-Text: OK\n".$headers."\n".$body, 200);
    }

    private function fakeJoker(array $extra): void
    {
        Http::fake(['*/login' => $this->reply('', "Auth-SID: fixture-session\n")] + $extra);
    }

    private function context(): array
    {
        return ['max_price_minor' => 821, 'currency' => 'USD', 'contact_handles' => array_fill_keys(['owner', 'billing', 'admin', 'tech'], 'CCOM-FIXTURE'),
            'name_servers' => ['ns1.example.test', 'ns2.example.test'], 'cltrid' => 'fixture-operation'];
    }

    public function test_joker_uses_exact_months_final_account_price_and_body_only_authentication(): void
    {
        $this->fakeJoker(['*/domain-check' => $this->reply("domain-status: available\ndomain-class: standard\ndomain-price-create: 8.21 USD 2y\n")]);
        $quote = (new JokerAdapter($this->account()))->quote('example.com', 'create', 24);
        $this->assertSame(821, $quote['amount_minor']);
        $this->assertSame(24, $quote['period_months']);
        $this->assertTrue($quote['tax_included']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/domain-check') && $r['period'] === 24 && $r['check-price'] === 'create');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'fixture-') || parse_url($r->url(), PHP_URL_QUERY) !== null);
    }

    public function test_joker_rejects_a_returned_period_different_from_requested_without_linearizing(): void
    {
        $this->fakeJoker(['*/domain-check' => $this->reply("domain-status: available\ndomain-class: standard\ndomain-price-create: 8.21 USD 1y\n")]);
        $this->expectExceptionMessage('supplier_period_mismatch');
        (new JokerAdapter($this->account()))->quote('example.com', 'create', 24);
    }

    public function test_joker_nonstandard_class_is_premium_even_if_availability_is_regular(): void
    {
        $this->fakeJoker(['*/domain-check' => $this->reply("domain-status: available\ndomain-class: tier-1\ndomain-price-create: 100.05 USD 1y\n")]);
        $quote = (new JokerAdapter($this->account()))->quote('example.com', 'create', 12);
        $this->assertTrue($quote['premium']);
        $this->assertSame(10005, $quote['amount_minor']);
    }

    public function test_pricing_errors_are_not_domain_unavailability_and_do_not_leak_the_key(): void
    {
        Http::fake(['*/login' => Http::response("Status-Code: 2000\nError: secret-marker\n\n")]);
        try {
            (new JokerAdapter($this->account()))->quote('example.com', 'create', 12);
            $this->fail('Expected an authentication error');
        } catch (DomainProviderException $e) {
            $this->assertSame('request_rejected', $e->reason);
            $this->assertStringNotContainsString('secret-marker', $e->getMessage());
            $this->assertStringNotContainsString('fixture-api-key', (string) $e);
        }
    }

    public function test_joker_initial_ack_is_queued_and_supplies_real_processing_references(): void
    {
        $this->fakeJoker(['*/domain-register' => $this->reply('', "Result: ACK\nProc-ID: 42\nTracking-Id: 99\n")]);
        $res = (new JokerAdapter($this->account()))->submit('create', 'example.com', 12, $this->context());
        $this->assertSame('queued', $res['status']);
        $this->assertSame('42', $res['process_id']);
        $this->assertSame('99', $res['tracking_id']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/domain-register') && $r['period'] === 12 && $r['max-price'] === '8.21' && $r['autorenew'] === 0);
    }

    public function test_joker_timeout_is_unknown_and_never_retries_the_financial_request(): void
    {
        $attempts = 0;
        $this->fakeJoker(['*/domain-register' => function () use (&$attempts) { $attempts++; throw new ConnectionException('secret-marker'); }]);
        $res = (new JokerAdapter($this->account()))->submit('create', 'example.com', 12, $this->context());
        $this->assertSame('unknown', $res['status']);
        $this->assertSame(1, $attempts);
        $this->assertStringNotContainsString('secret-marker', json_encode($res));
    }

    public function test_joker_renewal_uses_expyear_exclusively_for_the_exact_quoted_term(): void
    {
        $this->fakeJoker(['*/query-domain-list' => $this->reply("example.com\t2027-05-30\tlock\n"),
            '*/domain-renew' => $this->reply('', "Result: ACK\nProc-ID: 42\n")]);
        $context = ['object_id' => 'example.com', 'target_expiration_year' => 2029] + $this->context();
        $res = (new JokerAdapter($this->account()))->submit('renew', 'example.com', 24, $context);
        $this->assertSame('queued', $res['status']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/domain-renew') && $r['expyear'] === 2029 && ! isset($r['period']) && $r['privacy'] === 'none');
    }

    public function test_joker_retrieval_ack_cannot_complete_a_pending_original_request(): void
    {
        $this->fakeJoker(['*/result-list' => $this->reply("20261031120000\t99\t42\tdomain-register\t?\tfixture-operation\n"),
            '*/result-retrieve' => $this->reply('', "Result: ACK\n")]);
        $res = (new JokerAdapter($this->account()))->reconcile(['operation' => 'create', 'fqdn' => 'example.com', 'tracking_id' => '99', 'process_id' => '42']);
        $this->assertSame('queued', $res['status']);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/query-domain-list'));
    }

    public function test_joker_final_nack_is_a_definite_failure(): void
    {
        $this->fakeJoker(['*/result-list' => $this->reply("20261031120000\t99\t42\tdomain-register\tnack\tfixture-operation\n"),
            '*/result-retrieve' => $this->reply("Result: NACK\nError: private-detail\n", "Result: ACK\n")]);
        $res = (new JokerAdapter($this->account()))->reconcile(['operation' => 'create', 'fqdn' => 'example.com', 'tracking_id' => '99', 'process_id' => '42']);
        $this->assertSame('failed', $res['status']);
        $this->assertStringNotContainsString('private-detail', json_encode($res));
    }

    public function test_joker_final_ack_requires_the_domain_in_the_same_authenticated_account(): void
    {
        $this->fakeJoker(['*/result-list' => $this->reply("20261031120000\t99\t42\tdomain-register\tack\tfixture-operation\n"),
            '*/result-retrieve' => $this->reply('', "Result: ACK\n"),
            '*/query-domain-list' => $this->reply("other.com\t2027-05-30\tlock\n")]);
        $res = (new JokerAdapter($this->account()))->reconcile(['operation' => 'create', 'fqdn' => 'example.com', 'tracking_id' => '99', 'process_id' => '42']);
        $this->assertSame('unknown', $res['status']);
        $this->assertSame('domain_not_in_account', $res['error_code']);
    }

    public function test_joker_exact_terminal_result_and_portfolio_confirm_delivery(): void
    {
        $this->fakeJoker(['*/result-list' => $this->reply("20261031120000\t99\t42\tdomain-register\tack\tfixture-operation\n"),
            '*/result-retrieve' => $this->reply('', "Result: ACK\n"),
            '*/query-domain-list' => $this->reply("example.com\t2027-05-30\tlock\n"),
            '*/query-order-list' => $this->reply("123\t42\t2026-10-31T12:00:00Z\tok\tcreate\t8.20\t8.20\tUSD\texample.com\n",
                "Columns: order_id,proc_id,created_at,status,type,amount_brutto,amount_netto,currency,identifier\n")]);
        $res = (new JokerAdapter($this->account()))->reconcile(['operation' => 'create', 'fqdn' => 'example.com', 'tracking_id' => '99', 'process_id' => '42']);
        $this->assertSame('succeeded', $res['status']);
        $this->assertSame('example.com', $res['object_id']);
        $this->assertSame(820, $res['purchase_minor']);
        $this->assertSame('provider_reported', $res['cost_status']);
        $this->assertSame('123', $res['provider_order_id']);
    }

    public function test_joker_missing_provider_reference_never_uses_cltrid_as_idempotency(): void
    {
        $res = (new JokerAdapter($this->account()))->reconcile(['fqdn' => 'example.com', 'cltrid' => 'not-unique']);
        $this->assertSame('unknown', $res['status']);
        Http::assertNothingSent();
    }

    public function test_joker_probe_uses_spendable_balance_not_pending_or_total(): void
    {
        $this->fakeJoker(['*/query-account-balance' => $this->reply("9.75\t90.25\t100.00\tUSD\n", "Columns: available,pending,total,currency\nSeparator: TAB\n")]);
        $res = (new JokerAdapter($this->account()))->probe();
        $this->assertSame(975, $res['balance_minor']);
        $this->assertSame('USD', $res['currency']);
    }

    public function test_joker_credentials_and_environments_never_share_a_session(): void
    {
        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), '/login')) { return $this->reply('', 'Auth-SID: session-'.$r['api-key']."\n"); }
            return $this->reply("1.00\t0.00\t1.00\tUSD\n", "Columns: available,pending,total,currency\nSeparator: TAB\n");
        });
        (new JokerAdapter($this->account('joker', 'ote', 'first')))->probe();
        (new JokerAdapter($this->account('joker', 'ote', 'second')))->probe();
        (new JokerAdapter($this->account('joker', 'production', 'first')))->probe();
        Http::assertSentCount(6);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dmapi.ote.joker.com') && ($r['auth-sid'] ?? null) === 'session-second');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'dmapi.joker.com') && ($r['auth-sid'] ?? null) === 'session-first');
    }

    public function test_openprovider_landed_quote_adds_actual_tax_once_with_integer_rounding(): void
    {
        $account = $this->account('openprovider');
        $account->settings = ['supplier_vat_pct' => 10, 'supplier_fee_pct' => 1];
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'token']]),
            '*/domains/check' => Http::response(['code' => 0, 'data' => ['results' => [['status' => 'free']]]]),
            '*/domains/prices*' => Http::response(['code' => 0, 'data' => ['is_premium' => false, 'price' => ['reseller' => ['price' => '8.21', 'currency' => 'USD']]]]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['vatperc' => '10']])]);
        $quote = (new DomainProviderFactory)->forAccount($account)->quote('example.com', 'create', 12);
        $this->assertSame(914, $quote['amount_minor']); //821+83VAT+10fee
        $this->assertTrue($quote['tax_included']);
        $this->assertSame(83, $quote['components']['supplier_tax_minor']);
    }

    public function test_openprovider_new_accounts_do_not_reuse_the_global_or_each_others_token(): void
    {
        Cache::put('openprovider.token', 'legacy-other-account');
        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), '/auth/login')) { return Http::response(['code' => 0, 'data' => ['token' => 'token-'.$r['username']]]); }
            return Http::response(['code' => 0, 'data' => ['results' => []]]);
        });
        (new OpenProviderClient('https://api.openprovider.eu/v1beta', 'first', 'fixture', true))->check([]);
        (new OpenProviderClient('https://api.openprovider.eu/v1beta', 'second', 'fixture', true))->check([]);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $r) => ($r->header('Authorization')[0] ?? '') === 'Bearer legacy-other-account');
        Http::assertSent(fn (Request $r) => ($r->header('Authorization')[0] ?? '') === 'Bearer token-second');
    }

    public function test_openprovider_financial_timeout_is_not_retried(): void
    {
        $attempts = 0;
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'token']]),
            '*/domains' => function () use (&$attempts) { $attempts++; throw new ConnectionException('fixture-secret'); }]);
        $client = new OpenProviderClient('https://api.openprovider.eu/v1beta', 'fixture', 'fixture', true);
        $res = $client->registerDomain('example', 'com', 'fixture-handle', ['ns1.example.test', 'ns2.example.test']);
        $this->assertFalse($res['ok']);
        $this->assertSame(1, $attempts);
        $this->assertStringNotContainsString('fixture-secret', json_encode($res));
    }

    public function test_openprovider_account_adapter_uses_exact_premium_acceptance_and_ceiling(): void
    {
        $account = $this->account('openprovider');
        $account->settings = ['supplier_vat_pct' => 0, 'supplier_fee_pct' => 0];
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'token']]),
            '*/domains/check' => Http::response(['code' => 0, 'data' => ['results' => [['status' => 'free', 'is_premium' => true]]]]),
            '*/domains/prices*' => Http::response(['code' => 0, 'data' => ['is_premium' => true, 'price' => ['reseller' => ['price' => '8.21', 'currency' => 'USD']]]]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['vatperc' => 0]]),
            '*/domains' => Http::response(['code' => 0, 'data' => ['id' => 42, 'status' => 'ACT']])]);
        $res = (new DomainProviderFactory)->forAccount($account)->submit('create', 'example.com', 12, $this->context());
        $this->assertSame('queued', $res['status']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/domains') && $r['accept_premium_fee'] === 8.21 && $r['period'] === 1);
    }

    public function test_openprovider_price_increase_stops_before_financial_request(): void
    {
        $account = $this->account('openprovider');
        $account->settings = ['supplier_vat_pct' => 0, 'supplier_fee_pct' => 0];
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'token']]),
            '*/domains/check' => Http::response(['code' => 0, 'data' => ['results' => [['status' => 'free']]]]),
            '*/domains/prices*' => Http::response(['code' => 0, 'data' => ['is_premium' => false, 'price' => ['reseller' => ['price' => '8.22', 'currency' => 'USD']]]]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['vatperc' => 0]])]);
        try {
            (new DomainProviderFactory)->forAccount($account)->submit('create', 'example.com', 12, $this->context());
            $this->fail('Expected price ceiling to block submission');
        } catch (DomainProviderException $e) {
            $this->assertSame('supplier_price_changed', $e->reason);
            $this->assertFalse($e->uncertain);
        }
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/domains'));
    }

    public function test_joker_management_ack_does_not_confirm_a_nameserver_change_without_actual_matching_state(): void
    {
        $this->fakeJoker(['*/result-list' => $this->reply("20261031120000\t99\t42\tdomain-modify\tack\tfixture-operation\n"),
            '*/result-retrieve' => $this->reply('', "Result: ACK\n"),
            '*/query-domain-list' => $this->reply("example.com\t2027-05-30\tlock\n"),
            '*/query-whois' => $this->reply("nserver: old.example.test\n")]);
        $res = (new JokerAdapter($this->account()))->reconcile(['operation' => 'manage_nameservers', 'fqdn' => 'example.com', 'tracking_id' => '99', 'process_id' => '42',
            'request_context' => ['payload' => ['nameservers' => ['ns1.example.test', 'ns2.example.test']]]]);
        $this->assertSame('unknown', $res['status']);
        $this->assertSame('management_state_unconfirmed', $res['error_code']);
    }

    public function test_joker_management_reconciles_original_request_and_duplicate_nameserver_fields(): void
    {
        $this->fakeJoker(['*/result-list' => $this->reply("20261031120000\t99\t42\tdomain-modify\tack\tfixture-operation\n"),
            '*/result-retrieve' => $this->reply('', "Result: ACK\n"),
            '*/query-domain-list' => $this->reply("example.com\t2027-05-30\tlock\n"),
            '*/query-whois' => $this->reply("nserver: ns1.example.test\nnserver: ns2.example.test\n")]);
        $res = (new JokerAdapter($this->account()))->reconcile(['operation' => 'manage_nameservers', 'fqdn' => 'example.com', 'tracking_id' => '99', 'process_id' => '42',
            'request_context' => ['payload' => ['nameservers' => ['ns2.example.test', 'ns1.example.test']]]]);
        $this->assertSame('succeeded', $res['status']);
    }

    public function test_joker_de_maps_normalized_dnskey_in_documented_order(): void
    {
        $this->fakeJoker(['*/query-domain-list' => $this->reply("example.de\t2027-05-30\tlock\n"),
            '*/domain-modify' => $this->reply('', "Result: ACK\nProc-ID: 42\n")]);
        $res = (new JokerAdapter($this->account()))->manage('example.de', 'example.de', 'dnssec', ['keys' => [
            ['record_type' => 'DNSKEY', 'algorithm' => 13, 'flags' => 257, 'protocol' => 3, 'public_key' => 'AAAA'],
        ]]);
        $this->assertSame('queued', $res['status']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/domain-modify') && $r['ds-1'] === '3:13:257:AAAA' && $r['dnssec'] === 1);
    }

    public function test_openprovider_maps_normalized_dnskey_and_verifies_result_before_success(): void
    {
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'token']]),
            '*/domains?*' => Http::response(['code' => 0, 'data' => ['results' => [
                ['id' => 42, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => 'ACT'],
            ]]]),
            '*/domains/42' => function (Request $r) {
                if ($r->method() === 'PUT') { return Http::response(['code' => 0]); }
                return Http::response(['code' => 0, 'data' => ['dnssec_keys' => [['readonly' => 1, 'protocol' => 3, 'flags' => 257, 'alg' => 13, 'pub_key' => 'AAAA']]]]);
            }]);
        $res = (new DomainProviderFactory)->forAccount($this->account('openprovider'))->manage('example.com', '42', 'dnssec', ['keys' => [
            ['record_type' => 'DNSKEY', 'algorithm' => 13, 'flags' => 257, 'protocol' => 3, 'public_key' => 'AAAA'],
        ]]);
        $this->assertTrue($res['ok']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['dnssec_keys'] === [['alg' => 13, 'flags' => 257, 'protocol' => 3, 'pub_key' => 'AAAA']]);
    }

    public function test_openprovider_rejects_a_ds_payload_before_sending_a_wrong_dnskey_update(): void
    {
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'token']]),
            '*/domains?*' => Http::response(['code' => 0, 'data' => ['results' => [
                ['id' => 42, 'domain' => ['name' => 'example', 'extension' => 'com']],
            ]]])]);
        try {
            (new DomainProviderFactory)->forAccount($this->account('openprovider'))->manage('example.com', '42', 'dnssec', ['keys' => [
                ['tag' => 65535, 'algorithm' => 13, 'digest_type' => 2, 'digest' => 'AABBCC'],
            ]]);
            $this->fail('DS is not an Openprovider DNSKEY payload');
        } catch (DomainProviderException $e) {
            $this->assertSame('unsupported_dnssec_format', $e->reason);
        }
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    }

    public function test_legacy_account_identity_change_blocks_factory_management_and_finance_without_http(): void
    {
        config(['services.openprovider.username' => 'original-user', 'services.openprovider.password' => 'original-password']);
        $account = LegacyAccountIdentity::account();
        $account->update(['settings' => [LegacyAccountIdentity::SETTING => LegacyAccountIdentity::currentFingerprint()]]);
        $domain = new Domain(['domain' => 'example.com', 'registrar' => 'openprovider', 'op_id' => 42, 'supplier_account_id' => $account->id, 'supplier_object_id' => '42']);
        $this->assertTrue(DomainSupplierLifecycle::legacyCompatible($domain));
        config(['services.openprovider.username' => 'different-user']);
        $this->assertFalse(DomainSupplierLifecycle::legacyCompatible($domain));
        $this->assertFalse(DomainManagement::legacyCompatible($domain));
        $this->assertFalse(DomainManagement::legacyCompatible(new Domain(['registrar' => 'openprovider'])));
        try {
            (new DomainProviderFactory)->forAccount($account);
            $this->fail('A pinned login change is not a credential edit');
        } catch (DomainProviderException $e) { $this->assertSame('legacy_account_identity_changed', $e->reason); }
        Http::assertNothingSent();
    }

    public function test_legacy_missing_pin_is_not_writeable_as_an_account_bound_domain(): void
    {
        config(['services.openprovider.username' => 'fixture', 'services.openprovider.password' => 'fixture']);
        $account = LegacyAccountIdentity::account();
        $account->update(['settings' => []]);
        $domain = new Domain(['registrar' => 'openprovider', 'supplier_account_id' => $account->id, 'supplier_object_id' => '42', 'op_id' => 42]);
        $this->assertFalse(DomainSupplierLifecycle::legacyCompatible($domain));
        $this->assertFalse(DomainManagement::legacyCompatible($domain));
        try {
            (new DomainProviderFactory)->forAccount($account)->submit('renew', 'example.com', 12, ['object_id' => '42']);
            $this->fail('No writes before real account binding');
        } catch (DomainProviderException $e) { $this->assertSame('legacy_account_identity_unverified', $e->reason); }
        Http::assertNothingSent();
    }

    public function test_missing_legacy_pin_requires_fresh_scoped_exact_object_evidence(): void
    {
        config(['services.openprovider.username' => 'fixture', 'services.openprovider.password' => 'fixture']);
        $account = LegacyAccountIdentity::account();
        $account->update(['settings' => []]);
        Cache::put('openprovider.token', 'stale-other-account');
        $correct = false;
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'scoped-current-account']]),
            '*/domains/42' => function () use (&$correct) {
                return Http::response(['code' => 0, 'data' => ['id' => 42, 'domain' => ['name' => $correct ? 'example' : 'someone-else', 'extension' => 'com']]]);
            }]);
        $this->assertFalse(LegacyAccountIdentity::confirmObject($account, 'example.com', '42'));
        $this->assertFalse(LegacyAccountIdentity::matches($account->fresh()));
        $correct = true;
        $this->assertTrue(LegacyAccountIdentity::confirmObject($account, 'example.com', '42'));
        $this->assertTrue(LegacyAccountIdentity::matches($account->fresh()));
        $this->assertNull(Cache::get('openprovider.token'));
        Http::assertNotSent(fn (Request $r) => ($r->header('Authorization')[0] ?? '') === 'Bearer stale-other-account');
    }

    public function test_migration_pins_configured_legacy_identity_without_storing_credentials_or_reusing_stale_token(): void
    {
        $migration = require database_path('migrations/2026_10_31_150000_domain_supplier_accounts.php');
        $migration->down();
        config(['services.openprovider.username' => 'private-migration-user', 'services.openprovider.password' => 'private-migration-password']);
        Cache::put('openprovider.token', 'stale-token');
        $migration->up();
        $account = LegacyAccountIdentity::account();
        $this->assertTrue(LegacyAccountIdentity::matches($account));
        $this->assertNull($account->credentials);
        $settings = json_encode($account->settings);
        $this->assertStringNotContainsString('private-migration-user', $settings);
        $this->assertStringNotContainsString('private-migration-password', $settings);
        $this->assertNull(Cache::get('openprovider.token'));
        Http::assertNothingSent();
    }

    public function test_openprovider_changed_remote_expiry_stops_before_a_renewal_request(): void
    {
        Http::fake(['*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'token']]),
            '*/domains?*' => Http::response(['code' => 0, 'data' => ['results' => [
                ['id' => 42, 'domain' => ['name' => 'example', 'extension' => 'com'], 'expiration_date' => '2028-05-30'],
            ]]])]);
        try {
            (new DomainProviderFactory)->forAccount($this->account('openprovider'))->submit('renew', 'example.com', 12,
                ['object_id' => '42', 'prior_expires_at' => '2027-05-30', 'target_expires_at' => '2028-05-30'] + $this->context());
            $this->fail('Renewal target is already met');
        } catch (DomainProviderException $e) { $this->assertSame('renewal_expiry_changed', $e->reason); }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/renew'));
    }

    public function test_minor_units_use_explicit_currency_and_half_up_rounding(): void
    {
        $this->assertSame(1001, ProviderMoney::minor('10.005', 'USD'));
        $this->assertSame('10.01', ProviderMoney::decimal(1001, 'USD'));
        $this->assertSame(10, ProviderMoney::minor('10', 'IRT'));
        $this->assertSame(1, ProviderMoney::surcharge(1, 1));
        $this->expectExceptionMessage('unsupported_currency');
        ProviderMoney::minor('10', 'ZZZ');
    }
}
