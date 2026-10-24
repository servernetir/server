<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCustomerActive;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\CustomerStatusChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CustomerAccountBanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'customer@example.test', 'password' => 'secret',
            'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function change(Customer $customer, string $status, string $expected = 'active', string $reason = 'بررسی تخلف حساب')
    {
        return $this->post('/admin/customers/'.$customer->id.'/status', [
            'status' => $status, 'expected_status' => $expected, 'reason' => $reason,
        ]);
    }

    public function test_ban_and_unban_keep_reason_actor_history_and_revoke_old_sessions(): void
    {
        $c = $this->customer();
        $c->forceFill(['remember_token' => 'old-remember-token'])->save();
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->change($c, 'closed')->assertSessionHasNoErrors();
        $this->assertSame('closed', $c->fresh()->status);
        $this->assertSame(1, $c->fresh()->session_version);
        $this->assertNotSame('old-remember-token', $c->fresh()->remember_token);
        $this->assertDatabaseHas('customer_status_changes', [
            'customer_id' => $c->id, 'actor_id' => $admin->id,
            'from_status' => 'active', 'to_status' => 'closed', 'reason' => 'بررسی تخلف حساب',
        ]);
        $this->change($c, 'active', 'closed', 'رفع تخلف پس از بررسی')->assertSessionHasNoErrors();
        $this->assertSame(2, $c->fresh()->session_version);
        $this->assertCount(2, $c->statusChanges);
        $this->assertTrue($c->fresh()->isActive());
    }

    public function test_empty_reason_and_stale_form_cannot_change_the_account(): void
    {
        $c = $this->customer();
        $this->actingAs($this->admin());
        foreach (['', '   ', 'ab', str_repeat('x', 2001)] as $reason) {
            $this->change($c, 'closed', 'active', $reason)->assertSessionHasErrors('reason');
        }
        $this->change($c, 'closed', 'suspended')->assertSessionHasErrors('status');
        $this->assertSame('active', $c->fresh()->status);
        $this->assertDatabaseCount('customer_status_changes', 0);
    }

    public function test_support_author_customer_and_guest_cannot_ban(): void
    {
        $c = $this->customer();
        foreach (['support', 'author'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->change($c, 'closed')->assertForbidden();
        }
        Auth::guard('web')->logout();
        $this->actingAs($c, 'customer');
        $this->change($c, 'closed')->assertRedirect('/admin/login');
        $this->assertSame('active', $c->fresh()->status);
    }

    public function test_same_status_is_idempotent_and_does_not_create_fake_history(): void
    {
        $c = $this->customer();
        $this->actingAs($this->admin());
        $this->change($c, 'active')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('customer_status_changes', 0);
        $this->assertSame(0, $c->fresh()->session_version);
    }

    public function test_banned_existing_session_is_logged_out_before_account_actions(): void
    {
        $c = $this->customer();
        $this->actingAs($c, 'customer');
        $c->forceFill(['status' => 'closed', 'session_version' => 1])->save();
        $this->get('/account')->assertRedirect('/login')->assertSessionHasErrors('identifier');
        $this->assertGuest('customer');
        $this->assertDatabaseCount('customer_status_changes', 0);
    }

    public function test_ban_followed_by_unban_never_revives_an_old_session(): void
    {
        $c = $this->customer();
        $this->actingAs($c, 'customer')->withSession([EnsureCustomerActive::SESSION_KEY => 0]);
        $c->forceFill(['session_version' => 2])->save();
        $this->getJson('/account')->assertForbidden()->assertJsonPath('error', 'session_revoked');
        $this->assertGuest('customer');
    }

    public function test_fresh_login_records_current_version_for_every_customer_login(): void
    {
        $c = $this->customer();
        $c->forceFill(['session_version' => 3])->save();
        $this->get('/login');
        Auth::guard('customer')->login($c);
        $this->assertSame(3, session(EnsureCustomerActive::SESSION_KEY));
    }

    public function test_customer_logout_does_not_destroy_staff_session(): void
    {
        $admin = $this->admin();
        $c = $this->customer();
        $this->actingAs($admin, 'web')->actingAs($c, 'customer');
        $c->forceFill(['status' => 'suspended'])->save();
        $this->get('/account')->assertRedirect('/login');
        $this->assertAuthenticatedAs($admin, 'web');
        $this->assertGuest('customer');
    }

    public function test_bearer_tokens_cannot_access_banned_accounts(): void
    {
        $c = $this->customer();
        [, $plain] = CustomerApiToken::issue($c->id, 'test');
        $c->forceFill(['status' => 'closed'])->save();
        Route::middleware(\App\Http\Middleware\CustomerApiToken::class)
            ->get('/api/ban-probe', fn () => response()->json(['allowed' => true]));
        $this->withToken($plain)->getJson('/api/ban-probe')
            ->assertForbidden()->assertJsonPath('error', 'account_inactive');
    }

    public function test_every_localized_account_route_has_the_active_account_guard(): void
    {
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            if (in_array('auth:customer', $route->gatherMiddleware(), true)) {
                $this->assertContains(EnsureCustomerActive::class, $route->gatherMiddleware(), $route->uri());
                $count++;
            }
        }
        $this->assertGreaterThan(50, $count);
    }

    public function test_blocked_sessions_get_localized_errors_and_login_urls(): void
    {
        foreach (['' => 'fa', 'en/' => 'en', 'tr/' => 'tr'] as $prefix => $locale) {
            $c = $this->customer();
            $this->actingAs($c, 'customer');
            $c->forceFill(['status' => 'closed'])->save();
            $this->get('/'.$prefix.'account')
                ->assertRedirect('/'.$prefix.'login')
                ->assertSessionHasErrors('identifier');
            $c->delete();
        }
    }

    public function test_history_renders_escaped_for_staff_and_form_is_admin_only(): void
    {
        $c = $this->customer();
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->change($c, 'suspended', 'active', '<script>alert(1)</script>')->assertSessionHasNoErrors();
        $this->get('/admin/customers/'.$c->id)->assertOk()
            ->assertSee('مسدودسازی و رفع مسدودی حساب')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('name="reason"', false);
        $this->actingAs(User::factory()->create(['role' => 'support']));
        $this->get('/admin/customers/'.$c->id)->assertOk()
            ->assertDontSee('name="expected_status"', false);
        $this->assertSame(0, ActivityLog::where('customer_id', $c->id)->visibleToCustomer()->where('action', 'account_status')->count());
    }

    public function test_status_change_rolls_back_if_required_audit_write_fails(): void
    {
        $c = $this->customer();
        $this->actingAs($this->admin());
        CustomerStatusChange::creating(fn () => throw new \RuntimeException('Audit storage unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->change($c, 'closed');
            $this->fail('An account change without an audit must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit storage unavailable', $e->getMessage());
        } finally {
            CustomerStatusChange::flushEventListeners();
        }
        $this->assertSame('active', $c->fresh()->status);
        $this->assertSame(0, $c->fresh()->session_version);
        $this->assertDatabaseCount('customer_status_changes', 0);
    }
}
