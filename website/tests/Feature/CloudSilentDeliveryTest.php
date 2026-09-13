<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\AezaClient;
use App\Services\Cloud\CloudDeliveryWatch;
use App\Services\Cloud\CloudProvisioner;
use App\Services\SystemHealth;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * 🔴 «مشتری پول داد، ماشین نزدِ زیرساخت ACTIVE است، پنلِ ما چیزی تحویل نداد،
 * و در `/admin/errors` **صفر** خطا ثبت شد.»
 *
 * کارفرما فقط چون خودش پنلِ زیرساخت را باز کرد فهمید. سکوت، خودِ خرابی است.
 *
 * ═══ چه چیزی این‌جا قفل می‌شود ═══
 *
 * ۱) بن‌بستِ `order:` باز می‌شود — با نامِ قطعیِ `sn-svc-{id}` که خودمان
 *    داده‌ایم، پس به هیچ حدسی دربارهٔ شکلِ پاسخِ سفارش وابسته نیست.
 * ۲) تحویلِ ناتمام دیگر بی‌صدا نیست: `SystemHealth` — همان چیزی که بالای
 *    `/admin/errors` است و روی تغییرِ وضعیت به مدیر پیام می‌دهد — آن را
 *    **قرمز** می‌کند، حتی وقتی `provision_status` روی `done` است.
 * ۳) هشدار روی **کش** ننشسته: کشِ مرده دیگر هشدار را نمی‌بلعد.
 * ۴) `createServer` هرگز `ok=true` با شناسهٔ نال برنمی‌گرداند، و پیش از هر
 *    سفارشِ تازه می‌پرسد «سروری با این نام از قبل هست؟» — محافظِ «دو بار نخر».
 *
 * ⚠️ هیچ تماسِ واقعیِ API. زیرساخت سندباکس ندارد؛ هر سفارشِ واقعی پولِ واقعی
 *    است. تنها `Http::fake()`، و هر تست فقط **یک بار** استاب ثبت می‌کند
 *    (اولین تطبیق برنده است؛ استابِ همه‌گیرِ زودتر هر fakeِ بعدی را می‌کشد).
 */
class CloudSilentDeliveryTest extends TestCase
{
    use RefreshDatabase;

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

    private function plan(): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        CloudImage::create([
            'provider' => 'aeza', 'provider_ref' => 'ubuntu_2404', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
            'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'is_active' => true,
        ]);

        return CloudPlan::create([
            'provider' => 'aeza', 'provider_ref' => '153',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function service(CloudPlan $plan, array $over = []): Service
    {
        $customer = Customer::create([
            'email' => 'sd'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'سرورِ ابری CV-2-4', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
        ], $over));
    }

    /** دقیقاً وضعیتِ گزارش‌شده: سفارش پذیرفته شد، سرور «تحویل‌شده» ثبت شد، ولی ref روی `order:` ماند */
    private function stuckOnOrderRef(int $ageMinutes = 45): Service
    {
        $service = $this->service($this->plan(), [
            'status' => 'active', 'provision_status' => 'done', 'provisioned_at' => now(),
        ]);

        $instance = CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'aeza',
            'provider_ref' => 'order:8801', 'location_code' => 'de-falkenstein',
            'image_key' => 'ubuntu-24.04', 'hostname' => 'sn-svc-'.$service->id,
            'status' => 'building',
        ]);

        // ⚠️ `created_at` در `$fillable` نیست، پس `create()` بی‌صدا نادیده‌اش
        // می‌گیرد و ردیف «تازه» می‌مانَد — تستی که فکر می‌کند ۴۵ دقیقه گذشته و
        // در واقع صفر ثانیه گذشته، هیچ‌چیز را ثابت نمی‌کند.
        $this->age($instance, $ageMinutes);

