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
 * جریانِ پاسخِ تیکت: برگشت به فهرست + امضای پاسخ‌دهنده.
 *
 * ═══ دو خواسته، دو خطرِ متفاوت ═══
 *
 * ۱) **سرعت**: پس از پاسخ باید به فهرست برگردیم تا تیکتِ بعدی یک کلیک باشد.
 *    خطرش کوچک است ولی یک استثنا دارد که اگر رعایت نشود آزاردهنده می‌شود:
 *    یادداشتِ داخلی یعنی کار روی همین تیکت تمام نشده.
 *
 * ۲) 🔴 **امضا**: نامِ ثبت‌شده روی پاسخ، سندِ گفتگو با مشتری است. اگر هر
 *    کاربری بتواند `as_user` بفرستد، یعنی جعلِ امضا — و در تاریخچه هیچ
 *    نشانه‌ای از جعل نمی‌مانَد.
 */
class TicketReplyFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();      // اعلان‌ها بیرون نروند
    }

    private function ticket(): Ticket
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'tf'.random_int(1, 999999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $t = Ticket::create([
            'customer_id' => $c->id, 'number' => 'TK-F'.random_int(1000, 9999),
            'subject' => 'تست جریان', 'department' => 'technical', 'priority' => 'normal',
            'status' => 'open', 'last_reply_role' => 'customer', 'last_reply_at' => now(),
        ]);

        $t->addMessage('customer', $c->id, 'مشتری', 'سلام');

        return $t;
    }

    /** 🔴 پاسخِ عادی ⇒ برگشت به **فهرست**، نه ماندن در همان صفحه. */
    public function test_a_reply_redirects_back_to_the_list(): void
    {
        $t = $this->ticket();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/tickets/'.$t->id.'/reply', ['body' => 'بررسی شد.'])
            ->assertRedirect('/admin/tickets');
    }

    /** «پاسخ و بستن» هم همان مسیر — و تیکت واقعاً بسته می‌شود. */
    public function test_reply_and_close_also_returns_to_the_list(): void
    {
        $t = $this->ticket();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/tickets/'.$t->id.'/reply', ['body' => 'حل شد.', 'close' => '1'])
            ->assertRedirect('/admin/tickets');

        $this->assertSame('closed', $t->fresh()->status);
    }

    /**
     * ⚠️ استثنا: یادداشتِ داخلی در همان صفحه می‌مانَد.
     *
     * یادداشت یعنی کارِ روی این تیکت تمام نشده (دارد برای خودش می‌نویسد و بعد
     * پاسخ می‌دهد). پرت‌کردنش به فهرست، زمینه را از دستش می‌گیرد.
     */
    public function test_an_internal_note_stays_on_the_ticket(): void
    {
        $t = $this->ticket();

        $r = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->from('/admin/tickets/'.$t->id)
            ->post('/admin/tickets/'.$t->id.'/reply', ['body' => 'یادداشت', 'internal' => '1']);

        $r->assertRedirect('/admin/tickets/'.$t->id);
    }

    /** فیلترِ فهرست حفظ می‌شود — پشتیبان به همان نمایی برمی‌گردد که در آن بود. */
    public function test_the_list_filter_survives_the_round_trip(): void
    {
        $t = $this->ticket();
        $u = User::factory()->create(['role' => 'admin']);

        // باز کردنِ تیکت از نمای فیلترشده (Referer)
        $this->actingAs($u)
            ->withHeader('referer', 'https://console.servernet.cloud/admin/tickets?f=held&page=2')
            ->get('/admin/tickets/'.$t->id)->assertOk();

        $this->actingAs($u)
            ->post('/admin/tickets/'.$t->id.'/reply', ['body' => 'پاسخ'])
            ->assertRedirect('/admin/tickets?f=held&page=2');
    }

    /** ✅ مدیر می‌تواند پاسخ را به نامِ کارشناسِ دیگری ثبت کند. */
    public function test_an_admin_can_reply_as_another_staff_member(): void
    {
        $t = $this->ticket();
        $agent = User::factory()->create(['role' => 'support', 'name' => 'کارشناس اول']);

        $this->actingAs(User::factory()->create(['role' => 'admin', 'name' => 'ebrahimi']))
            ->post('/admin/tickets/'.$t->id.'/reply', ['body' => 'پاسخ', 'as_user' => $agent->id]);

        $m = TicketMessage::where('ticket_id', $t->id)->where('author_role', 'staff')->latest('id')->first();

        $this->assertSame('کارشناس اول', $m->author_name);
        $this->assertSame($agent->id, (int) $m->author_id);
    }

    /**
     * 🔴 پشتیبان **نمی‌تواند** به نامِ دیگری بنویسد — جعلِ امضا.
     *
     * ادعا روی نامِ ذخیره‌شده است، نه کدِ وضعیت: مسیرِ درست این است که فیلد
     * بی‌صدا نادیده گرفته شود و پاسخ به نامِ خودش ثبت شود.
     */
    public function test_support_cannot_sign_as_someone_else(): void
    {
        $t = $this->ticket();
        $other = User::factory()->create(['role' => 'admin', 'name' => 'مدیرِ ارشد']);
        $me = User::factory()->create(['role' => 'support', 'name' => 'کارشناس دوم']);

        $this->actingAs($me)
            ->post('/admin/tickets/'.$t->id.'/reply', ['body' => 'پاسخ', 'as_user' => $other->id]);

        $m = TicketMessage::where('ticket_id', $t->id)->where('author_role', 'staff')->latest('id')->first();

        $this->assertSame('کارشناس دوم', $m->author_name, 'پشتیبان توانست امضای دیگری بزند');
    }

    /** ⚠️ شناسهٔ نامعتبر یا نقشِ نامربوط ⇒ برگشت به خودِ نویسنده، نه خطا. */
    public function test_an_invalid_signer_falls_back_to_the_actual_author(): void
    {
        $t = $this->ticket();
        $writer = User::factory()->create(['role' => 'author', 'name' => 'نویسندهٔ بلاگ']);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'مدیر']);

        // نقشِ author اصلاً کارشناسِ پشتیبانی نیست ⇒ باید نادیده گرفته شود
        $this->actingAs($admin)
            ->post('/admin/tickets/'.$t->id.'/reply', ['body' => 'پاسخ', 'as_user' => $writer->id])
            ->assertRedirect();

        $m = TicketMessage::where('ticket_id', $t->id)->where('author_role', 'staff')->latest('id')->first();

        $this->assertSame('مدیر', $m->author_name);
    }

    /** فهرستِ انتخابِ امضا فقط برای مدیر رندر می‌شود. */
    public function test_only_an_admin_sees_the_signer_picker(): void
    {
        $t = $this->ticket();
        User::factory()->create(['role' => 'support', 'name' => 'کارشناس']);

        $adminHtml = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/tickets/'.$t->id)->getContent();
        $this->assertStringContainsString('name="as_user"', $adminHtml, 'مدیر انتخابگرِ امضا را نمی‌بیند');

        $supHtml = $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get('/admin/tickets/'.$t->id)->getContent();
        $this->assertStringNotContainsString('name="as_user"', $supHtml, 'پشتیبان انتخابگرِ امضا را می‌بیند');
    }
}
