<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 «حساب روی WHM ساخته شد، ولی پنلِ ما به مشتری گفت تحویل ناموفق بوده.»
 *
 * ═══ رخداد (مرداد ۱۴۰۵، دامنهٔ zhina.shop) ═══
 *
 * مشتری هاستِ لینوکسی خرید. کارفرما در WHM دید حساب ساخته شده، ولی مشتری خطای
 * تحویل گرفته بود و او مجبور شد دستی تحویل بزند.
 *
 * زنجیره:
 *   createacct روی نودِ شلوغ از بودجهٔ ۳۰ ثانیه رد می‌شود
 *     → تایم‌اوتِ سمتِ ما · حساب آن‌طرف **ساخته می‌شود**
 *     → `ok=false` که از «WHM نه گفت» قابلِ تشخیص نبود
 *     → provision_status='failed' و پیامِ «لغو کن، پولت برمی‌گردد» به مشتری
 *     → و کرونِ تحویل فقط `pending` را برمی‌دارد، پس هرگز خودش حل نمی‌شد
 *
 * ⚠️ علتِ ریشه‌ای **تایم‌اوت نبود**. این بود که بعد از شکست هیچ‌وقت دوباره از
 * سرور نمی‌پرسیدیم. تایم‌اوت فقط یکی از راه‌های رسیدن به آن حالت است؛ قطعیِ
 * لحظه‌ای، ریستِ WHM و سکسکهٔ گیت‌وی هم همان‌جا می‌رسند. پس تست‌ها روی
 * **پرسیدنِ دوباره** ادعا دارند، نه روی عددِ تایم‌اوت.
 */
class WhmDeliveryVerifyTest extends TestCase
{
    use RefreshDatabase;

    private function server(): Server
    {
        return Server::create([
            'name' => 'وب‌سرورِ آلمان', 'type' => 'whm', 'hostname' => 'whm.example.com',
            'username' => 'root', 'api_token' => 'tok', 'status' => 'active',
            'verify_tls' => false, 'max_accounts' => 100, 'active_accounts' => 0,
        ]);
    }

