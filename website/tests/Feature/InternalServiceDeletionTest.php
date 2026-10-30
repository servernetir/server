<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\ServiceController;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InternalServiceDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 200)]);
    }

    private function service(array $attributes = []): Service
    {
        $customer = Customer::create([
            'email' => 'protected@example.test', 'phone' => '09121112233',
            'password' => bcrypt('test'), 'status' => 'active', 'locale' => 'fa',
        ]);
        CloudLocation::create(['code' => 'de-test', 'country' => 'DE', 'city' => 'Test', 'is_active' => true]);
        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx-test',
            'provider_location' => 'test', 'location_code' => 'de-test',
            'public_name' => 'Test', 'slug' => 'protected-test', 'vcpu' => 2,
            'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'ssd',
            'cost_eur_cents' => 400, 'price_eur_cents' => 500, 'price_irt' => 500000,
            'is_active' => true, 'in_stock' => true,
        ]);
        $service = Service::create($attributes + [
            'customer_id' => $customer->id, 'name' => 'Protected service', 'currency_code' => 'IRT',
            'price' => 500000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done', 'activated_at' => now(),
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
        ]);
        CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'hetzner', 'provider_ref' => '7001',
            'location_code' => 'de-test', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$service->id, 'status' => 'running',
            'ipv4' => '192.0.2.20', 'ready_notified_at' => now(),
        ]);

        return $service->fresh();
    }

    private function protect(Service $service): void
    {
        config(['billing.internal_service_ids' => [$service->id]]);
    }

    private function untrackedHealth(): array
    {
        return collect(app(SystemHealth::class)->checks())->keyBy('key')['cloud_release_untracked'];
    }

    public function test_customer_cannot_start_deletion_of_a_protected_service(): void
    {
        $s = $this->service();
        $this->protect($s);
        $this->actingAs($s->customer, 'customer')->post("/account/services/{$s->id}/terminate/start")
            ->assertSessionHasErrors()->assertSessionMissing('svc_terminate_ctx');
        $this->assertSame('active', $s->fresh()->status);
        $this->assertSame('done', $s->fresh()->provision_status);
        Http::assertNothingSent();
    }

    public function test_new_protection_invalidates_an_existing_customer_confirmation(): void
    {
        $s = $this->service();
        $this->actingAs($s->customer, 'customer')->post("/account/services/{$s->id}/terminate/start");
        $payload = ['confirmation_phrase' => session('svc_terminate_ctx.phrase'), 'confirmation_token' => session('svc_terminate_ctx.token')];
        $this->protect($s);
        $this->post("/account/services/{$s->id}/terminate", $payload)
            ->assertSessionHasErrors()->assertSessionMissing('svc_terminate_ctx');
        $this->assertSame('active', $s->fresh()->status);
        $this->assertNull($s->fresh()->cancelled_at);
        $this->assertDatabaseMissing('activity_logs', ['service_id' => $s->id, 'action' => 'terminate']);
        Http::assertNothingSent();
    }

    public function test_cancellation_cannot_bypass_internal_protection_or_refund_money(): void
    {
        $s = $this->service(['status' => 'awaiting_provision', 'provision_status' => 'pending']);
        $this->protect($s);
        $this->actingAs($s->customer, 'customer')->post("/account/services/{$s->id}/cancel")->assertSessionHasErrors();
        $this->assertSame('awaiting_provision', $s->fresh()->status);
        $this->assertSame('pending', $s->fresh()->provision_status);
        $this->assertDatabaseCount('credit_ledger', 0);
        Http::assertNothingSent();
    }

    public function test_cancellation_rechecks_the_locked_row_before_refund_or_release(): void
    {
        $s = $this->service(['status' => 'awaiting_provision', 'provision_status' => 'pending']);
        $this->actingAs($s->customer, 'customer');
        Service::whereKey($s->id)->update(['status' => 'active', 'provision_status' => 'done']);
        $request = Request::create('/account/services/'.$s->id.'/cancel', 'POST');
        $request->setLaravelSession(app('session')->driver());
        app(ServiceController::class)->cancel($request, $s);
        $this->assertSame('active', $s->fresh()->status);
        $this->assertSame('running', $s->cloudInstance->fresh()->status);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseMissing('activity_logs', ['service_id' => $s->id, 'action' => 'terminate']);
        Http::assertNothingSent();
    }

    public function test_protected_actions_explain_the_restriction_in_all_three_languages(): void
    {
        $s = $this->service();
        $this->protect($s);
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $html = view('account.partials.svc-actions', ['s' => $s, 'kind' => 'server'])->render();
            $this->assertStringContainsString(__('ui.svc_removal_protected'), $html);
            $this->assertStringNotContainsString('/terminate/start', $html);
            $this->assertStringNotContainsString('/cancel"', $html);
            $this->assertStringNotContainsString('hetzner', strtolower($html));
        }
    }

    public function test_protected_pending_service_has_no_cancel_button(): void
    {
        $s = $this->service(['status' => 'awaiting_provision', 'provision_status' => 'pending']);
        $this->protect($s);
        $html = view('account.partials.svc-actions', ['s' => $s, 'kind' => 'server'])->render();
        $this->assertStringNotContainsString('/cancel"', $html);
    }

    public function test_normal_customer_deletion_still_releases_the_provider_machine(): void
    {
        $s = $this->service();
        config(['billing.internal_service_ids' => []]);
        Setting::putSecret('hetzner_api_token', 'test-token');
        $this->actingAs($s->customer, 'customer')->post("/account/services/{$s->id}/terminate/start");
        $this->post("/account/services/{$s->id}/terminate", [
            'confirmation_phrase' => session('svc_terminate_ctx.phrase'),
            'confirmation_token' => session('svc_terminate_ctx.token'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('terminated', $s->fresh()->status);
        $this->assertSame('none', $s->fresh()->provision_status);
        $this->assertSame('deleted', $s->cloudInstance->fresh()->status);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && $r->url() === 'https://api.hetzner.cloud/v1/servers/7001');
    }

    public function test_dead_protected_service_outside_release_queue_is_not_reported_healthy(): void
    {
        $s = $this->service(['status' => 'terminated']);
        $this->protect($s);
        $health = $this->untrackedHealth();
        $this->assertSame('fail', $health['level']);
        $this->assertStringContainsString('#'.fa_num($s->id), $health['detail']);
        $this->assertSame('running', $s->cloudInstance->fresh()->status);
        $this->assertSame('done', $s->fresh()->provision_status);
        Http::assertNothingSent();
    }

    public function test_unprotected_legacy_mismatch_is_also_detected(): void
    {
        $this->service(['status' => 'cancelled', 'provision_status' => 'none']);
        config(['billing.internal_service_ids' => []]);
        $this->assertSame('fail', $this->untrackedHealth()['level']);
    }

    public function test_live_protected_service_is_healthy(): void
    {
        $s = $this->service();
        $this->protect($s);
        $this->assertSame('ok', $this->untrackedHealth()['level']);
    }

    public function test_completed_deletion_does_not_raise_an_untracked_alarm(): void
    {
        $s = $this->service(['status' => 'terminated', 'provision_status' => 'none']);
        $s->cloudInstance->update(['status' => 'deleted']);
        $this->assertSame('ok', $this->untrackedHealth()['level']);
    }

    public function test_existing_release_queue_is_not_counted_twice(): void
    {
        $this->service(['status' => 'terminated', 'provision_status' => 'releasing']);
        $checks = collect(app(SystemHealth::class)->checks())->keyBy('key');
        $this->assertSame('ok', $checks['cloud_release_untracked']['level']);
        $this->assertSame('fail', $checks['cloud_release']['level']);
    }
}
