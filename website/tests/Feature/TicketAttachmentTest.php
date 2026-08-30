<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * پیوست تیکت — تصویر و PDF.
 *
 * محورها:
 *   • مشتری و کارمند می‌توانند فایل پیوست کنند
 *   • فقط تصویر/PDF پذیرفته می‌شود
 *   • دانلود فقط برای مالکِ تیکت؛ پیوستِ یادداشت داخلی هرگز به مشتری نمی‌رسد
 */
class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake();   // اعلان‌های بله/پیامک شبکه نروند
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret1234'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'پشتیبان', 'email' => 's'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    public function test_customer_can_open_ticket_with_image_attachment(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/tickets', [
            'subject' => 'مشکل من', 'department' => 'technical', 'priority' => 'normal',
            'body' => 'تصویر پیوست است',
            'attachments' => [UploadedFile::fake()->image('shot.png', 600, 400)],
        ])->assertRedirect();

        $this->assertSame(1, TicketAttachment::count());
        $att = TicketAttachment::first();
        $this->assertStringStartsWith('image/', $att->mime);
        Storage::disk('local')->assertExists($att->path);
    }

    public function test_pdf_is_accepted_on_reply(): void
    {
        $c = $this->customer();
        $ticket = $c->tickets()->create([
            'subject' => 's', 'department' => 'technical', 'priority' => 'normal',
            'status' => 'open', 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);

        $this->actingAs($c, 'customer')->post("/account/tickets/{$ticket->id}/reply", [
            'body' => 'فاکتور پیوست',
            'attachments' => [UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf')],
        ])->assertRedirect();

        $this->assertSame(1, TicketAttachment::where('mime', 'application/pdf')->count());
    }

    public function test_disallowed_type_is_rejected(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/tickets', [
            'subject' => 's', 'department' => 'technical', 'priority' => 'normal',
            'body' => 'فایل بد',
            'attachments' => [UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload')],
        ])->assertSessionHasErrors();

        $this->assertSame(0, TicketAttachment::count());
    }

    public function test_owner_can_download_but_stranger_cannot(): void
    {
        $owner = $this->customer();
        $stranger = $this->customer();
        $ticket = $owner->tickets()->create([
            'subject' => 's', 'department' => 'technical', 'priority' => 'normal',
            'status' => 'open', 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);
        $msg = $ticket->addMessage('customer', $owner->id, 'x', 'hi');
        $att = TicketAttachment::create([
            'ticket_id' => $ticket->id, 'ticket_message_id' => $msg->id,
            'disk' => 'local', 'path' => 'ticket-attachments/x.png',
            'original_name' => 'x.png', 'mime' => 'image/png', 'size' => 100,
        ]);
        Storage::disk('local')->put('ticket-attachments/x.png', 'fake');

        $this->actingAs($owner, 'customer')->get("/account/tickets/{$ticket->id}/att/{$att->id}")->assertOk();
        $this->actingAs($stranger, 'customer')->get("/account/tickets/{$ticket->id}/att/{$att->id}")->assertNotFound();
    }

    public function test_customer_cannot_download_internal_note_attachment(): void
    {
        $c = $this->customer();
        $staff = $this->staff();
        $ticket = $c->tickets()->create([
            'subject' => 's', 'department' => 'technical', 'priority' => 'normal',
            'status' => 'open', 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);
        $note = $ticket->addMessage('staff', $staff->id, 'پشتیبان', 'محرمانه', internal: true);
        $att = TicketAttachment::create([
            'ticket_id' => $ticket->id, 'ticket_message_id' => $note->id,
            'disk' => 'local', 'path' => 'ticket-attachments/n.pdf',
            'original_name' => 'n.pdf', 'mime' => 'application/pdf', 'size' => 100,
        ]);
        Storage::disk('local')->put('ticket-attachments/n.pdf', 'fake');

        // مشتری نباید پیوستِ یادداشت داخلی را ببیند
        $this->actingAs($c, 'customer')->get("/account/tickets/{$ticket->id}/att/{$att->id}")->assertNotFound();
        // ولی کارمند می‌بیند
        $this->actingAs($staff, 'web')->get("/admin/tickets/{$ticket->id}/attachments/{$att->id}")->assertOk();
    }
}
