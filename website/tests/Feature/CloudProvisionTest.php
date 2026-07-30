<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * تحویلِ خودکارِ سرورِ ابری — مسیرِ پول.
 *
 * این تست‌ها روی چیزی کار می‌کنند که خطایش **پولِ واقعی** است: یک بار سرورِ
 * اضافه خریدن، یا سرورِ فروخته‌شده را هرگز نساختن.
 */
class CloudProvisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('pricing_rate_override', '100000');
        Setting::putSecret('hetzner_api_token', 'test-token');
        Mail::fake();
    }

    protected function customer(): Customer
    {
        return Customer::create([
            'email' => 'cl'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    protected function plan(string $provider = 'hetzner', array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => $provider, 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    protected function image(string $provider = 'hetzner', string $ref = 'ubuntu-24.04'): CloudImage
    {
        return CloudImage::create([
            'provider' => $provider, 'provider_ref' => $ref, 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
            'label' => 'Ubuntu 24.04', 'is_active' => true,
        ]);
    }

    protected function service(CloudPlan $plan, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $this->customer()->id,
            'name' => 'سرورِ ابری CV-2-4', 'price' => 570000, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
        ], $over));
    }

    /** پاسخِ موفقِ ساختِ سرور */
    protected function fakeCreateOk(): void
    {
        Http::fake([
            '*/servers' => Http::response([
                'server' => [
                    'id' => 999, 'name' => 'sn-svc-1', 'status' => 'initializing',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.7'], 'ipv6' => ['ip' => '2a01:4f8::1']],
                    'server_type' => ['name' => 'cx22'],
                ],
                'action' => ['id' => 1, 'status' => 'running'],
                'root_password' => 'GeneratedRootPw9',
            ], 201),
            '*' => Http::response([], 200),
        ]);
    }

    // ═══════════════════ مسیرِ موفق ═══════════════════

    public function test_successful_delivery_activates_service_and_stores_instance(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);
        $this->fakeCreateOk();

        $ok = app(ProvisioningService::class)->provision($service);

        $this->assertTrue($ok);

        $service->refresh();
        $this->assertSame('done', $service->provision_status);
        $this->assertSame('active', $service->status);
        $this->assertSame('root', $service->username);
        $this->assertNotNull($service->activated_at);
        $this->assertStringContainsString('/account/cloud/'.$service->id, (string) $service->panel_url);

        $inst = CloudInstance::where('service_id', $service->id)->first();
        $this->assertNotNull($inst);
        $this->assertSame('999', $inst->provider_ref);
        $this->assertSame('203.0.113.7', $inst->ipv4);
        $this->assertSame('2a01:4f8::1', $inst->ipv6);
        $this->assertSame('building', $inst->status, 'initializing → building');
        $this->assertSame('GeneratedRootPw9', $inst->password());
        $this->assertSame('de-falkenstein', $inst->location_code);
    }

    /** مشخصاتِ لحظهٔ خرید در نمونه ثبت شود — پلن ممکن است بعداً عوض شود */
    public function test_specs_are_snapshotted_on_the_instance(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);
        $this->fakeCreateOk();

        app(ProvisioningService::class)->provision($service);

        $specs = CloudInstance::where('service_id', $service->id)->first()->specs;

        $this->assertSame(2, $specs['vcpu']);
        $this->assertSame(4096, $specs['ram_mb']);
        $this->assertSame(40, $specs['disk_gb']);
        $this->assertSame('CV-2-4', $specs['plan_name']);
    }

    // ═══════════════════ idempotency — گران‌ترین باگِ ممکن ═══════════════════

    /**
     * ⚠️ `provision:run` هر دقیقه می‌دود. اگر تلاشِ دوم سرورِ دوم بخرد، هر
     * سرویس دو برابر هزینه دارد و کسی هم متوجه نمی‌شود تا صورت‌حسابِ زیرساخت
     * برسد. نامِ قطعیِ سرور (`sn-svc-{id}`) این را می‌بندد.
     */
    public function test_second_run_does_not_buy_a_second_server(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);
        $this->fakeCreateOk();

        app(ProvisioningService::class)->provision($service);

        $createCalls = 0;
        Http::fake(function ($request) use (&$createCalls) {
            if (str_ends_with($request->url(), '/servers') && $request->method() === 'POST') {
                $createCalls++;
            }

            return Http::response([], 200);
        });

        // تلاشِ دوم روی سرویسِ done
        $this->assertTrue(app(ProvisioningService::class)->provision($service->fresh()));

        $this->assertSame(0, $createCalls, 'سرویسِ تحویل‌شده نباید دوباره سرور بخرد');
        $this->assertSame(1, CloudInstance::where('service_id', $service->id)->count());
    }

    /**
     * حالتِ بدتر: تلاشِ اول سرور را ساخت ولی پاسخ به ما نرسید (قطعیِ شبکه)، پس
     * سرویس روی pending ماند. تلاشِ دوم باید همان سرورِ موجود را پیدا کند، نه
     * اینکه دومی بخرد.
     */
    public function test_duplicate_name_resolves_to_the_existing_server(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/servers') && $request->method() === 'POST') {
                return Http::response([
                    'error' => ['code' => 'uniqueness_error', 'message' => 'server name is already used'],
                ], 409);
            }

            // GET /servers?name=… → همان سرورِ ساخته‌شده
            if (str_contains($request->url(), '/servers?') || str_contains($request->url(), 'name=sn-svc')) {
                return Http::response(['servers' => [[
                    'id' => 999, 'name' => 'sn-svc-1', 'status' => 'running',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.7'], 'ipv6' => ['ip' => '2a01:4f8::1']],
                ]]], 200);
            }

            return Http::response([], 200);
        });

        $ok = app(ProvisioningService::class)->provision($service);

        $this->assertTrue($ok, 'نامِ تکراری یعنی «قبلاً ساختیم»، نه «نشد»');

        $inst = CloudInstance::where('service_id', $service->id)->first();
        $this->assertSame('999', $inst->provider_ref);
        $this->assertSame('running', $inst->status);
        $this->assertSame(1, CloudInstance::count());
    }

    // ═══════════════════ رگرسیونِ کرون ═══════════════════

    /**
     * ⚠️ رگرسیونِ یک باگِ واقعی: `provision:run` فقط
     * `whereNotNull('server_id')` را برمی‌داشت. سرورِ ابری `server_id` ندارد،
     * پس هر سرویسِ ابری **بی‌صدا** رد می‌شد: مشتری پول می‌داد، هیچ خطایی هم
     * تولید نمی‌شد، و سرور هرگز ساخته نمی‌شد.
     */
    public function test_cron_picks_up_cloud_services_that_have_no_server_id(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);
        $this->assertNull($service->server_id);
        $this->fakeCreateOk();

        $this->artisan('provision:run')->assertSuccessful();

        $this->assertSame('done', $service->fresh()->provision_status);
    }

    // ═══════════════════ خرابی‌ها ═══════════════════

    /** ظرفیتِ تمام‌شده = خرابیِ گذرا → pending بمان تا کرونِ بعدی */
    public function test_out_of_stock_stays_pending_for_retry_not_failed(): void
    {
        $plan = $this->plan('hetzner', ['in_stock' => false]);
        $this->image();
        $service = $this->service($plan);
        Http::fake();

        $this->assertFalse(app(ProvisioningService::class)->provision($service));

        $service->refresh();
        $this->assertSame('pending', $service->provision_status, 'باید دوباره تلاش شود');
        $this->assertSame('awaiting_provision', $service->status);
        Http::assertNothingSent();
    }

    /** توکنِ نبود هم خرابیِ گذراست — با وارد کردنش خودش حل می‌شود */
    public function test_missing_token_stays_pending(): void
    {
        Setting::putSecret('hetzner_api_token', null);

        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);

        $this->assertFalse(app(ProvisioningService::class)->provision($service));
        $this->assertSame('pending', $service->fresh()->provision_status);
    }

    /** خطای واقعیِ زیرساخت = failed، با پیامِ قابلِ فهم برای مدیر */
    public function test_provider_error_marks_failed_with_reason(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);

        Http::fake(fn () => Http::response(
            ['error' => ['code' => 'resource_unavailable', 'message' => 'no available resources']], 503
        ));

        $this->assertFalse(app(ProvisioningService::class)->provision($service));

        $service->refresh();
        $this->assertSame('failed', $service->provision_status);
        $this->assertSame('awaiting_provision', $service->status);
        $this->assertStringContainsString('resource_unavailable', (string) $service->provision_error);

        $inst = CloudInstance::where('service_id', $service->id)->first();
        $this->assertSame('error', $inst->status);
        $this->assertStringContainsString('resource_unavailable', (string) $inst->last_error);
    }

    /** پلنِ حذف‌شده نباید استثنا بدهد */
    public function test_deleted_plan_fails_cleanly(): void
    {
        $plan = $this->plan();
        $service = $this->service($plan);
        $plan->delete();
        Http::fake();

        $this->assertFalse(app(ProvisioningService::class)->provision($service));
        $this->assertSame('failed', $service->fresh()->provision_status);
    }

    /** سیستم‌عاملِ نبود = شکستِ تمیز، نه سرورِ خالیِ بی‌سیستم‌عامل */
    public function test_missing_image_fails_instead_of_creating_a_blank_server(): void
    {
        $plan = $this->plan();
        // هیچ ایمیجی ساخته نشده
        $service = $this->service($plan);

        $created = 0;
        Http::fake(function ($request) use (&$created) {
            if (str_ends_with($request->url(), '/servers')) {
                $created++;
            }

            return Http::response([], 200);
        });

        $this->assertFalse(app(ProvisioningService::class)->provision($service));
        $this->assertSame(0, $created, 'بی‌سیستم‌عامل نباید سرور ساخته شود');
        $this->assertSame('failed', $service->fresh()->provision_status);
    }

    // ═══════════════════ انتخابِ دیرهنگامِ زیرساخت ═══════════════════

    /**
     * مشتری «اوبونتو ۲۴٫۰۴ در فالکن‌اشتاین» خریده، نه یک برند. اگر ارزان‌ترین
     * زیرساخت آن سیستم‌عامل را نداشته باشد، باید سراغِ زیرساختی برویم که دارد —
     * بی‌آنکه چیزی برای مشتری عوض شود.
     */
    public function test_falls_back_to_a_provider_that_has_the_chosen_os(): void
    {
        Setting::putSecret('aeza_api_token', 'aeza-key');

        // ارزان‌ترین = زیرساختِ ۲، ولی ایمیج ندارد
        $cheap = $this->plan('aeza', ['provider_ref' => '77', 'cost_eur_cents' => 300]);
        $other = $this->plan('hetzner', ['cost_eur_cents' => 379]);
        $this->image('hetzner');                       // فقط زیرساختِ ۱ اوبونتو دارد

        $service = $this->service($cheap);
        $this->fakeCreateOk();

        $this->assertTrue(app(ProvisioningService::class)->provision($service));

        // تحویل باید روی همان پلنی نشسته باشد که ایمیج دارد
        $this->assertSame($other->id, (int) $service->fresh()->cloud_plan_id);
        $this->assertSame('hetzner', CloudInstance::where('service_id', $service->id)->first()->provider);
    }

    /** اگر ارزان‌ترین ناموجود شد، تحویل از بعدی انجام شود */
    public function test_delivery_switches_provider_when_cheapest_is_out_of_stock(): void
    {
        Setting::putSecret('aeza_api_token', 'aeza-key');

        $this->plan('aeza', ['provider_ref' => '77', 'cost_eur_cents' => 300, 'in_stock' => false]);
        $inStock = $this->plan('hetzner', ['cost_eur_cents' => 379]);
        $this->image('hetzner');
        $this->image('aeza', '1042');

        // مشتری پلنِ ارزانِ ناموجود را سفارش داده
        $service = $this->service(CloudPlan::where('provider', 'aeza')->first());
        $this->fakeCreateOk();

        $this->assertTrue(app(ProvisioningService::class)->provision($service));
        $this->assertSame($inStock->id, (int) $service->fresh()->cloud_plan_id);
    }

    // ═══════════════════ چرخهٔ عمر ═══════════════════

    /**
     * سرویسِ تحویل‌شده‌ای که سرورش **بالا آمده** — ساخته‌شده به‌طورِ مستقیم،
     * بی‌گذر از HTTP.
     *
     * ⚠️ چرا بی‌HTTP: استابهای `Http::fake()` به **ترتیبِ ثبت** بررسی می‌شوند و
     * اولین تطبیق برنده است. اگر این متد یک استابِ `'*'` (همه‌گیر) ثبت کند، هر
     * `Http::fake()` بعدیِ خودِ تست **هرگز صدا زده نمی‌شود** و پاسخِ خالیِ
     * همه‌گیر برمی‌گردد. یک‌بار همین باعث شد تستِ «رمز پس از نصبِ دوباره» در
     * واقع هیچ‌چیز را نسنجد و بی‌صدا پاس شود.
     *
     * پس هر تست استابِ خودش را تنها ثبت می‌کند و این fixture هیچ استابی
     * نمی‌گذارد. مسیرِ واقعیِ HTTPیِ تحویل، تست‌های جداگانهٔ خودش را دارد.
     *
     * وضعیت `running` است چون زیرساخت لحظهٔ ساخت `initializing` می‌دهد و چند
     * ثانیه بعد بالا می‌آید؛ صفحهٔ پنل در حالتِ building عمداً فقط پیامِ
     * «در حالِ آماده‌سازی» را نشان می‌دهد.
     */
    protected function delivered(): Service
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);

        $inst = new CloudInstance([
            'service_id'    => $service->id,
            'provider'      => $plan->provider,
            'provider_ref'  => '999',
            'location_code' => $plan->location_code,
            'image_key'     => 'ubuntu-24.04',
            'hostname'      => 'sn-svc-'.$service->id,
            'ipv4'          => '203.0.113.7',
            'ipv6'          => '2a01:4f8::1',
            'status'        => 'running',
            'specs'         => [
                'vcpu' => $plan->vcpu, 'ram_mb' => $plan->ram_mb,
                'disk_gb' => $plan->disk_gb, 'disk_type' => $plan->disk_type,
                'traffic_gb' => $plan->traffic_gb, 'cpu_kind' => $plan->cpu_kind,
                'plan_name' => $plan->public_name,
            ],
        ]);
        $inst->setPassword('GeneratedRootPw9');
        $inst->save();

        $service->forceFill([
            'provision_status' => 'done', 'status' => 'active',
            'username' => 'root', 'activated_at' => now(),
            'panel_url' => url('/account/cloud/'.$service->id),
        ])->save();

        return $service->fresh();
    }

    /** تعلیق = خاموش کردن، **نه** حذف. دادهٔ مشتریِ بدهکار پاک نمی‌شود. */
    public function test_suspend_powers_off_and_never_deletes(): void
    {
        $service = $this->delivered();

        $deleted = false;
        $off = false;
        Http::fake(function ($request) use (&$deleted, &$off) {
            if ($request->method() === 'DELETE') {
                $deleted = true;
            }
            if (str_contains($request->url(), 'actions/shutdown')) {
                $off = true;
            }

            return Http::response(['action' => ['status' => 'running']], 201);
        });

        app(ProvisioningService::class)->suspend($service);

        $this->assertTrue($off, 'باید خاموش شود');
        $this->assertFalse($deleted, 'تعلیق هرگز نباید سرور را حذف کند');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame('off', CloudInstance::where('service_id', $service->id)->first()->status);
    }

    public function test_unsuspend_powers_on(): void
    {
        $service = $this->delivered();
        CloudInstance::where('service_id', $service->id)->update(['status' => 'off']);

        $on = false;
        Http::fake(function ($request) use (&$on) {
            if (str_contains($request->url(), 'actions/poweron')) {
                $on = true;
            }

            return Http::response(['action' => ['status' => 'running']], 201);
        });

        app(ProvisioningService::class)->unsuspend($service);

        $this->assertTrue($on);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame('running', CloudInstance::where('service_id', $service->id)->first()->status);
    }

    /** خاتمه = حذفِ واقعی. وگرنه اجارهٔ سرورِ بی‌مشتری را ما می‌دهیم. */
    public function test_terminate_actually_deletes_the_server(): void
    {
        $service = $this->delivered();

        $deleted = false;
        Http::fake(function ($request) use (&$deleted) {
            if ($request->method() === 'DELETE') {
                $deleted = true;
            }

            return Http::response([], 200);
        });

        $r = app(ProvisioningService::class)->terminate($service);

        $this->assertTrue($r->ok);
        $this->assertTrue($deleted, 'سرور باید نزدِ زیرساخت حذف شود');
        $this->assertSame('deleted', CloudInstance::where('service_id', $service->id)->first()->status);
        $this->assertSame('cancelled', $service->fresh()->status);
    }

    /** سرورِ ازقبل‌حذف‌شده (۴۰۴) هم «موفق» است — خاتمه نباید گیر کند */
    public function test_terminate_treats_missing_server_as_success(): void
    {
        $service = $this->delivered();

        Http::fake(fn () => Http::response(['error' => ['code' => 'not_found', 'message' => 'nope']], 404));

        $this->assertTrue(app(ProvisioningService::class)->terminate($service)->ok);
    }

    /** خاتمهٔ سرویسی که هنوز سرور ندارد نباید بشکند */
    public function test_terminate_without_instance_is_a_no_op(): void
    {
        $plan = $this->plan();
        $service = $this->service($plan);
        Http::fake();

        $this->assertTrue(app(ProvisioningService::class)->terminate($service)->ok);
        Http::assertNothingSent();
    }

    // ═══════════════════ رمزِ root ═══════════════════

    /**
     * زیرساختِ ۲ رمز را در پاسخِ سفارش نمی‌دهد. اگر خودمان ست نکنیم، مشتری
     * سرور دارد ولی هیچ راهی به داخلش ندارد — تحویلِ «موفقِ» بی‌فایده.
     */
    public function test_password_is_set_when_provider_does_not_return_one(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);

        $resetCalled = false;
        Http::fake(function ($request) use (&$resetCalled) {
            if (str_ends_with($request->url(), '/servers') && $request->method() === 'POST') {
                return Http::response([
                    'server' => [
                        'id' => 500, 'status' => 'running',
                        'public_net' => ['ipv4' => ['ip' => '198.51.100.9'], 'ipv6' => ['ip' => null]],
                    ],
                    'root_password' => null,          // رمزی برنگشت
                ], 201);
            }

            if (str_contains($request->url(), 'reset_password')) {
                $resetCalled = true;

                return Http::response(['root_password' => 'FallbackPw77', 'action' => []], 201);
            }

            return Http::response([], 200);
        });

        $this->assertTrue(app(ProvisioningService::class)->provision($service));
        $this->assertTrue($resetCalled, 'بی‌رمز، سرور برای مشتری بی‌فایده است');
        $this->assertSame('FallbackPw77', CloudInstance::where('service_id', $service->id)->first()->password());
    }

    // ═══════════════════ تفکیکِ مسیرها ═══════════════════

    /** سرویسِ هاستِ معمولی نباید از مسیرِ ابری برود */
    public function test_non_cloud_service_is_not_handled_by_cloud_provisioner(): void
    {
        $service = Service::create([
            'customer_id' => $this->customer()->id, 'name' => 'هاستِ اشتراکی',
            'price' => 250000, 'cycle' => 'monthly', 'status' => 'pending',
        ]);

        $this->assertFalse(CloudProvisioner::handles($service));
        $this->assertFalse($service->isCloud());
    }

    public function test_cloud_service_is_detected(): void
    {
        $service = $this->service($this->plan());

        $this->assertTrue(CloudProvisioner::handles($service));
        $this->assertTrue($service->isCloud());
    }

    // ═══════════════════ صفحهٔ مدیریتِ مشتری ═══════════════════

    /**
     * «کدِ ۲۰۰ یعنی هیچ». پس محتوای واقعی سنجیده می‌شود — به‌ویژه خطِ SSH، که
     * چون «root» و آکولاد با یک @ به هم می‌چسبیدند، Blade آن را دستورِ فرار
     * می‌فهمید و به‌جای IP، خودِ عبارت را چاپ می‌کرد.
     */
    public function test_panel_page_renders_real_values_not_blade_placeholders(): void
    {
        $service = $this->delivered();
        $customer = $service->customer;

        $html = $this->actingAs($customer, 'customer')
            ->get(route('account.cloud.show', $service))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('203.0.113.7', $html);
        $this->assertStringContainsString('ssh root'.'@'.'203.0.113.7', $html, 'خطِ SSH باید IP واقعی داشته باشد');
        $this->assertStringNotContainsString('$inst->ipv4', $html, 'عبارتِ Blade نباید خام چاپ شود');
        $this->assertStringNotContainsString('{{', $html, 'هیچ آکولادِ کامپایل‌نشده نباید بماند');
    }

    /**
     * نامِ زیرساختِ **این سرور** نباید در صفحهٔ مشتری باشد — مهم‌ترین قاعدهٔ این حوزه.
     *
     * ⚠️ دقتِ لازم: منویِ سراسریِ سایت از قبل صفحهٔ بازاریابیِ
     * `/dedicated/hetzner` را دارد (فروشِ سرورِ اختصاصیِ آن برند، محصولی جدا).
     * پس نمی‌شود گفت «واژهٔ hetzner هیچ‌جای HTML نباشد» — آن ادعا دربارهٔ چیزِ
     * دیگری است. این‌جا آن لینک‌ها را کنار می‌گذاریم و بعد ادعا می‌کنیم، تا تست
     * دقیقاً همان چیزی را بسنجد که مهم است: تحویلِ سرورِ مجازیِ این مشتری.
     */
    public function test_panel_page_never_leaks_the_provider(): void
    {
        $service = $this->delivered();

        $html = $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->getContent();

        // لینک‌های منویِ «سرورِ اختصاصی» را حذف کن (محصولِ دیگری‌اند)
        $own = preg_replace('~<a\b[^>]*href="[^"]*/dedicated/[^"]*"[^>]*>.*?</a>~is', '', $html);

        foreach (['hetzner', 'Hetzner', 'HETZNER', 'aeza', 'Aeza', 'cx22', 'CX22', 'fsn1'] as $secret) {
            $this->assertStringNotContainsString($secret, $own, "«{$secret}» نباید به مشتری نشان داده شود");
        }
    }

    /** رمز فقط یک بار — بارِ دوم پنهان است */
    public function test_root_password_is_revealed_only_once(): void
    {
        $service = $this->delivered();
        $customer = $service->customer;

        $first = $this->actingAs($customer, 'customer')
            ->get(route('account.cloud.show', $service))->getContent();
        $this->assertStringContainsString('GeneratedRootPw9', $first);

        $second = $this->actingAs($customer, 'customer')
            ->get(route('account.cloud.show', $service))->getContent();
        $this->assertStringNotContainsString('GeneratedRootPw9', $second, 'بارِ دوم نباید رمز را نشان دهد');
        $this->assertStringContainsString('دیگر نمایش داده نمی‌شود', $second);
    }

    /** سرورِ دیگران با حدسِ شناسه دیده نشود — ۴۰۴ نه ۴۰۳ (وجودش هم لو نرود) */
    public function test_another_customers_server_is_not_visible(): void
    {
        $service = $this->delivered();
        $stranger = $this->customer();

        $this->actingAs($stranger, 'customer')
            ->get(route('account.cloud.show', $service))->assertNotFound();

        $this->actingAs($stranger, 'customer')
            ->post(route('account.cloud.power', $service), ['action' => 'off'])->assertNotFound();

        $this->actingAs($stranger, 'customer')
            ->get(route('account.cloud.status', $service))->assertNotFound();
    }

    /** مهمانِ واردنشده هیچ دسترسی ندارد */
    public function test_guest_cannot_touch_a_server(): void
    {
        $service = $this->delivered();

        $this->get(route('account.cloud.show', $service))->assertRedirect();
        $this->post(route('account.cloud.power', $service), ['action' => 'off'])->assertRedirect();
    }

    /** نصبِ دوباره بی‌تأییدِ تایپی انجام نشود */
    public function test_rebuild_requires_typed_confirmation(): void
    {
        $service = $this->delivered();
        $rebuilt = false;

        Http::fake(function ($request) use (&$rebuilt) {
            if (str_contains($request->url(), 'actions/rebuild')) {
                $rebuilt = true;
            }

            return Http::response(['root_password' => 'X'], 201);
        });

        $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.rebuild', $service), ['image' => 'ubuntu-24.04', 'confirm' => 'yes'])
            ->assertSessionHasErrors();

        $this->assertFalse($rebuilt, 'بی‌تأییدِ DELETE نباید دیسک پاک شود');
    }

    public function test_rebuild_with_confirmation_works_and_resets_password_visibility(): void
    {
        $service = $this->delivered();

        // رمز را «دیده‌شده» کن تا اثرِ ری‌ست معلوم شود
        CloudInstance::where('service_id', $service->id)->update(['password_seen' => true]);

        Http::fake(fn () => Http::response(['action' => [], 'root_password' => 'AfterRebuildPw'], 201));

        $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.rebuild', $service), ['image' => 'ubuntu-24.04', 'confirm' => 'DELETE'])
            ->assertSessionHasNoErrors();

        $inst = CloudInstance::where('service_id', $service->id)->first();
        $this->assertSame('building', $inst->status);
        $this->assertSame('AfterRebuildPw', $inst->password());
        $this->assertFalse((bool) $inst->password_seen, 'رمزِ تازه باید یک‌بار نشان داده شود');
    }

    /** ایمیجِ دلخواهِ کاربر مستقیم به API نرود */
    public function test_rebuild_rejects_an_unknown_image(): void
    {
        $service = $this->delivered();
        $sent = false;

        Http::fake(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'rebuild')) {
                $sent = true;
            }

            return Http::response([], 200);
        });

        $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.rebuild', $service), ['image' => '../../etc/passwd', 'confirm' => 'DELETE'])
            ->assertSessionHasErrors();

        $this->assertFalse($sent);
    }

    // ═══════════════════ سفارشِ دومرحله‌ای (زیرساختِ ۲) ═══════════════════

    /**
     * ⚠️ رگرسیونِ یک نقصِ واقعی: زیرساختِ دوم اول «سفارش» می‌سازد و شناسهٔ
     * سرویس چند لحظه بعد می‌آید. اگر نرسد، ref با پیشوندِ `order:` ذخیره
     * می‌شود. بی‌فرمانِ `cloud:sync-instances` آن ref **هرگز** به شناسهٔ واقعی
     * تبدیل نمی‌شد: مشتری پول داده، سرویس «فعال» است، ولی نه IP دارد نه
     * می‌تواند روشن/خاموش کند — تحویلِ «موفقِ» بی‌فایده.
     */
    public function test_pending_order_is_resolved_into_a_real_server(): void
    {
        Setting::putSecret('aeza_api_token', 'aeza-key');

        $plan = $this->plan('aeza', ['provider_ref' => '77']);
        $this->image('aeza', '1042');
        $service = $this->service($plan);

        $inst = new CloudInstance([
            'service_id' => $service->id, 'provider' => 'aeza',
            'provider_ref' => 'order:5150', 'location_code' => $plan->location_code,
            'image_key' => 'ubuntu-24.04', 'status' => 'building',
        ]);
        $inst->save();

        $service->forceFill(['provision_status' => 'done', 'status' => 'active'])->save();

        Http::fake(function ($request) {
            // پی‌گیریِ سفارش → شناسهٔ سرویسِ واقعی
            if (str_contains($request->url(), '/services/orders/5150')) {
                return Http::response(['data' => ['createdServiceIds' => [8801]]], 200);
            }

            // وضعیتِ سرویسِ تازه
            return Http::response(['data' => [
                'id' => 8801, 'currentStatus' => 'active', 'name' => 'sn-svc-1',
                'ip' => ['185.51.200.9'],
            ]], 200);
        });

        $r = app(CloudProvisioner::class)->syncInstances();

        $this->assertSame(1, $r['resolved'], 'سفارش باید به شناسهٔ واقعی تبدیل شود');

        $inst->refresh();
        $this->assertSame('8801', $inst->provider_ref);
        $this->assertStringNotContainsString('order:', (string) $inst->provider_ref);
        $this->assertSame('running', $inst->status);
        $this->assertSame('185.51.200.9', $inst->ipv4);
        $this->assertTrue($inst->isActionable(), 'حالا مشتری باید بتواند مدیریتش کند');
    }

    /** سفارشی که هنوز آماده نیست، دست‌نخورده می‌مانَد تا اجرای بعدی */
    public function test_unready_order_is_left_for_the_next_run(): void
    {
        Setting::putSecret('aeza_api_token', 'aeza-key');

        $plan = $this->plan('aeza', ['provider_ref' => '77']);
        $service = $this->service($plan);

        $inst = new CloudInstance([
            'service_id' => $service->id, 'provider' => 'aeza',
            'provider_ref' => 'order:5150', 'status' => 'building',
        ]);
        $inst->save();

        Http::fake(fn () => Http::response(['data' => ['createdServiceIds' => []]], 200));

        $r = app(CloudProvisioner::class)->syncInstances();

        $this->assertSame(0, $r['resolved']);
        $this->assertSame('order:5150', $inst->fresh()->provider_ref, 'باید برای تلاشِ بعدی بماند');
    }

    /**
     * سرورِ آماده‌شده نباید ایمیلِ «آماده شد» را **دو بار** بفرستد.
     * اعلانِ اول لحظهٔ تحویل رفته؛ اعلانِ دوم فقط وقتی مجاز است که اعلانِ اول
     * IP نداشته باشد.
     */
    public function test_ready_notification_is_not_sent_twice(): void
    {
        $service = $this->delivered();

        // شبیه‌سازیِ حالتِ واقعی: تحویل با IP انجام شده و اعلان رفته است
        $service->forceFill(['provision_meta' => ['kind' => 'cloud', 'ip' => '203.0.113.7']])->save();
        CloudInstance::where('service_id', $service->id)->update(['status' => 'building']);

        Http::fake(fn () => Http::response([
            'server' => [
                'id' => 999, 'status' => 'running',
                'public_net' => ['ipv4' => ['ip' => '203.0.113.7'], 'ipv6' => ['ip' => null]],
            ],
        ], 200));

        Mail::fake();
        app(CloudProvisioner::class)->syncInstances();

        Mail::assertNothingOutgoing();
        $this->assertSame('running', CloudInstance::where('service_id', $service->id)->first()->status);
    }

    /** نمونهٔ حذف‌شده نباید دوباره پی‌گیری شود */
    public function test_deleted_instances_are_skipped(): void
    {
        $service = $this->delivered();
        CloudInstance::where('service_id', $service->id)->update(['status' => 'deleted']);

        Http::fake();
        $r = app(CloudProvisioner::class)->syncInstances();

        $this->assertSame(0, $r['refreshed']);
        Http::assertNothingSent();
    }

    /** فرمانِ کرون باید بی‌خطا بدود حتی وقتی چیزی برای کار نیست */
    public function test_sync_instances_command_runs_clean_when_idle(): void
    {
        Http::fake();
        $this->artisan('cloud:sync-instances')->assertSuccessful();
        Http::assertNothingSent();
    }
}
