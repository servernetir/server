<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * محافظ‌هایی که بازبینیِ تهاجمی لازمشان را ثابت کرد.
 *
 * هر تستِ این فایل یک یافتهٔ **مستند و با سناریوی واقعی** را می‌بندد؛ هیچ‌کدام
 * «شاید بد باشد» نیست. از fixtureهای CloudProvisionTest استفاده می‌کند تا
 * دادهٔ آزمون یک‌جا بماند.
 */
class CloudHardeningTest extends CloudProvisionTest
{
    // ═══════════ ۱) تعلیق باید واقعاً تعلیق باشد ═══════════

    /**
     * 🔴 چون «تعلیقِ سرورِ ابری = خاموش کردن»، بی‌گیت، مشتریِ بدهکار با یک کلیک
     * تعلیق را خودش لغو می‌کرد و تا ابد سرورِ ما را می‌چرخاند. یعنی کلِ سازوکارِ
     * تعلیق بی‌اثر بود و اجاره را ما می‌دادیم.
     */
    public function test_suspended_customer_cannot_power_the_server_back_on(): void
    {
        $service = $this->delivered();
        $service->update(['status' => 'suspended']);
        CloudInstance::where('service_id', $service->id)->update(['status' => 'off']);

        Http::fake();

        $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.power', $service), ['action' => 'on'])
            ->assertSessionHasErrors();

        // مهم‌تر از پیام: هیچ تماسی با زیرساخت نرفته باشد
        Http::assertNothingSent();
        $this->assertSame('off', CloudInstance::where('service_id', $service->id)->first()->status);
    }

    /** مشتریِ تعلیق‌شده نباید سیستم‌عامل هم عوض کند */
    public function test_suspended_customer_cannot_rebuild(): void
    {
        $service = $this->delivered();
        $service->update(['status' => 'suspended']);

        Http::fake();

        $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.rebuild', $service), ['image' => 'ubuntu-24.04', 'confirm' => 'DELETE'])
            ->assertSessionHasErrors();

        Http::assertNothingSent();
    }

    public function test_suspended_customer_cannot_reset_the_password(): void
    {
        $service = $this->delivered();
        $service->update(['status' => 'cancelled']);

        Http::fake();

        $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.password', $service))
            ->assertSessionHasErrors();

        Http::assertNothingSent();
    }

