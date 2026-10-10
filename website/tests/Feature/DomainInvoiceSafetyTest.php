<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Services\Domain\DomainRegistrar;
use App\Services\Domain\DomainRenewalInvoicer;
use App\Services\Domain\LiveDomainPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SupplierDomainPrices;
use Tests\TestCase;

class DomainInvoiceSafetyTest extends TestCase
{
    use RefreshDatabase;
    use SupplierDomainPrices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSupplierPrices();
        Http::fake($this->supplierPriceStubs());
    }

    private function domain(string $status = 'pending'): Domain
    {
        $c = Customer::create(['email' => 'invoice@example.test', 'password' => 'x', 'status' => 'active']);

        return Domain::create(['customer_id' => $c->id, 'domain' => 'invoice.com', 'sld' => 'invoice',
            'tld' => 'com', 'status' => $status, 'provision_status' => 'done', 'period_years' => 1,
            'op_id' => 777, 'expires_at' => now()->subDays(40)]);
    }

    private function invoice(Domain $d, int $host = 0, bool $mixed = false): Invoice
    {
        $i = Invoice::create(['customer_id' => $d->customer_id, 'domain_id' => $d->id,
            'kind' => $mixed ? 'service' : 'domain', 'currency_code' => 'IRT', 'subtotal' => $host + 200000,
            'tax' => 0, 'total' => $host + 200000, 'paid' => 0, 'status' => 'unpaid']);
        if ($mixed) {
            InvoiceItem::create(['invoice_id' => $i->id, 'title' => 'Hosting', 'quantity' => 1,
                'unit_price' => $host, 'line_total' => $host, 'tax_rate_bp' => 0, 'tax_amount' => 0]);
        }
        InvoiceItem::create(['invoice_id' => $i->id, 'title' => 'ثبتِ دامنهٔ '.$d->domain, 'quantity' => 1,
            'unit_price' => 200000, 'line_total' => 200000, 'tax_rate_bp' => 0, 'tax_amount' => 0]);

        return $i;
    }

    public function test_expensive_hosting_cannot_cover_an_underpriced_domain_at_payment(): void
    {
        $d = $this->domain();
        $i = $this->invoice($d, 5000000, true);
        $this->expectException(ValidationException::class);
        app(LiveDomainPricing::class)->assertInvoice($i);
    }

    public function test_expensive_hosting_cannot_cover_an_underpriced_paid_domain_at_delivery(): void
    {
        $d = $this->domain();
        $i = $this->invoice($d, 5000000, true);
        $i->update(['status' => 'paid', 'paid' => $i->total]);
        $d->update(['invoice_id' => $i->id]);
        $this->expectException(ValidationException::class);
        app(LiveDomainPricing::class)->assertDelivery($d, 'create');
    }

    #[DataProvider('hostingPrices')]
    public function test_review_reprices_only_the_domain_line_and_preserves_hosting(int $host): void
    {
        config(['billing.tax_percent' => 10]);
        Setting::put('tax_percent', '10');
        $d = $this->domain();
        $i = $this->invoice($d, $host, true);
        $hosting = $i->items()->where('title', 'Hosting')->firstOrFail();
        $before = $hosting->getAttributes();
        app(LiveDomainPricing::class)->reviewInvoice($i);
        $this->assertSame($before, $hosting->fresh()->getAttributes());
        $this->assertSame(1080000, $i->items()->where('id', '!=', $hosting->id)->firstOrFail()->line_total);
        $this->assertSame($host + 1080000, $i->fresh()->subtotal);
        $this->assertSame((int) $i->items()->sum('tax_amount'), $i->fresh()->tax);
        $this->assertSame($i->subtotal + $i->tax, $i->total);
        app(LiveDomainPricing::class)->assertInvoice($i);
    }

    public static function hostingPrices(): array
    {
        return [[100000], [5000000]];
    }

    public function test_a_mixed_invoice_without_an_identifiable_domain_line_is_blocked(): void
    {
        $d = $this->domain();
        $i = $this->invoice($d, 5000000, true);
        $i->items()->where('title', '!=', 'Hosting')->update(['title' => 'Unidentified item']);
        $this->expectException(ValidationException::class);
        app(LiveDomainPricing::class)->assertInvoice($i);
    }

    private function paymentAttempt(Invoice $i, string $channel): void
    {
        if ($channel === 'gateway') {
            $i->payments()->create(['customer_id' => $i->customer_id, 'gateway' => 'bale',
                'currency_code' => 'IRT', 'amount' => $i->total, 'status' => 'redirected', 'external_ref' => 'attempt']);
        } elseif ($channel === 'bank') {
            DB::table('bank_transfer_receipts')->insert(['invoice_id' => $i->id, 'customer_id' => $i->customer_id,
                'amount' => $i->total, 'reference' => 'attempt', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('crypto_payments')->insert(['invoice_id' => $i->id, 'customer_id' => $i->customer_id,
                'chain' => 'tron', 'asset' => 'USDT', 'network' => 'TRC20', 'address' => 'TEST-ADDRESS',
                'amount_atomic' => 2000000, 'invoice_amount' => $i->total, 'invoice_currency' => 'IRT',
                'rate_micro' => 100000000000, 'status' => 'pending', 'expires_at' => now()->addHour()]);
        }
    }

    #[DataProvider('paymentChannels')]
    public function test_price_review_never_mutates_an_invoice_with_money_in_flight(string $channel): void
    {
        $d = $this->domain();
        $i = $this->invoice($d);
        $this->paymentAttempt($i, $channel);
        try {
            app(LiveDomainPricing::class)->reviewInvoice($i);
            $this->fail('In-flight amount changed');
        } catch (ValidationException) {
            $this->assertSame(200000, $i->fresh()->total);
            $this->assertSame(200000, $i->items()->firstOrFail()->line_total);
        }
    }

    public static function paymentChannels(): array
    {
        return [['gateway'], ['bank'], ['crypto']];
    }

    public function test_renewal_reissue_does_not_change_years_or_amount_during_payment(): void
    {
        $d = $this->domain('active');
        $i = app(DomainRenewalInvoicer::class)->issue($d);
        $this->paymentAttempt($i, 'gateway');
        app(DomainRenewalInvoicer::class)->issue($d, 3);
        $this->assertSame(1080000, $i->fresh()->subtotal);
        $this->assertSame(1, $d->fresh()->renewYears());
    }

    public function test_restore_replaces_an_unpaid_renewal_and_reuses_only_its_own_invoice(): void
    {
        $d = $this->domain('expired');
        $old = $this->invoice($d);
        $d->putMeta(['renew_invoice_id' => $old->id, 'renew_years' => 3]);
        $this->actingAs($d->customer, 'customer')->post('/account/domains/'.$d->id.'/restore')->assertSessionHasNoErrors();
        $new = Invoice::latest('id')->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('canceled', $old->fresh()->status);
        $this->assertSame(3240000, $new->subtotal);
        $this->assertSame($new->id, data_get($d->fresh()->meta, 'restore_invoice_id'));
        $this->assertNull(data_get($d->fresh()->meta, 'renew_invoice_id'));
        $this->actingAs($d->customer, 'customer')->post('/account/domains/'.$d->id.'/restore')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_partial_renewal_is_preserved_and_restore_requires_reconciliation(): void
    {
        $d = $this->domain('expired');
        $i = $this->invoice($d);
        $i->update(['status' => 'partial', 'paid' => 100000]);
        $d->putMeta(['renew_invoice_id' => $i->id]);
        $this->actingAs($d->customer, 'customer')->post('/account/domains/'.$d->id.'/restore')->assertSessionHasErrors('domain');
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame('partial', $i->fresh()->status);
        $this->assertSame(100000, $i->fresh()->paid);
        $this->assertNull(data_get($d->fresh()->meta, 'restore_invoice_id'));
    }

    #[DataProvider('unreadableStates')]
    public function test_accepted_restore_with_unverified_followup_renewal_stays_manual(string $state): void
    {
        $d = $this->domain('expired');
        $i = $this->paidDomainInvoice($d, 'restore', 5000000);
        $d->update(['provision_status' => 'pending']);
        Http::swap(new Factory);
        $second = match ($state) {
            'error' => Http::response([], 503),
            'missing' => Http::response(['code' => 0, 'data' => ['status' => 'ACT']]),
            'past' => Http::response(['code' => 0, 'data' => ['expiration_date' => now()->subDays(40)->toDateString()]]),
        };
        Http::fake([
            ...$this->supplierPriceStubs(),
            '*/domains/777/restore*' => Http::response(['code' => 0, 'data' => []]),
            '*/domains/777/renew*' => Http::response(['code' => 0, 'data' => []]),
            '*/domains/777' => Http::sequence()
                ->push(['code' => 0, 'data' => ['expiration_date' => now()->subDays(40)->toDateString()]])
                ->pushResponse($second),
        ]);
        $result = app(DomainRegistrar::class)->restorePaid($d);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['manual']);
        $this->assertStringContainsString('پس از تمدید', $result['message']);
        $this->assertSame('expired', $d->fresh()->status);
        $this->assertSame('manual', $d->fresh()->provision_status);
        $this->assertSame($i->id, data_get($d->fresh()->meta, 'restore_supplier_accepted_invoice_id'));
        $this->assertSame('paid', $i->fresh()->status);
        $d->update(['provision_status' => 'pending']);
        app(DomainRegistrar::class)->restorePaid($d->fresh());
        $billable = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'
            && (str_contains($pair[0]->url(), '/restore') || str_contains($pair[0]->url(), '/renew')));
        $this->assertCount(2, $billable);
    }

    public static function unreadableStates(): array
    {
        return [['error'], ['missing'], ['past']];
    }
}
