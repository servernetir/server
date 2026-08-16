<?php

namespace Tests\Feature;

use App\Models\MailboxMessage;
use App\Models\User;
use App\Mail\MailboxReplyMail;
use App\Models\CalendarEvent;
use App\Services\Mail\MailboxReader;
use App\Services\Mail\MailHtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
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

        config(['mailboxes.signature' => 'سرورنت']);

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

            /** @var list<array{id:int,kind:string}> */
            public static array $moved = [];

            public bool $moveOk = true;

            public function move(MailboxMessage $m, string $kind): array
            {
                self::$moved[] = ['id' => $m->id, 'kind' => $kind];

                return $this->moveOk
                    ? ['ok' => true, 'message' => 'رفت.']
                    : ['ok' => false, 'message' => 'سرور جابه‌جایی را نپذیرفت. نامه سرِ جایش است.'];
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
        // ⚠️ ادعا روی خودِ فرم است نه واژهٔ «بفرست» — آن واژه در متنِ نمونهٔ
        // یادآوری هم هست و تست را به‌خاطرِ چیزی بی‌ربط قرمز می‌کرد.
        $r->assertDontSee('id="mail-reply"', false);
    }

    /** ⚠️ ولی جیمیل SMTPِ صریح دارد، پس باید بتواند. */
    public function test_gmail_can_reply_because_its_smtp_is_declared(): void
    {
        $this->fakeReader($this->mail());

        $m = $this->msg(['account' => 'gmail', 'uid_hash' => MailboxMessage::hashFor('gmail', 'g@x')]);

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$m->id);

        $r->assertOk();
        $r->assertSee('id="mail-reply"', false);
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

    // ───────────────────────── ادیتور و پیوست ─────────────────────────

    /**
     * 🔴 HTMLِ فرمِ خودمان هم پاک‌سازی می‌شود.
     *
     * `contenteditable` آشغالِ خودش را می‌سازد، و مهم‌تر: یک POSTِ دستی
     * می‌تواند هرچه بخواهد در آن فیلد بگذارد. «فرمِ خودمان است» همان جمله‌ای
     * است که XSS از آن وارد می‌شود.
     */
    public function test_the_composed_reply_is_sanitized_before_it_leaves(): void
    {
        Mail::fake();
        $m = $this->msg();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/mail/'.$m->id.'/reply', [
                'body' => '<p>سلام <b>دوست</b></p><script>steal()</script><img src=x onerror="bad()">',
            ]);

        Mail::assertSent(MailboxReplyMail::class, function (MailboxReplyMail $mail) {
            $this->assertStringNotContainsString('<script', (string) $mail->bodyHtml);
            $this->assertStringNotContainsString('onerror', (string) $mail->bodyHtml);
            $this->assertStringContainsString('<b>دوست</b>', (string) $mail->bodyHtml);

            return true;
        });
    }

    /** نسخهٔ متنی از خودِ HTML ساخته می‌شود — کاربر هرگز دو کادر پر نمی‌کند. */
    public function test_a_plain_text_part_is_derived_from_the_html(): void
    {
        Mail::fake();
        $m = $this->msg();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/mail/'.$m->id.'/reply', ['body' => '<p>خط یک</p><p>خط دو</p>']);

        Mail::assertSent(MailboxReplyMail::class, function (MailboxReplyMail $mail) {
            $this->assertStringContainsString('خط یک', $mail->bodyText);
            $this->assertStringContainsString('خط دو', $mail->bodyText);
            $this->assertStringNotContainsString('<p>', $mail->bodyText);

            return true;
        });
    }

    /** HTMLی که بعد از پاک‌سازی هیچ متنی ندارد، نباید نامهٔ خالی بفرستد. */
    public function test_markup_with_no_text_is_refused(): void
    {
        Mail::fake();
        $m = $this->msg();

        $this->actingAs($this->admin(), 'web')
            ->from('/admin/mail/'.$m->id)
            ->post('/admin/mail/'.$m->id.'/reply', ['body' => '<p><br></p><div>   </div>'])
            ->assertSessionHasErrors('body');

        Mail::assertNothingSent();
    }

    public function test_an_attachment_rides_along_with_the_reply(): void
    {
        Mail::fake();
        $m = $this->msg();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/mail/'.$m->id.'/reply', [
                'body'  => '<p>فاکتور پیوست است</p>',
                // ⚠️ `create()` فایلِ **خالی** می‌سازد و فقط اندازه را دروغ می‌گوید؛
                // با آن، این تست چیزی را می‌سنجید که هرگز پیوست نمی‌شد.
                'files' => [UploadedFile::fake()->createWithContent('invoice.pdf', '%PDF-1.4 fake')],
            ]);

        Mail::assertSent(MailboxReplyMail::class, function (MailboxReplyMail $mail) {
            $this->assertCount(1, $mail->files);
            $this->assertSame('invoice.pdf', $mail->files[0]['name']);

            return true;
        });
    }

    /**
     * ⚠️ ردِ پسوندِ اجرایی به‌خاطرِ **گیرنده** است نه ما: جیمیل نامه‌ای با
     * `.exe` را کامل رد می‌کند، پس کلِ پاسخ گم می‌شود نه فقط پیوستش.
     */
    public function test_an_executable_attachment_is_refused_with_a_reason(): void
    {
        Mail::fake();
        $m = $this->msg();

        $this->actingAs($this->admin(), 'web')
            ->from('/admin/mail/'.$m->id)
            ->post('/admin/mail/'.$m->id.'/reply', [
                'body'  => '<p>سلام</p>',
                'files' => [UploadedFile::fake()->createWithContent('setup.exe', 'MZ')],
            ])
            ->assertSessionHasErrors('files');

        Mail::assertNothingSent();
    }

    // ───────────────────────── حذف و اسپم ─────────────────────────

    /**
     * 🔴 «حذف» یعنی سطلِ زباله. اگر روزی این تست به `expunge` تغییر کند،
     * یعنی وعده‌ای که روی دکمه به کاربر داده‌ایم شکسته شده.
     */
    public function test_delete_means_trash_and_the_row_stays(): void
    {
        $this->fakeReader($this->mail());
        $m = $this->msg();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/mail/'.$m->id.'/move/trash')
            ->assertRedirect('/admin/mail?box=support');

        $this->assertSame('trash', $this->lastMoved()['kind']);
        $this->assertNotNull($m->fresh()->handled_at);
        $this->assertNotNull(MailboxMessage::find($m->id), 'ردیف نباید پاک شود وگرنه sync دوباره می‌آوردش');
    }

    public function test_marking_junk_also_files_it_as_spam(): void
    {
        $this->fakeReader($this->mail());
        $m = $this->msg(['needs_reply' => true]);

        $this->actingAs($this->admin(), 'web')->post('/admin/mail/'.$m->id.'/move/junk');

        $fresh = $m->fresh();
        $this->assertSame('junk', $this->lastMoved()['kind']);
        $this->assertSame('spam', $fresh->category);
        $this->assertFalse((bool) $fresh->needs_reply);
    }

    /** اگر سرور جابه‌جایی را نپذیرفت، ردیف نباید «رسیدگی‌شده» بخورد. */
    public function test_a_failed_move_changes_nothing(): void
    {
        $this->fakeReader($this->mail());
        app(MailboxReader::class)->moveOk = false;

        $m = $this->msg();

        $this->actingAs($this->admin(), 'web')
            ->from('/admin/mail/'.$m->id)
            ->post('/admin/mail/'.$m->id.'/move/trash')
            ->assertRedirect('/admin/mail/'.$m->id);

        $this->assertNull($m->fresh()->handled_at);
    }

    public function test_an_unknown_destination_is_refused(): void
    {
        $this->fakeReader($this->mail());

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/mail/'.$this->msg()->id.'/move/nowhere')
            ->assertNotFound();
    }

    // ───────────────────────── یادآوری ─────────────────────────

    public function test_a_reminder_lands_in_the_business_calendar(): void
    {
        $m = $this->msg(['subject' => 'پیشنهاد همکاری']);

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/mail/'.$m->id.'/remind', ['when' => 'three_days', 'note' => 'قیمت بفرست']);

        $e = CalendarEvent::latest('id')->first();

        $this->assertNotNull($e);
        $this->assertSame('task', $e->type);
        $this->assertStringContainsString('پیشنهاد همکاری', $e->title);
        $this->assertStringContainsString('قیمت بفرست', $e->description);
        $this->assertSame(now()->addDays(3)->toDateString(), $e->event_date->toDateString());
        $this->assertSame($m->id, $e->meta['mailbox_message_id'] ?? null);
    }

    /**
     * 🔴 متنِ نامه نباید در تقویم کپی شود — همان دادهٔ مشتری که عمداً در
     * دیتابیس نگه نمی‌داریم، نباید از درِ پشتی وارد شود.
     */
    public function test_a_reminder_never_copies_the_message_body(): void
    {
        $m = $this->msg(['snippet' => 'شمارهٔ کارت من ۶۰۳۷۹۹۱۱۱۱۱۱۱۱۱۱ است']);

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/mail/'.$m->id.'/remind', ['when' => 'tomorrow']);

        $e = CalendarEvent::latest('id')->first();

        $this->assertStringNotContainsString('۶۰۳۷۹۹', (string) $e->description);
    }

    public function test_an_unknown_reminder_window_is_refused(): void
    {
        $m = $this->msg();

        $this->actingAs($this->admin(), 'web')
            ->from('/admin/mail/'.$m->id)
            ->post('/admin/mail/'.$m->id.'/remind', ['when' => 'never'])
            ->assertSessionHasErrors('when');

        $this->assertSame(0, CalendarEvent::count());
    }

    // ───────────────────────── ناوبری ─────────────────────────

    /**
     * ⚠️ «بعدی» یعنی قدیمی‌تر. ترتیب با `id` شکسته می‌شود چون دو نامه
     * می‌توانند دقیقاً یک ثانیه `received_at` داشته باشند و بی‌آن، ناوبری
     * بینِ همان دو تا حلقه می‌زد.
     */
    public function test_navigation_points_at_the_neighbours_in_the_same_box(): void
    {
        $this->fakeReader($this->mail());

        $old = $this->msg(['received_at' => now()->subDays(2)]);
        $mid = $this->msg(['received_at' => now()->subDay()]);
        $new = $this->msg(['received_at' => now()]);

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$mid->id);

        $r->assertOk();
        $r->assertSee('/admin/mail/'.$old->id, false);
        $r->assertSee('/admin/mail/'.$new->id, false);
    }

    public function test_other_mail_from_the_same_sender_is_linked(): void
    {
        $this->fakeReader($this->mail());

        $this->msg(['from_email' => 'repeat@example.test']);
        $m = $this->msg(['from_email' => 'repeat@example.test']);

        $r = $this->actingAs($this->admin(), 'web')->get('/admin/mail/'.$m->id);

        $r->assertOk();
        $r->assertSee('نامهٔ دیگر از این فرستنده');
        $r->assertSee('from='.urlencode('repeat@example.test'), false);
    }

    /** @return array{id:int,kind:string} */
    private function lastMoved(): array
    {
        $moved = app(MailboxReader::class)::$moved;

        $this->assertNotEmpty($moved, 'هیچ جابه‌جایی روی صندوق انجام نشد');

        return end($moved);
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
