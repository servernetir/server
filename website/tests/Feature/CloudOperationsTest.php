<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * عملیاتِ پیشرفتهٔ سرورِ ابری — ارتقا/تنزلِ پلن، اسنپ‌شات، حالتِ نجات، PTR،
 * مصرفِ ترافیک و فهرستِ ایمیج‌های نصب‌شدنی.
 *
 * سه چیز این‌جا سنجیده می‌شود که خطای هرکدام گران است:
 *
 *  ۱) **پول**: تغییرِ پلن باید قیمتِ سرویس را هم عوض کند، وگرنه مشتری سرورِ
 *     بزرگ‌تر می‌گیرد و پولِ کوچک‌تر می‌دهد — تا ابد.
 *  ۲) **قابلیتِ نبود، بی‌تماسِ شبکه**: هر متد اول توانایی را می‌سنجد. اگر
 *     تماسی فرستاده شود، هم سهمیهٔ API می‌سوزد هم پیام دیر می‌آید.
 *  ۳) **سفیدبرچسبی**: هیچ پیامی نباید نامِ زیرساخت یا نامِ بومیِ پلن را بگوید.
 *
 * ⚠️ یادآوریِ تلهٔ تست‌ها: `Http::fake()` استابها را به **ترتیبِ ثبت** می‌سنجد و
 * اولین تطبیق برنده است؛ یک استابِ `'*'`، هر `Http::fake` بعدی را بی‌اثر می‌کند.
 * پس هر تست **فقط یک بار** fake ثبت می‌کند.
 */
class CloudOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('pricing_rate_override', '100000');
        Setting::putSecret('hetzner_api_token', 'test-token');
        Setting::putSecret('aeza_api_token', 'aeza-key');
    }

    protected function ops(): CloudOperations
    {
        return app(CloudOperations::class);
    }

    protected function customer(): Customer
    {
        return Customer::create([
            'email' => 'op'.random_int(1, 999999).'@example.com',
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

    protected function image(array $over = []): CloudImage
    {
        return CloudImage::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
            'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'min_disk_gb' => 5, 'is_active' => true,
        ], $over));
    }

    /**
     * سرویسِ تحویل‌شده — مستقیم ساخته می‌شود، بی‌گذر از HTTP.
     *
     * (چرا بی‌HTTP: مسیرِ تحویل تست‌های خودش را دارد و یک استابِ همه‌گیر در
     * fixture، استابِ اختصاصیِ هر تست را بی‌اثر می‌کرد.)
     */
    protected function delivered(string $provider = 'hetzner', array $planOver = [], array $instOver = []): Service
    {
        $plan = $this->plan($provider, $planOver);

        $service = Service::create([
            'customer_id' => $this->customer()->id,
            'name' => 'سرورِ ابری '.$plan->public_name, 'price' => (int) $plan->price_irt,
            'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
        ]);

        $inst = new CloudInstance(array_merge([
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
        ], $instOver));
        $inst->save();

        return $service->fresh();
    }

    /** پلنِ بزرگ‌ترِ هم‌مکان و هم‌زیرساخت */
    protected function biggerPlan(string $provider = 'hetzner', array $over = []): CloudPlan
    {
        return $this->plan($provider, array_merge([
            'provider_ref' => 'cx32', 'public_name' => 'CV-4-8',
            'slug' => 'cv-4c-8g-80d-de-falkenstein',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'cost_eur_cents' => 759, 'price_eur_cents' => 1100, 'price_irt' => 1100000,
        ], $over));
    }

    /** نشانه‌هایی که هرگز نباید به مشتری برسند */
    protected function secrets(): array
    {
        return ['hetzner', 'Hetzner', 'HETZNER', 'aeza', 'Aeza', 'cx22', 'CX22', 'cx32', 'fsn1'];
    }

    protected function assertNoLeak(string $message, string $where = ''): void
    {
        foreach ($this->secrets() as $secret) {
            $this->assertStringNotContainsString($secret, $message, "«{$secret}» در پیامِ {$where} لو رفته است");
        }
    }

    // ═══════════════════ تغییرِ پلن — قواعد ═══════════════════

    /**
     * ⚠️ تغییرِ نوعِ سرور روی سرورِ روشن نزدِ هیچ زیرساختی ممکن نیست. اگر خودمان
     * نگیریمش، مشتری خطای خامِ انگلیسی می‌بیند و نمی‌فهمد چه کند.
     *
     * وضعیت از **زنده** خوانده می‌شود نه از دیتابیس: وضعیتِ ذخیره‌شده فقط با
     * صفحهٔ وضعیت تازه می‌شود و می‌تواند کهنه باشد.
     */
    public function test_resize_is_rejected_while_the_server_is_running(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);   // دیتابیس: خاموش
        $this->biggerPlan();

        $changed = false;
        Http::fake(function ($request) use (&$changed) {
            if (str_contains($request->url(), 'actions/change_type')) {
                $changed = true;

                return Http::response(['action' => []], 201);
            }

            // زیرساخت می‌گوید روشن است — همین حرفِ آخر است
            return Http::response(['server' => [
                'id' => 999, 'status' => 'running',
                'public_net' => ['ipv4' => ['ip' => '203.0.113.7']],
                'outgoing_traffic' => 0,
            ]], 200);
        });

        $r = $this->ops()->resize($service, 'cv-4c-8g-80d-de-falkenstein');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('خاموش', $r['message']);
        $this->assertFalse($changed, 'روی سرورِ روشن نباید تغییرِ پلن فرستاده شود');
        $this->assertNoLeak($r['message'], 'سرورِ روشن');

        // وضعیتِ کهنهٔ دیتابیس هم اصلاح شده باشد
        $this->assertSame('running', CloudInstance::where('service_id', $service->id)->first()->status);
    }

    /** تنزلِ دیسک نشدنی است — و باید **محلی** رد شود، بی‌هیچ تماسی */
    public function test_resize_refuses_a_disk_downgrade_without_calling_the_api(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);
        $this->plan('hetzner', [
            'provider_ref' => 'cx11', 'public_name' => 'CV-1-2',
            'slug' => 'cv-1c-2g-20d-de-falkenstein',
            'vcpu' => 1, 'ram_mb' => 2048, 'disk_gb' => 20,
            'cost_eur_cents' => 200, 'price_eur_cents' => 300, 'price_irt' => 300000,
        ]);

        Http::fake();

        $r = $this->ops()->resize($service, 'cv-1c-2g-20d-de-falkenstein');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('دیسک', $r['message']);
        Http::assertNothingSent();
        $this->assertNoLeak($r['message'], 'تنزلِ دیسک');

        // قیمت و پلنِ سرویس دست‌نخورده
        $this->assertSame(570000, (int) $service->fresh()->price);
    }

    /** پلنِ مکانِ دیگر = مهاجرت، و باید صریح بگوید خودکار نیست */
    public function test_resize_to_another_location_says_it_is_not_automatic(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);

        $this->plan('hetzner', [
            'provider_ref' => 'cx32', 'provider_location' => 'hel1',
            'location_code' => 'fi-helsinki', 'public_name' => 'CV-4-8',
            'slug' => 'cv-4c-8g-80d-fi-helsinki',
            'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80,
            'cost_eur_cents' => 700, 'price_eur_cents' => 1000, 'price_irt' => 1000000,
        ]);

        Http::fake();

        $r = $this->ops()->resize($service, 'cv-4c-8g-80d-fi-helsinki');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('خودکار', $r['message']);
        $this->assertStringContainsString('مهاجرت', $r['message']);
        Http::assertNothingSent();
        $this->assertNoLeak($r['message'], 'مکانِ دیگر');
    }

    /**
     * همان مشخصات و همان مکان، ولی فقط روی **زیرساختِ دیگری** موجود است.
     *
     * تغییرِ پلن آن‌جا یعنی ساختِ سرورِ تازه و کوچِ داده. پیام باید همان پیامِ
     * «مهاجرت» باشد — و به هیچ زبانی نگوید «زیرساختِ دیگری داریم».
     */
    public function test_resize_to_a_plan_on_another_infrastructure_is_not_automatic(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);
        $this->biggerPlan('aeza', ['provider_ref' => '4488', 'provider_location' => '17']);

        Http::fake();

        $r = $this->ops()->resize($service, 'cv-4c-8g-80d-de-falkenstein');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('خودکار', $r['message']);
        Http::assertNothingSent();
        $this->assertNoLeak($r['message'], 'زیرساختِ دیگر');
    }

    public function test_resize_rejects_an_unknown_plan(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);

        Http::fake();

        $r = $this->ops()->resize($service, 'cv-99c-999g-9999d-nowhere');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('در دسترس نیست', $r['message']);
        Http::assertNothingSent();
    }

    public function test_resize_to_the_same_plan_is_a_no_op(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);

        Http::fake();

        $r = $this->ops()->resize($service, 'cv-2c-4g-40d-de-falkenstein');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('همین پلن', $r['message']);
        Http::assertNothingSent();
    }

    /** زیرساختی که تغییرِ پلن ندارد: پیامِ خنثا، بی‌هیچ تماسی */
    public function test_resize_is_unavailable_on_infrastructure_without_the_capability(): void
    {
        $service = $this->delivered('aeza', ['provider_ref' => '77', 'provider_location' => '17'], ['status' => 'off']);
        $this->biggerPlan('aeza', ['provider_ref' => '4488', 'provider_location' => '17']);

        Http::fake();

        $r = $this->ops()->resize($service, 'cv-4c-8g-80d-de-falkenstein');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('در دسترس نیست', $r['message']);
        Http::assertNothingSent();
        $this->assertNoLeak($r['message'], 'قابلیتِ نبود');
    }

    // ═══════════════════ تغییرِ پلن — مسیرِ موفق و پول ═══════════════════

    /**
     * مهم‌ترین تستِ این فایل: **قیمتِ سرویس باید عوض شود.**
     *
     * اگر تغییرِ پلن قیمت را جا بگذارد، مشتری تا آخرِ عمرِ سرویس پولِ پلنِ
     * کوچک‌تر می‌دهد و هیچ خطایی هم تولید نمی‌شود.
     */
    public function test_successful_resize_updates_price_specs_and_plan(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);
        $target = $this->biggerPlan();

        $payload = null;
        Http::fake(function ($request) use (&$payload) {
            if (str_contains($request->url(), 'actions/change_type')) {
                $payload = $request->data();

                return Http::response(['action' => ['id' => 1, 'status' => 'running']], 201);
            }

            return Http::response(['server' => [
                'id' => 999, 'status' => 'off',
                'public_net' => ['ipv4' => ['ip' => '203.0.113.7'], 'ipv6' => ['ip' => '2a01:4f8::1']],
                'outgoing_traffic' => 0,
            ]], 200);
        });

        $r = $this->ops()->resize($service, 'cv-4c-8g-80d-de-falkenstein');

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('CV-4-8', $r['plan']);
        $this->assertTrue($r['upgraded_disk']);

        // نامِ بومیِ پلن رفته باشد، ولی به مشتری گفته نشده باشد
        $this->assertSame('cx32', $payload['server_type'] ?? null);
        $this->assertTrue((bool) ($payload['upgrade_disk'] ?? false));
        $this->assertNoLeak($r['message'], 'تغییرِ پلنِ موفق');

        $fresh = $service->fresh();
        $this->assertSame(1100000, (int) $fresh->price, 'قیمتِ سرویس باید به پلنِ تازه برود');
        $this->assertSame($target->id, (int) $fresh->cloud_plan_id);
        $this->assertSame('CV-4-8', (string) data_get($fresh->provision_meta, 'plan'));

        $specs = CloudInstance::where('service_id', $service->id)->first()->specs;
        $this->assertSame(4, $specs['vcpu']);
        $this->assertSame(8192, $specs['ram_mb']);
        $this->assertSame(80, $specs['disk_gb']);
        $this->assertSame('CV-4-8', $specs['plan_name']);

        $this->assertTrue(
            ActivityLog::where('customer_id', $service->customer_id)->where('description', 'like', '%CV-4-8%')->exists(),
            'عملیاتِ حساس باید در لاگِ فعالیت بنشیند'
        );
    }

    /**
     * تنزلِ پردازنده/حافظه با **دیسکِ برابر** مجاز است، ولی `upgrade_disk`
     * نباید true برود: بزرگ‌کردنِ دیسک یک‌طرفه است و راهِ تنزلِ بعدی را می‌بندد.
     */
    public function test_downgrade_with_the_same_disk_does_not_grow_the_disk(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);
        $this->plan('hetzner', [
            'provider_ref' => 'cpx11', 'public_name' => 'CV-1-2',
            'slug' => 'cv-1c-2g-40d-de-falkenstein',
            'vcpu' => 1, 'ram_mb' => 2048, 'disk_gb' => 40,
            'cost_eur_cents' => 250, 'price_eur_cents' => 400, 'price_irt' => 400000,
        ]);

        $payload = null;
        Http::fake(function ($request) use (&$payload) {
            if (str_contains($request->url(), 'actions/change_type')) {
                $payload = $request->data();

                return Http::response(['action' => []], 201);
            }

            return Http::response(['server' => [
                'id' => 999, 'status' => 'off',
                'public_net' => ['ipv4' => ['ip' => '203.0.113.7']], 'outgoing_traffic' => 0,
            ]], 200);
        });

        $r = $this->ops()->resize($service, 'cv-1c-2g-40d-de-falkenstein');

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertFalse($r['upgraded_disk']);
        $this->assertFalse((bool) ($payload['upgrade_disk'] ?? true));
        $this->assertSame(400000, (int) $service->fresh()->price);
    }

    /**
     * خطای خامِ زیرساخت نامِ بومیِ پلن و مکان را دارد. اگر مستقیم به مشتری
     * برود، همان یک خط کلِ سفیدبرچسبی را می‌شکند.
     */
    public function test_provider_error_text_is_scrubbed_before_reaching_the_customer(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);
        $this->biggerPlan();

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'actions/change_type')) {
                return Http::response([
                    'error' => ['code' => 'resource_unavailable', 'message' => 'server type cx32 is not available in fsn1 (hetzner)'],
                ], 409);
            }

            return Http::response(['server' => [
                'id' => 999, 'status' => 'off',
                'public_net' => ['ipv4' => ['ip' => '203.0.113.7']], 'outgoing_traffic' => 0,
            ]], 200);
        });

        $r = $this->ops()->resize($service, 'cv-4c-8g-80d-de-falkenstein');

        $this->assertFalse($r['ok']);
        $this->assertNoLeak($r['message'], 'خطای خامِ زیرساخت');
        $this->assertStringContainsString('resource_unavailable', $r['message'], 'کدِ خطا برای پشتیبانی بماند');

        // قیمت و پلن نباید عوض شده باشند
        $this->assertSame(570000, (int) $service->fresh()->price);
    }

    // ═══════════════════ قابلیت‌هایی که هنوز درایور ندارد ═══════════════════

    /**
     * اسنپ‌شات: زیرساختِ ۱ توانایی‌اش را اعلام می‌کند ولی درایور هنوز متدش را
     * ندارد. باید پیامِ خنثا بدهد و **هیچ تماسی** نفرستد — نه اینکه مسیرِ
     * ناموجود را صدا بزند و ۴۰۴ بگیرد.
     */
    public function test_snapshot_family_is_neutral_and_silent_until_the_driver_supports_it(): void
    {
        $service = $this->delivered();

        Http::fake();

        $calls = [
            'snapshot'        => $this->ops()->snapshot($service, 'پیش از ارتقا'),
            'listSnapshots'   => $this->ops()->listSnapshots($service),
            'restoreSnapshot' => $this->ops()->restoreSnapshot($service, '12345', true),
            'deleteSnapshot'  => $this->ops()->deleteSnapshot($service, '12345'),
        ];

        foreach ($calls as $name => $r) {
            $this->assertFalse($r['ok'], $name);
            $this->assertStringContainsString('در دسترس نیست', $r['message'], $name);
            $this->assertNoLeak($r['message'], $name);
        }

        $this->assertSame([], $calls['listSnapshots']['items']);
        Http::assertNothingSent();
    }

    /** روی زیرساختی که اصلاً توانایی‌اش را اعلام نکرده هم همان پیام */
    public function test_snapshot_is_neutral_on_infrastructure_without_the_capability(): void
    {
        $service = $this->delivered('aeza', ['provider_ref' => '77', 'provider_location' => '17']);

        Http::fake();

        $r = $this->ops()->snapshot($service);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('در دسترس نیست', $r['message']);
        Http::assertNothingSent();
        $this->assertNoLeak($r['message'], 'اسنپ‌شاتِ زیرساختِ دوم');
    }

    public function test_rescue_mode_is_neutral_and_silent_until_the_driver_supports_it(): void
    {
        $service = $this->delivered();

        Http::fake();

        $on = $this->ops()->rescue($service);
        $off = $this->ops()->disableRescue($service);

        $this->assertFalse($on['ok']);
        $this->assertNull($on['password']);
        $this->assertFalse($off['ok']);
        $this->assertStringContainsString('در دسترس نیست', $on['message']);
        $this->assertStringContainsString('در دسترس نیست', $off['message']);
        $this->assertNoLeak($on['message'], 'حالتِ نجات');
        Http::assertNothingSent();
    }

    // ═══════════════════ رکوردِ معکوس ═══════════════════

    /**
     * ⚠️ ورودیِ بد باید **قبل** از سنجشِ قابلیت رد شود، وگرنه کاربر پیامِ
     * «در دسترس نیست» می‌گیرد و هرگز نمی‌فهمد نامی که نوشته هم غلط بوده.
     */
    public function test_invalid_reverse_dns_is_rejected_with_a_validation_message(): void
    {
        $service = $this->delivered();

        Http::fake();

        $bad = [
            'localhost',                      // بی‌نقطه
            'mail example.com',               // فاصله
            '-mail.example.com',              // خط تیره در ابتدا
            'mail-.example.com',              // خط تیره در انتها
            'mail..example.com',              // برچسبِ خالی
            'mail.example.123',               // پسوندِ عددی
            'mail.example.com/../etc',        // کاراکترِ ویژه
            'صندوق.example.com',              // غیرِ ASCII
            '',                               // خالی
            str_repeat('a', 70).'.example.com', // برچسبِ بلندتر از ۶۳
        ];

        foreach ($bad as $ptr) {
            $r = $this->ops()->reverseDns($service, $ptr);

            $this->assertFalse($r['ok'], "«{$ptr}» باید رد شود");
            $this->assertStringContainsString('نامِ میزبان', $r['message'], "«{$ptr}»");
        }

        Http::assertNothingSent();
    }

    /** نامِ درست ولی قابلیتِ نبود → پیامِ خنثا، هنوز بی‌تماس */
    public function test_valid_reverse_dns_is_neutral_until_the_driver_supports_it(): void
    {
        $service = $this->delivered();

        Http::fake();

        foreach (['mail.example.com', 'MAIL.Example.COM.', 'a.b.example.co.uk'] as $ptr) {
            $r = $this->ops()->reverseDns($service, $ptr);

            $this->assertFalse($r['ok']);
            $this->assertStringContainsString('در دسترس نیست', $r['message'], $ptr);
            $this->assertNoLeak($r['message'], 'PTR');
        }

        Http::assertNothingSent();
    }

    // ═══════════════════ مصرفِ ترافیک ═══════════════════

    /** درصد باید **رو به بالا** گرد شود: کم‌نماییِ مصرف، هشدار را بی‌فایده می‌کند */
    public function test_traffic_usage_reports_percent_rounded_up(): void
    {
        $service = $this->delivered('hetzner', [], [
            'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
                'traffic_gb' => 1000, 'cpu_kind' => 'shared', 'plan_name' => 'CV-2-4'],
        ]);

        Http::fake(fn () => Http::response(['server' => [
            'id' => 999, 'status' => 'running',
            'public_net' => ['ipv4' => ['ip' => '203.0.113.7'], 'ipv6' => ['ip' => '2a01:4f8::1']],
            'outgoing_traffic' => 1073741824,          // ۱ گیگابایت از ۱۰۰۰
        ]], 200));

        $r = $this->ops()->trafficUsage($service);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(1.0, $r['used_gb']);
        $this->assertSame(1000, $r['quota_gb']);
        $this->assertSame(1, $r['percent'], '۰٫۱٪ باید ۱٪ شود نه ۰٪');
        $this->assertFalse($r['unlimited']);
        $this->assertSame(999.0, $r['remaining_gb']);
        $this->assertSame('1 GB', $r['used_label']);
    }

    /** سهمیهٔ ۰ = مصرفِ منصفانه؛ درصد بی‌معناست و null می‌ماند */
    public function test_traffic_usage_of_a_fair_use_plan_has_no_percent(): void
    {
        $service = $this->delivered('hetzner', [], [
            'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
                'traffic_gb' => 0, 'cpu_kind' => 'shared', 'plan_name' => 'CV-2-4'],
        ]);

        Http::fake(fn () => Http::response(['server' => [
            'id' => 999, 'status' => 'running',
            'public_net' => ['ipv4' => ['ip' => '203.0.113.7']],
            'outgoing_traffic' => 2199023255552,       // ۲ ترابایت
        ]], 200));

        $r = $this->ops()->trafficUsage($service);

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['unlimited']);
        $this->assertNull($r['percent']);
        $this->assertSame('2 TB', $r['used_label']);
    }

    /** خرابیِ زیرساخت نباید صفحه را بشکند — فقط ok=false با پیامِ خنثا */
    public function test_traffic_usage_survives_a_provider_failure(): void
    {
        $service = $this->delivered();

        Http::fake(fn () => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'bad token for hetzner']], 401));

        $r = $this->ops()->trafficUsage($service);

        $this->assertFalse($r['ok']);
        $this->assertNull($r['used_gb']);
        $this->assertNoLeak($r['message'], 'خطای ترافیک');
    }

    // ═══════════════════ ایمیج‌های نصب‌شدنی ═══════════════════

    /**
     * سه فیلتر که اگر نباشند، مشتری گزینه‌ای می‌بیند که نصبش شکست می‌خورد:
     * زیرساختِ سرور، معماری، و حداقلِ دیسک.
     */
    public function test_rebuildable_images_lists_only_what_actually_fits(): void
    {
        $service = $this->delivered();          // دیسک ۴۰ گیگ، معماری x86

        $this->image();                                                              // ✔
        $this->image(['provider_ref' => 'app-docker', 'key' => 'app-docker', 'kind' => 'app',
            'family' => 'docker', 'version' => null, 'label' => 'Docker CE', 'min_disk_gb' => 20]); // ✔
        $this->image(['provider_ref' => 'windows-2022', 'key' => 'windows-2022', 'family' => 'windows',
            'version' => '2022', 'label' => 'Windows 2022', 'min_disk_gb' => 60]);   // ✘ دیسک
        $this->image(['provider_ref' => 'debian-12-arm', 'key' => 'debian-12', 'family' => 'debian',
            'version' => '12', 'label' => 'Debian 12', 'arch' => 'arm']);            // ✘ معماری
        $this->image(['provider' => 'aeza', 'provider_ref' => '1042', 'key' => 'alpine-3.20',
            'family' => 'alpine', 'version' => '3.20', 'label' => 'Alpine 3.20']);   // ✘ زیرساخت

        Http::fake();

        $r = $this->ops()->rebuildableImages($service);

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['count']);

        $keys = array_column($r['images'], 'key');
        sort($keys);
        $this->assertSame(['app-docker', 'ubuntu-24.04'], $keys);

        // سفیدبرچسبی: شناسهٔ بومی نباید در خروجی باشد
        foreach ($r['images'] as $img) {
            $this->assertArrayNotHasKey('provider_ref', $img);
            $this->assertArrayNotHasKey('provider', $img);
        }

        // ایمیجِ فعلی علامت خورده باشد
        $current = collect($r['images'])->firstWhere('key', 'ubuntu-24.04');
        $this->assertTrue($current['current']);

        Http::assertNothingSent();
    }

    // ═══════════════════ استواری ═══════════════════

    /** سرویسی که هنوز سرور ندارد: همه چیز تمیز رد شود، بی‌استثنا و بی‌تماس */
    public function test_every_operation_is_safe_on_a_service_without_an_instance(): void
    {
        $plan = $this->plan();
        $service = Service::create([
            'customer_id' => $this->customer()->id, 'name' => 'سرورِ ابری',
            'price' => 570000, 'cycle' => 'monthly', 'status' => 'awaiting_provision',
            'provision_status' => 'pending', 'cloud_plan_id' => $plan->id,
        ]);

        Http::fake();

        $results = [
            'resize'      => $this->ops()->resize($service, 'cv-2c-4g-40d-de-falkenstein'),
            'snapshot'    => $this->ops()->snapshot($service),
            'list'        => $this->ops()->listSnapshots($service),
            'restore'     => $this->ops()->restoreSnapshot($service, '1', true),
            'delete'      => $this->ops()->deleteSnapshot($service, '1'),
            'rescue'      => $this->ops()->rescue($service),
            'unrescue'    => $this->ops()->disableRescue($service),
            'rdns'        => $this->ops()->reverseDns($service, 'mail.example.com'),
            'traffic'     => $this->ops()->trafficUsage($service),
            'images'      => $this->ops()->rebuildableImages($service),
        ];

        foreach ($results as $name => $r) {
            $this->assertFalse($r['ok'], $name);
            $this->assertNotSame('', $r['message'], $name);
            $this->assertNoLeak($r['message'], $name);
        }

        Http::assertNothingSent();
    }

    /** سرورِ حذف‌شده هیچ عملیاتی نمی‌پذیرد */
    public function test_deleted_server_accepts_nothing(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'deleted']);
        $this->biggerPlan();

        Http::fake();

        $r = $this->ops()->resize($service, 'cv-4c-8g-80d-de-falkenstein');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('حذف', $r['message']);
        Http::assertNothingSent();
    }

    /** سرویسِ غیرِ ابری (هاستِ اشتراکی) از این مسیر نمی‌رود */
    public function test_non_cloud_service_gets_a_neutral_answer(): void
    {
        $service = Service::create([
            'customer_id' => $this->customer()->id, 'name' => 'هاستِ اشتراکی',
            'price' => 250000, 'cycle' => 'monthly', 'status' => 'active',
        ]);

        Http::fake();

        $this->assertFalse($this->ops()->trafficUsage($service)['ok']);
        $this->assertFalse($this->ops()->snapshot($service)['ok']);
        Http::assertNothingSent();
    }

    /**
     * جمعِ همهٔ پیام‌ها در یک جا — قاعدهٔ مطلقِ این حوزه.
     *
     * چرا تستِ جدا با آنکه هر تست خودش هم می‌سنجد: پیامِ تازه‌ای که بعداً اضافه
     * شود، معمولاً تستِ خودش را دارد ولی این‌جا هم می‌افتد و اگر نامِ زیرساخت
     * داشته باشد، همین تست می‌گیردش.
     */
    public function test_no_operation_ever_leaks_the_infrastructure_name(): void
    {
        $service = $this->delivered('hetzner', [], ['status' => 'off']);
        $this->biggerPlan('aeza', ['provider_ref' => '4488', 'provider_location' => '17']);
        $this->image();

        Http::fake(fn () => Http::response(['server' => [
            'id' => 999, 'status' => 'off',
            'public_net' => ['ipv4' => ['ip' => '203.0.113.7']], 'outgoing_traffic' => 0,
        ]], 200));

        $messages = [
            $this->ops()->resize($service, 'cv-4c-8g-80d-de-falkenstein')['message'],
            $this->ops()->resize($service, 'nope')['message'],
            $this->ops()->snapshot($service, 'x')['message'],
            $this->ops()->listSnapshots($service)['message'],
            $this->ops()->restoreSnapshot($service, '1')['message'],
            $this->ops()->restoreSnapshot($service, '1', true)['message'],
            $this->ops()->deleteSnapshot($service, '1')['message'],
            $this->ops()->rescue($service)['message'],
            $this->ops()->disableRescue($service)['message'],
            $this->ops()->reverseDns($service, 'mail.example.com')['message'],
            $this->ops()->reverseDns($service, '!!')['message'],
            $this->ops()->trafficUsage($service)['message'],
            $this->ops()->rebuildableImages($service)['message'],
        ];

        foreach ($messages as $i => $m) {
            $this->assertNoLeak((string) $m, 'شمارهٔ '.$i);
        }
    }
}
