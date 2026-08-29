<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * نقشِ «پشتیبان» — کارشناسی که تیکت و مشتری می‌بیند، ولی نه بیشتر.
 *
 * ═══ چرا مرزها تست دارند و فقط «کار می‌کند» کافی نیست ═══
 *
 * 🔴 نقشِ تازه دو جور خراب می‌شود و هر دو خاموش‌اند:
 *
 *   • **کم‌دسترسی**: پشتیبان ۴۰۳ می‌گیرد و نمی‌تواند کارش را بکند. این را
 *     خودش سرِ اولین تیکت می‌فهمد — پرهزینه ولی دیدنی.
 *   • **پُردسترسی**: پشتیبان به تنظیمات/مالی/رمزِ مشتری می‌رسد. این را
 *     **هیچ‌کس** نمی‌فهمد تا روزی که اتفاقِ بدی بیفتد.
 *
 * پس هر دو طرفِ مرز ادعا دارند: چه چیزی باز است، و چه چیزی حتماً بسته.
 */
class SupportRoleTest extends TestCase
{
    use RefreshDatabase;

    private function support(): User
    {
        return User::factory()->create(['role' => 'support']);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 's'.random_int(1, 999999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);
    }

    private function ticket(): Ticket
    {
        $c = $this->customer();

        $t = Ticket::create([
            'customer_id' => $c->id, 'number' => 'TK-S'.random_int(1000, 9999),
            'subject' => 'مشکلِ آزمایشی', 'department' => 'technical', 'priority' => 'normal',
            'status' => 'open', 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);

        $t->addMessage('customer', $c->id, 'مشتری', 'سلام، سایتم بالا نمی‌آید.');

        return $t;
    }

    /** ✅ کارهای پشتیبانی باز است: فهرستِ تیکت، خودِ تیکت، فهرست و پروندهٔ مشتری. */
    public function test_support_can_reach_the_support_pages(): void
    {
        $u = $this->support();
        $t = $this->ticket();

        $this->actingAs($u)->get('/admin/tickets')->assertOk();
        $this->actingAs($u)->get('/admin/tickets/'.$t->id)->assertOk();
        $this->actingAs($u)->get('/admin/customers')->assertOk();
        $this->actingAs($u)->get('/admin/customers/'.$t->customer_id)->assertOk();
    }

    /** ✅ پشتیبان واقعاً می‌تواند پاسخ بدهد — دسترسیِ فقط-خواندنی بی‌فایده است. */
    public function test_support_can_actually_reply(): void
    {
        Http::fake();
        $t = $this->ticket();

        $this->actingAs($this->support())
            ->post('/admin/tickets/'.$t->id.'/reply', ['body' => 'بررسی شد، درست است.'])
            ->assertRedirect();

        $this->assertSame('answered', $t->fresh()->status);
    }

    /**
     * 🔴 مرزِ سخت: تنظیمات، مالی، زیرساخت و کاربرانِ پنل بسته‌اند.
     *
     * هرکدام از این‌ها یا پول جابه‌جا می‌کند یا دسترسی می‌سازد.
     */
    public function test_support_is_locked_out_of_admin_only_areas(): void
    {
        $u = $this->support();

        foreach (['/admin/settings', '/admin/users', '/admin/finance', '/admin/cloud',
            '/admin/transactions', '/admin/provisioning', '/admin/servers'] as $path) {
            $this->actingAs($u)->get($path)->assertForbidden();
        }
    }

    /**
     * 🔴 حسابِ مشتری را نمی‌تواند تغییر دهد — دیدن ≠ دست‌زدن.
     *
     * ⚠️ ادعا روی **دیتابیس** است نه فقط کدِ وضعیت: یک ۴۰۳ که در عمل رمز را
     * عوض کرده باشد، بدترین حالتِ ممکن است.
     */
    public function test_support_cannot_change_a_customer_account(): void
    {
        $c = $this->customer();
        $before = $c->password;

        $this->actingAs($this->support())
            ->post('/admin/customers/'.$c->id.'/password', ['password' => 'new-secret-9999'])
            ->assertForbidden();

        $this->assertSame($before, $c->fresh()->password, 'رمزِ مشتری با وجودِ ۴۰۳ عوض شد');

        $this->actingAs($this->support())
            ->post('/admin/customers/'.$c->id.'/status', ['status' => 'suspended'])
            ->assertForbidden();

        $this->assertSame('active', $c->fresh()->status);
    }

    /** 🔴 نویسنده حتی به بخشِ پشتیبانی هم راه ندارد — نقشِ محتوا است. */
    public function test_an_author_still_cannot_see_support_pages(): void
    {
        $u = User::factory()->create(['role' => 'author']);

        $this->actingAs($u)->get('/admin/tickets')->assertForbidden();
        $this->actingAs($u)->get('/admin/customers')->assertForbidden();
    }

    /** مدیر همه‌جا هست — `isStaff()` نباید مدیر را از بخشِ پشتیبانی بیرون کند. */
    public function test_admin_keeps_full_access(): void
    {
        $u = User::factory()->create(['role' => 'admin']);

        $this->actingAs($u)->get('/admin/tickets')->assertOk();
        $this->actingAs($u)->get('/admin/customers')->assertOk();
        $this->actingAs($u)->get('/admin/settings')->assertOk();
    }

    /** منوی پشتیبان فقط بخش‌های خودش را دارد — لینکِ ۴۰۳ بدتر از نبودنش است. */
    public function test_the_sidebar_hides_what_support_cannot_open(): void
    {
        $html = $this->actingAs($this->support())->get('/admin/tickets')->assertOk()->getContent();

        $this->assertStringContainsString('/admin/tickets', $html);
        $this->assertStringContainsString('/admin/customers', $html);
        $this->assertStringNotContainsString('/admin/settings', $html, 'لینکِ تنظیمات در منوی پشتیبان است');
        $this->assertStringNotContainsString('/admin/finance', $html, 'لینکِ مالی در منوی پشتیبان است');
        $this->assertStringNotContainsString('/admin/cloud', $html);
    }

    /**
     * 🔴 داشبورد سودِ شرکت را به پشتیبان نشان نمی‌دهد.
     *
     * ⚠️ چرا این تست جداست و مهم: `/admin/finance` پشتِ گاردِ مدیر است، ولی
     * **عددش** روی داشبورد نشسته بود — و داشبورد تنها صفحه‌ای است که پشتیبان
     * هم بازش می‌کند. الگوی «در به قفل، پنجره باز»: بستنِ صفحه کافی نیست اگر
     * همان داده جای دیگری بی‌گارد رندر شود.
     */
    public function test_the_dashboard_hides_company_finances_from_support(): void
    {
        $supHtml = $this->actingAs($this->support())->get('/admin')->assertOk()->getContent();

        $this->assertStringNotContainsString('سود خالص', $supHtml, 'سودِ شرکت به پشتیبان نشان داده شد');
        $this->assertStringNotContainsString('آخرین پرداخت‌ها', $supHtml);
        $this->assertStringNotContainsString('فاکتور پرداخت‌نشده', $supHtml);

        // ...و برای مدیر سرِ جایش است — تست نباید کاشی را برای همه بکُشد
        $admHtml = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('سود خالص', $admHtml);
    }

    /** تیکت‌ها روی داشبورد برای پشتیبان می‌مانَد — کارِ خودش است. */
    public function test_the_dashboard_still_shows_tickets_to_support(): void
    {
        $html = $this->actingAs($this->support())->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('آخرین تیکت‌ها', $html);
        $this->assertStringContainsString('تیکت باز', $html);
    }

    /** ساختِ کاربر با نقشِ پشتیبان از فرمِ /admin/users. */
    public function test_an_admin_can_create_a_support_user(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/users', [
                'name' => 'کارشناس تست', 'email' => 'sup'.random_int(1, 9999).'@example.com',
                'role' => 'support', 'password' => 'secret-pass-123',
            ])->assertRedirect();

        $this->assertDatabaseHas('users', ['name' => 'کارشناس تست', 'role' => 'support']);
    }
}
