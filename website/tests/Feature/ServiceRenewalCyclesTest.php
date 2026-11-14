<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Services\Billing\InvoiceCanceller;
use App\Services\Billing\ServiceRenewals;
use App\Services\Payment\PaymentService;
use App\Services\ProductPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceRenewalCyclesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.internal_service_ids' => []]);
        $this->travelTo(now()->setDate(2026, 11, 14)->startOfDay());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Mail::fake();
        $pricing = \Mockery::mock(ProductPricing::class)->makePartial();
        $pricing->shouldReceive('rate')->andReturn(100000);
        $this->app->instance(ProductPricing::class, $pricing);
    }

    private function fixtures(string $type = 'whm', string $status = 'active'): array
    {
        $c = Customer::create(['email' => uniqid().'@example.test', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'fixture', 'status' => 'active', 'locale' => 'fa']);
        $server = Server::create(['name' => 'Fixture', 'type' => $type, 'hostname' => 'panel.example.test',
            'username' => 'admin', 'api_token' => 'fixture', 'status' => 'active', 'country' => 'IR']);
        $p = Product::create(['name' => 'Fixture plan', 'slug' => 'fixture-hosting-'.uniqid(), 'category' => 'shared',
            'group' => $type === 'plesk' ? 'windows' : 'woocommerce', 'plan' => 'fixture-plan-'.uniqid(), 'currency_code' => 'IRT',
            'base_price_eur' => '5.90', 'base_setup_eur' => '8', 'tax_percent' => 10, 'cycle' => 'monthly', 'is_active' => true]);
        $s = Service::create(['customer_id' => $c->id, 'server_id' => $server->id, 'name' => $p->name,
            'plan' => $p->plan, 'domain' => 'fixture.example.test', 'username' => 'fixture', 'currency_code' => 'IRT',
            'cycle' => 'monthly', 'price' => 590000, 'tax_percent' => 10, 'status' => $status, 'provision_status' => 'done',
            'activated_at' => '2026-10-30', 'next_due_at' => $status === 'suspended' ? '2026-11-05' : '2026-11-30']);
        return [$s, $p];
    }

    private function issue(Service $s, string $cycle = 'quarterly'): Invoice
    {
        $r = app(ServiceRenewals::class);
        $q = $r->quote($s, $cycle);
        return $r->issue($s, $cycle, $q, $r->hash($q));
    }

    public function test_catalog_periods_use_exact_purchase_price_discount_and_tax_without_setup(): void
    {
        foreach (['whm', 'plesk'] as $type) {
            [$s, $p] = $this->fixtures($type);
            $r = app(ServiceRenewals::class);
            $options = $r->options($s);
            $this->assertSame($p->orderCycles(), array_keys($options));
            foreach ($options as $cycle => $q) {
                $this->assertSame($p->priceForCycle($cycle, 'IR'), $q['price']);
                $this->assertSame((int) round($q['price'] * .1), $q['tax']);
            }
            $this->assertSame(1680000, $options['quarterly']['price']);
            $invoice = $this->issue($s);
            $this->assertSame(1848000, $invoice->total);
            $this->assertSame(1, $invoice->items()->count());
            $this->assertSame('quarterly', $invoice->items->first()->marketing_context['billing_cycle']);
            $this->assertSame('renewal', $invoice->items->first()->marketing_context['operation']);
            $this->assertSame('2026-11-30', $s->fresh()->next_due_at->toDateString());
            Http::assertNothingSent();
        }
    }

    public function test_replacement_cancels_all_old_unpaid_invoices_and_repeat_submission_is_idempotent(): void
    {
        [$s] = $this->fixtures();
        foreach (range(1, 2) as $unused) {
            Invoice::create(['customer_id' => $s->customer_id, 'service_id' => $s->id, 'kind' => 'service',
                'currency_code' => 'IRT', 'total' => 649000, 'status' => 'unpaid']);
        }
        $r = app(ServiceRenewals::class);
        $q = $r->quote($s, 'quarterly');
        $invoice = $r->issue($s, 'quarterly', $q, $r->hash($q));
        $again = $r->issue($s, 'quarterly', $q, $r->hash($q));
        $this->assertSame($invoice->id, $again->id);
        $this->assertSame(2, $s->invoices()->where('status', 'canceled')->count());
        $this->assertSame(1, $s->invoices()->outstanding()->count());
        $this->assertSame('quarterly', $s->fresh()->cycle);
    }

    public function test_full_payment_advances_selected_period_once_from_original_boundary(): void
    {
        foreach (['quarterly', 'semiannual', 'yearly'] as $cycle) {
            [$s] = $this->fixtures();
            $originalDue = $s->next_due_at->copy();
            $invoice = $this->issue($s, $cycle);
            $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $s->customer_id,
                'gateway' => 'credit', 'currency_code' => 'IRT', 'amount' => $invoice->total, 'status' => 'pending']);
            $payments = app(PaymentService::class);
            $payments->settleConfirmed($payment, 'fixture-'.$payment->id);
            $payments->settleConfirmed($payment->fresh(), 'fixture-'.$payment->id);
            $this->assertSame($originalDue->addMonthsNoOverflow(Service::monthsIn($cycle))->toDateString(), $s->fresh()->next_due_at->toDateString());
            $this->assertSame($cycle, $s->fresh()->cycle);
            $this->assertSame('paid', $invoice->fresh()->status);
        }
    }

    public function test_suspended_service_stays_suspended_and_due_date_stays_unchanged_until_payment(): void
    {
        [$s] = $this->fixtures(status: 'suspended');
        $invoice = $this->issue($s);
        $this->assertSame('suspended', $s->fresh()->status);
        $this->assertSame('2026-11-05', $s->fresh()->next_due_at->toDateString());
        $this->assertTrue(app(InvoiceCanceller::class)->cancel($invoice, 'fixture'));
        $this->assertSame('2026-11-05', $s->fresh()->next_due_at->toDateString());
    }

    public function test_payment_started_after_preview_blocks_replacement_without_changing_terms(): void
    {
        [$s] = $this->fixtures();
        $old = Invoice::create(['customer_id' => $s->customer_id, 'service_id' => $s->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'total' => 649000, 'status' => 'unpaid']);
        $r = app(ServiceRenewals::class);
        $q = $r->quote($s, 'quarterly');
        Payment::create(['invoice_id' => $old->id, 'customer_id' => $s->customer_id,
            'gateway' => 'zarinpal', 'currency_code' => 'IRT', 'amount' => 649000, 'status' => 'redirected']);
        try { $r->issue($s, 'quarterly', $q, $r->hash($q)); $this->fail('Must block pending payment'); }
        catch (ValidationException) {
            $this->assertSame('unpaid', $old->fresh()->status);
            $this->assertSame('monthly', $s->fresh()->cycle);
        }
    }

    public function test_catalog_price_change_invalidates_preview_and_invalid_cycles_are_rejected(): void
    {
        [$s, $p] = $this->fixtures();
        $r = app(ServiceRenewals::class);
        $q = $r->quote($s, 'quarterly');
        $p->update(['base_price_eur' => '8.90']);
        try { $r->issue($s, 'quarterly', $q, $r->hash($q)); $this->fail('Stale price'); }
        catch (ValidationException) { $this->assertDatabaseCount('invoices', 0); }
        foreach (['once', 'invalid'] as $cycle) {
            try { $r->quote($s->fresh(), $cycle); $this->fail('Invalid period'); }
            catch (ValidationException) { $this->assertSame('monthly', $s->fresh()->cycle); }
        }
    }

    public function test_customer_ownership_and_three_language_preview_do_not_issue_invoices(): void
    {
        [$s] = $this->fixtures();
        [$other] = $this->fixtures();
        $this->actingAs($other->customer, 'customer')->get('/account/services/'.$s->id.'/renew')->assertNotFound();
        $this->post('/account/services/'.$s->id.'/renew', ['cycle' => 'quarterly', 'quote_hash' => str_repeat('0', 64)])->assertNotFound();
        foreach (['' => 'تمدید و انتخاب دوره', '/en' => 'Renew and choose billing period', '/tr' => 'Yenile ve dönem seç'] as $prefix => $text) {
            $this->actingAs($s->customer, 'customer')->get($prefix.'/account/services/'.$s->id.'/renew')
                ->assertOk()->assertSee($text)->assertSee('quarterly')->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_post_issues_only_server_quoted_amount_and_redirects_to_invoice(): void
    {
        [$s] = $this->fixtures();
        $url = '/account/services/'.$s->id.'/renew';
        $this->actingAs($s->customer, 'customer')->get($url)->assertOk();
        $q = session('service_renewal.'.$s->id)['quotes']['quarterly'];
        $this->post($url, ['cycle' => 'quarterly', 'quote_hash' => app(ServiceRenewals::class)->hash($q), 'price' => 1])
            ->assertRedirect();
        $this->assertSame(1848000, $s->invoices()->outstanding()->firstOrFail()->total);
    }
    public function test_partial_payment_discount_and_bank_receipt_preserve_existing_invoice(): void
    {
        foreach (['partial', 'discount', 'bank'] as $case) {
            [$s] = $this->fixtures();
            $invoice = Invoice::create(['customer_id' => $s->customer_id, 'service_id' => $s->id, 'kind' => 'service',
                'currency_code' => 'IRT', 'total' => 649000, 'paid' => $case === 'partial' ? 1000 : 0,
                'discount' => $case === 'discount' ? 1000 : 0, 'status' => $case === 'partial' ? 'partial' : 'unpaid']);
            if ($case === 'bank') {
                \App\Models\BankTransferReceipt::create(['invoice_id' => $invoice->id, 'customer_id' => $s->customer_id,
                    'currency_code' => 'IRT', 'amount' => 649000, 'status' => 'pending', 'reference' => 'fixture']);
            }
            try { $this->issue($s); $this->fail('Protected invoice must remain'); }
            catch (ValidationException) {
                $this->assertSame('monthly', $s->fresh()->cycle);
                $this->assertSame($case === 'partial' ? 'partial' : 'unpaid', $invoice->fresh()->status);
            }
        }
    }

    public function test_missing_fx_ambiguous_catalog_and_unverified_or_hourly_services_offer_no_periods(): void
    {
        [$s, $p] = $this->fixtures();
        $r = app(ServiceRenewals::class);
        $copy = $p->replicate(); $copy->slug .= '-duplicate'; $copy->save();
        $this->assertSame([], $r->options($s));
        $copy->delete();
        foreach (['hourly', 'monthly'] as $mode) {
            $s->billing_mode = $mode;
            if ($mode === 'monthly') {
                $s->provision_meta = ['existing_hosting_adoption' => ['billing_terms' => 'unverified']];
            }
            $this->assertSame([], $r->options($s));
        }
        $s->provision_meta = [];
        $pricing = \Mockery::mock(ProductPricing::class)->makePartial();
        $pricing->shouldReceive('rate')->andReturn(null);
        $this->app->instance(ProductPricing::class, $pricing);
        $this->assertSame([], $r->options($s));
    }

    public function test_expired_or_tampered_preview_cannot_issue_invoice(): void
    {
        [$s] = $this->fixtures();
        $url = '/account/services/'.$s->id.'/renew';
        $this->actingAs($s->customer, 'customer')->get($url)->assertOk();
        $this->post($url, ['cycle' => 'quarterly', 'quote_hash' => str_repeat('0', 64)])->assertSessionHasErrors();
        $q = session('service_renewal.'.$s->id)['quotes']['quarterly'];
        $this->travel(16)->minutes();
        $this->post($url, ['cycle' => 'quarterly', 'quote_hash' => app(ServiceRenewals::class)->hash($q)])->assertSessionHasErrors();
        $this->assertDatabaseCount('invoices', 0);
    }

}
