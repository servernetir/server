<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * فاز ۵ — مشتریِ جدید و فروشِ تلفنی از داخلِ بله.
 *
 * ⚠️ ادعای مرکزیِ این فایل «کار می‌کند» نیست، **«مبلغ درست است»** است. هر دو
 * چیزی که این‌جا ساخته می‌شود (سرویس و فاکتور) تا ابد تکرار می‌شوند:
 * `services.price` هر دوره دوباره صورت‌حساب می‌شود و هیچ سقفِ منطقی ندارد.
 */
class BaleAdminSalesTest extends TestCase
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

    private function say(string $text): void
    {
        $this->postJson($this->hookUrl(), [
            'update_id' => random_int(1, 10_000_000),
            'message' => [
                'chat' => ['id' => self::OWNER_CHAT, 'type' => 'private'],
                'from' => ['id' => self::OWNER_CHAT, 'is_bot' => false],
                'text' => $text,
            ],
        ]);
    }

    private function click(string $data): void
    {
        $this->postJson($this->hookUrl(), [
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

    /** دکمهٔ تأییدِ فروش را از همان کارتی برمی‌دارد که ربات فرستاده */
    private function confirmButton(): ?string
    {
        foreach ($this->buttonsSent() as $d) {
            if (str_starts_with($d, 'v1:sey:')) {
                return $d;
            }
        }

        return null;
    }

    private function forget(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true])]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'c'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'name' => 'هاستِ لینوکسیِ برنزی',
            'slug' => 'bronze-'.random_int(1000, 99999),
            'category' => 'hosting', 'currency_code' => 'IRT',
            'price' => 500_000, 'setup_fee' => 0, 'cycle' => 'monthly',
            'tax_percent' => 0, 'requires_domain' => false, 'is_active' => true,
        ], $over));
    }

    // ═══════════════ مشتریِ جدید ═══════════════

    public function test_a_customer_is_created_through_three_prompts(): void
    {
        $this->bind();

        $this->click('v1:cn');
        $this->say('محمد رضایی');
        $this->say('09121112233');
        $this->say('m@example.com');

        $c = Customer::where('phone', '09121112233')->first();

        $this->assertNotNull($c, 'مشتری ساخته نشد');
        $this->assertSame('m@example.com', $c->email);
        $this->assertSame('رضایی', $c->defaultProfile()?->last_name);
    }

    /**
     * 🔴 تا گامِ آخر **هیچ ردیفی** نوشته نمی‌شود.
     *
     * وگرنه هر جریانِ رهاشده یک پروندهٔ نیم‌ساخته در فهرستِ مشتریان می‌گذاشت
     * که نه شماره دارد نه راهی برای پاک شدن.
     */
    public function test_nothing_is_written_before_the_last_step(): void
    {
        $this->bind();

        $before = Customer::count();

        $this->click('v1:cn');
        $this->say('محمد رضایی');
        $this->say('09121112233');

        $this->assertSame($before, Customer::count(), 'مشتری پیش از گامِ آخر ساخته شد');
    }

    /** شمارهٔ تکراری پروندهٔ دوم نمی‌سازد — همان موجود باز می‌شود */
    public function test_a_duplicate_mobile_opens_the_existing_file(): void
    {
        $this->bind();

        $existing = $this->customer();
        $existing->update(['phone' => '09121112233']);

        $before = Customer::count();

        $this->click('v1:cn');
        $this->say('محمد رضایی');
        $this->say('09121112233');
        $this->click('v1:cne');

        $this->assertSame($before, Customer::count(), 'پروندهٔ موازی ساخته شد');
        $this->assertStringContainsString($existing->code, $this->outbox());
    }

    /** بی‌ایمیل هم ثبت می‌شود، ولی نشانیِ جای‌نگهدار هرگز قابلِ ارسال نیست */
    public function test_a_customer_without_an_email_gets_an_unroutable_placeholder(): void
    {
        $this->bind();

        $this->click('v1:cn');
        $this->say('زهرا احمدی');
        $this->say('09351112233');
        $this->click('v1:cne');

        $c = Customer::where('phone', '09351112233')->first();

        $this->assertNotNull($c);
        $this->assertStringEndsWith('.invalid', (string) $c->email);
    }

    /** شمارهٔ نامعتبر جریان را جلو نمی‌برد */
    public function test_an_invalid_mobile_does_not_advance_the_flow(): void
    {
        $this->bind();

        $this->click('v1:cn');
        $this->say('محمد رضایی');
        $this->say('سلام');

        $this->assertStringContainsString('معتبر نیست', $this->outbox());
        $this->assertSame('cn:mobile', app(AdminBaleGate::class)->flow());
    }

    // ═══════════════ فروشِ تلفنی ═══════════════

    public function test_a_phone_sale_issues_a_proforma_from_the_catalogue_price(): void
    {
        $this->bind();

        $c = $this->customer();
        $p = $this->product(['price' => 500_000]);

        $this->click('v1:sell:'.$c->id);
        $this->click('v1:sep:'.$p->id);
        $this->click('v1:sec:monthly');

        $this->forget();

        $this->click('v1:sec:monthly');            // بازبینی را دوباره بساز
        $confirm = $this->confirmButton();

        $this->assertNotNull($confirm, 'دکمهٔ تأیید ساخته نشد');

        $this->click($confirm);

        $s = Service::where('customer_id', $c->id)->first();

        $this->assertNotNull($s, 'سرویس ساخته نشد');
        $this->assertSame(500_000, (int) $s->price);
        $this->assertSame('pending', $s->status);

        $inv = Invoice::where('service_id', $s->id)->first();

        $this->assertNotNull($inv, 'پیش‌فاکتور صادر نشد');
        $this->assertSame('unpaid', $inv->status);
    }

    /**
     * 🔴 هزینهٔ راه‌اندازی نباید بینِ دو مسیرِ فروش گم شود.
     *
     * تا پیش از این، فروشِ تلفنی از متدِ **تمدید** فاکتور می‌ساخت که راه‌اندازی
     * ندارد — یعنی همان پکیج، از تلفن ارزان‌تر از سایت درمی‌آمد و هیچ خطایی
     * هم نمی‌داد.
     */
    public function test_the_setup_fee_is_charged_exactly_like_the_online_store(): void
    {
        $this->bind();

        $c = $this->customer();
        $p = $this->product(['price' => 500_000, 'setup_fee' => 120_000]);

        $this->click('v1:sell:'.$c->id);
        $this->click('v1:sep:'.$p->id);
        $this->click('v1:sec:monthly');

        $confirm = $this->confirmButton();
        $this->click((string) $confirm);

        $inv = Invoice::where('customer_id', $c->id)->first();

        $this->assertNotNull($inv);
        $this->assertSame(620_000, (int) $inv->total, 'هزینهٔ راه‌اندازی روی فاکتور نیامد');
        $this->assertSame(2, $inv->items()->count());
    }

    /**
     * 🔴 دورهٔ سالانه باید **قیمتِ سالانه** را ثبت کند، نه ماهانه را.
     *
     * اگر قیمتِ پایه ثبت شود، کرونِ تمدید تا ابد سالی یک بار مبلغِ یک ماه را
     * صورت‌حساب می‌کند — ضررِ خاموشی که تا سالِ دوم دیده نمی‌شود.
     */
    public function test_the_cycle_price_is_taken_from_the_catalogue_not_the_base_price(): void
    {
        $this->bind();

        $c = $this->customer();
        $p = $this->product(['price' => 500_000]);

        $this->click('v1:sell:'.$c->id);
        $this->click('v1:sep:'.$p->id);
        $this->click('v1:sec:yearly');

        $confirm = $this->confirmButton();
        $this->click((string) $confirm);

        $s = Service::where('customer_id', $c->id)->first();

        $this->assertNotNull($s);
        $this->assertSame('yearly', $s->cycle);
        $this->assertSame($p->priceForCycle('yearly'), (int) $s->price);
        $this->assertGreaterThan(500_000, (int) $s->price, 'قیمتِ سالانه همان قیمتِ ماهانه ثبت شد');
    }

    /** پکیجی که دامنه می‌خواهد، بی‌دامنه ثبت نمی‌شود */
    public function test_a_domain_requiring_package_asks_for_the_domain_first(): void
    {
        $this->bind();

        $c = $this->customer();
        $p = $this->product(['requires_domain' => true]);

        $this->click('v1:sell:'.$c->id);
        $this->click('v1:sep:'.$p->id);
        $this->click('v1:sec:monthly');

        $this->assertNull($this->confirmButton(), 'بی‌دامنه مستقیم به تأیید رسید');
        $this->assertSame('sell:domain', app(AdminBaleGate::class)->flow());

        $this->say('example.com');

        $confirm = $this->confirmButton();
        $this->assertNotNull($confirm);

        $this->click((string) $confirm);

        $this->assertSame('example.com', Service::where('customer_id', $c->id)->first()?->domain);
    }

    /** تپِ دوم روی همان دکمه، فروشِ دوم نمی‌سازد */
    public function test_tapping_confirm_twice_sells_only_once(): void
    {
        $this->bind();

        $c = $this->customer();
        $p = $this->product();

        $this->click('v1:sell:'.$c->id);
        $this->click('v1:sep:'.$p->id);
        $this->click('v1:sec:monthly');

        $confirm = (string) $this->confirmButton();

        $this->click($confirm);
        $this->click($confirm);

        $this->assertSame(1, Service::where('customer_id', $c->id)->count(), 'دو بار فروخته شد');
        $this->assertSame(1, Invoice::where('customer_id', $c->id)->count());
    }

    /** دکمهٔ کهنه — همان محافظِ فاز ۳، این‌بار روی پول */
    public function test_a_stale_confirm_button_never_sells(): void
    {
        $this->bind();

        $c = $this->customer();
        $p = $this->product();

        $this->click('v1:sell:'.$c->id);
        $this->click('v1:sep:'.$p->id);
        $this->click('v1:sec:monthly');

        $old = $this->travelTo(now()->subDay(),
            fn () => app(AdminBaleGate::class)->stamp('sey:'.$c->id.':'.$p->id.':monthly'));

        $this->click('v1:sey:'.$old);

        $this->assertSame(0, Service::where('customer_id', $c->id)->count(), 'دکمهٔ کهنه فروخت');
        $this->assertStringContainsString('کهنه', $this->outbox());
    }

    /** پکیجِ بی‌قیمت فروخته نمی‌شود — سروری که هرگز پولی برایش نمی‌آید */
    public function test_a_zero_priced_package_is_refused(): void
    {
        $this->bind();

        $c = $this->customer();
        $p = $this->product(['price' => 0]);

        $this->click('v1:sell:'.$c->id);
        $this->click('v1:sep:'.$p->id);
        $this->click('v1:sec:monthly');

        $confirm = $this->confirmButton();

        if ($confirm !== null) {
            $this->click($confirm);
        }

        $this->assertSame(0, Service::where('customer_id', $c->id)->count());
    }

    /**
     * 🔴 قیمت هیچ‌جای این جریان **تایپ‌شدنی** نیست.
     *
     * اگر روزی کسی یک گامِ «مبلغ را بفرستید» اضافه کند، همین تست قرمز می‌شود.
     * دلیلش در `PhoneSale` نوشته شده: یک صفرِ اضافه روی گوشی، مشتری را تا ابد
     * ده برابر شارژ می‌کند.
     */
    public function test_the_price_can_never_be_typed_in(): void
    {
        $this->bind();

        $c = $this->customer();
        $p = $this->product(['price' => 500_000]);

        $this->click('v1:sell:'.$c->id);
        $this->click('v1:sep:'.$p->id);
        $this->click('v1:sec:monthly');

        // کارفرما یک عدد می‌فرستد — نباید هیچ اثری روی مبلغ بگذارد
        $this->say('99');

        $confirm = $this->confirmButton();
        $this->click((string) $confirm);

        $s = Service::where('customer_id', $c->id)->first();

        $this->assertNotNull($s);
        $this->assertSame(500_000, (int) $s->price, 'مبلغ از متنِ چت خوانده شد');
    }

    /** فروش در تاریخچهٔ سرویس ثبت می‌شود، وگرنه تنها سندِ این کار گم است */
    public function test_the_sale_is_written_to_the_service_history(): void
    {
        $this->bind();

        $c = $this->customer();
        $p = $this->product();

        $this->click('v1:sell:'.$c->id);
        $this->click('v1:sep:'.$p->id);
        $this->click('v1:sec:monthly');
        $this->click((string) $this->confirmButton());

        $s = Service::where('customer_id', $c->id)->first();

        $this->assertNotNull($s);
        $this->assertTrue(
            \App\Models\ActivityLog::ofService($s->id)->where('action', 'purchase')->exists(),
            'فروش در تاریخچه ثبت نشد'
        );
    }

    // ═══════════════ خلاصهٔ هوشمند ═══════════════

    /**
     * 🔴 آنچه به مدلِ بیرونی می‌رود، **فقط عدد** است.
     *
     * این متن به یک ارائه‌دهندهٔ بیرون از کشور می‌رود. نام و ایمیل و موبایلِ
     * مشتری برای «وضعش چطور است» هیچ لازم نیستند، و یک‌بار که رفتند
     * برنمی‌گردند. اگر روزی کسی `displayName()` را به آن آرایه اضافه کند،
     * همین‌جا قرمز می‌شود.
     */
    public function test_the_ai_brief_never_ships_identifying_data(): void
    {
        $c = $this->customer();

        $facts = app(\App\Services\Customer\CustomerBriefWriter::class)->facts($c);
        $json  = json_encode($facts, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString((string) $c->email, $json);
        $this->assertStringNotContainsString((string) $c->phone, $json);
        $this->assertStringNotContainsString((string) $c->code, $json);
        $this->assertStringNotContainsString($c->displayName(), $json);

        // و در عوض، ارقامی که واقعاً لازم‌اند هستند
        $this->assertArrayHasKey('invoices_unpaid', $facts);
        $this->assertArrayHasKey('tickets_open', $facts);
    }

    /** ارقام در PHP شمرده می‌شوند، پس روی پروندهٔ خالی هم صفرِ درست می‌دهند */
    public function test_the_brief_facts_are_counted_locally(): void
    {
        $c = $this->customer();

        \App\Models\Invoice::create([
            'customer_id' => $c->id, 'kind' => 'service', 'currency_code' => 'IRT',
            'subtotal' => 300000, 'tax' => 0, 'total' => 300000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $facts = app(\App\Services\Customer\CustomerBriefWriter::class)->facts($c);

        $this->assertSame(1, $facts['invoices_unpaid']);
        $this->assertSame(300000, $facts['unpaid_total_toman']);
        $this->assertSame(0, $facts['services_alive']);
    }
}