        return $service->fresh();
    }

    /** سن‌دادن به ردیف — از راهِ کوئری‌بیلدر، چون تایم‌استمپ‌ها fillable نیستند */
    private function age(\Illuminate\Database\Eloquent\Model $row, int $minutes): void
    {
        $row->newQuery()->whereKey($row->getKey())->update([
            'created_at' => now()->subMinutes($minutes),
            'updated_at' => now()->subMinutes($minutes),
        ]);
    }

    private function aeza(): AezaClient
    {
        return new AezaClient('aeza-key');
    }

    private function provisionNotes(): array
    {
        return array_values(array_filter(
            ErrorTracker::recent(200, 'error'),
            fn ($e) => ($e['type'] ?? '') === 'incident' && ($e['area'] ?? '') === 'provision'
        ));
    }

    // ═══════════ ۱) بن‌بستِ `order:` باز می‌شود ═══════════

    /**
     * 🔴 هستهٔ خرابی. مسیرهای «خواندنِ سفارش» استنتاجی‌اند و ممکن است هیچ‌وقت
     * جواب ندهند (پشتیبانی فقط دربارهٔ POST نوشت). پیش از این اثرش این بود:
     * `resolveOrder()` تا ابد null می‌داد، `syncInstances()` روی `continue`
     * می‌افتاد، و مشتری تا ابد سرورِ بی‌IP و بی‌کنترل داشت.
     *
     * نامِ سرور را **خودمان** انتخاب کرده‌ایم، پس همیشه در دست است.
     */
    public function test_a_dead_order_endpoint_no_longer_traps_the_server_forever(): void
    {
        $service = $this->stuckOnOrderRef();

        Http::swap(new Factory);
        Http::fake(function ($request) use ($service) {
            $url = $request->url();

            // هر دو مسیرِ خواندنِ سفارش ۴۰۴ — بدترین حالتِ ممکن
            if (str_contains($url, 'orders/8801')) {
                return Http::response(['error' => 'not found'], 404);
            }

            // فهرستِ سرویس‌ها: ماشین واقعاً وجود دارد، با همان نامِ قطعی
            if (str_contains($url, '/services?') || str_ends_with($url, '/services')) {
                return Http::response(['data' => ['total' => 1, 'items' => [[
                    'id' => 90210, 'name' => 'sn-svc-'.$service->id,
                    'currentStatus' => 'active', 'ip' => ['203.0.113.44'],
                ]]]], 200);
            }

            if (str_contains($url, 'password')) {
                return Http::response(['data' => ['password' => 'RootPw42!!']], 200);
            }

            return Http::response(['data' => [
                'id' => 90210, 'currentStatus' => 'active', 'ip' => ['203.0.113.44'],
            ]], 200);
        });

        $r = app(CloudProvisioner::class)->syncInstances();

        $inst = CloudInstance::where('service_id', $service->id)->first();

        $this->assertSame('90210', (string) $inst->provider_ref,
            'شناسهٔ واقعی باید از راهِ نامِ قطعی پیدا شود، حتی وقتی مسیرِ سفارش ۴۰۴ می‌دهد');
        $this->assertSame('203.0.113.44', $inst->ipv4);
        $this->assertSame(1, $r['resolved']);
    }

    /**
     * وقتی هیچ راهی جواب ندهد، ردیف باید **شمرده** شود.
     *
     * پیش از این فقط `continue` می‌خورد: هیچ شمارنده‌ای تکان نمی‌خورد، و
     * `cloud:sync-instances` خروجی‌اش را پشتِ `array_sum($r) > 0` گذاشته بود —
     * پس هر دقیقه با خروجیِ کاملاً خالی و کدِ ۰ تمام می‌شد.
     */
    public function test_an_unresolvable_row_is_counted_and_printed_not_swallowed(): void
    {
        $this->stuckOnOrderRef();

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['error' => 'nope'], 500));

        $r = app(CloudProvisioner::class)->syncInstances();

        $this->assertSame(1, $r['stuck'], 'ردیفِ حل‌نشده باید شمرده شود، نه بی‌صدا رد');
        $this->assertSame(0, $r['resolved']);

        $this->artisan('cloud:sync-instances')
            ->expectsOutputToContain('شناسهٔ واقعیِ زیرساخت را ندارد')
            ->assertExitCode(0);
    }

    // ═══════════ ۲) دیگر بی‌صدا نیست ═══════════

    /**
     * 🔴 ریشهٔ سکوت.
     *
     * `finalize()` همان لحظه‌ای که زیرساخت **سفارش** را می‌پذیرد
     * `provision_status='done'` می‌نویسد. و `SystemHealth::stuckServices()` —
     * تنها چکی که بالای `/admin/errors` دیده می‌شود و روی تغییرِ وضعیت پیام
     * می‌دهد — فقط `pending/running/failed/manual` را می‌شمارد. پس یک تحویلِ
     * کاملاً ناتمام «سبز» بود.
     */
    public function test_health_goes_red_for_a_paid_cloud_service_marked_done_but_never_delivered(): void
    {
        $service = $this->stuckOnOrderRef();

        $this->assertSame('done', $service->provision_status, 'فرضِ تست: برچسبِ داخلی «تحویل‌شده» است');

        $checks = app(SystemHealth::class)->checks();
        $cloud = collect($checks)->firstWhere('key', 'cloud_delivery');

        $this->assertNotNull($cloud, 'چکِ تحویلِ ابری باید وجود داشته باشد');
        $this->assertFalse($cloud['ok']);
        $this->assertSame('fail', $cloud['level']);
        $this->assertStringContainsString('تحویل نشده', $cloud['detail']);
        $this->assertSame('fail', SystemHealth::worst($checks));

        // و همان‌طور که «صفِ تحویل» سبز است — یعنی چکِ قدیمی واقعاً کور بود.
        $this->assertTrue(collect($checks)->firstWhere('key', 'services')['ok'],
            'چکِ قدیمی به این خرابی کور است؛ برای همین چکِ تازه لازم بود');
    }

    /** سرویسی که اصلاً ردیفِ نمونه ندارد هم باید دیده شود — کورترین حالتِ ممکن */
    public function test_a_cloud_service_with_no_instance_row_at_all_is_seen(): void
    {
        $service = $this->service($this->plan(), [
            'status' => 'active', 'provision_status' => 'done',
        ]);
        $this->age($service, 60);

        $stalled = CloudDeliveryWatch::stalled();

        $this->assertCount(1, $stalled);
        $this->assertStringContainsString('هیچ سروری برایش ساخته نشده',
            CloudDeliveryWatch::reasonFor($stalled->first()));
    }

    /** سرویسِ سالم نباید هشدار بسازد — هشدارِ بی‌عمل، هشدارهای واقعی را بی‌اعتبار می‌کند */
    public function test_a_healthy_delivered_service_stays_green(): void
    {
        $service = $this->service($this->plan(), [
            'status' => 'active', 'provision_status' => 'done',
        ]);
        $this->age($service, 60);

        $instance = CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'aeza', 'provider_ref' => '90210',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$service->id, 'status' => 'running',
            'ipv4' => '203.0.113.44', 'ready_notified_at' => now()->subMinutes(50),
        ]);
        $this->age($instance, 60);

        $this->assertTrue(CloudDeliveryWatch::stalled()->isEmpty());
        $this->assertTrue(collect(app(SystemHealth::class)->checks())
            ->firstWhere('key', 'cloud_delivery')['ok']);
    }

    /**
     * ⚠️ ستونِ nullable + `whereNotIn` = ردیفِ NULL بی‌صدا بیرون می‌افتد.
     *
     * همان جنسِ کوری که این کلاس برای شکستنش نوشته شد، این‌بار در خودِ ناظر.
     */
    public function test_a_null_provision_status_does_not_hide_the_service(): void
    {
        $service = $this->stuckOnOrderRef();
        Service::where('id', $service->id)->update(['provision_status' => null]);

        $this->assertCount(1, CloudDeliveryWatch::stalled());
    }

    /**
     * 🔴 «پول گرفته و تحویل نشده» نباید دربارهٔ سفارشی گفته شود که پول نداده.
     *
     * روی prod (۱۱ شهریور ۱۴۰۵) سرویسِ #۱۰۰ با پیش‌فاکتورِ بازِ ۱٬۸۰۴٬۰۰۰ تومان
     * و «مجموع پرداخت‌شده: ۰» ساعت‌به‌ساعت در «خرابی‌های خاموش» ثبت می‌شد و چکِ
     * `/admin/errors` را دائماً قرمز نگه می‌داشت.
     *
     * ⚠️ زیانش سروصدا نیست: اعلان فقط روی **تغییرِ وضعیت** می‌رود، پس ناظرِ
     * همیشه‌قرمز برای تحویلِ شکست‌خوردهٔ بعدی ساکت می‌مانَد.
     */
    public function test_an_unpaid_order_is_not_reported_as_paid_but_undelivered(): void
    {
        $service = $this->stuckOnOrderRef();
        Service::where('id', $service->id)->update(['status' => 'pending']);

        $this->assertTrue(CloudDeliveryWatch::stalled()->isEmpty(),
            'سفارشِ پرداخت‌نشده به‌عنوانِ «پول گرفته و تحویل نشده» گزارش شد');
    }

    /** ولی همان سرویس، به‌محضِ پرداخت، دوباره دیده می‌شود. */
    public function test_the_same_order_is_watched_again_once_it_is_paid(): void
    {
        $service = $this->stuckOnOrderRef();

        foreach (['awaiting_provision', 'active'] as $paid) {
            Service::where('id', $service->id)->update(['status' => $paid]);

            $this->assertCount(1, CloudDeliveryWatch::stalled(),
                'وضعیتِ «'.$paid.'» پرداخت‌شده است و باید پایش شود');
        }
    }

    /** سفارشِ رهاشده/آزادشده (`none`) عمداً بیرون است — صفی نمی‌خواهدش */
    public function test_a_released_service_is_not_watched(): void
    {
        $service = $this->stuckOnOrderRef();
        Service::where('id', $service->id)->update(['provision_status' => 'none']);

        $this->assertTrue(CloudDeliveryWatch::stalled()->isEmpty());
    }

    /** تحویلی که تازه شروع شده هنوز «گیر» نیست؛ ساختِ سرور واقعاً چند دقیقه طول می‌کشد */
    public function test_a_fresh_delivery_is_not_reported_yet(): void
    {
        $this->stuckOnOrderRef(2);

        $this->assertTrue(CloudDeliveryWatch::stalled()->isEmpty());
    }

    // ═══════════ ۳) هشدار روی کش نمی‌نشیند ═══════════

    /**
     * 🔴 قاعدهٔ نوشته‌شدهٔ CLAUDE.md: «هیچ چیزی که قرار است از مرگِ یک وابستگی
     * خبر دهد، نباید روی همان وابستگی بنشیند.»
     *
     * نسخهٔ قبلی اول `Cache::has()` را می‌پرسید و **بعد** در ردیاب می‌نوشت.
     * کشِ پیش‌فرضِ پروداکشن روی همان دیتابیسی است که در همین ردیاب ۱۹ بار قطع
     * شده، و کلِ متد در یک `catch` بود که فقط `Log::warning` می‌کرد. یعنی یک
     * قطعیِ گذرا، هشدار را کاملاً می‌بلعید.
     */
    public function test_the_alarm_still_reaches_the_error_tracker_when_the_cache_is_dead(): void
    {
        $this->stuckOnOrderRef();

        // کشِ مرده — هر تماسی استثنا می‌دهد
        app()->bind('cache', fn () => new class
        {
            public function __call($m, $a)
            {
                throw new \RuntimeException('SQLSTATE[HY000] Connection refused');
            }
        });

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['error' => 'nope'], 500));

        app(CloudProvisioner::class)->syncInstances();

        $notes = $this->provisionNotes();

        $this->assertNotEmpty($notes, 'با کشِ مرده هم هشدار باید در ردیاب بنشیند');
        $this->assertStringContainsString('تحویل نشده', $notes[0]['message']);
    }

    /**
     * ⚠️ گلوگاه لازم است و نبودش خودش یک خرابی است.
     *
     * این متد **هر دقیقه** می‌دود و پنجرهٔ ردیاب ۴۰۰ خط است. یک ردیفِ گیرکرده
     * بی‌گلوگاه روزی ۱۴۴۰ خط می‌نوشت و همان خطاهایی را که باید کنارش دیده
     * شوند بیرون می‌انداخت — دقیقاً خرابیِ سیلِ ۴۰۴.
     *
     * (سکوت بینِ دو شلیک بی‌خطر است: `SystemHealth` همان وضعیت را **دائمی**
     * قرمز نگه می‌دارد و به هیچ گلوگاهی بند نیست — تستِ بالا.)
     */
    public function test_the_alarm_does_not_flood_the_tracker_every_minute(): void
    {
        $this->stuckOnOrderRef();

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['error' => 'nope'], 500));

        // ⚠️ **اختلاف** را می‌سنجیم، نه شمارِ مطلق، و فقط پیامِ خودِ همین هشدار
        // را. شمارِ مطلقِ «هر incidentِ provision» یک بار در سوئیتِ کامل ۲ شد و
        // در اجرای تنها ۱ — یعنی تست به خطِ ثبت‌شدهٔ **جای دیگری** حساس بود و
        // ادعای خودش را نمی‌سنجید. ادعا این است: «۴ اجرا ⇒ ۱ خط».
        $before = $this->stalledNotes();

        for ($i = 0; $i < 4; $i++) {
            app(CloudProvisioner::class)->syncInstances();
        }

        $after = $this->stalledNotes();

        $this->assertSame(1, count($after) - count($before),
            'هر دقیقه یک خط یعنی پنجرهٔ ۴۰۰ خطی در ۷ ساعت پاک می‌شود. خطوطِ دیده‌شده: '
            .json_encode(array_column($after, 'message'), JSON_UNESCAPED_UNICODE));
    }

    /** فقط خطوطی که خودِ نگهبانِ «تحویل نشده» نوشته است */
    private function stalledNotes(): array
    {
        return array_values(array_filter(
            $this->provisionNotes(),
            fn ($e) => str_contains((string) ($e['message'] ?? ''), 'سرویسِ ابریِ پرداخت‌شده تحویل نشده')
        ));
    }

    /** و در حالتِ عادی هم می‌نویسد، با علتِ خوانا */
    public function test_the_alarm_names_the_reason(): void
    {
        $this->stuckOnOrderRef();

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['error' => 'nope'], 500));

        app(CloudProvisioner::class)->syncInstances();

        $notes = $this->provisionNotes();

        $this->assertNotEmpty($notes);
        $this->assertStringContainsString('هرگز به سرورِ واقعی تبدیل نشد', $notes[0]['message']);
    }

    // ═══════════ ۴) هرگز شناسهٔ نال، و هرگز دو بار خریدن ═══════════

    /**
     * پاسخی که نه `createdServiceIds` دارد نه هیچ شناسه‌ای — و سروری هم با آن
     * نام وجود ندارد. پیش از این `ok=true, ref=null` برمی‌گشت: سرویس
     * «تحویل‌شده» ثبت می‌شد، هیچ خطایی تولید نمی‌شد، و محافظِ «دو بار نخر»
     * (که به `filled(provider_ref)` بند است) خلع‌سلاح می‌شد.
     */
    public function test_an_order_with_no_usable_id_is_a_loud_failure_not_a_silent_success(): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['data' => ['status' => 'pending']], 200);
            }

            return Http::response(['data' => ['total' => 0, 'items' => []]], 200);
        });

        $r = $this->aeza()->createServer([
            'name' => 'sn-svc-77', 'plan_ref' => '153', 'location_ref' => 'fsn1',
            'image_ref' => 'ubuntu_2404', 'ssh_keys' => [], 'disk_gb' => 40,
            'labels' => [], 'term' => 'hour',
        ]);

        $this->assertFalse($r['ok'], 'ok=true با شناسهٔ نال یعنی تحویلِ دروغین');
        $this->assertNull($r['ref']);
        $this->assertStringContainsString('پنلِ زیرساخت را ببینید', $r['message'],
            'پیام باید مدیر را از «تلاشِ دوباره»ی کور بازدارد — ممکن است سرور خریده شده باشد');
    }

    /** شناسه‌ای که نامش `orderId` باشد هم باید پیدا شود، نه فقط `id` */
    public function test_an_order_id_under_a_different_key_is_still_found(): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['data' => ['orderId' => 4242, 'status' => 'pending']], 200);
            }

            if (str_contains($request->url(), 'orders/4242')) {
                return Http::response(['error' => 'not found'], 404);
            }

            return Http::response(['data' => ['total' => 0, 'items' => []]], 200);
        });

        $r = $this->aeza()->createServer([
            'name' => 'sn-svc-78', 'plan_ref' => '153', 'location_ref' => 'fsn1',
            'image_ref' => 'ubuntu_2404', 'ssh_keys' => [], 'disk_gb' => 40,
            'labels' => [], 'term' => 'month',
        ]);

        $this->assertTrue($r['ok']);
        $this->assertSame('order:4242', $r['ref'],
            'بی‌این، ref نال می‌شد و محافظِ «دو بار نخر» از کار می‌افتاد');
    }

    /**
     * 🔴 محافظِ «دو بار نخر» برای زیرساختِ سفارش‌محور.
     *
     * CLAUDE.md می‌گوید نامِ قطعی خودش محافظ است چون «تلاشِ دوباره خطای نامِ
     * تکراری می‌گیرد» — ولی این‌جا هیچ خطای تکراری نمی‌آید و هر POST یک سرورِ
     * تازه و پولِ تازه است. پس باید **بپرسیم**.
     */
    public function test_an_existing_machine_with_the_same_name_is_adopted_instead_of_bought_again(): void
    {
        $posts = 0;

        Http::swap(new Factory);
        Http::fake(function ($request) use (&$posts) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'orders')) {
                $posts++;

                return Http::response(['data' => ['id' => 5150, 'createdServiceIds' => []]], 200);
            }

            if (str_contains($request->url(), '/services?') || str_ends_with($request->url(), '/services')) {
                return Http::response(['data' => ['total' => 1, 'items' => [[
                    'id' => 555, 'name' => 'sn-svc-99', 'currentStatus' => 'active',
                    'ip' => ['198.51.100.7'],
                ]]]], 200);
            }

            return Http::response(['data' => [
                'id' => 555, 'currentStatus' => 'active', 'ip' => ['198.51.100.7'],
            ]], 200);
        });

        $r = $this->aeza()->createServer([
            'name' => 'sn-svc-99', 'plan_ref' => '153', 'location_ref' => 'fsn1',
            'image_ref' => 'ubuntu_2404', 'ssh_keys' => [], 'disk_gb' => 40,
            'labels' => [], 'term' => 'month',
        ]);

        $this->assertSame(0, $posts, '🔴 سرورِ موجود دوباره خریده شد — پولِ واقعی');
        $this->assertTrue($r['ok']);
        $this->assertSame('555', $r['ref']);
    }

    /**
     * ⚠️ فهرستِ **ناموفق** نباید «چنین سروری نیست» خوانده شود.
     *
     * توکنِ منقضی فهرستِ خالی می‌دهد. اگر آن را «وجود ندارد» بخوانیم، محافظِ
     * بالا برعکس عمل می‌کند و دقیقاً وقتی که نباید، سفارشِ دوم می‌رود.
     */
    public function test_a_failed_server_list_is_not_read_as_no_such_server(): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['error' => 'unauthorized'], 401));

        $this->assertNull($this->aeza()->findByHostname('sn-svc-1'));
    }

    /**
     * ⚠️ «نمی‌دانم» باید ردی بگذارد.
     *
     * وقتی فهرست خوانده نشود، محافظِ بالا کار نمی‌کند و باز هم سفارش می‌رود
     * (وگرنه یک قطعیِ گذرا کلِ فروش را می‌خواباند). ولی اگر روزی سرورِ تکراری
     * خریده شد، این خط تنها جایی است که علتش را می‌گوید.
     */
    public function test_an_unverifiable_server_list_leaves_a_trail_before_buying(): void
    {
        Http::swap(new Factory);
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['data' => ['id' => 7000, 'createdServiceIds' => []]], 200);
            }

            return Http::response(['error' => 'gateway'], 502);
        });

        $this->aeza()->createServer([
            'name' => 'sn-svc-123', 'plan_ref' => '153', 'location_ref' => 'fsn1',
            'image_ref' => 'ubuntu_2404', 'ssh_keys' => [], 'disk_gb' => 40,
            'labels' => [], 'term' => 'month',
        ]);

        $this->assertNotEmpty(array_values(array_filter(
            $this->provisionNotes(),
            fn ($e) => str_contains((string) $e['message'], 'دو بار نخر')
        )), 'نتوانستنِ بررسی نباید بی‌صدا باشد');
    }

    /**
     * پنجرهٔ ردیاب ۴۰۰ خط است. یک خرابیِ پرتکرار نباید خطاهای گران‌تر را بیرون
     * بیندازد — همان درسی که با سیلِ ۴۰۴ گرفته شد.
     */
    public function test_a_repeated_note_does_not_flood_the_tracker(): void
    {
        for ($i = 0; $i < 5; $i++) {
            ErrorTracker::noteOnce('pricing', 'همان پیامِ تکراری', 900);
        }

        $this->assertCount(1, array_values(array_filter(
            ErrorTracker::recent(50, 'error'),
            fn ($e) => ($e['area'] ?? '') === 'pricing'
        )));
    }

    // ═══════════ ۵) ماشینی که فکر می‌کنیم مرده و زنده است ═══════════

    /**
     * 🔴 گران‌ترین سکوتِ این حوزه.
     *
     * `terminate()` تنها ردی که از شکست می‌گذاشت ستونِ `last_error` بود، و
     * `CloudMeterHourly::creditOut()` مقدارِ برگشتی را دور می‌ریزد و سرویس را
     * «خاتمه‌یافته» می‌نویسد. یعنی در پنلِ ما مرده، نزدِ زیرساخت زنده، و اجاره‌اش
     * هر ماه از حسابِ ما — تا رسیدنِ صورت‌حساب، بی‌هیچ هشداری.
     */
    public function test_a_failed_termination_is_no_longer_a_silent_money_leak(): void
    {
        $service = $this->stuckOnOrderRef();
        CloudInstance::where('service_id', $service->id)->update(['provider_ref' => '90210']);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['error' => 'server busy'], 500));

        $this->assertFalse(app(CloudProvisioner::class)->terminate($service));

        $this->assertNotEmpty(array_values(array_filter(
            $this->provisionNotes(),
            fn ($e) => str_contains((string) $e['message'], 'اجاره‌اش از حسابِ ما می‌رود')
        )), 'حذفِ ناموفق باید بلند باشد، نه فقط یک ستونِ دیتابیس');
    }

    /** تعلیقِ ناموفق هم همان است، ارزان‌تر ولی هر ماه تکرارشونده */
    public function test_a_failed_suspend_is_reported_too(): void
    {
        $service = $this->stuckOnOrderRef();
        CloudInstance::where('service_id', $service->id)->update(['provider_ref' => '90210']);

        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['error' => 'nope'], 500));

        $this->assertFalse(app(CloudProvisioner::class)->suspend($service));
        $this->assertNotEmpty($this->provisionNotes());
    }

    /**
     * شناسهٔ سفارش، شناسهٔ سرویس نیست. بی‌این محافظ، درخواست به
     * `/services/order%3A8801/ctl` می‌رفت و ۴۰۴ می‌گرفت — و چون فراخوان مقدارِ
     * برگشتی را دور می‌ریزد، سرویس «معلق» ثبت می‌شد در حالی که ماشین روشن بود.
     */
    public function test_power_refuses_an_unresolved_order_ref_instead_of_calling_a_dead_url(): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['ok' => true], 200));

        $r = $this->aeza()->power('order:8801', 'off');

        $this->assertFalse($r['ok']);
        Http::assertNothingSent();
    }

    /** نامِ `sn-svc-4` نباید `sn-svc-42` را برگرداند — سرورِ مشتریِ دیگر */
    public function test_hostname_matching_is_exact(): void
    {
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['data' => ['total' => 1, 'items' => [
            ['id' => 42, 'name' => 'sn-svc-42', 'currentStatus' => 'active'],
        ]]], 200));

        $this->assertNull($this->aeza()->findByHostname('sn-svc-4'));
        $this->assertSame('42', $this->aeza()->findByHostname('sn-svc-42'));
    }
}