    private function service(Server $server, array $over = []): Service
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'h'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create(array_merge([
            'customer_id' => $c->id, 'server_id' => $server->id,
            'name' => 'هاستِ لینوکسی', 'domain' => 'zhina.shop',
            'username' => 'zhinasho', 'password' => 'Secret123!',
            'plan' => 'sn_wp5', 'cycle' => 'monthly', 'price' => 500000,
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
        ], $over));
    }

    /** پاسخِ accountsummary برای حسابی که هست */
    private function acct(string $user = 'zhinasho', string $domain = 'zhina.shop', int $suspended = 0): array
    {
        return ['metadata' => ['result' => 1, 'reason' => 'Ok'],
                'data' => ['acct' => [['user' => $user, 'domain' => $domain, 'suspended' => $suspended]]]];
    }

    /** پاسخِ accountsummary برای حسابی که نیست */
    private function noAcct(): array
    {
        return ['metadata' => ['result' => 0, 'reason' => 'Account does not exist']];
    }

    // ═══════════════ ۱) خودِ رخداد ═══════════════

    /**
     * 🔴 هستهٔ گزارشِ کارفرما: تایم‌اوت خوردیم ولی حساب ساخته شده بود.
     *
     * انتظار: سرویس **تحویل‌شده** حساب شود، نه ناموفق — چون یک `accountsummary`
     * ساده جواب را دارد.
     */
    public function test_a_create_that_times_out_but_did_create_the_account_is_delivered_not_failed(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        $calls = 0;

        Http::swap(new Factory);
        Http::fake(function ($request) use (&$calls) {
            $calls++;

            // پیش‌پرواز: هنوز نیست
            if ($calls === 1) {
                return Http::response($this->noAcct());
            }

            // createacct: تایم‌اوت — ولی WHM آن‌طرف دارد می‌سازد
            if (str_contains($request->url(), 'createacct')) {
                throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
            }

            // پرسشِ بعد از شکست: حالا هست
            return Http::response($this->acct());
        });

        $ok = app(ProvisioningService::class)->provision($service);

        $this->assertTrue($ok, 'با اینکه حساب روی سرور ساخته شده بود، تحویل ناموفق اعلام شد');

        $service->refresh();

        $this->assertSame('done', $service->provision_status);
        $this->assertSame('active', $service->status);
        $this->assertNull($service->provision_error);
        $this->assertTrue((bool) ($service->provision_meta['reused'] ?? false));
        $this->assertArrayHasKey('verified_after_error', $service->provision_meta,
            'ردِ اینکه این حساب «پس از خطا تأیید شد» نگه داشته نشد — عیب‌یابیِ بعدی مرجعی ندارد');
    }

    /**
     * 🔴 نیمهٔ دومِ ادعا، و مهم‌تر از نیمهٔ اول:
     * وقتی WHM **واقعاً** نه می‌گوید، نباید ادعای موفقیت کنیم.
     *
     * بی‌این تست، «رفعِ» بالا می‌توانست هر شکستی را موفق اعلام کند و مشتری
     * ایمیلِ «سرویس آماده شد» بگیرد برای حسابی که وجود ندارد.
     */
    public function test_a_genuine_whm_refusal_is_still_a_failure(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        Http::swap(new Factory);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'createacct')) {
                return Http::response(['metadata' => ['result' => 0, 'reason' => 'Sorry, a DNS entry for this domain already exists']]);
            }

            return Http::response($this->noAcct());
        });

        $this->assertFalse(app(ProvisioningService::class)->provision($service));

        $service->refresh();

        $this->assertSame('failed', $service->provision_status);
        $this->assertStringContainsString('DNS entry', (string) $service->provision_error);
    }

    /**
     * ⚠️ «نتوانستیم بپرسیم» نه موفق است نه ناموفق — به صفِ دستی می‌رود.
     *
     * مشتری «در حالِ آماده‌سازی» می‌بیند، نه «ناموفق، لغو کن و پولت را بگیر»؛
     * چون شاید حسابش همین حالا آماده باشد و ما فقط خبر نداریم.
     */
    public function test_an_unreachable_server_parks_the_service_instead_of_telling_the_customer_it_failed(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->assertFalse(app(ProvisioningService::class)->provision($service));

        $service->refresh();

        $this->assertSame('manual', $service->provision_status,
            'سرورِ بی‌پاسخ به مشتری «تحویل ناموفق» گفت — شاید حسابش ساخته شده باشد');
        $this->assertSame('awaiting_provision', $service->status);
    }

    /**
     * 🔴 بدنهٔ نامعتبر ≠ «نمی‌دانم».
     *
     * توکنِ باطل، آی‌پیِ بیرونِ allowlist و صفحهٔ ۴۰۳ِ WAF همگی بدنهٔ غیرِ JSON
     * می‌دهند. اگر «نمی‌دانم» بخوانیمشان، سرویس در حالتِ **ساکتِ** دستی می‌نشیند
     * و یک خرابیِ پایدارِ پیکربندی ماه‌ها بی‌صدا می‌مانَد — بدتر از خطای بلند.
     */
    public function test_a_broken_whm_configuration_fails_loudly_instead_of_parking_silently(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response('<html>403 Forbidden</html>', 403));

        $this->assertFalse(app(ProvisioningService::class)->provision($service));

        $this->assertSame('failed', $service->fresh()->provision_status,
            'خرابیِ پایدارِ پیکربندی در صفِ ساکتِ دستی پنهان شد');
    }

    // ═══════════════ ۲) پذیرش فقط روی ردیفِ **منطبق** ═══════════════

    /**
     * 🔴 صرفِ وجودِ نام‌کاربری کافی نیست.
     *
     * `accountsummary` برای حسابی که دامنه‌اش با آنچه فروخته‌ایم نمی‌خوانَد هم
     * موفق برمی‌گردد. پذیرشِ کور یعنی رکوردِ DNSِ زیردامنهٔ رایگان به حسابِ
     * اشتباه اشاره کند و رمزی ایمیل شود که رمزِ آن حساب نیست.
     */
    public function test_an_account_with_a_different_domain_is_never_adopted(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        Http::swap(new Factory);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'createacct')) {
                throw new ConnectionException('timed out');
            }

            // همان نام‌کاربری، ولی مالِ دامنهٔ دیگری
            return Http::response($this->acct('zhinasho', 'someone-else.com'));
        });

        $this->assertFalse(app(ProvisioningService::class)->provision($service));
        $this->assertNotSame('done', $service->fresh()->provision_status);
    }

    /** حسابِ معلق هم «تحویل‌شده» نیست */
    public function test_a_suspended_account_is_never_adopted(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        Http::swap(new Factory);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'createacct')) {
                throw new ConnectionException('timed out');
            }

            return Http::response($this->acct('zhinasho', 'zhina.shop', suspended: 1));
        });

        $this->assertFalse(app(ProvisioningService::class)->provision($service));
        $this->assertNotSame('done', $service->fresh()->provision_status);
    }

    // ═══════════════ ۳) کرونِ خوددرمان ═══════════════

    /**
     * ردیفی که در `failed` جا مانده باید خودش حل شود، بی‌دخالتِ آدم.
     *
     * ⚠️ کرونِ اصلیِ تحویل عمداً `failed` را برنمی‌دارد (چون همان کرون مسیرِ
     * ابری را هم می‌راند و آن‌جا تلاشِ دوباره یعنی خریدِ سرورِ دوم). پس این
     * فرمانِ جدا لازم است.
     */
    public function test_the_verify_command_adopts_an_account_that_was_really_created(): void
    {
        $server = $this->server();
        $service = $this->service($server, [
            'provision_status' => 'failed',
            'status' => 'provision_failed',
            'provision_error' => 'ارتباط با سرور برقرار نشد: timed out',
        ]);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response($this->acct()));

        $this->artisan('provision:verify-failed')->assertSuccessful();

        $service->refresh();

        $this->assertSame('done', $service->provision_status);
        $this->assertSame('active', $service->status);
    }

    /** و اگر حساب واقعاً نیست، دست نمی‌زند */
    public function test_the_verify_command_leaves_a_genuinely_missing_account_alone(): void
    {
        $server = $this->server();
        $service = $this->service($server, ['provision_status' => 'failed', 'status' => 'provision_failed']);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response($this->noAcct()));

        $this->artisan('provision:verify-failed')->assertSuccessful();

        $this->assertSame('failed', $service->fresh()->provision_status);
    }

    /**
     * 🔴 هرگز `createacct` نمی‌زند.
     *
     * این تنها چیزی است که ثابت می‌کند فرمانِ خوددرمان نمی‌تواند حسابِ دوم
     * بسازد یا پولِ تازه خرج کند.
     */
    public function test_the_verify_command_never_creates_anything(): void
    {
        $server = $this->server();
        $this->service($server, ['provision_status' => 'failed', 'status' => 'provision_failed']);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response($this->acct()));

        $this->artisan('provision:verify-failed');

        foreach (Http::recorded() as [$req, ]) {
            $this->assertStringNotContainsString('createacct', $req->url(),
                'فرمانِ وارسی دست به ساخت زد — این‌جا فقط باید بپرسد');
        }
    }

    /**
     * ⚠️ سروری که WHM نیست نباید اصلاً پرسیده شود.
     *
     * هر سفارشِ تحویلِ دستی (پلسک، VPS، اختصاصی) برای همیشه در `manual`
     * می‌نشیند؛ بی‌این قید، هر ۵ دقیقه یک درخواستِ WHM به ماشینی می‌رفت که
     * اصلاً WHM ندارد.
     */
    public function test_the_verify_command_ignores_non_whm_servers(): void
    {
        $server = Server::create([
            'name' => 'پلسکِ ایران', 'type' => 'plesk', 'hostname' => 'plesk.example.com',
            'username' => 'admin', 'api_token' => 'tok', 'status' => 'active',
            'verify_tls' => false, 'max_accounts' => 50, 'active_accounts' => 0,
        ]);

        $this->service($server, ['provision_status' => 'manual', 'status' => 'awaiting_provision']);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response($this->acct()));

        $this->artisan('provision:verify-failed')->assertSuccessful();

        $this->assertSame([], Http::recorded()->all(),
            'به سرورِ غیرِ WHM درخواستِ WHM رفت');
    }

    // ═══════════════ ۴) شمارشِ ظرفیت ═══════════════

    /**
     * 🔴 حسابی که پس از شکست پذیرفته می‌شود، **ظرفیت اشغال می‌کند**.
     *
     * شرطِ قدیمی `! reused` بود، و حسابِ پذیرفته‌شده هم `reused` است — پس
     * شمرده نمی‌شد و سرور به‌ازای هر رخداد یک‌واحد بیشتر از واقعیت «جا» نشان
     * می‌داد و بیش‌فروش می‌شد.
     */
    public function test_an_adopted_account_still_counts_against_server_capacity(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        Http::swap(new Factory);
        $n = 0;
        Http::fake(function ($request) use (&$n) {
            $n++;
            if ($n === 1) {
                return Http::response($this->noAcct());
            }
            if (str_contains($request->url(), 'createacct')) {
                throw new ConnectionException('timed out');
            }

            return Http::response($this->acct());
        });

        app(ProvisioningService::class)->provision($service);

        $this->assertSame(1, (int) $server->fresh()->active_accounts,
            'حسابِ پذیرفته‌شده ظرفیت را اشغال کرد ولی شمرده نشد — سرور بیش‌فروش می‌شود');
    }

    /**
     * 🔴 و نیمهٔ دوم: همان حساب موقعِ خاتمه باید ظرفیت را **پس بدهد**.
     *
     * ⚠️ رفعِ ساده‌لوحانه (فقط عوض‌کردنِ شرطِ افزایش) این را می‌شکست: کاهش
     * هنوز `! reused` را می‌خواند، پس شمارنده بی‌سقف بالا می‌رفت و سرور بی‌هیچ
     * خطایی از صفحهٔ خرید غیب می‌شد.
     */
    public function test_terminating_an_adopted_account_gives_the_capacity_back(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        Http::swap(new Factory);
        $n = 0;
        Http::fake(function ($request) use (&$n) {
            $n++;
            if ($n === 1) {
                return Http::response($this->noAcct());
            }
            if (str_contains($request->url(), 'createacct')) {
                throw new ConnectionException('timed out');
            }

            return Http::response($this->acct());
        });

        app(ProvisioningService::class)->provision($service);
        $this->assertSame(1, (int) $server->fresh()->active_accounts);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['metadata' => ['result' => 1, 'reason' => 'Ok']]));

        app(ProvisioningService::class)->releaseServer($service->fresh());

        $this->assertSame(0, (int) $server->fresh()->active_accounts,
            'ظرفیت پس داده نشد — شمارنده بی‌سقف بالا می‌رود و سرور از فروشگاه غیب می‌شود');
    }

    // ═══════════════ ۵) متنِ مشتری ═══════════════

    /**
     * ⚠️ متنِ خامِ خطای سرور نباید به مشتری برود.
     *
     * `service_failed` الگوی پیامکِ زنده دارد، یعنی هر مقداری که در متغیرها
     * بگذاریم به اپراتورِ پیامک هم می‌رود — و متنِ خامِ WHM شاملِ hostname و
     * پورتِ سرورِ ماست.
     */
    public function test_the_raw_server_error_never_reaches_the_customer(): void
    {
        $server = $this->server();
        $service = $this->service($server);

        $seen = new \ArrayObject;

        $this->app->instance(\App\Services\Notify\Notifier::class,
            new class($seen) extends \App\Services\Notify\Notifier
            {
                public function __construct(private \ArrayObject $box) {}

                public function fire(string $key, ?Customer $customer, array $vars, string $text,
                    array $adminRows = [], ?string $url = null, string $emoji = '🔔'): void
                {
                    $this->box[] = ['key' => $key, 'vars' => $vars, 'text' => $text];
                }
            });

        Http::swap(new Factory);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'createacct')) {
                return Http::response(['metadata' => ['result' => 0, 'reason' => 'connect to whm.example.com:2087 failed']]);
            }

            return Http::response($this->noAcct());
        });

        app(ProvisioningService::class)->provision($service);

        $flat = json_encode((array) $seen, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('whm.example.com', $flat,
            'نامِ میزبانِ سرور در پیامِ مشتری رفت — این متن به اپراتورِ پیامک هم می‌رسد');
        $this->assertStringNotContainsString('2087', $flat);

        // ولی مدیر باید متنِ خام را داشته باشد
        $this->assertStringContainsString('whm.example.com', (string) $service->fresh()->provision_error);
    }
}
