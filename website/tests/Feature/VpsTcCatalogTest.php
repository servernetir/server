<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcCatalog;
use App\Services\Cloud\VpsTcPricing;
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
        $this->product = ['id' => '12', 'in_stock' => true, 'config' => [
            'product' => [['id' => 'cycle', 'items' => [['value' => 'm', 'price' => 2.79, 'setup' => 1.25]]]],
            'forms' => [['id' => '44', 'type' => 'select', 'required' => true, 'price' => 0,
                'setup' => 0, 'recurring_price' => 0, 'value' => [], 'items' => [
                    ['id' => '123', 'title' => 'Ubuntu 24.04', 'price' => 0, 'setup' => 0, 'selected' => false],
                ]]],
        ]];
        $this->payments = ['120' => 'Credit Balance'];
        $this->fees = [['id' => 120, 'fixed_fee' => '0', 'percent_fee' => '0']];
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://portal.vps.tc/api/login' => Http::response(['token' => 'fixture-token']),
                'https://portal.vps.tc/api/balance' => Http::response(['details' => ['currency' => $this->currency, 'acc_credit' => '100.00', 'acc_balance' => '0']]),
                'https://portal.vps.tc/api/order/12' => Http::response(['product' => $this->product]),
                'https://portal.vps.tc/api/payment' => Http::response(['payments' => $this->payments]),
                'https://portal.vps.tc/api/payment/fees' => Http::response(['payments' => $this->fees]),
                default => Http::response(['error' => 'Unknown fixture endpoint'], 404),
            };
        });
    }

    private function mapping(array $overrides = []): array
    {
        return array_replace([
            'product_id' => '12', 'kind' => 'vps', 'cycle' => 'm',
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
            'setup_eur_cents' => 0, 'total_amount' => '0.01', 'fingerprint' => 'stale']));
        $quote = $this->catalog->quote('12');
        $this->assertSame('USD', $quote['currency']);
        $this->assertSame(176, $quote['cost_eur_cents']);
        $this->assertSame(79, $quote['setup_eur_cents']);
        $this->assertSame('4.85', $quote['total_amount']);
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
}
