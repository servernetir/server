<?php

namespace Tests\Feature;

use App\Models\MailboxMessage;
use App\Models\User;
use App\Services\Mail\MailboxReader;
use App\Services\Mail\MailHtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خواندنِ نامه و پاسخ از داخلِ پنل.
 *
 * ⚠️ هیچ تستی این‌جا به IMAP وصل نمی‌شود. `MailboxReader` با یک بدل جایگزین
 * می‌شود، چون چیزی که واقعاً می‌تواند بشکند بالای آن لایه است: پاک‌سازیِ HTML،
 * بستنِ تصویرِ ردیاب، هدرهای دانلود، و اینکه چه کسی اجازهٔ دیدن دارد.
 *
 * 🔴 محورها همه از خواسته‌های صریح‌اند، به‌جز یکی: «نامهٔ خصمانه نباید پنل را
 * بردارد». آن را کسی نخواست ولی اگر بشکند، بدترین حالتِ ممکنِ این صفحه است —
 * چون متنش را کسی می‌نویسد که ما نمی‌شناسیم.
 */
class MailboxReadReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mailboxes.accounts' => [
            [
                'key' => 'support', 'label' => 'پشتیبانی',
                'user' => 'support@servernet.cloud', 'pass' => 'x',
            ],
            [
                // روی سرورِ دیگر، **با** SMTPِ صریح ⇒ باید بتواند پاسخ بدهد
                'key' => 'gmail', 'label' => 'جیمیل',
                'user' => 'me@gmail.com', 'pass' => 'x',
                'host' => 'imap.gmail.com', 'port' => 993,
                'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 465, 'smtp_scheme' => 'smtps',
            ],
            [
                // روی سرورِ دیگر، **بی** SMTP ⇒ نباید دکمهٔ پاسخ بگیرد
                'key' => 'foreign', 'label' => 'بیگانه',
                'user' => 'x@other.test', 'pass' => 'x',
                'host' => 'imap.other.test', 'port' => 993,
            ],
        ]]);
    }

    private ?User $boss = null;

    /** ⚠️ یک‌بار ساخته می‌شود: دو بار صدا زدنش در یک تست، ایمیلِ یکتا را می‌شکست. */
    private function admin(): User
    {
        return $this->boss ??= User::create([
            'name' => 'Boss', 'email' => 'boss@servernet.cloud',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    private function msg(array $attrs = []): MailboxMessage
    {
        static $n = 0;
        $n++;

        return MailboxMessage::create($attrs + [
            'account'     => 'support',
            'uid_hash'    => MailboxMessage::hashFor('support', 'r'.$n.'@x'),
            'message_id'  => 'r'.$n.'@x',
            'from_email'  => 'client@example.test',
            'from_name'   => 'Client',
            'subject'     => 'Invoice question',
            'snippet'     => 'Hello',
            'received_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $mail */
    private function fakeReader(array $mail, bool $ok = true, bool $truncated = false): void
    {
        $this->instance(MailboxReader::class, new class($mail, $ok, $truncated) extends MailboxReader
        {
            public function __construct(private array $mail, private bool $okFlag, private bool $cut) {}

            public function read(MailboxMessage $m, bool $withAttachmentData = false): array
            {
                return [
                    'ok'        => $this->okFlag,
                    'message'   => $this->okFlag ? '' : 'این نامه دیگر در صندوق نیست.',
                    'mail'      => $this->okFlag ? $this->mail : null,
                    'size'      => 2048,
                    'truncated' => $this->cut,
                ];
            }

            public function attachment(MailboxMessage $m, int $index): array
            {
                if ($this->cut) {
                    return ['ok' => false, 'message' => 'ناقص است.', 'attachment' => null];
                }

                $a = $this->mail['attachments'][$index] ?? null;

                return $a === null
                    ? ['ok' => false, 'message' => 'این پیوست در نامه نیست.', 'attachment' => null]
                    : ['ok' => true, 'message' => '', 'attachment' => $a];
            }
        });
    }

    /** @return array<string,mixed> */
    private function mail(string $html = '', string $text = 'plain body', array $attachments = []): array
    {
        return [
            'headers' => [], 'subject' => 'Invoice question', 'from' => 'Client <client@example.test>',
            'to' => '', 'date' => '', 'message_id' => '<r@x>', 'in_reply_to' => '', 'references' => '',
            'text' => $text, 'html' => $html, 'attachments' => $attachments,
        ];
    }

    // ───────────────────────── دسترسی ─────────────────────────

    public function test_a_guest_cannot_read_a_message(): void
    {
        $m = $this->msg();

        $this->get('/admin/mail/'.$m->id)->assertRedirect();
        $this->get('/admin/mail/'.$m->id.'/attachment/0')->assertRedirect();
        $this->post('/admin/mail/'.$m->id.'/reply', ['body' => 'hi'])->assertRedirect();
    }

    /**
     * ⚠️ نویسندهٔ بلاگ کاربرِ واردشدهٔ همین پنل است و سایدبار را می‌بیند.
     * صندوقِ support@ پر از دادهٔ مشتری است، پس `auth` به‌تنهایی کافی نیست.
     */
    public function test_a_non_admin_user_is_refused(): void
    {
        $author = User::create([
            'name' => 'Writer', 'email' => 'w@servernet.cloud',
            'password' => bcrypt('x'), 'role' => 'author',
        ]);

        $m = $this->msg();

        $this->actingAs($author, 'web')->get('/admin/mail/'.$m->id)->assertForbidden();
    }

    // ───────────────────────── تصویرِ ردیاب ─────────────────────────

    /**
     * 🔴 مهم‌ترین ادعای این صفحه: تصویرِ بیرونی **بی‌درخواستِ صریح** بارگذاری
     * نمی‌شود. اگر بشود، هر باز کردنِ یک اسپم به فرستنده می‌گوید نشانی زنده است.
     */
    public function test_a_remote_image_is_not_loaded_until_the_user_asks(): void
    {
        $this->fakeReader($this->mail('<p>hi</p><img src="https://tracker.test/p.gif?u=9">'));
        $m = $this->msg();

        $off = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$m->id);
        $off->assertOk();
        $off->assertDontSee('tracker.test', false);
        $off->assertSee('تصاویر را نشان بده');

        $on = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$m->id.'?images=1');
        $on->assertOk();
        $on->assertSee('tracker.test/p.gif?u=9', false);
    }

    /**
     * 🔴 نامهٔ خصمانه: نه اسکریپت، نه `onerror`، نه `javascript:`.
     *
     * ادعا روی **خروجیِ واقعیِ صفحه** است، نه روی نیتِ پاک‌سازی‌کننده — چون
     * چیزی که به مرورگرِ مدیر می‌رسد همین است.
     */
    public function test_a_hostile_message_cannot_inject_anything(): void
    {
        $this->fakeReader($this->mail(
            '<p onclick="steal()">x</p><script>alert(1)</script>'
            .'<img src="x" onerror="alert(2)"><a href="javascript:alert(3)">click</a>'
        ));

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$this->msg()->id.'?images=1');

        $r->assertOk();

        /*
        | ⚠️ ادعا روی **بارِ مخرب** است نه روی رشته‌های عمومی: نسخهٔ اولِ این
        | تست `<script>` را می‌سنجید و روی خودِ لایوتِ پنل قرمز شد — لایوت
        | اسکریپتِ خودش را دارد. تستی که به‌خاطرِ چیزی بی‌ربط بشکند، همان
        | تستی است که فردا با `assertDontSee` کمتری «تعمیر» می‌شود.
        */
        $r->assertDontSee('alert(1)', false);
        $r->assertDontSee('alert(2)', false);
        $r->assertDontSee('alert(3)', false);
        $r->assertDontSee('steal()', false);
        $r->assertDontSee('onerror=', false);
        $r->assertDontSee('javascript:alert', false);
    }

    /** لینکِ نامه باید بی‌ارجاع و با noopener باز شود. */
    public function test_links_in_a_message_are_hardened(): void
    {
        $out = MailHtmlSanitizer::clean('<a href="https://x.test">go</a>', true);

        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $out['html']);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $out['html']);
    }

    /**
     * ⚠️ `HtmlSanitizer` تنها، `cid:` را می‌انداخت و لوگوی هر نامه بی‌صدا گم
     * می‌شد. این تست همان رگرسیون را قفل می‌کند.
     */
    public function test_an_inline_image_survives_the_shared_sanitizer(): void
    {
        $out = MailHtmlSanitizer::clean(
            '<img src="cid:logo@x">',
            true,
            fn (string $cid): string => '/admin/mail/1/attachment/0?inline=1&cid='.$cid,
        );

        $this->assertStringContainsString('/admin/mail/1/attachment/0', $out['html']);
        $this->assertSame(0, $out['blocked']);
    }

    // ───────────────────────── پیوست ─────────────────────────

    /**
     * 🔴 فایلِ فرستندهٔ ناشناس هرگز `inline` روی دامنهٔ پنل سرو نمی‌شود، و
     * نوعش هم به مرورگر واگذار نمی‌شود.
     */
    public function test_an_attachment_downloads_and_never_renders_in_the_panel(): void
    {
        $this->fakeReader($this->mail(attachments: [[
            'name' => 'evil.html', 'mime' => 'text/html', 'size' => 5, 'cid' => '', 'data' => '<b>x</b>',
        ]]));

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$this->msg()->id.'/attachment/0');

        $r->assertOk();
        $r->assertHeader('Content-Type', 'application/octet-stream');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('attachment;', $r->headers->get('Content-Disposition'));
    }

    /** نامِ فایل نباید بتواند از هدر بیرون بزند. */
    public function test_a_hostile_filename_cannot_break_the_header(): void
    {
        $this->fakeReader($this->mail(attachments: [[
            'name' => "a\r\nX-Evil: 1\".pdf", 'mime' => 'application/pdf', 'size' => 3, 'cid' => '', 'data' => 'abc',
        ]]));

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$this->msg()->id.'/attachment/0');

        $r->assertOk();
        $r->assertHeaderMissing('X-Evil');
        $this->assertStringNotContainsString("\n", (string) $r->headers->get('Content-Disposition'));
    }

    /**
     * ⚠️ نامهٔ بریده پیوستِ ناقص دارد. فایلِ نصفه‌ای که بی‌صدا دانلود شود بدتر
     * از دانلودنشدن است.
     */
    public function test_a_truncated_message_offers_no_attachment_download(): void
    {
        $this->fakeReader($this->mail(attachments: [[
            'name' => 'big.zip', 'mime' => 'application/zip', 'size' => 9, 'cid' => '', 'data' => '123456789',
        ]]), truncated: true);

        $m = $this->msg();
        $page = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$m->id);

        $page->assertOk();
        $page->assertSee('ناقص');
        $page->assertDontSee('/admin/mail/'.$m->id.'/attachment/0', false);

        $this->actingAs($this->admin(), 'web')
            ->get('/admin/mail/'.$m->id.'/attachment/0')
            ->assertNotFound();
    }

    // ───────────────────────── پاسخ ─────────────────────────

    /** نامه‌ای که بدنه‌اش نیامد، باز هم باید سرآیند و فرمِ پاسخ داشته باشد. */
    public function test_a_message_that_cannot_be_fetched_still_shows_its_headers(): void
    {
        $this->fakeReader([], ok: false);

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$this->msg()->id);

        $r->assertOk();
        $r->assertSee('Invoice question');
        $r->assertSee('متنِ این نامه خوانده نشد');
        $r->assertSee('بفرست');
    }

    /**
     * 🔴 صندوقی که نمی‌تواند از نشانیِ خودش بفرستد، **دکمه نمی‌گیرد** — نه
     * اینکه دکمه بگیرد و بعد خطا بدهد. کاربر متن را دو بار نمی‌نویسد.
     */
    public function test_a_foreign_mailbox_without_smtp_gets_no_reply_box(): void
    {
        $this->fakeReader($this->mail());

        $m = $this->msg(['account' => 'foreign', 'uid_hash' => MailboxMessage::hashFor('foreign', 'f@x')]);

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$m->id);

        $r->assertOk();
        $r->assertSee('SMTPش تعریف نشده');
        $r->assertDontSee('بفرست');
    }

    /** ⚠️ ولی جیمیل SMTPِ صریح دارد، پس باید بتواند. */
    public function test_gmail_can_reply_because_its_smtp_is_declared(): void
    {
        $this->fakeReader($this->mail());

        $m = $this->msg(['account' => 'gmail', 'uid_hash' => MailboxMessage::hashFor('gmail', 'g@x')]);

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$m->id);

        $r->assertOk();
        $r->assertSee('بفرست');
    }

    /** فرستندهٔ بی‌نشانیِ معتبر: پاسخ ممکن نیست و همان‌جا گفته می‌شود. */
    public function test_a_message_from_an_invalid_address_cannot_be_replied_to(): void
    {
        $this->fakeReader($this->mail());

        $m = $this->msg(['from_email' => 'not-an-address']);

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$m->id);

        $r->assertOk();
        $r->assertSee('نشانیِ فرستندهٔ این نامه معتبر نیست');
    }

    public function test_an_empty_reply_is_refused(): void
    {
        $m = $this->msg();

        $this->actingAs($this->admin(), 'web')
            ->from('/admin/mail/'.$m->id)
            ->post('/admin/mail/'.$m->id.'/reply', ['body' => ''])
            ->assertRedirect('/admin/mail/'.$m->id)
            ->assertSessionHasErrors('body');
    }
}
