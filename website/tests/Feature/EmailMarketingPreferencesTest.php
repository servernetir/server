<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ImpersonateController;
use App\Models\Customer;
use App\Models\EmailMarketingPreference;
use App\Models\User;
use App\Services\Marketing\EmailMarketingPreferences;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailMarketingPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'app.previous_keys' => []]);
        Mail::fake();
        Http::preventStrayRequests();
    }

    private function service(): EmailMarketingPreferences
    {
        return app(EmailMarketingPreferences::class);
    }

    private function customer(string $email, bool $verified = true, string $status = 'active'): Customer
    {
        $customer = Customer::create(['email' => $email, 'status' => $status, 'locale' => 'fa']);
        if ($verified) {
            $customer->forceFill(['email_verified_at' => now()->subDay()])->save();
        }

        return $customer;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web');

        return $admin;
    }

    private function asCustomer(Customer $customer): void
    {
        Auth::guard('web')->logout();
        $this->actingAs($customer, 'customer');
    }

    private function reject(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The preference operation should have been denied.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }

    private function events(EmailMarketingPreference $preference, string $event): int
    {
        return DB::table('email_marketing_preference_events')->where('email_marketing_preference_id', $preference->id)
            ->where('event', $event)->count();
    }

    public function test_admin_stage_is_pending_idempotent_and_recipient_grant_is_explicit(): void
    {
        $customer = $this->customer('recipient@example.test');
        $admin = $this->admin();
        $row = $this->service()->stage($customer, $admin->id);
        $this->assertSame(EmailMarketingPreference::STATE_PENDING, $row->state);
        $this->assertNull($row->consented_at);
        $this->assertFalse($this->service()->eligible($customer));
        $this->assertSame($row->id, $this->service()->stage($customer, $admin->id)->id);
        $this->assertSame(1, $this->events($row, 'create'));
        $this->assertSame(1, $this->events($row, 'stage'));
        $this->assertStringNotContainsString($customer->email, json_encode($row->toArray()));

        $this->asCustomer($customer);
        $granted = $this->service()->setConsent($customer, true);
        $this->assertSame($row->id, $granted->id);
        $this->assertSame(EmailMarketingPreference::STATE_GRANTED, $granted->state);
        $this->assertNotNull($granted->consented_at);
        $this->assertSame(EmailMarketingPreferences::CONSENT_VERSION, $granted->consent_version);
        $this->assertTrue($this->service()->eligible($customer));
        $this->assertSame([$row->id => true], $this->service()->eligibilityMap([$row]));
        $this->service()->setConsent($customer, true);
        $this->assertSame(1, $this->events($row, 'opt_in'));
        Mail::assertNothingSent();
    }

    public function test_unknown_or_unverified_ownership_and_nonrecipient_grants_fail_closed(): void
    {
        $unverified = $this->customer('unverified@example.test', false);
        $inactive = $this->customer('inactive@example.test', true, 'closed');
        $other = $this->customer('other@example.test');
        $admin = $this->admin();
        $this->reject(fn () => $this->service()->stage($unverified, $admin->id));
        $this->reject(fn () => $this->service()->stage($inactive, $admin->id));
        $this->asCustomer($unverified);
        $this->reject(fn () => $this->service()->setConsent($unverified, true));
        $this->reject(fn () => $this->service()->setConsent($other, true));
        $this->reject(fn () => $this->service()->setConsent($other, false));
        $this->assertDatabaseCount('email_marketing_preferences', 0);
        $this->assertDatabaseCount('email_marketing_preference_events', 0);
        Mail::assertNothingSent();
    }

    public function test_staff_impersonation_never_grants_consent(): void
    {
        $customer = $this->customer('impersonation@example.test');
        $admin = $this->admin();
        $this->actingAs($customer, 'customer');
        $this->reject(fn () => $this->service()->setConsent($customer, true));
        $this->reject(fn () => $this->service()->setConsent($customer, false));
        Auth::guard('web')->logout();
        request()->setLaravelSession(app('session.store'));
        request()->session()->put(ImpersonateController::SESSION_KEY, $admin->id);
        $this->reject(fn () => $this->service()->setConsent($customer, true));
        $this->assertDatabaseCount('email_marketing_preferences', 0);
    }

    public function test_account_withdrawal_is_idempotent_and_import_cannot_restore_it(): void
    {
        $customer = $this->customer('withdraw@example.test');
        $this->asCustomer($customer);
        $row = $this->service()->setConsent($customer, true);
        $customer->forceFill(['email_verified_at' => null])->save();
        $this->service()->setConsent($customer, false);
        $this->service()->setConsent($customer, false);
        $this->assertSame(EmailMarketingPreference::STATE_WITHDRAWN, $row->fresh()->state);
        $this->assertNotNull($row->fresh()->withdrawn_at);
        $this->assertSame(1, $this->events($row, 'opt_out'));
        $customer->forceFill(['email_verified_at' => now()])->save();
        $this->reject(fn () => $this->service()->setConsent($customer, true));
        $admin = $this->admin();
        $stagedAgain = $this->service()->stage($customer, $admin->id);
        $this->assertSame(EmailMarketingPreference::STATE_WITHDRAWN, $stagedAgain->state);
        $this->assertFalse($this->service()->eligible($customer));
        $this->assertSame(1, $this->events($row, 'opt_in'));
        Mail::assertNothingSent();
    }

    public function test_bearer_token_only_withdraws_its_address_and_is_idempotent(): void
    {
        $first = $this->customer('first@example.test');
        $second = $this->customer('second@example.test');
        $this->asCustomer($first);
        $one = $this->service()->setConsent($first, true);
        $this->asCustomer($second);
        $two = $this->service()->setConsent($second, true);
        $token = $this->service()->unsubscribeToken($one);
        $this->assertStringNotContainsString($first->email, $token);
        [$id, $mac] = explode('.', $token);
        $forged = substr($mac, 0, -1).(substr($mac, -1) === 'a' ? 'b' : 'a');
        Auth::guard('customer')->logout();
        $this->assertFalse($this->service()->unsubscribeByToken($id.'.'.$forged));
        $this->assertFalse($this->service()->unsubscribeByToken($two->public_id.'.'.$mac));
        $this->assertSame(EmailMarketingPreference::STATE_GRANTED, $one->fresh()->state);
        $this->assertTrue($this->service()->unsubscribeByToken($token));
        $this->assertTrue($this->service()->unsubscribeByToken($token));
        $this->assertSame(1, $this->events($one, 'opt_out'));
        $this->assertFalse($this->service()->eligible($first));
        $this->assertTrue($this->service()->eligible($second));
        Mail::assertNothingSent();
    }

    public function test_admin_can_only_suppress_and_cannot_remove_suppression(): void
    {
        $customer = $this->customer('suppression@example.test');
        $this->asCustomer($customer);
        $row = $this->service()->setConsent($customer, true);
        $admin = $this->admin();
        $this->service()->suppress($row, $admin->id);
        $this->service()->suppress($row, $admin->id);
        $this->assertSame(1, $this->events($row, 'suppress'));
        $this->assertNotNull($row->fresh()->suppressed_at);
        $this->assertFalse($this->service()->eligible($customer));
        $this->asCustomer($customer);
        $this->reject(fn () => $this->service()->setConsent($customer, true));
        $this->reject(fn () => $row->fresh()->forceFill(['suppressed_at' => null])->save());
        $this->service()->setConsent($customer, false);
        $this->assertSame(EmailMarketingPreference::STATE_WITHDRAWN, $row->fresh()->state);
        $this->assertNotNull($row->fresh()->suppressed_at);
        Mail::assertNothingSent();
    }

    public function test_duplicate_normalized_ownership_and_reassignment_fail_closed(): void
    {
        $customer = $this->customer('identity@example.test');
        $this->asCustomer($customer);
        $row = $this->service()->setConsent($customer, true);
        $duplicate = $this->customer(' identity@example.test');
        $this->assertFalse($this->service()->eligible($customer));
        $this->assertFalse($this->service()->eligible($duplicate));
        $this->asCustomer($duplicate);
        $this->reject(fn () => $this->service()->setConsent($duplicate, true));
        $customer->forceFill(['email' => 'changed@example.test', 'email_verified_at' => now()])->save();
        $this->assertFalse($this->service()->eligible($customer));
        $this->assertFalse($this->service()->eligible($duplicate));
        $this->reject(fn () => $this->service()->setConsent($duplicate, true));
        $this->assertSame(1, EmailMarketingPreference::query()->count());
        $this->assertSame([$row->id => false], $this->service()->eligibilityMap([$row]));
        Mail::assertNothingSent();
    }

    public function test_legacy_suppression_blocks_explicit_grant_and_existing_eligibility(): void
    {
        $customer = $this->customer('legacy@example.test');
        DB::table('crm_suppression')->insert(['email' => 'LEGACY@example.test', 'domain' => null, 'reason' => 'unsubscribe', 'created_at' => now(), 'updated_at' => now()]);
        $this->asCustomer($customer);
        $this->reject(fn () => $this->service()->setConsent($customer, true));
        $this->assertDatabaseCount('email_marketing_preferences', 0);

        $another = $this->customer('another@second.example.test');
        $this->asCustomer($another);
        $row = $this->service()->setConsent($another, true);
        $this->assertTrue($this->service()->eligible($another));
        DB::table('crm_suppression')->insert(['email' => 'unrelated@second.example.test', 'domain' => 'second.example.test', 'reason' => 'unsubscribe', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($this->service()->eligible($another));
        $this->assertSame([$row->id => false], $this->service()->eligibilityMap([$row]));
        Mail::assertNothingSent();
    }

    public function test_key_rotation_preserves_withdrawal_and_missing_history_disallows_new_grants(): void
    {
        // These are synthetic isolated test values, never production signing material.
        $oldKey = 'base64:'.base64_encode(str_repeat('o', 32));
        $nextKey = 'base64:'.base64_encode(str_repeat('n', 32));
        config(['app.key' => $oldKey, 'app.previous_keys' => []]);
        $customer = $this->customer('rotation@example.test');
        $this->asCustomer($customer);
        $row = $this->service()->setConsent($customer, true);
        $oldToken = $this->service()->unsubscribeToken($row);
        config(['app.key' => $nextKey, 'app.previous_keys' => [$oldKey]]);
        $this->assertTrue($this->service()->eligible($customer));
        $this->assertTrue($this->service()->unsubscribeByToken($oldToken));
        $this->reject(fn () => $this->service()->setConsent($customer, true));

        config(['app.previous_keys' => []]);
        $this->assertFalse($this->service()->eligible($customer));
        $this->reject(fn () => $this->service()->setConsent($customer, true));
        $newCustomer = $this->customer('fresh@example.test');
        $this->asCustomer($newCustomer);
        $this->reject(fn () => $this->service()->setConsent($newCustomer, true));
        $this->assertDatabaseCount('email_marketing_preferences', 1);
        Mail::assertNothingSent();
    }
}
