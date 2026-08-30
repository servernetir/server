<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 بستنِ دستیِ سفارشی که در «آزادسازی» گیر کرده.
 *
 * ═══ چرا این پرونده وجود دارد ═══
 *
 * متدِ `resolveRelease()` روی **سرور** بود و در مخزن نبود — و بدتر: هیچ روت و
 * هیچ دکمه‌ای نداشت، نه در گیت نه روی سرور. یعنی یک متدِ کامل و خوش‌نوشته که
 * هیچ‌کس نمی‌توانست صدایش بزند: کدِ مرده.
 *
 * ⚠️ و کدِ مرده این‌جا از نبودِ کد **بدتر** بود: داکبلاکش می‌گفت مشکل حل شده
 * («تا امروز هیچ راهی برای بستنش نبود» ⇒ حالا هست)، در حالی که پیامِ ساعتیِ
 * `cloud:release-retry` همچنان تکرار می‌شد. کسی که کد را می‌خواند فکر می‌کرد
 * راه‌حل دارد.
 *
 * حالا هم به گیت برگشت (بایت‌به‌بایت، ۵۲ خط)، هم روت و دکمه گرفت، هم تست.
 */
class ResolveStuckReleaseTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function service(array $attrs = []): Service
    {
        $c = Customer::create([
            'email' => 'rr'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create($attrs + [
            'customer_id' => $c->id, 'name' => 'سرور مجازی', 'price' => 500000,
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'cancelled',
            'provision_status' => Service::PROVISION_RELEASING,
        ]);
    }

    /** صف بسته می‌شود و ردِ حسابرسی می‌مانَد. */
    public function test_the_admin_can_close_a_stuck_release(): void
    {
        $s = $this->service();

        $this->actingAs($this->admin())
            ->post('/admin/services/'.$s->id.'/resolve-release')
            ->assertRedirect();

        $this->assertSame(Service::PROVISION_NONE, $s->fresh()->provision_status,
            'صفِ تلاشِ دوبارهٔ حذف بسته نشد — پیامِ ساعتی تا ابد تکرار می‌شود');
    }

    /**
     * 🔴 روی سرویسی که `releasing` نیست هیچ اثری ندارد.
     *
     * بی‌این شرط، یک POSTِ دست‌ساز می‌توانست پروندهٔ سالمی را ببندد.
     */
    public function test_a_healthy_service_is_untouched(): void
    {
        $s = $this->service(['status' => 'active', 'provision_status' => 'done']);

        $this->actingAs($this->admin())
            ->post('/admin/services/'.$s->id.'/resolve-release');

        $this->assertSame('done', $s->fresh()->provision_status);
    }

    /**
     * ⚠️ ظرفیتِ سرور آزاد می‌شود — ولی فقط اگر واقعاً شمرده شده بود.
     *
     * مسیرِ ناموفقِ `releaseServer()` هرگز به decrement نرسیده، پس اسلات هنوز
     * «پر» است و بی‌این، آن سرور برای همیشه از صفحهٔ خرید غیب می‌شد.
     */
    public function test_it_frees_the_server_slot_when_the_account_was_counted(): void
    {
        $server = Server::create([
            'name' => 'rr1', 'type' => 'whm', 'hostname' => 'whm.test',
            'username' => 'root', 'api_token' => 'x', 'is_active' => true,
            'active_accounts' => 3,
        ]);

        $s = $this->service([
            'server_id' => $server->id,
            'provision_meta' => ['released_from_done' => true, 'counted' => true],
        ]);

        $this->actingAs($this->admin())->post('/admin/services/'.$s->id.'/resolve-release');

        $this->assertSame(2, (int) $server->fresh()->active_accounts);
    }

    /** و اگر شمرده نشده بود، شمارنده دست نمی‌خورد — وگرنه دوبار کم می‌شد. */
    public function test_an_uncounted_account_does_not_move_the_counter(): void
    {
        $server = Server::create([
            'name' => 'rr2', 'type' => 'whm', 'hostname' => 'whm2.test',
            'username' => 'root', 'api_token' => 'x', 'is_active' => true,
            'active_accounts' => 3,
        ]);

        $s = $this->service(['server_id' => $server->id, 'provision_meta' => []]);

        $this->actingAs($this->admin())->post('/admin/services/'.$s->id.'/resolve-release');

        $this->assertSame(3, (int) $server->fresh()->active_accounts);
    }

    /** ⚠️ و فقط مدیر — این عمل یک ردیفِ مالی/عملیاتی را می‌بندد. */
    public function test_a_stranger_cannot_close_it(): void
    {
        $s = $this->service();

        $this->post('/admin/services/'.$s->id.'/resolve-release');

        $this->assertSame(Service::PROVISION_RELEASING, $s->fresh()->provision_status);
    }

    /**
     * 🔴 و مهم‌ترین ادعا: دکمه واقعاً روی صفحه هست.
     *
     * متد بی‌روت و بی‌دکمه، دقیقاً همان چیزی بود که این پرونده برای رفعش نوشته
     * شد. تستی که فقط متد را صدا بزند، همان کدِ مرده را سبز نگه می‌داشت.
     */
    public function test_the_button_is_actually_on_the_page(): void
    {
        $s = $this->service();

        $html = $this->actingAs($this->admin())
            ->get('/admin/customers/'.$s->customer_id)->assertOk()->getContent();

        $this->assertStringContainsString('/resolve-release', $html,
            'دکمه روی صفحه نیست — متد دوباره کدِ مرده است');
    }
}
