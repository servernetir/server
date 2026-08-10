<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudFraudGuard;
use App\Services\Cloud\CloudProvisioner;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * 🔴 درِ خروجِ صفِ بازبینی — «می‌دانم، بساز».
 *
 * ═══ خرابی‌ای که این فایل می‌بندد (اندازه‌گیری‌شده روی پروداکشن) ═══
 *
 * پنج سفارش در `provision_status='manual'` نشسته بودند و **تنها درِ خروج**
 * («تلاشِ دوباره»ی مدیر) از خودِ همان محافظی رد می‌شد که پارکشان کرده بود. هر
 * POST کدِ ۲۰۰ می‌داد و هر پنج ردیف سرِ جایشان می‌ماندند. پیامِ خطا هم به مدیر
 * می‌گفت «نیازمندِ تأییدِ دستی» در حالی که **هیچ افورد‌نسِ تأییدی در محصول
 * وجود نداشت**.
 *
 * ═══ چه چیزی این‌جا قفل می‌شود ═══
 *
 * ۱) رهاسازی واقعاً کار می‌کند: ردیفِ پارک‌شده تحویل می‌شود.
 * ۲) 🔴 و **دو بار نمی‌خرد** — مهم‌ترین ادعای این فایل، چون تنها اشتباهِ
 *    این حوزه که پولِ واقعی می‌سوزاند همین است.
 * ۳) یک‌بارمصرف است: محافظ برای سفارشِ بعدیِ همان مشتری همچنان کار می‌کند.
 * ۴) هیچ‌چیزِ زیرِ محافظ دور زده نمی‌شود (موجودی، درایور، سیستم‌عامل).
 * ۵) ردِ حسابرسی می‌مانَد: چه کسی، کِی، و محافظ چه چیزی را علامت زده بود.
 * ۶) از سمتِ مشتری غیرقابلِ دسترس است.
 *
 * ⚠️ هیچ تماسِ واقعیِ API. زیرساخت سندباکس ندارد؛ هر سفارشِ واقعی پولِ واقعی است.
 */
class CloudGuardOverrideTest extends TestCase
{
    use RefreshDatabase;

    /** تعدادِ سفارش‌های واقعی‌ای که به زیرساخت رفت */
    private int $orders = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('pricing_rate_override', '100000');
        Setting::putSecret('aeza_api_token', 'aeza-key');

