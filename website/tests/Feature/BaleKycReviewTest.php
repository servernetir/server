<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerProfile;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Customer\IranSalesGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بررسیِ KYC و تلاشِ دوبارهٔ تحویل از داخلِ بله.
 *
 * ═══ چرا (۶ شهریور ۱۴۰۵) ═══
 *
 * کارفرما: «در ادامهٔ همان اعلانِ "نیازمندِ تأیید" دکمهٔ مشاهدهٔ مدارک و
 * تأیید و رد باشد؛ لینکِ آخرِ اعلان‌ها به کارِ من نمی‌آید، دکمهٔ پروفایلِ
 * مشتری بیاور؛ و روی اعلانِ شکستِ تحویل دکمهٔ تلاشِ دوباره باشد.»
 */
class BaleKycReviewTest extends TestCase
{
    use RefreshDatabase;

    private const BOT = 'bot-token-123';

    private const OWNER_CHAT = '700700';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bale.token', self::BOT);
        config()->set('services.bale_safir.key', null);
        config()->set('servernet.contact.email', '');
        config()->set('servernet.contact.notify_chat_id', self::OWNER_CHAT);

        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 10]])]);

        Storage::fake('local');
    }

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

    private function foreigner(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'f'.random_int(1, 999999).'@example.com',
            'phone' => '+90532'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'en',
        ]);
    }

    /** پروفایلِ pending با مدارکِ واقعی — از خودِ فرمِ KYC، نه دست‌ساز */
    private function pendingProfile(?Customer $c = null): CustomerProfile
    {
        $c = $c ?? $this->foreigner();

        $this->actingAs($c, 'customer')->post('/en/account/verify', [
            'type' => 'individual',
            'first_name' => 'Mehmet', 'last_name' => 'Yilmaz',
            'birth_date' => '1990-04-12', 'country' => 'TR',
            'address' => 'Bagdat Cd. 1', 'city' => 'Istanbul', 'id_type' => 'passport',
            'doc_passport' => UploadedFile::fake()->create('passport.pdf', 60, 'application/pdf'),
            'doc_selfie'   => UploadedFile::fake()->image('selfie.jpg'),
            'doc_address'  => UploadedFile::fake()->image('bill.png'),
        ])->assertSessionHasNoErrors();

        auth('customer')->logout();

        return CustomerProfile::where('customer_id', $c->id)->firstOrFail();
    }

    private function tap(string $data, int $messageId = 55): void
    {
        $this->postJson($this->hookUrl(), [
            'update_id' => random_int(1, 10_000_000),
            'callback_query' => [
                'id' => 'cb'.random_int(1, 99999), 'data' => $data,
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'message' => ['message_id' => $messageId, 'text' => 'کارت'],
            ],
        ])->assertOk();
    }

    private function say(string $text): void
    {
        $this->postJson($this->hookUrl(), [
            'update_id' => random_int(1, 10_000_000),
            'message' => [
                'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'],
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'text' => $text,
            ],
        ])->assertOk();
    }

    private function stamp(string $verb, int $id): string
    {
        return app(AdminBaleGate::class)->stamp($verb.':'.$id);
    }

    private function work(): void
    {
        $this->artisan('bale:work')->assertSuccessful();
    }

    /** همهٔ بدنه‌های sendMessage — متن و کیبورد، هر دو JSONِ ثبت‌شده */
    private function outboxRaw(): string
    {
        $out = '';

        foreach (Http::recorded() as [$req, ]) {
            if (str_contains($req->url(), '/sendMessage')) {
                $out .= "\n".json_encode($req->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $out;
    }

    // ═══════════════ اعلان با دکمه ═══════════════

    /** 🔴 اعلانِ «نیازمندِ تأیید» هر سه دکمه را دارد و لینکِ پنل ندارد */
    public function test_the_kyc_notification_carries_review_buttons_and_no_link(): void
    {
        $this->bind();
        $p = $this->pendingProfile();

        $raw = $this->outboxRaw();

        $this->assertStringContainsString('v1:kd:'.$p->id, $raw, 'دکمهٔ مشاهدهٔ مدارک نیست.');
        $this->assertStringContainsString('v1:ka:'.$p->id, $raw, 'دکمهٔ تأیید نیست.');
        $this->assertStringContainsString('v1:kr:'.$p->id, $raw, 'دکمهٔ رد نیست.');
        $this->assertStringContainsString('v1:c:'.$p->customer_id, $raw, 'دکمهٔ پروفایلِ مشتری نیست.');
        $this->assertStringContainsString('Türkiye', $raw, 'کشورِ مدارک باید در خودِ اعلان باشد.');
        $this->assertStringNotContainsString('/admin/', $raw, 'لینکِ پنل نباید در اعلانِ بله باشد.');
    }

    /** 🔴 «مشاهدهٔ مدارک»: صف → ارسالِ خودِ فایل‌ها به چتِ متصل + دکمه‌های تصمیم */
    public function test_the_docs_button_sends_the_actual_files(): void
    {
        $this->bind();
        $p = $this->pendingProfile();

        $this->tap('v1:kd:'.$p->id);
        $this->work();

        $docs = 0;

        foreach (Http::recorded() as [$req, ]) {
            if (str_contains($req->url(), '/sendDocument')) {
                $docs++;
            }
        }

        $this->assertSame(3, $docs, 'هر سه مدرک (پاسپورت/سلفی/آدرس) باید فایل بروند.');
        $this->assertStringContainsString('v1:ka:'.$p->id, $this->outboxRaw(),
            'بعد از مدارک باید دکمهٔ تصمیم دوباره بیاید.');
    }

    // ═══════════════ تأیید/رد — برابری با پنل ═══════════════

    /** 🔴 تأیید از بله = تأیید از پنل: verified + مدارک approved + دروازهٔ ایران باز */
    public function test_approving_from_bale_equals_the_panel(): void
    {
        $admin = $this->bind();

        $viaPanel = $this->pendingProfile();
        $viaBot   = $this->pendingProfile();

        $this->actingAs($admin, 'web')->post('/admin/verifications/'.$viaPanel->id.'/approve');

        $this->tap('v1:ka:'.$viaBot->id);                                   // پرسش
        $this->tap('v1:kay:'.$viaBot->id.':'.$this->stamp('kay', $viaBot->id)); // تأییدِ مهردار
        $this->work();

        foreach ([$viaPanel, $viaBot] as $p) {
            $p = $p->fresh();
            $this->assertSame('verified', $p->status);
            $this->assertSame('approved',
                CustomerDocument::where('customer_profile_id', $p->id)->first()->status);
            $this->assertFalse(IranSalesGate::blocks($p->customer->fresh(), 'IR'),
                'تأیید باید دروازهٔ ایران را باز کند — از هر دو مسیر.');
        }
    }

    /** 🔴 رد دلیل می‌خواهد و دلیل روی پروفایل می‌نشیند */
    public function test_rejecting_from_bale_requires_a_reason(): void
    {
        $this->bind();
        $p = $this->pendingProfile();

        $this->tap('v1:kr:'.$p->id);
        $this->say('Passport photo is blurry');
        $this->work();

        $p = $p->fresh();
        $this->assertSame('rejected', $p->status);
        $this->assertSame('Passport photo is blurry', $p->reject_reason);
    }

    // ═══════════════ تلاشِ دوبارهٔ تحویل ═══════════════

    /** 🔴 دکمهٔ اعلانِ شکست: اول پرسش (با هشدارِ پول)، بعدِ مهر برگشت به صف */
    public function test_the_retry_button_asks_then_requeues_the_service(): void
    {
        $this->bind();
        $c = $this->foreigner();
        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'gpu-4090', 'currency_code' => 'IRT',
            'price' => 500000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'failed',
            'provision_error' => 'quota exceeded',
        ]);

        $this->tap('v1:spa:'.$s->id);

        $this->assertStringContainsString('تلاشِ دوبارهٔ تحویل؟', $this->outboxRaw());
        $this->assertStringContainsString('quota exceeded', $this->outboxRaw(),
            'علتِ شکست باید در پرسش دیده شود.');
        $this->assertSame('failed', $s->fresh()->provision_status, 'پرسش نباید خودش اجرا کند.');

        $this->tap('v1:sp:'.$s->id.':'.$this->stamp('sp', $s->id));

        $this->assertSame('pending', $s->fresh()->provision_status,
            'بعد از تأیید باید به صفِ تحویل برگردد.');
    }
}
