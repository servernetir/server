<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عملیاتِ گروهیِ تیکت — انتخابِ چندتایی و تغییرِ وضعیتِ یک‌جا.
 *
 * ═══ خرابی‌های خاموشِ ممکن ═══
 *
 * · روتِ `bulk` بعدِ `{ticket}` ثبت شود ⇒ بلعیده می‌شود و دکمه برای همیشه
 *   ۴۰۴ — همان درسِ روتِ compare در فروشگاهِ قطعات.
 * · چک‌باکس داخلِ ردیفِ کلیک‌خور، بدونِ توقفِ انتشار ⇒ هر تیک به صفحهٔ تیکت
 *   می‌بَرد و انتخابِ گروهی عملاً ناممکن است.
 * · پیامِ موفقیت تعدادِ «انتخاب‌شده» را بگوید نه «تغییرکرده» ⇒ ادعای دروغ.
 */
class TicketBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** @return list<Ticket> */
    private function tickets(int $n, string $status = 'open'): array
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'b'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        return collect(range(1, $n))->map(fn ($i) => Ticket::create([
            'customer_id' => $c->id, 'number' => 'TK-B'.random_int(100000, 999999),
            'subject' => 'آزمون '.$i, 'department' => 'technical', 'priority' => 'normal',
            'status' => $status, 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]))->all();
    }

    /**
     * 🔴 هسته: فقط انتخاب‌شده‌ها تغییر می‌کنند، بقیه دست‌نخورده.
     */
    public function test_bulk_close_changes_only_the_selected_tickets(): void
    {
        [$a, $b, $c] = $this->tickets(3);

        $this->actingAs($this->admin)
            ->post('/admin/tickets/bulk', ['ids' => [$a->id, $b->id], 'status' => 'closed'])
            ->assertRedirect();

        $this->assertSame('closed', $a->fresh()->status);
        $this->assertNotNull($a->fresh()->closed_at, 'بستنِ گروهی باید closed_at بزند');
        $this->assertSame('closed', $b->fresh()->status);
        $this->assertSame('open', $c->fresh()->status, 'تیکتِ انتخاب‌نشده نباید تغییر کند');
    }

    /** نگه‌داشتنِ گروهی، همه را از صف بیرون می‌بَرد. */
    public function test_bulk_hold_empties_the_queue_for_those_tickets(): void
    {
        [$a, $b] = $this->tickets(2);

        $this->actingAs($this->admin)
            ->post('/admin/tickets/bulk', ['ids' => [$a->id, $b->id], 'status' => 'held']);

        $queue = Ticket::queue()->pluck('id');
        $this->assertFalse($queue->contains($a->id));
        $this->assertFalse($queue->contains($b->id));
    }

    /** بازگشاییِ گروهیِ تیکت‌های بسته، closed_at را هم پاک می‌کند. */
    public function test_bulk_reopen_clears_closed_at(): void
    {
        [$a] = $this->tickets(1, 'closed');
        $a->forceFill(['closed_at' => now()])->save();

        $this->actingAs($this->admin)
            ->post('/admin/tickets/bulk', ['ids' => [$a->id], 'status' => 'open']);

        $this->assertSame('open', $a->fresh()->status);
        $this->assertNull($a->fresh()->closed_at);
    }

    /**
     * ⚠️ پیامِ موفقیت شمارِ **واقعاً تغییرکرده** را می‌گوید.
     *
     * سه تیکت انتخاب شده که یکی از قبل بسته است ⇒ پیام باید «۲» بگوید نه
     * «۳». پیامی که بیشتر از واقعیت ادعا کند، اعتماد به کلِ پنل را می‌بَرد.
     */
    public function test_the_success_message_counts_real_changes_not_selections(): void
    {
        [$a, $b] = $this->tickets(2);
        [$closed] = $this->tickets(1, 'closed');

        $r = $this->actingAs($this->admin)
            ->post('/admin/tickets/bulk', ['ids' => [$a->id, $b->id, $closed->id], 'status' => 'closed']);

        $r->assertSessionHas('ok', fn ($msg) => str_contains($msg, fa_num('2')));
    }

    /** وضعیتِ نامعتبر ⇒ خطای اعتبارسنجی، و هیچ تیکتی تغییر نمی‌کند. */
    public function test_an_invalid_status_changes_nothing(): void
    {
        [$a] = $this->tickets(1);

        $this->actingAs($this->admin)
            ->post('/admin/tickets/bulk', ['ids' => [$a->id], 'status' => 'banana'])
            ->assertSessionHasErrors('status');

        $this->assertSame('open', $a->fresh()->status);
    }

    /** شناسهٔ ناموجود بی‌صدا رد می‌شود — بقیه اعمال می‌شوند. */
    public function test_unknown_ids_are_skipped_gracefully(): void
    {
        [$a] = $this->tickets(1);

        $this->actingAs($this->admin)
            ->post('/admin/tickets/bulk', ['ids' => [$a->id, 999999], 'status' => 'held'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('held', $a->fresh()->status);
    }

    /** 🔴 سقفِ ۱۰۰: فرمِ دست‌ساز نتواند کلِ جدول را یک‌جا ببندد. */
    public function test_more_than_a_hundred_ids_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/tickets/bulk', ['ids' => range(1, 101), 'status' => 'closed'])
            ->assertSessionHasErrors('ids');
    }

    /**
     * 🔴 روتِ bulk نباید توسطِ `{ticket}` بلعیده شود.
     *
     * اگر بعدِ روتِ پارامتری ثبت شده بود، `bulk` یک شناسهٔ تیکت تعبیر می‌شد
     * و اتصالِ مدل ۴۰۴ می‌داد — دکمه بی‌صدا از کار می‌افتاد.
     */
    public function test_the_bulk_route_is_not_swallowed_by_the_ticket_route(): void
    {
        [$a] = $this->tickets(1);

        $r = $this->actingAs($this->admin)
            ->post('/admin/tickets/bulk', ['ids' => [$a->id], 'status' => 'answered']);

        $this->assertNotSame(404, $r->status(), 'روتِ bulk بلعیده شده است');
        $this->assertSame('answered', $a->fresh()->status);
    }

    /**
     * ⚠️ فهرست: چک‌باکس‌ها، فرمِ گروهی، و توقفِ انتشارِ کلیکِ ردیف.
     *
     * ادعا روی HTMLِ رندرشده — تا حذفِ هرکدام در ویرایش‌های آینده هم دیده شود.
     */
    public function test_the_list_renders_the_bulk_machinery(): void
    {
        $this->tickets(2);

        $html = $this->actingAs($this->admin)->get('/admin/tickets')->assertOk()->getContent();

        $this->assertStringContainsString('action="/admin/tickets/bulk"', $html, 'فرمِ گروهی نیست');
        $this->assertStringContainsString('name="ids[]"', $html, 'چک‌باکسِ انتخاب نیست');
        $this->assertStringContainsString('event.stopPropagation()', $html,
            'سلولِ چک‌باکس انتشارِ کلیک را نمی‌بندد — هر تیک به صفحهٔ تیکت می‌بَرد');
        $this->assertStringContainsString('tk-bulkbar[hidden]{ display:none }', $html,
            'قاعدهٔ [hidden] نیست — نوار همیشه دیده می‌شود (تلهٔ نوارِ مقایسه)');
    }
}
