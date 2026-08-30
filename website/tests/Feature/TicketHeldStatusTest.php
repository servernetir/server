<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * وضعیتِ «نگه‌داشته‌شده» — ماشینِ وضعیت، نه فقط برچسب.
 *
 * ═══ چرا این تست ═══
 *
 * صفِ پشتیبانی بینِ پنل و رباتِ بله مشترک است (`scopeQueue`). وضعیتِ تازه اگر
 * با صف ناسازگار باشد، خرابی‌اش **خاموش** است: تیکتِ نگه‌داشته یا بی‌دلیل در
 * صف می‌مانَد و مدیر را گول می‌زند، یا پاسخِ مشتری رویش گم می‌شود چون دیگر
 * هیچ صفی نمی‌بیندش. هیچ‌کدام خطا نمی‌دهند.
 */
class TicketHeldStatusTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'h'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);
    }

    private function ticket(string $status = 'open', ?Customer $c = null): Ticket
    {
        return Ticket::create([
            'customer_id' => ($c ?? $this->customer())->id,
            'number' => 'TK-'.random_int(100000, 999999),
            'subject' => 'آزمون', 'department' => 'technical', 'priority' => 'normal',
            'status' => $status, 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);
    }

    /**
     * 🔴 هستهٔ تست: تیکتِ نگه‌داشته در صف **نیست**.
     *
     * نگه‌داشتن یعنی «الان دستم به این نمی‌رسد»؛ اگر همچنان در صفِ پنل و
     * رباتِ بله بنشیند، نقضِ غرض است و مدیر هر روز از کنارِ همان تیکت رد
     * می‌شود.
     */
    public function test_a_held_ticket_leaves_the_shared_queue(): void
    {
        $t = $this->ticket('open');
        $this->assertTrue(Ticket::queue()->pluck('id')->contains($t->id), 'پیش‌شرط: تیکتِ باز در صف است');

        $t->transitionTo('held');

        $this->assertFalse(Ticket::queue()->pluck('id')->contains($t->id), 'تیکتِ نگه‌داشته هنوز در صف است');
    }

    /**
     * 🔴 پاسخِ مشتری روی تیکتِ نگه‌داشته، خودکار به صف برش می‌گرداند.
     *
     * بی‌این، پیامِ مشتری در تیکتی گم می‌شود که هیچ صفی دیگر نمی‌بیندش — و
     * مشتری بی‌جواب می‌مانَد بی‌آنکه کسی بفهمد.
     */
    public function test_a_customer_reply_pulls_a_held_ticket_back_to_open(): void
    {
        $t = $this->ticket('held');

        $t->addMessage('customer', $t->customer_id, 'مشتری', 'هنوز مشکل دارم');

        $this->assertSame('open', $t->fresh()->status);
        $this->assertTrue(Ticket::queue()->pluck('id')->contains($t->id), 'بعدِ پاسخِ مشتری باید به صف برگردد');
    }

    /** بستن مهرِ زمان می‌زند؛ هر وضعیتِ دیگری پاکش می‌کند — قاعدهٔ متمرکز. */
    public function test_transition_manages_closed_at_in_one_place(): void
    {
        $t = $this->ticket('open');

        $t->transitionTo('closed');
        $this->assertNotNull($t->fresh()->closed_at, 'بستن باید closed_at بزند');

        $t->fresh()->transitionTo('held');
        $this->assertNull($t->fresh()->closed_at, 'خروج از بسته باید closed_at را پاک کند');
        $this->assertSame('held', $t->fresh()->status);
    }

    /** وضعیتِ ناشناخته بی‌صدا رد می‌شود — فرمِ دست‌کاری‌شده نباید ۵۰۰ بسازد. */
    public function test_an_unknown_status_is_ignored_not_fatal(): void
    {
        $t = $this->ticket('open');

        $this->assertFalse($t->transitionTo('banana'));
        $this->assertSame('open', $t->fresh()->status);
    }

    /** پنل: به‌روزرسانیِ تکی هم held را می‌پذیرد. */
    public function test_the_update_endpoint_accepts_held(): void
    {
        $t = $this->ticket('open');

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/tickets/'.$t->id.'/update', ['status' => 'held'])
            ->assertRedirect();

        $this->assertSame('held', $t->fresh()->status);
    }

    /**
     * ⚠️ مشتری هرگز «نگه‌داشته‌شده» یا `held`ِ خام نمی‌بیند.
     *
     * نگه‌داشتن تصمیمِ داخلیِ ماست (منتظرِ قطعه، تأمین‌کننده…)؛ برای مشتری
     * همان «در انتظار پاسخ» است. برچسبِ «کنار گذاشته شد» به مشتری یعنی
     * «فراموشت کردیم».
     */
    public function test_the_customer_never_sees_the_internal_held_label(): void
    {
        $c = $this->customer();
        $this->ticket('held', $c);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.tickets'))->assertOk()->getContent();

        $this->assertStringNotContainsString('held', $html, 'وضعیتِ خامِ ماشین به مشتری نشت کرد');
        $this->assertStringNotContainsString('نگه‌داشته', $html, 'برچسبِ داخلی به مشتری نشت کرد');
        $this->assertStringContainsString(__('ui.tk_st_open'), $html);
    }

    /** فهرستِ ادمین باید تبِ نگه‌داشته‌شده را با شمارش نشان بدهد. */
    public function test_the_admin_list_has_a_held_tab_and_filter(): void
    {
        $this->ticket('held');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/tickets')
            ->assertOk()->assertSee('نگه‌داشته‌شده (1', false);

        $this->actingAs($admin)->get('/admin/tickets?status=held')
            ->assertOk()->assertSee('نگه‌داشته‌شده');
    }
}
