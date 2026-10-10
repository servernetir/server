<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminTicketCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Mail::fake();
    }

    private function staff(string $role = 'admin'): User
    {
        return User::create([
            'name' => 'پشتیبان', 'email' => uniqid('staff').'@example.com',
            'password' => bcrypt('secret1234'), 'role' => $role,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => uniqid('customer').'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    public function test_staff_can_create_independent_tickets_for_multiple_customers(): void
    {
        $staff = $this->staff('support');
        $a = $this->customer();
        $b = $this->customer();

        $this->actingAs($staff, 'web')->post('/admin/tickets/create', [
            'customer_ids' => [$a->id, $b->id],
            'subject' => 'اطلاع‌رسانی سرویس', 'department' => 'technical',
            'priority' => 'normal', 'body' => 'لطفاً سفارش جدید را ثبت کنید.',
        ])->assertRedirect('/admin/tickets?status=answered')->assertSessionHasNoErrors();

        $this->assertSame(2, Ticket::count());
        $this->assertSame([$a->id, $b->id], Ticket::orderBy('customer_id')->pluck('customer_id')->all());
        foreach (Ticket::all() as $ticket) {
            $this->assertSame('answered', $ticket->status);
            $this->assertSame('staff', $ticket->last_reply_role);
            $this->assertSame('لطفاً سفارش جدید را ثبت کنید.', $ticket->messages()->sole()->body);
            $this->assertSame($staff->id, $ticket->messages()->sole()->author_id);
        }
    }

    public function test_ticket_creation_requires_at_least_one_customer(): void
    {
        $this->actingAs($this->staff(), 'web')->post('/admin/tickets/create', [
            'customer_ids' => [], 'subject' => 'موضوع', 'department' => 'technical',
            'priority' => 'normal', 'body' => 'متن',
        ])->assertSessionHasErrors('customer_ids');

        $this->assertSame(0, Ticket::count());
    }

    public function test_api_creates_ticket_by_public_customer_code(): void
    {
        config(['services.support_ticket_api.token' => 'test-support-token']);
        $customer = $this->customer();

        $this->withToken('test-support-token')->postJson('/api/admin/tickets', [
            'customer_codes' => [$customer->code],
            'subject' => 'پیام خودکار', 'department' => 'billing',
            'priority' => 'high', 'body' => 'این تیکت از API ساخته شده است.',
        ])->assertCreated()->assertJsonPath('created', 1)
            ->assertJsonPath('tickets.0.customer_code', $customer->code);

        $this->assertDatabaseHas('tickets', [
            'customer_id' => $customer->id, 'subject' => 'پیام خودکار',
            'department' => 'billing', 'priority' => 'high', 'status' => 'answered',
        ]);
    }

    public function test_api_rejects_missing_or_wrong_token(): void
    {
        config(['services.support_ticket_api.token' => 'right-token']);

        $this->postJson('/api/admin/tickets', [])->assertUnauthorized()->assertJsonPath('code', 'unauthorized');
        $this->withToken('wrong-token')->postJson('/api/admin/tickets', [])->assertUnauthorized();
    }
}
