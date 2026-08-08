<?php

namespace Tests\Feature;

use App\Models\MailboxMessage;
use App\Models\User;
use App\Services\Bale\BaleNotifier;
use App\Services\Mail\ImapClient;
use App\Services\Mail\MailboxDigest;
use App\Services\Mail\MailboxSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صندوق‌های مدیریتی و گزارشِ بله.
 *
 * محورها — هر کدام یک خواستهٔ صریحِ کارفرما:
 *   • «ایمیلِ ایونتِ سیستم که یک‌بار در بله رفته را دوباره نگو»
 *   • «هر ایمیل فقط یک بار گزارش شود»
 *   • «فقط چیزی که آدم می‌خواهد» (خبرنامه و اسپم بی‌صدا)
 *   • و یک چیزی که کارفرما نگفت ولی اگر بشکند بدترین حالت است:
 *     اگر بله قطع باشد، نامه‌ها **نباید** گزارش‌شده علامت بخورند.
 */
class MailboxDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mailboxes.accounts' => [
                ['key' => 'ceo', 'label' => 'مدیرعامل', 'user' => 'ceo@servernet.cloud', 'pass' => 'x'],
                ['key' => 'support', 'label' => 'پشتیبانی', 'user' => 'support@servernet.cloud', 'pass' => 'x'],
            ],
            'servernet.contact.notify_phone' => '09120000000',
        ]);
    }

    private function msg(array $attrs = []): MailboxMessage
    {
        static $n = 0;
        $n++;

        return MailboxMessage::create($attrs + [
            'account'     => 'ceo',
            'uid_hash'    => MailboxMessage::hashFor('ceo', 'm'.$n.'@x'),
            'message_id'  => 'm'.$n.'@x',
            'from_email'  => 'someone@clinic.ae',
            'from_name'   => 'Someone',
            'subject'     => 'Question about hosting',
            'snippet'     => 'Hello, what are your prices?',
            'received_at' => now(),
            'is_system'   => false,
            'category'    => 'sales',
            'needs_reply' => true,
            'importance'  => 4,
            'summary'     => 'قیمتِ میزبانی را پرسیده',
        ]);
    }

    /** بله‌ای که هر بار می‌ترکد — برای تستِ «هیچی مهر نخورد» */
    private function brokenBale(): void
    {
        $this->app->bind(BaleNotifier::class, fn () => new class extends BaleNotifier
        {
            public function __construct() {}

            public function notify(string $mobile, string $text): void
            {
                throw new \RuntimeException('bale down');
            }
        });
    }

    /** بلهٔ ساکت که فقط متن را نگه می‌دارد */
    private function fakeBale(): object
    {
        $fake = new class extends BaleNotifier
        {
            public array $sent = [];

            public function __construct() {}

            public function notify(string $mobile, string $text): void
            {
                $this->sent[] = $text;
            }
        };

        $this->app->instance(BaleNotifier::class, $fake);

        return $fake;
    }

    // ───────────── «تکراری نگو» ─────────────

    public function test_our_own_admin_notifications_are_marked_system(): void
    {
        $sync = app(MailboxSync::class);

        // AdminNotifier هر رویداد را با همین پیشوند ایمیل می‌کند و هم‌زمان
        // همان را در بله می‌گوید — یعنی این نامه از قبل خوانده شده است.
        $this->assertTrue($sync->isSystem('noreply@servernet.cloud', '[سرورنت] تیکت تازه'));
        $this->assertTrue($sync->isSystem('x@y.com', '[آزمایشی] الگوی پیام'));

        $this->assertFalse($sync->isSystem('customer@gmail.com', 'سلام، سرور من قطع شده'));
        $this->assertFalse($sync->isSystem('customer@gmail.com', 'درباره [سرورنت] سؤال داشتم'));
    }

    public function test_system_mail_is_counted_in_the_panel_but_never_reported_to_bale(): void
    {
        $bale = $this->fakeBale();

        $this->msg(['is_system' => true, 'subject' => '[سرورنت] پرداختِ موفق', 'category' => null]);
        $this->msg(['subject' => 'Real customer question']);

        $r = app(MailboxDigest::class)->send();

        $this->assertTrue($r['sent']);
        $this->assertSame(1, $r['reported'], 'فقط نامهٔ واقعی گزارش می‌شود');
        $this->assertStringContainsString('Real customer question', $bale->sent[0]);
        $this->assertStringNotContainsString('پرداختِ موفق', $bale->sent[0]);

        // ولی در پنل هست و شمرده می‌شود
        $this->assertSame(2, MailboxMessage::count());
    }

    // ───────────── «فقط یک بار» ─────────────

    public function test_the_same_email_is_never_reported_twice(): void
    {
        $bale = $this->fakeBale();

        $this->msg();

        $first = app(MailboxDigest::class)->send();
        $second = app(MailboxDigest::class)->send();

        $this->assertTrue($first['sent']);
        $this->assertFalse($second['sent']);
        $this->assertSame('nothing_new', $second['reason']);
        $this->assertCount(1, $bale->sent);
    }

    public function test_duplicate_message_id_in_the_same_box_is_stored_once(): void
    {
        $a = MailboxMessage::hashFor('ceo', 'ABC@mail');
        $b = MailboxMessage::hashFor('ceo', 'abc@mail');
        $c = MailboxMessage::hashFor('support', 'ABC@mail');

        $this->assertSame($a, $b, 'شناسه‌ی نامه حساس به بزرگی و کوچکی نیست');
        $this->assertNotSame($a, $c, 'یک نامه در دو صندوق، دو ردیف است و این درست است');
    }

    // ───────────── «فقط چیزی که آدم می‌خواهد» ─────────────

    public function test_newsletters_are_stamped_but_never_mentioned(): void
    {
        $bale = $this->fakeBale();

        $bulk = $this->msg(['category' => 'bulk', 'needs_reply' => false, 'subject' => 'Weekly deals']);

        $r = app(MailboxDigest::class)->send();

        $this->assertFalse($r['sent'], 'گزارشِ خالی فرستاده نمی‌شود');
        $this->assertSame('nothing_worth_saying', $r['reason']);
        $this->assertCount(0, $bale->sent);

        // ولی مهر خورده‌اند، وگرنه هر اجرا دوباره بررسی می‌شوند
        $this->assertNotNull($bulk->fresh()->reported_at);
    }

    public function test_digest_separates_what_needs_a_reply_from_the_rest(): void
    {
        $bale = $this->fakeBale();

        $this->msg(['subject' => 'Urgent invoice', 'needs_reply' => true, 'importance' => 5]);
        $this->msg(['subject' => 'Receipt', 'needs_reply' => false, 'category' => 'billing']);

        app(MailboxDigest::class)->send();

        $text = $bale->sent[0];
        $this->assertStringContainsString('نیازمندِ جواب', $text);
        $this->assertStringContainsString('Urgent invoice', $text);
        $this->assertStringContainsString('بدونِ نیاز به جواب', $text);
        $this->assertStringNotContainsString('Receipt', $text, 'موردِ بی‌نیاز به جواب فقط شمرده می‌شود');
    }

    // ───────────── وقتی بله می‌میرد ─────────────

    public function test_nothing_is_stamped_when_bale_fails(): void
    {
        $this->brokenBale();

        $m = $this->msg();

        $r = app(MailboxDigest::class)->send();

        $this->assertFalse($r['sent']);
        $this->assertSame('bale_failed', $r['reason']);
        $this->assertNull($m->fresh()->reported_at, 'قطعیِ لحظه‌ای نباید یک روز ایمیل را برای همیشه ناپیدا کند');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $bale = $this->fakeBale();
        $m = $this->msg();

        $r = app(MailboxDigest::class)->send(dry: true);

        $this->assertFalse($r['sent']);
        $this->assertCount(0, $bale->sent);
        $this->assertNull($m->fresh()->reported_at);
        $this->assertStringContainsString('Question about hosting', (string) $r['reason']);
    }

    // ───────────── پارسِ IMAP ─────────────

    public function test_persian_subject_and_sender_name_are_decoded(): void
    {
        $encoded = '=?UTF-8?B?'.base64_encode('تیکت تازه از مشتری').'?=';

        $this->assertSame('تیکت تازه از مشتری', ImapClient::decodeHeader($encoded));
        $this->assertSame('ali@clinic.ae', ImapClient::addressIn('"Dr Ali" <ali@clinic.ae>'));
        $this->assertSame('Dr Ali', ImapClient::displayName('"Dr Ali" <ali@clinic.ae>'));
        $this->assertSame('ali@clinic.ae', ImapClient::addressIn('ali@clinic.ae'));
    }

    public function test_html_body_is_reduced_to_readable_text(): void
    {
        $out = ImapClient::plain(
            "Content-Type: text/html\n<style>b{color:red}</style><p>Hello</p><br>World &amp; co"
        );

        $this->assertStringContainsString('Hello', $out);
        $this->assertStringContainsString('World & co', $out);
        $this->assertStringNotContainsString('color:red', $out);
        $this->assertStringNotContainsString('Content-Type', $out);
    }

    // ───────────── پنل ─────────────

    public function test_panel_requires_an_admin(): void
    {
        $writer = User::create([
            'name' => 'نویسنده', 'email' => 'mw'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'writer',
        ]);

        $this->actingAs($writer)->get('/admin/mail')->assertForbidden();
    }

    public function test_admin_sees_the_boxes_and_can_close_a_message(): void
    {
        $admin = User::create([
            'name' => 'مدیر', 'email' => 'ma'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        $m = $this->msg();

        $this->actingAs($admin)->get('/admin/mail')
            ->assertOk()
            ->assertSee('مدیرعامل', false)
            ->assertSee('Question about hosting', false);

        $this->actingAs($admin)->post('/admin/mail/'.$m->id.'/handled')->assertRedirect();
        $this->assertNotNull($m->fresh()->handled_at);

        $this->actingAs($admin)->post('/admin/mail/'.$m->id.'/reopen')->assertRedirect();
        $this->assertNull($m->fresh()->handled_at);
    }

    public function test_bulk_close_only_touches_the_chosen_box(): void
    {
        $admin = User::create([
            'name' => 'مدیر', 'email' => 'mb'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        $ceo = $this->msg(['account' => 'ceo']);
        $support = $this->msg(['account' => 'support']);

        $this->actingAs($admin)->post('/admin/mail/clear', ['box' => 'ceo'])->assertRedirect();

        $this->assertNotNull($ceo->fresh()->handled_at);
        $this->assertNull($support->fresh()->handled_at, 'بستنِ گروهی نباید صندوقِ دیگر را لمس کند');
    }
}
