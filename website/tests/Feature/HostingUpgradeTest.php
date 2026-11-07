<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\GiftCoupon;
use App\Models\HostingUpgrade;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\HostingUpgradeFulfillment;
use App\Services\Billing\HostingUpgrades;
use App\Services\Billing\InvoiceCanceller;
use App\Services\Payment\PaymentService;
use App\Services\ProductPricing;
use App\Services\Provisioning\HostingPlanChanger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HostingUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.timezone' => 'UTC', 'billing.internal_service_ids' => []]);
        $this->travelTo(now()->setDate(2026, 11, 16)->startOfDay()->addHours(12));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Mail::fake();
        $pricing = \Mockery::mock(ProductPricing::class)->makePartial();
        $pricing->shouldReceive('rate')->andReturn(100000);
        $this->app->instance(ProductPricing::class, $pricing);
    }

    private function fixtures(string $type = 'whm'): array
    {
        $c = Customer::create(['email' => uniqid().'@example.test', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'fixture-only', 'status' => 'active', 'locale' => 'fa']);
        $server = Server::create(['name' => 'Fixture', 'type' => $type, 'hostname' => 'panel.example.test',
            'username' => 'admin', 'api_token' => 'fake-only', 'verify_tls' => true, 'status' => 'active', 'country' => 'IR']);
        $group = $type === 'whm' ? 'linux' : 'windows';
        $base = ['category' => 'shared', 'group' => $group, 'currency_code' => 'IRT', 'cycle' => 'monthly',
            'tax_percent' => 10, 'is_active' => true, 'requires_domain' => true];
        $old = Product::create($base + ['name' => 'Old', 'slug' => $group.'-1', 'plan' => 'old-plan', 'sort' => 0, 'base_price_eur' => '5.90']);
        $target = Product::create($base + ['name' => 'New', 'name_en' => 'New plan', 'name_tr' => 'Yeni paket',
            'slug' => $group.'-2', 'plan' => 'new-plan', 'sort' => 1, 'base_price_eur' => '7.90']);
        $s = Service::create(['customer_id' => $c->id, 'server_id' => $server->id, 'name' => 'Old', 'plan' => $old->plan,
            'username' => 'fixtureuser', 'domain' => 'site.example.test', 'cycle' => 'monthly', 'currency_code' => 'IRT',
            'price' => 590000, 'tax_percent' => 10, 'status' => 'active', 'provision_status' => 'done',
            'activated_at' => '2026-11-01 00:00:00', 'next_due_at' => '2026-12-01',
            'provision_meta' => $type === 'plesk' ? ['plesk' => ['server_id' => $server->id, 'subscription_id' => 12,
                'customer_id' => 7, 'login' => 'fixtureuser', 'domain' => 'site.example.test', 'plan' => $old->plan]] : []]);

        return [$s, $target, $old];
    }

    private function issue(Service $s, Product $p): HostingUpgrade
    {
        $billing = app(HostingUpgrades::class);
        $q = $billing->quote($s, $p);

        return $billing->issue($s, $p, $q, $billing->hash($q));
    }

    private function pay(HostingUpgrade $u): Payment
    {
        $p = Payment::create(['invoice_id' => $u->invoice_id, 'customer_id' => $u->service->customer_id,
            'gateway' => 'credit', 'currency_code' => 'IRT', 'amount' => $u->invoice->total, 'status' => 'pending']);
        app(PaymentService::class)->settleConfirmed($p, 'fixture-ref-'.$p->id);

        return $p;
    }

    public function test_exact_half_period_credit_and_tax_with_no_setup_or_renewal(): void
    {
        [$s, $target] = $this->fixtures();
        $q = app(HostingUpgrades::class)->quote($s, $target);
        $this->assertSame(295000, $q['credit']);
        $this->assertSame(395000, $q['debit']);
        $this->assertSame(100000, $q['subtotal']);
        $this->assertSame(10000, $q['tax']);
        $this->assertSame(110000, $q['total']);
        $u = $this->issue($s, $target);
        $this->assertNull($u->invoice->service_id);
        $this->assertSame('hosting_upgrade', $u->invoice->kind);
        $this->assertSame('2026-11-17', $u->invoice->due_at->toDateString());
        $this->assertSame('old-plan', $s->fresh()->plan);
        $this->assertDatabaseCount('invoice_items', 1);
    }

    public function test_double_click_reuses_the_existing_invoice_even_with_another_target(): void
    {
        [$s, $target] = $this->fixtures();
        $a = $this->issue($s, $target);
        $this->assertSame($a->id, $this->issue($s, $target)->id);
        $this->assertDatabaseCount('hosting_upgrades', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_payment_callback_is_idempotent_and_never_extends_the_period(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        $payment = $this->pay($u);
        app(PaymentService::class)->settleConfirmed($payment, 'same-ref');
        $this->assertSame('paid', $u->invoice->fresh()->status);
        $this->assertSame(110000, $u->invoice->fresh()->paid);
        $this->assertSame('2026-12-01', $s->fresh()->next_due_at->toDateString());
        $this->assertSame('old-plan', $s->fresh()->plan);
        Http::assertNothingSent();
    }

    public function test_paid_whm_upgrade_is_verified_on_the_same_account_and_applied_once(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        $this->pay($u);
        $plan = 'old-plan';
        $mutations = 0;
        Http::fake(function ($r) use (&$plan, &$mutations) {
            $data = [];
            if (str_contains($r->url(), 'accountsummary')) {
                $data = ['acct' => [['user' => 'fixtureuser', 'domain' => 'site.example.test', 'suspended' => 0, 'plan' => $plan]]];
            } elseif (str_contains($r->url(), 'listpkgs')) {
                $data = ['pkg' => [['name' => 'new-plan']]];
            } elseif (str_contains($r->url(), 'changepackage')) {
                $mutations++;
                $plan = 'new-plan';
                $this->assertSame('fixtureuser', $r['user']);
                $this->assertSame('new-plan', $r['pkg']);
            } else {
                $this->fail('Unexpected request');
            }

            return Http::response(['metadata' => ['result' => 1], 'data' => $data]);
        });
        $this->artisan('hosting:upgrades')->assertSuccessful();
        $this->artisan('hosting:upgrades')->assertSuccessful();
        $this->assertSame(1, $mutations);
        $this->assertSame('complete', $u->fresh()->status);
        $this->assertNull($u->fresh()->open_service_id);
        $this->assertSame('new-plan', $s->fresh()->plan);
        $this->assertSame(790000, $s->fresh()->price);
        $this->assertSame('2026-12-01', $s->fresh()->next_due_at->toDateString());
        $this->assertSame('fixtureuser', $s->fresh()->username);
        $this->assertSame(1, Service::count());
    }

    public function test_unpaid_and_partial_payment_never_contact_the_panel(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        app(HostingUpgradeFulfillment::class)->process($u);
        $u->invoice->update(['paid' => 500, 'status' => 'partial']);
        app(HostingUpgradeFulfillment::class)->process($u);
        Http::assertNothingSent();
        $this->assertSame('old-plan', $s->fresh()->plan);
    }

    public function test_timeout_after_mutation_reconciles_without_another_change(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        $this->pay($u);
        $changer = \Mockery::mock(HostingPlanChanger::class);
        $changer->shouldReceive('inspect')->once()->andReturn(['state' => 'source']);
        $changer->shouldReceive('change')->once()->andReturnNull();
        $changer->shouldReceive('inspect')->once()->andReturn(['state' => 'target']);
        $this->app->instance(HostingPlanChanger::class, $changer);
        app(HostingUpgradeFulfillment::class)->process($u);
        $this->assertSame('complete', $u->fresh()->status);
        // Simulate a crash after the persisted intent and successful remote change.
        [$s2, $p2] = $this->fixtures('plesk');
        $u2 = $this->issue($s2, $p2);
        $this->pay($u2);
        $u2->update(['attempted_at' => now(), 'status' => 'applying']);
        $changer2 = \Mockery::mock(HostingPlanChanger::class);
        $changer2->shouldReceive('inspect')->once()->andReturn(['state' => 'target']);
        $changer2->shouldNotReceive('change');
        (new HostingUpgradeFulfillment(app(HostingUpgrades::class), $changer2))->process($u2);
        $this->assertSame('complete', $u2->fresh()->status);
    }

    public function test_ambiguous_result_requires_review_and_preserves_plan_and_price(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        $this->pay($u);
        $changer = \Mockery::mock(HostingPlanChanger::class);
        $changer->shouldReceive('inspect')->once()->andReturn(['state' => 'source']);
        $changer->shouldReceive('change')->once();
        $changer->shouldReceive('inspect')->once()->andReturn(['state' => 'unknown']);
        $f = new HostingUpgradeFulfillment(app(HostingUpgrades::class), $changer);
        $f->process($u);
        $this->assertSame('review', $u->fresh()->status);
        $this->assertSame(590000, $s->fresh()->price);
        $this->assertSame('old-plan', $s->fresh()->plan);
        $this->travel(10)->minutes();
        $reconcile = \Mockery::mock(HostingPlanChanger::class);
        $reconcile->shouldReceive('inspect')->once()->andReturn(['state' => 'target']);
        $reconcile->shouldNotReceive('change');
        (new HostingUpgradeFulfillment(app(HostingUpgrades::class), $reconcile))->process($u);
        $this->assertSame('complete', $u->fresh()->status);
    }

    public function test_changed_paid_period_is_reviewed_without_any_remote_mutation(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        $this->pay($u);
        $s->update(['next_due_at' => '2027-01-01']);
        app(HostingUpgradeFulfillment::class)->process($u);
        $this->assertSame('review', $u->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_cancellation_releases_request_without_cancelling_the_live_host(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        app(InvoiceCanceller::class)->cancel($u->invoice, 'fixture cancel');
        $this->artisan('hosting:upgrades')->assertSuccessful();
        $this->assertNull($u->fresh()->open_service_id);
        $this->assertSame('active', $s->fresh()->status);
        $this->assertNotSame($u->invoice_id, $this->issue($s, $p)->invoice_id);
    }

    public function test_owner_preview_and_invoice_routes_work_in_all_three_languages(): void
    {
        [$s, $p] = $this->fixtures();
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $r = $this->actingAs($s->customer, 'customer')->get($prefix.'/account/services/'.$s->id.'/upgrade?product_id='.$p->id);
            $r->assertOk()->assertViewHas('quote', fn ($q) => $q['total'] === 110000)->assertDontSee('ui.hu_');
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            if ($directory = getenv('HOSTING_UPGRADE_PREVIEW_DIR')) {
                file_put_contents($directory.'/'.$locale.'.html', $r->getContent());
            }
        }
        $q = session('hosting_upgrade.'.$s->id);
        $changer = \Mockery::mock(HostingPlanChanger::class);
        $changer->shouldReceive('inspect')->once()->andReturn(['state' => 'source']);
        $this->app->instance(HostingPlanChanger::class, $changer);
        $this->post('/account/services/'.$s->id.'/upgrade', ['product_id' => $p->id, 'quote_hash' => app(HostingUpgrades::class)->hash($q)])->assertRedirect();
        $this->assertDatabaseCount('hosting_upgrades', 1);
    }

    public function test_other_customer_gets_404_before_quote_or_invoice_creation(): void
    {
        [$s, $p] = $this->fixtures();
        [$other] = $this->fixtures('plesk');
        $this->actingAs($other->customer, 'customer')->get('/account/services/'.$s->id.'/upgrade')->assertNotFound();
        $this->post('/account/services/'.$s->id.'/upgrade', ['product_id' => $p->id, 'quote_hash' => str_repeat('a', 64)])->assertNotFound();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_stale_preview_rejects_repricing_and_never_creates_an_invoice(): void
    {
        [$s, $p] = $this->fixtures();
        $b = app(HostingUpgrades::class);
        $q = $b->quote($s, $p);
        $p->update(['base_price_eur' => '8.90']);
        $this->expectException(ValidationException::class);
        try {
            $b->issue($s, $p, $q, $b->hash($q));
        } finally {
            $this->assertDatabaseCount('invoices', 0);
        }
    }

    public function test_elapsed_preview_rejected(): void
    {
        [$s, $p] = $this->fixtures();
        $b = app(HostingUpgrades::class);
        $q = $b->quote($s, $p);
        $this->travel(16)->minutes();
        $this->expectException(ValidationException::class);
        $b->issue($s, $p, $q, $b->hash($q));
    }

    public function test_downsell_cross_family_location_and_unsupported_billing_are_rejected(): void
    {
        [$s, $p, $old] = $this->fixtures();
        $b = app(HostingUpgrades::class);
        foreach ([['group' => 'wordpress'], ['locations' => ['DE']], ['sort' => -1], ['server_id' => 999], ['is_active' => false]] as $change) {
            $candidate = clone $p;
            $candidate->fill($change);
            try {
                $b->quote($s, $candidate);
                $this->fail('Incompatible plan accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        foreach ([['status' => 'suspended'], ['is_reseller' => true], ['billing_mode' => 'external'], ['next_due_at' => '2026-11-15'], ['currency_code' => 'EUR']] as $change) {
            $candidate = clone $s;
            $candidate->fill($change);
            $this->assertFalse($b->eligible($candidate));
        }
    }

    public function test_plesk_xml_switches_only_the_bound_subscription_and_confirms_sync(): void
    {
        [$s, $p] = $this->fixtures('plesk');
        $u = $this->issue($s, $p);
        $this->pay($u);
        $plan = 'old-guid';
        $mutations = 0;
        Http::fake(function ($r) use (&$plan, &$mutations) {
            $xml = $r->body();
            if (str_contains($xml, '<switch-subscription>')) {
                $this->assertStringContainsString('<filter><id>12</id></filter><plan-guid>new-guid</plan-guid>', $xml);
                $plan = 'new-guid';
                $mutations++;

                return Http::response('<packet><webspace><switch-subscription><result><status>ok</status><id>12</id></result></switch-subscription></webspace></packet>');
            }
            if (str_contains($xml, '<service-plan>')) {
                $new = str_contains($xml, '<name>new-plan</name>');

                return Http::response('<packet><service-plan><get><result><status>ok</status><name>'.($new ? 'new-plan' : 'old-plan').'</name><guid>'.($new ? 'new-guid' : 'old-guid').'</guid></result></get></service-plan></packet>');
            }
            $this->assertStringContainsString('<name>site.example.test</name>', $xml);

            return Http::response('<packet><webspace><get><result><status>ok</status><id>12</id><data><gen_info><name>site.example.test</name><owner-id>7</owner-id><status>0</status><htype>vrt_hst</htype></gen_info><hosting><vrt_hst><property><name>ftp_login</name><value>fixtureuser</value></property></vrt_hst></hosting><subscriptions><subscription><locked>false</locked><synchronized>true</synchronized><plan><plan-guid>'.$plan.'</plan-guid></plan></subscription></subscriptions></data></result></get></webspace></packet>');
        });
        $this->artisan('hosting:upgrades')->assertSuccessful();
        $this->assertSame(1, $mutations);
        $this->assertSame('complete', $u->fresh()->status);
        $this->assertSame('new-plan', data_get($s->fresh()->provision_meta, 'plesk.plan'));
        $this->assertSame('2026-12-01', $s->fresh()->next_due_at->toDateString());
    }

    public function test_wrong_remote_account_never_changes_a_package(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        $this->pay($u);
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [[
            'user' => 'anotheruser', 'domain' => $s->domain, 'plan' => $s->plan, 'suspended' => 0]]]])]);
        app(HostingUpgradeFulfillment::class)->process($u);
        Http::assertSentCount(1);
        $this->assertNull($u->fresh()->attempted_at);
        $this->assertSame('old-plan', $s->fresh()->plan);
    }

    public function test_expired_invoice_is_not_payable_and_late_payment_does_not_upgrade(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        $this->travel(25)->hours();
        $this->assertFalse($u->invoice->fresh()->isPayable());
        $this->pay($u);
        app(HostingUpgradeFulfillment::class)->process($u);
        $this->assertSame('review', $u->fresh()->status);
        Http::assertNothingSent();
        $this->assertSame('old-plan', $s->fresh()->plan);
    }

    public function test_long_cycles_keep_existing_discounts_and_calendar_periods(): void
    {
        [$s, $p] = $this->fixtures();
        foreach (['quarterly' => ['2026-09-01', 2250000], 'semiannual' => ['2026-06-01', 4270000], 'yearly' => ['2025-12-01', 7580000]] as $cycle => [$start, $price]) {
            $candidate = clone $s;
            $candidate->cycle = $cycle;
            $candidate->activated_at = $start;
            $q = app(HostingUpgrades::class)->quote($candidate, $p);
            $this->assertSame($price, $q['price']);
            $this->assertSame($start, substr($q['period_start'], 0, 10));
            $this->assertGreaterThan(0, $q['total']);
        }
        $candidate = clone $s;
        $candidate->activated_at = '2026-10-31';
        $candidate->next_due_at = '2026-11-30';
        $this->assertSame('2026-10-31', substr(app(HostingUpgrades::class)->quote($candidate, $p)['period_start'], 0, 10));
    }

    public function test_preview_protects_against_amount_tampering_and_renewal_debt(): void
    {
        [$s, $p] = $this->fixtures();
        $b = app(HostingUpgrades::class);
        $q = $b->quote($s, $p);
        $hash = $b->hash($q);
        $tampered = $q;
        $tampered['total'] = 1;
        try {
            $b->issue($s, $p, $tampered, $hash);
            $this->fail('Tampered amount accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('invoices', 0);
        }
        Invoice::create(['customer_id' => $s->customer_id, 'service_id' => $s->id, 'currency_code' => 'IRT', 'total' => 1000, 'status' => 'unpaid']);
        $this->expectException(ValidationException::class);
        $b->quote($s, $p);
    }

    public function test_wallet_payment_of_upgrade_uses_the_official_flow_once(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        CreditEntry::create(['customer_id' => $s->customer_id, 'currency_code' => 'IRT',
            'amount' => 200000, 'balance_after' => 200000, 'reason' => 'adjustment', 'note' => 'fixture']);
        $this->actingAs($s->customer, 'customer')->post('/account/invoices/'.$u->invoice_id.'/pay-credit')->assertRedirect();
        $this->post('/account/invoices/'.$u->invoice_id.'/pay-credit')->assertRedirect();
        $this->assertSame('paid', $u->invoice->fresh()->status);
        $this->assertSame(90000, (int) CreditEntry::where('customer_id', $s->customer_id)->sum('amount'));
        $this->assertSame('2026-12-01', $s->fresh()->next_due_at->toDateString());
        $this->assertSame('old-plan', $s->fresh()->plan);
        $this->get('/account/invoices/'.$u->invoice_id)->assertOk()
            ->assertSee('/account/services/'.$s->id.'/upgrade', false)
            ->assertSee(__('ui.hu_status_applying'))->assertDontSee('ui.hu_');
    }

    public function test_renewal_expiry_does_not_make_the_upgrade_invoice_extend_service(): void
    {
        [$s, $p] = $this->fixtures();
        $s->update(['next_due_at' => '2026-11-17', 'activated_at' => '2026-10-17']);
        $u = $this->issue($s->fresh(), $p);
        $this->assertSame('2026-11-17', $u->invoice->due_at->toDateString());
        $this->travel(25)->hours();
        app(InvoiceCanceller::class)->cancel($u->invoice, 'expired');
        $this->pay($u);
        app(HostingUpgradeFulfillment::class)->process($u);
        $this->assertSame('canceled', $u->fresh()->status);
        $this->assertSame('canceled', $u->invoice->fresh()->status);
        $this->assertSame('old-plan', $s->fresh()->plan);
        Http::assertNothingSent();
    }

    public function test_quote_honors_the_full_paid_due_day_in_billing_timezone(): void
    {
        [$s, $p] = $this->fixtures();
        config(['billing.timezone' => 'Asia/Tehran']);
        $this->travelTo(Carbon::parse('2026-12-01 19:00:00', 'UTC'));
        $q = app(HostingUpgrades::class)->quote($s, $p);
        $this->assertSame('2026-12-01T20:29:59+00:00', $q['period_end']);
        $this->assertSame(5399, $q['remaining']);
        $u = $this->issue($s, $p);
        $this->assertSame('2026-12-01 20:29:59', $u->invoice->due_at->format('Y-m-d H:i:s'));
    }

    public function test_renewal_invoice_created_during_payment_window_requires_review(): void
    {
        [$s, $p] = $this->fixtures();
        $u = $this->issue($s, $p);
        $this->pay($u);
        Invoice::create(['customer_id' => $s->customer_id, 'service_id' => $s->id, 'currency_code' => 'IRT',
            'total' => 590000, 'status' => 'unpaid']);
        app(HostingUpgradeFulfillment::class)->process($u);
        $this->assertSame('review', $u->fresh()->status);
        $this->assertSame('old-plan', $s->fresh()->plan);
        Http::assertNothingSent();
    }

    public function test_early_renewal_upgrades_current_and_prepaid_cycles_without_extending_again(): void
    {
        [$s, $target] = $this->fixtures();
        $renewal = Invoice::create(['customer_id' => $s->customer_id, 'service_id' => $s->id,
            'currency_code' => 'IRT', 'kind' => 'service', 'total' => 649000, 'status' => 'unpaid']);
        $payment = Payment::create(['invoice_id' => $renewal->id, 'customer_id' => $s->customer_id,
            'gateway' => 'credit', 'currency_code' => 'IRT', 'amount' => 649000, 'status' => 'pending']);
        app(PaymentService::class)->settleConfirmed($payment, 'early-renewal-fixture');
        $this->assertSame('2027-01-01', $s->fresh()->next_due_at->toDateString());
        $q = app(HostingUpgrades::class)->quote($s->fresh(), $target);
        $this->assertCount(2, $q['periods']);
        $this->assertSame(885000, $q['credit']);
        $this->assertSame(1185000, $q['debit']);
        $this->assertSame(330000, $q['total']);
        $u = $this->issue($s->fresh(), $target);
        $this->pay($u);
        $changer = \Mockery::mock(HostingPlanChanger::class);
        $changer->shouldReceive('inspect')->once()->andReturn(['state' => 'source']);
        $changer->shouldReceive('change')->once();
        $changer->shouldReceive('inspect')->once()->andReturn(['state' => 'target']);
        (new HostingUpgradeFulfillment(app(HostingUpgrades::class), $changer))->process($u);
        $this->assertSame('complete', $u->fresh()->status);
        $this->assertSame('2027-01-01', $s->fresh()->next_due_at->toDateString());
        $this->assertSame(790000, $s->fresh()->price);
    }

    public function test_expired_wallet_form_cannot_debit_before_the_expiry_job_runs(): void
    {
        [$s, $target] = $this->fixtures();
        $u = $this->issue($s, $target);
        CreditEntry::create(['customer_id' => $s->customer_id, 'currency_code' => 'IRT',
            'amount' => 200000, 'balance_after' => 200000, 'reason' => 'adjustment', 'note' => 'fixture']);
        $this->travel(25)->hours();
        foreach (['unpaid', 'partial'] as $status) {
            $u->invoice->update(['status' => $status, 'paid' => $status === 'partial' ? 1000 : 0]);
            $this->actingAs($s->customer, 'customer')->post('/account/invoices/'.$u->invoice_id.'/pay-credit')
                ->assertSessionHasErrors();
            $this->assertSame(200000, (int) CreditEntry::where('customer_id', $s->customer_id)->sum('amount'));
        }
        $this->assertSame(0, Payment::where('invoice_id', $u->invoice_id)->count());
        $this->assertSame('old-plan', $s->fresh()->plan);
    }

    public function test_upgrade_invoice_and_customer_audit_records_cannot_be_deleted(): void
    {
        [$s, $target] = $this->fixtures();
        $u = $this->issue($s, $target);
        $admin = User::create(['name' => 'Fixture admin', 'email' => 'upgrade-admin@example.test',
            'password' => 'fixture-only', 'role' => 'admin']);
        foreach (['unpaid', 'canceled'] as $status) {
            $u->invoice->update(['status' => $status]);
            $this->assertFalse($u->invoice->fresh()->isDeletable());
            $this->actingAs($admin, 'web')->post('/admin/invoices/'.$u->invoice_id.'/delete')->assertSessionHasErrors();
            $this->assertNotNull(Invoice::find($u->invoice_id));
        }
        $this->post('/admin/customers/'.$s->customer_id.'/delete')->assertSessionHasErrors();
        $this->assertNotNull(Customer::find($s->customer_id));
        $this->assertNotNull(Service::find($s->id));
        $this->assertNotNull($u->fresh());
    }

    public function test_expired_upgrade_cannot_consume_a_gift_coupon(): void
    {
        [$s, $target] = $this->fixtures();
        $u = $this->issue($s, $target);
        $s->customer->forceFill(['discount_entry_visibility' => 'enabled'])->save();
        $coupon = GiftCoupon::create(['customer_id' => $s->customer_id, 'code' => GiftCoupon::freshCode(),
            'currency_code' => 'IRT', 'amount' => 200000, 'min_invoice' => 0,
            'reason' => 'fixture', 'expires_at' => now()->addDays(3)]);
        $this->travel(25)->hours();
        foreach (['unpaid', 'partial'] as $status) {
            $paid = $status === 'partial' ? 1000 : 0;
            $u->invoice->update(['status' => $status, 'paid' => $paid]);
            $this->actingAs($s->customer, 'customer')->post('/account/invoices/'.$u->invoice_id.'/coupon', ['code' => $coupon->code])
                ->assertSessionHasErrors();
            $this->assertNull($coupon->fresh()->used_at);
            $this->assertSame($paid, $u->invoice->fresh()->paid);
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, CreditEntry::count());
        Http::assertNothingSent();
    }

    public function test_expired_partial_payment_reaches_review_with_payment_and_coupon_provenance_preserved(): void
    {
        [$s, $target] = $this->fixtures();
        $u = $this->issue($s, $target);
        $s->customer->forceFill(['discount_entry_visibility' => 'enabled'])->save();
        $coupon = GiftCoupon::create(['customer_id' => $s->customer_id, 'code' => GiftCoupon::freshCode(),
            'currency_code' => 'IRT', 'amount' => 1000, 'min_invoice' => 0,
            'reason' => 'fixture', 'expires_at' => now()->addDays(3)]);
        $this->actingAs($s->customer, 'customer')->post('/account/invoices/'.$u->invoice_id.'/coupon', ['code' => $coupon->code])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($coupon->fresh()->used_at);
        $this->assertSame('unpaid', $u->invoice->fresh()->status);
        $this->artisan('hosting:upgrades')->assertSuccessful();
        $this->assertSame('awaiting_payment', $u->fresh()->status);
        $this->travel(25)->hours();
        $this->artisan('hosting:upgrades')->assertSuccessful();
        $this->artisan('hosting:upgrades')->assertSuccessful();
        $this->assertSame('review', $u->fresh()->status);
        $this->assertSame($s->id, $u->fresh()->open_service_id);
        $this->assertNull($u->fresh()->attempted_at);
        $this->assertSame(1000, $u->invoice->fresh()->paid);
        $this->assertSame($u->invoice_id, $coupon->fresh()->used_invoice_id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame('old-plan', $s->fresh()->plan);
        $this->assertSame('2026-12-01', $s->fresh()->next_due_at->toDateString());
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/account/services/'.$s->id.'/upgrade')->assertOk()
                ->assertSee($prefix.'/account/tickets/new', false);
        }
        Http::assertNothingSent();
    }
}
