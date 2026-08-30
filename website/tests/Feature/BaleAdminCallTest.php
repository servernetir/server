<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تماس با مشتری از داخلِ کنسولِ بله.
 *
 * ⚠️ ادعای مرکزی «دکمه کار می‌کند» نیست — **«به چه کسی زنگ می‌زند»** است.
 * تماس از خطِ شرکت می‌رود و پول خرج می‌کند؛ یک شمارهٔ اشتباه یعنی زنگ‌زدن به
 * غریبه با شمارهٔ ما روی کالر آی‌دی.
 */
class BaleAdminCallTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 'bot-token-123';

    private const OWNER_CHAT = '700700';

    private const RELAY = 'https://flow.servernet.cloud/webhook/cloud-phone-outgoing';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bale.token', self::BOT);
        config()->set('services.bale_safir.key', null);

        config()->set('services.cloud_phone.relay_url', self::RELAY);
        config()->set('services.cloud_phone.relay_secret', 'shared-secret-for-tests');
        config()->set('services.cloud_phone.extension', '71057757');
        config()->set('services.cloud_phone.agent_number', '09142223343');

        Http::swap(new Factory);
        Http::fake([
            self::RELAY => Http::response(['status' => 'sent'], 200),
            '*' => Http::response(['ok' => true]),
        ]);
    }

    // ───────────────────────────── داربست ─────────────────────────────

    private function hookUrl(): string
    {
        return '/bale/webhook/'.substr(hash('sha256', self::BOT), 0, 32);
    }

    private function bind(): User
    {
        $u = User::create([
            'name' => 'کارفرما', 'email' => 'o'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        Setting::putSecret(AdminBaleGate::KEY_BIND, json_encode([
            'chat_id' => self::OWNER_CHAT, 'user_id' => $u->id, 'at' => now()->toIso8601String(),
        ]));
        Setting::put(AdminBaleGate::KEY_ENABLED, '1');

        return $u;
    }

    private function click(string $data, string $from = self::OWNER_CHAT): void
    {
        $this->postJson($this->hookUrl(), [
            'update_id' => random_int(1, 10_000_000),
            'callback_query' => [
                'id' => 'cb'.random_int(1, 9999), 'data' => $data,
                'from' => ['id' => $from, 'is_bot' => false],
            ],
        ]);
    }

    private function outbox(): string
    {
        $out = '';

        foreach (Http::recorded() as [$req]) {
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

        foreach (Http::recorded() as [$req]) {
            foreach (($req->data()['reply_markup']['inline_keyboard'] ?? []) as $row) {
                foreach ($row as $b) {
                    $out[] = (string) ($b['callback_data'] ?? '');
                }
            }
        }

        return $out;
    }

    private function callButton(): ?string
    {
        foreach ($this->buttonsSent() as $d) {
            if (str_starts_with($d, 'v1:cc:')) {
                return $d;
            }
        }

        return null;
    }

    private function customer(?string $phone = '+989142223343'): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'password' => 'secret123',
            'phone' => $phone,
        ]);
    }

    /**
     * کارخانهٔ HTTP را از نو می‌سازد و استابِ تازه ثبت می‌کند.
     *
     * 🔴 درسِ ثبت‌شدهٔ همین پروژه: `Http::fake()` استاب‌ها را **ادغام** می‌کند و
     * اولین تطبیق برنده است. استابِ `'*'`ِ `setUp` هر `fake()`ِ بعدی را بی‌اثر
     * می‌کند — یعنی تستی که فکر می‌کند شکست را شبیه‌سازی کرده، در واقع مسیرِ
     * موفق را می‌سنجد و **بی‌صدا سبز** می‌ماند.
     *
     * ⚠️ ضمناً ضبط‌شده‌ها هم پاک می‌شوند، پس بعد از این فقط درخواست‌های تازه
     * دیده می‌شوند — که دقیقاً همان چیزی است که می‌خواهیم.
     */
    private function refake(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs + ['*' => Http::response(['ok' => true])]);
    }

    private function relayCalls(): array
    {
        $out = [];

        foreach (Http::recorded() as [$req]) {
            if (str_contains($req->url(), 'cloud-phone-outgoing')) {
                $out[] = $req;
            }
        }

        return $out;
    }

    // ══════════════════════════════════════════════════════════════════
    // دکمه
    // ══════════════════════════════════════════════════════════════════

    public function test_the_customer_card_offers_a_call_button(): void
    {
        $this->bind();
        $c = $this->customer();

        $this->click('v1:c:'.$c->id);

        $this->assertNotNull($this->callButton(), 'کارتِ مشتری باید دکمهٔ تماس داشته باشد');
        // ⚠️ شماره روی خودِ دکمه — کارفرما پیش از کلیک می‌بیند به چه کسی زنگ می‌زند
        $this->assertStringContainsString('989142223343', $this->buttonLabels());
    }

    private function buttonLabels(): string
    {
        $out = '';

        foreach (Http::recorded() as [$req]) {
            foreach (($req->data()['reply_markup']['inline_keyboard'] ?? []) as $row) {
                foreach ($row as $b) {
                    $out .= ' '.(string) ($b['text'] ?? '');
                }
            }
        }

        return $out;
    }

    public function test_the_ticket_card_offers_a_call_button(): void
    {
        // خواستهٔ کارفرما: «وقتی تیکتش را می‌خوانم بتوانم همان‌جا زنگ بزنم»
        $this->bind();
        $c = $this->customer();

        $t = Ticket::create([
            'customer_id' => $c->id, 'subject' => 'تست', 'status' => 'open',
            'priority' => 'normal', 'department' => 'support',
        ]);

        $this->click('v1:t:'.$t->id);

        $this->assertNotNull($this->callButton(), 'کارتِ تیکت باید دکمهٔ تماس داشته باشد');
    }

    public function test_no_button_when_the_customer_has_no_number(): void
    {
        /*
        | دکمه‌ای که کلیکش خطا بدهد، در بله بدتر از پنل است: کارفرما روی موبایل
        | است و یک پیامِ خطا یعنی باید برود سراغِ پنل تا بفهمد چه چیزی کم بوده.
        */
        $this->bind();

        $this->click('v1:c:'.$this->customer(null)->id);

        $this->assertNull($this->callButton());
    }

    public function test_no_button_when_the_number_has_no_area_code(): void
    {
        // 🔴 «34261000» شماره‌گیری‌شدنی نیست — سه شهر، سه مشتریِ متفاوت
        $this->bind();

        $this->click('v1:c:'.$this->customer('34261000')->id);

        $this->assertNull($this->callButton());
    }

    public function test_no_button_when_the_relay_is_not_configured(): void
    {
        config()->set('services.cloud_phone.relay_url', '');

        $this->bind();

        $this->click('v1:c:'.$this->customer()->id);

        $this->assertNull($this->callButton());
    }

    // ══════════════════════════════════════════════════════════════════
    // کلیک
    // ══════════════════════════════════════════════════════════════════

    public function test_clicking_places_the_call_to_the_stored_number(): void
    {
        $this->bind();
        $c = $this->customer();

        $this->click('v1:c:'.$c->id);
        $button = $this->callButton();

        $this->click($button);

        $sent = $this->relayCalls();
        $this->assertCount(1, $sent, 'دقیقاً یک تماس باید برقرار شود');

        $b64 = explode('.', substr($sent[0]['envelope'], strlen('CLOUD_PHONE_V1:')), 2)[0];
        $payload = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);

        $this->assertSame('9142223343', $payload['to_number']);
        $this->assertSame('9142223343', $payload['from_number'], 'پایی که اول زنگ می‌خورد');
        $this->assertSame('71057757', $payload['caller_extension']);

        $this->assertStringContainsString('در حالِ برقراری', $this->outbox());
    }

    public function test_a_stale_button_does_not_place_a_call(): void
    {
        /*
        | 🔴 دکمه‌ها در تاریخچهٔ چت می‌مانند. کلیکِ روی کارتِ سه‌ماه‌پیش نباید
        | امروز به مشتری زنگ بزند.
        */
        $this->bind();
        $c = $this->customer();

        $this->click('v1:cc:'.$c->id.':thisisnotavalidstamp');

        $this->assertCount(0, $this->relayCalls());
        $this->assertStringContainsString('کهنه', $this->outbox());
    }

    public function test_an_unbound_chat_cannot_place_a_call(): void
    {
        /*
        | 🔴 مهم‌ترین ادعای امنیتیِ این فایل.
        |
        | آدرسِ وب‌هوکِ بله در لاگِ سرور و Cloudflare می‌نشیند و قابلِ چرخاندن
        | نیست. اگر لو برود، دارنده‌اش نباید بتواند از خطِ شرکت زنگ بزند.
        */
        $this->bind();
        $c = $this->customer();

        $this->click('v1:c:'.$c->id);
        $button = $this->callButton();

        $this->refake([self::RELAY => Http::response(['status' => 'sent'], 200)]);

        $this->click($button, from: '999999');   // چتِ غریبه

        $this->assertCount(0, $this->relayCalls(), 'چتِ نامتصل نباید بتواند تماس بگیرد');
    }

    public function test_an_unreadable_relay_response_is_reported_as_maybe_not_as_failure(): void
    {
        /*
        | ⚠️ همان قاعدهٔ پنل: «نمی‌دانم» نباید شبیهِ شکست گزارش شود. یک بار همین
        | باعث شد بگوییم تماس نرفته در حالی که تلفن زنگ خورده بود.
        */
        $this->bind();
        $c = $this->customer();

        $this->click('v1:c:'.$c->id);
        $button = $this->callButton();

        $this->refake([self::RELAY => Http::response('OK', 200)]);

        $this->click($button);

        $out = $this->outbox();
        $this->assertStringContainsString('ممکن است تماس برقرار شده باشد', $out);
        $this->assertStringNotContainsString('تماس برقرار نشد', $out);
    }

    public function test_an_explicit_relay_failure_is_reported_as_failure(): void
    {
        $this->bind();
        $c = $this->customer();

        $this->click('v1:c:'.$c->id);
        $button = $this->callButton();

        $this->refake([self::RELAY => Http::response(['status' => 'ignored', 'reason' => 'bad_signature'], 200)]);

        $this->click($button);

        $this->assertStringContainsString('تماس برقرار نشد', $this->outbox());
    }
}
