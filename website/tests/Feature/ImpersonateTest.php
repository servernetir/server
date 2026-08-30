<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ImpersonateController;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * «جای مشتری نشستنِ» مدیر — حساس‌ترین قابلیتِ پنل مدیریت.
 *
 * چون با آن می‌شود بدونِ رمز وارد حسابِ هر مشتری شد، مرزهایش باید تست داشته
 * باشد: فقط مدیر، همیشه لاگ، و راهِ بازگشت.
 */
class ImpersonateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function author(): User
    {
        return User::create([
            'name' => 'نویسنده', 'email' => 'w'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'author',
        ]);
    }

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'email' => 'c'.random_int(1, 99999).'@x.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    public function test_admin_can_enter_customer_panel(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/customers/{$customer->id}/impersonate")
            ->assertRedirect();

        $this->assertTrue(Auth::guard('customer')->check());
        $this->assertSame($customer->id, Auth::guard('customer')->id());
    }

    /** نویسنده (نقشِ غیرمدیر) حق ندارد */
    public function test_non_admin_staff_cannot_impersonate(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->author(), 'web')
            ->post("/admin/customers/{$customer->id}/impersonate")
            ->assertForbidden();

        $this->assertFalse(Auth::guard('customer')->check());
    }

    /** مهمان به‌هیچ‌وجه */
    public function test_guest_cannot_impersonate(): void
    {
        $customer = $this->customer();

        $this->post("/admin/customers/{$customer->id}/impersonate")->assertRedirect();
        $this->assertFalse(Auth::guard('customer')->check());
    }

    /** حسابِ بسته قابلِ ورود نیست */
    public function test_closed_account_cannot_be_impersonated(): void
    {
        $customer = $this->customer(['status' => 'closed']);

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/customers/{$customer->id}/impersonate")
            ->assertSessionHasErrors();

        $this->assertFalse(Auth::guard('customer')->check());
    }

    /** هر ورودی باید ردِ ممیزی بگذارد */
    public function test_impersonation_is_logged(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/customers/{$customer->id}/impersonate");

        $log = ActivityLog::where('customer_id', $customer->id)->where('action', 'impersonate')->first();

        $this->assertNotNull($log, 'ورودِ مدیر به پنلِ مشتری لاگ نشد');
        $this->assertSame('staff', $log->actor);
    }

    /** بازگشت: نشستِ مشتری بسته می‌شود و کلیدِ نشست پاک */
    public function test_stopping_logs_the_customer_out(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $this->actingAs($admin, 'web')->post("/admin/customers/{$customer->id}/impersonate");
        $this->assertTrue(Auth::guard('customer')->check());

        $this->post('/admin/impersonate/stop')->assertRedirect();

        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertNull(session(ImpersonateController::SESSION_KEY));
    }
}
