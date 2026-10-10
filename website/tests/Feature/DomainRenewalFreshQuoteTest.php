<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Domain\DomainRegistrar;
use App\Services\Domain\DomainRenewalInvoicer;
use App\Services\Domain\LiveDomainPricing;
use App\Services\Domain\Reseller\ResellerOrderService;
use App\Services\Payment\GatewayRegistry;
use App\Services\Payment\PaymentService;
use App\Services\Payment\ZarinPalGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DomainRenewalFreshQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_restore_is_not_automatically_refunded_or_purchased_again(): void
    {
        $this->prices();
        $d = $this->domain();
        $i = $this->cheapInvoice($d);
        $i->update(['status' => 'paid', 'paid' => $i->total]);
        $d->putMeta(['renew_invoice_id' => null, 'restore_invoice_id' => $i->id,
            'restore_supplier_accepted_invoice_id' => $i->id]);
        $d->update(['status' => 'expired', 'provision_status' => 'manual']);
        DB::table('domains')->where('id', $d->id)->update(['updated_at' => now()->subHours(25)]);
        $this->artisan('domains:resolve-stuck', ['--hours' => 24])->assertExitCode(0);
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertDatabaseCount('credit_ledger', 0);
        $d->refresh()->update(['provision_status' => 'pending']);
        $result = app(DomainRegistrar::class)->restorePaid($d->fresh());
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['manual']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/domains/') && $r->method() === 'POST');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openprovider.username' => 'u', 'services.openprovider.password' => 'p', 'domain_pricing.supplier_vat_pct' => 20, 'domain_pricing.supplier_fee_pct' => 0, 'domain_reseller.min_margin_pct' => 8]);
        Setting::put('pricing_rate_override', '100000');
        Setting::put('domain_margin_pct', '0');
        Http::preventStrayRequests();
    }

    private function prices(float $renew = 30.2, float $create = .98): void
    {
        Http::swap(new Factory);
        Http::fake(['*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]), '*/resellers*' => Http::response(['code' => 0, 'data' => ['vatperc' => 20]]), '*/domains/prices*' => function ($r) use ($renew, $create) {
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);

            return Http::response(['code' => 0, 'data' => ['price' => ['reseller' => ['price' => $q['operation'] === 'create' ? $create : $renew, 'currency' => 'EUR']]]]);
        }]);
    }

    private function domain(): Domain
    {
        $c = Customer::create(['email' => 'fq@example.com', 'phone' => '09121112222', 'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);

        return Domain::create(['customer_id' => $c->id, 'domain' => 'customer.shop', 'sld' => 'customer', 'tld' => 'shop', 'status' => 'active', 'provision_status' => 'done', 'period_years' => 1, 'price_toman' => 100000, 'renew_toman' => 200000, 'cost_amount' => 98, 'cost_renew_amount' => 98, 'cost_currency' => 'EUR', 'op_id' => 777, 'expires_at' => now()->addDays(10)]);
    }

    public function test_five_year_renewal_uses_actual_name_renewal_vat_and_margin(): void
    {
        $this->prices();
        $d = $this->domain();
        $i = app(DomainRenewalInvoicer::class)->issue($d, 5);
        $this->assertSame(19570000, $i->subtotal);
        $this->assertSame(18120000, data_get($d->fresh()->meta, 'live_price.wholesale_toman'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/domains/prices') && $r['domain.name'] === 'customer' && $r['operation'] === 'renew' && $r['period'] == 1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/domains/check'));
    }

    public function test_create_quotes_whole_selected_term_instead_of_multiplying_promo_price(): void
    {
        $this->prices(create: 121.78);
        $q = app(LiveDomainPricing::class)->quote('realname', 'shop', 'create', 5);
        $this->assertEquals(121.78, $q['net_amount']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/domains/prices') && $r['operation'] === 'create' && $r['period'] == 5 && $r['domain.name'] === 'realname');
    }

    public function test_transfer_quotes_actual_name_and_distinct_operation(): void
    {
        $this->prices();
        $q = app(LiveDomainPricing::class)->quote('premium-name', 'shop', 'transfer');
        $this->assertSame(3914000, $q['sell_toman']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/domains/prices') && $r['operation'] === 'transfer' && $r['domain.name'] === 'premium-name');
    }

    public function test_outage_never_creates_invoice_from_stored_registration_price(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $d = $this->domain();
        try {
            app(DomainRenewalInvoicer::class)->issue($d);
            $this->fail('Unverified invoice issued');
        } catch (ValidationException) {
            $this->assertDatabaseCount('invoices', 0);
        }
    }

    public function test_unknown_supplier_vat_blocks_price_and_purchase_requests(): void
    {
        Http::fake(['*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]), '*/resellers*' => Http::response(['code' => 0, 'data' => []])]);
        try {
            app(LiveDomainPricing::class)->quote('x', 'shop', 'renew');
            $this->fail('Unknown VAT accepted');
        } catch (ValidationException) {
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '/domains/prices') || $r->method() === 'POST' && ! str_contains($r->url(), '/auth/login'));
        }
    }

    public function test_product_price_cannot_replace_missing_reseller_price(): void
    {
        Http::fake(['*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]), '*/resellers*' => Http::response(['code' => 0, 'data' => ['vatperc' => 20]]), '*/domains/prices*' => Http::response(['code' => 0, 'data' => ['price' => ['product' => ['price' => .98, 'currency' => 'EUR']]]])]);
        $this->expectException(ValidationException::class);
        app(LiveDomainPricing::class)->quote('x', 'shop', 'renew');
    }

    public function test_stale_invoice_blocks_payment_and_delivery(): void
    {
        $this->prices();
        $d = $this->domain();
        $i = Invoice::create(['customer_id' => $d->customer_id, 'domain_id' => $d->id, 'kind' => 'domain', 'currency_code' => 'IRT', 'subtotal' => 200000, 'tax' => 0, 'total' => 200000, 'paid' => 0, 'status' => 'unpaid']);
        $d->putMeta(['renew_invoice_id' => $i->id, 'renew_years' => 5]);
        try {
            app(LiveDomainPricing::class)->assertInvoice($i);
            $this->fail('Cheap payment accepted');
        } catch (ValidationException) {
            $this->assertSame(0, $i->fresh()->paid);
        }
        $i->update(['status' => 'paid', 'paid' => 200000]);
        $this->expectException(ValidationException::class);
        app(LiveDomainPricing::class)->assertDelivery($d, 'renew', 5);
    }

    public function test_price_rise_after_payment_blocks_execution(): void
    {
        $this->prices();
        $d = $this->domain();
        $i = app(DomainRenewalInvoicer::class)->issue($d);
        $i->update(['status' => 'paid', 'paid' => $i->total]);
        $this->prices(renew: 50);
        $this->expectException(ValidationException::class);
        app(LiveDomainPricing::class)->assertDelivery($d->fresh(), 'renew');
    }

    public function test_restore_reserves_restore_and_possible_renewal(): void
    {
        $this->prices();
        $q = app(LiveDomainPricing::class)->quote('x', 'shop', 'restore');
        $this->assertSame(7828000, $q['sell_toman']);
        $this->assertSame(3624000, $q['restore_wholesale_toman']);
    }

    public function test_supplier_cost_metadata_is_hidden_in_serialization(): void
    {
        $d = $this->domain();
        $d->putMeta(['live_price' => ['net_amount' => 30.2], 'source' => 'retail']);
        $this->assertArrayNotHasKey('live_price', $d->toArray()['meta']);
        $this->assertSame('retail', $d->toArray()['meta']['source']);
    }

    private function cheapInvoice(Domain $d): Invoice
    {
        $i = Invoice::create(['customer_id' => $d->customer_id, 'domain_id' => $d->id, 'kind' => 'domain', 'currency_code' => 'IRT', 'subtotal' => 200000, 'tax' => 0, 'total' => 200000, 'paid' => 0, 'status' => 'unpaid']);
        $d->putMeta(['renew_invoice_id' => $i->id, 'renew_years' => 5]);

        return $i;
    }

    public function test_credit_payment_does_not_debit_wallet_for_underpriced_domain(): void
    {
        $this->prices();
        $d = $this->domain();
        $i = $this->cheapInvoice($d);
        CreditEntry::create(['customer_id' => $d->customer_id, 'currency_code' => 'IRT', 'amount' => 25000000, 'balance_after' => 25000000, 'reason' => 'test']);
        $this->actingAs($d->customer, 'customer')->post('/account/invoices/'.$i->id.'/pay-credit')->assertSessionHasErrors('domain');
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $i->fresh()->paid);
    }

    public function test_gateway_is_not_contacted_for_underpriced_domain(): void
    {
        $this->prices();
        $d = $this->domain();
        $i = $this->cheapInvoice($d);
        $registry = new GatewayRegistry;
        $registry->register(new ZarinPalGateway('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', sandbox: false));
        $result = (new PaymentService($registry))->begin($i, 'zarinpal', Request::create('/', 'POST'));
        $this->assertFalse($result->ok);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'zarinpal'));
    }

    public function test_paid_underpriced_renewal_never_calls_supplier_purchase(): void
    {
        $this->prices();
        $d = $this->domain();
        $i = $this->cheapInvoice($d);
        $i->update(['status' => 'paid', 'paid' => $i->total]);
        try {
            app(DomainRegistrar::class)->renew($d, 5);
            $this->fail('Underpriced domain purchased');
        } catch (ValidationException) {
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '/domains/') && $r->method() === 'POST');
        }
    }

    public function test_existing_unpaid_quote_can_be_reviewed_again_without_duplicate_invoice(): void
    {
        $this->prices();
        $d = $this->domain();
        $i = $this->cheapInvoice($d);
        $updated = app(DomainRenewalInvoicer::class)->issue($d, 5);
        $this->assertSame($i->id, $updated->id);
        $this->assertSame(19570000, $updated->subtotal);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_invoice_display_refreshes_an_old_unpaid_quote_before_payment(): void
    {
        $this->prices();
        $d = $this->domain();
        $i = $this->cheapInvoice($d);
        $this->actingAs($d->customer, 'customer')->get('/account/invoices/'.$i->id)->assertOk();
        $this->assertSame(19570000, $i->fresh()->subtotal);
        $this->assertSame(0, $i->fresh()->paid);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_reseller_discount_cannot_cross_live_cost_floor(): void
    {
        $this->prices();
        Setting::put('domain_margin_pct', '30');
        config(['domain_reseller.promote_instantly' => false]);
        $d = $this->domain();
        $c = $d->customer;
        $c->forceFill(['is_reseller' => true, 'reseller_bonus_pct' => 80])->save();
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => 25000000, 'balance_after' => 25000000, 'reason' => 'test']);
        $result = app(ResellerOrderService::class)->renew($c, $d, 1);
        $this->assertTrue($result['ok']);
        $this->assertSame(3914000, $result['invoice']->subtotal);
        $this->assertTrue($result['pricing']['floored']);
        $this->assertEquals(round((1 - 3914000 / $result['pricing']['retail']) * 100, 2), $result['pricing']['applied_pct']);
    }
}
