<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Bale\Admin\AdminBaleRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اعلانِ «پاسخِ تازهٔ مشتری» باید دکمهٔ شیشه‌ای داشته باشد.
 *
 * ═══ چرا ═══
 *
 * تا امروز این اعلان فقط متن و یک لینک بود. برای یک جملهٔ کوتاه باید پنل باز
 * می‌شد — و عملاً جواب عقب می‌افتاد. کارفرما خواست همان‌جا هم مشتری را ببیند
 * هم جواب بدهد، مثلِ تیکت‌ها.
 *
 * 🔴 و هیچ‌کدام از این دکمه‌ها خودشان چیزی نمی‌فرستند؛ همه به کارتِ تأیید
 * می‌روند.
 */
class BaleReplyButtonsTest extends TestCase
{
    use RefreshDatabase;

    private function ticketWithCustomer(): Ticket
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'b'.random_int(1, 99999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        return Ticket::create([
            'customer_id' => $c->id, 'number' => 'TK-'.random_int(1000, 9999),
            'subject' => 'آزمونِ دکمه', 'department' => 'support', 'priority' => 'normal',
            'status' => 'open', 'last_reply_role' => 'staff', 'last_reply_at' => now(),
        ]);
    }

    /**
     * 🔴 پاسخِ مشتری باید اعلانِ **دکمه‌دار** بسازد.
     *
     * ⚠️ ادعا روی خودِ درخواستِ HTTPِ بله است، نه روی کد: تنها راهِ اثباتِ
     * اینکه `reply_markup` واقعاً به بله رسیده.
     */
    public function test_a_customer_reply_sends_glass_buttons_to_bale(): void
    {
        config([
            'services.bale.token' => 'test-token',
            'servernet.contact.notify_chat_id' => '12345',
        ]);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $t = $this->ticketWithCustomer();

        $this->actingAs($t->customer, 'customer')
            ->post('/account/tickets/'.$t->id.'/reply', ['body' => 'سلام، هنوز مشکل دارم.']);

        $withKeyboard = collect(Http::recorded())->filter(function ($pair) {
            $body = (string) ($pair[0]->body() ?? '');

            return str_contains($body, 'inline_keyboard');
        });

        $this->assertTrue($withKeyboard->isNotEmpty(),
            'اعلانِ پاسخِ مشتری بدونِ دکمهٔ شیشه‌ای رفت');
    }

    /**
     * ⚠️ دکمه‌ها باید افعالِ **موجود** را بزنند.
     *
     * فعلِ تازه یعنی یک `default` در روتر که می‌گوید «این دکمه معتبر نیست» —
     * دکمه‌ای که رندر می‌شود و کار نمی‌کند، بدترین حالت است.
     */
    public function test_the_buttons_use_verbs_the_router_already_knows(): void
    {
        config([
            'services.bale.token' => 'test-token',
            'servernet.contact.notify_chat_id' => '12345',
        ]);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $t = $this->ticketWithCustomer();

        $this->actingAs($t->customer, 'customer')
            ->post('/account/tickets/'.$t->id.'/reply', ['body' => 'سلام، هنوز مشکل دارم.']);

        $body = collect(Http::recorded())
            ->map(fn ($p) => (string) ($p[0]->body() ?? ''))
            ->first(fn ($b) => str_contains($b, 'inline_keyboard')) ?? '';

        $prefix = AdminBaleRouter::CB_PREFIX;

        foreach (['tw:', 'td:', 't:', 'c:'] as $verb) {
            $this->assertStringContainsString($prefix.$verb, urldecode($body),
                "دکمه‌ای با فعلِ «{$verb}» ساخته نشد");
        }
    }

    /** 🔴 پیشوندِ نسخه‌دار باید از خودِ روتر بیاید، نه رشتهٔ کپی‌شده. */
    public function test_the_prefix_is_shared_not_copied(): void
    {
        $src = (string) file_get_contents(app_path('Http/Controllers/Account/TicketController.php'));

        $this->assertStringContainsString('AdminBaleRouter::CB_PREFIX', $src,
            'پیشوند دستی نوشته شده — با بالا رفتنِ نسخه، دکمه‌ها بی‌صدا می‌میرند');
        $this->assertStringNotContainsString("'v1:'", $src);
    }

    /**
     * 🔴 «تصحیح» هرگز خودش نمی‌فرستد.
     *
     * خروجی در انبارِ پیش‌نویس می‌نشیند و فقط با دکمهٔ «ارسال» می‌رود.
     */
    public function test_polish_never_sends_a_message_by_itself(): void
    {
        $t = $this->ticketWithCustomer();
        $before = TicketMessage::where('ticket_id', $t->id)->count();

        Http::fake();

        app(\App\Services\Ticket\ReplyPolisher::class)->polish('یک متنِ به‌اندازه بلند برای صیقل دادن.');

        $this->assertSame($before, TicketMessage::where('ticket_id', $t->id)->count(),
            'تصحیح یک پیام ساخت — یعنی چیزی به مشتری رفت');
    }
}
