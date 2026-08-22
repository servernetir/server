<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Ticket\ReplyPolisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «تصحیح نگارش با AI» فقط متن را برمی‌گرداند — هرگز چیزی نمی‌فرستد.
 *
 * ═══ خطرِ واقعیِ این ویژگی ═══
 *
 * 🔴 خطر «بد نوشتن» نیست، **اضافه‌کردن** است. کارفرما می‌نویسد «تا فردا درست
 * میشه»؛ مدلِ رهاشده می‌تواند بنویسد «طبق SLA ظرف ۲۴ ساعت رفع می‌شود» — و
 * شرکت ناگهان به چیزی متعهد شده که کسی نگفته بود. متنِ رسمی‌ترِ غلط از متنِ
 * شکسته‌بستهٔ درست بدتر است، چون معتبر به‌نظر می‌رسد.
 *
 * 🔴 و خطرِ دوم: ارسالِ ناخواسته. دکمه داخلِ همان `<form>` است، پس اگر
 * `type="button"` نباشد کلیک روی «تصحیح» پاسخ را **می‌فرستد**.
 */
class ReplyPolishNeverSendsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function ticket(): Ticket
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 't'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        return Ticket::create([
            'customer_id' => $c->id, 'number' => 'TK-'.random_int(1000, 9999),
            'subject' => 'آزمون', 'department' => 'support', 'priority' => 'normal',
            'status' => 'open', 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);
    }

    /** 🔴 فراخوانِ تصحیح نباید هیچ پیامی در تیکت بسازد. */
    public function test_polishing_creates_no_message(): void
    {
        $t = $this->ticket();
        $before = TicketMessage::where('ticket_id', $t->id)->count();

        $this->actingAs($this->admin())
            ->postJson('/admin/tickets/'.$t->id.'/polish', ['body' => 'سلام این یک متن آزمایشی است برای تصحیح.']);

        $this->assertSame($before, TicketMessage::where('ticket_id', $t->id)->count(),
            'تصحیح یک پیام در تیکت ساخت — یعنی چیزی به مشتری رفت');
    }

    /**
     * 🔴 دکمه باید `type="button"` باشد.
     *
     * داخلِ `<form>` پیش‌فرضِ `<button>` برابرِ submit است. بی‌این ویژگی، کلیک
     * روی «تصحیح» پاسخِ خام را به مشتری می‌فرستد — دقیقاً برعکسِ هدف.
     */
    public function test_the_button_can_never_submit_the_form(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/admin/tickets/'.$this->ticket()->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<button[^>]+type="button"[^>]+id="tk-polish"~',
            $html,
            'دکمهٔ تصحیح `type="button"` ندارد و فرم را می‌فرستد'
        );
    }

    /**
     * ⚠️ اسکریپت باید واقعاً رندر شود.
     *
     * لایوتِ ادمین `@stack` ندارد؛ نسخهٔ اولِ این کار اسکریپت را `@push` کرد و
     * بی‌صدا دور ریخته می‌شد — دکمه دیده می‌شد و هیچ‌وقت کار نمی‌کرد.
     */
    public function test_the_script_is_actually_on_the_page(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/admin/tickets/'.$this->ticket()->id)->assertOk()->getContent();

        $this->assertStringContainsString("getElementById('tk-polish')", $html,
            'اسکریپتِ تصحیح روی صفحه نیست');
    }

    /** ⚠️ متنِ خالی ⇒ ۴۲۲ِ JSON، نه ریدایرکتِ HTML. */
    public function test_an_empty_body_returns_json_not_a_redirect(): void
    {
        $r = $this->actingAs($this->admin())
            ->postJson('/admin/tickets/'.$this->ticket()->id.'/polish', ['body' => '']);

        $r->assertStatus(422)->assertJson(['ok' => false]);
    }

    /**
     * ⚠️ نبودِ سرویسِ AI باید پیامِ روشن بدهد، نه ۵۰۰.
     *
     * در محیطِ تست هیچ کلیدی نیست، پس این همان مسیرِ واقعی است.
     */
    public function test_a_missing_ai_key_says_so_instead_of_crashing(): void
    {
        Http::preventStrayRequests();

        $r = $this->actingAs($this->admin())
            ->postJson('/admin/tickets/'.$this->ticket()->id.'/polish', ['body' => 'یک متنِ به‌اندازه بلند برای تصحیح.']);

        $this->assertContains($r->status(), [200, 503], 'پاسخِ غیرمنتظره: '.$r->status());
        $r->assertJsonPath('ok', false);
    }

    /** ⚠️ متنِ خیلی کوتاه اصلاً به مدل نمی‌رود — تماسِ بی‌فایده هزینه است. */
    public function test_a_too_short_draft_never_reaches_the_model(): void
    {
        Http::fake();

        app(ReplyPolisher::class)->polish('کوتاه');

        Http::assertNothingSent();
    }
}
