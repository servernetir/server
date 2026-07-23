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
        Http::fake();   // هیچ تماس شبکه‌ای واقعی نباید برود
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

    public function test_broadcast_to_all_excludes_customers_without_phone(): void
    {
        $this->customer();
        $this->customer();
        $this->customer(['phone' => null]);   // بدون موبایل

        $this->actingAs($this->staff(), 'web')
            ->post('/admin/broadcasts', ['audience' => 'all', 'body' => 'پیام همگانی'])
            ->assertRedirect();

        $this->assertSame(2, Broadcast::latest('id')->first()->recipients);
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
