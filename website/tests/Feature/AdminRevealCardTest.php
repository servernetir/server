<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نمایشِ یک‌بارهٔ شمارهٔ کاملِ کارت در پنلِ مدیر.
 *
 * ═══ چرا لازم شد ═══
 *
 * PANِ کامل در `card_number_enc` هست ولی هیچ‌جای رابط نشان داده نمی‌شد. مدیر
 * برای عودتِ وجه شماره را نداشت و از مشتری خواست دوباره بفرستد — یعنی دادهٔ
 * حساس از کانالِ ناامن رد شد، دقیقاً برعکسِ چیزی که رمزنگاری برای آن بود.
 *
 * ⚠️ و مهم‌تر: عودتِ وجه معمولاً با **شبا** انجام می‌شود که از قبل روی همان
 * صفحه هست. این دکمه استثناست، نه مسیرِ اصلی.
 */
class AdminRevealCardTest extends TestCase
{
    use RefreshDatabase;

    private const PAN = '6037991234567890';

    private function customerWithCard(): Customer
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'rc'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active', 'locale' => 'fa',
        ]);

        BankAccount::create([
            'customer_id' => $c->id,
            'card_bin' => '603799', 'card_last4' => '7890',
            'card_number_enc' => self::PAN,
            'bank_name' => 'ملی', 'iban' => 'IR'.random_int(100000000, 999999999).random_int(100000000, 999999999),
            'owner_name' => 'مهرداد نوربخش', 'name_matched' => true,
            'status' => 'verified', 'is_default' => true, 'verified_at' => now(),
        ]);

        return $c;
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'ad'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'admin',
        ]);
    }

    private function url(Customer $c): string
    {
        return '/admin/customers/'.$c->id.'/bank/'.$c->bankAccounts->first()->id.'/reveal-card';
    }

    // ───────────────────────── ادعاها ─────────────────────────

    /** 🔴 شماره باید واقعاً برگردد — رمزگشایی باید کار کند، نه فقط ستون باشد */
    public function test_an_admin_sees_the_full_number_once(): void
    {
        $c = $this->customerWithCard();

        $this->actingAs($this->admin())
            ->post($this->url($c))
            ->assertRedirect()
            ->assertSessionHas('ok', fn ($m) => str_contains((string) $m, self::PAN));
    }

    /**
     * 🔴 دیدنِ PAN باید ردِ حسابرسی بگذارد.
     *
     * بی‌این، هیچ‌کس نمی‌داند چه کسی و کِی شمارهٔ کارتِ مشتری را دیده — و
     * همین تفاوتِ «دسترسیِ کنترل‌شده» با «ستونِ باز» است.
     */
    public function test_every_reveal_is_written_to_the_activity_log(): void
    {
        $c = $this->customerWithCard();

        $this->actingAs($this->admin())->post($this->url($c));

        $this->assertTrue(
            ActivityLog::where('customer_id', $c->id)->where('action', 'card_revealed')->exists(),
            'نمایشِ شمارهٔ کارت باید در لاگِ فعالیت ثبت شود.'
        );
    }

    /** ⚠️ خودِ شماره هرگز در متنِ لاگ نمی‌نشیند — وگرنه لاگ خودش نشتی می‌شود */
    public function test_the_log_line_never_contains_the_number_itself(): void
    {
        $c = $this->customerWithCard();

        $this->actingAs($this->admin())->post($this->url($c));

        $row = ActivityLog::where('customer_id', $c->id)->where('action', 'card_revealed')->first();

        $this->assertStringNotContainsString(self::PAN, (string) $row?->description);
        $this->assertStringContainsString('7890', (string) $row?->description);
    }

    /** 🔴 حسابِ مشتریِ دیگر از این مسیر خوانده نمی‌شود */
    public function test_an_account_of_another_customer_is_refused(): void
    {
        $a = $this->customerWithCard();
        $b = $this->customerWithCard();

        $this->actingAs($this->admin())
            ->post('/admin/customers/'.$a->id.'/bank/'.$b->bankAccounts->first()->id.'/reveal-card')
            ->assertRedirect()
            ->assertSessionHas('err');
    }

    /** کاربرِ غیرِمدیرِ پنل اجازه ندارد */
    public function test_a_non_admin_panel_user_cannot_reveal(): void
    {
        $c = $this->customerWithCard();

        $staff = User::create([
            'name' => 'پشتیبان', 'email' => 'st'.random_int(1000, 9999).'@example.test',
            'password' => bcrypt('secret-for-test'), 'role' => 'support',
        ]);

        $this->actingAs($staff)->post($this->url($c));

        // سنجه، **اثر** است نه کدِ وضعیت: هرچه میدل‌ور بکند، نباید شماره‌ای
        // دیده شده باشد — و تنها ردِ دیده‌شدن همین ردیفِ لاگ است.
        $this->assertFalse(
            ActivityLog::where('customer_id', $c->id)->where('action', 'card_revealed')->exists(),
            'کاربرِ غیرِمدیر نباید بتواند شماره را ببیند.'
        );
    }
}
