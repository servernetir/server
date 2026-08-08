<?php

namespace Tests\Feature;

use App\Mail\ServiceReadyMail;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Post;
use App\Models\PostTranslation;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\AezaClient;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * سه خرابیِ واقعی روی مسیرِ تحویل، که کارفرما پس از یک خریدِ **ساعتیِ واقعی**
 * گزارش کرد. کارفرما: «نباید همچین سوتی‌هایی بدیم و کاربر باید تجربهٔ خوبی
 * کسب کند.»
 *
 *  ۱) ایمیلِ تحویل بی‌IP و بی‌رمز می‌رسید. زیرساختِ دوم در پاسخِ سفارش IP نمی‌دهد
 *     (ماشین `activating` است)، و کد به‌جای صبر کردن `?: '—'` چاپ می‌کرد.
 *  ۲) پنل «ساخته شد» می‌گفت در حالی که زیرساخت `activating` می‌گفت.
 *  ۳) هیچ تجربهٔ زنده‌ای در حینِ ساخت نبود.
 *
 * ═══ قراردادهای این فایل ═══
 *
 * ⚠️ **هیچ تماسِ واقعیِ API.** زیرساختِ دوم سندباکس ندارد؛ هر سفارشِ واقعی پولِ
 * واقعی است.
 *
 * ⚠️ `Http::fake()` استابها را به **ترتیبِ ثبت** می‌سنجد و اولین تطبیق برنده
 * است، پس یک استابِ همه‌گیرِ قبلی هر `fake()` بعدی را بی‌اثر می‌کند. هر جا لازم
 * است دورِ دومی با پاسخِ دیگر بزنیم، با `Http::swap(new Factory)` از صفر شروع
 * می‌کنیم تا تست بی‌صدا هیچ‌چیز نسنجد.
 *
 * ⚠️ «کدِ ۲۰۰ یعنی هیچ.» این تست‌ها **مقدارِ دیداری** را می‌سنجند: خودِ IP در
 * ایمیل و در خطِ SSH، و اینکه چند مرحله «جاری» است.
 */
class CloudDeliveryReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('pricing_rate_override', '100000');
        Setting::putSecret('aeza_api_token', 'aeza-key');
        Setting::putSecret('hetzner_api_token', 'hetzner-key');

        // درایورِ زیرساختِ دوم بینِ دو مرحلهٔ سفارش کوتاه می‌خوابد؛ بی‌این، هر
        // تستِ این مسیر ۷٫۵ ثانیه به سوئیت اضافه می‌کند.
        Sleep::fake();
        Mail::fake();
    }

    // ───────────────────────── فیکسچرها ─────────────────────────

    private function customer(string $locale = 'fa'): Customer
    {
        return Customer::create([
            'email'    => 'rd'.random_int(1, 999999).'@example.com',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => $locale,
        ]);
    }

    private function plan(string $provider, array $over = []): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        return CloudPlan::create(array_merge([
            'provider' => $provider, 'provider_ref' => $provider === 'aeza' ? '153' : 'cx22',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    private function image(string $provider, string $ref): CloudImage
    {
        return CloudImage::create([
            'provider' => $provider, 'provider_ref' => $ref, 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
            'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'is_active' => true,
        ]);
    }

    private function service(CloudPlan $plan, ?Customer $customer = null): Service
    {
        return Service::create([
            'customer_id' => ($customer ?? $this->customer())->id,
            'name' => 'سرورِ ابری CV-2-4', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
        ]);
    }

    /**
     * سفارشِ پذیرفته‌شدهٔ زیرساختِ دوم که **هنوز سرور نساخته**.
     *
     * دقیقاً همان چیزی که روی خریدِ واقعی رخ داد: سفارش ثبت می‌شود،
     * `createdServiceIds` خالی است، پس ref با پیشوندِ `order:` می‌نشیند و IP
     * وجود ندارد.
     */
    private function aezaOrderAcceptedWithoutServer(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'services/orders') && $request->method() === 'POST') {
                return Http::response(['data' => ['id' => 5150, 'createdServiceIds' => []]], 200);
            }

            // پی‌گیریِ سفارش: هنوز سرویسی ساخته نشده
            return Http::response(['data' => ['id' => 5150, 'createdServiceIds' => []]], 200);
        });
    }

    /** سرویسِ زیرساختِ دوم که سفارشش پذیرفته شده ولی سرور نساخته است */
    private function orderedButNotBuilt(?Customer $customer = null): Service
    {
        $plan = $this->plan('aeza');
        $this->image('aeza', 'ubuntu_2404');
        $service = $this->service($plan, $customer);

        $this->aezaOrderAcceptedWithoutServer();
        app(ProvisioningService::class)->provision($service);

        return $service->fresh();
    }

    /** استابِ «سفارش بسته شد و سرور با وضعیتِ داده‌شده بالا آمد» */
    private function fakeAezaResolves(string $status, ?string $ip): void
    {
        Http::swap(new Factory);          // ⚠️ استابِ قبلی وگرنه برنده می‌مانَد

        Http::fake(function ($request) use ($status, $ip) {
            if (str_contains($request->url(), 'services/orders/5150')
                || str_contains($request->url(), 'orders/5150')) {
                return Http::response(['data' => ['createdServiceIds' => [8801]]], 200);
            }

            if (str_contains($request->url(), 'changePassword')
                || str_contains($request->url(), 'password')) {
                return Http::response(['data' => ['password' => 'AezaRootPw42']], 200);
            }

            $body = ['id' => 8801, 'currentStatus' => $status, 'name' => 'sn-svc-1'];

            if ($ip !== null) {
                $body['ip'] = [$ip];
            }

            return Http::response(['data' => $body], 200);
        });
    }

    // ═════════════════════════════════════════════════════════════════
    // باگ ۱ — ایمیلِ تحویل تا رسیدنِ IP نگه داشته می‌شود
    // ═════════════════════════════════════════════════════════════════

    /**
     * 🔴 قلبِ باگِ اول. کد از قبل **می‌دانست** IP ممکن است نباشد، چون خودش
     * `$instance->ipv4 ?: '—'` نوشته بود؛ به‌جای صبر کردن، خط تیره چاپ کرد.
     */
    public function test_no_delivery_email_while_the_provider_has_not_given_an_ip(): void
    {
        $service = $this->orderedButNotBuilt();

        Mail::assertNothingOutgoing();

        $inst = CloudInstance::where('service_id', $service->id)->first();
        $this->assertNull($inst->ipv4, 'فرضِ این تست: زیرساخت هنوز IP نداده');
        $this->assertNull($inst->ready_notified_at, 'ایمیل باید «بدهی» ثبت شده باشد');
        $this->assertTrue($inst->owesReadyNotice());
    }

    /** سفارش قبول شده، پس تحویل «شکست» نخورده — فقط ایمیل عقب افتاده */
    public function test_order_acceptance_is_still_recorded_as_a_successful_delivery(): void
    {
        $service = $this->orderedButNotBuilt();

        $this->assertSame('done', $service->provision_status);
        $this->assertSame('active', $service->status);
        $this->assertNull($service->provision_error);
    }

    /**
     * کرونِ هر-دقیقه‌ای باید بدهی را ببیند و به‌محضِ رسیدنِ IP بفرستد.
     *
     * ⚠️ `active` و IP هر دو لازم‌اند. این دو رشته تنها وضعیت‌هایی‌اند که روی
     * سرورِ واقعیِ کارفرما دیده شده‌اند.
     */
    public function test_cron_sends_the_owed_email_once_the_ip_arrives(): void
    {
        $service = $this->orderedButNotBuilt();
        $this->fakeAezaResolves('active', '185.51.200.9');

        $r = app(CloudProvisioner::class)->syncInstances();

        $this->assertSame(1, $r['notified'], 'ایمیلِ بدهی‌مانده باید همین دقیقه برود');

        $inst = CloudInstance::where('service_id', $service->id)->first();
        $this->assertSame('185.51.200.9', $inst->ipv4);
        $this->assertNotNull($inst->ready_notified_at);

        Mail::assertSent(ServiceReadyMail::class, 1);
    }

    /**
     * 🔴 «هرگز دو بار.» کرون هر دقیقه می‌دود؛ بی‌قفل، مشتری روزی ۱۴۴۰ ایمیل
     * می‌گرفت.
     */
    public function test_the_owed_email_is_never_sent_twice(): void
    {
        $this->orderedButNotBuilt();
        $this->fakeAezaResolves('active', '185.51.200.9');

        app(CloudProvisioner::class)->syncInstances();
        app(CloudProvisioner::class)->syncInstances();
        $this->assertSame(0, app(CloudProvisioner::class)->deliverOwedNotices());

        Mail::assertSent(ServiceReadyMail::class, 1);
    }

    /**
     * ماشین `active` شده ولی IP نرسیده ⇒ **هنوز نه**. یک دقیقه تأخیر، در برابرِ
     * ایمیلی که مشتری با آن هیچ کاری نمی‌تواند بکند.
     */
    public function test_active_without_an_ip_still_holds_the_email(): void
    {
        $this->orderedButNotBuilt();
        $this->fakeAezaResolves('active', null);

        $r = app(CloudProvisioner::class)->syncInstances();

        $this->assertSame(0, $r['notified']);
        Mail::assertNothingOutgoing();
    }

    /** وضعیتِ `activating` هرگز نباید ایمیل را آزاد کند */
    public function test_activating_status_holds_the_email_even_with_an_ip(): void
    {
        $this->orderedButNotBuilt();
        $this->fakeAezaResolves('activating', '185.51.200.9');

        $this->assertSame(0, app(CloudProvisioner::class)->syncInstances()['notified']);
        Mail::assertNothingOutgoing();
    }

    /**
     * رگرسیون: زیرساختی که IP را **همان لحظه** می‌دهد نباید کند شود. مشتریِ
     * زیرساختِ اول باید ایمیلش را در همان ثانیهٔ تحویل بگیرد.
     */
    public function test_a_provider_that_returns_an_ip_immediately_still_emails_at_once(): void
    {
        $plan = $this->plan('hetzner');
        $this->image('hetzner', 'ubuntu-24.04');
        $service = $this->service($plan);

        Http::fake([
            '*/servers' => Http::response([
                'server' => [
                    'id' => 999, 'name' => 'sn-svc-1', 'status' => 'running',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.7'], 'ipv6' => ['ip' => null]],
                ],
                'root_password' => 'ImmediatePw9',
            ], 201),
            '*' => Http::response([], 200),
        ]);

        $this->assertTrue(app(ProvisioningService::class)->provision($service));

        Mail::assertSent(ServiceReadyMail::class, 1);
        $this->assertNotNull(CloudInstance::where('service_id', $service->id)->first()->ready_notified_at);
    }

    /**
     * زیرساختی که IP را زودتر از «بالا آمدن» می‌دهد (هتزنر لحظهٔ ساخت
     * `initializing` است ولی IP دارد): ایمیل باید تا واقعاً بالا آمدن صبر کند.
     * ماشینی که هنوز بوت نشده، ایمیلی که مشتری با آن SSH بزند نمی‌ارزد.
     */
    public function test_an_ip_before_the_machine_is_up_still_waits(): void
    {
        $service = $this->orderedButNotBuilt();

        CloudInstance::where('service_id', $service->id)
            ->update(['status' => 'building', 'ipv4' => '203.0.113.7', 'provider_ref' => '999']);

        Http::swap(new Factory);
        Http::fake();

        $this->assertSame(0, app(CloudProvisioner::class)->deliverOwedNotices());
        Mail::assertNothingOutgoing();
    }

    /**
     * 🔴 حفرهٔ خاموشی که خودِ این اصلاح می‌توانست بسازد.
     *
     * ایمیل حالا شرطی است. اگر ماشینی هرگز به «بالا آمده + IP» نرسد، ایمیل هرگز
     * نمی‌رود و **هیچ خطایی تولید نمی‌شود** — همان الگویی که این پروژه سه بار
     * خورده. پس نبودنِ ایمیل خودش باید دیده شود.
     */
    public function test_a_stalled_delivery_is_reported_to_the_admin(): void
    {
        $titles = $this->captureAdminTitles();

        $service = $this->orderedButNotBuilt();

        CloudInstance::where('service_id', $service->id)
            ->update(['created_at' => now()->subMinutes(45), 'status' => 'building']);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['data' => ['createdServiceIds' => []]], 200));

        app(CloudProvisioner::class)->syncInstances();

        $this->assertNotEmpty($this->stalledAmong($titles),
            'نبودنِ ایمیل باید خودش دیده شود، وگرنه سکوت است');
    }

    /**
     * سرویسِ لغوشده عمداً ایمیل نمی‌گیرد، پس بدهی‌اش برای همیشه باز می‌مانَد.
     * اگر هشدارِ «گیر کرده» آن را بشمارد، هر ساعت یک هشدارِ بی‌عمل می‌سازد — و
     * هشدارِ بی‌عمل، هشدارهای واقعی را بی‌اعتبار می‌کند.
     */
    public function test_a_dead_service_does_not_raise_the_stalled_warning(): void
    {
        $titles = $this->captureAdminTitles();

        $service = $this->orderedButNotBuilt();
        CloudInstance::where('service_id', $service->id)->update(['created_at' => now()->subMinutes(45)]);
        $service->forceFill(['status' => 'terminated'])->save();

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['data' => ['createdServiceIds' => []]], 200));

        app(CloudProvisioner::class)->syncInstances();

        $this->assertSame([], $this->stalledAmong($titles));
    }

    /** سرویسِ لغوشده نباید ایمیلِ «آماده شد» بگیرد */
    public function test_a_dead_service_never_gets_the_owed_email(): void
    {
        $service = $this->orderedButNotBuilt();

        CloudInstance::where('service_id', $service->id)
            ->update(['status' => 'running', 'ipv4' => '185.51.200.9']);
        $service->forceFill(['status' => 'cancelled'])->save();

        Http::swap(new Factory);
        Http::fake();

        $this->assertSame(0, app(CloudProvisioner::class)->deliverOwedNotices());
        Mail::assertNothingOutgoing();
    }

    // ═════════════════════════════════════════════════════════════════
    // باگ ۲ — پنل باید وضعیتِ **واقعیِ زیرساخت** را آینه کند
    // ═════════════════════════════════════════════════════════════════

    /**
     * ✅ رشتهٔ دیده‌شده روی سرورِ واقعی: در حینِ ساخت `activating`.
     * (کارفرما، خریدِ ساعتیِ مرداد ۱۴۰۵ — از پنلِ خودِ زیرساخت)
     */
    public function test_activating_is_mapped_to_building(): void
    {
        Http::fake(fn () => Http::response(
            ['data' => ['id' => 8801, 'currentStatus' => 'activating']], 200
        ));

        $r = app(AezaClient::class)->serverStatus('8801');

        $this->assertTrue($r['ok']);
        $this->assertSame('building', $r['status'], '«activating» یعنی هنوز در حالِ ساخت');
    }

    /** ✅ رشتهٔ دیده‌شدهٔ دوم: پس از پایانِ ساخت `active` */
    public function test_active_is_mapped_to_running(): void
    {
        Http::fake(fn () => Http::response(
            ['data' => ['id' => 8801, 'currentStatus' => 'active', 'ip' => ['185.51.200.9']]], 200
        ));

        $r = app(AezaClient::class)->serverStatus('8801');

        $this->assertSame('running', $r['status']);
        $this->assertSame('185.51.200.9', $r['ipv4']);
    }

    /**
     * 🔴 پیش‌فرضِ ایمن: رشتهٔ ناشناخته ⇒ `unknown` ⇒ **هرگز آماده**.
     *
     * فهرستِ رسمیِ وضعیت‌های این زیرساخت را نداریم (سندباکس ندارد). اگر فردا
     * رشتهٔ تازه‌ای بیاید، بدترین اتفاق باید یک دقیقه تأخیر باشد، نه ایمیلِ
     * «آماده شد» برای ماشینی که وجود ندارد.
     */
    public function test_an_unknown_provider_status_is_never_treated_as_ready(): void
    {
        Http::fake(fn () => Http::response(
            ['data' => ['id' => 8801, 'currentStatus' => 'some-state-we-have-never-seen',
                'ip' => ['185.51.200.9']]], 200
        ));

        $r = app(AezaClient::class)->serverStatus('8801');
        $this->assertSame('unknown', $r['status']);

        $inst = new CloudInstance(['status' => $r['status'], 'ipv4' => $r['ipv4'], 'provider_ref' => '8801']);
        $this->assertFalse($inst->readyForNotice(), 'وضعیتِ ناشناخته نباید ایمیل را آزاد کند');
        $this->assertFalse($inst->isDelivered(), 'و نباید چیدمانِ تحویل‌شده را نشان دهد');
    }

    /** بی‌IP هیچ وضعیتی «تحویل‌شده» نیست — همان چیزی که ایمیلِ خط‌تیره‌ای را ساخت */
    public function test_running_without_an_ip_is_not_delivered(): void
    {
        $inst = new CloudInstance(['status' => 'running', 'ipv4' => null, 'provider_ref' => '8801']);

        $this->assertFalse($inst->isDelivered());
        $this->assertFalse($inst->readyForNotice());
        $this->assertSame('finishing', $inst->stage());
    }

    /**
     * رگرسیونِ محافظتی: سرورِ **خاموشِ** مشتری تحویل‌شده است. اگر «آماده» را فقط
     * `running` بگیریم، کسی که سرورش را خاموش می‌کند یک‌باره صفحهٔ «در حالِ ساخت»
     * می‌بیند — یک باگِ تازه به‌جای باگِ قبلی.
     */
    public function test_a_powered_off_server_is_still_delivered(): void
    {
        $inst = new CloudInstance(['status' => 'off', 'ipv4' => '203.0.113.7', 'provider_ref' => '42']);

        $this->assertTrue($inst->isDelivered());
        $this->assertSame('ready', $inst->stage());
        $this->assertFalse($inst->readyForNotice(), 'ولی ایمیلِ «آماده شد» فقط با active می‌رود');
    }

    /** مرحله‌ها باید از واقعیتِ ردیف بیایند، نه از یک شمارندهٔ ساختگی */
    public function test_stage_reflects_the_real_row_state(): void
    {
        // ردیف پیش از تماسِ API ساخته می‌شود، پس بی‌شناسه یعنی سفارش هنوز ثبت نشده
        $ordered = new CloudInstance(['status' => 'building', 'provider_ref' => null]);
        $this->assertSame('ordered', $ordered->stage());
        $this->assertSame(0, $ordered->stageIndex());

        // شناسهٔ نیمه‌کاره یعنی سفارش **پذیرفته شده** و ماشین ساخته می‌شود
        $accepted = new CloudInstance(['status' => 'building', 'provider_ref' => 'order:5150']);
        $this->assertSame('building', $accepted->stage());
        $this->assertSame(1, $accepted->stageIndex());

        $building = new CloudInstance(['status' => 'building', 'provider_ref' => '8801']);
        $this->assertSame('building', $building->stage());
        $this->assertSame(1, $building->stageIndex());

        $unknown = new CloudInstance(['status' => 'unknown', 'provider_ref' => '8801']);
        $this->assertSame('building', $unknown->stage(), '«نمی‌دانم» ⇒ در حالِ ساخت');

        $ready = new CloudInstance(['status' => 'running', 'ipv4' => '1.2.3.4', 'provider_ref' => '8801']);
        $this->assertSame('ready', $ready->stage());
        $this->assertSame(3, $ready->stageIndex());
    }

    /** مسیرِ وضعیت هرگز نباید بی‌IP بگوید آماده است */
    public function test_status_endpoint_never_reports_ready_without_an_ip(): void
    {
        $service = $this->orderedButNotBuilt();
        $this->fakeAezaResolves('activating', null);

        $this->actingAs($service->customer, 'customer')
            ->getJson(route('account.cloud.status', $service))
            ->assertOk()
            ->assertJson(['ready' => false])
            ->assertJsonPath('stage', fn ($v) => $v !== 'ready');
    }

    /** و وقتی واقعاً آماده شد، همان مسیر باید بگوید آماده است */
    public function test_status_endpoint_reports_ready_when_the_provider_is_active_with_an_ip(): void
    {
        $service = $this->orderedButNotBuilt();
        $this->fakeAezaResolves('active', '185.51.200.9');

        // ref را ببند تا مسیرِ وضعیت مستقیم سرویس را بپرسد
        app(CloudProvisioner::class)->syncInstances();

        $this->actingAs($service->customer, 'customer')
            ->getJson(route('account.cloud.status', $service))
            ->assertOk()
            ->assertJson(['ready' => true, 'stage' => 'ready', 'ipv4' => '185.51.200.9']);
    }

    /**
     * 🔴 خودِ گزارشِ کارفرما: «پنلِ ما می‌گوید created، زیرساخت می‌گوید activating».
     *
     * صفحه نباید چیدمانِ «تحویل‌شده» را نشان دهد — نه بخشِ دسترسی، نه دکمه‌های
     * کنترل، و مطلقاً نه یک ردیفِ IPv4 با خط تیره.
     */
    public function test_panel_shows_the_building_state_while_the_provider_is_activating(): void
    {
        $service = $this->orderedButNotBuilt();

        $html = $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->assertOk()->getContent();

        $this->assertStringContainsString('cb-steps', $html, 'نوارِ مرحله‌ها باید باشد');
        $this->assertStringNotContainsString(__('ui.cs_access_h'), $html,
            'بخشِ «دسترسی به سرور» پیش از تحویل نباید نشان داده شود');
        // ⚠️ روی خودِ **مسیرِ عمل** سنجیده می‌شود نه متنِ عنوان: عنوان می‌تواند در
        // توضیحِ مرحله‌ها هم بیاید و تست را بی‌صدا بی‌اثر کند.
        $this->assertStringNotContainsString(route('account.cloud.power', $service, false), $html,
            'دکمه‌های کنترل روی سروری که وجود ندارد بی‌معنی‌اند');
        // ساختار سنجیده می‌شود نه متن: عنوان‌ها در توضیحِ مرحله‌ها هم می‌آیند و
        // اسمِ کلاس در بلوکِ <style> پایینِ صفحه که همیشه رندر می‌شود.
        $this->assertStringNotContainsString('<div class="cs-grid">', $html,
            'جدولِ مشخصات/دسترسی پیش از تحویل نباید رندر شود');
    }

    /** نشانگرِ بالای صفحه هم نباید کلمهٔ «نامشخص» را به مشتری نشان دهد */
    public function test_the_status_pill_shows_a_stage_not_a_raw_provider_word(): void
    {
        $service = $this->orderedButNotBuilt();
        CloudInstance::where('service_id', $service->id)->update(['status' => 'unknown']);

        $html = $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->getContent();

        $this->assertStringNotContainsString('نامشخص', $html);
        $this->assertStringContainsString(__('ui.cs_stage_building'), $html);
    }

    // ═════════════════════════════════════════════════════════════════
    // باگ ۳ — تجربهٔ زندهٔ ساخت
    // ═════════════════════════════════════════════════════════════════

    /** فقط **یک** مرحله جاری است؛ اگر همه‌چیز بجنبد هیچ‌چیز معنا ندارد */
    public function test_exactly_one_stage_is_marked_as_current(): void
    {
        $service = $this->orderedButNotBuilt();

        $html = $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->getContent();

        // ⚠️ فقط داخلِ خودِ نوار شمرده می‌شود: نامِ کلاس در جاوااسکریپتِ پایینِ
        // صفحه هم می‌آید و شمارشِ کلِ صفحه بی‌صدا عددِ دیگری می‌داد.
        $pane = $this->slice($html, '<ol class="cb-steps"', '</ol>');

        $this->assertSame(1, substr_count($pane, 'is-now'), 'حرکت فقط روی مرحلهٔ جاری است');
        $this->assertSame(4, substr_count($pane, 'cb-step '), 'چهار مرحلهٔ گسسته');

        // شکلِ خواسته‌شدهٔ کارفرما: «سفارش ثبت شد ✓ → در حالِ ساخت ● → … ○ → … ○»
        $this->assertSame(1, substr_count($pane, 'is-done'), 'سفارشِ پذیرفته‌شده باید تیک خورده باشد');
        $this->assertSame(2, substr_count($pane, 'is-todo'));
    }

    /**
     * 🔴 **هیچ درصدِ ساختگی.** همان قاعدهٔ صفحهٔ /status این پروژه: مشتری‌ای که
     * روی ۷۰٪ گیر می‌کند نتیجه می‌گیرد سایت خراب است.
     */
    public function test_the_building_pane_invents_no_percentage(): void
    {
        $service = $this->orderedButNotBuilt();

        $html = $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->getContent();

        $pane = $this->slice($html, '<ol class="cb-steps"', '</ol>');

        $this->assertNotSame('', $pane, 'نوارِ مرحله‌ها پیدا نشد');
        $this->assertStringNotContainsString('%', $pane);
        $this->assertStringNotContainsString('progress', $pane);
        $this->assertDoesNotMatchRegularExpression('~\bwidth\s*:~i', $pane,
            'نوارِ پیشرفتِ درصدی ⇒ عددِ ساختگی');
    }

    /** صفحه باید واقعاً بپرسد، وگرنه «زنده» بودنش ادعا است */
    public function test_the_building_pane_polls_the_status_endpoint(): void
    {
        $service = $this->orderedButNotBuilt();

        $html = $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->getContent();

        $this->assertStringContainsString('data-status-url', $html);
        $this->assertStringContainsString(route('account.cloud.status', $service, false), $html);
        // بازخوانی فقط با `ready`، نه با «دیگر building نیست» — وگرنه `unknown`
        // صفحه را به چیدمانِ تحویل‌شدهٔ بی‌IP می‌برد (همان باگِ گزارش‌شده).
        $this->assertStringContainsString('d.ready === true', $html);
    }

    /**
     * ⚠️ کلاسِ CSSِ نبود، بی‌هیچ خطایی بی‌استایل رندر می‌شود و هیچ تستی نمی‌گیردش.
     * پس وجودِ خودِ قاعده‌ها سنجیده می‌شود.
     */
    public function test_the_stage_strip_styles_exist_in_panel_css(): void
    {
        $css = (string) file_get_contents(public_path('assets/css/panel.css'));

        foreach (['.cb-steps', '.cb-step', '.cb-dot', '.cb-txt', '.cb-live',
            '.cb-foot', '.cb-warn', '.cb-step.is-done', '.cb-step.is-now',
            '.cb-step.is-todo', '@keyframes cbPulse'] as $rule) {
            $this->assertStringContainsString($rule, $css, "قاعدهٔ «{$rule}» در panel.css نیست");
        }

        // ⚠️ `--card` در این پروژه تعریف **نشده**؛ استفاده‌اش بی‌صدا بی‌رنگ می‌شود.
        $this->assertStringNotContainsString('var(--card', $this->cbBlock($css));
    }

    /** ⚠️ هر کلیدِ ui باید در هر سه زبان باشد، وگرنه کاربر کلیدِ خام می‌بیند */
    public function test_new_ui_keys_exist_in_all_three_languages(): void
    {
        $keys = [
            'cs_stage_ordered', 'cs_stage_ordered_d', 'cs_stage_building', 'cs_stage_building_d',
            'cs_stage_finishing', 'cs_stage_finishing_d', 'cs_stage_ready', 'cs_stage_ready_d',
            'cs_build_live', 'cs_build_leave', 'cs_pw_missing', 'cs_js_stage_wait',
            'email_service_ssh_cmd', 'email_service_pass_panel_h', 'email_service_pass_panel',
            'email_service_pass_panel_btn', 'email_service_ssh_p', 'email_service_ssh_link',
            'email_service_note_cloud',
        ];

        foreach (['fa', 'en', 'tr'] as $locale) {
            $strings = require lang_path($locale.'/ui.php');

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings, "کلیدِ «{$key}» در {$locale} نیست");
                $this->assertNotSame('', trim((string) $strings[$key]));
            }
        }
    }

    /** و هر سه فایل باید **دقیقاً** هم‌کلید بمانند */
    public function test_the_three_language_files_stay_key_identical(): void
    {
        $fa = array_keys(require lang_path('fa/ui.php'));
        $en = array_keys(require lang_path('en/ui.php'));
        $tr = array_keys(require lang_path('tr/ui.php'));

        $this->assertSame([], array_diff($fa, $en), 'کلیدهایی که در en نیستند');
        $this->assertSame([], array_diff($fa, $tr), 'کلیدهایی که در tr نیستند');
        $this->assertSame([], array_diff($en, $fa));
        $this->assertSame([], array_diff($tr, $fa));
    }

    // ═════════════════════════════════════════════════════════════════
    // ایمیلِ تحویل — رمز، لینکِ پنل و راهنمای SSH
    // ═════════════════════════════════════════════════════════════════

    private function cloudMail(?string $ip = '185.51.200.9', string $locale = 'fa'): string
    {
        return (new ServiceReadyMail(
            'سرورِ ابری CV-2-4', $ip, 'https://console.servernet.cloud/account/cloud/7',
            'root', null, $locale, passwordInPanel: true, withSshGuide: true,
        ))->render();
    }

    /**
     * 🔴 کارفرما: «الان ساکت است و کاربر فکر می‌کند چیزی جا افتاده.»
     */
    public function test_cloud_email_says_where_the_root_password_is_and_links_to_the_service_page(): void
    {
        $html = $this->cloudMail();

        $this->assertStringContainsString(__('ui.email_service_pass_panel_h'), $html);
        $this->assertStringContainsString(__('ui.email_service_pass_panel_btn'), $html);
        $this->assertStringContainsString('/account/cloud/7', $html, 'لینکِ مستقیمِ همان سرویس');
    }

    /**
     * رمزِ root در ایمیل نمی‌آید: قاعدهٔ «یک بار در پنل» با نسخهٔ ابدیِ اینباکس
     * از درِ پشتی می‌شکست.
     */
    public function test_cloud_email_carries_no_root_password(): void
    {
        $html = (new ServiceReadyMail(
            'سرور', '185.51.200.9', 'https://x/account/cloud/7', 'root',
            'SuperSecretRootPw', 'fa', passwordInPanel: true, withSshGuide: true,
        ))->render();

        $this->assertStringNotContainsString('SuperSecretRootPw', $html);
        $this->assertStringNotContainsString(__('ui.email_service_pass').':', $html);
    }

    /**
     * 🔴 تلهٔ ثبت‌شدهٔ پروژه: یک `@` چسبیده به آکولاد در Blade دستورِ **فرار**
     * است و مقدار را چاپ نمی‌کند. یک بار همین باگ روی خطِ SSH پنل رفت و صفحه
     * هم ۲۰۰ می‌داد. پس مقدارِ واقعی سنجیده می‌شود، نه رندرِ موفق.
     */
    public function test_cloud_email_ssh_command_contains_the_real_ip(): void
    {
        $html = $this->cloudMail();

        $this->assertStringContainsString('ssh root'.'@'.'185.51.200.9', $html);
        $this->assertStringNotContainsString('{{', $html, 'هیچ آکولادِ کامپایل‌نشده نباید بماند');
        $this->assertStringNotContainsString('$domain', $html);
    }

    /** ایمیل باید IP واقعی داشته باشد، نه خط تیره — همان خرابیِ گزارش‌شده */
    public function test_cloud_email_shows_the_ip_and_not_a_dash(): void
    {
        $html = $this->cloudMail();

        $this->assertStringContainsString('185.51.200.9', $html);
        $this->assertStringNotContainsString('>—<', $html);
    }

    /** لینکِ ۴۰۴ در ایمیلِ تحویل از نبودِ لینک بدتر است */
    public function test_no_ssh_guide_link_when_the_article_is_not_published(): void
    {
        $this->assertNull(ServiceReadyMail::sshDocUrl(), 'فرضِ تست: مقاله seed نشده');
        $this->assertStringNotContainsString(__('ui.email_service_ssh_link'), $this->cloudMail());
    }

    /** و اگر مقاله واقعاً منتشر شده باشد، لینک می‌آید */
    public function test_the_ssh_guide_is_linked_when_the_article_exists(): void
    {
        $this->publishSshDoc();

        $url = ServiceReadyMail::sshDocUrl();
        $this->assertNotNull($url);
        $this->assertStringContainsString(ServiceReadyMail::SSH_DOC_SLUG, $url);

        $html = $this->cloudMail();
        $this->assertStringContainsString(__('ui.email_service_ssh_link'), $html);
        $this->assertStringContainsString(ServiceReadyMail::SSH_DOC_SLUG, $html);
    }

    /** پیش‌نویسِ منتشرنشده هم لینک نمی‌گیرد */
    public function test_a_draft_ssh_article_is_not_linked(): void
    {
        $this->publishSshDoc('draft');

        $this->assertNull(ServiceReadyMail::sshDocUrl());
    }

    /**
     * رگرسیون: مسیرِ هاستِ اشتراکی از همین Mailable استفاده می‌کند و **نباید**
     * عوض شده باشد — رمزِ cPanel جای دیگری نگه داشته نمی‌شود.
     */
    public function test_the_shared_hosting_email_is_unchanged(): void
    {
        $html = (new ServiceReadyMail(
            'هاستِ اشتراکی', 'example.com', 'https://srv1:2083', 'exampl01', 'CpanelPw33', 'fa',
        ))->render();

        $this->assertStringContainsString('CpanelPw33', $html, 'رمزِ هاست باید در ایمیل بماند');
        $this->assertStringNotContainsString(__('ui.email_service_pass_panel_h'), $html);
        $this->assertStringNotContainsString('ssh ', $html, 'دستورِ ssh روی هاستِ اشتراکی گمراه‌کننده است');
        $this->assertStringNotContainsString(__('ui.email_service_note_cloud'), $html);
    }

    // ───────────────────────── کمک‌تابع‌ها ─────────────────────────

    /**
     * عنوانِ هر اعلانِ مدیر را جمع کن.
     *
     * ⚠️ چرا با دست و نه با `spy()->shouldNotHaveReceived('event', [...])`:
     * آن شکل، آرایهٔ آرگومان‌ها را **کامل** تطبیق می‌دهد. `event()` چهار
     * پارامتر دارد، پس دادنِ یک الگو هرگز تطبیق نمی‌کند و ادعای «دریافت نشد»
     * بی‌قید درست از آب درمی‌آید — یعنی تستی که هیچ‌چیز نمی‌سنجد و بی‌صدا سبز
     * می‌مانَد. (همان درسِ ثبت‌شدهٔ استابِ همه‌گیرِ `Http::fake`.)
     */
    private function captureAdminTitles(): \ArrayObject
    {
        $titles = new \ArrayObject;

        $mock = \Mockery::mock(\App\Services\Notify\AdminNotifier::class);
        $mock->shouldReceive('event')->andReturnUsing(function (string $title) use ($titles) {
            $titles->append($title);
        });

        $this->app->instance(\App\Services\Notify\AdminNotifier::class, $mock);

        return $titles;
    }

    /** @return array<int,string> */
    private function stalledAmong(\ArrayObject $titles): array
    {
        return array_values(array_filter(
            $titles->getArrayCopy(),
            fn (string $t) => str_contains($t, 'گیر کرده')
        ));
    }

    private function publishSshDoc(string $status = 'published'): void
    {
        $post = Post::create([
            'slug' => ServiceReadyMail::SSH_DOC_SLUG, 'type' => 'kb',
            'category' => 'servers', 'status' => $status, 'published_at' => now(),
        ]);

        foreach (['fa', 'en', 'tr'] as $loc) {
            PostTranslation::create([
                'post_id' => $post->id, 'locale' => $loc,
                'title' => 'SSH', 'excerpt' => '', 'content' => '<p>x</p>',
            ]);
        }
    }

    private function slice(string $haystack, string $from, string $to): string
    {
        $a = strpos($haystack, $from);

        if ($a === false) {
            return '';
        }

        $b = strpos($haystack, $to, $a);

        return $b === false ? '' : substr($haystack, $a, $b - $a);
    }

    private function cbBlock(string $css): string
    {
        return $this->slice($css, '.cb-live{', '@media (prefers-reduced-motion');
    }
}
