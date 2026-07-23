<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تیکت پشتیبانی.
 *
 * محورها:
 *   • مشتری فقط تیکت خودش را می‌بیند (۴۰۴ نه ۴۰۳)
 *   • جریان وضعیت درست است: مشتری→open، کارکنان→answered
 *   • یادداشت داخلی هرگز به مشتری نشان داده نمی‌شود
 */
class TicketTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 't'.random_int(1, 99999).'@example.com',
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'کارمند', 'email' => 's'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function openTicket(Customer $c, string $body = 'مشکل من این است'): Ticket
    {
        $t = $c->tickets()->create([
            'subject' => 'تست', 'department' => 'technical', 'priority' => 'normal',
            'status' => 'open', 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);
        $t->addMessage('customer', $c->id, $c->displayName(), $body);

        return $t;
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_a_customer_can_open_a_ticket(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/tickets', [
            'subject' => 'سرورم بالا نمی‌آید', 'department' => 'technical',
            'priority' => 'high', 'body' => 'بعد از ری‌استارت روشن نشد',
        ])->assertRedirect();

        $t = Ticket::where('customer_id', $c->id)->firstOrFail();
        $this->assertSame('open', $t->status);
        $this->assertSame('high', $t->priority);
        $this->assertSame(1, $t->messages()->count());
        $this->assertStringStartsWith('TK-', $t->number);
    }

    public function test_a_staff_reply_moves_the_ticket_to_answered(): void
    {
        $c = $this->customer();
        $t = $this->openTicket($c);

        $t->addMessage('staff', $this->staff()->id, 'پشتیبان', 'لطفاً لاگ را بفرستید');

        $this->assertSame('answered', $t->fresh()->status);
        $this->assertSame('staff', $t->fresh()->last_reply_role);
    }

    public function test_a_customer_reply_reopens_it(): void
    {
        $c = $this->customer();
        $t = $this->openTicket($c);
        $t->addMessage('staff', $this->staff()->id, 'پشتیبان', 'پاسخ');
        $this->assertSame('answered', $t->fresh()->status);

        $this->actingAs($c, 'customer')->post("/account/tickets/{$t->id}/reply", [
            'body' => 'باز هم مشکل دارم',
        ])->assertRedirect();

        $this->assertSame('open', $t->fresh()->status);
    }

    public function test_replying_to_a_closed_ticket_reopens_it(): void
    {
        $c = $this->customer();
        $t = $this->openTicket($c);
        $t->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        $this->actingAs($c, 'customer')->post("/account/tickets/{$t->id}/reply", [
            'body' => 'دوباره پیش آمد',
        ])->assertRedirect();

        $this->assertSame('open', $t->fresh()->status);
        $this->assertNull($t->fresh()->closed_at);
    }

    public function test_a_customer_cannot_see_another_customers_ticket(): void
    {
        $mine  = $this->customer();
        $other = $this->customer();
        $t = $this->openTicket($other);

        // ۴۰۴ و نه ۴۰۳ — وگرنه وجودش تأیید می‌شود
        $this->actingAs($mine, 'customer')->get("/account/tickets/{$t->id}")->assertNotFound();
        $this->actingAs($mine, 'customer')->post("/account/tickets/{$t->id}/reply", ['body' => 'نفوذ'])->assertNotFound();
    }

    /** ⚠ یادداشت داخلی هرگز نباید به مشتری برسد */
    public function test_internal_notes_are_hidden_from_the_customer(): void
    {
        $c = $this->customer();
        $t = $this->openTicket($c);

        $t->addMessage('staff', $this->staff()->id, 'پشتیبان', 'یادداشت-محرمانه-فقط-تیم', internal: true);
        $t->addMessage('staff', $this->staff()->id, 'پشتیبان', 'پاسخ-برای-مشتری');

        // یادداشت داخلی وضعیت را جلو نمی‌برد؛ پاسخ عادی بعدش answered می‌کند
        $this->assertSame('answered', $t->fresh()->status);

        $html = $this->actingAs($c, 'customer')->get("/account/tickets/{$t->id}")->getContent();

        $this->assertStringNotContainsString('یادداشت-محرمانه-فقط-تیم', $html);
        $this->assertStringContainsString('پاسخ-برای-مشتری', $html);

        // و visibleMessages فقط پیام غیرداخلی می‌دهد
        $this->assertCount(2, $t->visibleMessages()->get()); // پیام اول مشتری + پاسخ کارکنان
    }

    public function test_the_customer_can_close_their_ticket(): void
    {
        $c = $this->customer();
        $t = $this->openTicket($c);

        $this->actingAs($c, 'customer')->post("/account/tickets/{$t->id}/close")->assertRedirect();

        $this->assertSame('closed', $t->fresh()->status);
        $this->assertNotNull($t->fresh()->closed_at);
    }

    public function test_tickets_are_closed_to_visitors(): void
    {
        $this->get('/account/tickets')->assertRedirect(route('login'));
    }

    /** پنل مدیریت: کارمند همهٔ تیکت‌ها و یادداشت داخلی را می‌بیند */
    public function test_staff_can_view_and_reply_to_any_ticket(): void
    {
        $c = $this->customer();
        $t = $this->openTicket($c);
        $staff = $this->staff();

        $this->actingAs($staff, 'web')->get("/admin/tickets/{$t->id}")->assertOk();

        $this->actingAs($staff, 'web')->post("/admin/tickets/{$t->id}/reply", [
            'body' => 'پاسخ کارکنان',
        ])->assertRedirect();

        $this->assertSame('answered', $t->fresh()->status);
    }

    public function test_a_customer_cannot_reach_the_admin_ticket_pages(): void
    {
        $c = $this->customer();
        $t = $this->openTicket($c);

        // guard مشتری هیچ دسترسی‌ای به /admin ندارد
        $this->actingAs($c, 'customer')->get("/admin/tickets/{$t->id}")
            ->assertRedirect(route('admin.login'));
    }

    public function test_every_ticket_page_renders_without_a_raw_key(): void
    {
        $c = $this->customer();
        $t = $this->openTicket($c);

        foreach (['/account/tickets', '/account/tickets/new', "/account/tickets/{$t->id}"] as $uri) {
            $html = $this->actingAs($c, 'customer')->get($uri)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/\bui\.tk_[a-z_]+/', $html, "{$uri} کلید خام دارد");
        }
    }
}
