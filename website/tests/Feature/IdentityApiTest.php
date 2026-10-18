<?php

namespace Tests\Feature;

use App\Models\AiReservation;
use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\IdentityApplication;
use App\Models\IdentityPrice;
use App\Models\IdentityRequest;
use App\Models\IdentityVerification;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use App\Services\IdentityApi\Catalog;
use App\Services\IdentityApi\BalanceSync;
use App\Services\IdentityApi\Contract;
use App\Services\IdentityApi\Controls;
use App\Services\IdentityApi\Engine;
use App\Services\IdentityApi\Pricing;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentityApiTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private IdentityApplication $application;

    private CustomerApiToken $token;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Wallet::flushSchemaCache();
        Http::preventStrayRequests();
        $this->customer = Customer::create(['email' => 'identity-fixture@example.test', 'status' => 'active', 'locale' => 'fa']);
        IdentityVerification::create(['customer_id' => $this->customer->id, 'status' => 'verified', 'national_id_enc' => Crypt::encryptString('synthetic'), 'national_id_hash' => hash('sha256', 'synthetic'), 'birth_date' => '2000-01-01', 'mobile' => '00000000000']);
        $this->application = IdentityApplication::create(['customer_id' => $this->customer->id, 'name' => 'Synthetic test', 'daily_cap_irt' => 100000, 'terms_version' => '2026-10-17', 'accepted_at' => now()]);
        [$this->token,$this->plain] = CustomerApiToken::issue($this->customer->id, 'Synthetic', array_keys(CustomerApiToken::IDENTITY_ABILITIES), [], null, null, $this->application->id);
        $this->token->update(['daily_spend_cap_irt' => 100000]);
        app(Wallet::class)->credit($this->customer->id, 'IRT', 100000, 'topup', $this->customer);
        TaxRate::query()->delete();
        TaxRate::create(['country' => 'IR', 'product_kind' => null, 'name' => 'Fixture tax', 'rate_bp' => 1000, 'priority' => 1, 'is_active' => true]);
        foreach (['enabled' => true, 'egress_verified' => true, 'resale_evidence' => 'test-contract', 'service:shahkar-inquiry' => true, 'provider_daily_cap_irt' => 1000000, 'upstream_balance_irt' => 1000000, 'upstream_balance_verified_at' => now()->toIso8601String()] as $k => $v) {
            app(Controls::class)->put($k, $v, 1);
        }
        config(['identity_api.token' => 'synthetic-provider-key']);
        app(Controls::class)->put('customer_cap:'.$this->customer->id, 1000000, 1);
        $this->price();
    }

    private function price(array $extra = []): IdentityPrice
    {
        return IdentityPrice::create(array_merge(['service' => 'shahkar-inquiry', 'source_amount' => 1000, 'source_currency' => 'IRT', 'purchase_tax_included' => true, 'purchase_tax_amount' => 0, 'markup_bps' => 500, 'daily_quota' => 100, 'billing_policy' => 'valid_data_v1', 'evidence' => 'synthetic-contract', 'access_evidence' => 'synthetic-access', 'effective_at' => now(), 'valid_until' => now()->addDay()], $extra));
    }

    private function payload(): array
    {
        return ['mobile' => '09120000000', 'national_code' => '0000000019'];
    }

    private function accept(string $key = 'synthetic-request-0001'): IdentityRequest
    {
        return app(Engine::class)->accept($this->application, $this->token, 'shahkar-inquiry', $this->payload(), $key, 10000);
    }

    private function fake(array $data = ['matched' => false], int $result = 1, ?string $error = null): void
    {
        Http::fake(['service.zohal.io/*' => Http::response(['result' => $result, 'response_body' => ['data' => $data, 'error_code' => $error]], 200)]);
    }

    public function test_valid_negative_result_captures_once_and_posts_separate_cost_revenue_and_tax(): void
    {
        $this->fake();
        $r = $this->accept();
        $this->assertSame(1155, $r->hold_irt);
        $this->assertSame(98845, app(Wallet::class)->availableOf($this->customer->id));
        $r = app(Engine::class)->dispatch($r);
        app(Engine::class)->dispatch($r);
        app(Engine::class)->capture($r);
        $this->assertSame('unmatched', $r->inquiry_outcome);
        $this->assertSame('captured', $r->financial_status);
        $this->assertSame(98845, app(Wallet::class)->balanceOf($this->customer->id));
        $this->assertSame(1, CreditEntry::where('reason', 'identity_inquiry')->count());
        $this->assertSame(1050, (int) BusinessEntry::where('source_type', IdentityRequest::class)->where('kind', 'revenue')->sum('amount'));
        $this->assertSame(105, (int) BusinessEntry::where('source_type', IdentityRequest::class)->where('kind', 'tax_collected')->sum('amount'));
        $this->assertSame(1000, (int) BusinessEntry::where('source_type', IdentityRequest::class)->where('kind', 'expense')->sum('amount'));
        Http::assertSentCount(1);
    }

    public function test_idempotent_replay_uses_snapshot_even_after_price_changes_and_expiry(): void
    {
        $this->fake();
        $r = app(Engine::class)->dispatch($this->accept());
        $this->price(['source_amount' => 90000]);
        $same = $this->accept();
        $this->assertSame($r->id, $same->id);
        $this->assertSame(1155, $same->charged_irt);
        $this->travel(25)->hours();
        $this->artisan('identity:reconcile')->assertSuccessful();
        $same = $this->accept();
        $this->assertSame($r->id, $same->id);
        $this->assertNull($same->receipt()['result']);
        Http::assertSentCount(1);
    }

    public function test_same_key_with_changed_payload_conflicts(): void
    {
        $this->accept();
        $p = $this->payload();
        $p['mobile'] = '09120000001';
        $this->expectExceptionMessage('idempotency_conflict');
        app(Engine::class)->accept($this->application, $this->token, 'shahkar-inquiry', $p, 'synthetic-request-0001', 10000);
    }

    public function test_timeout_never_retries_and_deadline_releases_without_late_capture(): void
    {
        Http::fake(['service.zohal.io/*' => Http::failedConnection()]);
        $r = app(Engine::class)->dispatch($this->accept());
        $this->assertSame('pending_reconciliation', $r->financial_status);
        $this->travel(16)->minutes();
        $this->artisan('identity:reconcile')->assertSuccessful();
        $this->assertSame('released', $r->fresh()->financial_status);
        $this->assertTrue($r->fresh()->discrepancy);
        $this->fake();
        app(Engine::class)->dispatch($r);
        app(Engine::class)->capture($r);
        $this->assertSame(100000, app(Wallet::class)->balanceOf($this->customer->id));
        Http::assertNothingSent();
    }

    public function test_cancelled_request_never_dispatches_after_topup(): void
    {
        $r = $this->accept();
        app(Engine::class)->release($r, 'cancelled');
        app(Wallet::class)->credit($this->customer->id, 'IRT', 500, 'topup', $this->customer);
        app(Engine::class)->dispatch($r);
        Http::assertNothingSent();
        $this->assertSame('released', $r->fresh()->financial_status);
    }

    public function test_database_failure_after_response_preserves_evidence_and_reconciles_without_resend(): void
    {
        $this->fake();
        $r = $this->accept();
        $real = app(BusinessLedger::class);
        $broken = \Mockery::mock(BusinessLedger::class)->makePartial();
        $broken->shouldReceive('recordIdentitySale')->once()->andThrow(new \RuntimeException('Synthetic database failure'));
        app()->instance(BusinessLedger::class, $broken);
        $r = app(Engine::class)->dispatch($r);
        $this->assertSame('pending_reconciliation', $r->financial_status);
        $this->assertNotNull($r->provider_evidence);
        $this->assertSame(0, CreditEntry::where('reason', 'identity_inquiry')->count());
        app()->instance(BusinessLedger::class, $real);
        $this->artisan('identity:reconcile')->assertSuccessful();
        $this->assertSame('captured', $r->fresh()->financial_status);
        Http::assertSentCount(1);
    }

    public function test_identity_exclusion_never_excludes_same_numeric_ai_id(): void
    {
        $r = $this->accept();
        AiReservation::forceCreate(['id' => $r->id, 'customer_id' => $this->customer->id, 'currency_code' => 'IRT', 'amount_irt' => 100, 'status' => 'pending', 'expires_at' => now()->addHour()]);
        $this->assertSame(1255, app(Wallet::class)->reservedOf($this->customer->id));
        $this->assertSame(1155, app(Wallet::class)->reservedOf($this->customer->id, 'IRT', $r->id));
        $this->assertSame(100, app(Wallet::class)->reservedOf($this->customer->id, 'IRT', null, $r->id));
        $this->fake();
        app(Engine::class)->dispatch($r);
        $this->assertSame(100, app(Wallet::class)->reservedOf($this->customer->id));
    }

    public function test_ordinary_debit_cannot_spend_identity_hold(): void
    {
        $this->accept();
        $this->expectException(WalletException::class);
        app(Wallet::class)->debit($this->customer->id, 'IRT', 99000, 'invoice_payment', $this->customer);
    }

    public function test_refund_is_idempotent_and_reverses_revenue_and_tax_without_erasing_provider_cost(): void
    {
        $this->fake();
        $r = app(Engine::class)->dispatch($this->accept());
        app(Engine::class)->refund($r, 1, 'synthetic-correction');
        app(Engine::class)->refund($r, 1, 'duplicate');
        $this->assertSame(100000, app(Wallet::class)->balanceOf($this->customer->id));
        $this->assertSame(1, CreditEntry::where('reason', 'identity_refund')->count());
        $summary = app(BusinessLedger::class)->summary();
        $this->assertSame(0, $summary['revenue']);
        $this->assertSame(0, $summary['tax_collected']);
        $this->assertSame(1000, $summary['expense']);
    }

    public static function forbiddenTokens(): array
    {
        return [['*'], ['read'], ['domains:write'], ['ai:chat']];
    }

    #[DataProvider('forbiddenTokens')]
    public function test_legacy_scopes_cannot_execute_identity(string $scope): void
    {
        [$t,$p] = CustomerApiToken::issue($this->customer->id, 'Legacy', [$scope]);
        $this->withToken($p)->postJson('/api/v1/identity/inquiries/shahkar-inquiry', ['input' => $this->payload(), 'max_charge_irt' => 10000])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_tenant_and_application_isolation_for_receipts(): void
    {
        $r = $this->accept();
        $a = IdentityApplication::create(['customer_id' => $this->customer->id, 'name' => 'Other app', 'terms_version' => '2026-10-17', 'accepted_at' => now(), 'daily_cap_irt' => 10000]);
        [$t,$p] = CustomerApiToken::issue($this->customer->id, 'Other', array_keys(CustomerApiToken::IDENTITY_ABILITIES), [], null, null, $a->id);
        $this->withToken($p)->getJson('/api/v1/identity/requests/'.$r->public_id)->assertNotFound();
        $this->withToken($this->plain)->getJson('/api/v1/identity/requests/'.$r->public_id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_expiry_revocation_and_ip_deny_prevent_dispatch(): void
    {
        $this->token->update(['revoked_at' => now()]);
        $this->withToken($this->plain)->postJson('/api/v1/identity/inquiries/shahkar-inquiry', [])->assertUnauthorized();
        $this->token->update(['revoked_at' => null, 'expires_at' => now()->subMinute()]);
        $this->withToken($this->plain)->getJson('/api/v1/identity/services')->assertUnauthorized();
        $this->token->update(['expires_at' => null, 'allowed_cidrs' => ['192.0.2.0/24']]);
        $this->withToken($this->plain)->getJson('/api/v1/identity/services')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_api_execution_and_public_projection_do_not_expose_provider_or_buy_cost(): void
    {
        $this->fake();
        $response = $this->withToken($this->plain)->withHeader('Idempotency-Key', 'synthetic-api-request-0001')
            ->postJson('/api/v1/identity/inquiries/shahkar-inquiry', ['input' => $this->payload(), 'max_charge_irt' => 2000])->assertOk();
        foreach (['zohal', 'source_amount', 'markup_bps', 'provider_evidence', 'synthetic-provider-key', '0000000019'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertStringNotContainsString('0000000019', DB::table('identity_requests')->value('provider_evidence'));
    }

    public function test_unknown_or_unconfirmed_zero_price_fails_closed(): void
    {
        $this->price(['source_amount' => 0]);
        $this->expectExceptionMessage('price_unavailable');
        $this->accept();
    }

    public function test_irr_conversion_rounds_once_after_markup(): void
    {
        $p = $this->price(['source_amount' => 10001, 'source_currency' => 'IRR']);
        $q = app(Pricing::class)->quote($p);
        $this->assertSame(1051, $q['sell_irt']);
        $this->assertSame(1001, $q['cost_irt']);
        $this->assertSame(106, $q['tax_irt']);
    }

    public function test_ambiguous_envelopes_do_not_capture(): void
    {
        $this->fake(['matched' => false], 1, 'not_found');
        $r = app(Engine::class)->dispatch($this->accept());
        $this->assertSame('pending_reconciliation', $r->financial_status);
        $this->assertSame(0, $r->charged_irt);
    }

    public function test_expired_undispatched_hold_can_never_be_claimed(): void
    {
        $r = $this->accept();
        $this->travel(16)->minutes();
        app(Engine::class)->dispatch($r);
        Http::assertNothingSent();
        $this->assertSame(0, app(Wallet::class)->reservedOf($this->customer->id));
    }

    public function test_query_pii_and_arbitrary_proxy_inputs_rejected_without_dispatch(): void
    {
        $this->withToken($this->plain)->getJson('/api/v1/identity/services?national_code=synthetic')->assertUnprocessable();
        $this->expectExceptionMessage('invalid_input');
        $p = $this->payload();
        $p['url'] = 'http://127.0.0.1/';
        app(Contract::class)->input(app(Catalog::class)->find('shahkar-inquiry'), $p);
    }

    public function test_catalog_has_all_products_and_media_credit_are_explicitly_blocked(): void
    {
        $c = app(Catalog::class);
        $this->assertCount(35, $c->all());
        $this->assertCount(31, array_filter($c->all(), fn ($s) => ! $s['contract_blocker']));
        foreach (['credit-inquiry', 'liveness-check', 'ocr-for-id-card'] as $s) {
            $this->assertNotNull($c->find($s)['contract_blocker']);
        }
        $spec = json_decode(file_get_contents(resource_path('identity/openapi.json')), true);
        $this->assertCount(62, $spec['components']['schemas']);
    }

    public function test_all_single_stage_response_contracts_with_synthetic_fixtures(): void
    {
        $fixture = function (array $s) use (&$fixture) {
            if (isset($s['anyOf'])) {
                return $fixture($s['anyOf'][0]);
            }
            if (isset($s['oneOf'])) {
                return $fixture($s['oneOf'][0]);
            }
            if (isset($s['enum'])) {
                return $s['enum'][0];
            }

            return match ($s['type'] ?? 'object') {
                'null' => null,'string' => 'synthetic','boolean' => false,'integer' => 0,'number' => 0,'array' => [],default => array_map($fixture, $s['properties'] ?? [])
            };
        };
        foreach (app(Catalog::class)->all() as $s) {
            if ($s['contract_blocker']) {
                continue;
            }
            $schema = $s['operations'][0]['output']['properties']['response_body']['properties']['data'];
            $data = $fixture($schema);
            $result = app(Contract::class)->response($s, ['result' => 1, 'response_body' => ['data' => $data, 'error_code' => null]], 200);
            $this->assertNotNull($result, $s['slug']);
        }
    }

    public function test_persian_digits_and_mobile_prefix_are_normalized_without_changing_identifier_type(): void
    {
        $p = app(Contract::class)->input(app(Catalog::class)->find('shahkar-inquiry'), ['mobile' => '+989120000000', 'national_code' => '۰۰۰۰۰۰۰۰۱۹']);
        $this->assertSame($this->payload()['national_code'], $p['national_code']);
        $this->assertSame('09120000000', $p['mobile']);
    }

    public function test_emergency_stop_blocks_dispatch_but_allows_existing_capture(): void
    {
        $r = $this->accept();
        app(Controls::class)->put('enabled', false, 1);
        app(Engine::class)->dispatch($r);
        Http::assertNothingSent();
        app(Controls::class)->put('enabled', true, 1);
        $this->fake();
        app(Engine::class)->dispatch($r);
        $this->assertSame('captured', $r->fresh()->financial_status);
    }

    public function test_sandbox_never_calls_provider_or_changes_wallet(): void
    {
        $this->actingAs($this->customer, 'customer')->post('/account/identity-api/sandbox')->assertOk()->assertJson(['simulated' => true, 'charged_irt' => 0]);
        Http::assertNothingSent();
        $this->assertSame(100000, app(Wallet::class)->balanceOf($this->customer->id));
    }

    public function test_three_language_pages_render_without_provider_or_missing_translations(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['/identity-api', '/identity-api/docs', '/identity-api/terms'] as $path) {
                $r = $this->get($prefix.$path)->assertOk();
                $this->assertStringNotContainsString('zohal', strtolower($r->getContent()));
                $this->assertStringNotContainsString('identity.service_', $r->getContent());
            }
        }
        foreach (['fa', 'en', 'tr'] as $l) {
            $this->assertSame(array_keys(require lang_path('fa/identity.php')), array_keys(require lang_path($l.'/identity.php')));
        }
    }

    public function test_account_and_admin_pages_render_and_author_cannot_administer_product(): void
    {
        $this->actingAs($this->customer, 'customer')->get('/account/identity-api')->assertOk();
        $this->get('/account/security')->assertOk();
        $u = User::factory()->create(['role' => 'admin']);
        $this->actingAs($u, 'web')->get('/admin/identity-api')->assertOk();
        $u->update(['role' => 'author']);
        $this->get('/admin/identity-api')->assertForbidden();
    }

    public function test_explicit_execute_without_group_scope_is_rejected(): void
    {
        $this->token->update(['abilities' => ['identity:execute']]);
        $this->withToken($this->plain)->withHeader('Idempotency-Key', 'synthetic-no-group-key')
            ->postJson('/api/v1/identity/inquiries/shahkar-inquiry', ['input' => $this->payload(), 'max_charge_irt' => 2000])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_localized_admin_identity_routes_stay_on_console_and_main_redirects_there(): void
    {
        $u = User::factory()->create(['role' => 'admin']);
        foreach (['en', 'tr'] as $locale) {
            $path = '/'.$locale.'/admin/identity-api';
            $this->actingAs($u, 'web')->get('https://console.servernet.cloud'.$path)->assertOk()->assertSee('lang="'.$locale.'"', false)->assertSee('dir="ltr"', false);
            $this->get('https://servernet.cloud'.$path)->assertRedirect('https://console.servernet.cloud'.$path);
        }
    }

    public function test_identity_web_guests_use_existing_login_while_api_guests_receive_401(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get('https://console.servernet.cloud'.$prefix.'/account/identity-api')->assertRedirect();
            $this->get('https://console.servernet.cloud'.$prefix.'/admin/identity-api')->assertRedirect('https://console.servernet.cloud/admin/login');
        }
        $this->get('/api/v1/identity/services')->assertUnauthorized();
    }

    public function test_available_wallet_funds_and_holds_are_shared_across_applications_without_customer_budgets(): void
    {
        app(Controls::class)->put('customer_cap:'.$this->customer->id, 0, 1);
        $this->application->update(['daily_cap_irt' => 0]);
        $this->token->update(['daily_spend_cap_irt' => 0]);
        app(Wallet::class)->debit($this->customer->id, 'IRT', 97000, 'invoice_payment', $this->customer);
        $this->accept();
        $other = IdentityApplication::create(['customer_id' => $this->customer->id, 'name' => 'second', 'terms_version' => '2026-10-17', 'accepted_at' => now(), 'daily_cap_irt' => 0]);
        app(Engine::class)->accept($other, null, 'shahkar-inquiry', $this->payload(), 'synthetic-other-app-key', 2000);
        $this->assertSame(690, app(Wallet::class)->availableOf($this->customer->id));
        $this->expectExceptionMessage('insufficient_funds');
        app(Engine::class)->accept($other, null, 'shahkar-inquiry', $this->payload(), 'synthetic-third-app-key', 2000);
    }


    public function test_wrong_customer_bound_token_cannot_read_another_customers_result(): void
    {
        $r = $this->accept();
        $other = Customer::create(['email' => 'other@example.test', 'status' => 'active', 'locale' => 'fa']);
        [$token,$plain] = CustomerApiToken::issue($other->id, 'wrong-owner', ['identity:requests:read'], [], null, null, $this->application->id);
        $this->withToken($plain)->getJson('/api/v1/identity/requests/'.$r->public_id)->assertForbidden();
    }

    public function test_unmigrated_product_does_not_break_other_wallet_debits(): void
    {
        Schema::drop('identity_requests');
        Wallet::flushSchemaCache();
        $this->assertFalse(app(Controls::class)->ready());
        app(Wallet::class)->debit($this->customer->id, 'IRT', 1000, 'invoice_payment', $this->customer);
        $this->assertSame(99000, app(Wallet::class)->availableOf($this->customer->id));
    }

    public function test_sensitive_exception_context_and_query_do_not_enter_tracker(): void
    {
        Route::get('/api/v1/identity/synthetic-exception', fn () => throw new \RuntimeException('synthetic-national-code-and-secret'));
        $this->getJson('/api/v1/identity/synthetic-exception?national_code=synthetic-private-id')->assertStatus(503)
            ->assertDontSee('synthetic-national-code-and-secret');
        $tracker = json_encode(ErrorTracker::recent());
        $this->assertStringNotContainsString('synthetic-private-id', $tracker);
        $this->assertStringNotContainsString('synthetic-national-code-and-secret', $tracker);
    }

    public function test_purchase_vat_is_in_cost_once_and_output_tax_uses_current_table(): void
    {
        $this->price(['source_amount' => 14300, 'source_currency' => 'IRR', 'purchase_tax_amount' => 1300]);
        TaxRate::create(['country' => 'IR', 'product_kind' => 'identity', 'name' => 'Specific fixture', 'rate_bp' => 900, 'priority' => 1, 'is_active' => true]);
        $this->fake();
        $r = app(Engine::class)->dispatch($this->accept());
        $this->assertSame(1430, $r->quote['cost_irt']);
        $this->assertSame(1502, $r->quote['sell_irt']);
        $this->assertSame(136, $r->quote['tax_irt']);
        $this->assertSame(1430, (int) BusinessEntry::where('source_type', IdentityRequest::class)->where('kind', 'expense')->sum('amount'));
        $this->assertSame(0, (int) BusinessEntry::where('source_type', IdentityRequest::class)->where('kind', 'tax_paid')->sum('amount'));
    }

    public function test_draft_price_can_be_calculated_but_cannot_be_offered_or_admitted(): void
    {
        $p = $this->price(['source_amount' => 14300, 'source_currency' => 'IRR', 'purchase_tax_amount' => 1300,
            'billing_policy' => 'pending_billing_evidence_v1']);
        $q = app(Pricing::class)->preview($p);
        $this->assertSame(1430, $q['cost_irt']);
        $this->assertSame(1502, $q['sell_irt']);
        $this->assertSame(151, $q['tax_irt']);
        $this->assertSame(1653, $q['total_irt']);
        $service = collect(app(Catalog::class)->publicList())->firstWhere('slug', 'shahkar-inquiry');
        $this->assertFalse($service['active']);
        $this->assertNull($service['price']);
        try {
            $this->accept();
            $this->fail('A draft must never reserve money or reach dispatch.');
        } catch (\App\Services\IdentityApi\IdentityError $e) {
            $this->assertSame('price_unavailable', $e->getMessage());
        }
        $this->assertSame(0, IdentityRequest::count());
        $this->assertSame(100000, app(Wallet::class)->availableOf($this->customer->id));
        Http::assertNothingSent();
    }

    public function test_admin_can_save_an_unconfirmed_price_and_see_final_prices_in_all_three_languages(): void
    {
        $u = User::factory()->create(['role' => 'admin']);
        $this->actingAs($u, 'web')->post('/admin/identity-api/prices', [
            'service' => 'shahkar-inquiry', 'source_amount' => 14300, 'source_currency' => 'IRR',
            'purchase_tax_amount' => 1300, 'purchase_tax_included' => '1', 'markup_bps' => 500,
            'daily_quota' => 20, 'evidence' => 'synthetic-fee-observation', 'access_evidence' => 'synthetic-service-access',
            'effective_at' => now()->subMinute()->format('Y-m-d H:i:s'), 'valid_until' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $p = IdentityPrice::latest('id')->firstOrFail();
        $this->assertSame('pending_billing_evidence_v1', $p->billing_policy);
        $this->assertDatabaseHas('identity_audits', ['action' => 'price_version']);
        foreach (['' => 'fa', 'en/' => 'en', 'tr/' => 'tr'] as $prefix => $locale) {
            $this->get('/'.$prefix.'admin/identity-api')->assertOk()
                ->assertSee('1,502')->assertSee(__('identity.price_billing_pending', [], $locale))
                ->assertDontSee('identity.price_billing_pending');
        }
        Http::assertNothingSent();
    }

    public function test_identity_fee_preview_uses_the_current_tax_table_and_cannot_bypass_expiry(): void
    {
        $p = $this->price(['service' => 'id-inquiry', 'source_amount' => 74800, 'source_currency' => 'IRR',
            'purchase_tax_amount' => 6800, 'billing_policy' => 'pending_billing_evidence_v1']);
        $q = app(Pricing::class)->preview($p);
        $this->assertSame(7480, $q['cost_irt']);
        $this->assertSame(7854, $q['sell_irt']);
        $this->assertSame(786, $q['tax_irt']);
        $this->assertSame(8640, $q['total_irt']);
        TaxRate::create(['country' => 'IR', 'product_kind' => 'identity', 'name' => 'Synthetic new tax', 'rate_bp' => 900, 'priority' => 2, 'is_active' => true]);
        $this->assertSame(8561, app(Pricing::class)->preview($p)['total_irt']);
        $this->travel(2)->days();
        $this->expectExceptionMessage('price_unavailable');
        app(Pricing::class)->preview($p);
    }

    public function test_catalog_excludes_tax_and_read_only_purchase_quote_exposes_no_wholesale_details(): void
    {
        $this->token->update(['abilities' => ['identity:services:read']]);
        $catalog = $this->withToken($this->plain)->getJson('/api/v1/identity/services')->assertOk();
        $service = collect($catalog->json('services'))->firstWhere('slug', 'shahkar-inquiry');
        $this->assertSame(1050, $service['price']);
        $this->assertArrayNotHasKey('tax_irt', $service);
        $this->assertArrayNotHasKey('total_irt', $service);
        $quote = $this->withToken($this->plain)->getJson('/api/v1/identity/quotes/shahkar-inquiry')->assertOk()
            ->assertJsonPath('subtotal_irt', 1050)->assertJsonPath('tax_irt', 105)->assertJsonPath('total_irt', 1155)
            ->assertJsonPath('currency', 'IRT')->assertHeader('Cache-Control', 'no-store, private');
        foreach (['source_amount', 'cost_irt', 'evidence', 'access_evidence', 'markup_bps'] as $field) {
            $this->assertArrayNotHasKey($field, $quote->json());
        }
        $this->assertSame(0, IdentityRequest::count());
        $this->token->update(['abilities' => ['*']]);
        $this->withToken($this->plain)->getJson('/api/v1/identity/quotes/shahkar-inquiry')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_tax_is_shown_in_the_purchase_confirmation_in_all_languages_and_not_the_public_rate_list(): void
    {
        foreach (['' => 'fa', 'en/' => 'en', 'tr/' => 'tr'] as $prefix => $locale) {
            $this->get('/'.$prefix.'identity-api')->assertOk()->assertSee('1,050')->assertDontSee('1,155');
            $response = $this->actingAs($this->customer, 'customer')->get('/'.$prefix.'account/identity-api?service=shahkar-inquiry')->assertOk();
            $this->assertSame(1, preg_match('/<div class="identity-checkout".*?<\/div>/s', $response->getContent(), $matches));
            $this->assertStringContainsString(__('identity.preview_tax', [], $locale), $matches[0]);
            $this->assertStringContainsString('1,155', $matches[0]);
            $this->assertStringContainsString('105', $matches[0]);
            $response->assertSee('name="max_charge_irt" value="1155"', false)->assertSee('name="price_version"', false);
        }
        Http::assertNothingSent();
    }

    public function test_absent_default_and_explicit_zero_historical_customer_caps_do_not_limit_wallet_usage(): void
    {
        DB::table('identity_controls')->where('key', 'customer_cap:'.$this->customer->id)->delete();
        app(Controls::class)->put('default_customer_cap_irt', 0, 1);
        $this->assertSame(1155, $this->accept('synthetic-default-cap-0001')->hold_irt);
        app(Controls::class)->put('customer_cap:'.$this->customer->id, 0, 1);
        $this->assertSame(1155, $this->accept('synthetic-default-cap-0002')->hold_irt);
        Http::assertNothingSent();
    }


    public function test_account_checkout_rejects_a_changed_price_version_before_any_dispatch(): void
    {
        $quote = app(Catalog::class)->checkoutQuote('shahkar-inquiry', $this->customer);
        $this->price(['source_amount' => 2000]);
        $this->actingAs($this->customer, 'customer')->postJson('/account/identity-api/inquire', [
            'application_id' => $this->application->id, 'service' => 'shahkar-inquiry', 'input' => $this->payload(),
            'idempotency_key' => 'synthetic-account-quote-0001', 'max_charge_irt' => 10000,
            'price_version' => $quote['price_version'], 'real_charge' => '1',
        ])->assertStatus(409)->assertJsonPath('error', 'quote_changed');
        $this->assertSame(0, IdentityRequest::count());
        Http::assertNothingSent();
    }

    public function test_stale_funding_removes_purchase_prices_and_prevents_quotes_without_an_upstream_call(): void
    {
        app(Controls::class)->put('upstream_balance_verified_at', now()->subMinutes(61)->toIso8601String(), 1);
        $service = collect(app(Catalog::class)->publicList())->firstWhere('slug', 'shahkar-inquiry');
        $this->assertFalse($service['active']);
        $this->assertNull($service['price']);
        $this->withToken($this->plain)->getJson('/api/v1/identity/quotes/shahkar-inquiry')->assertStatus(503);
        $this->assertSame(0, IdentityRequest::count());
        Http::assertNothingSent();
    }

    public function test_all_thirty_one_json_services_use_their_registered_wire_contract_and_settle_synthetic_responses(): void
    {
        $fixture = function (array $s) use (&$fixture) {
            if (isset($s['anyOf'])) {
                return $fixture($s['anyOf'][0]);
            }
            if (isset($s['oneOf'])) {
                return $fixture($s['oneOf'][0]);
            }
            if (isset($s['enum'])) {
                return $s['enum'][0];
            }

            return match ($s['type'] ?? 'object') {
                'null' => null,'string' => 'synthetic','boolean' => false,'integer' => 0,'number' => 0,'array' => [],default => array_map($fixture, $s['properties'] ?? [])
            };
        };
        $values = ['mobile' => '09120000000', 'national_code' => '0000000019', 'national_id' => '00000000019', 'birth_date' => '1400/01/01',
            'card_number' => '4111111111111111', 'iban' => 'IR000000000000000000000000', 'IBAN' => 'IR000000000000000000000000',
            'postal_code' => '0000000000', 'sayad_id' => '0000000000000000', 'bank_account' => '0000000001', 'bank_code' => '000',
            'name' => 'Synthetic Test', 'persian_text' => 'آزمایشی', 'website' => 'example.test', 'code' => '123456', 'bill_id' => '0000000000000',
            'phone_number' => '02100000000', 'region_code' => '00', 'plate_number' => 'SYNTHETIC', 'cheque_type' => '1'];
        $count = 0;
        foreach (app(Catalog::class)->all() as $s) {
            if ($s['contract_blocker']) {
                continue;
            }
            $op = $s['operations'][0];
            $input = [];
            foreach ($op['input']['properties'] as $name => $schema) {
                $input[$name] = isset($schema['enum']) ? $schema['enum'][0] : ($values[$name] ?? $fixture($schema));
            }
            $data = $fixture($op['output']['properties']['response_body']['properties']['data']);
            $url = 'https://service.zohal.io'.$op['path'];
            Http::fake([$url => Http::response(['result' => 1, 'response_body' => ['data' => $data, 'error_code' => null]])]);
            app(Controls::class)->put('service:'.$s['slug'], true, 1);
            $this->price(['service' => $s['slug']]);
            $r = app(Engine::class)->accept($this->application, $this->token, $s['slug'], $input, 'synthetic-wire-request-'.(++$count), 2000);
            $r = app(Engine::class)->dispatch($r);
            $this->assertSame('captured', $r->financial_status, $s['slug']);
            Http::assertSent(fn ($req) => $req->url() === $url && $req->method() === 'POST' && $req->hasHeader('Authorization', 'Bearer synthetic-provider-key'));
            Http::assertSentCount(1); // Each fake starts a fresh request recorder.
            $this->travel(4)->seconds();
        }
        $this->assertSame(31, $count);
        $this->assertSame(31, CreditEntry::where('reason', 'identity_inquiry')->count());
    }

    public function test_legacy_daily_count_metadata_does_not_limit_customer_usage(): void
    {
        $this->price(['source_amount' => 0, 'confirmed_zero_cost' => true, 'daily_quota' => 1]);
        $this->fake();
        $first = app(Engine::class)->dispatch($this->accept());
        $second = app(Engine::class)->dispatch($this->accept('synthetic-second-zero-key'));
        $this->assertSame('captured', $second->financial_status);
        $this->assertSame(0, $first->charged_irt + $second->charged_irt);
        Http::assertSentCount(2);
    }


    public function test_identity_token_issuance_is_bound_hashed_and_requires_an_owned_application(): void
    {
        $this->actingAs($this->customer, 'customer')->post('/account/security/api-token', [
            'name' => 'New identity key', 'abilities' => ['identity:execute', 'identity:authentication'],
            'identity_application_id' => $this->application->id, 'expires_days' => 1, 'cidrs' => '192.0.2.0/24',
        ])->assertRedirect()->assertSessionHas('new_token');
        $plain = session('new_token');
        $key = CustomerApiToken::orderByDesc('id')->first();
        $this->assertSame(hash('sha256', $plain), $key->token_hash);
        $this->assertSame($this->application->id, $key->identity_application_id);
        $this->get('/account/security')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $before = CustomerApiToken::count();
        $this->post('/account/security/api-token', ['name' => 'Unbound', 'abilities' => ['identity:execute'], 'daily_spend_cap_irt' => 1000])->assertRedirect()->assertSessionHasErrors();
        $this->assertSame($before, CustomerApiToken::count());
    }

    public function test_runtime_requires_wallet_hold_exclusion_and_explicit_identity_scopes(): void
    {
        $this->assertTrue(app(Controls::class)->runtimeReady());
    }

    public function test_identity_token_limit_update_is_owned_and_does_not_reveal_the_key(): void
    {
        $this->actingAs($this->customer, 'customer')->post('/account/identity-api/keys/'.$this->token->id, [
            'daily_spend_cap_irt' => 500, 'cidrs' => '192.0.2.1', 'expires_days' => 1,
        ])->assertRedirect();
        $this->assertSame(100000, (int) $this->token->fresh()->daily_spend_cap_irt); // Shared non-identity budget is not rewritten.
        $this->assertFalse($this->token->fresh()->allowsIp('198.51.100.1'));
        $this->assertFalse(session()->has('new_token'));
    }

    private function enableBalanceSync(): Controls
    {
        config(['identity_api.balance_token' => 'synthetic-read-only-financial-key']);
        $controls = app(Controls::class);
        $controls->put('balance_sync_enabled', true, 1);

        return $controls;
    }

    public function test_balance_sync_reads_real_endpoint_in_rials_and_preserves_all_budgets_and_customer_records(): void
    {
        $this->freezeTime();
        $c = $this->enableBalanceSync();
        foreach (['provider_daily_cap_irt' => 100000, 'internal_kyc_reserve_irt' => 200000, 'provider_concurrency' => 1, 'default_customer_cap_irt' => 25000] as $key => $value) {
            $c->put($key, $value, 1);
        }
        $c->put('upstream_balance_verified_at', now()->subMinutes(50)->toIso8601String(), 1);
        $counts = [IdentityRequest::count(), IdentityApplication::count(), CustomerApiToken::count(), CreditEntry::count(), BusinessEntry::count()];
        Http::fake(['service.zohal.io/api/v0/financial/wallet/' => Http::response(['balance' => 4757809], 200)]);
        $this->artisan('identity:sync-balance')->expectsOutput('balance_sync=updated')->assertSuccessful();
        $this->assertSame(475780, $c->get('upstream_balance_irt'));
        $this->assertSame(now()->startOfSecond()->toIso8601String(), $c->get('upstream_balance_verified_at'));
        $this->assertSame('ok', $c->get('balance_sync_state')['status']);
        $this->assertTrue($c->fundingReady());
        foreach (['provider_daily_cap_irt' => 100000, 'internal_kyc_reserve_irt' => 200000, 'provider_concurrency' => 1, 'default_customer_cap_irt' => 25000] as $key => $value) {
            $this->assertSame($value, $c->get($key));
        }
        $this->assertSame($counts, [IdentityRequest::count(), IdentityApplication::count(), CustomerApiToken::count(), CreditEntry::count(), BusinessEntry::count()]);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://service.zohal.io/api/v0/financial/wallet/'
            && $r->hasHeader('Authorization', 'Bearer synthetic-read-only-financial-key') && $r->data() === []);
        $this->assertSame('not_due', app(BalanceSync::class)->refreshIfDue());
        Http::assertSentCount(1);
        $this->travel(6)->minutes();
        $this->artisan('identity:reconcile')->assertSuccessful();
        $this->assertSame(now()->startOfSecond()->toIso8601String(), $c->get('upstream_balance_verified_at'));
        Http::assertSentCount(2);
        for ($i = 0; $i < 12; $i++) {
            $this->travel(6)->minutes();
            $this->artisan('identity:reconcile')->assertSuccessful();
            $this->assertTrue($c->fundingReady());
        }
        Http::assertSentCount(14);
        $this->assertSame(100000, $c->get('provider_daily_cap_irt'));
        $this->assertSame(200000, $c->get('internal_kyc_reserve_irt'));
    }

    public static function invalidBalanceResponses(): array
    {
        return [
            'unauthorized' => [401, '{"detail":"Unauthorized"}', 'application/json'],
            'rate limited' => [429, '{"balance":4757800}', 'application/json'],
            'server failure' => [500, '{"balance":4757800}', 'application/json'],
            'redirect' => [302, '{"balance":4757800}', 'application/json'],
            'HTML login' => [200, '<html>Login</html>', 'text/html'],
            'missing balance' => [200, '{}', 'application/json'],
            'negative' => [200, '{"balance":-1}', 'application/json'],
            'float' => [200, '{"balance":4757800.0}', 'application/json'],
            'exponent string' => [200, '{"balance":"4e6"}', 'application/json'],
            'boolean' => [200, '{"balance":true}', 'application/json'],
            'unbounded' => [200, '{"balance":1000000000001}', 'application/json'],
            'wrong unit' => [200, '{"balance":4757800,"currency":"IRT"}', 'application/json'],
            'error envelope' => [200, '{"balance":4757800,"result":5}', 'application/json'],
            'error details' => [200, '{"balance":4757800,"detail":"invalid"}', 'application/json'],
        ];
    }

    #[DataProvider('invalidBalanceResponses')]
    public function test_failed_balance_observation_never_extends_the_previous_evidence(int $status, string $body, string $type): void
    {
        $this->freezeTime();
        $c = $this->enableBalanceSync();
        $at = now()->subMinutes(61)->toIso8601String();
        $c->put('upstream_balance_verified_at', $at, 1);
        $before = $c->get('upstream_balance_irt');
        Http::fake(['service.zohal.io/api/v0/financial/wallet/' => Http::response($body, $status, ['Content-Type' => $type])]);
        $this->artisan('identity:sync-balance')->expectsOutput('balance_sync=failed')->assertFailed();
        $this->assertSame($at, $c->get('upstream_balance_verified_at'));
        $this->assertSame($before, $c->get('upstream_balance_irt'));
        $this->assertFalse($c->fundingReady());
        $this->assertSame('failed', $c->get('balance_sync_state')['status']);
        Http::assertSentCount(1);
    }

    public function test_balance_sync_keeps_zero_as_a_real_observation_and_closes_admission(): void
    {
        $this->freezeTime();
        $c = $this->enableBalanceSync();
        $this->travel(1)->minutes();
        Http::fake(['service.zohal.io/api/v0/financial/wallet/' => Http::response(['balance' => 0], 200)]);
        $this->assertSame('updated', app(BalanceSync::class)->refreshIfDue());
        $this->assertSame(0, $c->get('upstream_balance_irt'));
        $this->assertSame(now()->startOfSecond()->toIso8601String(), $c->get('upstream_balance_verified_at'));
        $this->assertFalse($c->fundingReady());
        $this->expectExceptionMessage('provider_unavailable');
        $this->accept();
    }

    public function test_balance_sync_requires_separate_financial_access_and_never_uses_the_inquiry_key(): void
    {
        app(Controls::class)->put('balance_sync_enabled', true, 1);
        config(['identity_api.balance_token' => null]);
        $this->assertSame('not_configured', app(BalanceSync::class)->refreshIfDue());
        Http::assertNothingSent();
        $this->assertNull(app(Controls::class)->get('balance_sync_state'));
    }

    public function test_balance_sync_is_opt_in_and_network_failure_retains_the_previous_snapshot(): void
    {
        $this->freezeTime();
        config(['identity_api.balance_token' => 'synthetic-read-only-financial-key']);
        $this->assertSame('disabled', app(BalanceSync::class)->refreshIfDue());
        Http::assertNothingSent();
        $c = $this->enableBalanceSync();
        $before = [$c->get('upstream_balance_irt'), $c->get('upstream_balance_verified_at')];
        Http::fake(['service.zohal.io/api/v0/financial/wallet/' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Synthetic private exception must not be logged');
        }]);
        $this->assertSame('failed', app(BalanceSync::class)->refreshIfDue());
        $this->assertSame($before, [$c->get('upstream_balance_irt'), $c->get('upstream_balance_verified_at')]);
        $this->assertSame('request_failed', $c->get('balance_sync_state')['error']);
    }

    public function test_admin_cannot_reintroduce_customer_daily_spending_caps(): void
    {
        $u = User::factory()->create(['role' => 'admin']);
        $this->actingAs($u, 'web')->postJson('/admin/identity-api/control', ['key' => 'default_customer_cap_irt', 'value' => '100'])->assertStatus(422);
        $this->postJson('/admin/identity-api/control', ['key' => 'customer_cap:'.$this->customer->id, 'value' => '100'])->assertStatus(422);
        $this->postJson('/admin/identity-api/customers', ['customer_id' => $this->customer->id, 'cap_irt' => 100])->assertStatus(410);
        foreach (['', 'en/', 'tr/'] as $prefix) {
            $this->get('/'.$prefix.'admin/identity-api')->assertOk()->assertDontSee('default_customer_cap_irt')->assertDontSee('customer_cap:')->assertDontSee('name="daily_quota"', false);
        }
        Http::assertNothingSent();
    }


    public function test_balance_sync_does_not_overlap_or_override_a_newer_observation(): void
    {
        $this->freezeTime();
        $c = $this->enableBalanceSync();
        $lock = \Illuminate\Support\Facades\Cache::lock('identity:balance-sync', 45);
        $this->assertTrue($lock->get());
        $this->assertSame('busy', app(BalanceSync::class)->refreshIfDue(true));
        Http::assertNothingSent();
        $lock->release();
        Http::fake(['service.zohal.io/api/v0/financial/wallet/' => function () use ($c) {
            $this->travel(1)->seconds();
            $c->put('upstream_balance_verified_at', now()->toIso8601String(), 1);
            $c->put('upstream_balance_irt', 450000, 1);

            return Http::response(['balance' => 9999999], 200);
        }]);
        $this->assertSame('superseded', app(BalanceSync::class)->refreshIfDue(true));
        $this->assertSame(450000, $c->get('upstream_balance_irt'));
        $this->assertSame(now()->toIso8601String(), $c->get('upstream_balance_verified_at'));
    }

    public function test_refresh_cannot_discard_an_old_request_still_waiting_for_dispatch(): void
    {
        $this->freezeTime();
        $c = $this->enableBalanceSync();
        $first = $this->accept();
        $first->forceFill(['created_at' => now()->subMinutes(10)])->save();
        $this->travel(6)->minutes();
        Http::fake(['service.zohal.io/api/v0/financial/wallet/' => Http::response(['balance' => 15000], 200)]);
        $this->assertSame('updated', app(BalanceSync::class)->refreshIfDue());
        $this->expectExceptionMessage('provider_unavailable');
        $this->accept('synthetic-second-old-hold');
    }

    public function test_settlement_racing_a_balance_read_remains_part_of_provider_exposure(): void
    {
        $this->freezeTime();
        $c = $this->enableBalanceSync();
        $first = $this->accept();
        $first->forceFill(['created_at' => now()->subMinutes(10), 'dispatch_at' => now()->subMinute()])->save();
        $this->travel(1)->minutes();
        Http::fake(['service.zohal.io/api/v0/financial/wallet/' => function () use ($first) {
            $this->travel(1)->seconds();
            $first->forceFill(['financial_status' => 'captured', 'settled_at' => now()])->save();

            return Http::response(['balance' => 15000], 200);
        }]);
        $this->assertSame('updated', app(BalanceSync::class)->refreshIfDue());
        $this->expectExceptionMessage('provider_unavailable');
        $this->accept('synthetic-second-racing-charge');
    }

    public function test_an_expired_dispatched_request_is_retained_until_reconciliation_records_its_settlement(): void
    {
        $this->freezeTime();
        $c = $this->enableBalanceSync();
        $first = $this->accept();
        $first->forceFill(['created_at' => now()->subMinutes(20), 'dispatch_at' => now()->subMinutes(19),
            'decide_by' => now()->subSecond(), 'financial_status' => 'pending_reconciliation', 'settled_at' => null])->save();
        $this->travel(1)->minutes();
        Http::fake(['service.zohal.io/api/v0/financial/wallet/' => Http::response(['balance' => 15000], 200)]);
        $this->assertSame('updated', app(BalanceSync::class)->refreshIfDue());
        $this->expectExceptionMessage('provider_unavailable');
        $this->accept('synthetic-second-expired-unknown');
    }

    public function test_an_old_settled_charge_already_in_the_provider_snapshot_is_not_reserved_twice(): void
    {
        $this->freezeTime();
        $c = $this->enableBalanceSync();
        $first = $this->accept();
        $first->forceFill(['created_at' => now()->subMinutes(10), 'dispatch_at' => now()->subMinutes(5),
            'financial_status' => 'captured', 'settled_at' => now()->subMinute()])->save();
        $this->travel(1)->minutes();
        Http::fake(['service.zohal.io/api/v0/financial/wallet/' => Http::response(['balance' => 15000], 200)]);
        $this->assertSame('updated', app(BalanceSync::class)->refreshIfDue());
        $this->assertSame('reserved', $this->accept('synthetic-second-accounted-charge')->fresh()->financial_status);
        Http::assertSentCount(1);
    }

    public function test_a_funded_customer_can_spend_beyond_all_legacy_daily_caps_and_count_limits(): void
    {
        $c = app(Controls::class);
        $c->put('default_customer_cap_irt', 25000, 1);
        $c->put('customer_cap:'.$this->customer->id, 0, 1);
        $this->application->update(['daily_cap_irt' => 1]);
        $this->token->update(['daily_spend_cap_irt' => 1]);
        $this->price(['daily_quota' => 1]);
        $this->fake();
        for ($i = 0; $i < 30; $i++) {
            $r = app(Engine::class)->dispatch($this->accept('synthetic-wallet-only-'.str_pad((string)$i, 4, '0', STR_PAD_LEFT)));
            $this->assertSame('captured', $r->financial_status);
            $this->travel(4)->seconds();
        }
        $this->assertSame(34650, (int) IdentityRequest::sum('charged_irt'));
        $this->assertSame(65350, app(Wallet::class)->availableOf($this->customer->id));
        Http::assertSentCount(30);
    }

    public function test_wallet_only_policy_still_honors_provider_funding_and_abuse_protections(): void
    {
        app(Controls::class)->put('provider_daily_cap_irt', 1000, 1);
        $this->expectExceptionMessage('provider_unavailable');
        $this->accept();
    }

    public function test_identity_applications_and_keys_need_no_spending_budget_in_all_customer_forms(): void
    {
        $this->actingAs($this->customer, 'customer')->post('/account/identity-api/applications', ['name' => 'Wallet funded', 'accept' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $a = IdentityApplication::latest('id')->firstOrFail();
        $this->assertSame(0, $a->daily_cap_irt);
        $this->post('/account/identity-api/applications/'.$a->id, ['status' => 'disabled'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('disabled', $a->fresh()->status);
        foreach (['' => 'fa', 'en/' => 'en', 'tr/' => 'tr'] as $prefix => $locale) {
            $this->get('/'.$prefix.'account/identity-api')->assertOk()->assertSee(__('identity.wallet_only', [], $locale))
                ->assertDontSee('name="daily_cap_irt"', false)->assertDontSee('name="daily_spend_cap_irt"', false);
            $this->get('/'.$prefix.'account/security')->assertOk()->assertDontSee('name="daily_spend_cap_irt"', false);
        }
        Http::assertNothingSent();
    }
}
