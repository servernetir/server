<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Notify\CustomerNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 خطِ GPU هیچ‌جا SSH، root یا IP نشان نمی‌دهد — تنها راهِ ورود نشانیِ دروازه + توکن است.
 *
 * ═══ رخداد (مهر ۱۴۰۵) ═══
 * سه مشتری در سه روز تیکت زدند «رمزِ SSH/root کجاست؟». این خط نه SSH دارد، نه
 * root، نه رمز. ولی:
 *   · کارتِ داشبورد برای GPU هم «ssh root@<IP>» چاپ می‌کرد — با پورتِ ۲۲ که
 *     اصلاً درست نیست، و IPای که `ssh_ip`ِ خودِ زیرساخت است؛
 *   · سربرگِ صفحهٔ مدیریت همان IP را نشان می‌داد؛
 *   · پیامک/بلهٔ «آماده شد» می‌گفت «آدرس: <IP>»؛
 *   · ردیفِ سرویس با username=root ذخیره می‌شد، پس پنلِ مدیریت «کاربر: root» می‌گفت.
 * صفحهٔ جزئیات از قبل درست بود (پشتِ `$gpuApp`) — و همین باعث شد بقیه دیده نشوند.
 *
 * ⚠️ ادعاها روی **متنِ رندرشده و مقدارِ ارسال‌شده** است، نه روی اینکه شرطی وجود دارد.
 * ⚠️ نیمهٔ دیگر هم سنجیده می‌شود: سرورِ مجازیِ معمولی باید SSHِ خودش را نگه دارد.
 */
class GpuNoSshSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const SALAD_IP = '195.181.163.241';

    private const LABEL = 'amber-owl-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Setting::put('salad_branded_domain', 'servernet.cloud');
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'g'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function gpuService(Customer $c): Service
    {
        CloudLocation::firstOrCreate(['code' => 'global-gpu'], ['country' => 'XX', 'is_active' => true]);

        $plan = CloudPlan::create([
            'provider' => 'salad', 'provider_ref' => 'gc-3090', 'provider_location' => 'global',
            'location_code' => 'global-gpu', 'public_name' => 'RTX 3090',
            'slug' => 'cv-8c-30g-150d-global-gpu-rtx-3090', 'vcpu' => 8, 'ram_mb' => 30720,
            'disk_gb' => 150, 'disk_type' => 'ssd', 'traffic_gb' => 0, 'cpu_kind' => 'shared',
            'arch' => 'x86', 'cost_eur_cents' => 400, 'price_eur_cents' => 600, 'price_irt' => 720_000,
            'is_active' => true, 'in_stock' => true,
            'gpu_model' => 'RTX 3090', 'gpu_count' => 1, 'is_interruptible' => true,
        ]);

        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرور مجازی gpu-test (ساعتی)', 'currency_code' => 'IRT',
            'price' => 0, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'active',
            'provision_status' => 'done', 'cloud_plan_id' => $plan->id, 'activated_at' => now(),
        ]);

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'salad', 'provider_ref' => 'sn-svc-'.$s->id,
            'location_code' => 'global-gpu', 'image_key' => 'gpu-ollama',
            'hostname' => self::LABEL.'.salad.cloud', 'ipv4' => self::SALAD_IP,
            'status' => 'running', 'password_seen' => true,
        ]);

        return $s;
    }

    private function vpsService(Customer $c): Service
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 20480,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 379, 'price_eur_cents' => 570,
            'price_irt' => 570_000, 'is_active' => true, 'in_stock' => true,
        ]);

        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرور مجازی vps-test', 'currency_code' => 'IRT',
            'price' => 570_000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'active',
            'provision_status' => 'done', 'cloud_plan_id' => $plan->id, 'activated_at' => now(),
        ]);

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'hetzner', 'provider_ref' => '42',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '203.0.113.45',
            'status' => 'running', 'password_seen' => true,
        ]);

        return $s;
    }

    public function test_the_model_knows_a_gpu_app_and_its_gateway(): void
    {
        $i = $this->gpuService($this->customer())->cloudInstance;

        $this->assertTrue($i->isGpuApp());
        $this->assertSame('https://g-'.self::LABEL.'.servernet.cloud', $i->gatewayUrl());
    }

    public function test_only_an_image_key_starting_with_gpu_is_a_gpu_app(): void
    {
        $this->assertFalse((new CloudInstance(['image_key' => 'ubuntu-24.04']))->isGpuApp());
        $this->assertFalse((new CloudInstance(['image_key' => null]))->isGpuApp());
        $this->assertNull(
            (new CloudInstance(['image_key' => 'ubuntu-24.04', 'hostname' => 'x.salad.cloud']))->gatewayUrl(),
            'نشانیِ دروازه فقط برای برنامهٔ GPU معنا دارد.'
        );
    }

    /** 🔴 ریشهٔ تیکت‌ها: کارتِ داشبورد */
    public function test_the_dashboard_card_shows_the_gateway_not_ssh(): void
    {
        $c = $this->customer();
        $this->gpuService($c);

        $html = (string) $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringNotContainsString('ssh root', $html,
            'کارتِ داشبورد برای GPU دستورِ SSH چاپ می‌کند — این خط SSH ندارد.');
        $this->assertStringNotContainsString(self::SALAD_IP, $html,
            'IPِ زیرساخت روی کارت است؛ هم بی‌فایده است هم سفیدبرچسبی را می‌شکند.');
        $this->assertStringContainsString('g-'.self::LABEL.'.servernet.cloud', $html,
            'نشانیِ دروازه — تنها راهِ ورود — روی کارت نیست.');
        $this->assertStringContainsString(e(__('ui.svc_gpu_card_note')), $html);
    }

    /**
     * ⚠️ نیمهٔ دیگر در **همان صفحه**: شرطی که SSH را برای همه خاموش کند، این‌جا
     * گیر می‌افتد — تستِ جدا روی دو پنل آن را نمی‌دید.
     */
    public function test_a_normal_vps_on_the_same_dashboard_keeps_its_ssh_line(): void
    {
        $c = $this->customer();
        $this->gpuService($c);
        $this->vpsService($c);

        $html = (string) $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('ssh root@203.0.113.45', $html);
        $this->assertSame(1, substr_count($html, 'ssh root@'),
            'دستورِ SSH باید فقط برای سرورِ مجازی بیاید، نه برای GPU.');
    }

    public function test_the_management_page_header_shows_the_gateway_not_the_ip(): void
    {
        $c = $this->customer();
        $s = $this->gpuService($c);

        $html = (string) $this->actingAs($c, 'customer')
            ->get(route('account.cloud.show', $s, false))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::SALAD_IP, $html,
            'صفحهٔ مدیریت هنوز IPِ زیرساخت را نشان می‌دهد.');
        $this->assertStringNotContainsString('ssh root', $html);
        $this->assertStringContainsString('g-'.self::LABEL.'.servernet.cloud', $html);
    }

    /** 🔴 پیامک/بلهٔ «آماده شد» */
    public function test_the_ready_notice_sends_the_gateway_not_the_ip(): void
    {
        $c = $this->customer();
        $s = $this->gpuService($c);
        $s->cloudInstance->forceFill(['ready_notified_at' => null])->save();

        $sent = [];
        $this->mock(CustomerNotifier::class, function ($m) use (&$sent) {
            $m->shouldReceive('event')->andReturnUsing(function ($cust, $key, $vars = [], $text = '') use (&$sent) {
                $sent[] = ['key' => $key, 'vars' => $vars, 'text' => $text];

                return true;
            });
            $m->shouldReceive('templated')->andReturn(true);
        });

        app(CloudProvisioner::class)->deliverOwedNotices();

        $ready = collect($sent)->firstWhere('key', 'service_ready');
        $this->assertNotNull($ready, 'اعلانِ «آماده شد» اصلاً نرفت — نباید با این تغییر ساکت شود.');
        $this->assertStringNotContainsString(self::SALAD_IP, $ready['text'].json_encode($ready['vars']),
            'پیامکِ تحویلِ GPU هنوز IPِ زیرساخت را می‌فرستد.');
        $this->assertStringContainsString('g-'.self::LABEL.'.servernet.cloud', $ready['text']);
        $this->assertSame('g-'.self::LABEL.'.servernet.cloud', $ready['vars']['ip'],
            'متغیرِ {ip}ِ الگوهای مدیر باید نشانیِ دروازه را بگیرد.');
    }
}
