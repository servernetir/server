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

    public function test_customer_budget_is_shared_across_applications(): void
    {
        app(Controls::class)->put('customer_cap:'.$this->customer->id, 2000, 1);
        $this->accept();
        $other = IdentityApplication::create(['customer_id' => $this->customer->id, 'name' => 'second', 'terms_version' => '2026-10-17', 'accepted_at' => now(), 'daily_cap_irt' => 10000]);
        $this->expectExceptionMessage('budget_exceeded');
        app(Engine::class)->accept($other, null, 'shahkar-inquiry', $this->payload(), 'synthetic-other-app-key', 2000);
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
                ->assertSee('1,653')->assertSee(__('identity.price_billing_pending', [], $locale))
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

    public function test_confirmed_zero_cost_still_has_a_daily_quota(): void
    {
        $this->price(['source_amount' => 0, 'confirmed_zero_cost' => true, 'daily_quota' => 1]);
        $this->fake();
        $r = app(Engine::class)->dispatch($this->accept());
        $this->assertSame('captured', $r->financial_status);
        $this->assertSame(0, $r->charged_irt);
        $this->expectExceptionMessage('quota_exceeded');
        $this->accept('synthetic-second-zero-key');
    }

    public function test_identity_token_issuance_is_bound_hashed_and_requires_an_owned_application(): void
    {
        $this->actingAs($this->customer, 'customer')->post('/account/security/api-token', [
            'name' => 'New identity key', 'abilities' => ['identity:execute', 'identity:authentication'],
            'identity_application_id' => $this->application->id, 'daily_spend_cap_irt' => 1000, 'expires_days' => 1, 'cidrs' => '192.0.2.0/24',
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
        $this->assertSame(500, (int) $this->token->fresh()->daily_spend_cap_irt);
        $this->assertFalse($this->token->fresh()->allowsIp('198.51.100.1'));
        $this->assertFalse(session()->has('new_token'));
    }
}
