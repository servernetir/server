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

    /**
     * 🔴 هیچ دکمهٔ «ارسال»ی وجود ندارد — و پیام هم باید صریح بگوید.
     *
     * صندوق فقط با IMAP خوانده می‌شود و مسیرِ SMTPِ پاسخ در اپ نیست. اگر
     * کارفرما خیال کند ایمیل رفته، مشتری بی‌پاسخ می‌مانَد و هیچ‌جا هم ردی
     * نیست که چرا.
     */
    public function test_the_draft_is_never_presented_as_sent(): void
    {
        $m = $this->mail();

        $this->fakeWriter('متنِ پیش‌نویس');
        $this->click('v1:me:'.$m->id);

        $out = $this->outbox();

        $this->assertStringContainsString('ارسال نمی‌شود', $out);

        foreach ($this->buttonsSent() as $d) {
            $this->assertStringNotContainsString('send', $d);
        }
    }

    /** نامه پس از پیش‌نویس هنوز در صف است — تا کارفرما خودش بایگانی کند */
    public function test_drafting_does_not_take_the_mail_out_of_the_queue(): void
    {
        $m = $this->mail();

        $this->fakeWriter('متنِ پیش‌نویس');
        $this->click('v1:me:'.$m->id);

        $this->assertTrue((bool) $m->fresh()->needs_reply, 'نامه بی‌آنکه پاسخ برود از صف رفت');
    }

    /** وقتی مدل جواب نمی‌دهد، متنِ صادق می‌آید نه پیش‌نویسِ خالی */
    public function test_a_failed_model_says_so(): void
    {
        $m = $this->mail();

        $this->fakeWriter(null);
        $this->click('v1:me:'.$m->id);

        $this->assertStringContainsString('ساخته نشد', $this->outbox());
    }
}
