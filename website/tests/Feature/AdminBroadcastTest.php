<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اعلان به مشتریان — یک نفر یا همه.
 *
 * محورها:
 *   • هدف‌گیری درست است (all/active/verified/one)
 *   • مشتری بدون موبایل گیرنده نمی‌شود
 *   • ارسال بدون عنوان ۵۰۰ نمی‌دهد (باگی که یک‌بار داشت)
 *   • هر ارسال یک ردیف تاریخچه با تعداد گیرنده ثبت می‌کند
 */
class AdminBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();                                    // هیچ تماس شبکه‌ای واقعی نباید برود
        \Illuminate\Support\Facades\Mail::fake();        // و هیچ SMTP واقعی هم (ارسالِ ایمیلِ اعلان)
    }

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

    public function test_broadcast_to_all_reaches_customers_by_phone_or_email(): void
    {
        $this->customer();                    // موبایل + ایمیل
        $this->customer();                    // موبایل + ایمیل
        $this->customer(['phone' => null]);   // فقط ایمیل — حالا با کانالِ ایمیل به او هم می‌رسد

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/broadcasts', ['audience' => 'all', 'body' => 'پیام همگانی'])
            ->assertRedirect();

        // هر سه گیرنده‌اند: دو نفر با پیامک/بله/ایمیل و یک نفر فقط با ایمیل
        $this->assertSame(3, Broadcast::latest('id')->first()->recipients);
    }

    public function test_broadcast_also_emails_recipients(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->customer();
        $this->customer();

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/broadcasts', ['audience' => 'all', 'title' => 'خبر', 'body' => 'متنِ اعلان'])
            ->assertRedirect();

        // به هر مشتریِ دارای ایمیل، یک ایمیلِ اعلان با قالبِ برنددار فرستاده می‌شود
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\BroadcastMail::class, 2);
    }

    public function test_broadcast_without_title_does_not_fail(): void
    {
        $this->customer();

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/broadcasts', ['audience' => 'all', 'body' => 'بدون عنوان'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull(Broadcast::latest('id')->first()->title);
    }

    public function test_verified_audience_targets_only_verified(): void
    {
        $verified = $this->customer();
        CustomerProfile::create([
            'customer_id' => $verified->id, 'type' => 'individual', 'status' => 'verified',
            'is_default' => true, 'email' => $verified->email, 'mobile' => $verified->phone, 'country' => 'IR',
        ]);
        $this->customer();   // بدون پروفایل تأییدشده

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/broadcasts', ['audience' => 'verified', 'body' => 'فقط احرازشده‌ها']);

        $this->assertSame(1, Broadcast::latest('id')->first()->recipients);
    }

    public function test_one_audience_requires_customer(): void
    {
        $this->customer();

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/broadcasts', ['audience' => 'one', 'body' => 'به یک نفر'])
            ->assertSessionHasErrors();
    }

    public function test_one_audience_targets_single_customer(): void
    {
        $target = $this->customer();
        $this->customer();

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/broadcasts', ['audience' => 'one', 'customer_id' => $target->id, 'body' => 'سلام']);

        $b = Broadcast::latest('id')->first();
        $this->assertSame('one', $b->audience);
        $this->assertSame($target->id, $b->customer_id);
        $this->assertSame(1, $b->recipients);
    }
}
