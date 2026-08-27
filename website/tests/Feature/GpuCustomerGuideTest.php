<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * راهنمای استفادهٔ مشتری + دامنهٔ برندشده — خطِ GPU در پنل.
 *
 * ═══ چرا ═══
 *
 * صفحهٔ سرویس برای GPU همان قالبِ VPS را نشان می‌داد: «کاربر root» و فرمانِ
 * `ssh root@IP` — دو چیزی که این محصول اصلاً ندارد. و نشانیِ خامِ دروازه
 * نامِ زیرساخت را لو می‌دهد (*.salad.cloud)، نقضِ قاعدهٔ سفیدبرچسبیِ پروژه.
 * کارفرما: «مشتری باید بدونه چطوری استفاده کنه» + «با ساب‌دامنهٔ خودمون بدیم».
 */
class GpuCustomerGuideTest extends CloudProvisionTest
{
    private function gpuService(string $imageKey = 'gpu-ollama', array $inst = []): array
    {
        \App\Models\CloudLocation::firstOrCreate(['code' => 'global-gpu'],
            ['country' => 'XX', 'is_active' => true, 'sort' => 1]);

        $plan = $this->plan('salad', [
            'provider_ref' => 'gc-1', 'provider_location' => 'global',
            'location_code' => 'global-gpu', 'public_name' => 'RTX 4090',
            'slug' => 'cv-8c-30g-50d-global-gpu-rtx-4090',
            'gpu_model' => 'RTX 4090', 'gpu_count' => 1, 'is_interruptible' => true,
        ]);

        $service = $this->service($plan, [
            'cloud_image_key' => $imageKey, 'status' => 'active', 'provision_status' => 'done',
        ]);

        $instance = CloudInstance::create(array_merge([
            'service_id' => $service->id, 'provider' => 'salad',
            'provider_ref' => 'sn-svc-'.$service->id, 'location_code' => 'global-gpu',
            'image_key' => $imageKey, 'hostname' => 'abc123-def456.salad.cloud',
            'ipv4' => '203.0.113.9', 'status' => 'running',
            'ready_notified_at' => now(),
        ], $inst));

        return [$service, $instance];
    }

    // ═══════════ accessHost — سفیدبرچسبی ═══════════

    /** بدونِ تنظیم، نشانیِ کارا برمی‌گردد (کارایی بر برند مقدم است) */
    public function test_access_host_falls_back_to_the_raw_gateway(): void
    {
        [, $instance] = $this->gpuService();

        $this->assertSame('abc123-def456.salad.cloud', $instance->accessHost());
    }

    /** 🔴 با تنظیمِ دامنهٔ برندشده، نگاشتِ قطعیِ g-{label}.{دامنهٔ ما} */
    public function test_access_host_brands_the_gateway_when_configured(): void
    {
        Setting::put('salad_branded_domain', 'servernet.cloud');
        [, $instance] = $this->gpuService();

        $this->assertSame('g-abc123-def456.servernet.cloud', $instance->accessHost());
    }

    /** جای‌نگهدارِ بی‌نقطه (sn-svc-N) نشانی نیست */
    public function test_a_placeholder_hostname_is_not_an_address(): void
    {
        [, $instance] = $this->gpuService('gpu-ollama', ['hostname' => 'sn-svc-9']);

        $this->assertNull($instance->accessHost());
    }

    // ═══════════ صفحهٔ مشتری ═══════════

    /**
     * 🔴 مشتریِ Ollama باید نشانی + مثالِ فراخوانی ببیند، و هرگز «root» و
     * فرمانِ ssh را نبیند — این محصول هیچ‌کدام را ندارد.
     */
    public function test_the_ollama_customer_sees_the_endpoint_and_a_curl_example(): void
    {
        [$service] = $this->gpuService('gpu-ollama');

        $html = (string) $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->assertOk()->getContent();

        $this->assertStringContainsString('https://abc123-def456.salad.cloud', $html);
        $this->assertStringContainsString('/api/chat', $html, 'مثالِ فراخوانیِ API نیست.');
        $this->assertStringContainsString(__('ui.cs_gpu_use_h'), $html);
        $this->assertStringNotContainsString('ssh root', $html, 'فرمانِ SSH برای محصولِ بی‌SSH.');
        // ساختاری، نه واژه‌ای: «کاربر» در متن‌های دیگرِ صفحه هم هست
        $this->assertStringNotContainsString('<b dir="ltr">root</b>', $html,
            'ردیفِ «کاربر root» برای کانتینر دروغ است.');
    }

    /** و با دامنهٔ برندشده، نامِ زیرساخت هیچ‌جای صفحه نیست */
    public function test_the_branded_page_never_leaks_the_provider_domain(): void
    {
        Setting::put('salad_branded_domain', 'servernet.cloud');
        [$service] = $this->gpuService('gpu-comfyui');

        $html = (string) $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->assertOk()->getContent();

        $this->assertStringContainsString('g-abc123-def456.servernet.cloud', $html);
        $this->assertStringNotContainsString('salad', $html,
            'نامِ زیرساخت به صفحهٔ مشتری نشت کرد — نقضِ سفیدبرچسبی.');
    }

    /** نشانیِ هنوز-نرسیده، پیامِ صادقانه می‌گیرد نه خط تیره */
    public function test_a_pending_address_says_it_is_coming(): void
    {
        [$service] = $this->gpuService('gpu-ollama', ['hostname' => 'sn-svc-9']);

        $html = (string) $this->actingAs($service->customer, 'customer')
            ->get(route('account.cloud.show', $service))->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.cs_gpu_endpoint_wait'), $html);
    }
}
