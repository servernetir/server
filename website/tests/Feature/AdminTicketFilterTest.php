<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فیلترِ صفِ تیکت‌های مدیریت — جستجو (شماره/موضوع/مشتری)، اولویت و بخش.
 * (یافتهٔ ممیزی: صف فقط تبِ وضعیت داشت و نه جستجو/اولویت.)
 */
class AdminTicketFilterTest extends TestCase
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
            'code' => 'SN-'.random_int(100000, 999999), 'email' => 't'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999), 'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ], $over));
    }

    private function ticket(Customer $c, array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'customer_id' => $c->id, 'number' => 'TK'.random_int(100000, 999999),
            'subject' => 'موضوعِ عمومی', 'department' => 'technical', 'priority' => 'normal',
            'status' => 'open', 'last_reply_at' => now(), 'last_reply_role' => 'customer',
        ], $over));
    }

    public function test_search_matches_subject_number_and_customer(): void
    {
        $c1 = $this->customer(['code' => 'SN-FINDME', 'email' => 'buyer@x.com']);
        $c2 = $this->customer(['code' => 'SN-OTHER1']);
        $this->ticket($c1, ['number' => 'TK-PAY-1', 'subject' => 'مشکلِ درگاهِ پرداخت']);
        $this->ticket($c2, ['number' => 'TK-DNS-2', 'subject' => 'تنظیمِ رکوردِ DNS']);

        // موضوع
        $this->actingAs($this->staff(), 'web')->get('/admin/tickets?status=all&q=پرداخت')
            ->assertOk()->assertSee('مشکلِ درگاهِ پرداخت')->assertDontSee('تنظیمِ رکوردِ DNS');

        // شمارهٔ تیکت
        $this->actingAs($this->staff(), 'web')->get('/admin/tickets?status=all&q=TK-DNS-2')
            ->assertOk()->assertSee('تنظیمِ رکوردِ DNS')->assertDontSee('مشکلِ درگاهِ پرداخت');

        // کدِ مشتری
        $this->actingAs($this->staff(), 'web')->get('/admin/tickets?status=all&q=SN-FINDME')
            ->assertOk()->assertSee('مشکلِ درگاهِ پرداخت')->assertDontSee('تنظیمِ رکوردِ DNS');
    }

    public function test_priority_and_department_filters(): void
    {
        $c = $this->customer();
        $this->ticket($c, ['subject' => 'تیکتِ فوریِ من', 'priority' => 'urgent', 'department' => 'billing']);
        $this->ticket($c, ['subject' => 'تیکتِ عادیِ من', 'priority' => 'normal', 'department' => 'technical']);

        $this->actingAs($this->staff(), 'web')->get('/admin/tickets?status=all&priority=urgent')
            ->assertOk()->assertSee('تیکتِ فوریِ من')->assertDontSee('تیکتِ عادیِ من');

        $this->actingAs($this->staff(), 'web')->get('/admin/tickets?status=all&department=billing')
            ->assertOk()->assertSee('تیکتِ فوریِ من')->assertDontSee('تیکتِ عادیِ من');

        // بی‌فیلتر هر دو
        $this->actingAs($this->staff(), 'web')->get('/admin/tickets?status=all')
            ->assertOk()->assertSee('تیکتِ فوریِ من')->assertSee('تیکتِ عادیِ من');
    }
}
