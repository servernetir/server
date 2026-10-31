<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ServiceController as AdminServices;
use App\Http\Controllers\Api\CustomerApiController;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\BillingLifecycleActions;
use App\Services\Billing\ProductInvoiceIssuer;
use App\Services\Billing\RenewalTerms;
use App\Services\Calendar\Providers\HostingRenewalProvider;
use App\Services\Provisioning\ExistingHostingAdoption;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Synthetic accounts only; WHM and notifications never reach external services. */
class ExistingHostingAdoptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['cache.default' => 'array']);
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => uniqid('fixture-').'@example.test', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('fixture-only'), 'status' => 'active', 'locale' => 'fa']);
    }

    private function admin(): User
    {
        return User::create(['name' => 'Fixture operator', 'email' => uniqid('fixture-admin-').'@example.test',
            'password' => bcrypt('fixture-only'), 'role' => 'admin']);
    }

    private function server(): Server
    {
        return Server::create(['name' => 'Fixture WHM', 'type' => 'whm', 'hostname' => 'whm.example.test',
            'port' => 2087, 'username' => 'root', 'api_token' => 'fixture-not-live', 'verify_tls' => true,
            'status' => 'active', 'active_accounts' => 7]);
    }

    private function whm(array $account = [], bool $ok = true): void
    {
        Http::fake(['https://whm.example.test:2087/json-api/accountsummary*' => Http::response([
            'metadata' => ['result' => $ok ? 1 : 0],
            'data' => ['acct' => [array_merge(['user' => 'managedtest', 'domain' => 'managed.example.test',
                'suspended' => 0, 'plan' => 'FixturePackage'], $account)]],
        ])]);
    }

    private function adopt(?Customer $customer = null, ?Server $server = null): Service
    {
        return app(ExistingHostingAdoption::class)->adopt($customer ?? $this->customer(), $server ?? $this->server(),
            'managedtest', 'managed.example.test', $this->admin(), 'fixture-adoption-v1');
    }

    private function external(array $changes = []): Service
    {
        return Service::create(array_merge(['customer_id' => $this->customer()->id, 'name' => 'Fixture existing account',
            'currency_code' => 'IRT', 'price' => 0, 'tax_percent' => 0, 'cycle' => 'external', 'billing_mode' => 'external',
            'status' => 'active', 'provision_status' => 'done', 'next_due_at' => null,
            'provision_meta' => ['existing_hosting_adoption' => ['version' => 1, 'billing_terms' => 'unverified']]], $changes));
    }

    public function test_adopts_existing_account_without_provisioning_financial_events_or_invented_dates(): void
    {
        $this->whm(); $server = $this->server();
        $service = $this->adopt(server: $server);
        $this->assertSame('active', $service->status);
        $this->assertSame('done', $service->provision_status);
        $this->assertSame('external', $service->billing_mode);
        $this->assertSame('external', $service->cycle);
        $this->assertSame($server->id, $service->server_id);
        $this->assertSame('managedtest', $service->username);
        $this->assertSame('managed.example.test', $service->domain);
        $this->assertSame('FixturePackage', $service->plan);
        $this->assertFalse($service->is_reseller);
        foreach (['activated_at', 'provisioned_at', 'next_due_at', 'password'] as $field) { $this->assertNull($service->{$field}); }
        $this->assertSame('unverified', data_get($service->provision_meta, 'existing_hosting_adoption.billing_terms'));
        $this->assertSame(7, $server->fresh()->active_accounts);
        foreach (['invoices', 'payments', 'credit_ledger'] as $table) { $this->assertSame(0, DB::table($table)->count()); }
        $this->assertSame(1, ActivityLog::where('service_id', $service->id)->where('action', 'existing_hosting_adopted')->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/json-api/accountsummary'));
        Mail::assertNothingSent();
    }

    public function test_repeated_adoption_reuses_the_same_row_and_audit_event(): void
    {
        $this->whm(); $customer = $this->customer(); $server = $this->server();
        $first = $this->adopt($customer, $server); $second = $this->adopt($customer, $server);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Service::count());
        $this->assertSame(1, ActivityLog::where('action', 'existing_hosting_adopted')->count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(7, $server->fresh()->active_accounts);
    }

    public function test_refuses_other_owner_or_ambiguous_or_known_billable_bindings_without_conversion(): void
    {
        $this->whm(); $customer = $this->customer(); $server = $this->server();
        $existing = Service::create(['customer_id' => $this->customer()->id, 'name' => 'Known billed account',
            'domain' => 'managed.example.test', 'username' => 'managedtest', 'server_id' => $server->id,
            'currency_code' => 'IRT', 'price' => 123000, 'cycle' => 'monthly', 'billing_mode' => 'cycle',
            'status' => 'active', 'provision_status' => 'done']);
        try { $this->adopt($customer, $server); $this->fail('Expected conflict'); }
        catch (\DomainException $e) { $this->assertSame('existing_hosting_service_binding_conflict', $e->getMessage()); }
        $this->assertSame(1, Service::count());
        $this->assertSame(123000, $existing->fresh()->price);
        $this->assertSame('cycle', $existing->fresh()->billing_mode);
        $this->assertSame(0, Invoice::count());
    }

    public function test_refuses_unverified_or_mismatched_or_suspended_whm_account(): void
    {
        $customer = $this->customer(); $server = $this->server();
        foreach ([[[], false], [['domain' => 'someone-else.example.test'], true], [['user' => 'anotheruser'], true], [['suspended' => 1], true]] as [$account, $ok]) {
            $this->whm($account, $ok);
            try { $this->adopt($customer, $server); $this->fail('Expected refused read-only evidence'); }
            catch (\DomainException $e) { $this->assertStringStartsWith('existing_hosting_account_', $e->getMessage()); }
        }
        $this->assertSame(0, Service::count()); $this->assertSame(0, Invoice::count());
    }

    public function test_legacy_whitespace_and_case_binding_blocks_duplicate_even_without_server_link(): void
    {
        $this->whm();
        Service::create(['customer_id' => $this->customer()->id, 'name' => 'Legacy fixture', 'username' => ' MANAGEDTEST ',
            'domain' => 'different.example.test', 'price' => 100000, 'cycle' => 'monthly', 'status' => 'cancelled']);
        try { $this->adopt(); $this->fail('Legacy account must not be duplicated'); }
        catch (\DomainException $e) { $this->assertSame('existing_hosting_service_binding_conflict', $e->getMessage()); }
        $this->assertSame(1, Service::count()); $this->assertSame(0, Invoice::count());
    }

    public function test_audit_failure_rolls_back_the_local_binding(): void
    {
        $this->whm();
        Event::listen('eloquent.creating: '.ActivityLog::class, fn () => throw new \RuntimeException('fixture-audit-unavailable'));
        try { $this->adopt(); $this->fail('Audit must be durable'); }
        catch (\RuntimeException $e) { $this->assertSame('fixture-audit-unavailable', $e->getMessage()); }
        $this->assertSame(0, Service::count()); $this->assertSame(0, Invoice::count());
    }

    public function test_refuses_non_admin_and_busy_import_before_any_upstream_request(): void
    {
        Http::fake(); $customer = $this->customer(); $server = $this->server();
        $actor = $this->admin(); $actor->forceFill(['role' => 'support'])->save();
        try {
            app(ExistingHostingAdoption::class)->adopt($customer, $server, 'managedtest', 'managed.example.test', $actor, 'fixture-adoption-v1');
            $this->fail('Support cannot attach an existing hosting account');
        } catch (\DomainException $e) { $this->assertSame('existing_hosting_invalid_authorization_or_identity', $e->getMessage()); }
        $lock = Cache::lock('hosting:existing-adoption:'.hash('sha256', 'managed.example.test'), 120); $this->assertTrue($lock->get());
        try { $this->adopt($customer, $server); $this->fail('Busy import must not continue'); }
        catch (\DomainException $e) { $this->assertSame('existing_hosting_adoption_busy', $e->getMessage()); }
        finally { $lock->release(); }
        Http::assertNothingSent(); $this->assertSame(0, Service::count());
    }

    public function test_mode_cycle_and_metadata_each_fail_closed_for_all_recurring_scopes(): void
    {
        $variants = [
            ['billing_mode' => 'external', 'cycle' => 'monthly', 'provision_meta' => []],
            ['billing_mode' => 'cycle', 'cycle' => 'external', 'provision_meta' => []],
            ['billing_mode' => 'cycle', 'cycle' => 'monthly'],
        ];
        foreach ($variants as $variant) {
            $service = $this->external($variant + ['next_due_at' => now()->subDay()]);
            $this->assertTrue($service->hasUnverifiedBillingTerms());
            $this->assertFalse($service->isRecurring());
            $this->assertNull($service->nextDueFrom(now()));
            $this->assertNull(RenewalTerms::serviceDeadline($service));
            $this->assertFalse(Service::customerRecurring()->whereKey($service->id)->exists());
            $this->assertFalse(Service::customerRecurring(true)->whereKey($service->id)->exists());
            $this->assertNull(RenewalTerms::ensureInvoice($service));
            $this->assertSame(__('ui.svc_existing_management'), $service->cycleLabel());
        }
        $this->assertSame(0, Invoice::count());
    }

    public function test_scheduled_billing_backfill_and_lifecycle_do_not_invoice_notify_or_suspend(): void
    {
        $service = $this->external(['cycle' => 'monthly', 'next_due_at' => now()->subDays(40)]);
        Http::fake();
        $this->artisan('services:renew-due')->assertSuccessful();
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->artisan('services:backfill-due')->assertSuccessful();
        $this->artisan('billing:repair-dates', ['--apply' => true])->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNull($service->fresh()->suspended_at);
        $this->assertSame(0, Invoice::count());
        Http::assertNothingSent(); Mail::assertNothingSent();
        $provisioner = \Mockery::mock(ProvisioningService::class);
        $provisioner->shouldNotReceive('suspend'); $provisioner->shouldNotReceive('unsuspend');
        $this->assertSame('deferred', BillingLifecycleActions::run($service, 'suspend', $provisioner));
    }

    public function test_direct_invoice_issuers_refuse_unknown_terms_but_normal_service_still_invoices(): void
    {
        $service = $this->external();
        foreach ([fn () => app(AdminServices::class)->issueInvoice($service),
            fn () => app(ProductInvoiceIssuer::class)->issue($service, new Product),
            fn () => Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
                'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'paid' => 0, 'status' => 'unpaid'])] as $issue) {
            try { $issue(); $this->fail('Unverified terms must not issue invoices'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('service', $e->errors()); }
        }
        $normal = Service::create(['customer_id' => $this->customer()->id, 'name' => 'Known monthly fixture',
            'price' => 100000, 'tax_percent' => 10, 'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'active']);
        $invoice = app(AdminServices::class)->issueInvoice($normal);
        $this->assertSame(110000, $invoice->total); $this->assertSame(1, Invoice::count());
        $this->assertTrue($normal->isRecurring());
    }

    public function test_explicit_renew_due_reprovision_and_customer_delete_cannot_mutate_adopted_service(): void
    {
        $this->whm(); $service = $this->adopt(); $actor = $this->admin();
        $this->actingAs($actor, 'web')->post('/admin/services/'.$service->id.'/renew')->assertSessionHasErrors();
        $this->actingAs($actor, 'web')->post('/admin/services/'.$service->id.'/due', ['next_due_at' => now()->addMonth()->toDateString()])->assertSessionHasErrors();
        $this->actingAs($actor, 'web')->post('/admin/services/'.$service->id.'/provision')->assertSessionHasErrors();
        $this->actingAs($service->customer, 'customer')->post('/account/services/'.$service->id.'/terminate/start')->assertRedirect();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNull($service->fresh()->next_due_at); $this->assertSame(0, Invoice::count());
        Http::assertSentCount(1);
    }

    public function test_management_ui_api_and_calendar_do_not_claim_free_price_or_a_due_date(): void
    {
        $this->whm(); $service = $this->adopt();
        $this->actingAs($service->customer, 'customer')->get('/account/services')->assertOk()
            ->assertSee('managed.example.test')->assertSee(__('ui.svc_billing_terms_pending'))
            ->assertSee('/account/services/'.$service->id.'/cpanel', false);
        $request = Request::create('/fixture-services'); $request->attributes->set('api_customer', $service->customer);
        $response = app(CustomerApiController::class)->services($request)->getData(true)['data'][0];
        $this->assertNull($response['price']); $this->assertNull($response['cycle']);
        $this->assertSame('unverified', $response['billing_terms']);
        $service->forceFill(['next_due_at' => now()->addDay(), 'cycle' => 'monthly'])->save();
        $events = app(HostingRenewalProvider::class)->getEvents(now()->subDay(), now()->addWeek());
        $this->assertCount(0, $events);
    }

    public function test_adopted_account_uses_existing_cpanel_sso_with_customer_isolation(): void
    {
        $this->whm(); $service = $this->adopt();
        Http::fake(['https://whm.example.test:2087/json-api/create_user_session*' => Http::response([
            'metadata' => ['result' => 1], 'data' => ['url' => 'https://whm.example.test:2083/fixture-session/']])]);
        $this->actingAs($service->customer, 'customer')->get('/account/services/'.$service->id.'/cpanel')
            ->assertRedirect('https://whm.example.test:2083/fixture-session/');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'create_user_session') && $r['user'] === 'managedtest' && $r['service'] === 'cpaneld');
        $this->actingAs($this->customer(), 'customer')->get('/account/services/'.$service->id.'/cpanel')->assertNotFound();
        $this->assertSame(0, Invoice::count());
    }
}
