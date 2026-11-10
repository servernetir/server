<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\Service;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudPower;
use App\Services\Cloud\CloudProvider;
use App\Services\Cloud\HourlyGpuStart;
use App\Services\Cloud\HourlyHold;
use App\Services\Cloud\InterruptibleBillingClock;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerCloudApiTest extends TestCase
{
    use RefreshDatabase;

    private string $liveStatus = 'off';
    private array $commands = [];
    private array $powerResult = ['ok' => true];
    private bool $throwPower = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
        Wallet::flushSchemaCache(); HourlyHold::flush();
        Http::preventStrayRequests();
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('serverStatus')->andReturnUsing(fn () => ['ok' => true, 'status' => $this->liveStatus,
            'ipv4' => '192.0.2.10', 'ipv6' => null, 'hostname' => 'example.test', 'build' => ['secret' => 'private-provider-data']]);
        $driver->shouldReceive('power')->andReturnUsing(function ($ref, $action) {
            $this->commands[] = [$ref, $action];
            if ($this->throwPower) { throw new \RuntimeException('test transport lost'); }
            return $this->powerResult;
        });
        $manager = \Mockery::mock(CloudManager::class)->makePartial();
        $manager->shouldReceive('forInstance')->andReturn($driver);
        $this->app->instance(CloudManager::class, $manager);
    }

    protected function tearDown(): void
    {
        Wallet::flushSchemaCache(); HourlyHold::flush(); $this->travelBack();
        parent::tearDown();
    }

    private function fixture(int $balance = 1000, array $abilities = ['cloud:read', 'cloud:power']): array
    {
        $customer = Customer::create(['email' => uniqid('api-').'@example.test', 'status' => 'active', 'locale' => 'en']);
        if ($balance > 0) { app(Wallet::class)->credit($customer->id, 'IRT', $balance, 'topup', $customer); }
        $plan = CloudPlan::create(['provider' => 'salad', 'provider_ref' => uniqid('synthetic'), 'is_interruptible' => true,
            'location_code' => 'de-test', 'public_name' => 'Test GPU', 'slug' => uniqid('gpu'), 'price_irt' => 720000]);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Test GPU', 'currency_code' => 'IRT',
            'price' => 720000, 'cycle' => 'monthly', 'billing_mode' => 'hourly', 'hourly_rate_irt' => 1000,
            'status' => 'active', 'provision_status' => 'done', 'last_metered_at' => now(), 'cloud_plan_id' => $plan->id]);
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'salad', 'provider_ref' => 'private-ref-'.$service->id,
            'location_code' => 'de-test', 'image_key' => 'gpu-jupyter', 'hostname' => 'example.test',
            'ipv4' => '192.0.2.10', 'status' => 'off', 'ready_notified_at' => now(), 'meta' => ['secret' => 'never-public']]);
        [$token, $plain] = CustomerApiToken::issue($customer->id, 'Synthetic', $abilities);
        return [$customer, $service, $token, $plain];
    }

    private function headers(string $plain, string $key = 'unique-command-000001'): array
    {
        return ['Authorization' => 'Bearer '.$plain, 'Idempotency-Key' => $key];
    }

    private function power(Service $service, string $plain, string $action = 'start', string $key = 'unique-command-000001')
    {
        return $this->postJson('/api/v1/cloud/'.$service->id.'/actions/'.$action, [], $this->headers($plain, $key));
    }

    public function test_cloud_requires_an_explicit_scope_and_is_stateless_even_with_a_panel_login(): void
    {
        [$c, $s, $t, $plain] = $this->fixture(1000, ['*']);
        $this->actingAs($c, 'customer')->getJson('/api/v1/cloud')->assertUnauthorized()->assertJsonPath('error', 'missing_token');
        $this->getJson('/api/v1/cloud', $this->headers($plain))->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
        $this->power($s, $plain)->assertForbidden();
        $this->assertCount(0, $this->commands);
    }

    public function test_expired_revoked_and_ip_restricted_tokens_are_denied(): void
    {
        [, $s, $t, $plain] = $this->fixture();
        $t->update(['expires_at' => now()->subSecond()]);
        $this->power($s, $plain)->assertUnauthorized()->assertJsonPath('error', 'token_expired');
        $t->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->power($s, $plain)->assertUnauthorized()->assertJsonPath('error', 'token_revoked');
        $t->update(['revoked_at' => null, 'allowed_cidrs' => ['192.0.2.0/24']]);
        $this->power($s, $plain)->assertForbidden()->assertJsonPath('error', 'ip_not_allowed');
        $this->assertCount(0, $this->commands);
    }

    public function test_reads_and_writes_cannot_access_another_customer_or_an_excluded_service(): void
    {
        [, $s, $t, $plain] = $this->fixture();
        [, $other] = $this->fixture();
        $this->getJson('/api/v1/cloud/'.$other->id, $this->headers($plain))->assertNotFound();
        $this->power($other, $plain)->assertNotFound();
        $t->update(['cloud_service_ids' => [$other->id]]);
        $this->power($s, $plain)->assertNotFound();
        $this->getJson('/api/v1/cloud', $this->headers($plain))->assertOk()->assertJsonPath('data', []);
        $this->assertSame(0, DB::table('customer_api_operations')->count());
    }

    public function test_service_output_is_allowlisted_and_private(): void
    {
        [, $s, , $plain] = $this->fixture();
        $r = $this->getJson('/api/v1/cloud/'.$s->id, $this->headers($plain))->assertOk()
            ->assertJsonPath('data.billing.hourly_rate_irt', 1000)->assertJsonPath('data.billing.off_billing', 'paused');
        foreach (['private-ref', 'never-public', 'provider', 'token_hash', 'root_password'] as $secret) {
            $this->assertStringNotContainsString($secret, $r->getContent());
        }
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertCount(0, $this->commands);
    }

    public function test_start_reserves_exactly_one_hour_and_replay_never_dispatches_or_charges_again(): void
    {
        [$c, $s, , $plain] = $this->fixture();
        $r = $this->power($s, $plain)->assertAccepted()->assertJsonPath('accepted', true);
        $this->assertSame(1000, app(Wallet::class)->balanceOf($c->id));
        $this->assertSame(0, app(Wallet::class)->availableOf($c->id));
        $this->assertSame(1000, HourlyGpuStart::pending($s->fresh()));
        Cache::flush();
        $this->power($s, $plain)->assertAccepted()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($r->json());
        $this->assertCount(1, $this->commands);
        $this->liveStatus = 'running'; $this->travel(21)->seconds();
        $this->getJson('/api/v1/cloud/'.$s->id.'/status', $this->headers($plain))->assertOk()->assertJsonPath('data.status', 'running');
        $this->assertSame(0, app(Wallet::class)->balanceOf($c->id));
        $this->assertSame(1, CreditEntry::where('reason', 'cloud_hourly')->count());
        $this->getJson('/api/v1/cloud/'.$s->id.'/status', $this->headers($plain))->assertOk();
        $this->assertSame(1, CreditEntry::where('reason', 'cloud_hourly')->count());
    }

    public function test_one_key_cannot_be_reused_for_a_different_action(): void
    {
        [, $s, , $plain] = $this->fixture();
        $this->power($s, $plain)->assertAccepted();
        $this->power($s, $plain, 'stop')->assertConflict()->assertJsonPath('error', 'idempotency_conflict');
        $this->assertCount(1, $this->commands);
    }

    public function test_missing_key_insufficient_credit_and_inactive_service_never_dispatch(): void
    {
        [, $s, , $plain] = $this->fixture(999);
        $this->power($s, $plain, 'start', '')->assertUnprocessable();
        $this->power($s, $plain)->assertStatus(402)->assertJsonPath('error', 'insufficient_credit');
        $s->update(['status' => 'suspended']);
        $this->power($s, $plain, 'start', 'unique-command-000002')->assertConflict()->assertJsonPath('error', 'service_not_active');
        $this->assertCount(0, $this->commands);
    }

    public function test_start_on_running_preserves_the_paid_runtime_anchor_in_both_transports(): void
    {
        [$c, $s, , $plain] = $this->fixture();
        $this->liveStatus = 'running'; $s->cloudInstance->update(['status' => 'running']);
        $s->update(['last_metered_at' => now()->subMinutes(50)]);
        $before = $s->fresh()->last_metered_at->toISOString();
        $this->power($s, $plain)->assertAccepted()->assertJsonPath('no_op', true);
        $this->actingAs($c, 'customer')->post(route('account.cloud.power', $s), ['action' => 'on'])->assertSessionHasNoErrors();
        $this->assertSame($before, $s->fresh()->last_metered_at->toISOString());
        $this->assertCount(0, $this->commands);
        $this->assertSame(1000, app(Wallet::class)->availableOf($c->id));
    }

    public function test_stop_acceptance_keeps_funds_until_a_later_verified_off_observation(): void
    {
        [$c, $s, , $plain] = $this->fixture();
        $this->power($s, $plain)->assertAccepted();
        $this->travel(1)->seconds();
        $this->power($s, $plain, 'stop', 'unique-command-000002')->assertAccepted();
        $this->assertSame(1000, HourlyGpuStart::pending($s->fresh()));
        $this->assertSame(0, app(Wallet::class)->availableOf($c->id));
        $this->travel(1)->seconds();
        $this->getJson('/api/v1/cloud/'.$s->id.'/status', $this->headers($plain))->assertOk()->assertJsonPath('data.status', 'off');
        $this->assertSame(1000, app(Wallet::class)->availableOf($c->id));
        $this->assertSame(0, CreditEntry::where('reason', 'cloud_hourly')->count());
    }

    public function test_unknown_command_is_not_retried_and_a_safe_stop_remains_available(): void
    {
        [, $s, , $plain] = $this->fixture();
        $this->throwPower = true;
        $r = $this->power($s, $plain)->assertStatus(503)->assertJsonPath('error', 'provider_result_unknown');
        $this->power($s, $plain)->assertStatus(503)->assertHeader('Idempotency-Replayed', 'true');
        $this->power($s, $plain, 'start', 'unique-command-000002')->assertConflict();
        $this->throwPower = false;
        $this->power($s, $plain, 'stop', 'unique-command-000003')->assertAccepted();
        $this->assertCount(2, $this->commands);
        $this->getJson('/api/v1/operations/'.$r->json('operation_id'), $this->headers($plain))->assertOk()->assertJsonPath('data.state', 'uncertain');
    }

    public function test_new_matching_observation_reconciles_an_unknown_start_without_replaying_it(): void
    {
        [, $s, , $plain] = $this->fixture();
        $this->powerResult = ['ok' => false, 'message' => 'private provider diagnostic'];
        $r = $this->power($s, $plain)->assertStatus(503);
        $this->liveStatus = 'running'; $this->travel(21)->seconds();
        $this->getJson('/api/v1/cloud/'.$s->id.'/status', $this->headers($plain))->assertOk();
        $this->getJson('/api/v1/operations/'.$r->json('operation_id'), $this->headers($plain))->assertOk()->assertJsonPath('data.state', 'reconciled');
        $this->assertCount(1, $this->commands);
    }

    public function test_confirmed_stop_supersedes_an_old_unknown_start_and_allows_a_new_start(): void
    {
        [, $s, , $plain] = $this->fixture();
        $this->throwPower = true;
        $r = $this->power($s, $plain)->assertStatus(503);
        $this->throwPower = false; $this->travel(1)->seconds();
        $this->power($s, $plain, 'stop', 'unique-command-000002')->assertAccepted();
        $this->travel(1)->seconds();
        $this->getJson('/api/v1/cloud/'.$s->id.'/status', $this->headers($plain))->assertOk();
        $this->getJson('/api/v1/operations/'.$r->json('operation_id'), $this->headers($plain))->assertOk()->assertJsonPath('data.state', 'superseded');
        $this->power($s, $plain, 'start', 'unique-command-000003')->assertAccepted();
        $this->assertCount(3, $this->commands);
    }

    public function test_a_crashed_operation_can_be_safely_stopped_after_the_dispatch_lease(): void
    {
        [$c, $s, $t, $plain] = $this->fixture();
        DB::table('customer_api_operations')->insert(['public_id' => (string) \Illuminate\Support\Str::uuid(),
            'customer_id' => $c->id, 'token_id' => $t->id, 'service_id' => $s->id,
            'key_hash' => hash('sha256', 'old-key'), 'fingerprint' => hash('sha256', 'old'), 'action' => 'on',
            'state' => 'processing', 'created_at' => now()->subMinutes(6), 'updated_at' => now()->subMinutes(6)]);
        $this->power($s, $plain, 'stop')->assertAccepted();
        $this->assertCount(1, $this->commands);
    }

    public function test_wallet_uses_real_reservations_and_invoice_totals_without_recalculating_them(): void
    {
        [$c, $s, , $plain] = $this->fixture(2000, ['read', 'cloud:read', 'cloud:power']);
        $this->power($s, $plain)->assertAccepted();
        $this->getJson('/api/v1/wallet', $this->headers($plain))->assertOk()
            ->assertJsonPath('data.IRT.balance', 2000)->assertJsonPath('data.IRT.reserved', 1000)->assertJsonPath('data.IRT.available', 1000);
        $i = \App\Models\Invoice::create(['customer_id' => $c->id, 'number' => 'SYNTHETIC-INVOICE', 'currency_code' => 'IRT',
            'subtotal' => 10000, 'discount' => 2000, 'tax' => 800, 'total' => 8800, 'paid' => 3000, 'status' => 'partial']);
        $this->getJson('/api/v1/invoices/'.$i->id, $this->headers($plain))->assertOk()
            ->assertJsonPath('data.total', 8800)->assertJsonPath('data.due', 5800)->assertJsonPath('data.tax', 800);
        [, , , $otherPlain] = $this->fixture(0, ['read']);
        $this->getJson('/api/v1/invoices/'.$i->id, $this->headers($otherPlain))->assertNotFound();
        $this->assertSame(1, CreditEntry::where('customer_id', $c->id)->count());
    }

    public function test_shared_lock_blocks_panel_api_overlap_and_billing_hold_allows_stop(): void
    {
        [$c, $s, , $plain] = $this->fixture();
        $lock = Cache::lock('cloud-power:'.$s->id, 300); $lock->get();
        $this->power($s, $plain)->assertConflict()->assertJsonPath('error', 'operation_in_progress');
        $lock->release();
        $s->update(['provision_meta' => ['hourly_billing_hold' => ['active' => true]]]);
        $this->actingAs($c, 'customer')->post(route('account.cloud.power', $s), ['action' => 'off'])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->commands);
    }

    public static function locales(): array { return [['fa', ''], ['en', '/en'], ['tr', '/tr']]; }

    #[DataProvider('locales')]
    public function test_docs_and_profile_hub_render_in_all_locales_without_charging(string $locale, string $prefix): void
    {
        [$c, , , $plain] = $this->fixture();
        $c->update(['locale' => $locale]);
        $this->get($prefix.'/developers/platform?qa=1')->assertOk()->assertSee('cloud:power')->assertSee('OpenAPI');
        $this->actingAs($c, 'customer')->get($prefix.'/account/api')->assertOk()->assertSee('Idempotency-Key');
        $this->getJson($prefix.'/developers/platform/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0');
        $this->assertSame(1000, app(Wallet::class)->availableOf($c->id));
        $this->assertCount(0, $this->commands); Http::assertNothingSent();
    }

    public function test_scope_form_rejects_foreign_service_and_persists_owned_restriction(): void
    {
        [$c, $s] = $this->fixture(); [, $other] = $this->fixture();
        $this->actingAs($c, 'customer')->post(route('account.security.token'), ['name' => 'Limited GPU',
            'abilities' => ['cloud:read', 'cloud:power'], 'cloud_service_ids' => (string) $other->id])->assertSessionHasErrors('cloud_service_ids');
        $this->post(route('account.security.token'), ['name' => 'Limited GPU', 'abilities' => ['cloud:read', 'cloud:power'],
            'cloud_service_ids' => (string) $s->id, 'expires_days' => 7])->assertSessionHas('new_token');
        $this->assertSame([$s->id], CustomerApiToken::where('name', 'Limited GPU')->sole()->cloud_service_ids);
    }

}
