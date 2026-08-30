<?php

namespace Tests\Feature;

use App\Models\MailboxMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Mail\MailReplyDraftWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * فاز ۵ — پیش‌نویسِ پاسخِ ایمیل از داخلِ بله.
 *
 * ⚠️ مدل این‌جا **جایگزین** می‌شود، نه صدا زده. تستی که به ارائه‌دهندهٔ واقعی
 * وصل شود هم پول خرج می‌کند هم روزی که سرویس بالا نباشد قرمز می‌شود — و آن
 * قرمزی چیزی دربارهٔ کدِ ما نمی‌گوید.
 */
class BaleAdminMailDraftTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 'bot-token-123';

    private const OWNER_CHAT = '700700';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bale.token', self::BOT);
        config()->set('services.bale_safir.key', null);

        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true])]);
        Mail::fake();

        // اعتبارنامهٔ صندوقِ مدیرعامل — بی‌آن، ارسال عمداً رد می‌شود
        config()->set('mailboxes.accounts', [
            ['key' => 'ceo', 'label' => 'مدیرعامل', 'user' => 'ceo@servernet.cloud', 'pass' => 'x'],
            ['key' => 'info', 'label' => 'اطلاعات', 'user' => 'info@servernet.cloud', 'pass' => null],
        ]);

        $u = User::create([
            'name' => 'کارفرما', 'email' => 'o'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode([
            'chat_id' => self::OWNER_CHAT, 'user_id' => $u->id, 'at' => now()->toIso8601String(),
        ]));
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');
    }

    private function click(string $data): void
    {
        $this->postJson('/bale/webhook/'.substr(hash('sha256', self::BOT), 0, 32), [
            'update_id' => random_int(1, 10_000_000),
            'callback_query' => [
                'id' => 'cb'.random_int(1, 9999), 'data' => $data,
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
            ],
        ]);
    }

    private function outbox(): string
    {
        $out = '';

        foreach (Http::recorded() as [$req, ]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $out .= "\n".(string) ($req->data()['text'] ?? '');
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    private function buttonsSent(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req, ]) {
            foreach (($req->data()['reply_markup']['inline_keyboard'] ?? []) as $row) {
                foreach ($row as $b) {
                    $out[] = (string) ($b['callback_data'] ?? '');
                }
            }
        }

        return $out;
    }

    private function mail(array $over = []): MailboxMessage
    {
        static $n = 0;
        $n++;

        return MailboxMessage::create($over + [
            'account'     => 'ceo',
            'uid_hash'    => MailboxMessage::hashFor('ceo', 'm'.$n.'@x'),
            'message_id'  => 'm'.$n.'@x',
            'from_email'  => 'someone@example.com',
            'from_name'   => 'Someone',
            'subject'     => 'Question about hosting',
            'snippet'     => 'سلام، قیمتِ هاستِ لینوکسی چند است؟',
            'received_at' => now(),
            'is_system'   => false,
            'category'    => 'sales',
            'needs_reply' => true,
            'importance'  => 4,
            'summary'     => 'قیمتِ میزبانی را پرسیده',
        ]);
    }

    private function fakeWriter(?string $draft): void
    {
        $this->app->instance(MailReplyDraftWriter::class, new class($draft) extends MailReplyDraftWriter
        {
            public function __construct(private ?string $canned)
            {
                parent::__construct();
            }

            public function draft(MailboxMessage $m): ?string
            {
                return $this->canned;
            }
        });
    }

    /**
     * 🔴 کارتِ ایمیل باید **متنِ نامه** را نشان دهد.
     *
     * تا امروز از ستونِ `body_text` می‌خواند که در این جدول وجود ندارد؛
     * Eloquent برای صفتِ ناموجود null می‌دهد، پس کارت بی‌هیچ خطایی همیشه
     * بی‌متن بود و کارفرما باید صندوق را باز می‌کرد — یعنی دقیقاً همان کاری
     * که این کنسول قرار بود لازم نباشد.
     */
    public function test_the_mail_card_shows_the_actual_text(): void
    {
        $m = $this->mail();

        $this->click('v1:mv:'.$m->id);

        $this->assertStringContainsString('قیمتِ هاستِ لینوکسی چند است', $this->outbox());
    }

    public function test_a_draft_is_offered_and_shown(): void
    {
        $m = $this->mail();

        $this->click('v1:mv:'.$m->id);

        $this->assertContains('v1:me:'.$m->id, $this->buttonsSent(), 'دکمهٔ پیش‌نویس نیامد');

        $this->fakeWriter('سلام، قیمت‌ها در صفحهٔ هاست آمده است.');
        $this->click('v1:me:'.$m->id);

        $this->assertStringContainsString('قیمت‌ها در صفحهٔ هاست', $this->outbox());
    }

    /** پیش‌نویس به‌تنهایی چیزی نمی‌فرستد — تا وقتی دکمهٔ ارسال زده نشود */
    public function test_a_draft_alone_sends_nothing(): void
    {
        $m = $this->mail();

        $this->fakeWriter('متنِ پیش‌نویس');
        $this->click('v1:me:'.$m->id);

        $this->assertStringContainsString('هنوز چیزی نرفته', $this->outbox());
        $this->assertTrue((bool) $m->fresh()->needs_reply, 'نامه بی‌آنکه پاسخ برود از صف رفت');
        Mail::assertNothingSent();
    }

    /** وقتی مدل جواب نمی‌دهد، متنِ صادق می‌آید نه پیش‌نویسِ خالی */
    public function test_a_failed_model_says_so(): void
    {
        $m = $this->mail();

        $this->fakeWriter(null);
        $this->click('v1:me:'.$m->id);

        $this->assertStringContainsString('ساخته نشد', $this->outbox());
    }

    // ═══════════════ ارسالِ واقعی ═══════════════

    private function work(): void
    {
        $this->artisan('bale:work')->assertSuccessful();
    }

    /**
     * 🔴 ارسال داخلِ وب‌هوک انجام **نمی‌شود** — در صف می‌رود.
     *
     * یک اتصالِ SMTP می‌تواند از مهلتِ بله بلندتر شود؛ بله همان آپدیت را
     * دوباره می‌فرستد و نامه دو بار برای مشتری می‌رود. ایمیل برگشت ندارد.
     */
    public function test_sending_is_queued_not_run_inside_the_webhook(): void
    {
        $m = $this->mail();

        $this->fakeWriter('سلام، قیمت‌ها در صفحهٔ هاست آمده است.');
        $this->click('v1:me:' . $m->id);
        $this->click('v1:mes:' . $m->id);

        Mail::assertNothingSent();
        $this->assertNotNull(app(AdminBaleGate::class)->pendingJob(), 'کار در صف نرفت');

        $this->work();

        Mail::assertSentCount(1);
    }

    /** پاسخ از نشانیِ **همان صندوقی** می‌رود که نامه به آن رسیده */
    public function test_the_reply_is_sent_from_the_receiving_mailbox(): void
    {
        $m = $this->mail();

        $this->fakeWriter('پاسخِ ما');
        $this->click('v1:me:' . $m->id);
        $this->click('v1:mes:' . $m->id);
        $this->work();

        Mail::assertSent(\App\Mail\MailboxReplyMail::class, function ($mail) {
            return $mail->hasFrom('ceo@servernet.cloud')
                && $mail->hasTo('someone@example.com');
        });
    }

    /**
     * 🔴 صندوقِ بی‌رمز پاسخ داده نمی‌شود — و سقوطِ بی‌صدا به فرستندهٔ پیش‌فرض
     * هم نمی‌کند.
     *
     * اگر From با کاربرِ احرازشده نخوانَد، SPF/DKIM رد می‌کند و پاسخِ ما به
     * اسپم می‌رود، بی‌هیچ خطایی سمتِ ما — یعنی نامه‌ای که به‌نظر رفته و
     * کارفرما دیگر پیگیرش نمی‌شود.
     */
    public function test_a_mailbox_without_credentials_refuses_instead_of_sending(): void
    {
        $m = $this->mail(['account' => 'info']);

        $res = app(\App\Services\Mail\MailboxReplier::class)->reply($m, 'متن');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('رمزی', $res['message']);
        Mail::assertNothingSent();
        $this->assertTrue((bool) $m->fresh()->needs_reply, 'نامهٔ نفرستاده از صف بیرون رفت');
    }

    /** نامه فقط **پس از** ارسالِ موفق از صف بیرون می‌رود */
    public function test_the_mail_leaves_the_queue_only_after_a_successful_send(): void
    {
        $m = $this->mail();

        $res = app(\App\Services\Mail\MailboxReplier::class)->reply($m, 'پاسخِ ما');

        $this->assertTrue($res['ok'], $res['message']);
        $this->assertFalse((bool) $m->fresh()->needs_reply);
        $this->assertNotNull($m->fresh()->handled_at);
    }

    /** فرستندهٔ بی‌نشانیِ معتبر — هرگز تلاشِ ارسال نمی‌شود */
    public function test_an_invalid_sender_address_is_refused(): void
    {
        $m = $this->mail(['from_email' => 'not-an-email']);

        $res = app(\App\Services\Mail\MailboxReplier::class)->reply($m, 'پاسخ');

        $this->assertFalse($res['ok']);
        Mail::assertNothingSent();
    }

    /** «Re: Re:» ساخته نمی‌شود و موضوعِ خالی هم قابلِ ارسال است */
    public function test_the_subject_is_threaded_not_doubled(): void
    {
        $already = $this->mail(['subject' => 'Re: قیمت هاست']);

        app(\App\Services\Mail\MailboxReplier::class)->reply($already, 'پاسخ');

        Mail::assertSent(\App\Mail\MailboxReplyMail::class, function ($mail) {
            return ! str_contains($mail->subjectLine, 'Re: Re:')
                && str_starts_with($mail->subjectLine, 'Re: ');
        });
    }

    /** «خودم می‌نویسم» متنِ آزاد را می‌گیرد و همان را در صف می‌گذارد */
    public function test_writing_the_reply_by_hand_queues_that_text(): void
    {
        $m = $this->mail();

        $this->click('v1:mew:' . $m->id);

        $this->assertSame('mailreply:' . $m->id, app(AdminBaleGate::class)->flow());

        $this->postJson('/bale/webhook/' . substr(hash('sha256', self::BOT), 0, 32), [
            'update_id' => random_int(1, 10_000_000),
            'message' => [
                'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'],
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'text' => 'متنِ دستیِ من',
            ],
        ]);

        $job = app(AdminBaleGate::class)->pendingJob();

        $this->assertNotNull($job, 'متنِ دستی در صف نرفت');
        $this->assertSame('mail_reply', $job['verb']);
        $this->assertSame('متنِ دستیِ من', $job['args']['body']);
    }

    /**
     * 🔴 هدرهای رشته واقعاً روی نامه می‌نشینند.
     *
     * ادعای «پاسخ در همان گفتگو می‌نشیند» را فقط همین دو هدر نگه می‌دارند، و
     * نبودشان هیچ خطایی نمی‌سازد: نامه می‌رود، فقط به‌عنوان یک رشتهٔ تازه.
     * پس ادعا باید روی خودِ پیامِ ساخته‌شده سنجیده شود، نه روی نیتِ کد.
     */
    public function test_the_reply_carries_the_threading_headers(): void
    {
        $mail = new \App\Mail\MailboxReplyMail('متن', 'Re: سلام', 'abc123@mail.example');
        $mail->build();

        $email = new \Symfony\Component\Mime\Email;

        foreach ($mail->callbacks as $cb) {
            $cb($email);
        }

        $h = $email->getHeaders();

        $this->assertTrue($h->has('In-Reply-To'), 'هدرِ In-Reply-To نیامد');
        $this->assertStringContainsString('<abc123@mail.example>', $h->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString('<abc123@mail.example>', $h->get('References')->getBodyAsString());
    }

    /** نامهٔ بی‌Message-ID هم باید بی‌خطا برود — فقط بی‌نخ */
    public function test_a_mail_without_a_message_id_still_sends(): void
    {
        $mail = new \App\Mail\MailboxReplyMail('متن', 'Re: سلام', null);
        $mail->build();

        $email = new \Symfony\Component\Mime\Email;

        foreach ($mail->callbacks as $cb) {
            $cb($email);
        }

        $this->assertFalse($email->getHeaders()->has('In-Reply-To'));
    }
}