    /** ولی **دیدنِ** صفحه باز می‌مانَد تا مشتری فاکتورش را ببیند */
    public function test_suspended_customer_can_still_view_the_page(): void
    {
        $service = $this->delivered();
        $service->update(['status' => 'suspended']);

        $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))
            ->assertOk();
    }

    // ═══════════ ۲) پیامِ خطا نباید هویتِ زیرساخت را لو بدهد ═══════════

    /**
     * پیامِ خامِ ارائه‌دهنده شناسه‌های بومی دارد؛ مثلاً
     * «Server 55443322 (cx22 in fsn1) is locked». همین سه چیز در `$hidden`
     * مدل‌ها پنهان شده‌اند، پس چاپشان در errorBag همان قاعده را از درِ پشتی
     * می‌شکند.
     */
    public function test_raw_provider_error_is_scrubbed_before_reaching_the_customer(): void
    {
        $service = $this->delivered();

        Http::fake(fn () => Http::response([
            'error' => ['code' => 'locked', 'message' => 'Server 55443322 (cx22 in fsn1) is locked'],
        ], 423));

        $res = $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.power', $service), ['action' => 'reboot']);

        $bag = $res->baseResponse->getSession()->get('errors');
        $shown = json_encode(
            $bag instanceof \Illuminate\Support\ViewErrorBag ? $bag->getBags() : $bag,
            JSON_UNESCAPED_UNICODE
        );

        foreach (['cx22', 'fsn1', 'hetzner'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $shown,
                "«{$secret}» نباید در پیامِ خطای مشتری باشد");
        }

        // ولی متنِ خام باید برای مدیر بمانَد
        $this->assertStringContainsString('cx22',
            (string) CloudInstance::where('service_id', $service->id)->first()->last_error,
            'متنِ خام برای عیب‌یابیِ مدیر لازم است');
    }

    // ═══════════ ۳) کنسول نباید آدرسِ زیرساخت را بدهد ═══════════

    /**
     * آدرسی که ارائه‌دهنده می‌دهد میزبانِ خودش است. اگر در href بنشیند، مشتری با
     * یک hover نامِ برند و شناسهٔ داخلیِ سرور را می‌بیند. (ضمناً یک نشانیِ
     * `wss://` در تگ `a` در هیچ مرورگری باز نمی‌شود، پس آن دکمه اصلاً کار
     * نمی‌کرد و فقط نشت می‌داد.)
     */
    public function test_console_never_exposes_the_provider_url(): void
    {
        $service = $this->delivered();

        Http::fake(fn () => Http::response([
            'wss_url'  => 'wss://console.hetzner.cloud/?server_id=55443322&token=abc',
            'password' => 'consolepw',
            'action'   => [],
        ], 201));

        $res = $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.console', $service));

        $session = $res->baseResponse->getSession();

        $this->assertNull($session->get('console'), 'آدرسِ کنسول نباید در نشست بنشیند');

        $html = $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->getContent();

        foreach (['console.hetzner.cloud', 'wss://', 'consolepw'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
    }

    // ═══════════ ۴) هرگز سرورِ دوم نخر ═══════════

    /**
     * 🔴 گران‌ترین یافته: زیرساختِ سفارش‌محور برای هر POST یک **سفارشِ تازه و
     * پولِ تازه** ثبت می‌کند و محافظِ «نامِ تکراری» رویش کار نمی‌کند.
     *
     * سناریو: تماسِ اول در سمتِ آنها موفق می‌شود ولی پاسخ به ما نمی‌رسد →
     * 'failed' ثبت می‌شود → ادمین «تلاش دوباره» می‌زند → سرورِ دوم خریده می‌شود
     * و سرورِ اول یتیم می‌مانَد و اجاره‌اش تا ابد از حسابِ ما کم می‌شود.
     */
    public function test_retry_adopts_the_existing_server_instead_of_buying_another(): void
    {
        Setting::putSecret('aeza_api_token', 'k');

        $plan = $this->plan('aeza', ['provider_ref' => '77']);
        $this->image('aeza', '1042');
        $service = $this->service($plan);

        // شناسهٔ سرورِ خریده‌شده داریم، ولی تحویل «شکست‌خورده» ثبت شده
        $inst = new CloudInstance([
            'service_id' => $service->id, 'provider' => 'aeza',
            'provider_ref' => '8801', 'location_code' => $plan->location_code,
            'image_key' => 'ubuntu-24.04', 'status' => 'error',
        ]);
        $inst->save();
        $service->update(['provision_status' => 'failed']);

        $orders = 0;
        Http::fake(function ($request) use (&$orders) {
            if (str_contains($request->url(), '/services/orders') && $request->method() === 'POST') {
                $orders++;
            }

            if (str_contains($request->url(), 'changePassword')) {
                return Http::response(['data' => ['ok' => true]], 200);
            }

            return Http::response(['data' => [
                'id' => 8801, 'currentStatus' => 'active', 'ip' => ['185.51.200.9'],
            ]], 200);
        });

        $ok = app(CloudProvisioner::class)->provision($service->fresh());

        $this->assertTrue($ok);
        $this->assertSame(0, $orders, 'هیچ سفارشِ تازه‌ای نباید ثبت شود');

        $inst->refresh();
        $this->assertSame('8801', $inst->provider_ref);
        $this->assertSame('running', $inst->status);
        $this->assertSame('185.51.200.9', $inst->ipv4);
        $this->assertSame('done', $service->fresh()->provision_status);
    }

    /** و رمز هم برایش ساخته می‌شود، وگرنه سرور بی‌فایده است */
    public function test_adopted_server_gets_a_root_password(): void
    {
        Setting::putSecret('aeza_api_token', 'k');

        $plan = $this->plan('aeza', ['provider_ref' => '77']);
        $this->image('aeza', '1042');
        $service = $this->service($plan);

        $inst = new CloudInstance([
            'service_id' => $service->id, 'provider' => 'aeza',
            'provider_ref' => '8801', 'status' => 'error',
        ]);
        $inst->save();

        Http::fake(fn () => Http::response(['data' => [
            'id' => 8801, 'currentStatus' => 'active', 'ip' => ['185.51.200.9'],
        ]], 200));

        app(CloudProvisioner::class)->provision($service->fresh());

        $this->assertTrue($inst->fresh()->hasPassword(), 'بی‌رمز، مشتری راهی به سرورش ندارد');
    }

    // ═══════════ ۵) قفلِ کهنه نباید سرویس را برای همیشه حبس کند ═══════════

    /**
     * اگر پروسه بینِ قفل و پایانِ ساخت کشته شود (دپلوی، ری‌استارتِ FPM، OOM)،
     * سرویس در 'running' گیر می‌کرد: کرون نمی‌دیدش، خطایی هم تولید نمی‌شد، و
     * پولِ مشتری گرفته‌شده بود.
     */
    public function test_stale_running_lock_is_reclaimed(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);

        // قفلِ ۲۰ دقیقه‌پیش که هیچ‌وقت آزاد نشد
        Service::whereKey($service->id)->update([
            'provision_status' => 'running',
            'updated_at'       => now()->subMinutes(20),
        ]);

        $this->fakeCreateOk();

        $this->assertTrue(app(CloudProvisioner::class)->provision($service->fresh()));
        $this->assertSame('done', $service->fresh()->provision_status);
    }

    /** ولی قفلِ **تازه** محترم است — وگرنه دو پروسه هم‌زمان سرور می‌خرند */
    public function test_fresh_running_lock_is_respected(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);

        Service::whereKey($service->id)->update([
            'provision_status' => 'running',
            'updated_at'       => now(),
        ]);

        Http::fake();

        $this->assertFalse(app(CloudProvisioner::class)->provision($service->fresh()));
        Http::assertNothingSent();
    }

    /** و کرون هم قفلِ کهنه را برمی‌دارد */
    public function test_cron_reclaims_a_stale_lock(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);

        Service::whereKey($service->id)->update([
            'provision_status' => 'running',
            'updated_at'       => now()->subMinutes(30),
        ]);

        $this->fakeCreateOk();
        $this->artisan('provision:run')->assertSuccessful();

        $this->assertSame('done', $service->fresh()->provision_status);
    }

    // ═══════════ ۶) ادمین باید بتواند تحویلِ شکست‌خورده را دوباره بزند ═══════════

    /**
     * سرویسِ ابری هرگز `server_id` ندارد، پس دکمهٔ «تلاش دوباره» با پیامِ «اول یک
     * سرورِ تحویل انتخاب کنید» بیرون می‌زد — و کرون هم 'failed' را برنمی‌دارد.
     * یعنی تحویلِ شکست‌خورده **هیچ راهِ بازیابی** نداشت جز ویرایشِ دستیِ دیتابیس.
     */
    public function test_admin_can_retry_a_failed_cloud_delivery(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);
        $service->update(['provision_status' => 'failed', 'provision_error' => 'قبلاً نشد']);

        $admin = User::create([
            'name' => 'مدیر', 'email' => 'h'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);

        $this->fakeCreateOk();

        $this->actingAs($admin, 'web')
            ->post('/admin/services/'.$service->id.'/provision')
            ->assertRedirect();

        $this->assertSame('done', $service->fresh()->provision_status);
    }

    // ═══════════ ۷) سهمیهٔ API نباید سوزانده شود ═══════════

    /**
     * `status` را صفحه هر ۳۰ ثانیه می‌پرسد و سهمیهٔ API **مشترکِ کلِ پروژه**
     * است. بی‌کش، یک تبِ رهاشده می‌توانست سهمیه را بسوزاند و از آن لحظه تحویلِ
     * سرورِ همهٔ مشتریانِ دیگر شکست بخورد.
     */
    public function test_status_endpoint_is_cached_so_it_cannot_burn_the_shared_quota(): void
    {
        Cache::flush();
        RateLimiter::clear('cloud:power:1');

        $service = $this->delivered();

        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;

            return Http::response(['server' => [
                'id' => 999, 'status' => 'running',
                'public_net' => ['ipv4' => ['ip' => '203.0.113.7'], 'ipv6' => ['ip' => null]],
            ]], 200);
        });

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($service->customer, 'customer')
                ->get(route('account.cloud.status', $service))->assertOk();
        }

        $this->assertSame(1, $calls, 'پنج درخواستِ پنل باید فقط یک تماسِ واقعی بزند');
    }
}
