<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «پیشنهاد پاسخ» در پنل — همان موتورِ رباتِ بله (`TicketDraftWriter`).
 *
 * ═══ دو خطرِ واقعی ═══
 *
 * 🔴 ارسالِ ناخواسته: دکمه داخلِ همان `<form>`ِ پاسخ است؛ بدونِ
 * `type="button"` هر کلیک، متنِ نیمه‌کاره را به مشتری **می‌فرستد**.
 *
 * 🔴 نشتِ یادداشتِ داخلی: پیش‌نویس از گفتگو ساخته می‌شود، و یادداشت‌هایی که
 * عمداً از مشتری پنهان‌اند («این مشتری بدحساب است») نباید وارد متنی شوند که
 * قرار است به همان مشتری برسد.
 */
class TicketPanelDraftTest extends TestCase
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
            'email' => 'd'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $t = Ticket::create([
            'customer_id' => $c->id, 'number' => 'TK-D'.random_int(1000, 9999),
            'subject' => 'سرورم کند است', 'department' => 'technical', 'priority' => 'normal',
            'status' => 'open', 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);

        $t->addMessage('customer', $c->id, 'مشتری', 'سایتم خیلی کند باز می‌شود، لطفاً بررسی کنید.');

        return $t;
    }

    /** 🔴 فراخوانِ پیش‌نویس نباید هیچ پیامی در تیکت بسازد. */
    public function test_drafting_creates_no_message(): void
    {
        Http::preventStrayRequests();
        $t = $this->ticket();
        $before = TicketMessage::where('ticket_id', $t->id)->count();

        $this->actingAs($this->admin())
            ->postJson('/admin/tickets/'.$t->id.'/draft', ['tone' => 'n']);

        $this->assertSame($before, TicketMessage::where('ticket_id', $t->id)->count(),
            'پیش‌نویس یک پیام ساخت — یعنی چیزی به مشتری رفت');
    }

    /** ⚠️ نبودِ سرویسِ AI ⇒ پیامِ JSONِ روشن، نه ۵۰۰ و نه ریدایرکتِ HTML. */
    public function test_a_missing_ai_key_answers_in_json(): void
    {
        Http::preventStrayRequests();

        $r = $this->actingAs($this->admin())
            ->postJson('/admin/tickets/'.$this->ticket()->id.'/draft', []);

        $this->assertContains($r->status(), [200, 503], 'پاسخِ غیرمنتظره: '.$r->status());
        $r->assertJsonPath('ok', false);
    }

    /** لحنِ ناشناخته بی‌صدا به «معمولی» برمی‌گردد — فرمِ دست‌کاری‌شده ۵۰۰ نسازد. */
    public function test_an_unknown_tone_falls_back_instead_of_crashing(): void
    {
        Http::preventStrayRequests();

        $r = $this->actingAs($this->admin())
            ->postJson('/admin/tickets/'.$this->ticket()->id.'/draft', ['tone' => 'weird']);

        $this->assertContains($r->status(), [200, 503]);
    }

    /**
     * 🔴 دکمهٔ پیشنهاد باید `type="button"` باشد و اسکریپتش واقعاً روی صفحه.
     *
     * لایوتِ ادمین `@stack` ندارد؛ اسکریپتِ pushشده بی‌صدا دور ریخته می‌شود —
     * دکمه‌ای که رندر می‌شود و هرگز کار نمی‌کند.
     */
    public function test_the_draft_button_cannot_submit_and_its_script_is_present(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/admin/tickets/'.$this->ticket()->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<button[^>]+type="button"[^>]+id="tk-draft"~',
            $html,
            'دکمهٔ پیشنهاد type="button" ندارد و فرم را می‌فرستد'
        );
        $this->assertStringContainsString("getElementById('tk-draft')", $html, 'اسکریپتِ پیش‌نویس روی صفحه نیست');
        $this->assertStringContainsString('tk-draft-tone', $html, 'انتخابِ لحن روی صفحه نیست');
    }

    /**
     * 🔴 یادداشتِ داخلی هرگز به مدل نمی‌رسد.
     *
     * ⚠️ چرا `Http::fake` این‌جا **کار نمی‌کند**: `AiContent::call()` با curlِ
     * خام می‌فرستد، نه با فاساد Http. نسخهٔ اولِ این تست با `assertNotSent`
     * نوشته شد و خالی‌ازمعنا سبز بود — هیچ درخواستِ Httpای وجود نداشت که
     * بررسی شود. پس پرامپت در خودِ سرویس ضبط می‌شود: زیرکلاسی که `call()` را
     * بازنویسی می‌کند در ظرف می‌نشیند، و ادعا روی متنی است که **واقعاً** به
     * مدل می‌رفت. اگر روزی `visibleMessages()` به `messages()` عوض شود،
     * همین‌جا قرمز می‌شود.
     */
    public function test_internal_notes_never_reach_the_model(): void
    {
        config()->set('services.gapgpt.key', 'test-key-123');

        $t = $this->ticket();
        $t->addMessage('staff', 1, 'کارمند', 'این مشتری بدحساب است — مراقب باش', internal: true);

        $spy = new class extends \App\Services\Ticket\TicketDraftWriter
        {
            public string $captured = '';

            protected function call(string $system, string $user, int $maxTokens, int $timeout = 140, bool $stream = false): ?string
            {
                $this->captured = $system."
".$user;

                return 'پاسخ آزمایشی';
            }
        };
        app()->instance(\App\Services\Ticket\TicketDraftWriter::class, $spy);

        $r = $this->actingAs($this->admin())
            ->postJson('/admin/tickets/'.$t->id.'/draft', ['tone' => 'n']);

        $r->assertOk()->assertJsonPath('ok', true)->assertJsonPath('text', 'پاسخ آزمایشی');

        // ⚠️ اول ثابت می‌شود پرامپت واقعاً ساخته شده — تا ادعای بعدی
        //    نتواند روی رشتهٔ خالی، خالی‌ازمعنا سبز بماند
        $this->assertStringContainsString('کند', $spy->captured, 'پیامِ دیدنیِ مشتری به مدل نرفته — تست توخالی است');
        $this->assertStringNotContainsString('بدحساب', $spy->captured, 'یادداشتِ داخلی واردِ پرامپتِ مدل شد');
    }
}
