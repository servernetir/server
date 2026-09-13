<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\Setting;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Support\Facades\Http;

/**
 * تحویلِ انتها-به-انتهای GPU — همان مسیری که «شکست در تحویل» گزارش شد.
 *
 * از فیکسچرهای CloudProvisionTest ارث می‌برد و همان CloudProvisioner واقعی را
 * می‌دواند؛ فقط HTTP جعلی است. اگر این سبز باشد، خطِ لولهٔ خودِ ما تا لحظهٔ
 * تماسِ API سالم است و شکستِ واقعی از پاسخِ زیرساخت (حساب/سهمیه/بدنه) می‌آید.
 */
class GpuDeliveryE2eTest extends CloudProvisionTest
{
    private function seedGpu(): array
    {
        Setting::putSecret('salad_api_key', 'k-test');
        Setting::put('salad_org', 'servernet');
        Setting::put('salad_project', 'prod');
        Setting::put('pricing_rate_override', '100000');
        Setting::put('pricing_usd_rate_override', '90000');

        CloudLocation::firstOrCreate(['code' => 'global-gpu'],
            ['country' => 'XX', 'is_active' => true, 'sort' => 1]);

        // همان ردیفی که cloud:sync از APPS می‌نویسد
        CloudImage::create([
            'provider' => 'salad',
            'provider_ref' => \App\Services\Cloud\SaladClient::APPS['gpu-ollama']['ref'],
            'key' => 'gpu-ollama', 'kind' => 'app', 'family' => 'gpu-ollama',
            'label' => 'Ollama', 'arch' => 'x86', 'is_active' => true,
        ]);

        $plan = $this->plan('salad', [
            'provider_ref' => 'a1b2c3d4-0000-0000-0000-000000000001',
            'provider_location' => 'global', 'location_code' => 'global-gpu',
            'public_name' => 'RTX 4090', 'slug' => 'cv-8c-30g-100d-global-gpu-rtx-4090',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 50,
            'gpu_model' => 'RTX 4090', 'gpu_count' => 1, 'is_interruptible' => true,
        ]);

        $service = $this->service($plan, [
            'cloud_image_key' => 'gpu-ollama',
            'cycle' => 'hourly',
        ]);

        return [$plan, $service];
    }

    /**
     * 🔴 مسیرِ کاملِ تحویل: سفارشِ ساعتیِ GPU با برنامهٔ انتخابی باید بدونِ
     * هیچ شکستِ درون-خطی به تماسِ ساختِ کانتینر برسد و نمونه ثبت شود.
     */
    public function test_a_gpu_order_provisions_end_to_end(): void
    {
        [$plan, $service] = $this->seedGpu();

        $sent = null;

        Http::fake(function ($request) use (&$sent) {
            $url = $request->url();

            if (str_contains($url, '/containers') && $request->method() === 'POST') {
                $sent = $request->data();

                return Http::response(['name' => $sent['name'] ?? 'sn-svc-x',
                    'current_state' => ['status' => 'pending']], 201);
            }

            // serverStatus بعد از ساخت
            if (str_contains($url, '/instances')) {
                return Http::response(['instances' => [[
                    'machine_id' => 'm-1', 'state' => 'running',
                    'ssh_ip' => '203.0.113.77', 'started' => true,
                ]]], 200);
            }

            if (str_contains($url, '/containers/')) {
                return Http::response(['name' => 'sn-svc-'.request()->id,
                    'current_state' => ['status' => 'running'],
                    'networking' => ['dns' => 'demo.salad.cloud']], 200);
            }

            return Http::response(['items' => []], 200);
        });

        $ok = app(CloudProvisioner::class)->provision($service);

        $service->refresh();
        $instance = CloudInstance::where('service_id', $service->id)->first();

        $this->assertTrue($ok, 'تحویل شکست خورد: '
            .(string) ($instance?->last_error ?? data_get($service->provision_meta, 'error', '—'))
            .' | provision_status='.$service->provision_status);

        $this->assertNotNull($sent, 'هیچ تماسِ ساختی به زیرساخت نرفت.');
        $this->assertSame(\App\Services\Cloud\SaladClient::APPS['gpu-ollama']['ref'],
            data_get($sent, 'container.image'), 'ایمیجِ انتخابیِ مشتری فرستاده نشد.');
        $this->assertSame(11434, data_get($sent, 'networking.port'), 'دروازه با پورتِ برنامه روشن نشد.');
        $this->assertNotNull($instance);
        $this->assertNotNull($instance->provider_ref, 'شناسهٔ گروه ثبت نشد.');

        /*
        | IP این زیرساخت در پاسخِ ساخت نیست؛ بعد از تخصیصِ نمونه می‌آید و
        | کرونِ cloud:sync-instances می‌نشاندش — پس همان مسیر را می‌دوانیم.
        */
        app(CloudProvisioner::class)->syncInstances();
        $instance->refresh();

        $this->assertSame('203.0.113.77', $instance->ipv4, 'IP از سینکِ وضعیت ننشست.');
        $this->assertSame('demo.salad.cloud', $instance->hostname,
            'نشانیِ دروازه در hostname ننشست — مشتری چیزی برای وصل‌شدن ندارد.');
    }

    /**
     * 🔴 مسیرِ وضعیتِ پنل باید پیشرفتِ **واقعیِ** ساخت را رد کند: درصدِ
     * «Pulling, N%» عیناً از زیرساخت به JSON صفحه برسد. بی‌این، مشتریِ GPU
     * تا یک ساعت روی مرحلهٔ بی‌حرکتِ «در حالِ ساخت» می‌مانَد و فکر می‌کند
     * خرید خراب شده — همان تیکت‌های واقعی.
     */
    public function test_the_status_endpoint_streams_the_real_build_progress(): void
    {
        [$plan, $service] = $this->seedGpu();

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/containers') && $request->method() === 'POST') {
                return Http::response(['name' => $request->data()['name'] ?? 'sn-svc-x',
                    'current_state' => ['status' => 'pending']], 201);
            }

            if (str_contains($url, '/instances')) {
                // هنوز هیچ گره‌ای تخصیص نگرفته — وسطِ فازِ pulling
                return Http::response(['instances' => []], 200);
            }

            if (str_contains($url, '/containers/')) {
                return Http::response([
                    'current_state' => ['status' => 'pending', 'description' => 'Pulling, 37% complete'],
                ], 200);
            }

            return Http::response(['items' => []], 200);
        });

        $this->assertTrue(app(CloudProvisioner::class)->provision($service));

        $this->actingAs($service->customer, 'customer')
            ->getJson(route('account.cloud.status', $service))
            ->assertOk()
            ->assertJson([
                'ready' => false,
                'stage' => 'building',
                'build' => ['phase' => 'pulling', 'pull_pct' => 37],
            ]);
    }
}
