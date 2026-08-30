<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «مدیر وارد پنلِ این مشتری شد» را خودِ مشتری نبیند.
 *
 * 🔴 آن رویدادِ ماست، نه او. نشان دادنش هیچ سودی برای مشتری ندارد و دو زیان
 * دارد: نگرانی («چرا وارد حسابم شدند؟») و افشای الگوی کارِ داخلی.
 *
 * ⚠️ ولی کنشِ کارمندیِ مربوط به **خودِ مشتری** باید بماند: «مدیر رسیدِ واریزت
 * را تأیید کرد» دقیقاً همان چیزی است که باید ببیند. پس فیلتر روی `action` است
 * نه روی `actor` — همین تفاوت را این تست قفل می‌کند.
 */
class ImpersonationLogIsStaffOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'),
            'status' => 'active',
        ]);
    }

    /** 🔴 ردیفِ ورودِ مدیر در پنلِ مشتری دیده نمی‌شود. */
    public function test_the_customer_never_sees_the_impersonation_entry(): void
    {
        $c = $this->customer();

        ActivityLog::record($c->id, 'impersonate', 'مدیر «احسان» وارد پنلِ این مشتری شد', null, 'staff');

        $html = $this->actingAs($c, 'customer')->get('/account')->assertOk()->getContent();

        $this->assertStringNotContainsString('وارد پنلِ این مشتری', $html,
            'لاگِ ورودِ مدیر به مشتری نشان داده شد');
    }

    /**
     * ⚠️ و کنشِ کارمندیِ **مفید** همچنان دیده می‌شود.
     *
     * اگر فیلتر روی `actor='staff'` بود، این هم بلعیده می‌شد — و مشتری دیگر
     * نمی‌فهمید رسیدش تأیید شده. آن یک رگرسیونِ بی‌صدا بود.
     */
    public function test_a_useful_staff_action_is_still_visible(): void
    {
        $c = $this->customer();

        ActivityLog::record($c->id, 'bank_receipt', 'رسیدِ واریزِ شما تأیید شد', null, 'staff');

        $this->actingAs($c, 'customer')->get('/account')->assertOk()
            ->assertSee('رسیدِ واریزِ شما تأیید شد', false);
    }

    /** 🔴 و تیمِ مدیریت همچنان می‌بیندش — پنهان‌سازی فقط سمتِ مشتری است. */
    public function test_the_admin_still_sees_it(): void
    {
        $c = $this->customer();

        ActivityLog::record($c->id, 'impersonate', 'مدیر «احسان» وارد پنلِ این مشتری شد', null, 'staff');

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/customers/'.$c->id)->assertOk()
            ->assertSee('وارد پنلِ این مشتری', false);
    }

    /**
     * ⚠️ قاعده در **اسکوپ** است نه در کنترلر.
     *
     * نقطهٔ نمایشِ دوم روزی اضافه می‌شود؛ اگر شرط درون‌خطی بود همان‌جا جا
     * می‌افتاد و نشتی‌ای که هیچ خطایی نمی‌سازد ماه‌ها دیده نمی‌شد.
     */
    public function test_the_rule_lives_in_a_reusable_scope(): void
    {
        $c = $this->customer();

        ActivityLog::record($c->id, 'impersonate', 'مدیر وارد شد', null, 'staff');
        ActivityLog::record($c->id, 'password', 'رمز عبور عوض شد', null, 'customer');

        $rows = ActivityLog::where('customer_id', $c->id)->visibleToCustomer()->get();

        $this->assertCount(1, $rows);
        $this->assertSame('password', $rows->first()->action);
    }
}
