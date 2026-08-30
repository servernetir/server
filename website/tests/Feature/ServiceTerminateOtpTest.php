<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\OtpChallenge;
use App\Models\Server;
use App\Models\Service;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * حذفِ سرویسِ تحویل‌شده توسط مشتری، با تأییدِ کدِ یک‌بارمصرف.
 *
 * چرا این تست‌ها سخت‌گیرند: این مسیر **سرور را واقعاً نزدِ زیرساخت پاک می‌کند**
 * و داده برنمی‌گردد. سه چیز باید غیرممکن باشد:
 *   ۱) حذف بدونِ کد
 *   ۲) حذفِ سرویسِ شخصِ دیگر
 *   ۳) گرفتنِ کد برای سرویسِ ارزان و حذفِ سرویسِ دیگر با همان کد
 */
class ServiceTerminateOtpTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> شمارهٔ مقصد => کدِ فرستاده‌شده */
    public array $codes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        // کد هش‌شده ذخیره می‌شود و متنِ خام فقط از درِ پیامک بیرون می‌رود؛
        // پس فرستنده را می‌گیریم تا مسیرِ موفق هم واقعاً سنجیده شود.
        $this->app->instance(SmsSender::class, new class($this) implements SmsSender {
            public function __construct(private ServiceTerminateOtpTest $t) {}

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

    private function sentCode(): string
    {
        $this->assertNotEmpty($this->codes, 'هیچ کدی فرستاده نشد');

        return (string) end($this->codes);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function activeService(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرور مجازی زنده', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done', 'activated_at' => now(),
        ], $over));
    }

    /**
     * استابِ تمیزِ HTTP برای تست‌های WHM.
     *
     * ⚠️ `setUp()` یک `Http::fake()`ِ بی‌آرگومان می‌زند که یعنی استابِ همه‌گیرِ
     * `*`. لاراول استاب‌ها را به **ترتیبِ ثبت** می‌سنجد و اولین تطبیق برنده
     * است، پس آن `*` هر `Http::fake([...])`ِ بعدی را بی‌اثر می‌کند و تست
     * بی‌صدا چیزی را می‌سنجد که فکر می‌کند. (همان تلهٔ مستندشده در CLAUDE.md.)
     * برای همین کارخانه را از نو می‌سازیم، نه اینکه استاب اضافه کنیم.
     */
    private function fakeWhm(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake($stubs);
    }
    /** سرویسِ هاستِ اشتراکیِ تحویل‌شده روی یک سرورِ WHM */
    private function hostingService(Customer $c, int $accounts = 3): Service
    {
        $server = Server::create([
            'name' => 'WHM-1', 'type' => 'whm', 'hostname' => 'w.test',
            'username' => 'root', 'api_token' => 't', 'verify_tls' => false,
            'status' => 'active', 'active_accounts' => $accounts,
        ]);

        return $this->activeService($c, [
            'name' => 'هاست اشتراکی', 'server_id' => $server->id,
            'username' => 'clientusr', 'domain' => 'x.com',
        ]);
    }

    private function terminateVia(Customer $c, Service $s): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");

        return $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate", ['code' => $this->sentCode()]);
    }

    /**
     * 🔴 حذفِ هاستِ اشتراکی باید واقعاً `removeacct` را روی WHM بزند.
     *
     * قبلاً کنترلر فقط `isCloud()` را شاخه می‌زد، پس حذفِ هاست هرگز به WHM
     * نمی‌رسید: متنی که مشتری تأیید می‌کند صریح می‌گوید «سرور و همهٔ داده‌ها
     * برای همیشه پاک می‌شود»، ولی حسابِ cPanel زنده می‌مانْد — سایت بالا،
     * دیسک مصرف‌شده، و با همان رمزِ نشان‌داده‌شده در پنل قابلِ ورود.
     *
     * هیچ تستی این را نمی‌گرفت چون فیکسچرِ این فایل هرگز `server_id` نمی‌گذاشت.
     */
    public function test_terminating_shared_hosting_really_removes_the_cpanel_account(): void
    {
        $this->fakeWhm(['*/json-api/removeacct*' => Http::response(['metadata' => ['result' => 1]])]);

        $c = $this->customer();
        $s = $this->hostingService($c);

        $this->terminateVia($c, $s)->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'removeacct')
            && str_contains($r->url(), 'clientusr'));

        $this->assertSame('terminated', $s->fresh()->status, 'برچسبِ مشتری باید «حذف شده» بماند نه «لغو شده»');
    }

    /** ظرفیتِ آزادشدهٔ سرور باید برگردد، وگرنه آن سرور برای همیشه «پر» می‌مانَد */
    public function test_terminating_shared_hosting_frees_server_capacity(): void
    {
        $this->fakeWhm(['*/json-api/removeacct*' => Http::response(['metadata' => ['result' => 1]])]);

        $c = $this->customer();
        $s = $this->hostingService($c, accounts: 3);

        $this->terminateVia($c, $s);

        $this->assertSame(2, (int) Server::firstOrFail()->active_accounts);
    }

    /**
     * 🔴 **این تست عمداً برعکسِ نسخهٔ قبلی‌اش است — نگذار تصادفی برگردد.**
     *
     * تا مرداد ۱۴۰۵ ادعا می‌کرد «حذفِ ناموفق باید سرویس را باز نگه دارد تا مشتری
     * دوباره تلاش کند». آن قاعده گران بود: سرویسِ ساعتی باز می‌مانْد و مترِ
     * ساعتی همان ساعت دوباره از کیفِ پولِ کسی کسر می‌کرد که کدِ یک‌بارمصرفش را
     * سوزانده و گفته بود «پاکش کن» — یعنی مشتری خرابیِ **ما** را می‌پرداخت.
     *
     * قاعدهٔ کارفرما («نه ما ضرر کنیم نه مشتری») سه چیزِ هم‌زمان می‌خواهد:
     *   ۱) سرویس بسته می‌شود و صورت‌حساب می‌ایستد — بی‌قیدوشرط.
     *   ۲) `active_accounts` **آزاد نمی‌شود**: حسابِ cPanel واقعاً هنوز هست و
     *      ظرفیتِ آن سرور واقعاً مصرف است.
     *   ۳) ردیف در `releasing` می‌مانَد تا کرون خودش ببندَدش. تلاشِ دوباره کارِ
     *      ماست، نه کارِ مشتری.
     *
     * ⚠️ هر دو تماس باید استاب شوند و ترتیبشان مهم است: روی شکستِ `removeacct`
     * درایور عمداً `accountExists` را می‌پرسد و اگر حساب نبود، حذف را «انجام‌شده»
     * می‌شمارد (idempotent). پس «شکستِ واقعی» یعنی WHM رد کرد **و** حساب هنوز
     * سرِ جایش است.
     */
    public function test_a_failed_whm_removal_closes_the_service_but_keeps_the_capacity_consumed(): void
    {
        $this->fakeWhm([
            '*/json-api/removeacct*' => Http::response(
                ['metadata' => ['result' => 0, 'reason' => 'permission denied']]
            ),
            '*/json-api/accountsummary*' => Http::response(
                ['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'clientusr']]]]
            ),
        ]);

        $c = $this->customer();
        $s = $this->hostingService($c);

        $this->terminateVia($c, $s)->assertSessionHasNoErrors();

        $fresh = $s->fresh();
        $this->assertSame('terminated', $fresh->status,
            'صورت‌حسابِ مشتری در همان لحظهٔ درخواست بسته می‌شود');
        $this->assertSame(\App\Models\Service::PROVISION_RELEASING, $fresh->provision_status,
            'ماشین تأییدنشده پاک نشده — در صفِ تلاشِ دوباره');
        $this->assertSame(3, (int) Server::firstOrFail()->active_accounts,
            'ظرفیت نباید برای حذفی که انجام نشد آزاد شود');
    }

    /** و کرونِ تلاشِ دوباره همان ردیف را می‌بندد و ظرفیت را آزاد می‌کند */
    public function test_the_release_retry_cron_finishes_a_failed_whm_removal(): void
    {
        $this->fakeWhm([
            '*/json-api/removeacct*' => Http::response(
                ['metadata' => ['result' => 0, 'reason' => 'permission denied']]
            ),
            '*/json-api/accountsummary*' => Http::response(
                ['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'clientusr']]]]
            ),
        ]);

        $c = $this->customer();
        $s = $this->hostingService($c);
        $this->terminateVia($c, $s);

        $this->assertSame(\App\Models\Service::PROVISION_RELEASING, $s->fresh()->provision_status);

        // این بار WHM می‌پذیرد
        $this->fakeWhm(['*/json-api/removeacct*' => Http::response(['metadata' => ['result' => 1]])]);
        $this->artisan('cloud:release-retry')->assertOk();

        $this->assertSame(\App\Models\Service::PROVISION_NONE, $s->fresh()->provision_status);
        $this->assertSame(2, (int) Server::firstOrFail()->active_accounts,
            'ظرفیت وقتی آزاد می‌شود که حذف واقعاً انجام شده باشد');
    }
    /**
     * 🔴 پرداختِ فاکتورِ بازِ یک سرویسِ حذف‌شده نباید آن را زنده کند.
     *
     * فاکتورِ تمدید چند روز پیش از سررسید صادر می‌شود. اگر مشتری بعدش سرویس را
     * حذف کند، آن فاکتور هنوز در پنلش هست و قابلِ پرداخت. `PaymentService`
     * فقط `cancelled` را «مرده» می‌شمرد، پس `terminated` بی‌صدا از کنارش رد
     * می‌شد و بلوکِ فعال‌سازی اجرا می‌شد: مشتری پول می‌داد و سرویسی «فعال»
     * می‌شد که سرورش واقعاً نزدِ زیرساخت پاک شده بود.
     */
    public function test_paying_a_stale_invoice_does_not_resurrect_a_terminated_service(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $s->update(['status' => 'terminated', 'cancelled_at' => now(), 'provision_status' => 'none']);

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

        app(\App\Services\Payment\PaymentService::class)
            ->settleConfirmed($payment, 'REF-'.random_int(1000, 9999));

        $fresh = $s->fresh();
        $this->assertSame('terminated', $fresh->status,
            'سرویسِ حذف‌شده نباید با پرداختِ فاکتورِ کهنه دوباره فعال شود');
        $this->assertNotSame('pending', $fresh->provision_status,
            'نباید دوباره وارد صفِ تحویل شود');
    }
    /** ✅ مسیرِ موفق — بی‌این، فقط ثابت کرده‌ایم که حذف *نمی‌کند* */
    public function test_correct_code_terminates_the_service(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate", ['code' => $this->sentCode()])
            ->assertRedirect();

        $this->assertSame('terminated', $s->fresh()->status);
        $this->assertNotNull($s->fresh()->cancelled_at);
    }

    /**
     * 🔴 کارفرما: «سرویسی که کاربر حذف می‌کند دیگر در بخشِ سرویس‌هایش نباشد.»
     * حذف که شد، از فهرست می‌رود؛ ردِ مالی‌اش در فاکتورها می‌مانَد.
     */
    public function test_terminated_service_disappears_from_the_list(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c, ['name' => 'سرور قدیمی من']);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->getContent();
        $this->assertStringContainsString('سرور قدیمی من', $html, 'پیش از حذف باید دیده شود');

        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");
        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate", ['code' => $this->sentCode()]);

        $this->assertSame('terminated', $s->fresh()->status);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringNotContainsString('سرور قدیمی من', $html, 'حذف‌شده نباید بماند');
    }

    /** فهرست باید **سفید** باشد: فقط زنده‌ها و در حالِ تحویل */
    public function test_only_live_services_are_listed(): void
    {
        $c = $this->customer();

        $this->activeService($c, ['name' => 'زنده', 'status' => 'active']);
        $this->activeService($c, ['name' => 'معلق بابت فاکتور', 'status' => 'suspended']);
        $this->activeService($c, ['name' => 'در حال تحویل', 'status' => 'awaiting_provision', 'provision_status' => 'pending']);
        $this->activeService($c, ['name' => 'حذف شده من', 'status' => 'terminated']);
        $this->activeService($c, ['name' => 'لغو شده من', 'status' => 'cancelled']);
        $this->activeService($c, ['name' => 'منقضی من', 'status' => 'expired']);
        $this->activeService($c, ['name' => 'منتظر پرداخت اولیه', 'status' => 'pending']);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        foreach (['زنده', 'معلق بابت فاکتور', 'در حال تحویل'] as $shown) {
            $this->assertStringContainsString($shown, $html, "«{$shown}» باید دیده شود");
        }

        foreach (['حذف شده من', 'لغو شده من', 'منقضی من', 'منتظر پرداخت اولیه'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, "«{$hidden}» نباید دیده شود");
        }
    }

    /** 🔴 کدِ مصرف‌شده نباید بارِ دوم روی سرویسِ دیگری کار کند */
    public function test_a_used_code_cannot_be_replayed(): void
    {
        $c = $this->customer();
        $first = $this->activeService($c, ['name' => 'اولی']);
        $second = $this->activeService($c, ['name' => 'دومی']);

        $this->actingAs($c, 'customer')->post("/account/services/{$first->id}/terminate/start");
        $code = $this->sentCode();

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$first->id}/terminate", ['code' => $code]);

        $this->assertSame('terminated', $first->fresh()->status);

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$second->id}/terminate", ['code' => $code])
            ->assertSessionHasErrors();

        $this->assertSame('active', $second->fresh()->status);
    }

    public function test_terminate_without_a_code_does_nothing(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate", ['code' => '123456'])
            ->assertSessionHasErrors();

        $this->assertSame('active', $s->fresh()->status, 'بدونِ درخواستِ کد نباید حذف شود');
    }

    /** 🔴 سرویسِ شخصِ دیگر — نه کد بگیرد نه حذف کند */
    public function test_cannot_touch_someone_elses_service(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        $s = $this->activeService($other);

        $this->actingAs($mine, 'customer')
            ->post("/account/services/{$s->id}/terminate/start")->assertNotFound();

        $this->actingAs($mine, 'customer')
            ->post("/account/services/{$s->id}/terminate", ['code' => '111111'])->assertNotFound();

        $this->assertSame('active', $s->fresh()->status);
    }

    public function test_start_issues_a_challenge_and_remembers_the_service(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate/start")
            ->assertRedirect()
            ->assertSessionHas('svc_terminate_ctx.service_id', $s->id);

        $this->assertDatabaseHas('otp_challenges', ['purpose' => 'service_terminate']);
        $this->assertSame('active', $s->fresh()->status, 'صدورِ کد به‌تنهایی نباید چیزی را حذف کند');
    }

    /**
     * 🔴 مهم‌ترین محافظ: کدِ صادرشده برای سرویسِ الف نباید سرویسِ ب را حذف کند.
     * بی‌این قید، «کدِ حذف» می‌شد یک کلیدِ عمومیِ حذف برای همهٔ سرویس‌ها.
     */
    public function test_a_code_issued_for_one_service_cannot_delete_another(): void
    {
        $c = $this->customer();
        $cheap = $this->activeService($c, ['name' => 'سرویس ارزان']);
        $pricey = $this->activeService($c, ['name' => 'سرویس گران']);

        $this->actingAs($c, 'customer')->post("/account/services/{$cheap->id}/terminate/start");

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$pricey->id}/terminate", ['code' => '123456'])
            ->assertSessionHasErrors();

        $this->assertSame('active', $pricey->fresh()->status);
    }

    /** سرویسِ تحویل‌نشده از این مسیر نمی‌رود (مسیرِ لغو با بازگشتِ وجه را دارد) */
    public function test_undelivered_service_uses_the_cancel_path_not_this_one(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c, ['status' => 'awaiting_provision', 'provision_status' => 'pending']);

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate/start")
            ->assertSessionHasErrors();
    }

    /** سرویسِ ازقبل‌حذف‌شده دوباره حذف نمی‌شود */
    public function test_already_terminated_service_is_rejected(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c, ['status' => 'terminated']);

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate/start")
            ->assertSessionHasErrors();
    }

    /** کدِ غلط نباید حذف کند */
    public function test_wrong_code_does_not_delete(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c);

        $this->actingAs($c, 'customer')->post("/account/services/{$s->id}/terminate/start");

        $this->actingAs($c, 'customer')
            ->post("/account/services/{$s->id}/terminate", ['code' => '000000'])
            ->assertSessionHasErrors();

        $this->assertSame('active', $s->fresh()->status);
    }

    /** دکمهٔ حذف فقط برای سرویسِ تحویل‌شده دیده شود */
    public function test_delete_button_only_shows_for_delivered_services(): void
    {
        $c = $this->customer();
        $live = $this->activeService($c, ['name' => 'سرویس زنده']);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringContainsString(
            route('account.services.terminate.start', $live, false), $html);
        $this->assertStringContainsString('حذف سرویس', $html);
        $this->assertStringNotContainsString('ui.svc_terminate', $html, 'کلیدِ خام نباید چاپ شود');
    }

    /** وضعیتِ «حذف شده» باید برچسبِ فارسی داشته باشد نه واژهٔ انگلیسیِ خام */
    public function test_terminated_status_has_a_persian_badge(): void
    {
        $c = $this->customer();
        $s = $this->activeService($c, ['status' => 'terminated']);

        $this->assertSame('حذف شده', $s->statusBadge()[0]);
    }
}
