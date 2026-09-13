<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerInternalNoteTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create(['code' => 'SN-9001', 'email' => 'note@example.test', 'phone' => '09120000001', 'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => $role.random_int(1, 9999).'@example.test', 'password' => bcrypt('x'), 'role' => $role]);
    }

    public function test_staff_can_create_but_customer_can_never_see_internal_notes(): void
    {
        $customer = $this->customer();
        $support = $this->user('support');

        $this->actingAs($support)->post("/admin/customers/{$customer->id}/notes", ['body' => 'پیگیری حقوقی'])->assertRedirect();
        $this->assertDatabaseHas('customer_notes', ['customer_id' => $customer->id, 'user_id' => $support->id, 'body' => 'پیگیری حقوقی']);
        $this->assertDatabaseHas('activity_logs', ['customer_id' => $customer->id, 'action' => 'internal_note_created', 'actor' => 'staff']);

        $this->actingAs($customer, 'customer')->get('/account')->assertDontSee('پیگیری حقوقی');
        $serialized = $customer->fresh()->load('notes')->toArray();
        $this->assertArrayNotHasKey('notes', $serialized, 'مدل مشتری نیز نباید یادداشت را در JSON تصادفی نشت دهد');
    }

    public function test_support_cannot_edit_another_authors_note_but_admin_can(): void
    {
        $customer = $this->customer();
        $owner = $this->user('support');
        $other = $this->user('support');
        $admin = $this->user('admin');
        $note = CustomerNote::create(['customer_id' => $customer->id, 'user_id' => $owner->id, 'body' => 'اصل']);

        $this->actingAs($other)->put("/admin/customers/{$customer->id}/notes/{$note->id}", ['body' => 'غیرمجاز'])->assertForbidden();
        $this->actingAs($other)->delete("/admin/customers/{$customer->id}/notes/{$note->id}")->assertForbidden();
        $this->actingAs($admin)->put("/admin/customers/{$customer->id}/notes/{$note->id}", ['body' => 'مجاز'])->assertRedirect();
        $this->assertSame('مجاز', $note->fresh()->body);
        $this->assertDatabaseHas('activity_logs', ['action' => 'internal_note_updated', 'actor' => 'staff']);
    }

    public function test_guests_and_customers_cannot_create_edit_or_delete_notes(): void
    {
        $customer = $this->customer();
        $author = $this->user('support');
        $note = CustomerNote::create(['customer_id' => $customer->id, 'user_id' => $author->id, 'body' => 'خصوصی']);
        $base = "/admin/customers/{$customer->id}/notes";

        $this->post($base, ['body' => 'x'])->assertRedirect();
        $this->actingAs($customer, 'customer')->post($base, ['body' => 'x'])->assertRedirect();
        $this->actingAs($customer, 'customer')->put("$base/{$note->id}", ['body' => 'x'])->assertRedirect();
        $this->actingAs($customer, 'customer')->delete("$base/{$note->id}")->assertRedirect();
        $this->assertSame('خصوصی', $note->fresh()->body);
    }

    public function test_author_can_delete_and_delete_is_audited(): void
    {
        $customer = $this->customer();
        $author = $this->user('support');
        $note = CustomerNote::create(['customer_id' => $customer->id, 'user_id' => $author->id, 'body' => 'حذف']);

        $this->actingAs($author)->delete("/admin/customers/{$customer->id}/notes/{$note->id}")->assertRedirect();
        $this->assertDatabaseMissing('customer_notes', ['id' => $note->id]);
        $this->assertDatabaseHas('activity_logs', ['customer_id' => $customer->id, 'action' => 'internal_note_deleted', 'actor' => 'staff']);
    }

    public function test_note_cannot_be_moved_or_deleted_through_another_customer_url(): void
    {
        $first = $this->customer();
        $second = Customer::create(['code' => 'SN-9002', 'email' => 'two@example.test', 'phone' => '09120000002', 'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);
        $admin = $this->user('admin');
        $note = CustomerNote::create(['customer_id' => $first->id, 'user_id' => $admin->id, 'body' => 'محرمانه']);

        $this->actingAs($admin)->delete("/admin/customers/{$second->id}/notes/{$note->id}")->assertNotFound();
        $this->actingAs($admin)->put("/admin/customers/{$second->id}/notes/{$note->id}", ['body' => 'نشت'])->assertNotFound();
        $this->assertDatabaseHas('customer_notes', ['id' => $note->id]);
    }
}
