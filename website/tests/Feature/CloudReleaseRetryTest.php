<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Sms\SmsSender;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 «درستش هرطوری هست همانگونه انجام بده، نه ما ضرر کنیم نه مشتری.»
 *
 * حذفی که زیرساخت نمی‌پذیرد، تا مرداد ۱۴۰۵ **هر دو طرف** را می‌سوزاند، از دو در:
 *
 *   · مسیرِ مشتری خطا می‌داد و سرویس `active` می‌مانْد ⇒ مشتری‌ای که کدِ
 *     یک‌بارمصرفش را سوزانده و گفته «پاکش کن»، همان ساعت دوباره کسر می‌خورد.
 *   · مترِ ساعتی مقدارِ برگشتی را دور می‌ریخت و «خاتمه‌یافته» می‌نوشت ⇒ ماشینِ
 *     زنده‌ای که اجاره‌اش پای ماست و هیچ صف و ناظری دیگر سراغش نمی‌رفت.
 *
 * سیاستِ تازه، و آنچه این فایل قفل می‌کند:
 *   گامِ ۱ صورت‌حساب (بی‌قیدوشرط، پیش از زیرساخت) → گامِ ۲ آزادسازی →
 *   گامِ ۳ دفترداری: موفق ⇒ `none` · ناموفق ⇒ `releasing` + فریاد + صفِ تلاشِ دوباره.
 *
 * ⚠️ و خطرناک‌ترین چیزی که این تغییر می‌توانست بسازد: حالتی که `provision:run`
 * برش دارد و **سرورِ دوم** بخرد. دو لایهٔ محافظ، جداگانه سنجیده می‌شوند.
 */
class CloudReleaseRetryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> */
    public array $codes = [];

    private const DELETE_URL = 'my.aeza.net/api/services/*';

    private const ORDER_URL = 'my.aeza.net/api/v2/services/orders';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::putSecret('aeza_api_token', 'aeza-key');
        Setting::put('pricing_rate_override', '100000');
        ErrorTracker::clear();

        $this->app->instance(SmsSender::class, new class($this) implements SmsSender
        {
            public function __construct(private CloudReleaseRetryTest $t) {}

            public function enabled(): bool { return true; }

            public function name(): string { return 'fake'; }

            public function send(string $m, string $text): bool { return true; }

            public function sendOtp(string $m, string $code): bool
            {
                $this->t->codes[$m] = $code;

                return true;
            }
        });
    }

    // ───────────────────────── فیکسچرها ─────────────────────────

    /**
     * ⚠️ استاب‌ها **یک بار** ثبت می‌شوند و کارخانه از نو ساخته می‌شود.
     * `Http::fake()`ِ همه‌گیر هر fakeِ بعدی را بی‌اثر می‌کند (اولین تطبیق برنده).
     */
    private function fake(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs + ['*' => Http::response([], 200)]);
    }

    private function customer(int $irt = 0): Customer
    {
        $c = Customer::create([
            'email' => 'rr'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        if ($irt !== 0) {
            CreditEntry::create([
                'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $irt,
                'balance_after' => $irt, 'reason' => 'topup', 'source_type' => Customer::class,
                'source_id' => $c->id, 'note' => 'test',
            ]);
        }

        return $c;
    }

    private function plan(): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'aeza', 'provider_ref' => '153',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 3_600_000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    /** سرویسِ ابریِ ساعتیِ **تحویل‌شده** + ماشینِ واقعی */
    private function liveCloudService(Customer $c, array $over = []): Service
    {
        $s = Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرور ابری ساعتی', 'currency_code' => 'IRT',
            'price' => 3_600_000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'billing_mode' => 'hourly', 'hourly_rate_irt' => 5000,
            'status' => 'active', 'provision_status' => 'done', 'activated_at' => now()->subDay(),
            'last_metered_at' => now()->subHour(), 'on_credit_out' => 'suspend',
            'cloud_plan_id' => $this->plan()->id, 'cloud_image_key' => 'ubuntu-24.04',
        ], $over));

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'aeza', 'provider_ref' => 'srv-'.$s->id,
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '10.0.0.9',
            'status' => 'running', 'ready_notified_at' => now()->subDays(5),
        ]);

        return $s->fresh();
    }

    private function terminateVia(Customer $c, Service $s): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");

        $this->assertNotEmpty($this->codes, 'هیچ کدی فرستاده نشد');

        return $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate", ['code' => (string) end($this->codes)]);
    }

    // ═══════════════ حذفِ موفق ═══════════════

    public function test_a_successful_deletion_closes_the_file_completely(): void
    {
        $this->fake([self::DELETE_URL => Http::response([], 200)]);

        $c = $this->customer(100_000);
        $s = $this->liveCloudService($c);

        $this->terminateVia($c, $s)->assertSessionHasNoErrors();

        $fresh = $s->fresh();
        $this->assertSame('terminated', $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame(Service::PROVISION_NONE, $fresh->provision_status,
            'شاخهٔ ابری هم باید none بنویسد، وگرنه done برای همیشه می‌مانَد');
        $this->assertSame('deleted', CloudInstance::where('service_id', $s->id)->first()->status);

        // ساعت ایستاد
        $this->artisan('cloud:meter')->assertOk();
        $this->assertSame(100_000, $c->creditBalance('IRT'), 'پس از حذف هیچ کسری');
    }

    /** حذف وسطِ ساعت: ساعتِ جاریِ پرداخت‌شده برنمی‌گردد — قاعده، نه تصادف */
    public function test_mid_hour_deletion_does_not_refund_the_paid_hour(): void
    {
        $this->fake([self::DELETE_URL => Http::response([], 200)]);

        $c = $this->customer(100_000);
        $s = $this->liveCloudService($c, ['last_metered_at' => now()->subMinutes(90)]);

        // تیکِ ساعتِ اول (T+۱ ساعت) پیش از حذف
        $this->artisan('cloud:meter')->assertOk();
        $this->assertSame(95_000, $c->creditBalance('IRT'));

        $this->terminateVia($c, $s->fresh())->assertSessionHasNoErrors();

        $this->artisan('cloud:meter')->assertOk();
        $this->assertSame(95_000, $c->creditBalance('IRT'),
            'نه کسرِ تازه، نه بازگشتِ نیم‌ساعتِ استفاده‌نشده');
    }

    // ═══════════════ حذفی که زیرساخت رد می‌کند ═══════════════

    public function test_a_refused_deletion_still_stops_the_customers_clock(): void
    {
        $this->fake([self::DELETE_URL => Http::response(['error' => 'nope'], 500)]);

        $c = $this->customer(100_000);
        $s = $this->liveCloudService($c);

        $res = $this->terminateVia($c, $s);

        $res->assertSessionHasNoErrors();
        $res->assertRedirect();

        $fresh = $s->fresh();
        $this->assertSame('terminated', $fresh->status, 'مشتری تمام است — بی‌قیدوشرط');
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame(Service::PROVISION_RELEASING, $fresh->provision_status);

        $instance = CloudInstance::where('service_id', $s->id)->first();
        $this->assertSame('running', $instance->status, 'ماشین واقعاً هنوز زنده است');
        $this->assertNotEmpty($instance->last_error);
        $this->assertSame(1, (int) ($instance->meta['release_attempts'] ?? 0));
        $this->assertNotEmpty($instance->meta['release_first_failed_at'] ?? null);

        // 🔴 مهم‌ترین ادعا: هزینهٔ خرابیِ ما پای مشتری نیست
        $this->artisan('cloud:meter')->assertOk();
        $this->assertSame(100_000, $c->creditBalance('IRT'));

        // و بلند است
        $this->assertTrue(
            collect(ErrorTracker::recent(200, 'error'))
                ->contains(fn ($e) => str_contains((string) ($e['message'] ?? ''), 'حذفِ سرور نزدِ زیرساخت انجام نشد')),
            'نشتی باید در ردیابِ خطا ثبت شود، نه فقط در ستونِ last_error'
        );
    }

    public function test_the_health_check_goes_red_while_a_machine_is_lingering(): void
    {
        $this->fake([self::DELETE_URL => Http::response([], 500)]);

        $c = $this->customer(100_000);
        $s = $this->liveCloudService($c);
        $this->terminateVia($c, $s);

        $checks = collect(app(\App\Services\SystemHealth::class)->checks())->keyBy('key');

        $this->assertArrayHasKey('cloud_release', $checks->all());
        $this->assertSame('fail', $checks['cloud_release']['level']);
        $this->assertStringContainsString(fa_num($s->id), $checks['cloud_release']['detail'],
            'شناسهٔ سرویس باید در متن باشد تا امضای وضعیت با ردیفِ تازه عوض شود');
    }

    /** کرونِ ساعتی پرونده را می‌بندد — بی‌یک تومان هزینهٔ اضافه برای مشتری */
    public function test_the_retry_cron_closes_a_lingering_release(): void
    {
        $this->fake([self::DELETE_URL => Http::response([], 500)]);

        $c = $this->customer(100_000);
        $s = $this->liveCloudService($c);
        $this->terminateVia($c, $s);

        $this->assertSame(Service::PROVISION_RELEASING, $s->fresh()->provision_status);

        // این بار زیرساخت می‌پذیرد
        $this->fake([self::DELETE_URL => Http::response([], 200)]);
        $this->artisan('cloud:release-retry')->assertOk();

        $this->assertSame(Service::PROVISION_NONE, $s->fresh()->provision_status);
        $this->assertSame('deleted', CloudInstance::where('service_id', $s->id)->first()->status);
        $this->assertSame(100_000, $c->creditBalance('IRT'), 'کلِ ماجرا صفر تومان به مشتری');
    }

    public function test_the_retry_cron_is_silent_when_the_queue_is_empty(): void
    {
        $this->fake([]);

        $this->artisan('cloud:release-retry')->assertOk();

        Http::assertNothingSent();
    }

    // ═══════════════ 🔴 «دو بار نخر» — دو لایه، جداگانه ═══════════════

    /** لایهٔ ۱: وضعیتِ مرده. `provision:run` نباید ردیفِ releasing را ببیند. */
    public function test_layer_one_a_releasing_row_is_never_picked_up_by_provisioning(): void
    {
        $this->fake([self::DELETE_URL => Http::response([], 500)]);

        $c = $this->customer(100_000);
        $s = $this->liveCloudService($c);
        $this->terminateVia($c, $s);

        $this->assertSame(Service::PROVISION_RELEASING, $s->fresh()->provision_status);

        $this->fake([]);                                   // شمارش از این‌جا صفر می‌شود
        $this->artisan('provision:run')->assertOk();

        $this->assertSame(1, CloudInstance::where('service_id', $s->id)->count(),
            'هیچ ماشینِ دومی');
        $this->assertSame(Service::PROVISION_RELEASING, $s->fresh()->provision_status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), self::ORDER_URL));
    }

    /**
     * لایهٔ ۲ **به‌تنهایی**: حتی اگر روزی باگی وضعیت را به `active` برگرداند،
     * `releasing` در هیچ‌کدام از مجموعه‌های claim نیست.
     *
     * ⚠️ عمداً جدا سنجیده می‌شود: گاردی که فرضِ نانوشته‌اش را نسنجد، روزی
     * محافظِ خودِ باگ می‌شود (درسِ ثبت‌شدهٔ همین پروژه).
     */
    public function test_layer_two_stands_alone_even_if_the_dead_status_is_lost(): void
    {
        $this->fake([self::DELETE_URL => Http::response([], 500)]);

        $c = $this->customer(100_000);
        $s = $this->liveCloudService($c);
        $this->terminateVia($c, $s);

        // شبیه‌سازیِ ازدست‌رفتنِ لایهٔ ۱
        Service::whereKey($s->id)->update(['status' => 'active']);

        $this->fake([]);
        $this->artisan('provision:run')->assertOk();

        $this->assertFalse(app(CloudProvisioner::class)->provision($s->fresh()),
            'claimِ اتمی نباید ردیفِ releasing را بردارد');

        $this->assertSame(Service::PROVISION_RELEASING, $s->fresh()->provision_status);
        $this->assertSame(1, CloudInstance::where('service_id', $s->id)->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), self::ORDER_URL));
    }

    /**
     * 🔴 خطرناک‌ترین در: `PaymentService::applyPaid` فقط `status` را می‌سنجد.
     * فاکتورِ تمدیدِ بازِ یک سرویسِ بسته‌شده نباید آن را دوباره به صفِ خرید ببرد.
     */
    public function test_paying_a_stale_invoice_does_not_re_arm_a_releasing_service(): void
    {
        $this->fake([self::DELETE_URL => Http::response([], 500)]);

        $c = $this->customer(100_000);
        $s = $this->liveCloudService($c);
        $this->terminateVia($c, $s);

        $invoice = \App\Models\Invoice::create([
            'customer_id' => $c->id, 'service_id' => $s->id,
            'number' => 'INV-'.random_int(10000, 99999),
            'currency_code' => 'IRT', 'subtotal' => 570000, 'tax' => 0, 'total' => 570000,
            'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);

        $payment = \App\Models\Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $c->id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => 570000, 'status' => 'redirected',
            'external_ref' => 'X'.random_int(1000, 9999),
        ]);

        $this->fake([]);
        app(\App\Services\Payment\PaymentService::class)
            ->settleConfirmed($payment, 'REF-'.random_int(1000, 9999));

        $fresh = $s->fresh();
        $this->assertSame('terminated', $fresh->status);
        $this->assertSame(Service::PROVISION_RELEASING, $fresh->provision_status,
            'نه awaiting_provision، نه pending');

        $this->artisan('provision:run')->assertOk();
        $this->assertSame(1, CloudInstance::where('service_id', $s->id)->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), self::ORDER_URL));
    }

    /**
     * 🔴 گاردِ **گاردِ** درِ پرداخت.
     *
     * `PaymentService::applyPaid` یک شاخهٔ پشتیبانِ SQL خام هم دارد که فقط وقتی
     * می‌دود که بلوکِ Eloquent استثنا بدهد — پس با یک تستِ رفتاری قابلِ رسیدن
     * نیست. ولی شرطِ خودش قابلِ سنجش است، و **باید** سنجیده شود: آن پرس‌وجو
     * ردیف‌هایی با `provision_status != 'done'` را به `pending` می‌برد، و
     * `releasing` با آن شرط **تطبیق می‌کند**. تنها چیزی که جلویش را می‌گیرد
     * `whereNotIn('status', DEAD_STATUSES)` است. اگر روزی کسی آن را بردارد،
     * پرداختِ یک فاکتورِ کهنه سرورِ دوم می‌خرد و هیچ تستِ رفتاری‌ای نمی‌گیردش.
     */
    public function test_the_raw_sql_fallback_of_the_payment_door_is_still_guarded_by_dead_status(): void
    {
        $src = file_get_contents(app_path('Services/Payment/PaymentService.php'));

        $this->assertStringContainsString(
            "->whereNotIn('status', \App\Models\Service::DEAD_STATUSES)", $src,
            'شاخهٔ خامِ SQL باید همچنان وضعیتِ مرده را کنار بگذارد'
        );

        // و همان شرطِ دیگرش که با releasing تطبیق می‌کند، هنوز سرِ جایش است —
        // یعنی این تست دربارهٔ کدِ واقعی حرف می‌زند، نه دربارهٔ فرضِ کهنه.
        $this->assertStringContainsString("'provision_status', '!=', 'done'", $src);
    }

    /**
     * ستونِ `provision_status` باید مقدارِ تازه را جا بدهد — روی MariaDB هم.
     *
     * ⚠️ SQLiteِ تست طولِ رشته را اعمال نمی‌کند، پس «کار کرد» این‌جا هیچ چیزی
     * دربارهٔ پروداکشن نمی‌گوید. درسِ `awaiting_provision` (۱۸ نویسه روی ستونِ
     * ۱۲تایی) یک تراکنشِ کاملِ پرداخت را برگرداند. پس خودِ مهاجرت خوانده می‌شود.
     */
    public function test_the_provision_status_column_is_wide_enough_for_the_new_value(): void
    {
        $migration = file_get_contents(
            base_path('database/migrations/2026_08_21_000102_add_provisioning_to_services.php')
        );

        $this->assertMatchesRegularExpression(
            "~string\('provision_status',\s*(\d+)\)~", $migration, 'شکلِ تعریفِ ستون عوض شده'
        );

        preg_match("~string\('provision_status',\s*(\d+)\)~", $migration, $m);

        $this->assertGreaterThanOrEqual(
            mb_strlen(Service::PROVISION_RELEASING), (int) $m[1],
            'مقدارِ releasing باید در ستون جا شود، وگرنه MariaDB «Data too long» می‌دهد'
        );
    }

    /** دکمهٔ «تلاشِ دوباره»ی مدیر هم نباید ردیفِ releasing را دوباره مسلح کند */
    public function test_the_admin_retry_button_cannot_re_arm_a_releasing_row(): void
    {
        $this->fake([self::DELETE_URL => Http::response([], 500)]);

        $c = $this->customer(100_000);
        $s = $this->liveCloudService($c);
        $this->terminateVia($c, $s);

        $admin = User::create([
            'name' => 'مدیر', 'email' => 'ad'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        $this->fake([]);
        $this->actingAs($admin, 'web')->post("/admin/services/{$s->id}/provision");

        $this->assertSame(Service::PROVISION_RELEASING, $s->fresh()->provision_status);
        $this->assertSame(1, CloudInstance::where('service_id', $s->id)->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), self::ORDER_URL));
    }
}
