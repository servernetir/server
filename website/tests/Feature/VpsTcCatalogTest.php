<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcCatalog;
use App\Services\Cloud\VpsTcPricing;
use App\Services\Cloud\VpsTcMoneyContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class VpsTcCatalogTest extends TestCase
{
    use RefreshDatabase;

    private VpsTcApi $api;
    private VpsTcCatalog $catalog;
    private array $product;
    private array $payments;
    private array $fees;
    private array $categoryProducts;
    private array $anchorInvoice = [];
    private array $anchorService = [];
    private array $calculation = [];
    private string $currency = 'USD';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        NavasanRates::store();
        foreach (['vpstc_currency' => 'USD', 'cloud_margin_pct_vpstc' => '20',
            'vpstc_supplier_tax_pct' => '20', 'pricing_fx_fee_pct_vpstc' => '5',
            'vpstc_fx_rate_toman' => ''] as $key => $value) { Setting::put($key, $value); }
        $this->api = new VpsTcApi('https://portal.vps.tc', 'fixture-reseller', 'fixture-secret');
        $this->catalog = new VpsTcCatalog($this->api, app(VpsTcPricing::class));
        $this->product = ['id' => '12', 'category_id' => 56, 'paytype' => 'Regular', 'billingtype' => 'PrePay',
            'recurring' => 'm', 'price' => '2.79', 'recurring_price' => '2.79', 'setup' => '1.25',
            'in_stock' => true, 'config' => [
            'product' => [['id' => 'cycle', 'items' => [['value' => 'm', 'price' => 2.79, 'setup' => 1.25]]]],
            'forms' => [['id' => '44', 'type' => 'select', 'required' => true, 'price' => 0,
                'setup' => 0, 'recurring_price' => 0, 'value' => [], 'items' => [
                    ['id' => '123', 'title' => 'Ubuntu 24.04', 'price' => 0, 'setup' => 0, 'selected' => false],
                ]]],
        ]];
        $this->payments = ['120' => 'Credit Balance'];
        $this->fees = [['id' => 120, 'fixed_fee' => '0', 'percent_fee' => '0']];
        $this->categoryProducts = [['id' => 12, 'category_id' => '56', 'paytype' => 'Regular',
            'periods' => [['value' => 'm', 'price' => '2.79', 'setup' => '1.25']]]];
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://portal.vps.tc/api/login' => Http::response(['token' => 'fixture-token']),
                'https://portal.vps.tc/api/balance' => Http::response(['details' => ['currency' => $this->currency, 'acc_credit' => '100.00', 'acc_balance' => '0']]),
                'https://portal.vps.tc/api/order/12' => Http::response(['product' => $this->product]),
                'https://portal.vps.tc/api/category/56/product' => Http::response(['products' => $this->categoryProducts]),
                'https://portal.vps.tc/api/invoice/91' => Http::response(['invoice' => $this->anchorInvoice]),
                'https://portal.vps.tc/api/service/301' => Http::response(['service' => $this->anchorService]),
                'https://portal.vps.tc/api/quote' => Http::response($this->calculation),
                'https://portal.vps.tc/api/payment' => Http::response(['payments' => $this->payments]),
                'https://portal.vps.tc/api/payment/fees' => Http::response(['payments' => $this->fees]),
                default => Http::response(['error' => 'Unknown fixture endpoint'], 404),
            };
        });
    }

    private function mapping(array $overrides = []): array
    {
        return array_replace([
            'product_id' => '12', 'category_id' => '56', 'kind' => 'vps', 'cycle' => 'm',
            'renewal_mode' => 'manual_invoice_deduplicated', 'parent_cancel_stops_billing' => true,
            'forms_sha256' => VpsTcCatalog::formFingerprint($this->product['config']['forms']),
            'country' => 'TR', 'city' => 'Istanbul', 'location_ref' => 'istanbul',
            'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30, 'disk_type' => 'nvme',
            'cpu_kind' => 'shared', 'arch' => 'x86', 'traffic_gb' => 1000,
            'network_terms' => ['charge_mode' => 'hard_cap', 'extra_charges' => false],
            'availability' => ['path' => 'in_stock', 'mode' => 'boolean'],
            'payment_method' => '120', 'custom' => [],
            'images' => ['12|ubuntu-24.04' => ['key' => 'ubuntu-24.04', 'family' => 'ubuntu',
                'version' => '24.04', 'label' => 'Ubuntu 24.04', 'min_ram_mb' => 512, 'min_disk_gb' => 10,
                'custom' => ['44' => ['123' => 1]]]],
        ], $overrides);
    }

    private function save(?array $mapping = null, ?string $account = null): void
    {
        Setting::put('vpstc_catalog_manifest', json_encode(['account_fingerprint' => $account ?? $this->api->accountFingerprint(),
            'products' => [$mapping ?? $this->mapping()]], JSON_THROW_ON_ERROR));
    }

    private function converted(array $overrides = []): void
    {
        Setting::put('vpstc_currency', 'TRY'); Setting::put('vpstc_fx_rate_toman', '5000');
        $this->currency = 'TRY';
        $this->product['setup'] = 0;
        $this->product['description'] = '<p>Reviewed CPU/RAM/DISK/NETWORK/LOCATION terms</p>';
        $this->product['out_of_stock'] = false;
        $this->product['config']['product'][0]['items'][0] = ['value' => 'm', 'price' => '2.79', 'setup' => 0, 'formatted' => '₺125.55 Monthly'];
        $this->categoryProducts[0]['periods'][0] = ['value' => 'm', 'price' => '125.55', 'setup' => 0, 'formatted' => '₺125.55 Monthly'];
        $this->fees = [['id' => 120, 'fixed_fee' => 0, 'percent_fee' => 0]];
        $this->anchorService = ['id' => '301', 'name' => 'Reviewed VPS', 'billingcycle' => 'Monthly', 'date_created' => '2026-01-01'];
        $this->anchorInvoice = ['id' => '91', 'status' => 'Paid', 'currency' => 'TRY', 'rate' => '45', 'subtotal' => '125.55',
            'tax' => '25.11', 'tax2' => '0', 'grandtotal' => '150.66', 'credit' => '150.66', 'total' => '0', 'discounts' => [],
            'items' => [['type' => 'Hosting', 'item_id' => '301', 'qty' => '1.00']]];
        Setting::putSecret(VpsTcMoneyContract::KEY, json_encode(['version' => 1, 'account_fingerprint' => $this->api->accountFingerprint(),
            'base_currency' => 'USD', 'account_currency' => 'TRY', 'multiplier' => '45', 'payment_method' => '120',
            'source' => ['kind' => 'reviewed_portal_currency_table', 'sha256' => str_repeat('a', 64), 'reviewed_at' => now()->toIso8601String(),
                'url' => 'https://www.vps.tc/en/vps',
                'product_id' => '12', 'base_price' => '2.79', 'native_price' => '125.55'],
            'anchor' => ['invoice_id' => '91', 'service_id' => '301', 'product_id' => '12', 'cycle' => 'm', 'base_price' => '2.79', 'tax_percent' => '20',
                'financial_sha256' => VpsTcMoneyContract::financialFingerprint($this->anchorInvoice),
                'service_sha256' => VpsTcMoneyContract::serviceFingerprint($this->anchorService)]], JSON_THROW_ON_ERROR));
        $mapping = $this->mapping(array_replace(['terms_sha256' => VpsTcCatalog::termsFingerprint($this->product),
            'availability' => ['path' => 'out_of_stock', 'mode' => 'boolean_false']], $overrides));
        $custom = array_replace_recursive($mapping['custom'], $mapping['images']['12|ubuntu-24.04']['custom']);
        $echo = $this->product;
        $echo['domain'] = 'preflight-12.example.invalid';
        $echo['config']['product'][0] = ['id' => 'cycle', 'value' => 'm', 'price' => '2.79', 'setup' => 0];
        $echo['config']['forms'] = [];
        foreach ($custom as $id => $value) {
            $echo['config']['forms'][] = ['id' => (string) $id, 'value' => $value, 'price' => 0, 'recurring_price' => 0, 'setup' => 0];
        }
        $this->calculation = ['summary' => ['subtotal' => '2.79', 'cost' => '2.79', 'setup_fee' => 0, 'discount' => 0,
            'credit' => 3.348, 'total' => 0, 'recurring_discount' => [],
            'tax' => [['name' => 'KDV', 'tax' => 0.5579999999999999, 'value' => 20]],
            'recurring' => [['value' => 'm', 'price' => 3.348]]], 'items' => [['index' => 0, 'valid' => true, 'error' => [], 'coupon' => [], 'product' => $echo,
                'domains' => ['preflight-12.example.invalid' => ['category_id' => null, 'category_name' => null, 'config' => [], 'id' => null,
                    'name' => 'preflight-12.example.invalid', 'nameservers' => [], 'period' => 0, 'price' => 0,
                    'recurring_price' => null, 'setup' => 0, 'tld' => '', 'type' => 'hostname']]]]];
        $this->save($mapping);
    }

    public function test_absent_or_different_account_mapping_cannot_import_an_offer(): void
    {
        $this->assertFalse($this->catalog->fetch()['ok']);
        $this->save(account: 'another-account');
        $this->assertNull($this->catalog->quote('12'));
        $this->assertFalse($this->catalog->fetch()['ok']);
        Http::assertNothingSent();
    }

    public function test_unknown_renewal_or_cancellation_policy_blocks_before_any_provider_request(): void
    {
        foreach ([['renewal_mode' => null], ['renewal_mode' => 'automatic'],
            ['parent_cancel_stops_billing' => null], ['parent_cancel_stops_billing' => false],
            ['parent_cancel_stops_billing' => 'true']] as $unknown) {
            $this->save($this->mapping($unknown));
            $this->assertNull($this->catalog->manifest());
            $this->assertNull($this->catalog->quote('12'));
            $this->assertFalse($this->catalog->fetch()['ok']);
        }
        Http::assertNothingSent();
    }

    public function test_native_numeric_monthly_quote_includes_tax_funding_and_setup_once(): void
    {
        $this->save();
        $quote = $this->catalog->quote('12');
        $this->assertNotNull($quote);
        // USD2.79 * 1.20 tax * 1.05 funding * EUR0.50 per USD = EUR1.7577.
        $this->assertSame(176, $quote['cost_eur_cents']);
        $this->assertSame(79, $quote['setup_eur_cents']);
        // Native invoice maximum includes tax, while funding costs are ours.
        $this->assertSame('4.85', $quote['total_amount']);
        $this->assertSame('3.35', $quote['renewal_total_amount']);
        $this->assertTrue($quote['in_stock']);
        $this->assertSame('USD', $quote['currency']);
        $catalog = $this->catalog->fetch();
        $this->assertTrue($catalog['ok']);
        $this->assertCount(1, $catalog['plans']);
        $this->assertSame(176, $catalog['plans'][0]['cost_eur_cents']);
        $this->assertNull($catalog['plans'][0]['cost_hour_eur_micro']);
        $this->assertSame(1000, $catalog['plans'][0]['traffic_gb']);
        $this->assertSame('12|ubuntu-24.04', $catalog['images'][0]['provider_ref']);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' && ! str_ends_with($request->url(), '/login'));
    }

    private function commercialMapping(): array
    {
        $row = $this->mapping(['parent_cancel_stops_billing' => null,
            'network_terms' => ['charge_mode' => 'unconfirmed', 'extra_charges' => null]]);
        $row['commercial_launch_approval'] = [
            'version' => 1, 'decision' => 'launch_pending_supplier_clarification', 'approved_by' => 'owner',
            'account_fingerprint' => $this->api->accountFingerprint(), 'product_id' => '12',
            'approved_at' => gmdate('c'), 'support_ticket' => '154596',
            'pending' => ['supplier_autorenew', 'cancellation_billing', 'traffic_overage'],
            'scope_sha256' => VpsTcCatalog::launchScopeFingerprint($row),
        ];
        return $row;
    }

    public function test_explicit_commercial_launch_accepts_unknown_policies_without_changing_them(): void
    {
        $row = $this->commercialMapping();
        $this->save($row);
        $quote = $this->catalog->quote('12');
        $this->assertNotNull($quote);
        $this->assertNull($quote['parent_cancel_stops_billing']);
        $this->assertNull($quote['network_terms']['extra_charges']);
        $this->assertSame('unconfirmed', $quote['network_terms']['charge_mode']);
        $this->assertSame('4.85', $quote['total_amount']);
        $this->assertTrue($this->catalog->fetch()['ok']);
    }

    public function test_commercial_approval_is_bound_to_account_product_configuration_and_owner(): void
    {
        foreach ([
            ['account_fingerprint' => 'different'], ['product_id' => '13'], ['approved_by' => 'customer'],
            ['scope_sha256' => str_repeat('0', 64)], ['approved_at' => '2100-01-01T00:00:00+00:00'],
            ['support_ticket' => ''], ['pending' => []], ['decision' => 'free_operations'],
        ] as $override) {
            $row = $this->commercialMapping();
            $row['commercial_launch_approval'] = array_replace($row['commercial_launch_approval'], $override);
            $this->save($row);
            $this->assertNull($this->catalog->manifest());
        }
        $row = $this->commercialMapping();
        $row['ram_mb'] = 4096;
        $this->save($row);
        $this->assertNull($this->catalog->manifest());
        Http::assertNothingSent();
    }

    public function test_commercial_approval_does_not_bypass_price_stock_or_missing_resource_validation(): void
    {
        $row = $this->commercialMapping();
        $this->save($row);
        $this->product['price'] = '0.01';
        $this->assertNull($this->catalog->quote('12'));
        $row['vcpu'] = 0;
        $row['commercial_launch_approval']['scope_sha256'] = VpsTcCatalog::launchScopeFingerprint($row);
        $this->save($row);
        $this->assertNull($this->catalog->manifest());
        foreach (['fa', 'en', 'tr'] as $locale) {
            $plan = new \App\Models\CloudPlan(['provider' => 'vpstc', 'traffic_gb' => 2048,
                'network_terms' => ['charge_mode' => 'unconfirmed', 'extra_charges' => null]]);
            $label = $plan->trafficLabel($locale);
            $this->assertNotEmpty($label);
            $this->assertStringNotContainsString('hard limit', $label);
            $this->assertStringNotContainsString('سقف قطعی', $label);
        }
    }

    public function test_annual_lxc_or_missing_monthly_tariff_is_never_divided_into_retail_terms(): void
    {
        $this->product['config']['product'][0]['items'] = [['value' => 'a', 'price' => '12', 'setup' => 0]];
        $this->save();
        $this->assertNull($this->catalog->quote('12'));
        $this->assertFalse($this->catalog->fetch()['ok']);
        foreach ([['kind' => 'lxc'], ['cycle' => 'a']] as $invalid) {
            $this->save($this->mapping($invalid));
            $this->assertNull($this->catalog->manifest());
        }
    }

    public function test_unknown_negative_formatted_and_missing_monthly_or_setup_price_close_offer(): void
    {
        $this->save();
        foreach (['unknown', '1,000', '-1', '1e3', '', null, []] as $bad) {
            $this->product['config']['product'][0]['items'][0]['price'] = $bad;
            $this->assertNull($this->catalog->quote('12'));
        }
        $this->product['config']['product'][0]['items'][0]['price'] = '2.79';
        foreach (['unknown', '-1', null, []] as $bad) {
            $this->product['config']['product'][0]['items'][0]['setup'] = $bad;
            $this->assertNull($this->catalog->quote('12'));
        }
        $this->product['config']['product'][0]['items'][0]['setup'] = 0.0;
        $this->product['setup'] = 0;
        $this->categoryProducts[0]['periods'][0]['setup'] = 0;
        $this->assertNotNull($this->catalog->quote('12'));
    }

    public function test_unverified_currency_or_pricing_cannot_inherit_other_suppliers_inputs(): void
    {
        $this->save();
        foreach (['TRY', 'EUR', '$', 'usd'] as $currency) {
            $this->currency = $currency;
            $this->assertNull($this->catalog->quote('12'));
        }
        $this->currency = 'USD';
        Setting::put('vpstc_supplier_tax_pct', '');
        $this->assertNull($this->catalog->quote('12'));
        $this->assertFalse($this->catalog->fetch()['ok']);
    }

    public function test_missing_paid_or_unverified_credit_payment_method_closes_offer(): void
    {
        $this->save();
        foreach ([[], [['id' => 120, 'fixed_fee' => '1', 'percent_fee' => '0']],
            [['id' => 120, 'fixed_fee' => '0', 'percent_fee' => '0.01']],
            [['id' => 120, 'fixed_fee' => 'unknown', 'percent_fee' => '0']],
            [['id' => 121, 'fixed_fee' => '0', 'percent_fee' => '0']]] as $fees) {
            $this->fees = $fees;
            $this->assertNull($this->catalog->quote('12'));
        }
        $this->fees = [['id' => 120, 'fixed_fee' => '0', 'percent_fee' => '0']];
        $this->payments = ['120' => 'Credit card'];
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_changed_form_contract_invalidates_review_even_if_prices_remain_free(): void
    {
        $this->save();
        $this->product['config']['forms'][0]['items'][0]['title'] = 'Different image';
        $this->assertNull($this->catalog->quote('12'));
        $this->assertFalse($this->catalog->fetch()['ok']);
    }

    public function test_review_hash_cannot_hide_selected_os_licence_or_unknown_option_surcharge(): void
    {
        foreach (['1.00', 'unknown', null] as $price) {
            $this->product['config']['forms'][0]['items'][0]['price'] = $price;
            $this->save();
            $this->assertNull($this->catalog->quote('12'));
        }
        $this->product['config']['forms'][0]['items'][0]['price'] = 0;
        $mapping = $this->mapping();
        $mapping['images']['12|ubuntu-24.04']['custom']['44'] = ['999' => 1];
        $this->save($mapping);
        $this->assertNull($this->catalog->quote('12'));
        $this->save($this->mapping(['custom' => ['999' => ['123' => 1]]]));
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_base_and_image_cannot_select_two_mutually_exclusive_options(): void
    {
        $this->product['config']['forms'][0]['items'][] = ['id' => '124', 'price' => 0, 'setup' => 0];
        $this->save($this->mapping(['custom' => ['44' => ['124' => 1]]]));
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_unknown_or_default_paid_extra_option_is_not_silently_omitted(): void
    {
        $this->product['config']['forms'][] = ['id' => '45', 'type' => 'select', 'required' => false,
            'items' => [['id' => '125', 'price' => 2, 'setup' => 0, 'selected' => true]]];
        $this->save();
        $this->assertNull($this->catalog->quote('12'));
        $this->product['config']['forms'][1]['type'] = 'unknown-new-billing-type';
        $this->save();
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_malformed_resource_or_network_mappings_fail_closed(): void
    {
        foreach ([['vcpu' => 0], ['ram_mb' => '2048'], ['forms_sha256' => []], ['payment_method' => []],
            ['network_terms' => ['charge_mode' => 'hard_cap', 'extra_charges' => true]],
            ['traffic_gb' => 0], ['images' => []]] as $bad) {
            $this->save($this->mapping($bad));
            $this->assertNull($this->catalog->manifest());
            $this->assertNull($this->catalog->quote('12'));
        }
        $mapping = $this->mapping();
        $mapping['images']['12|ubuntu-24.04']['min_disk_gb'] = 50;
        $this->save($mapping);
        $this->assertNull($this->catalog->manifest());
    }

    public function test_manifest_cannot_override_computed_current_currency_cost_or_invoice_ceiling(): void
    {
        $this->save($this->mapping(['currency' => 'TRY', 'cost_eur_cents' => 1,
            'setup_eur_cents' => 0, 'total_amount' => '0.01', 'fingerprint' => 'stale',
            'paytype' => 'Metered', 'billingtype' => 'PostPay']));
        $quote = $this->catalog->quote('12');
        $this->assertSame('USD', $quote['currency']);
        $this->assertSame(176, $quote['cost_eur_cents']);
        $this->assertSame(79, $quote['setup_eur_cents']);
        $this->assertSame('4.85', $quote['total_amount']);
        $this->assertSame('Regular', $quote['paytype']);
        $this->assertSame('PrePay', $quote['billingtype']);
        $this->assertNotSame('stale', $quote['fingerprint']);
    }

    public function test_reviewed_native_availability_overrides_stale_manifest_stock(): void
    {
        $this->save($this->mapping(['in_stock' => true]));
        $this->assertTrue($this->catalog->quote('12')['in_stock']);
        $this->product['in_stock'] = false;
        $this->assertFalse($this->catalog->quote('12')['in_stock']);
        $catalog = $this->catalog->fetch();
        $this->assertTrue($catalog['ok']);
        $this->assertFalse($catalog['plans'][0]['in_stock']);
        foreach ([1, 0, 'true', 'false', null, [], ['available' => true]] as $invalid) {
            $this->product['in_stock'] = $invalid;
            $this->assertNull($this->catalog->quote('12'));
        }
    }

    public function test_reviewed_native_stock_count_requires_nonnegative_integer(): void
    {
        $this->product['capacity'] = ['remaining' => 2];
        $this->save($this->mapping(['availability' => ['path' => 'capacity.remaining', 'mode' => 'positive_integer']]));
        $this->assertTrue($this->catalog->quote('12')['in_stock']);
        $this->product['capacity']['remaining'] = 0;
        $this->assertFalse($this->catalog->quote('12')['in_stock']);
        foreach ([-1, 2.5, '2', true, null, []] as $invalid) {
            $this->product['capacity']['remaining'] = $invalid;
            $this->assertNull($this->catalog->quote('12'));
        }
        foreach ([[], ['path' => 'missing.path', 'mode' => 'positive_integer'],
            ['path' => 'capacity.*', 'mode' => 'positive_integer'],
            ['path' => 'capacity.remaining', 'mode' => 'unknown'],
            ['path' => str_repeat('a', 101), 'mode' => 'boolean']] as $mapping) {
            $this->save($this->mapping(['availability' => $mapping]));
            $this->assertNull($this->catalog->quote('12'));
        }
    }

    public function test_duplicate_monthly_cycles_are_ambiguous_and_never_pick_one(): void
    {
        $this->product['config']['product'][0]['items'][] = ['value' => 'm', 'price' => '9.99', 'setup' => 0];
        $this->save();
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_metered_cloud_with_monthly_cycle_and_tiny_rate_is_not_a_prepaid_monthly_contract(): void
    {
        $this->product['paytype'] = 'Metered';
        $this->product['price'] = $this->product['recurring_price'] = '0.00001';
        $this->product['setup'] = '5';
        $this->product['config']['product'][0]['items'] = [['value' => 'm', 'price' => '0.00001', 'setup' => '5']];
        $this->categoryProducts[0]['paytype'] = 'Metered';
        $this->categoryProducts[0]['periods'] = [['value' => 'm', 'price' => '0.00001', 'setup' => '5']];
        $this->save();
        $this->assertNull($this->catalog->quote('12'));
        $this->assertFalse($this->catalog->fetch()['ok']);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/payment'));
    }

    public function test_regular_product_must_explicitly_use_prepay_monthly_billing(): void
    {
        $this->save();
        foreach ([['billingtype' => 'PostPay'], ['billingtype' => null], ['billingtype' => 'prepay'],
            ['paytype' => null], ['paytype' => 'regular'], ['recurring' => 'h'], ['recurring' => null]] as $invalid) {
            $original = $this->product;
            $this->product = array_replace($this->product, $invalid);
            $this->assertNull($this->catalog->quote('12'));
            $this->product = $original;
        }
        $this->assertNotNull($this->catalog->quote('12'));
    }

    public function test_native_category_and_order_amounts_must_agree_without_a_formatted_price_conversion(): void
    {
        Setting::put('vpstc_currency', 'TRY');
        Setting::put('vpstc_fx_rate_toman', '5000');
        $this->currency = 'TRY';
        $this->categoryProducts[0]['periods'][0] = ['value' => 'm', 'price' => '125.55', 'setup' => '0',
            'formatted' => '₺125.55  Monthly'];
        $this->product['setup'] = 0;
        $this->product['config']['product'][0]['items'][0] = ['value' => 'm', 'price' => '2.79', 'setup' => 0,
            'formatted' => '₺125.55  Monthly'];
        $this->save();
        $this->assertNull($this->catalog->quote('12'), 'The observed 45x API disagreement cannot become a conversion factor.');
        $this->assertFalse($this->catalog->fetch()['ok']);
        $this->product['price'] = $this->product['recurring_price'] = '125.55';
        $this->product['config']['product'][0]['items'][0]['price'] = '125.55';
        $this->assertSame('125.55', $this->catalog->quote('12')['monthly_amount']);
        $this->product['config']['product'][0]['items'][0]['price'] = '₺125.55';
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_order_raw_prices_and_category_identity_cannot_be_replaced_by_the_cycle_row(): void
    {
        $this->save();
        foreach ([['price' => '1.00'], ['recurring_price' => '1.00'], ['setup' => '0'],
            ['price' => null], ['recurring_price' => null], ['category_id' => '57'], ['id' => null]] as $invalid) {
            $original = $this->product;
            $this->product = array_replace($this->product, $invalid);
            $this->assertNull($this->catalog->quote('12'));
            $this->product = $original;
        }
        $this->categoryProducts[0]['category_id'] = '57';
        $this->assertNull($this->catalog->quote('12'));
        $this->categoryProducts[0]['category_id'] = '56';
        $this->categoryProducts[] = $this->categoryProducts[0];
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_missing_account_currency_and_literal_array_fees_are_never_inferred_as_safe(): void
    {
        $this->save();
        $this->currency = '';
        $this->assertNull($this->catalog->quote('12'));
        $this->currency = 'USD';
        $this->fees = [['id' => 120, 'fixed_fee' => 'Array', 'percent_fee' => 'Array']];
        $this->assertNull($this->catalog->quote('12'));
        $this->fees = [['id' => 120, 'fixed_fee' => '0', 'percent_fee' => '0'],
            ['id' => 120, 'fixed_fee' => 'Array', 'percent_fee' => 'Array']];
        $this->assertNull($this->catalog->quote('12'), 'An ambiguous fee list cannot pick its zero-fee row.');
    }

    public function test_category_mapping_and_native_monthly_evidence_are_required(): void
    {
        foreach ([null, '', '0', '56/../57', []] as $bad) {
            $this->save($this->mapping(['category_id' => $bad]));
            $this->assertNull($this->catalog->manifest());
        }
        $this->save();
        $this->categoryProducts[0]['periods'][] = ['value' => 'm', 'price' => '2.79', 'setup' => '1.25'];
        $this->assertNull($this->catalog->quote('12'));
        array_pop($this->categoryProducts[0]['periods']);
        $this->categoryProducts[0]['paytype'] = 'Metered';
        $this->assertNull($this->catalog->quote('12'));
        $this->categoryProducts = [];
        $this->assertNull($this->catalog->quote('12'));
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' && ! str_ends_with($request->url(), '/login'));
    }

    public function test_reviewed_unit_proof_uses_native_category_cost_and_current_exact_computed_configuration(): void
    {
        $this->converted();
        $quote = $this->catalog->quote('12');
        $this->assertNotNull($quote);
        $this->assertSame('125.55', $quote['monthly_amount']);
        $this->assertSame('150.66', $quote['total_amount']);
        $this->assertSame(396, $quote['cost_eur_cents']);
        $this->assertTrue($quote['in_stock']);
        $this->product['out_of_stock'] = true;
        $this->assertFalse($this->catalog->quote('12')['in_stock']);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
            && ! str_ends_with($request->url(), '/login') && ! str_ends_with($request->url(), '/quote'));
    }

    public function test_repaired_try_currency_and_numeric_fees_do_not_normalize_raw_usd_prices(): void
    {
        $this->converted();
        $this->assertSame('150.66', $this->catalog->quote('12')['total_amount']);
        foreach ([['fixed_fee' => 'Array', 'percent_fee' => 'Array'],
            ['fixed_fee' => 0, 'percent_fee' => 'Array'], ['fixed_fee' => 1, 'percent_fee' => 0]] as $fee) {
            $this->fees = [['id' => 120] + $fee];
            $this->assertNull($this->catalog->quote('12'), 'Even a reviewed paid invoice cannot establish a missing current payment fee.');
        }
        $this->fees = [['id' => 120, 'fixed_fee' => 0, 'percent_fee' => 0]];
        $this->calculation = [];
        $this->assertNull($this->catalog->quote('12'), 'Native TRY balance is not proof that raw order prices changed from USD.');
    }

    public function test_throttled_combined_allowance_is_distinct_from_a_hard_cap_and_pending_billing(): void
    {
        $terms = ['charge_mode' => 'throttled', 'extra_charges' => false, 'counting' => 'combined',
            'period' => 'monthly', 'overuse_mbps' => 16];
        $row = $this->commercialMapping();
        $row['traffic_gb'] = 2048;
        $row['network_terms'] = $terms;
        $this->save($row);
        $this->assertNull($this->catalog->manifest(), 'The previous approval cannot be reused for changed terms.');
        $row['commercial_launch_approval']['pending'] = ['supplier_autorenew', 'cancellation_billing'];
        $row['commercial_launch_approval']['scope_sha256'] = VpsTcCatalog::launchScopeFingerprint($row);
        $this->save($row);
        $quote = $this->catalog->quote('12');
        $this->assertNotNull($quote);
        $this->assertSame($terms, $quote['network_terms']);
        $this->assertNull($quote['parent_cancel_stops_billing']);
        foreach ([['counting' => 'outbound'], ['period' => 'permanent'], ['overuse_mbps' => 0],
            ['overuse_mbps' => '16'], ['extra_charges' => true]] as $bad) {
            $changed = $row;
            $changed['network_terms'] = array_replace($terms, $bad);
            $changed['commercial_launch_approval']['scope_sha256'] = VpsTcCatalog::launchScopeFingerprint($changed);
            $this->save($changed);
            $this->assertNull($this->catalog->manifest());
        }
        foreach (['fa', 'en', 'tr'] as $locale) {
            $plan = new \App\Models\CloudPlan(['provider' => 'vpstc', 'traffic_gb' => 2048, 'network_terms' => $terms]);
            $label = $plan->trafficLabel($locale);
            $this->assertStringContainsString($locale === 'fa' ? '۱۶' : '16', $label);
            foreach (['hard limit', 'kesin sınır', 'سقف قطعی'] as $wrong) { $this->assertStringNotContainsString($wrong, $label); }
            $plan->network_terms = ['charge_mode' => 'throttled'];
            $this->assertStringNotContainsString('hard limit', $plan->trafficLabel('en'));
        }
    }

    public function test_paid_unit_is_free_only_at_the_reviewed_included_baseline_with_a_zero_cost_echo(): void
    {
        $this->product['config']['forms'][] = ['id' => '45', 'type' => 'slider', 'price' => 0, 'recurring_price' => 0, 'setup' => 0,
            'config' => ['minvalue' => '2', 'maxvalue' => '100', 'initialval' => '2', 'dontchargedefault' => '1'],
            'items' => [['id' => '124', 'value' => 2, 'price' => 0.5, 'unit_price' => 0.5, 'setup' => 0]]];
        $this->converted(['custom' => ['45' => ['124' => 2]], 'included_quantities' => ['45' => ['124' => 2]]]);
        $this->assertNotNull($this->catalog->quote('12'));
        $this->calculation['items'][0]['product']['config']['forms'][1]['value']['124'] = 3;
        $this->assertNull($this->catalog->quote('12'));
        $this->calculation['items'][0]['product']['config']['forms'][1]['value']['124'] = 2;
        $this->calculation['items'][0]['product']['config']['forms'][1]['price'] = '0.5';
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_native_server_group_requires_an_explicit_free_nested_selection_and_exact_calculated_echo(): void
    {
        $this->product['config']['forms'][] = ['id' => '48', 'type' => 'servergroupselector', 'name' => 'custom[48]',
            'price' => 0, 'recurring_price' => 0, 'setup' => 0, 'value' => ['128' => '1'],
            'items' => [['id' => '128', 'title' => 'Reviewed location', 'value' => '1',
                'price' => 0, 'unit_price' => 0, 'setup' => 0, 'selected' => true]]];
        $this->converted(['custom' => ['48' => ['128' => 1]]]);
        $this->calculation['items'][0]['product']['config']['forms'][0]['type'] = 'servergroupselector';
        $this->calculation['items'][0]['product']['config']['forms'][0]['value']['128'] = '1';
        $quote = $this->catalog->quote('12');
        $this->assertNotNull($quote);
        $this->assertSame('150.66', $quote['total_amount']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/quote')
            && $request['items'][0]['custom']['48'] === ['128' => 1]);
        $this->calculation['items'][0]['product']['config']['forms'][0]['value']['128'] = '2';
        $this->assertNull($this->catalog->quote('12'));
        $this->converted(['custom' => ['48' => ['128' => 1]]]);
        $this->calculation = [];
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_server_group_cannot_use_an_implicit_unknown_paid_or_non_unit_selection(): void
    {
        $this->product['config']['forms'][] = ['id' => '48', 'type' => 'servergroupselector',
            'price' => 0, 'recurring_price' => 0, 'setup' => 0, 'value' => ['128' => '1'],
            'items' => [['id' => '128', 'price' => 0, 'unit_price' => 0, 'setup' => 0, 'selected' => true]]];
        foreach ([[], ['48' => []], ['48' => '128'], ['48' => ['999' => 1]], ['48' => ['128' => 2]],
            ['48' => ['128' => 'main']], ['48' => ['128' => true]], ['48' => ['128' => 1, '129' => 1]]] as $custom) {
            $this->converted(['custom' => $custom]);
            $this->assertNull($this->catalog->quote('12'));
        }
        foreach ([['price' => '0.01'], ['unit_price' => '0.01'], ['price' => 'unknown'], ['setup' => '0.01']] as $charge) {
            $this->product['config']['forms'][1]['items'][0] = array_replace(
                ['id' => '128', 'price' => 0, 'unit_price' => 0, 'setup' => 0, 'selected' => true], $charge);
            $this->converted(['custom' => ['48' => ['128' => 1]]]);
            $this->assertNull($this->catalog->quote('12'));
        }
        $this->product['config']['forms'][1]['items'][0] = ['id' => '128', 'price' => 0, 'unit_price' => 0, 'setup' => 0];
        $this->product['config']['forms'][1]['type'] = 'unknownservergroupselector';
        $this->converted(['custom' => ['48' => ['128' => 1]]]);
        $this->assertNull($this->catalog->quote('12'));
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/quote'));
    }

    public function test_server_group_requires_a_fresh_calculation_even_when_direct_prices_and_fees_look_free(): void
    {
        $this->product['config']['forms'][] = ['id' => '48', 'type' => 'servergroupselector',
            'price' => 0, 'recurring_price' => 0, 'setup' => 0,
            'items' => [['id' => '128', 'price' => 0, 'setup' => 0]]];
        $this->converted(['custom' => ['48' => ['128' => 1]]]);
        $this->categoryProducts[0]['periods'][0]['price'] = '2.79';
        $this->fees = [['id' => 120, 'fixed_fee' => 0, 'percent_fee' => 0]];
        $this->calculation = [];
        $this->assertNull($this->catalog->quote('12'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/quote'));
    }

    public function test_description_unit_tax_anchor_or_default_review_change_closes_normalized_offer(): void
    {
        $this->converted();
        $this->product['description'] .= '<p>Changed resources</p>';
        $this->assertNull($this->catalog->quote('12'));
        $this->converted();
        $this->product['config']['product'][0]['items'][0]['formatted'] = '$2.79 Monthly';
        $this->assertNull($this->catalog->quote('12'));
        $this->converted();
        Setting::put('vpstc_supplier_tax_pct', '0');
        $this->assertNull($this->catalog->quote('12'));
        Setting::put('vpstc_supplier_tax_pct', '20');
        $this->anchorInvoice['rate'] = '46';
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_price_identity_is_stable_when_only_credit_coverage_changes(): void
    {
        $this->converted();
        $first = $this->catalog->quote('12');
        $this->calculation['summary']['total'] = 3.348;
        $this->calculation['summary']['credit'] = 0;
        $next = $this->catalog->quote('12');
        $this->assertSame($first['fingerprint'], $next['fingerprint']);
        $this->assertSame($first['total_amount'], $next['total_amount']);
        $this->assertNotSame($first['calculation_sha256'], $next['calculation_sha256']);
    }

    public function test_only_the_single_zero_cost_hostname_wrapper_is_accepted(): void
    {
        $this->converted();
        $this->assertNotNull($this->catalog->quote('12'));
        $this->calculation['items'][0]['domains']['preflight-12.example.invalid']['price'] = '0.01';
        $this->assertNull($this->catalog->quote('12'));
        $this->calculation['items'][0]['domains']['preflight-12.example.invalid']['price'] = 0;
        $this->calculation['items'][0]['domains']['preflight-12.example.invalid']['type'] = 'register';
        $this->assertNull($this->catalog->quote('12'));
        $this->converted();
        $this->calculation['items'][0]['index'] = 1;
        $this->assertNull($this->catalog->quote('12'));
    }

    public function test_zero_quantity_paid_storage_and_backup_require_reviewed_default_and_echo_evidence(): void
    {
        $this->product['config']['forms'][] = ['id' => '45', 'type' => 'slider', 'price' => 0, 'recurring_price' => 0, 'setup' => 0,
            'config' => ['minvalue' => '2', 'maxvalue' => '100', 'initialval' => '2', 'dontchargedefault' => '1'],
            'items' => [['id' => '124', 'value' => 2, 'price' => '0.5', 'setup' => 0]]];
        foreach (['46' => ['item' => '126', 'unit' => '0.1'], '47' => ['item' => '127', 'unit' => '1']] as $id => $option) {
            $this->product['config']['forms'][] = ['id' => (string) $id, 'type' => 'qty', 'price' => 0, 'recurring_price' => 0, 'setup' => 0,
                'config' => ['minvalue' => '0', 'maxvalue' => '100', 'initialval' => '0', 'dontchargedefault' => '1'],
                'items' => [['id' => $option['item'], 'value' => 0, 'price' => $option['unit'], 'setup' => 0]]];
        }
        $this->converted(['custom' => ['45' => ['124' => 2], '46' => ['126' => 0], '47' => ['127' => 0]],
            'included_quantities' => ['45' => ['124' => 2], '46' => ['126' => 0], '47' => ['127' => 0]]]);
        $this->assertNotNull($this->catalog->quote('12'));
        $this->assertCount(4, $this->calculation['items'][0]['product']['config']['forms']);
        $this->calculation['items'][0]['product']['config']['forms'][0]['unknown_fee'] = '0.001';
        $this->assertNull($this->catalog->quote('12'));
        $this->converted(['custom' => ['46' => ['126' => 0], '47' => ['127' => 0]],
            'included_quantities' => ['46' => ['126' => 0]]]);
        $this->assertNull($this->catalog->quote('12'));
    }
}