        Sleep::fake();
        Mail::fake();
        ErrorTracker::clear();
    }

    // ───────────────────────── فیکسچرها ─────────────────────────

    private function admin(string $name = 'مدیرِ آزمون'): User
    {
        return User::create([
            'name' => $name, 'email' => 'a'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ])->fresh();
    }

    private function plan(array $over = []): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        CloudImage::firstOrCreate(
            ['provider' => 'aeza', 'key' => 'ubuntu-24.04', 'arch' => 'x86'],
            ['provider_ref' => 'ubuntu_2404', 'kind' => 'os', 'family' => 'ubuntu',
                'version' => '24.04', 'label' => 'Ubuntu 24.04', 'is_active' => true]
        );

        return CloudPlan::create(array_merge([
            'provider' => 'aeza', 'provider_ref' => '153',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein-'.random_int(1, 99999),
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $over));
    }

    private function service(Customer $c, CloudPlan $plan, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرورِ ابری CV-2-4', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
        ], $over));
    }

    /** مشتری‌ای که سقفِ روزانه را رد کرده — همان چیزی که پروداکشن دارد */
    private function burstingCustomer(CloudPlan $plan): Customer
    {
        $c = $this->customer();

        foreach (range(1, CloudFraudGuard::DAILY_MAX) as $i) {
            $this->service($c, $plan, ['provision_status' => 'manual']);
        }

        return $c->fresh();
    }

    /**
     * زیرساختِ ساختگی که **هر سفارش را می‌شمارد**.
     *
     * ⚠️ شمارش روی مسیرِ سفارش است نه روی تعدادِ کلِ درخواست‌ها: خواندنِ فهرست
     * و گرفتنِ وضعیت پول خرج نمی‌کنند، و اگر با هم شمرده شوند این تست دیگر
     * چیزی دربارهٔ «دو بار خریدن» ثابت نمی‌کند.
     */
    private function fakeProvider(): void
    {
        $this->orders = 0;

        Http::swap(new Factory);
        Http::fake(function ($request) {
            $url = $request->url();

            if ($request->method() === 'POST' && str_contains($url, 'services/orders')) {
                $this->orders++;

                return Http::response(['data' => ['id' => 90210, 'items' => [['id' => 90210]]]], 200);
            }

            if (str_contains($url, 'password')) {
                return Http::response(['data' => ['password' => 'RootPw42!!']], 200);
            }

            if (str_contains($url, '/services')) {
                return Http::response(['data' => ['total' => 0, 'items' => []]], 200);
            }

            return Http::response(['data' => [
                'id' => 90210, 'currentStatus' => 'active', 'ip' => ['203.0.113.44'],
            ]], 200);
        });
    }

    private function override(Service $s, ?User $admin = null)
    {
        return $this->actingAs($admin ?? $this->admin(), 'web')
            ->post('/admin/services/'.$s->id.'/provision-override');
    }

    // ═══════════════ ۱) درِ خروج واقعاً باز است ═══════════════

    /**
     * 🔴 ادعای مرکزی. همان کاری که کارفرما پنج بار کرد و هیچ اثری نداشت،
     * حالا باید سرور بسازد.
     */
    public function test_the_override_actually_releases_a_parked_order(): void
    {
        $plan = $this->plan();
        $c = $this->burstingCustomer($plan);

        // ششمین سفارش — همان که محافظ پارکش می‌کند
        $s = $this->service($c, $plan);
        app(CloudProvisioner::class)->provision($s->fresh());

        $this->assertSame('manual', $s->fresh()->provision_status,
            'پیش‌شرطِ تست: سفارش باید پارک شده باشد، وگرنه چیزی سنجیده نمی‌شود');

        $this->fakeProvider();
        $this->override($s)->assertRedirect();

        $fresh = $s->fresh();

        $this->assertSame('done', $fresh->provision_status, 'رهاسازی باید تحویل را به سرانجام برساند');
        $this->assertSame('active', $fresh->status);
        $this->assertSame(1, $this->orders, 'دقیقاً یک سفارش باید به زیرساخت رفته باشد');
        $this->assertNotNull(CloudInstance::where('service_id', $s->id)->first()?->provider_ref,
            'ردیفِ نمونه باید شناسهٔ زیرساخت گرفته باشد');
    }

    // ═══════════════ ۲) 🔴 هرگز دو بار نمی‌خرد ═══════════════

    /**
     * 🔴 گران‌ترین اشتباهِ ممکن در این حوزه.
     *
     * سه فشارِ هم‌زمان روی یک ردیف: دو بار زدنِ دکمهٔ رهاسازی، و کرونِ
     * `provision:run` وسطش. هر سه از **همان** مسیرِ تحویل رد می‌شوند، پس هر سه
     * لایهٔ ضدِ خریدِ دوباره باید کار کنند — قفلِ اتمیِ `pending→running`،
     * شاخهٔ `adoptExisting()` وقتی `provider_ref` پر است، و نامِ قطعیِ
     * `sn-svc-{id}`.
     *
     * ⚠️ چیزی که این تست **واقعاً** می‌سنجد تعدادِ POSTِ سفارش است، نه وضعیتِ
     * نهاییِ ردیف. وضعیتِ نهایی در هر دو حالت `done` است؛ تفاوتِ «یک سرور» و
     * «دو سرور» فقط در همین شمارنده دیده می‌شود.
     */
    public function test_the_override_can_never_buy_a_second_server(): void
    {
        $plan = $this->plan();
        $c = $this->burstingCustomer($plan);

        $s = $this->service($c, $plan);
        app(CloudProvisioner::class)->provision($s->fresh());
        $this->assertSame('manual', $s->fresh()->provision_status);

        $this->fakeProvider();

        $admin = $this->admin();

        $this->override($s, $admin);                          // بارِ اول: می‌خرد
        $this->override($s, $admin);                          // بارِ دوم: نباید بخرد
        $this->artisan('provision:run');                      // کرون هم وسطش
        app(CloudProvisioner::class)->provision($s->fresh()); // و یک تلاشِ مستقیم

        $this->assertSame(1, $this->orders,
            'بیش از یک سفارش به زیرساخت رفت — یعنی رهاسازی می‌تواند پولِ دو سرور را بسوزاند');

        $this->assertSame(1, CloudInstance::where('service_id', $s->id)->count(),
            'برای یک سرویس بیش از یک ردیفِ نمونه ساخته شد');
    }

    /**
     * و برادرانِ پارک‌شده‌اش **دست‌نخورده** می‌مانند.
     *
     * رهاسازی تک‌سرویس است. اگر روزی به یک سوییچِ سراسری تبدیل شود، این تست
     * قرمز می‌شود — و باید بشود.
     */
    public function test_the_override_does_not_release_the_customers_other_parked_orders(): void
    {
        $plan = $this->plan();
        $c = $this->burstingCustomer($plan);

        $s = $this->service($c, $plan);
        app(CloudProvisioner::class)->provision($s->fresh());

        $this->fakeProvider();
        $this->override($s);

        foreach (Service::where('customer_id', $c->id)->where('id', '!=', $s->id)->get() as $sibling) {
            $this->assertSame('manual', $sibling->provision_status,
                'سرویس #'.$sibling->id.' هم آزاد شد — رهاسازی باید فقط روی یک سرویس اثر کند');
        }

        $this->assertSame(1, $this->orders, 'فقط سرویسِ رهاشده باید خرید کند');
    }

    /**
     * یک‌بارمصرف: علامت **مصرف** می‌شود، ذخیره نمی‌ماند.
     *
     * بی‌این، اولین رهاسازی عملاً حسابِ آن مشتری را برای همیشه معاف می‌کرد —
     * دقیقاً همان معافیتِ ماندگاری که عمداً نساختیم.
     */
    public function test_the_override_is_single_use_and_the_guard_still_works_afterwards(): void
    {
        $plan = $this->plan();
        $c = $this->burstingCustomer($plan);

        $s = $this->service($c, $plan);
        app(CloudProvisioner::class)->provision($s->fresh());

        $this->fakeProvider();
        $this->override($s);

        $this->assertSame('done', $s->fresh()->provision_status);
        $this->assertFalse(CloudProvisioner::overrideRequested($s->fresh()),
            'علامتِ رهاسازی باید مصرف شده باشد');

        // سفارشِ بعدیِ همان مشتری — محافظ باید باز هم نگهش دارد
        $next = $this->service($c->fresh(), $plan);
        app(CloudProvisioner::class)->provision($next->fresh());

        $this->assertSame('manual', $next->fresh()->provision_status,
            'محافظ بعد از یک رهاسازی خاموش شده — یعنی معافیتِ دائمی ساخته‌ایم');
        $this->assertSame(1, $this->orders, 'سفارشِ نگه‌داشته‌شده نباید هیچ خریدی کرده باشد');
    }

    // ═══════════════ ۳) چیزی جز محافظ دور زده نمی‌شود ═══════════════

    /**
     * ⚠️ رهاسازی فقط `$verdict['hold']` را کنار می‌گذارد.
     *
     * موجودی، درایورِ پیکربندی‌شده و در دسترس بودنِ سیستم‌عامل همه **زیرِ**
     * محافظ‌اند و از تحویلِ چیزی که تحویل‌شدنی نیست جلوگیری می‌کنند. رهاسازی‌ای
     * که از رویشان بپرد، بن‌بست را با «پولِ گرفته‌شده برای سرورِ ناممکن» عوض
     * می‌کند — معاملهٔ بدتری.
     */
    public function test_the_override_does_not_skip_the_stock_check(): void
    {
        $plan = $this->plan(['in_stock' => false]);
        $c = $this->burstingCustomer($plan);

        $s = $this->service($c, $plan);
        app(CloudProvisioner::class)->provision($s->fresh());

        $this->fakeProvider();
        $this->override($s);

        $this->assertSame(0, $this->orders,
            'پلنِ ناموجود سفارش داده شد — رهاسازی از محافظِ موجودی هم پریده است');
        $this->assertSame('pending', $s->fresh()->provision_status,
            'ظرفیتِ تمام‌شده خرابیِ گذراست: باید pending بمانَد تا کرون دوباره تلاش کند');
    }

    // ═══════════════ ۴) ردِ حسابرسی ═══════════════

    /**
     * «چه کسی اجازه داد» باید **بعد از** تحویلِ موفق هم خواندنی باشد.
     *
     * 🔴 برای همین سند در `ActivityLog` و ردیابِ خطا می‌نشیند نه فقط در
     * `provision_meta`: `finalize()` آن ستون را یکجا بازنویسی می‌کند، یعنی
     * دقیقاً در لحظه‌ای که این اطلاعات بیشترین ارزش را دارد پاک می‌شد.
     */
    public function test_the_override_is_recorded_with_who_when_and_what_was_flagged(): void
    {
        $plan = $this->plan();
        $c = $this->burstingCustomer($plan);

        $s = $this->service($c, $plan);
        app(CloudProvisioner::class)->provision($s->fresh());

        $flagged = (string) $s->fresh()->provision_error;
        $this->assertStringContainsString('تأییدِ دستی', $flagged);

        $this->fakeProvider();
        $this->override($s, $this->admin('کارفرما'));

        $this->assertSame('done', $s->fresh()->provision_status);

        $log = ActivityLog::where('service_id', $s->id)->get()
            ->map(fn ($r) => (string) $r->description)->implode("\n");

        $this->assertStringContainsString('کارفرما', $log, 'نامِ مدیرِ تصمیم‌گیرنده ثبت نشده');
        $this->assertStringContainsString('محافظ', $log, 'ماهیتِ تصمیم در تاریخچه پیدا نیست');
        $this->assertStringContainsString('۲۴ ساعت', $log,
            'نشانه‌ای که محافظ زده بود باید در تاریخچه بمانَد — وگرنه دلیلِ تصمیم گم می‌شود');

        $notes = collect(ErrorTracker::recent(200, 'error'))
            ->filter(fn ($e) => ($e['area'] ?? '') === 'fraud-guard')
            ->map(fn ($e) => (string) ($e['message'] ?? ''))->implode("\n");

        $this->assertStringContainsString('کارفرما', $notes,
            'ردیابِ خطا باید رهاسازی را ثبت کند — این تنها سطحی است که مدیر مرور می‌کند');
    }

    // ═══════════════ ۵) از سمتِ مشتری غیرقابلِ دسترس ═══════════════

    /** مشتریِ واردشده روی گاردِ `customer` هیچ راهی به این روت ندارد */
    public function test_a_logged_in_customer_cannot_reach_the_override(): void
    {
        $plan = $this->plan();
        $c = $this->burstingCustomer($plan);
        $s = $this->service($c, $plan, ['provision_status' => 'manual']);

        $this->fakeProvider();

        $this->actingAs($c, 'customer')
            ->post('/admin/services/'.$s->id.'/provision-override')
            ->assertRedirect();      // به ورودِ مدیر — هرگز اجرا نمی‌شود

        $this->assertSame('manual', $s->fresh()->provision_status);
        $this->assertFalse(CloudProvisioner::overrideRequested($s->fresh()));
        $this->assertSame(0, $this->orders);
    }

    public function test_an_anonymous_request_cannot_reach_the_override(): void
    {
        $plan = $this->plan();
        $s = $this->service($this->burstingCustomer($plan), $plan, ['provision_status' => 'manual']);

        $this->fakeProvider();
        $this->post('/admin/services/'.$s->id.'/provision-override')->assertRedirect();

        $this->assertSame('manual', $s->fresh()->provision_status);
        $this->assertSame(0, $this->orders);
    }

    /**
     * 🔴 و علامتِ رهاسازی از هیچ مسیرِ مشتری‌ای **نوشتنی** نیست.
     *
     * `provision_meta` در `Service::$fillable` است. امروز هیچ کنترلرِ
     * مشتری‌محوری دادهٔ درخواست را روی `Service` mass-assign نمی‌کند، ولی از
     * لحظه‌ای که علامتِ رهاسازی آن‌جا می‌نشیند، آن «امروز» بارِ امنیتی پیدا
     * می‌کند: یک `$service->update($request->all())` در آینده، محافظ را به
     * مشتری تحویل می‌دهد.
     */
    public function test_no_customer_facing_controller_mass_assigns_onto_a_service(): void
    {
        $bad = [];

        foreach (['app/Http/Controllers/Account', 'app/Http/Controllers/Api'] as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }

                // ⚠️ کامنت‌ها اول پاک می‌شوند، وگرنه نثرِ خودمان تست را قرمز می‌کند.
                $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($file->getPathname()));

                if (preg_match('~->update\(\s*\$request\b|->fill\(\s*\$request\b|->update\(\s*\$data\s*\)~', (string) $code)) {
                    $bad[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $bad,
            "\nاین کنترلرهای مشتری‌محور دادهٔ خام را روی مدل می‌نویسند.\n"
            ."اگر مدلش `Service` باشد، مشتری می‌تواند `provision_meta` و در نتیجه\n"
            .'علامتِ رهاسازیِ محافظ را خودش بنویسد:'."\n".implode("\n", $bad));
    }

    // ═══════════════ ۶) سقف‌های تنظیم‌شدنی (بندِ ب) ═══════════════

    /**
     * سقف از تنظیمات خوانده می‌شود، **بی‌آنکه تست خودش مقدارش را جعل کند**.
     *
     * ⚠️ این تست عمداً از راهِ `Setting::put` می‌رود و بعد سیمِ واقعی را
     * می‌دواند. `config([...])`ِ دستی هرگز سیم‌کشی را نمی‌سنجد — همان تله‌ای که
     * یک بار درایورِ پیامک را ماه‌ها خاموش نگه داشت.
     */
    public function test_the_daily_cap_is_configurable_and_applies_to_everyone_equally(): void
    {
        $plan = $this->plan();
        $c = $this->customer();

        // ⚠️ حسابِ قدیمی، وگرنه قاعدهٔ «حسابِ نوپا» می‌گیرد و این تست دربارهٔ
        //    سقفِ روزانه چیزی ثابت نمی‌کند.
        $c->forceFill(['created_at' => now()->subMonths(6)])->save();

        foreach (range(1, 6) as $i) {
            $this->service($c, $plan, ['provision_status' => 'manual']);
        }

        $this->assertTrue(app(CloudFraudGuard::class)->check($c->fresh())['hold'],
            'با سقفِ پیش‌فرض باید نگه دارد');

        Setting::put('cloud_guard_daily_max', '20');

        $this->assertFalse(app(CloudFraudGuard::class)->check($c->fresh())['hold'],
            'سقفِ تنظیم‌شده خوانده نشد — یعنی فرمِ مدیر هیچ اثری ندارد');
        $this->assertSame(20, CloudFraudGuard::dailyMax());
    }

    /**
     * 🔴 و فرمِ واقعی واقعاً وصل است — سرتاسر، از POSTِ مدیر تا حکمِ محافظ.
     *
     * ⚠️ چرا این تستِ جدا لازم است: تستِ بالا خودش `Setting::put` می‌زند، پس
     * ثابت می‌کند «محافظ تنظیمات را می‌خوانَد» ولی نه «فرم آن تنظیم را
     * می‌نویسد». این پروژه دقیقاً از همین شکاف ضربه خورده — درایورِ پیامک
     * ماه‌ها خاموش بود چون هر تست مقدار را خودش ست می‌کرد و مسیرِ واقعیِ
     * پیکربندی هرگز دویده نمی‌شد.
     */
    public function test_the_admin_settings_form_is_really_wired_to_the_guard(): void
    {
        $plan = $this->plan();
        $c = $this->customer();
        $c->forceFill(['created_at' => now()->subMonths(6)])->save();

        foreach (range(1, 6) as $i) {
            $this->service($c, $plan, ['provision_status' => 'manual']);
        }

        $this->assertTrue(app(CloudFraudGuard::class)->check($c->fresh())['hold']);

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/settings', ['cloud_guard_daily_max' => 25])
            ->assertRedirect();

        $this->assertSame('25', Setting::get('cloud_guard_daily_max'),
            'فرم مقدار را ذخیره نکرد — فیلدی که چیزی را عوض نمی‌کند از نبودنش بدتر است');

        $this->assertFalse(app(CloudFraudGuard::class)->check($c->fresh())['hold'],
            'مقدار ذخیره شد ولی محافظ همچنان با عددِ قبلی کار می‌کند');
    }

    /**
     * مقدارِ بی‌معنی سقف را **باز نمی‌کند**.
     *
     * یک رشتهٔ خالی یا صفر در جدولِ تنظیمات نباید بی‌صدا محافظ را خاموش کند —
     * آن دقیقاً همان «شکست نمی‌خورد، فقط اتفاق نمی‌افتد»ی است که این پروژه
     * بارها خورده.
     */
    public function test_a_nonsense_threshold_falls_back_to_the_strict_code_default(): void
    {
        foreach (['', 'abc', '0', '-3'] as $junk) {
            Setting::put('cloud_guard_daily_max', $junk);

            $this->assertSame(CloudFraudGuard::DAILY_MAX, CloudFraudGuard::dailyMax(),
                'مقدارِ «'.$junk.'» باید نادیده گرفته شود، نه اینکه سقف را عوض کند');
        }
    }

    /**
     * 🔴 و هیچ **معافیتِ حساب** وجود ندارد.
     *
     * وسوسهٔ ساده این بود که حسابِ کارفرما از شمارش معاف شود. آن یعنی گران‌ترین
     * حسابِ سیستم یک خطِ بی‌سقف و بی‌بازبینی به APIِ پولیِ زیرساخت داشته باشد؛
     * اگر روزی لو برود، همان شعاعِ انفجاری می‌شود که این محافظ برای بستنش
     * نوشته شد. این تست نبودنش را قفل می‌کند.
     */
    public function test_there_is_no_per_account_exemption_anywhere_in_the_guard(): void
    {
        $src = (string) file_get_contents(app_path('Services/Cloud/CloudFraudGuard.php'));
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);   // نثرِ خودمان را نسنج

        foreach (['exempt', 'whitelist', 'allow_list', 'is_owner', 'owner_id', 'trusted_customer'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $code,
                'محافظ نشانه‌ای از معافیتِ حساب دارد («'.$needle.'») — تصمیمِ ثبت‌شده «سقفِ تنظیم‌شدنی» بود، نه معافیت');
        }
    }

    // ═══════════════ ۷) سرویسِ یتیم دیگر از محافظ رد نمی‌شود ═══════════════

    /**
     * 🔴 تنها مسیری که به پولِ واقعی می‌رسید و **هیچ** بررسیِ سوءاستفاده‌ای
     * نداشت.
     *
     * `services.customer_id` کلیدِ خارجیِ واقعی ندارد، پس مشتریِ حذف‌شده یک
     * سرویسِ یتیم جا می‌گذارد. شکلِ قبلی کلِ محافظ را در
     * `if ($service->customer !== null)` پیچیده بود — یعنی آن ردیف مستقیم
     * می‌رفت سراغِ خرید.
     */
    public function test_an_orphan_service_is_held_instead_of_silently_buying(): void
    {
        $plan = $this->plan();
        $c = $this->customer();
        $s = $this->service($c, $plan);

        // مشتری از دیتابیس می‌رود؛ سرویس (بی‌کلیدِ خارجی) می‌مانَد
        Customer::whereKey($c->id)->delete();

        $this->fakeProvider();
        app(CloudProvisioner::class)->provision($s->fresh());

        $this->assertSame('manual', $s->fresh()->provision_status,
            'سرویسِ بی‌مشتری باید نگه داشته شود، نه اینکه بی‌بررسی خرید کند');
        $this->assertSame(0, $this->orders);
    }
}
