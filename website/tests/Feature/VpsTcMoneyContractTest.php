<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcMoneyContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VpsTcMoneyContractTest extends TestCase
{
    use RefreshDatabase;

    private VpsTcApi $api;
    private array $invoice;
    private array $proof;
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::put('vpstc_supplier_tax_pct', '20');
        $this->api = new VpsTcApi('https://portal.vps.tc', 'fixture-reseller', 'fixture-password');
        $this->invoice = ['id' => '91', 'status' => 'Paid', 'currency' => 'TRY', 'rate' => '45',
            'subtotal' => '125.55', 'tax' => '25.11', 'tax2' => '0', 'grandtotal' => '150.66',
            'total' => '0', 'credit' => '150.66', 'discounts' => [],
            'items' => [['type' => 'Hosting', 'item_id' => '301', 'qty' => '1']]];
        $this->service = ['id' => '301', 'name' => 'Reviewed monthly VPS', 'billingcycle' => 'Monthly', 'date_created' => '2026-01-01'];
        $this->proof = ['version' => 1, 'account_fingerprint' => $this->api->accountFingerprint(),
            'base_currency' => 'USD', 'account_currency' => 'TRY', 'multiplier' => '45', 'payment_method' => '120',
            'source' => ['kind' => 'reviewed_portal_currency_table', 'sha256' => str_repeat('a', 64), 'reviewed_at' => now()->toIso8601String(),
                'url' => 'https://www.vps.tc/en/vps',
                'product_id' => '12', 'base_price' => '2.79', 'native_price' => '125.55'],
            'anchor' => ['invoice_id' => '91', 'service_id' => '301', 'product_id' => '12', 'cycle' => 'm',
                'base_price' => '2.79', 'tax_percent' => '20', 'service_sha256' => VpsTcMoneyContract::serviceFingerprint($this->service),
                'financial_sha256' => VpsTcMoneyContract::financialFingerprint($this->invoice)]];
        $this->save();
        Http::fake(fn ($r) => str_ends_with($r->url(), '/api/login')
            ? Http::response(['token' => 'fixture-token']) : (str_ends_with($r->url(), '/service/301')
                ? Http::response(['service' => $this->service]) : Http::response(['invoice' => $this->invoice])));
    }

    private function save(): void
    {
        Setting::putSecret(VpsTcMoneyContract::KEY, json_encode($this->proof, JSON_THROW_ON_ERROR));
    }

    private function money(): VpsTcMoneyContract { return new VpsTcMoneyContract($this->api); }

    private function quote(): array
    {
        return ['summary' => ['subtotal' => '2.79', 'cost' => '2.79', 'total' => '0', 'credit' => '3.348',
            'tax' => [['name' => 'KDV', 'tax' => 0.5579999999999999, 'value' => '20']],
            'recurring' => [['value' => 'm', 'price' => '3.348']]],
            'items' => [['valid' => true, 'product' => ['id' => '12', 'price' => '2.79', 'setup' => '0',
                'domain' => 'preflight-12.example.invalid', 'recurring' => 'm',
                'config' => ['forms' => [], 'product' => [['id' => 'cycle', 'value' => 'm', 'price' => '2.79', 'setup' => '0']]]]]]];
    }

    public function test_blank_balance_currency_needs_encrypted_fresh_account_bound_financial_evidence(): void
    {
        $this->assertSame('TRY', $this->money()->resolveCurrency(['currency' => '']));
        $this->assertNotNull($this->money()->verified());
        $this->assertStringNotContainsString('fixture-reseller', Setting::get(VpsTcMoneyContract::KEY));
        $this->proof['account_fingerprint'] = str_repeat('b', 64);
        $this->save();
        $this->assertNull($this->money()->resolveCurrency(['currency' => '']));
        $this->assertNull($this->money()->resolveCurrency(['currency' => null]));
        $this->assertNull($this->money()->resolveCurrency(['currency' => '$']));
        $this->assertSame('USD', $this->money()->resolveCurrency(['currency' => 'USD']));
    }

    public function test_no_evidence_is_not_a_currency_guess_or_an_outbound_request(): void
    {
        Setting::putSecret(VpsTcMoneyContract::KEY, null);
        $this->assertNull($this->money()->resolveCurrency(['currency' => '']));
        $this->assertSame('TRY', $this->money()->resolveCurrency(['ok' => true, 'data' => ['details' => ['currency' => 'TRY']]]));
        $this->assertNull($this->money()->resolveCurrency(['ok' => true, 'data' => ['details' => 'Array']]));
        Http::assertNothingSent();
    }

    public function test_fresh_anchor_change_or_unreconciled_credit_invalidates_the_reviewed_contract(): void
    {
        foreach ([['rate' => '46'], ['currency' => 'USD'], ['status' => 'Unpaid'],
            ['credit' => '0'], ['tax' => '0'], ['discounts' => [['amount' => '1']]]] as $change) {
            $original = $this->invoice;
            $this->invoice = array_replace($this->invoice, $change);
            $this->assertNull($this->money()->verified());
            $this->invoice = $original;
        }
    }

    public function test_computed_credit_and_tax_are_normalized_to_the_native_category_amount(): void
    {
        $result = $this->money()->normalizeQuote('12', '120', ['price' => '2.79', 'setup' => '0'],
            ['price' => '125.55', 'setup' => '0'], $this->quote());
        $this->assertSame('125.55', $result['monthly_amount']);
        $this->assertSame('150.66', $result['total_amount']);
        $this->assertSame('150.66', $result['renewal_total_amount']);
        $this->assertSame('TRY', $result['currency']);
        $this->assertSame('45', $result['multiplier']);
        $this->assertFalse($result['configuration_verified']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ! str_ends_with($r->url(), '/login'));
    }

    public function test_nonzero_extras_wrong_product_method_tax_period_or_unit_never_normalize(): void
    {
        $quote = $this->quote();
        foreach (['fee', 'discount', 'tax2'] as $field) {
            $quote['summary'][$field] = '0.01';
            $this->assertNull($this->money()->normalizeQuote('12', '120', ['price' => '2.79', 'setup' => '0'], ['price' => '125.55', 'setup' => '0'], $quote));
            unset($quote['summary'][$field]);
        }
        foreach ([['product' => '13', 'method' => '120', 'native' => '125.55'],
            ['product' => '12', 'method' => '121', 'native' => '125.55'],
            ['product' => '12', 'method' => '120', 'native' => '125.56']] as $bad) {
            $this->assertNull($this->money()->normalizeQuote($bad['product'], $bad['method'], ['price' => '2.79', 'setup' => '0'], ['price' => $bad['native'], 'setup' => '0'], $quote));
        }
        $quote['summary']['credit'] = '3.349';
        $this->assertNull($this->money()->normalizeQuote('12', '120', ['price' => '2.79', 'setup' => '0'], ['price' => '125.55', 'setup' => '0'], $quote));
    }

    public function test_anchor_product_source_and_service_projection_must_match_the_review(): void
    {
        $this->proof['anchor']['product_id'] = '13'; $this->save();
        $this->assertNull($this->money()->verified());
        $this->proof['anchor']['product_id'] = '12'; $this->save();
        $this->service['name'] = 'Different supplier product';
        $this->assertNull($this->money()->verified());
    }

    public function test_configuration_echo_is_required_for_financial_use_but_not_diagnostic_units(): void
    {
        $quote = $this->quote();
        $quote['items'][0]['product']['config']['forms'] = [['id' => '44', 'value' => ['123' => 1],
            'price' => 0, 'recurring_price' => 0, 'setup' => 0]];
        $diagnostic = $this->money()->normalizeQuote('12', '120', ['price' => '2.79', 'setup' => 0], ['price' => '125.55', 'setup' => 0], $quote);
        $this->assertFalse($diagnostic['configuration_verified']);
        $this->assertTrue($this->money()->normalizeQuote('12', '120', ['price' => '2.79', 'setup' => 0], ['price' => '125.55', 'setup' => 0], $quote, ['44' => ['123' => 1]])['configuration_verified']);
        $this->assertNull($this->money()->normalizeQuote('12', '120', ['price' => '2.79', 'setup' => 0], ['price' => '125.55', 'setup' => 0], $quote, ['44' => ['123' => 2]]));
    }

    public function test_binary_float_tails_are_normalized_but_exact_strings_or_new_charges_are_not(): void
    {
        $quote = $this->quote();
        $this->assertNotNull($this->money()->normalizeQuote('12', '120', ['price' => 2.79, 'setup' => 0], ['price' => 125.55000000000001, 'setup' => 0], $quote));
        $this->assertNull($this->money()->normalizeQuote('12', '120', ['price' => '2.79', 'setup' => 0], ['price' => '125.55000000000001', 'setup' => 0], $quote));
        foreach ([['fee' => '0.000000001'], ['unknown_surcharge' => 1], ['cost' => '3'], ['grandtotal' => '0'], ['valid' => false]] as $bad) {
            $changed = $quote; $changed['summary'] = array_replace($changed['summary'], $bad);
            $this->assertNull($this->money()->normalizeQuote('12', '120', ['price' => '2.79', 'setup' => 0], ['price' => '125.55', 'setup' => 0], $changed));
        }
        $quote['items'][0]['error'] = ['invalid configuration'];
        $this->assertNull($this->money()->normalizeQuote('12', '120', ['price' => '2.79', 'setup' => 0], ['price' => '125.55', 'setup' => 0], $quote));
    }
}
