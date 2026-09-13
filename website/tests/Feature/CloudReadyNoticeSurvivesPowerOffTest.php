<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ایمیلِ «سرورت آماده شد» نباید به **روشن‌بودن** ماشین گره بخورد.
 *
 * ═══ رخدادِ پروداکشن (سرویس‌های #۱۱۸ و #۱۲۰) ═══
 *
 * `deliverOwedNotices()` فقط ردیف‌های `running` را برمی‌داشت. یعنی مشتری‌ای
 * که سرورش را **پیش از رسیدنِ ایمیل** خاموش کند، اعلانِ تحویل را هرگز
 * نمی‌گیرد — نه آن دقیقه، نه هیچ‌وقت: `ready_notified_at` نال می‌مانَد و
 * کرونِ هر-دقیقه تا ابد ردش می‌کند.
 *
 * نتیجه‌اش مشتری‌ای است که سرور دارد و **رمزِ rootش را ندارد**، و در
 * `/admin/errors` ردیفی که ساعت‌به‌ساعت می‌گوید «سرور آماده است ولی ایمیلِ
 * تحویلش نرفته» — جمله‌ای که درست است و علت را نمی‌گوید.
 *
 * ⚠️ محتوای آن ایمیل (آدرس و رمز) به **وجود داشتنِ** ماشین ربط دارد، نه به
 * روشن‌بودنش.
 */
class CloudReadyNoticeSurvivesPowerOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function instanceWith(string $status): CloudInstance
    {
        $customer = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'rn'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22-'.random_int(1, 999999),
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);

        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'سرورِ ابری',
            'currency_code' => 'IRT', 'price' => 570000, 'tax_percent' => 0,
            'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'activated_at' => now(), 'next_due_at' => now()->addMonth(),
        ]);

        return CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'hetzner', 'provider_ref' => '4711',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$service->id, 'ipv4' => '185.51.200.9',
            'status' => $status, 'ready_notified_at' => null,
            'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40],
            'synced_at' => now(),
        ]);
    }

    // ───────────────────────── ادعاها ─────────────────────────

    /** 🔴 ادعای اصلی: ماشینِ **خاموش** هم ایمیلِ تحویلش را می‌گیرد */
    public function test_a_powered_off_server_still_gets_its_delivery_email(): void
    {
        $instance = $this->instanceWith('off');

        app(CloudProvisioner::class)->deliverOwedNotices();

        $this->assertNotNull($instance->fresh()->ready_notified_at,
            'مشتری نباید صاحبِ سروری باشد که رمزِ rootش را ندارد.');
    }

    /** رفتارِ قبلی برای ماشینِ روشن دست‌نخورده می‌مانَد */
    public function test_a_running_server_still_gets_it(): void
    {
        $instance = $this->instanceWith('running');

        app(CloudProvisioner::class)->deliverOwedNotices();

        $this->assertNotNull($instance->fresh()->ready_notified_at);
    }

    /**
     * ⚠️ ماشینِ در حالِ ساخت **نباید** بگیرد: آن‌جا IP هنوز واقعی نیست و
     * ایمیلِ زودهنگام آدرسی می‌دهد که کار نمی‌کند — و چون `ready_notified_at`
     * همان لحظه قفل می‌شود، ایمیلِ **درست** هم دیگر هرگز نمی‌رود.
     */
    public function test_a_building_server_is_still_skipped(): void
    {
        $instance = $this->instanceWith('building');

        app(CloudProvisioner::class)->deliverOwedNotices();

        $this->assertNull($instance->fresh()->ready_notified_at);
    }

    /** و دو بار اجرا، دو ایمیل نمی‌فرستد */
    public function test_running_twice_notifies_only_once(): void
    {
        $instance = $this->instanceWith('off');

        app(CloudProvisioner::class)->deliverOwedNotices();
        $first = $instance->fresh()->ready_notified_at;

        app(CloudProvisioner::class)->deliverOwedNotices();

        $this->assertEquals($first, $instance->fresh()->ready_notified_at);
    }
}
