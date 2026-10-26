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
            ->assertSee('<dd dir="ltr">تأیید نشده</dd>', false)
            ->assertSee('<td dir="ltr">125.55</td>', false)->assertSee('<td dir="ltr">2.79</td>', false)
            ->assertDontSee('<td dir="ltr">₺125.55</td>', false)
            ->assertSee('به‌جای عدد، عبارت Array')->assertSee('ناسازگار؛ فروش بسته می‌ماند');
        Http::assertNothingSent();
    }
}
