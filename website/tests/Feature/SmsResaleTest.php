<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Models\SmsSubscription;
use App\Services\Payment\PaymentService;
use App\Services\SmsResale\Controls;
use App\Services\SmsResale\Orders;
use App\Services\SmsResale\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsResaleTest extends TestCase
{
    use RefreshDatabase;

    private int $createAttempts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function enable(): void
    {
        foreach (['automation_enabled' => '1', 'sales_enabled' => '1', 'parent_id' => '42', 'cost_irr' => '1728', 'tax_percent' => '10',
            'commercial_evidence' => 'synthetic commercial receipt', 'native_billing_evidence' => 'synthetic billing receipt',
            'automation_evidence' => 'synthetic unattended API receipt', 'verified_at' => now()->toIso8601String(),
            'valid_until' => now()->addDay()->toIso8601String(), 'start_acl' => '11', 'growth_acl' => '12', 'pro_acl' => '13',
            'start_tariff_verified' => '1', 'growth_tariff_verified' => '1', 'pro_tariff_verified' => '1'] as $key => $value) {
            Setting::put('sms_resale_'.$key, $value);
        }
        Setting::putSecret('sms_resale_api_key', str_repeat('synthetic-key-', 3));
    }

    private function customer(): Customer
    {
        $c = Customer::create(['email' => 'sms'.random_int(1000, 999999).'@example.test', 'phone' => Customer::where('phone', '09121234567')->exists() ? '0912'.random_int(1000000, 9999999) : '09121234567', 'password' => 'x', 'status' => 'active', 'locale' => 'fa']);
        $c->forceFill(['phone_verified_at' => now()])->save();
        return $c;
    }

    private function identity(): array
    {
        return ['national_code' => '0084575948', 'birth_date' => '1990-01-01', 'mobile_number' => '09121234567'];
    }

    private function order(string $plan = 'start'): SmsSubscription
    {
        return app(Orders::class)->place($this->customer(), $plan, $this->identity());
    }

    private function pay(Invoice $invoice): Payment
    {
        $p = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'gateway' => 'bank',
            'currency_code' => 'IRT', 'amount' => $invoice->total, 'status' => 'pending']);
        $outcome = app(PaymentService::class)->settleConfirmed($p, 'SMS-TEST-'.$p->id);
        $this->assertTrue($outcome->ok);
        return $p->fresh();
    }

    private function user(SmsSubscription $s, array $overrides = []): array
    {
        return array_replace(['user_id' => 77, 'uname' => $s->username, 'parent_id' => 42, 'national_id' => $this->identity()['national_code'],
            'mobile' => '+989121234567', 'description' => 'servernet:sms:'.$s->operation_id, 'acl_role_id' => $s->quote['plan']['acl_id'],
            'expire_time' => $s->service->next_due_at?->format('Y-m-d'), 'status' => 'active', 'document_block' => 'no', 'send_block' => 'no',
            'credit' => ['credit' => '12345.25']], $overrides);
    }

    private function fakeAccount(SmsSubscription $s, bool $lostCreate = false, array $overrides = []): void
    {
        $created = false;
        Http::fake(function ($request) use ($s, $lostCreate, $overrides, &$created) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $user = $this->user($s->fresh(), $overrides);
            if ($path === '/v1/api/user/list') {
                return Http::response(['data' => $created ? [$user] : [], 'meta' => ['status' => true]]);
            }
            if ($path === '/v1/api/user/create') {
                $this->createAttempts++;
                $created = true;
                if ($lostCreate) { throw new ConnectionException('Synthetic timeout'); }
                return Http::response(['data' => null, 'meta' => ['status' => true]]);
            }
            if ($path === '/v1/api/user/show') { return Http::response(['data' => $user, 'meta' => ['status' => true]]); }
            return Http::response(['data' => null, 'meta' => ['status' => false]], 500);
        });
    }

    public function test_public_page_renders_three_locales_without_provider_calls(): void
    {
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $response = $this->get($prefix.'/cloud/sms')->assertOk();
            $html = $response->getContent();
            $this->assertStringContainsString('data-sms-calculator', $html);
            $this->assertStringContainsString('599,000', $html);
            $this->assertStringNotContainsString('sms.hero', $html);
            $this->assertStringNotContainsString('/cart.php', $html);
            $this->assertStringNotContainsString('/sms/orders', $html);
            if ($out = getenv('SMS_UI_PREVIEW_DIR')) {
                if (! is_dir($out)) { mkdir($out, 0755, true); }
                file_put_contents($out.'/'.$locale.'.html', $html);
            }
        }
        Http::assertNothingSent();
    }

    public function test_closed_sales_reject_stale_and_crafted_forms(): void
    {
        $c = $this->customer();
        $this->actingAs($c, 'customer')->post('/account/sms/orders', $this->identity() + ['plan' => 'growth', 'consent' => '1'])
            ->assertSessionHasErrors('plan');
        $this->assertSame(0, Service::count());
        $this->assertSame(0, Invoice::count());
        Http::assertNothingSent();
    }

    public function test_stale_proof_or_cost_below_margin_blocks_sales(): void
    {
        $this->enable();
        $controls = app(Controls::class);
        $this->assertTrue($controls->sellable('pro'));
        Setting::put('sms_resale_cost_irr', '2000');
        $this->assertFalse($controls->sellable('pro'));
        Setting::put('sms_resale_cost_irr', '1728');
        Setting::put('sms_resale_valid_until', now()->subMinute()->toIso8601String());
        $this->assertFalse($controls->sellable('growth'));
        $this->assertSame(1964, Controls::floor(1728, 1200));
    }

    public function test_duplicate_order_has_one_service_and_invoice_with_no_credit_charge(): void
    {
        $this->enable(); $c = $this->customer();
        $a = app(Orders::class)->place($c, 'growth', $this->identity());
        $b = app(Orders::class)->place($c, 'growth', $this->identity());
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Service::count()); $this->assertSame(1, Invoice::count());
        $invoice = Invoice::findOrFail($a->pending_invoice_id);
        $this->assertSame(249000, $invoice->subtotal);
        $this->assertSame(273900, $invoice->total);
        $this->assertSame(129000, $a->service->price);
        $this->assertSame('IRT', $invoice->currency_code);
        $this->assertSame(0, DB::table('credit_ledger')->count());
        $raw = DB::table('sms_subscriptions')->where('id', $a->id)->first();
        $this->assertStringNotContainsString('0084575948', $raw->identity);
        $this->assertStringNotContainsString($a->initial_password, $raw->initial_password);
        Http::assertNothingSent();
    }

    public function test_unpaid_subscription_never_creates_upstream_account(): void
    {
        $this->enable(); $s = $this->order('growth');
        $this->assertFalse(app(Provisioner::class)->provision($s->service));
        Http::assertNothingSent();
    }

    public function test_official_settlement_queues_sms_and_duplicate_callback_does_not_extend_twice(): void
    {
        $this->enable(); $s = $this->order('growth');
        Http::fake(['*' => Http::response([], 500)]);
        $p = $this->pay(Invoice::findOrFail($s->pending_invoice_id));
        $service = $s->service->fresh();
        $this->assertSame('awaiting_provision', $service->status);
        $this->assertSame('pending', $service->provision_status);
        $date = $service->next_due_at->toIso8601String();
        app(PaymentService::class)->settleConfirmed($p->fresh(), 'SMS-TEST-'.$p->id);
        $this->assertSame($date, $service->fresh()->next_due_at->toIso8601String());
    }

    public function test_create_null_response_and_lost_response_are_reconciled_without_duplicate_create(): void
    {
        $this->enable(); $s = $this->order();
        $this->fakeAccount($s, true);
        $this->assertTrue(app(Provisioner::class)->provision($s->service));
        $this->assertTrue(app(Provisioner::class)->provision($s->service->fresh()));
        $this->assertSame(77, $s->fresh()->upstream_user_id);
        $this->assertSame('active', $s->fresh()->state);
        $this->assertSame('12345.250000', $s->fresh()->credit_irr);
        // Laravel does not record requests whose fake throws a transport exception.
        $this->assertSame(1, $this->createAttempts);
    }

    public function test_missing_account_after_unknown_create_is_not_created_again(): void
    {
        $this->enable(); $s = $this->order();
        $s->update(['attempted_at' => now(), 'state' => 'reconciling']);
        Http::fake(['*' => Http::response(['data' => [], 'meta' => ['status' => true]])]);
        $this->assertFalse(app(Provisioner::class)->provision($s->service));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_existing_foreign_username_is_not_adopted_or_overwritten(): void
    {
        $this->enable(); $s = $this->order();
        Http::fake(['*' => Http::response(['data' => [$this->user($s, ['parent_id' => 900])], 'meta' => ['status' => true]])]);
        $this->assertFalse(app(Provisioner::class)->provision($s->service));
        $this->assertNull($s->fresh()->upstream_user_id);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_provider_review_does_not_become_active_delivery(): void
    {
        $this->enable(); $s = $this->order(); $this->fakeAccount($s, false, ['document_block' => 'yes']);
        $this->assertFalse(app(Provisioner::class)->provision($s->service));
        $this->assertSame('awaiting_review', $s->fresh()->state);
        $this->assertSame('awaiting_provision', $s->service->fresh()->status);
    }

    public function test_upgrade_uses_separate_invoice_and_does_not_extend_service_period(): void
    {
        $this->enable(); $s = $this->order(); $this->fakeAccount($s);
        app(Provisioner::class)->provision($s->service);
        $date = $s->service->fresh()->next_due_at->toIso8601String();
        $s = app(Orders::class)->upgrade($s->fresh(), 'growth');
        $invoice = Invoice::findOrFail($s->pending_invoice_id);
        $this->assertNull($invoice->service_id);
        $this->assertGreaterThan(0, $invoice->total);
        $this->assertLessThanOrEqual(273900, $invoice->total);
        Http::fake(['*' => Http::response([], 500)]); $this->pay($invoice);
        $this->assertSame($date, $s->service->fresh()->next_due_at->toIso8601String());
    }

    public function test_cancelled_service_is_not_created_or_reactivated(): void
    {
        $this->enable(); $s = $this->order(); $s->service->update(['status' => 'cancelled']);
        $this->assertFalse(app(Provisioner::class)->provision($s->service->fresh()));
        Http::assertNothingSent();
    }

    public function test_cancelled_unknown_creation_is_reconciled_and_closed_by_scheduler(): void
    {
        $this->enable(); $s = $this->order();
        $s->update(['attempted_at' => now(), 'state' => 'reconciling']);
        $s->service->update(['status' => 'cancelled']);
        $discovered = false;
        $user = $this->user($s);
        Http::fake(function ($request) use (&$discovered, &$user) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/api/user/list') { return Http::response(['data' => $discovered ? [$user] : [], 'meta' => ['status' => true]]); }
            if ($path === '/v1/api/user/show') { return Http::response(['data' => $user, 'meta' => ['status' => true]]); }
            if ($path === '/v1/api/user/update') {
                $user['status'] = $request['status'];
                return Http::response(['data' => null, 'meta' => ['status' => true]]);
            }
            return Http::response([], 500);
        });
        $this->artisan('sms:sync-subscriptions')->assertSuccessful();
        $this->assertSame('reconciling', $s->fresh()->state);
        $discovered = true; $this->travel(6)->minutes();
        $this->artisan('sms:sync-subscriptions')->assertSuccessful();
        $this->assertSame('suspended', $s->fresh()->state);
        $this->assertSame(77, $s->fresh()->upstream_user_id);
        $this->assertSame('cancelled', $s->service->fresh()->status);
        $this->assertSame('inactive', $user['status']);
        $this->assertSame('12345.25', $user['credit']['credit']);
        Http::assertNotSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/create'));
    }

    public function test_suspension_during_provisioning_wins_over_active_response(): void
    {
        $this->enable(); $s = $this->order();
        $s->update(['upstream_user_id' => 77, 'attempted_at' => now()]);
        $user = $this->user($s); $changed = false;
        Http::fake(function ($request) use ($s, &$user, &$changed) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/api/user/show') {
                if (! $changed) { $changed = true; $s->service->update(['status' => 'suspended']); }
                return Http::response(['data' => $user, 'meta' => ['status' => true]]);
            }
            if ($path === '/v1/api/user/update') {
                $user['status'] = $request['status'];
                return Http::response(['data' => null, 'meta' => ['status' => true]]);
            }
            return Http::response([], 500);
        });
        $this->assertFalse(app(Provisioner::class)->provision($s->service));
        $this->assertSame('suspended', $s->service->fresh()->status);
        $this->assertSame('suspended', $s->fresh()->state);
        $this->assertSame('inactive', $user['status']);
    }

    public function test_cancel_during_restoration_closes_access_again_and_retains_credit(): void
    {
        $this->enable(); $s = $this->order();
        $s->update(['upstream_user_id' => 77, 'attempted_at' => now(), 'state' => 'suspended']);
        $s->service->update(['status' => 'suspended']);
        $user = $this->user($s, ['status' => 'inactive']); $assignments = [];
        Http::fake(function ($request) use ($s, &$user, &$assignments) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v1/api/user/show') { return Http::response(['data' => $user, 'meta' => ['status' => true]]); }
            if ($path === '/v1/api/user/update') {
                $assignments[] = $request['status']; $user['status'] = $request['status'];
                if ($request['status'] === 'active') { $s->service->update(['status' => 'cancelled']); }
                return Http::response(['data' => null, 'meta' => ['status' => true]]);
            }
            return Http::response([], 500);
        });
        $this->assertFalse(app(Provisioner::class)->changeAccess($s->service, true)->ok);
        $this->assertSame(['active', 'inactive'], $assignments);
        $this->assertSame('cancelled', $s->service->fresh()->status);
        $this->assertSame('suspended', $s->fresh()->state);
        $this->assertSame('12345.25', $user['credit']['credit']);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, DB::table('credit_ledger')->count());
        $this->assertFalse($s->service->isManuallyDelivered());
    }

    public function test_customer_workspace_never_serializes_identity_or_secrets(): void
    {
        $this->enable(); $s = $this->order();
        $response = $this->actingAs($s->customer, 'customer')->get('/account/sms')->assertOk();
        $response->assertDontSee($s->initial_password)->assertDontSee('0084575948')->assertDontSee(str_repeat('synthetic-key-', 3));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $other = $this->customer();
        $this->actingAs($other, 'customer')->get('/account/sms')->assertOk()->assertDontSee($s->username);
        Http::assertNothingSent();
    }
}
