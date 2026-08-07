<?php

namespace Tests\Feature;

use App\Models\BaleContact;
use App\Models\SmsOutbox;
use App\Services\Bale\BaleNotifier;
use App\Services\Bale\BaleSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * بله — کانال دوم موازی پیامک.
 *
 * محورها:
 *   • بدون chat_id (کاربر بله را وصل نکرده) هیچ اتفاقی نمی‌افتد
 *   • آلمان اول؛ اگر موفق شد صفی برای ایران ساخته نمی‌شود
 *   • آلمان اگر نشد، ردیف فقط‌بله برای ایران صف می‌شود
 *   • وب‌هوک، contact را به نگاشت شماره→chat_id تبدیل می‌کند
 */
class BaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.bale.token' => 'bot-token-123']);
    }

    /**
     * ⚠️ سفیر عمداً **خاموش** است در این فایل.
     *
     * این تست‌ها مسیرِ قدیمیِ `chat_id` را می‌سنجند (آلمانِ مستقیم، و صف برای
     * ایران). با سفیرِ روشن، هیچ‌کدام از آن شاخه‌ها اجرا نمی‌شوند چون سفیر
     * اول امتحان می‌شود و موفق برمی‌گردد — یعنی تست سبز می‌مانْد بی‌آنکه چیزی
     * از مسیرِ پشتیبان بسنجد. رفتارِ خودِ سفیر در `BaleSafirTest` سنجیده می‌شود.
     */
    private function notifier(): BaleNotifier
    {
        return new BaleNotifier(
            new BaleSender('bot-token-123'),
            new \App\Services\Bale\BaleSafirSender(null, null),
        );
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_no_chat_id_means_no_send_and_no_queue(): void
    {
        Http::fake();   // نباید اصلاً تماسی گرفته شود

        $this->notifier()->notify('09121234567', 'کد شما ۱۲۳۴۵۶');

        Http::assertNothingSent();
        $this->assertSame(0, SmsOutbox::count());
    }

    public function test_germany_direct_send_when_it_succeeds_does_not_queue_for_iran(): void
    {
        BaleContact::create(['mobile' => '09121234567', 'chat_id' => '55501', 'linked_at' => now()]);
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->notifier()->notify('09121234567', 'کد شما ۱۲۳۴۵۶');

        Http::assertSent(function ($req) {
            $this->assertStringContainsString('sendMessage', $req->url());
            $this->assertSame('55501', $req->data()['chat_id']);
            $this->assertStringContainsString('۱۲۳۴۵۶', $req->data()['text']);

            return true;
        });
        // آلمان موفق شد، پس هیچ صفی برای ایران نیست
        $this->assertSame(0, SmsOutbox::count());
    }

    public function test_germany_failure_queues_a_bale_only_row_for_iran(): void
    {
        BaleContact::create(['mobile' => '09121234567', 'chat_id' => '55502', 'linked_at' => now()]);
        // بله از آلمان رد می‌کند
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => false, 'description' => 'blocked'], 403)]);

        $this->notifier()->notify('09121234567', 'کد شما ۱۲۳۴۵۶');

        $row = SmsOutbox::firstOrFail();
        $this->assertSame('bale_only', $row->event);
        $this->assertSame('55502', $row->bale_chat_id);
        $this->assertStringContainsString('۱۲۳۴۵۶', $row->body);
    }

    public function test_a_network_error_never_throws(): void
    {
        BaleContact::create(['mobile' => '09121234567', 'chat_id' => '55503', 'linked_at' => now()]);
        Http::fake(fn () => throw new \RuntimeException('down'));

        // نباید استثنا بیرون بدهد — best-effort
        $this->notifier()->notify('09121234567', 'متن');

        // شبکه قطع = آلمان نشد = صف برای ایران
        $this->assertSame(1, SmsOutbox::where('event', 'bale_only')->count());
    }

    // ── وب‌هوک ──

    private function hookUrl(): string
    {
        return '/bale/webhook/'.substr(hash('sha256', 'bot-token-123'), 0, 32);
    }

    public function test_webhook_rejects_a_wrong_token(): void
    {
        $this->postJson('/bale/webhook/'.str_repeat('0', 32), [])->assertNotFound();
    }

    public function test_webhook_links_a_shared_contact(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true])]);

        $this->postJson($this->hookUrl(), [
            'message' => [
                'chat' => ['id' => 99900],
                'from' => ['id' => 99900],
                'contact' => ['phone_number' => '989121234567', 'first_name' => 'علی'],
            ],
        ])->assertOk();

        $c = BaleContact::where('mobile', '09121234567')->firstOrFail();
        $this->assertSame('99900', $c->chat_id);
        $this->assertSame('علی', $c->name);
    }

    public function test_webhook_on_start_prompts_for_contact(): void
    {
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true])]);

        $this->postJson($this->hookUrl(), [
            'message' => ['chat' => ['id' => 88800], 'from' => ['id' => 88800], 'text' => '/start'],
        ])->assertOk();

        // دکمهٔ request_contact فرستاده می‌شود، نگاشتی ساخته نمی‌شود
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'request_contact'));
        $this->assertSame(0, BaleContact::count());
    }

    public function test_otp_send_also_notifies_bale_when_linked(): void
    {
        BaleContact::create(['mobile' => '09121234567', 'chat_id' => '77701', 'linked_at' => now()]);
        Http::fake(['tapi.bale.ai/*' => Http::response(['ok' => true])]);

        // فرستندهٔ پیامک را جعلی کن تا واقعی نرود
        $this->app->instance(\App\Services\Sms\SmsSender::class, new class implements \App\Services\Sms\SmsSender {
            public function enabled(): bool { return true; }
            public function name(): string { return 'fake'; }
            public function send(string $m, string $t): bool { return true; }
            public function sendOtp(string $m, string $c): bool { return true; }
        });

        app(\App\Services\Otp\OtpService::class)->issue('sms', '09121234567', 'register', '1.2.3.4');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'sendMessage') && $req->data()['chat_id'] === '77701');
    }
}
