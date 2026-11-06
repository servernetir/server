<?php

namespace Tests\Feature;

use App\Models\{AiUsage, Customer, CustomerApiToken, IdentityApplication, IdentityPrice, IdentityRequest, IdentityVerification, TaxRate};
use App\Services\Finance\Wallet;
use App\Services\IdentityApi\Controls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt, DB, Http, Schema};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

/** Render real authenticated GET routes; never execute a paid inquiry or AI call. */
class AccountApiWorkspacePresentationTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        Wallet::flushSchemaCache();
        $this->sellableCatalog();
        Http::preventStrayRequests();
        Http::fake();
    }

    public static function locales(): array
    {
        return [['fa'], ['en'], ['tr']];
    }

    #[DataProvider('locales')]
    public function test_identity_quote_and_application_cards_preserve_charge_consent_and_key_controls(string $locale): void
    {
        [$customer, $application, $token] = $this->identityCustomer($locale);
        $this->identityRow($customer, $application, 'completed-demo-receipt-123456789', 'captured', 1155);
        $before = $this->financialSnapshot();
        $html = $this->page($customer, $locale, 'identity-api?service=shahkar-inquiry');
        $xpath = $this->xpath($html);
        $this->assertWorkspace($html, 'identity');
        $this->assertSame(1, $xpath->query('//form[@action="'.lroute('account.identity.activate').'"]//input[@name="name" and @maxlength="80" and @required]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.lroute('account.identity.activate').'"]//input[@name="accept" and @type="checkbox" and @required]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.lroute('account.identity.application', $application).'"]//select[@name="status"]')->length);

        $form = $xpath->query('//form[@action="'.lroute('account.identity.inquire').'"]')->item(0);
        $this->assertNotNull($form);
        foreach (['service' => 'shahkar-inquiry', 'max_charge_irt' => '1155', 'price_version' => '1'] as $name => $value) {
            $this->assertSame($value, $xpath->query('.//input[@name="'.$name.'"]', $form)->item(0)?->getAttribute('value'));
        }
        $idempotency = $xpath->query('.//input[@name="idempotency_key"]', $form)->item(0);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $idempotency?->getAttribute('value') ?? '');
        $this->assertSame(1, $xpath->query('.//input[@name="real_charge" and @type="checkbox" and @value="1" and @required]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="input[mobile]" and @required]', $form)->length);
        $this->assertSame(1, $xpath->query('.//input[@name="input[national_code]" and @required]', $form)->length);
        $checkout = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," identity-checkout ")]', $form)->item(0);
        $this->assertNotNull($checkout);
        foreach (['1,050', '105', '1,155'] as $amount) {
            $this->assertStringContainsString($amount, $checkout->textContent);
        }

        $update = $xpath->query('//form[@action="'.lroute('account.identity.key-update', $token).'"]')->item(0);
        $this->assertNotNull($update);
        $this->assertSame('192.0.2.0/24', $xpath->query('.//input[@name="cidrs"]', $update)->item(0)?->getAttribute('value'));
        $this->assertSame('1825', $xpath->query('.//input[@name="expires_days"]', $update)->item(0)?->getAttribute('max'));
        $this->assertSame(1, $xpath->query('//form[@action="'.lroute('account.security.token.delete', $token).'"]')->length);
        $this->assertStringNotContainsString($token->token_hash, $html);
        $this->assertStringContainsString('identity:execute', $html);
        $this->assertEveryPostHasCsrf($xpath);
        $this->assertSame($before, $this->financialSnapshot());
        Http::assertNothingSent();
        $this->capture('identity', $locale, 'populated', $html);
    }

    #[DataProvider('locales')]
    public function test_identity_wallet_holds_are_separate_and_history_is_tenant_scoped(string $locale): void
    {
        [$customer, $application] = $this->identityCustomer($locale);
        $held = $this->identityRow($customer, $application, 'held', 'reserved', 0, 5000);
        $captured = $this->identityRow($customer, $application, 'captured', 'captured', 1155);
        [$other, $otherApplication] = $this->identityCustomer('en', 'Other tenant');
        $private = $this->identityRow($other, $otherApplication, 'private', 'captured', 7777);
        $before = $this->financialSnapshot();
        $html = $this->page($customer, $locale, 'identity-api?financial_status=captured');
        $this->assertWorkspace($html, 'identity');
        $visible = $this->xpath($html)->query('//*[contains(concat(" ",normalize-space(@class)," ")," api-workspace ")]')->item(0)->textContent;
        foreach (['100,000', '5,000', '95,000'] as $amount) {
            $this->assertStringContainsString($amount, $visible);
        }
        $this->assertStringContainsString($captured->public_id, $html);
        $this->assertStringNotContainsString($held->public_id, $html);
        $this->assertStringNotContainsString($private->public_id, $html);
        $filter = $this->xpath($html)->query('//select[@name="financial_status"]/option[@selected]')->item(0);
        $this->assertSame('captured', $filter?->getAttribute('value'));
        $this->assertSame($before, $this->financialSnapshot());
        Http::assertNothingSent();
    }

    #[DataProvider('locales')]
    public function test_identity_empty_and_verification_gate_keep_safe_actions_without_charge_form(string $locale): void
    {
        $customer = Customer::create(['email' => 'unverified-'.$locale.'@example.test', 'status' => 'active', 'locale' => $locale]);
        $before = $this->financialSnapshot();
        $html = $this->page($customer, $locale, 'identity-api');
        $xpath = $this->xpath($html);
        $this->assertWorkspace($html, 'identity');
        $this->assertStringContainsString(__('identity.kyc_required'), $html);
        $this->assertSame(0, $xpath->query('//form[@action="'.lroute('account.identity.activate').'"]')->length);
        $this->assertSame(0, $xpath->query('//form[@action="'.lroute('account.identity.inquire').'"]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.lroute('account.identity.sandbox').'"]')->length);
        $this->assertSame($before, $this->financialSnapshot());
        Http::assertNothingSent();
        $this->capture('identity', $locale, 'empty', $html);
    }

    #[DataProvider('locales')]
    public function test_ai_cards_use_the_actual_monthly_net_spend_and_keep_receipt_tax_and_refunds(string $locale): void
    {
        [$customer, , , $project] = $this->greenKey(100000, $locale, ['name' => 'Support project', 'slug' => 'support-ui']);
        $row = $this->usage($customer, ['ai_project_id' => $project->id, 'sell_irt' => 1200, 'tax_irt' => 120, 'charged_irt' => 1320, 'refunded_irt' => 220]);
        $this->usage($customer, ['status' => 'released', 'charged_irt' => 0, 'sell_irt' => null, 'tax_irt' => null]);
        $this->usage($customer, ['created_at' => now()->subMonth(), 'charged_irt' => 9000, 'sell_irt' => 8000, 'tax_irt' => 1000]);
        [$other] = $this->greenKey(0, 'en', ['name' => 'Private other project']);
        $private = $this->usage($other, ['charged_irt' => 777777]);
        $before = $this->financialSnapshot();
        $html = $this->page($customer, $locale, 'ai');
        $xpath = $this->xpath($html);
        $this->assertWorkspace($html, 'ai');
        $stats = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," aw-stats ")]')->item(0);
        $this->assertNotNull($stats);
        $this->assertStringContainsString((string) fa_num('1,100'), $stats->textContent);
        $this->assertStringContainsString('Support project', $html);
        $this->assertStringNotContainsString('Private other project', $html);
        $this->assertStringNotContainsString($private->public_id, $html);
        $rowNode = $xpath->query('//table[@data-ai-billing-receipts]//tr[td//code[text()="'.$row->public_id.'"]]')->item(0);
        $this->assertNotNull($rowNode);
        $cells = $xpath->query('./td', $rowNode);
        $this->assertCount(7, $cells);
        $this->assertSame('1,200', trim($cells->item(3)->textContent));
        $this->assertSame('120', trim($cells->item(4)->textContent));
        $this->assertSame('1,100', trim($cells->item(5)->textContent));
        $this->assertSame($before, $this->financialSnapshot());
        Http::assertNothingSent();
        $this->capture('ai', $locale, 'populated', $html);
    }

    #[DataProvider('locales')]
    public function test_ai_empty_dashboard_keeps_localized_setup_and_documentation_links(string $locale): void
    {
        $customer = Customer::create(['email' => 'ai-empty-'.$locale.'@example.test', 'status' => 'active', 'locale' => $locale]);
        $before = $this->financialSnapshot();
        $html = $this->page($customer, $locale, 'ai');
        $xpath = $this->xpath($html);
        $this->assertWorkspace($html, 'ai');
        foreach (['account.security', 'account.topup', 'ai.docs', 'ai.models'] as $route) {
            $this->assertGreaterThanOrEqual(1, $xpath->query('//a[starts-with(@href,"'.lroute($route).'")]')->length);
        }
        $this->assertStringContainsString(__('ai.no_projects'), $html);
        $this->assertStringContainsString(__('ai.no_usage'), $html);
        $this->assertSame($before, $this->financialSnapshot());
        Http::assertNothingSent();
        $this->capture('ai', $locale, 'empty', $html);
    }

    public function test_workspace_names_are_escaped_and_receipts_remain_paginated(): void
    {
        [$customer] = $this->greenKey(0, 'en', ['name' => '<script>bad-project</script>'.str_repeat('a', 80)]);
        for ($i = 0; $i < 23; $i++) {
            $this->usage($customer, ['charged_irt' => 100, 'model_slug' => str_repeat('long-model-', 8)]);
        }
        $html = $this->page($customer, 'en', 'ai');
        $xpath = $this->xpath($html);
        $this->assertStringNotContainsString('<script>bad-project</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;bad-project&lt;/script&gt;', $html);
        $this->assertSame(20, $xpath->query('//table[@data-ai-billing-receipts]/tbody/tr')->length);
        $this->assertStringContainsString('page=2', $html);
        Http::assertNothingSent();
        $this->capture('ai', 'en', 'long', $html);
    }

    private function identityCustomer(string $locale, string $name = 'Identity workspace'): array
    {
        $customer = Customer::create(['email' => uniqid('identity-').'@example.test', 'status' => 'active', 'locale' => $locale]);
        IdentityVerification::create(['customer_id' => $customer->id, 'status' => 'verified',
            'national_id_enc' => Crypt::encryptString('synthetic'), 'national_id_hash' => hash('sha256', uniqid()),
            'birth_date' => '2000-01-01', 'mobile' => '00000000000']);
        $application = IdentityApplication::create(['customer_id' => $customer->id, 'name' => $name,
            'daily_cap_irt' => 100000, 'terms_version' => '2026-10-17', 'accepted_at' => now()]);
        [$token] = CustomerApiToken::issue($customer->id, 'Development access', ['identity:execute', 'identity:requests:read'], ['192.0.2.0/24'], null, null, $application->id);
        app(Wallet::class)->credit($customer->id, 'IRT', 100000, 'topup', $customer);
        TaxRate::query()->delete();
        TaxRate::create(['country' => 'IR', 'product_kind' => null, 'name' => 'Test tax', 'rate_bp' => 1000, 'priority' => 1, 'is_active' => true]);
        foreach (['enabled' => true, 'egress_verified' => true, 'resale_evidence' => 'synthetic',
            'service:shahkar-inquiry' => true, 'provider_daily_cap_irt' => 1000000,
            'upstream_balance_irt' => 1000000, 'upstream_balance_verified_at' => now()->toIso8601String()] as $key => $value) {
            app(Controls::class)->put($key, $value, 1);
        }
        config(['identity_api.token' => 'synthetic-only']);
        if (!IdentityPrice::exists()) {
            IdentityPrice::create(['service' => 'shahkar-inquiry', 'source_amount' => 1000, 'source_currency' => 'IRT',
                'purchase_tax_included' => true, 'purchase_tax_amount' => 0, 'markup_bps' => 500, 'daily_quota' => 100,
                'billing_policy' => 'valid_data_v1', 'evidence' => 'test', 'access_evidence' => 'test',
                'effective_at' => now(), 'valid_until' => now()->addDay()]);
        }
        return [$customer, $application, $token];
    }

    private function identityRow(Customer $customer, IdentityApplication $application, string $key, string $state, int $charged, int $hold = 0): IdentityRequest
    {
        return IdentityRequest::create(['customer_id' => $customer->id, 'application_id' => $application->id,
            'public_id' => 'synthetic-'.$key, 'service' => 'shahkar-inquiry', 'key_hash' => hash('sha256', $key),
            'fingerprint' => hash('sha256', $key), 'processing_status' => $state === 'captured' ? 'completed' : 'accepted',
            'financial_status' => $state, 'charged_irt' => $charged, 'hold_irt' => $hold,
            'quote' => ['version' => 1, 'sell_irt' => 1050, 'tax_irt' => 105], 'decide_by' => now()->addHour(),
            'retain_until' => now()->addDay()]);
    }

    private function usage(Customer $customer, array $over = []): AiUsage
    {
        return AiUsage::create($over + ['customer_id' => $customer->id, 'ai_provider_id' => $this->provider->id, 'ai_model_id' => $this->model->id,
            'model_slug' => $this->model->slug, 'upstream_model' => $this->model->upstream_model,
            'request_sha256' => str_repeat('0', 64), 'status' => AiUsage::STATUS_SETTLED,
            'max_input_tokens' => 100, 'max_output_tokens' => 100, 'prompt_tokens' => 70, 'completion_tokens' => 25,
            'price_currency' => 'USD', 'input_price_id' => 1, 'output_price_id' => 1, 'input_rate_micro' => 1, 'output_rate_micro' => 1,
            'fx_rate_toman' => 100000, 'fx_source' => 'scraped', 'fee_bp' => 0, 'margin_bp' => 1, 'vat_bp' => 0,
            'vat_basis' => 'fa_locale', 'p_input_irt_m' => 1, 'p_cached_irt_m' => 1, 'p_output_irt_m' => 1,
            'hold_sell_irt' => 0, 'hold_tax_irt' => 0, 'hold_irt' => 0, 'decide_by' => now()->addHour()]);
    }

    private function page(Customer $customer, string $locale, string $path): string
    {
        return $this->actingAs($customer, 'customer')->get(($locale === 'fa' ? '' : '/'.$locale).'/account/'.$path)->assertOk()->getContent();
    }

    private function assertWorkspace(string $html, string $kind): void
    {
        $this->assertStringContainsString('assets/css/account-api.css', $html);
        $this->assertStringContainsString('api-workspace--'.$kind, $html);
        $this->assertStringNotContainsString('identity.workspace_', $html);
        $this->assertStringNotContainsString('ai.workspace_', $html);
        $workspace = $this->xpath($html)->query('//*[contains(concat(" ",normalize-space(@class)," ")," api-workspace ")]')->item(0);
        $this->assertNotNull($workspace);
        $this->assertDoesNotMatchRegularExpression('/\b(?:identity|ai)\.[a-z_]+/', $workspace->textContent);
    }

    private function assertEveryPostHasCsrf(\DOMXPath $xpath): void
    {
        foreach ($xpath->query('//form[translate(@method,"POST","post")="post"]') as $form) {
            $token = $xpath->query('.//input[@name="_token"]', $form)->item(0);
            $this->assertNotNull($token);
            $this->assertNotSame('', $token->getAttribute('value'));
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        return new \DOMXPath($dom);
    }

    private function financialSnapshot(): array
    {
        return collect(['credit_ledger', 'business_entries', 'ai_usage', 'ai_reservations', 'identity_requests', 'identity_applications', 'customer_api_tokens'])
            ->filter(fn ($table) => Schema::hasTable($table))
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function capture(string $kind, string $locale, string $state, string $html): void
    {
        if (getenv('API_UI_CAPTURE') !== '1') { return; }
        $dir = base_path('tmp/api-ui-preview');
        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
        file_put_contents($dir.'/'.$kind.'-'.$locale.'-'.$state.'.html', $html);
    }
}
