<?php

namespace Tests\Feature;

use App\Services\Cloud\CloudManager;
use App\Services\Cloud\VpsTcPreflight;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcMoneyContract;
use App\Models\Setting;
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
            'api.vps.tc/api/category/1/product' => Http::response(['products' => []]),
            'api.vps.tc/api/payment' => Http::response(['payments' => ['120' => 'Credit Balance']]),
            'api.vps.tc/api/payment/fees' => Http::response(['payments' => [['id' => 120, 'fixed_fee' => '0', 'percent_fee' => '0']]]),
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
        $this->assertSame('api_balance', $report['currency_source']);
        $this->assertSame(1, $report['categories_seen']);
        $this->assertSame(0, $report['products_seen']);
        $this->assertTrue($report['discovery_complete']);
        $this->assertTrue($report['payment_fees_verified']);
        $this->assertContains('traffic_cost_control_not_verified', $report['blockers']);
        $json = json_encode($report);
        foreach (['fixture-user', 'fixture-password', 'fixture-token', 'fixture-refresh', 'upstream-credential', '<script>', 'acc_credit'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertArrayHasKey('vpstc', CloudManager::DRIVERS);
        $this->assertFalse(app(\App\Services\Cloud\VpsTcSales::class)->enabled());
        Http::assertSentCount(6);
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
        Http::assertSentCount(7);
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

    private function product(int $id, string $paytype = 'Regular', string $period = 'm', mixed $price = '125.55'): array
    {
        return ['id' => $id, 'name' => 'Fixture server '.$id, 'paytype' => $paytype,
            'periods' => [['value' => $period, 'price' => $price, 'setup' => 0, 'formatted' => '₺125.55  Monthly']]];
    }

    private function form(int $id, int $category, string $paytype = 'Regular', mixed $price = '125.55', mixed $setup = 0): array
    {
        return ['product' => ['id' => $id, 'category_id' => $category, 'billingtype' => 'PrePay', 'paytype' => $paytype,
            'config' => ['forms' => [], 'product' => [['id' => 'cycle', 'value' => 'm', 'items' => [
                ['value' => 'm', 'price' => $price, 'setup' => $setup, 'formatted' => '₺125.55  Monthly'],
            ]]]]]];
    }

    private function nativeQuote(int $id, array $changes = []): array
    {
        return ['summary' => array_replace(['subtotal' => '125.55',
            'tax' => [['name' => 'KDV', 'tax' => '25.11', 'value' => '20']],
            'total' => '0', 'credit' => '150.66', 'grandtotal' => '150.66', 'cost' => '125.55', 'setup_fee' => '0'], $changes),
            'items' => [['type' => 'product', 'product_id' => $id, 'valid' => true]]];
    }

    private function vpsCategory(int $count = 16, array $extra = []): void
    {
        $this->fixtures($extra + [
            'api.vps.tc/api/category' => Http::response(['categories' => [['id' => 55, 'name' => 'Server Services', 'subcategories' => [
                ['id' => 56, 'name' => 'VPS'], ['id' => 71, 'name' => 'Virtual Datacenter'], ['id' => 59, 'name' => 'Dedicated Server'],
            ]]]]),
            'api.vps.tc/api/category/56/product' => Http::response(['products' => array_map(
                fn ($id) => $this->product($id), range(351, 350 + $count))]),
            'api.vps.tc/api/order/*' => fn ($request) => Http::response($this->form((int) basename($request->url()), 56, price: '2.79')),
            'api.vps.tc/api/quote' => fn ($request) => Http::response($this->nativeQuote((int) $request['items'][0]['product_id'])),
        ], currency: 'TRY');
    }

    private function anchorInvoice(): array
    {
        return ['id' => '91', 'status' => 'Paid', 'currency' => 'TRY', 'rate' => '45',
            'subtotal' => '125.55', 'tax' => '25.11', 'tax2' => '0', 'grandtotal' => '150.66',
            'total' => '0', 'credit' => '150.66', 'discounts' => [],
            'items' => [['type' => 'Hosting', 'item_id' => '301', 'qty' => '1']]];
    }

    private function moneyQuote(int $product): array
    {
        return ['summary' => ['subtotal' => '2.79', 'cost' => '2.79', 'setup_fee' => '0', 'total' => '0', 'credit' => '3.348',
            'tax' => [['name' => 'KDV', 'tax' => 0.5579999999999999, 'value' => '20']],
            'recurring' => [['value' => 'm', 'price' => '3.348']]],
            'items' => [['valid' => true, 'error' => [], 'product' => ['id' => (string) $product,
                'price' => '2.79', 'setup' => '0', 'domain' => 'preflight-'.$product.'.example.invalid', 'recurring' => 'm',
                'config' => ['forms' => [['id' => '7', 'value' => ['11' => '2'],
                    'price' => '0', 'recurring_price' => '0', 'setup' => '0']],
                    'product' => [['id' => 'cycle', 'value' => 'm', 'price' => '2.79', 'setup' => '0']]]]]]];
    }

    private function moneyFixtures(int $count = 1, array $extra = []): void
    {
        $invoice = $this->anchorInvoice();
        $service = ['id' => '301', 'name' => 'Reviewed monthly VPS', 'billingcycle' => 'Monthly', 'date_created' => '2026-01-01'];
        $proof = ['version' => 1, 'account_fingerprint' => app(VpsTcApi::class)->accountFingerprint(),
            'base_currency' => 'USD', 'account_currency' => 'TRY', 'multiplier' => '45', 'payment_method' => '120',
            'source' => ['kind' => 'reviewed_portal_currency_table', 'url' => 'https://www.vps.tc/en/vps',
                'product_id' => '351', 'base_price' => '2.79', 'native_price' => '125.55',
                'sha256' => str_repeat('a', 64), 'reviewed_at' => now()->toIso8601String()],
            'anchor' => ['invoice_id' => '91', 'service_id' => '301', 'product_id' => '351', 'cycle' => 'm',
                'base_price' => '2.79', 'tax_percent' => '20',
                'financial_sha256' => VpsTcMoneyContract::financialFingerprint($invoice),
                'service_sha256' => VpsTcMoneyContract::serviceFingerprint($service)]];
        Setting::putSecret(VpsTcMoneyContract::KEY, json_encode($proof, JSON_THROW_ON_ERROR));
        $this->vpsCategory($count, $extra + [
            'api.vps.tc/api/balance' => Http::response(['details' => ['currency' => '', 'acc_credit' => 10, 'acc_balance' => 0]]),
            'api.vps.tc/api/invoice/91' => Http::response(['invoice' => $invoice]),
            'api.vps.tc/api/service/301' => Http::response(['service' => $service]),
            'api.vps.tc/api/quote' => fn ($request) => Http::response($this->moneyQuote((int) $request['items'][0]['product_id'])),
            'api.vps.tc/api/payment/fees' => Http::response(['payments' => [['id' => 120, 'fixed_fee' => 'Array', 'percent_fee' => 'Array']]]),
        ]);
    }

    public function test_optional_fresh_money_evidence_resolves_only_blank_currency_and_keeps_raw_columns_separate(): void
    {
        $this->moneyFixtures();
        $before = Setting::query()->pluck('value', 'key')->all();
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $candidate = $report['candidates'][0];
        $this->assertSame('TRY', $report['currency']);
        $this->assertSame('reviewed_account_contract', $report['currency_source']);
        $this->assertSame(1, $report['money_quotes_verified']);
        $this->assertFalse($report['raw_unit_verified']);
        $this->assertSame('125.55', $candidate['monthly_price_raw']);
        $this->assertSame('2.79', $candidate['order_monthly_raw']);
        $this->assertFalse($candidate['price_match']);
        $this->assertTrue($candidate['price_normalized_match']);
        $this->assertTrue($candidate['raw_unit_verified']);
        $this->assertSame('TRY', $candidate['catalog_raw_unit']);
        $this->assertSame('USD', $candidate['order_raw_unit']);
        $this->assertSame('3.348', $candidate['quote_diagnostics']['gross_raw']);
        $this->assertSame('USD', $candidate['quote_diagnostics']['unit']);
        $money = $candidate['money_evidence'];
        $this->assertSame('TRY', $money['currency']);
        $this->assertSame('125.55', $money['monthly_amount']);
        $this->assertSame('150.66', $money['total_amount']);
        $this->assertSame('20', $money['tax_percent']);
        $this->assertSame('45', $money['multiplier']);
        $this->assertTrue($money['calculation_zero_extras_verified']);
        $this->assertFalse($money['configuration_verified']);
        $this->assertFalse($money['all_os_prices_verified']);
        $this->assertFalse($money['funding_costs_verified']);
        $this->assertFalse($report['payment_fees_verified']);
        $this->assertContains('payment_fees_not_verified', $report['blockers']);
        foreach (['monthly_price_mismatch', 'account_currency_not_verified', 'raw_price_units_not_verified', 'quote_raw_unit_not_verified'] as $blocker) {
            $this->assertNotContains($blocker, $report['blockers']);
        }
        foreach (['vpstc_currency', 'vpstc_supplier_tax_pct', 'cloud_margin_pct_vpstc', 'pricing_fx_fee_pct_vpstc', 'vpstc_fx_rate_toman'] as $key) {
            $this->assertNull(Setting::get($key));
        }
        $this->assertSame($before, Setting::query()->pluck('value', 'key')->all());
        $this->assertFalse($report['sales_ready']);
        $this->assertFalse($report['catalog_complete']);
        $this->assertDatabaseCount('cloud_plans', 0);
        $this->assertDatabaseCount('cloud_provider_operations', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && ! in_array($request->url(), ['https://api.vps.tc/api/login', 'https://api.vps.tc/api/quote'], true));
        $this->assertStringNotContainsString('fixture-password', json_encode($report));
        $this->assertStringNotContainsString('invoice_id', json_encode($money));
    }

    public function test_money_diagnostics_without_reviewed_evidence_never_guess_from_the_raw_price_ratio(): void
    {
        $this->vpsCategory(1, [
            'api.vps.tc/api/balance' => Http::response(['details' => ['currency' => '', 'acc_credit' => 10, 'acc_balance' => 0]]),
            'api.vps.tc/api/quote' => Http::response($this->moneyQuote(351)),
        ]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $this->assertNull($report['currency']);
        $this->assertSame(0, $report['money_quotes_verified']);
        $this->assertNull($report['candidates'][0]['money_evidence']);
        $this->assertNull($report['candidates'][0]['order_raw_unit']);
        $this->assertContains('monthly_price_mismatch', $report['blockers']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/invoice/') || str_contains($request->url(), '/service/'));
    }

    public function test_all_sixteen_fresh_calculations_resolve_numeric_mismatches_only_in_the_checked_scope(): void
    {
        $this->moneyFixtures(16);
        $before = Setting::query()->pluck('value', 'key')->all();
        $report = app(VpsTcPreflight::class)->inspect(null, '56');
        $this->assertSame(16, $report['order_forms_checked']);
        $this->assertSame(16, $report['quotes_checked']);
        $this->assertSame(16, $report['money_quotes_verified']);
        $this->assertNotContains('monthly_price_mismatch', $report['blockers']);
        $this->assertFalse($report['raw_unit_verified']);
        $this->assertFalse($report['payment_fees_verified']);
        $this->assertFalse($report['sales_ready']);
        foreach ($report['candidates'] as $candidate) {
            $this->assertFalse($candidate['price_match']);
            $this->assertTrue($candidate['price_normalized_match']);
            $this->assertFalse($candidate['money_evidence']['configuration_verified']);
            $this->assertContains('monthly_price_mismatch', $candidate['resolved_issues']);
        }
        // One currency anchor read and one fresh verification per calculation.
        $this->assertCount(17, Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/invoice/91')));
        $this->assertSame($before, Setting::query()->pluck('value', 'key')->all());
        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && ! in_array($request->url(), ['https://api.vps.tc/api/login', 'https://api.vps.tc/api/quote'], true));
        $this->assertDatabaseCount('cloud_plans', 0);
        $this->assertDatabaseCount('cloud_provider_operations', 0);
    }

    public function test_unquoted_candidate_keeps_price_and_unit_blockers_after_sixteen_successes(): void
    {
        $this->moneyFixtures(17);
        $report = app(VpsTcPreflight::class)->inspect(null, '56');
        $this->assertSame(17, $report['order_forms_checked']);
        $this->assertSame(16, $report['quotes_checked']);
        $this->assertSame(16, $report['money_quotes_verified']);
        $unquoted = collect($report['candidates'])->firstWhere('id', '367');
        $this->assertFalse($unquoted['price_match']);
        $this->assertFalse($unquoted['quote_checked']);
        $this->assertNull($unquoted['price_normalized_match']);
        $this->assertNull($unquoted['money_evidence']);
        $this->assertContains('monthly_price_mismatch', $report['blockers']);
        $this->assertContains('raw_price_units_not_verified', $report['blockers']);
        $this->assertContains('quote_read_limit_reached', $report['blockers']);
        $this->assertFalse($report['quote_diagnostics_complete']);
        $this->assertFalse($report['sales_ready']);
    }

    public function test_changed_fresh_anchor_invalidates_optional_currency_and_candidate_unit_evidence(): void
    {
        $this->moneyFixtures(extra: ['api.vps.tc/api/invoice/91' => Http::response(['invoice' =>
            array_replace($this->anchorInvoice(), ['rate' => '46'])])]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $this->assertNull($report['currency']);
        $this->assertSame(0, $report['money_quotes_verified']);
        $this->assertNull($report['candidates'][0]['money_evidence']);
        $this->assertFalse($report['candidates'][0]['raw_unit_verified']);
        $this->assertContains('monthly_price_mismatch', $report['blockers']);
        $this->assertFalse($report['sales_ready']);
    }

    public function test_each_candidate_needs_fresh_money_success_and_one_success_cannot_clear_another_mismatch(): void
    {
        $valid = $this->anchorInvoice();
        $this->moneyFixtures(2, ['api.vps.tc/api/invoice/91' => Http::sequence()
            ->push(['invoice' => $valid])->push(['invoice' => $valid])
            ->push(['invoice' => array_replace($valid, ['rate' => '46'])])]);
        $report = app(VpsTcPreflight::class)->inspect(null, '56');
        $this->assertSame(2, $report['quotes_checked']);
        $this->assertSame(1, $report['money_quotes_verified']);
        $first = collect($report['candidates'])->firstWhere('id', '351');
        $second = collect($report['candidates'])->firstWhere('id', '352');
        $this->assertTrue($first['price_normalized_match']);
        $this->assertNull($second['price_normalized_match']);
        $this->assertNull($second['money_evidence']);
        $this->assertNull($second['order_raw_unit']);
        $this->assertContains('monthly_price_mismatch', $report['blockers']);
        $this->assertFalse($report['raw_unit_verified']);
    }

    public function test_optional_money_evidence_does_not_override_an_explicit_conflicting_account_currency_or_symbol(): void
    {
        $this->moneyFixtures(extra: ['api.vps.tc/api/balance' => Http::sequence()
            ->push(['details' => ['currency' => 'USD', 'acc_credit' => 10, 'acc_balance' => 0]])
            ->push(['details' => ['currency' => '$', 'acc_credit' => 10, 'acc_balance' => 0]])]);
        foreach (['USD', null] as $expected) {
            $report = app(VpsTcPreflight::class)->inspect('351', '56');
            $this->assertSame($expected, $report['currency']);
            $this->assertSame(0, $report['money_quotes_verified']);
            $this->assertNull($report['candidates'][0]['money_evidence']);
            $this->assertNull($report['candidates'][0]['order_raw_unit']);
        }
    }

    public function test_extra_calculation_charges_do_not_become_verified_units_or_general_zero_fee_evidence(): void
    {
        $quote = $this->moneyQuote(351); $quote['summary']['fee'] = '0.01';
        $this->moneyFixtures(extra: ['api.vps.tc/api/quote' => Http::response($quote)]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $this->assertSame('TRY', $report['currency']);
        $this->assertSame(0, $report['money_quotes_verified']);
        $this->assertNull($report['candidates'][0]['money_evidence']);
        $this->assertFalse($report['payment_fees_verified']);
        $this->assertContains('monthly_price_mismatch', $report['blockers']);
    }

    public function test_explicit_vps_category_reads_all_sixteen_forms_and_bounded_calculations_without_importing_or_spending(): void
    {
        $settingsBefore = \App\Models\Setting::query()->pluck('value', 'key')->all();
        $this->vpsCategory(extra: [
            'api.vps.tc/api/payment/fees' => Http::response(['payments' => [['id' => 120, 'fixed_fee' => 'Array', 'percent_fee' => 'Array']]]),
        ]);
        $report = app(VpsTcPreflight::class)->inspect(null, '56');
        $this->assertTrue($report['ok']);
        $this->assertSame('category', $report['scope']);
        $this->assertSame('56', $report['scope_category_id']);
        $this->assertSame('all_fixed_monthly_in_category', $report['order_forms_scope']);
        $this->assertSame(16, $report['order_forms_expected']);
        $this->assertSame(16, $report['order_forms_attempted']);
        $this->assertSame(16, $report['order_forms_checked']);
        $this->assertTrue($report['order_forms_complete']);
        $this->assertSame(16, $report['quotes_expected']);
        $this->assertSame(16, $report['quotes_checked']);
        $this->assertSame(16, $report['quotes_arithmetic_verified']);
        $this->assertTrue($report['quote_diagnostics_complete']);
        $this->assertSame('120', $report['credit_payment_method_id']);
        $this->assertSame(32, $report['limits']['order_form_reads']);
        $category = collect($report['categories'])->firstWhere('id', '56');
        $this->assertSame(16, $category['order_forms_expected']);
        $this->assertSame(16, $category['order_forms_checked']);
        $candidate = $report['candidates'][0];
        $this->assertSame('45', $candidate['catalog_to_order_ratio']);
        $this->assertFalse($candidate['raw_unit_verified']);
        $this->assertNull($candidate['order_raw_unit']);
        $this->assertFalse($report['payment_fees_verified']);
        $this->assertFalse($report['sales_ready']);
        $this->assertFalse($report['catalog_complete']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/category/71/product')
            || str_contains($request->url(), '/category/59/product'));
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->method() === 'GET' || in_array($request->url(), [
                'https://api.vps.tc/api/login', 'https://api.vps.tc/api/quote',
            ], true));
        }
        foreach (['cloud_plans', 'cloud_images', 'cloud_locations', 'cloud_provider_operations', 'services', 'invoices', 'credit_ledger'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($settingsBefore, \App\Models\Setting::query()->pluck('value', 'key')->all());
        Http::assertSentCount(38);
    }

    public function test_explicit_category_form_and_quote_budgets_mark_their_exact_partial_scope(): void
    {
        $this->vpsCategory(40);
        $report = app(VpsTcPreflight::class)->inspect(null, '56');
        $this->assertFalse($report['ok']);
        $this->assertTrue($report['truncated']);
        $this->assertSame(40, $report['order_forms_expected']);
        $this->assertSame(32, $report['order_forms_attempted']);
        $this->assertSame(32, $report['order_forms_checked']);
        $this->assertFalse($report['order_forms_complete']);
        $this->assertSame(32, $report['quotes_expected']);
        $this->assertSame(16, $report['quotes_attempted']);
        $this->assertSame(16, $report['quotes_checked']);
        $this->assertFalse($report['quote_diagnostics_complete']);
        $this->assertContains('order_form_read_limit_reached', $report['blockers']);
        $this->assertContains('quote_read_limit_reached', $report['blockers']);
        Http::assertSentCount(54);
    }

    public function test_explicit_product_is_one_form_and_one_quote_and_zero_due_is_covered_credit_not_free(): void
    {
        $this->vpsCategory();
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $this->assertSame('product', $report['scope']);
        $this->assertSame(1, $report['order_forms_expected']);
        $this->assertSame(1, $report['order_forms_checked']);
        $this->assertSame(1, $report['quotes_checked']);
        $this->assertSame(1, $report['limits']['quote_reads']);
        $quote = $report['candidates'][0]['quote_diagnostics'];
        $this->assertSame('0', $quote['total_raw']);
        $this->assertSame('150.66', $quote['credit_raw']);
        $this->assertSame('150.66', $quote['gross_raw']);
        $this->assertSame('125.55', $quote['subtotal_raw']);
        $this->assertSame('25.11', $quote['tax_raw']);
        $this->assertSame(['20'], $quote['taxrates_raw']);
        $this->assertSame('covered_by_credit', $quote['payment_coverage']);
        $this->assertTrue($quote['positive_cost']);
        $this->assertTrue($quote['arithmetic_match']);
        $this->assertNull($quote['unit']);
        $this->assertNull($quote['currency']);
        $this->assertFalse($quote['unit_verified']);
        $this->assertFalse($quote['global_payment_fees_proven']);
        Http::assertSentCount(8);
    }

    public function test_quote_preserves_raw_float_tax_and_canonicalizes_only_the_binary_tail_for_arithmetic(): void
    {
        $this->vpsCategory(extra: ['api.vps.tc/api/quote' => Http::response($this->nativeQuote(351, [
            'subtotal' => 2.79, 'tax' => [['name' => 'KDV', 'tax' => 0.5579999999999999, 'value' => 20]],
            'credit' => 3.348, 'grandtotal' => 3.348,
        ]))]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $quote = $report['candidates'][0]['quote_diagnostics'];
        $this->assertSame('0.5579999999999999', $quote['tax_raw']);
        $this->assertSame('3.348', $quote['gross_raw']);
        $this->assertSame('3.3479999999999999', $quote['component_sum_raw']);
        $this->assertFalse($quote['raw_arithmetic_match']);
        $this->assertTrue($quote['arithmetic_match']);
        $this->assertTrue($quote['normalized_arithmetic_match']);
        $this->assertTrue($quote['normalization_applied']);
        $this->assertSame('0.558', $quote['tax_arithmetic']);
        $this->assertSame('3.348', $quote['gross_arithmetic']);
        $this->assertSame(1, $report['quotes_checked']);
        $this->assertSame(1, $report['quotes_arithmetic_verified']);
        $this->assertNotContains('quote_arithmetic_mismatch', $report['blockers']);
        $this->assertNull($quote['unit']);
        $this->assertFalse($report['sales_ready']);
    }

    public function test_quote_canonicalizes_a_float_subtotal_tail_without_changing_the_raw_observation(): void
    {
        $this->vpsCategory(extra: ['api.vps.tc/api/quote' => Http::response($this->nativeQuote(351, [
            'subtotal' => 125.54999999999999,
        ]))]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $quote = $report['candidates'][0]['quote_diagnostics'];
        $this->assertSame(json_encode(125.54999999999999, JSON_PRESERVE_ZERO_FRACTION), $quote['subtotal_raw']);
        $this->assertSame('125.55', $quote['subtotal_arithmetic']);
        $this->assertTrue($quote['normalization_applied']);
        $this->assertTrue($quote['arithmetic_match']);
        $this->assertNull($quote['currency']);
    }

    public function test_an_actual_eight_decimal_monetary_difference_is_not_erased_as_roundoff(): void
    {
        $this->vpsCategory(extra: ['api.vps.tc/api/quote' => Http::response($this->nativeQuote(351, [
            'subtotal' => 2.79, 'tax' => [['name' => 'KDV', 'tax' => 0.55800001, 'value' => 20]],
            'credit' => 3.348, 'grandtotal' => 3.348,
        ]))]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $quote = $report['candidates'][0]['quote_diagnostics'];
        $this->assertSame('0.55800001', $quote['tax_arithmetic']);
        $this->assertFalse($quote['arithmetic_match']);
        $this->assertContains('quote_arithmetic_mismatch', $report['blockers']);
        $this->assertFalse($report['sales_ready']);
    }

    public function test_numeric_strings_are_exact_and_never_receive_float_roundoff_normalization(): void
    {
        $this->vpsCategory(extra: ['api.vps.tc/api/quote' => Http::response($this->nativeQuote(351, [
            'subtotal' => '2.79', 'tax' => [['name' => 'KDV', 'tax' => '0.5579999999999999', 'value' => '20']],
            'credit' => '3.348', 'grandtotal' => '3.348',
        ]))]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $quote = $report['candidates'][0]['quote_diagnostics'];
        $this->assertSame('0.5579999999999999', $quote['tax_arithmetic']);
        $this->assertFalse($quote['normalization_applied']);
        $this->assertFalse($quote['arithmetic_match']);
        $this->assertContains('quote_arithmetic_mismatch', $report['blockers']);
    }

    public function test_real_nested_quote_product_identity_is_bound_to_the_requested_product(): void
    {
        $body = $this->nativeQuote(351);
        $body['items'] = [['valid' => true, 'error' => [], 'product' => ['id' => 351, 'price' => 2.79]]];
        $this->vpsCategory(extra: ['api.vps.tc/api/quote' => Http::response($body)]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $quote = $report['candidates'][0]['quote_diagnostics'];
        $this->assertSame(1, $report['quotes_checked']);
        $this->assertSame('351', $quote['product_id']);
        $this->assertSame('provider_defaults', $quote['configuration_scope']);
        $this->assertFalse($quote['configuration_verified']);
    }

    public function test_wrong_nested_product_and_summary_errors_are_not_verified_quote_observations(): void
    {
        $body = $this->nativeQuote(351);
        $body['items'] = [['valid' => true, 'product' => ['id' => 999]]];
        $summaryError = $this->nativeQuote(351, ['error' => 'private-summary-error']);
        $this->vpsCategory(extra: ['api.vps.tc/api/quote' => Http::sequence()->push($body)->push($summaryError)]);
        foreach ([1, 2] as $_) {
            $report = app(VpsTcPreflight::class)->inspect('351', '56');
            $this->assertSame(0, $report['quotes_checked']);
            $this->assertNull($report['candidates'][0]['quote_diagnostics']);
            $this->assertContains('quote_schema_not_verified', $report['blockers']);
            $this->assertStringNotContainsString('private-summary-error', json_encode($report));
        }
    }

    public function test_quote_payment_method_is_discovered_by_verified_name_instead_of_a_hardcoded_id(): void
    {
        $this->vpsCategory(extra: ['api.vps.tc/api/payment' => Http::response(['payments' => ['777' => 'Credit Balance']])]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $this->assertSame('777', $report['credit_payment_method_id']);
        $this->assertSame(1, $report['quotes_checked']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/quote') && $request['pay_method'] === 777);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/api/order/'));
    }

    public function test_ambiguous_credit_method_does_not_submit_a_quote_or_guess_the_account_method(): void
    {
        $this->vpsCategory(extra: ['api.vps.tc/api/payment' => Http::response(['payments' => [
            '120' => 'Credit Balance', '121' => 'Credit Balance',
        ]])]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $this->assertNull($report['credit_payment_method_id']);
        $this->assertSame(1, $report['quotes_expected']);
        $this->assertSame(0, $report['quotes_attempted']);
        $this->assertFalse($report['quote_diagnostics_complete']);
        $this->assertFalse($report['ok']);
        $this->assertContains('credit_payment_method_ambiguous', $report['blockers']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/quote'));
    }

    public function test_malformed_quote_is_not_a_verified_calculation_and_does_not_expose_unselected_fields(): void
    {
        $this->vpsCategory(extra: ['api.vps.tc/api/quote' => Http::response($this->nativeQuote(351, [
            'credit' => 'Array', 'password' => 'private-quote-secret',
        ]))]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $this->assertFalse($report['ok']);
        $this->assertSame(0, $report['quotes_checked']);
        $this->assertFalse($report['candidates'][0]['quote_checked']);
        $this->assertNull($report['candidates'][0]['quote_diagnostics']);
        $this->assertContains('quote_schema_not_verified', $report['blockers']);
        $this->assertStringNotContainsString('private-quote-secret', json_encode($report));
        $this->assertFalse($report['sales_ready']);
        $this->assertDatabaseCount('cloud_provider_operations', 0);
    }

    public function test_zero_due_without_a_positive_gross_cost_is_not_reported_as_a_free_verified_offer(): void
    {
        $this->vpsCategory(extra: ['api.vps.tc/api/quote' => Http::response($this->nativeQuote(351, [
            'total' => 0, 'credit' => 0, 'grandtotal' => 0,
        ]))]);
        $report = app(VpsTcPreflight::class)->inspect('351', '56');
        $this->assertFalse($report['ok']);
        $this->assertSame(0, $report['quotes_checked']);
        $this->assertNull($report['candidates'][0]['quote_diagnostics']);
        $this->assertFalse($report['sales_ready']);
    }

    public function test_explicit_virtual_datacenter_forms_are_read_without_vps_quote_calls(): void
    {
        $this->fixtures([
            'api.vps.tc/api/category' => Http::response(['categories' => [['id' => 71, 'name' => 'Virtual Datacenter']]]),
            'api.vps.tc/api/category/71/product' => Http::response(['products' => [$this->product(400), $this->product(401)]]),
            'api.vps.tc/api/order/400' => Http::response($this->form(400, 71)),
            'api.vps.tc/api/order/401' => Http::response($this->form(401, 71)),
        ]);
        $report = app(VpsTcPreflight::class)->inspect(null, '71');
        $this->assertTrue($report['ok']);
        $this->assertSame(2, $report['order_forms_expected']);
        $this->assertSame(2, $report['order_forms_checked']);
        $this->assertSame(0, $report['quotes_expected']);
        $this->assertSame(0, $report['quotes_attempted']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/quote'));
    }

    public function test_nested_live_catalog_finds_vps_leaf_and_keeps_metered_cloud_separate(): void
    {
        $vps = array_map(fn ($i) => $this->product(351 + $i), range(0, 15));
        $vdc = array_map(fn ($i) => $this->product(400 + $i), range(0, 3));
        $dedicated = array_map(fn ($i) => $this->product(500 + $i), range(0, 19));
        $lxc = array_map(fn ($i) => $this->product(1001 + $i, 'Regular', 'a', '12'), range(0, 3));
        $this->fixtures([
            'api.vps.tc/api/category' => Http::response(['categories' => [
                ['id' => 70, 'name' => 'Domains'], ['id' => 67, 'name' => 'LXC Container'],
                ['id' => 55, 'name' => 'Server Services', 'subcategories' => [
                    ['id' => 56, 'name' => 'VPS'], ['id' => 57, 'name' => 'Cloud'],
                    ['id' => 71, 'name' => 'Virtual Datacenter'], ['id' => 59, 'name' => 'Dedicated Server'],
                ]],
            ]]),
            'api.vps.tc/api/category/70/product' => Http::response(['products' => []]),
            'api.vps.tc/api/category/67/product' => Http::response(['products' => $lxc]),
            'api.vps.tc/api/category/56/product' => Http::response(['products' => $vps]),
            'api.vps.tc/api/category/57/product' => Http::response(['products' => [$this->product(2194, 'Metered', 'm', '0.00045')]]),
            'api.vps.tc/api/category/71/product' => Http::response(['products' => $vdc]),
            'api.vps.tc/api/category/59/product' => Http::response(['products' => $dedicated]),
            'api.vps.tc/api/order/351' => Http::response($this->form(351, 56, price: '2.79')),
            'api.vps.tc/api/order/400' => Http::response($this->form(400, 71)),
            'api.vps.tc/api/order/500' => Http::response($this->form(500, 59)),
            'api.vps.tc/api/payment/fees' => Http::response(['payments' => [
                ['id' => 120, 'fixed_fee' => 'Array', 'percent_fee' => 'Array'],
            ]]),
        ], currency: '');
        $report = app(VpsTcPreflight::class)->inspect();
        $this->assertTrue($report['ok']);
        $this->assertTrue($report['discovery_complete']);
        $this->assertSame(7, $report['categories_seen']);
        $this->assertSame(6, $report['leaf_categories_seen']);
        $this->assertSame(6, $report['categories_inspected']);
        $this->assertSame(45, $report['products_seen']);
        $this->assertSame(41, $report['monthly_offers_seen']);
        $this->assertSame(40, $report['fixed_monthly_candidates_seen']);
        $this->assertSame(1, $report['metered_candidates_seen']);
        $this->assertSame(3, $report['order_forms_checked']);
        $this->assertSame('representative_catalog', $report['scope']);
        $this->assertSame('representative_per_category', $report['order_forms_scope']);
        $this->assertSame(3, $report['order_forms_expected']);
        $this->assertSame(0, $report['limits']['quote_reads']);
        $this->assertSame(0, $report['quotes_attempted']);
        $category = collect($report['categories'])->firstWhere('id', '56');
        $this->assertSame('55', $category['parent_id']);
        $this->assertSame(16, $category['products_seen']);
        $this->assertSame(16, $category['monthly_offers_seen']);
        $this->assertSame(16, $category['fixed_monthly_candidates_seen']);
        $vps = collect($report['candidates'])->firstWhere('id', '351');
        $this->assertSame('125.55', $vps['monthly_price_raw']);
        $this->assertSame('2.79', $vps['order_monthly_raw']);
        $this->assertFalse($vps['price_match']);
        $this->assertNull($report['currency']);
        foreach (['account_currency_not_verified', 'monthly_price_mismatch', 'payment_fees_literal_array', 'payment_fees_not_verified'] as $blocker) {
            $this->assertContains($blocker, $report['blockers']);
        }
        $cloud = collect($report['candidates'])->firstWhere('id', '2194');
        $this->assertSame('0.00045', $cloud['monthly_price_raw']);
        $this->assertSame('Metered', $cloud['paytype']);
        $this->assertFalse($cloud['order_checked']);
        $this->assertFalse($report['sales_ready']);
        $this->assertFalse($report['catalog_complete']);
        $orderUrls = Http::recorded(fn ($request) => str_contains($request->url(), '/api/order/'))
            ->map(fn ($pair) => $pair[0]->url())->values()->all();
        $this->assertSame(['https://api.vps.tc/api/order/351', 'https://api.vps.tc/api/order/400', 'https://api.vps.tc/api/order/500'], $orderUrls);
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->method() === 'GET' || str_ends_with($request->url(), '/login'));
        }
    }

    public function test_explicit_product_price_mismatch_is_reported_without_currency_inference(): void
    {
        $this->fixtures([
            'api.vps.tc/api/category/1/product' => Http::response(['products' => [$this->product(351)]]),
            'api.vps.tc/api/order/351' => Http::response($this->form(351, 1, price: '2.79')),
        ], currency: '');
        $report = app(VpsTcPreflight::class)->inspect('351', '1');
        $this->assertSame(1, $report['order_forms_checked']);
        $this->assertFalse($report['candidates'][0]['price_match']);
        $this->assertSame('₺125.55  Monthly', $report['candidates'][0]['monthly_price_formatted']);
        $this->assertSame('₺125.55  Monthly', $report['candidates'][0]['order_monthly_formatted']);
        $this->assertNull($report['currency']);
        $this->assertContains('monthly_price_mismatch', $report['blockers']);
        Http::assertSentCount(7);
    }

    public function test_order_metered_or_unknown_evidence_removes_fixed_monthly_classification(): void
    {
        $this->fixtures([
            'api.vps.tc/api/category/1/product' => Http::response(['products' => [$this->product(2)]]),
            'api.vps.tc/api/category/2/product' => Http::response(['products' => [$this->product(3)]]),
            'api.vps.tc/api/order/2' => Http::response($this->form(2, 1, 'Metered')),
            'api.vps.tc/api/order/3' => Http::response($this->form(3, 2, 'Unsupported')),
        ]);
        foreach ([['2', '1', 'Metered'], ['3', '2', 'Unsupported']] as [$product, $category, $paytype]) {
            $report = app(VpsTcPreflight::class)->inspect($product, $category);
            $this->assertSame(1, $report['monthly_offers_seen']);
            $this->assertSame(0, $report['fixed_monthly_candidates_seen']);
            $this->assertSame($paytype === 'Metered' ? 1 : 0, $report['metered_candidates_seen']);
            $this->assertSame($paytype === 'Metered' ? 0 : 1, $report['unknown_paytype_candidates_seen']);
        }
    }

    public function test_category_and_product_read_budgets_bound_traversal_and_mark_partial_counts(): void
    {
        $this->fixtures([
            'api.vps.tc/api/category' => Http::response(['categories' => array_map(fn ($id) => ['id' => $id, 'name' => 'Category '.$id], range(1, 70))]),
            'api.vps.tc/api/category/*/product' => Http::response(['products' => []]),
        ]);
        $report = app(VpsTcPreflight::class)->inspect();
        $this->assertFalse($report['ok']);
        $this->assertFalse($report['discovery_complete']);
        $this->assertTrue($report['truncated']);
        $this->assertSame(64, $report['categories_seen']);
        $this->assertSame(16, $report['categories_inspected']);
        $this->assertContains('category_traversal_limit_reached', $report['blockers']);
        $this->assertContains('product_read_limit_reached', $report['blockers']);
        Http::assertSentCount(21);
    }

    public function test_deep_duplicate_and_malformed_categories_never_become_unbounded_leaf_reads(): void
    {
        $deep = ['id' => 20, 'name' => 'Deep leaf'];
        for ($id = 19; $id >= 10; $id--) { $deep = ['id' => $id, 'subcategories' => [$deep]]; }
        $this->fixtures([
            'api.vps.tc/api/category' => Http::response(['categories' => [
                ['id' => 1, 'name' => 'Good'], ['id' => 1, 'name' => 'Duplicate'],
                ['id' => 2, 'subcategories' => 'unsupported'], ['id' => ['invalid']], $deep,
            ]]),
        ]);
        $report = app(VpsTcPreflight::class)->inspect();
        $this->assertFalse($report['discovery_complete']);
        $this->assertTrue($report['truncated']);
        $this->assertSame(1, $report['categories_inspected']);
        foreach (['duplicate_category_id', 'category_schema_not_verified', 'category_traversal_limit_reached'] as $blocker) {
            $this->assertContains($blocker, $report['blockers']);
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/category/2/product'));
    }

    public function test_partial_product_reads_keep_observations_without_claiming_complete_catalog(): void
    {
        $this->fixtures([
            'api.vps.tc/api/category' => Http::response(['categories' => [['id' => 1], ['id' => 2]]]),
            'api.vps.tc/api/category/1/product' => Http::response(['products' => [$this->product(10)], 'next_page' => 2, 'total' => 5]),
            'api.vps.tc/api/category/2/product' => Http::response(['error' => 'fixture-password'], 503),
            'api.vps.tc/api/order/10' => Http::response($this->form(10, 1)),
        ]);
        $report = app(VpsTcPreflight::class)->inspect();
        $this->assertFalse($report['ok']);
        $this->assertSame(1, $report['products_seen']);
        $this->assertSame(1, $report['monthly_offers_seen']);
        $this->assertContains('product_list_incomplete', $report['blockers']);
        $this->assertContains('product_read_failed', $report['blockers']);
        $this->assertStringNotContainsString('fixture-password', json_encode($report));
    }

    public function test_malformed_money_is_diagnostic_and_never_taken_from_formatted_labels(): void
    {
        $bad = $this->product(2, price: '₺125.55');
        $bad['name'] = '<script>alert(1)</script> VPS';
        $bad['password'] = 'upstream-secret';
        $this->fixtures([
            'api.vps.tc/api/category/1/product' => Http::response(['products' => [$bad, ['id' => ['bad']]]]),
            'api.vps.tc/api/order/2' => Http::response($this->form(2, 1, price: '2.79')),
        ]);
        $report = app(VpsTcPreflight::class)->inspect('2', '1');
        $this->assertNull($report['candidates'][0]['monthly_price_raw']);
        $this->assertNull($report['candidates'][0]['price_match']);
        $this->assertSame(0, $report['monthly_offers_seen']);
        $this->assertContains('monthly_price_not_verified', $report['candidates'][0]['issues']);
        $this->assertContains('product_schema_not_verified', $report['blockers']);
        $this->assertStringNotContainsString('<script>', json_encode($report));
        $this->assertStringNotContainsString('upstream-secret', json_encode($report));
    }

    public function test_product_and_preview_limits_keep_explicit_product_in_bounded_output(): void
    {
        $products = array_map(fn ($id) => $this->product($id, 'Metered'), range(1, 600));
        $this->fixtures([
            'api.vps.tc/api/category/1/product' => Http::response(['products' => $products]),
            'api.vps.tc/api/order/500' => Http::response($this->form(500, 1, 'Metered')),
        ]);
        $report = app(VpsTcPreflight::class)->inspect('500', '1');
        $this->assertSame(512, $report['products_seen']);
        $this->assertCount(128, $report['candidates']);
        $this->assertSame('500', $report['candidates'][0]['id']);
        $this->assertTrue($report['truncated']);
        $this->assertFalse($report['discovery_complete']);
        $this->assertContains('product_row_limit_reached', $report['blockers']);
        $this->assertContains('candidate_preview_limit_reached', $report['blockers']);
    }

    public function test_explicit_product_missing_from_selected_category_is_not_assumed_matched(): void
    {
        $this->fixtures(['api.vps.tc/api/order/99' => Http::response($this->form(99, 1))]);
        $report = app(VpsTcPreflight::class)->inspect('99', '1');
        $this->assertContains('product_list_match_not_found', $report['blockers']);
        $this->assertTrue($report['order_form_checked']);
        $this->assertNull($report['candidates'][0]['monthly_price_raw']);
        $this->assertNull($report['candidates'][0]['price_match']);
        $this->assertFalse($report['sales_ready']);
    }

    public function test_admin_report_escapes_upstream_text_and_keeps_unknown_currency_out_of_raw_amounts(): void
    {
        $report = ['ok' => true, 'connection_ok' => true, 'currency' => null, 'categories_seen' => 1,
            'products_seen' => 1, 'monthly_offers_seen' => 1, 'fixed_monthly_candidates_seen' => 1,
            'payment_fees_verified' => false, 'discovery_complete' => true, 'order_form_checked' => true,
            'blockers' => ['account_currency_not_verified', 'payment_fees_literal_array', 'monthly_price_mismatch'],
            'categories' => [['id' => '56', 'name' => '<script>category()</script>', 'is_leaf' => true,
                'product_read' => true, 'products_seen' => 16, 'fixed_monthly_candidates_seen' => 16]],
            'candidates' => [['id' => '351', 'category_id' => '56', 'name' => '<img src=x onerror=alert(1)>',
                'paytype' => 'Regular', 'monthly_price_raw' => '125.55', 'order_monthly_raw' => '2.79',
                'monthly_price_formatted' => '₺125.55', 'order_monthly_formatted' => '₺125.55',
                'price_match' => false]]];
        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin']), 'web');
        $response = $this->withSession(['vpstc_report' => $report])->get('/admin/cloud/vpstc');
        $response->assertOk()->assertSee('&lt;script&gt;category()&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<script>category()</script>', false)->assertDontSee('<img src=x', false)
            ->assertSee('<span dir="ltr">تأیید نشده</span>', false)
            ->assertSee('<td dir="ltr">125.55</td>', false)->assertSee('<td dir="ltr">2.79</td>', false)
            ->assertDontSee('<td dir="ltr">₺125.55</td>', false)
            ->assertSee('به‌جای عدد، عبارت Array')->assertSee('اعداد متفاوت؛ سند واحد پول لازم است');
        Http::assertNothingSent();
    }
}
