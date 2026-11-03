<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\ArvanClient;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Finance\ArvanTrafficCreditGuard;
use App\Services\Finance\Wallet;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Real database commits + actual Arvan driver with synthetic HTTP only. No live VM or financial account. */
class ArvanTrafficCreditGuardTest extends TestCase
{

    private const ID = '00000000-0000-0000-0000-000000000001';
    private const REF = 'ir-test:'.self::ID;
    private const URL = 'https://napi.arvancloud.ir/ecc/v1/regions/ir-test/servers/'.self::ID;

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = config('database.connections.'.config('database.default'));
        $this->assertSame('testing', app()->environment());
        if (($cfg['driver'] ?? null) === 'sqlite') {
            $this->assertSame(':memory:', $cfg['database']);
        } else {
            $this->assertContains($cfg['driver'], ['mysql', 'mariadb']);
            $this->assertSame('127.0.0.1', $cfg['host']);
            $this->assertSame('incident_testing', $cfg['database']);
            $this->assertSame('1', getenv('ARVAN_TRAFFIC_MARIADB'));
        }
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        RefreshDatabaseState::$migrated = false;
        Http::preventStrayRequests();
        Wallet::flushSchemaCache();
        Setting::putSecret('arvan_api_token', 'synthetic-credit-guard-token');
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function fixture(bool $held = true): array
    {
        $customer = Customer::create(['email' => 'credit-guard-'.bin2hex(random_bytes(4)).'@example.test', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic credit guard',
            'currency_code' => 'IRT', 'billing_mode' => 'cycle', 'cycle' => 'monthly', 'status' => 'active',
            'provision_status' => 'done', 'provision_meta' => ['staff_marker' => 'preserve']]);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan',
            'provider_ref' => 'ir-test:00000000-0000-0000-0000-'.str_pad((string) $service->id, 12, '0', STR_PAD_LEFT), 'status' => 'running', 'ipv4' => '192.0.2.1']);
        if ($held) {
            DB::transaction(function () use ($customer, $service, $instance): void {
                Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $current = Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
                $vm = CloudInstance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
                $this->assertTrue(app(ArvanTrafficCreditGuard::class)->holdLocked($current, $vm, 99, 0));
            });
        }

        return [$customer, $service, $instance];
    }

    private function detail(string $status, string $id = self::ID): array
    {
        return ['data' => ['id' => $id, 'status' => $status, 'addresses' => []]];
    }

    private function fund(Customer $customer): void
    {
        app(Wallet::class)->credit($customer->id, 'IRT', 1, 'topup', null);
    }

    public function test_monthly_zero_balance_without_a_new_debit_is_not_swept(): void
    {
        [, $service] = $this->fixture(false);
        $this->assertSame(['confirmed' => 0, 'pending' => 0, 'released' => 0, 'stale' => 0, 'review_required' => 0], app(ArvanTrafficCreditGuard::class)->process());
        $this->assertFalse(ArvanTrafficCreditGuard::active($service->fresh()));
        Http::assertNothingSent();
    }

    public function test_actual_driver_requires_detail_identity_for_stop_acknowledgement(): void
    {
        Http::fake([self::URL => Http::sequence()->push($this->detail('SHUTOFF', 'different-vm'))->push($this->detail('SHUTOFF'))]);
        $driver = app(ArvanClient::class);
        $this->assertNull($driver->serverStatus(self::REF)['provider_ref']);
        $this->assertSame(self::REF, $driver->serverStatus(self::REF)['provider_ref']);
    }

    public function test_stop_commits_before_provider_io_and_confirms_exact_uuid_without_charging_again(): void
    {
        [, $service, $instance] = $this->fixture();
        $posts = 0;
        Http::fake(function ($request) use (&$posts) {
            $this->assertSame(0, DB::transactionLevel(), 'Provider I/O must occur after the financial commit');
            if ($request->method() === 'POST') {
                $posts++;
                $this->assertSame(self::URL.'/power-off', $request->url());

                return Http::response(['data' => []]);
            }

            return Http::response($this->detail($posts > 0 ? 'SHUTOFF' : 'ACTIVE'));
        });
        $guard = app(ArvanTrafficCreditGuard::class);
        $this->assertSame('confirmed', $guard->reconcile($service->id));
        $this->assertSame(1, $posts);
        $this->assertSame('off', $instance->fresh()->status);
        $this->assertTrue(ArvanTrafficCreditGuard::active($service->fresh()));
        $this->assertSame('confirmed', data_get(ArvanTrafficCreditGuard::state($service->fresh()), 'stop.state'));
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNull($service->fresh()->suspended_at);
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame('pending', $guard->reconcile($service->id));
        $this->assertSame(1, $posts);
    }

    public function test_accepted_post_is_pending_until_exact_off_get_and_retry_does_not_post_or_bill_twice(): void
    {
        [, $service] = $this->fixture();
        Http::fake([self::URL => Http::sequence()->push($this->detail('ACTIVE'))->push($this->detail('ACTIVE'))->push($this->detail('SHUTOFF')),
            self::URL.'/power-off' => Http::response(['data' => []])]);
        $guard = app(ArvanTrafficCreditGuard::class);
        $this->assertSame('pending', $guard->reconcile($service->id));
        $this->assertTrue(data_get(ArvanTrafficCreditGuard::state($service->fresh()), 'stop.inflight'));
        $this->assertSame('pending', $guard->reconcile($service->id));
        Http::assertSentCount(3);
        $this->travel(6)->minutes();
        $this->assertSame('confirmed', $guard->reconcile($service->id));
        Http::assertSentCount(4);
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_wrong_uuid_and_api_outage_never_send_off_or_acknowledge(): void
    {
        [, $service, $instance] = $this->fixture();
        Http::fake([self::URL => Http::sequence()->push($this->detail('SHUTOFF', 'wrong'))->push([], 503)]);
        $guard = app(ArvanTrafficCreditGuard::class);
        $this->assertSame('pending', $guard->reconcile($service->id));
        $this->travel(6)->minutes();
        $this->assertSame('pending', $guard->reconcile($service->id));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        $this->assertSame('running', $instance->fresh()->status);
    }

    public function test_funding_before_claim_cancels_only_traffic_hold_without_starting_or_changing_staff_hold(): void
    {
        [$customer, $service, $instance] = $this->fixture();
        $meta = $service->fresh()->provision_meta;
        $meta['hourly_billing_hold'] = ['active' => true, 'reason' => 'operator'];
        $service->update(['provision_meta' => $meta, 'status' => 'suspended']);
        $this->fund($customer);
        $this->assertSame('released', app(ArvanTrafficCreditGuard::class)->reconcile($service->id));
        $fresh = $service->fresh();
        $this->assertFalse(ArvanTrafficCreditGuard::active($fresh));
        $this->assertTrue($fresh->hourlyBillingHeld());
        $this->assertSame('preserve', $fresh->provision_meta['staff_marker']);
        $this->assertSame('suspended', $fresh->status);
        $this->assertSame('running', $instance->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_funding_during_first_get_cancels_unissued_off_and_allows_explicit_restart(): void
    {
        [$customer, $service] = $this->fixture();
        Http::fake(function ($request) use ($customer) {
            $this->assertSame('GET', $request->method());
            $this->fund($customer);

            return Http::response($this->detail('ACTIVE'));
        });
        $guard = app(ArvanTrafficCreditGuard::class);
        $this->assertSame('released', $guard->reconcile($service->id));
        $this->assertTrue($guard->allowPower(self::REF));
        Http::assertSentCount(1);
    }

    public function test_funded_restart_cannot_race_an_issued_ambiguous_stop(): void
    {
        [$customer, $service] = $this->fixture();
        $posts = 0;
        $funded = false;
        $offObserved = false;
        Http::fake(function ($request) use (&$posts, &$funded, &$offObserved, $customer) {
            if ($request->method() === 'POST') {
                $posts++;
                $this->fund($customer);
                $funded = true;
                $this->assertFalse(app(ArvanTrafficCreditGuard::class)->allowPower(self::REF), 'An unresolved OFF must block a new ON even after topup');

                return Http::response(['data' => []]);
            }

            return Http::response($this->detail($offObserved ? 'SHUTOFF' : 'ACTIVE'));
        });
        $guard = app(ArvanTrafficCreditGuard::class);
        $this->assertSame('pending', $guard->reconcile($service->id));
        $this->assertTrue($funded);
        $this->assertFalse($guard->allowPower(self::REF));
        $this->travel(6)->minutes();
        $this->assertSame('pending', $guard->reconcile($service->id));
        $this->assertSame(1, $posts, 'A funded ambiguous retry is GET-only');
        $offObserved = true;
        $this->travel(6)->minutes();
        $this->assertSame('released', $guard->reconcile($service->id));
        $this->assertTrue($guard->allowPower(self::REF));
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(1, CreditEntry::count());
    }

    public function test_customer_driver_and_automatic_unsuspend_cannot_bypass_unfunded_hold(): void
    {
        [, $service] = $this->fixture();
        $driver = app(ArvanClient::class);
        foreach (['on', 'reboot', 'reset'] as $action) {
            $this->assertFalse($driver->power(self::REF, $action)['ok']);
        }
        $this->assertFalse($driver->rebuild(self::REF, 'synthetic-image')['ok']);
        $this->assertFalse(app(CloudProvisioner::class)->unsuspend($service));
        Http::assertNothingSent();
    }

    public function test_one_positive_spendable_toman_releases_only_own_flag_for_explicit_power(): void
    {
        [$customer, $service] = $this->fixture();
        $this->fund($customer);
        Http::fake([self::URL.'/power-on' => Http::response(['data' => []])]);
        $this->assertTrue(app(ArvanClient::class)->power(self::REF, 'on')['ok']);
        $this->assertFalse(ArvanTrafficCreditGuard::active($service->fresh()));
        $this->assertSame('preserve', $service->fresh()->provision_meta['staff_marker']);
        Http::assertSentCount(1);
    }

    public function test_changed_ref_or_owner_prevents_provider_io(): void
    {
        [, $service, $instance] = $this->fixture();
        $instance->update(['provider_ref' => 'ir-test:replacement']);
        $this->assertSame('review_required', app(ArvanTrafficCreditGuard::class)->reconcile($service->id));
        Http::assertNothingSent();
    }

    public function test_same_ref_inflight_power_rearms_generation_and_stale_ack_cannot_confirm(): void
    {
        [, $service] = $this->fixture();
        $gets = 0;
        Http::fake(function ($request) use (&$gets) {
            $gets++;
            app(ArvanTrafficCreditGuard::class)->rearmAfterPower(self::REF);

            return Http::response($this->detail('SHUTOFF'));
        });
        $this->assertSame('pending', app(ArvanTrafficCreditGuard::class)->reconcile($service->id));
        $this->assertSame('pending', data_get(ArvanTrafficCreditGuard::state($service->fresh()), 'stop.state'));
        $this->assertSame(2, data_get(ArvanTrafficCreditGuard::state($service->fresh()), 'stop.generation'));
        $this->assertSame(1, $gets);
    }

    public function test_late_accepted_on_cannot_escape_an_active_confirmed_hold_forever(): void
    {
        [, $service] = $this->fixture();
        $running = false;
        $posts = 0;
        Http::fake(function ($request) use (&$running, &$posts) {
            if ($request->method() === 'POST') {
                $posts++;
                $running = false;
            }

            return Http::response($this->detail($running ? 'ACTIVE' : 'SHUTOFF'));
        });
        $guard = app(ArvanTrafficCreditGuard::class);
        $guard->rearmAfterPower(self::REF); // An earlier ON was accepted but has not become visible yet.
        $this->assertSame(1, $guard->process()['confirmed']);
        $this->assertSame(0, $posts);
        $running = true; // Provider applies the already queued ON after our first off observation.
        $this->travel(6)->minutes();
        $this->assertSame(1, $guard->process()['confirmed']);
        $this->assertSame(1, $posts);
        $this->assertFalse($running);
        $this->assertTrue(ArvanTrafficCreditGuard::active($service->fresh()));
    }

    public function test_funded_unresolved_issued_stop_has_bounded_operator_review_state(): void
    {
        [$customer, $service] = $this->fixture();
        Http::fake([self::URL => Http::response($this->detail('ACTIVE')),
            self::URL.'/power-off' => Http::response(['data' => []])]);
        $guard = app(ArvanTrafficCreditGuard::class);
        $this->assertSame('pending', $guard->reconcile($service->id));
        $this->fund($customer);
        $this->travel(16)->minutes();
        $this->assertSame(1, $guard->process()['review_required']);
        $hold = ArvanTrafficCreditGuard::state($service->fresh());
        $this->assertSame('review_required', $hold['stop']['state']);
        $this->assertSame('funded_account_with_unresolved_issued_stop', $hold['stop']['review_reason']);
        $this->assertFalse($guard->allowPower(self::REF));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/power-on'));
    }

    public function test_ordinary_hourly_credit_out_remains_independent_of_traffic_hold(): void
    {
        [, $service, $instance] = $this->fixture();
        $service->update(['billing_mode' => 'hourly', 'hourly_rate_irt' => 1000,
            'last_metered_at' => now()->subHours(2), 'activated_at' => now()->subDay(), 'on_credit_out' => 'suspend']);
        $instance->update(['ready_notified_at' => now()->subDay()]);
        $off = false;
        Http::fake(function ($request) use (&$off) {
            if ($request->method() === 'POST') {
                $off = true;
            }

            return Http::response($this->detail($off ? 'SHUTOFF' : 'ACTIVE'));
        });
        $this->assertSame(0, Artisan::call('cloud:meter'), Artisan::output());
        $fresh = $service->fresh();
        $this->assertSame('suspended', $fresh->status);
        $this->assertNotNull($fresh->suspended_at, 'Only the existing hourly policy sets its own retention marker');
        $this->assertTrue(ArvanTrafficCreditGuard::active($fresh));
        $this->assertArrayHasKey('hourly_suspend', $fresh->provision_meta);
        $this->assertArrayNotHasKey('hourly_billing_hold', $fresh->provision_meta);
    }

    public function test_funded_hourly_resume_does_not_resurrect_released_traffic_metadata(): void
    {
        [$customer, $service, $instance] = $this->fixture();
        $meta = $service->fresh()->provision_meta;
        $meta['hourly_suspend'] = ['confirmed_at' => now()->toIso8601String()];
        $service->update(['billing_mode' => 'hourly', 'hourly_rate_irt' => 1000, 'status' => 'suspended',
            'suspended_at' => now(), 'last_metered_at' => now(), 'provision_meta' => $meta]);
        $instance->update(['status' => 'off', 'ready_notified_at' => now()->subDay()]);
        app(Wallet::class)->credit($customer->id, 'IRT', 1001, 'topup', null);
        $on = false;
        Http::fake(function ($request) use (&$on) {
            if ($request->method() === 'POST') {
                $this->assertSame(self::URL.'/power-on', $request->url());
                $on = true;
            }

            return Http::response($this->detail($on ? 'ACTIVE' : 'SHUTOFF'));
        });
        $this->assertSame(0, Artisan::call('cloud:meter'), Artisan::output());
        $fresh = $service->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertFalse(ArvanTrafficCreditGuard::active($fresh));
        $this->assertArrayNotHasKey('hourly_suspend', $fresh->provision_meta);
        $this->assertSame(1, app(Wallet::class)->availableOf($customer->id));
    }

    public function test_admin_lifecycle_resume_preserves_released_traffic_flag_and_existing_staff_policy(): void
    {
        [$customer, $service] = $this->fixture();
        $meta = $service->fresh()->provision_meta;
        $meta['staff_suspend'] = ['active' => true];
        $plan = CloudPlan::create(['provider' => 'arvan', 'provider_ref' => 'synthetic-plan',
            'location_code' => 'ir-test', 'public_name' => 'Synthetic', 'slug' => 'synthetic']);
        $service->update(['cloud_plan_id' => $plan->id, 'status' => 'suspended', 'provision_meta' => $meta]);
        $this->fund($customer);
        Http::fake([self::URL => Http::response($this->detail('ACTIVE'))]);
        $result = app(\App\Services\Provisioning\ProvisioningService::class)->unsuspend($service);
        $this->assertTrue($result->ok);
        $fresh = $service->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertFalse(ArvanTrafficCreditGuard::active($fresh));
        $this->assertArrayNotHasKey('staff_suspend', $fresh->provision_meta, 'Existing explicit staff resume policy remains unchanged');
    }

    public function test_oldest_due_retry_order_does_not_starve_higher_service_ids(): void
    {
        [, $first] = $this->fixture();
        $others = [];
        for ($i = 0; $i < 5; $i++) {
            [, $row] = $this->fixture();
            $others[] = $row;
        }
        // Distinct refs are irrelevant to this read-only outage: no POST may be issued.
        Http::fake(fn () => Http::response([], 503));
        $guard = app(ArvanTrafficCreditGuard::class);
        for ($i = 0; $i < 6; $i++) {
            $guard->process(1);
            $this->travel(1)->minutes();
        }
        foreach (array_merge([$first], $others) as $row) {
            $this->assertSame(1, data_get(ArvanTrafficCreditGuard::state($row->fresh()), 'stop.attempts'),
                'Never-attempted intents must run before an older ID becomes eligible for its next retry');
        }
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_terminal_change_during_get_is_rechecked_before_any_off(): void
    {
        [, $service] = $this->fixture();
        Http::fake(function ($request) use ($service) {
            $service->update(['status' => 'cancelled']);
            $this->assertSame('GET', $request->method());

            return Http::response($this->detail('ACTIVE'));
        });
        $this->assertSame('review_required', app(ArvanTrafficCreditGuard::class)->reconcile($service->id));
        $this->assertSame('quarantined', data_get(ArvanTrafficCreditGuard::state($service->fresh()), 'stop.state'));
        Http::assertSentCount(1);
    }

    public function test_stale_prefix_is_quarantined_and_cannot_starve_valid_later_intent(): void
    {
        [, $stale, $old] = $this->fixture();
        [, $valid] = $this->fixture();
        $old->update(['provider_ref' => 'ir-test:replacement']);
        Http::fake(fn ($request) => Http::response($this->detail('SHUTOFF', basename(parse_url($request->url(), PHP_URL_PATH)))));
        $guard = app(ArvanTrafficCreditGuard::class);
        $this->assertSame(1, $guard->process(1)['review_required']);
        $this->assertSame(1, $guard->process(1)['confirmed']);
        $this->assertSame('quarantined', data_get(ArvanTrafficCreditGuard::state($stale->fresh()), 'stop.state'));
        $this->assertSame(1, data_get(ArvanTrafficCreditGuard::state($valid->fresh()), 'stop.attempts'));
        Http::assertSentCount(1);
    }

    public function test_funding_during_issued_stop_updates_exact_off_state_before_release(): void
    {
        [$customer, $service, $instance] = $this->fixture();
        $off = false;
        Http::fake(function ($request) use (&$off, $customer) {
            if ($request->method() === 'POST') {
                $off = true;
                $this->fund($customer);
            }

            return Http::response($this->detail($off ? 'SHUTOFF' : 'ACTIVE'));
        });
        $this->assertSame('released', app(ArvanTrafficCreditGuard::class)->reconcile($service->id));
        $this->assertFalse(ArvanTrafficCreditGuard::active($service->fresh()));
        $this->assertSame('off', $instance->fresh()->status);
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_stale_metadata_writes_cannot_erase_pending_or_resurrect_released_own_record(): void
    {
        [$customer, $service] = $this->fixture(false);
        $stale = $service->fresh();
        DB::transaction(function () use ($customer, $service): void {
            Customer::whereKey($customer->id)->lockForUpdate()->first();
            $current = Service::whereKey($service->id)->lockForUpdate()->first();
            $instance = CloudInstance::where('service_id', $service->id)->lockForUpdate()->first();
            app(ArvanTrafficCreditGuard::class)->holdLocked($current, $instance, 99, 0);
        });
        $stale->update(['provision_meta' => ['plan' => 'stale resize/warning write']]);
        $guard = app(ArvanTrafficCreditGuard::class);
        $this->assertTrue(ArvanTrafficCreditGuard::active($service));
        $this->assertFalse($guard->allowPower(self::REF));
        $this->fund($customer);
        $this->assertTrue($guard->allowPower(self::REF));
        $stale->update(['provision_meta' => ['plan' => 'another old lifecycle write']]);
        $this->assertFalse(ArvanTrafficCreditGuard::active($service));
        $this->assertArrayNotHasKey('traffic_credit_hold', $service->fresh()->provision_meta);
        Http::assertNothingSent();
    }

    public function test_corrupt_own_record_is_reported_without_starving_valid_stop_or_reading_cached_settings(): void
    {
        [, $bad] = $this->fixture();
        [, $valid] = $this->fixture();
        DB::table('settings')->where('key', ArvanTrafficCreditGuard::KEY.$bad->id)->update(['value' => '{broken']);
        app()->instance('settings.request-memo', [ArvanTrafficCreditGuard::KEY.$valid->id => '{stale-cache}',
            'arvan_api_token' => Crypt::encryptString('synthetic-credit-guard-token')]);
        Http::fake(fn ($request) => Http::response($this->detail('SHUTOFF', basename(parse_url($request->url(), PHP_URL_PATH)))));
        $result = app(ArvanTrafficCreditGuard::class)->process();
        $this->assertSame(1, $result['confirmed']);
        $this->assertSame(1, $result['review_required']);
        $this->assertFalse(app(ArvanTrafficCreditGuard::class)->allowPower(self::REF), 'Corrupt control must deny restart without exposing a raw exception');
        $this->expectException(\JsonException::class);
        ArvanTrafficCreditGuard::state($bad);
    }

    public function test_orphan_and_noncanonical_keys_are_reported_without_consuming_valid_stop_limit(): void
    {
        [, $orphan] = $this->fixture();
        [, $valid] = $this->fixture();
        $payload = json_encode(ArvanTrafficCreditGuard::state($orphan), JSON_THROW_ON_ERROR);
        DB::table('cloud_instances')->where('service_id', $orphan->id)->delete();
        DB::table('services')->where('id', $orphan->id)->delete();
        Setting::create(['key' => ArvanTrafficCreditGuard::KEY.'000'.$valid->id, 'value' => $payload]);
        Http::fake(fn ($request) => Http::response($this->detail('SHUTOFF', basename(parse_url($request->url(), PHP_URL_PATH)))));
        $result = app(ArvanTrafficCreditGuard::class)->process(1);
        $this->assertSame(1, $result['confirmed']);
        $this->assertSame(2, $result['review_required']);
        Http::assertSentCount(1);
    }

    public function test_duplicate_resource_added_after_hold_or_during_get_is_never_stopped(): void
    {
        [, $service, $instance] = $this->fixture();
        [, $other, $second] = $this->fixture(false);
        Http::fake(function ($request) use ($second, $instance) {
            $this->assertSame('GET', $request->method());
            $second->update(['provider_ref' => $instance->provider_ref]);

            return Http::response($this->detail('ACTIVE'));
        });
        $this->assertSame('review_required', app(ArvanTrafficCreditGuard::class)->reconcile($service->id));
        $this->assertSame('quarantined', data_get(ArvanTrafficCreditGuard::state($service), 'stop.state'));
        $this->assertFalse(app(ArvanTrafficCreditGuard::class)->allowPower(self::REF));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_unsupported_or_malformed_control_shape_is_reported_before_limit_without_blocking_valid_stop(): void
    {
        [, $bad] = $this->fixture();
        [, $valid] = $this->fixture();
        $state = ArvanTrafficCreditGuard::state($bad);
        $state['version'] = 2;
        DB::table('settings')->where('key', ArvanTrafficCreditGuard::KEY.$bad->id)->update(['value' => json_encode($state)]);
        Http::fake(fn ($request) => Http::response($this->detail('SHUTOFF', basename(parse_url($request->url(), PHP_URL_PATH)))));
        $result = app(ArvanTrafficCreditGuard::class)->process(1);
        $this->assertSame(1, $result['confirmed']);
        $this->assertSame(1, $result['review_required']);
        Http::assertSentCount(1);
    }

    public function test_coerced_control_types_are_reviewed_without_occupying_valid_work_limit(): void
    {
        $bad = [];
        foreach (['version' => '1', 'service_id' => '1', 'active' => 1] as $key => $value) {
            [, $row] = $this->fixture();
            $state = ArvanTrafficCreditGuard::state($row);
            $state[$key] = $key === 'service_id' ? (string) $row->id : $value;
            DB::table('settings')->where('key', ArvanTrafficCreditGuard::KEY.$row->id)->update(['value' => json_encode($state)]);
            $bad[] = $row;
        }
        [, $valid] = $this->fixture();
        Http::fake(fn ($request) => Http::response($this->detail('SHUTOFF', basename(parse_url($request->url(), PHP_URL_PATH)))));
        $result = app(ArvanTrafficCreditGuard::class)->process(1);
        $this->assertSame(1, $result['confirmed']);
        $this->assertSame(3, $result['review_required']);
        Http::assertSentCount(1);
    }

    public function test_worker_rejects_an_outer_financial_transaction_before_any_provider_call(): void
    {
        [, $service] = $this->fixture();
        try {
            DB::transaction(fn () => app(ArvanTrafficCreditGuard::class)->reconcile($service->id));
            $this->fail('Worker must run after commit');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('inside a transaction', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_single_natural_worker_schedule_and_registered_command_report_counts(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(),
            fn ($event) => str_contains((string) $event->command, 'cloud:traffic-credit-guard')));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(0, Artisan::call('cloud:traffic-credit-guard'));
        $this->assertStringContainsString('"confirmed":0', Artisan::output());
        $this->assertNotNull(Setting::get('arvan_traffic_credit_guard_progress'));
        Http::assertNothingSent();
    }
}
