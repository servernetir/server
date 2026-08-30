<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\IdentityVerification;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مدیریت مشتریان در پنل ادمین.
 *
 * محورها:
 *   • فقط کارکنان (گارد web) می‌بینند؛ مهمان به ورود می‌رود
 *   • جستجو روی کد/ایمیل/موبایل/نام کار می‌کند
 *   • پروندهٔ مشتری با همهٔ روابط بی‌خطا باز می‌شود
 *   • تغییر وضعیت ثبت می‌شود
 */
class AdminCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 's'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function customer(array $over = []): Customer
    {
        return Customer::create(array_merge([
            'email' => 'c'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    public function test_guest_is_redirected_from_customers(): void
    {
        $this->get('/admin/customers')->assertRedirect('/admin/login');
    }

    public function test_staff_sees_customer_list(): void
    {
        $c = $this->customer();

        $this->actingAs($this->staff(), 'web')
            ->get('/admin/customers')
            ->assertOk()
            ->assertSee($c->code);
    }

    public function test_search_matches_verified_name(): void
    {
        $c = $this->customer();
        IdentityVerification::create([
            'customer_id' => $c->id, 'national_id_enc' => '1234567890',
            'national_id_hash' => hash('sha256', (string) $c->id),
            'first_name' => 'بهرام', 'last_name' => 'کیانی', 'birth_date' => '1370-01-01',
            'mobile' => $c->phone, 'shahkar_matched' => true, 'status' => 'verified', 'provider' => 'zohal',
        ]);

        $this->actingAs($this->staff(), 'web')
            ->get('/admin/customers?q=کیانی')
            ->assertOk()
            ->assertSee($c->code);

        // مشتری بی‌ربط نباید در نتیجه باشد
        $other = $this->customer();
        $this->actingAs($this->staff(), 'web')
            ->get('/admin/customers?q=کیانی')
            ->assertDontSee($other->code);
    }

    public function test_customer_dossier_opens_with_all_relations(): void
    {
        $c = $this->customer();
        IdentityVerification::create([
            'customer_id' => $c->id, 'national_id_enc' => '1234567890',
            'national_id_hash' => hash('sha256', (string) $c->id),
            'first_name' => 'سارا', 'last_name' => 'م', 'birth_date' => '1370-01-01',
            'mobile' => $c->phone, 'shahkar_matched' => true, 'status' => 'verified', 'provider' => 'zohal',
        ]);
        Invoice::create([
            'customer_id' => $c->id, 'number' => 'INV-9', 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 100000, 'tax' => 10000, 'total' => 110000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);
        Ticket::create([
            'customer_id' => $c->id, 'number' => 'TKT-9', 'subject' => 'س', 'department' => 'technical',
            'priority' => 'normal', 'status' => 'open', 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);

        $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}")
            ->assertOk()
            ->assertSee('سارا')
            ->assertSee('INV-9');
    }

    public function test_status_change_persists(): void
    {
        $c = $this->customer(['status' => 'active']);

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/customers/{$c->id}/status", ['status' => 'suspended'])
            ->assertRedirect();

        $this->assertSame('suspended', $c->fresh()->status);
    }

    public function test_status_rejects_invalid_value(): void
    {
        $c = $this->customer();

        $this->actingAs($this->staff(), 'web')
            ->post("/admin/customers/{$c->id}/status", ['status' => 'banana'])
            ->assertSessionHasErrors('status');
    }

    /**
     * 🔴 «احراز هویت» از نوارِ کناری برداشته شد — ولی **شمارشِ در انتظار** نه.
     *
     * ═══ چرا این تست هست ═══
     *
     * حذفِ یک آیتمِ منو ساده است؛ چیزی که با آن بی‌صدا می‌رود، تنها سیگنالِ
     * «N نفر منتظرِ تأییدند» است. اگر آن گم شود، مدیر تا شکایتِ خودِ مشتری
     * خبردار نمی‌شود — یعنی شلوغی کم شد و هشدار هم با آن رفت.
     *
     * پس ادعا دو نیمه دارد و هر دو لازم است: دکمه روی صفحهٔ مشتریان هست، و
     * عددِ در انتظار رویش دیده می‌شود.
     */
    public function test_the_verification_queue_stays_visible_from_the_customer_list(): void
    {
        $c = $this->customer();

        \App\Models\CustomerProfile::create([
            'customer_id' => $c->id,
            'type'        => 'individual',
            'status'      => 'pending',
            'email'       => $c->email,
            'mobile'      => $c->phone,
            'country'     => 'IR',
        ]);

        $html = $this->actingAs($this->staff(), 'web')
            ->get('/admin/customers')->assertOk()->getContent();

        $this->assertStringContainsString('/admin/verifications', (string) $html,
            'راهِ رسیدن به صفِ احراز هویت از فهرستِ مشتریان قطع شد.');

        // ⚠️ ادعا روی **عدد** است نه بر وجودِ دکمه: دکمهٔ بی‌شمارش یعنی صفِ پر
        //    و پنلِ ساکت.
        $this->assertMatchesRegularExpression('~/admin/verifications.*?ad-pill~s', (string) $html,
            'شمارشِ در انتظارِ احراز هویت با حذفِ آیتمِ منو ناپدید شد.');
    }
}
