<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * سوییچِ کشورِ خروج توسطِ خودِ مشتری (فازِ A).
 *
 * فقط برای سرورهای میزبانِ ایران (Proxmox) که تحویل شده‌اند؛ با گیتِ مالکیت
 * (۴۰۴ برای سرورِ دیگران) و اعتبارسنجیِ کشور — مثلِ بقیهٔ کنش‌های این کنترلر.
 */
class CustomerExitCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function plan(): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein-'.random_int(1, 999999),
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function cloudService(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرور مجازی exit-test', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $this->plan()->id, 'activated_at' => now(),
        ], $over));
    }

    /** یک سرورِ Proxmoxِ تحویل‌شده (اکسیت‌پذیر) */
    private function proxmoxInstance(Service $s, array $over = []): CloudInstance
    {
        return CloudInstance::create(array_merge([
            'service_id' => $s->id, 'provider' => 'proxmox', 'provider_ref' => 'qemu/'.$s->id,
            'location_code' => 'exit-de', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '10.10.10.'.(100 + $s->id),
            'status' => 'running', 'password_seen' => true,
        ], $over));
    }

    public function test_proxmox_server_page_renders_the_exit_control(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->proxmoxInstance($s);

        // مسیرِ زندهٔ مشتری‌رو: صفحه باید ۲۰۰ بدهد و بلوکِ کشورِ خروج را نشان دهد
        // (اطمینان از اینکه ویرایشِ Blade روی سرورِ لایو ۵۰۰ نمی‌دهد).
        $this->actingAs($c, 'customer')
            ->get('/account/cloud/'.$s->id)
            ->assertOk()
            ->assertSee('کشورِ خروجِ اینترنت');
    }

    public function test_customer_can_switch_own_server_exit_country(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $inst = $this->proxmoxInstance($s);

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/exit-country', ['country' => 'nl'])
            ->assertRedirect();

        $this->assertSame('nl', $inst->fresh()->meta['exit_country']);
    }

    public function test_customer_can_disable_exit(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $inst = $this->proxmoxInstance($s);

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/exit-country', ['country' => 'ir'])
            ->assertRedirect();

        $this->assertNull($inst->fresh()->exitCountryCode());   // ir → بدونِ اکسیت
    }

    public function test_switch_rejected_for_non_proxmox_server(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $inst = $this->proxmoxInstance($s, ['provider' => 'hetzner', 'ipv4' => '203.0.113.9']);

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/exit-country', ['country' => 'nl'])
            ->assertRedirect();   // withErrors → بازگشت

        $this->assertArrayNotHasKey('exit_country', $inst->fresh()->meta ?? []);
    }

    public function test_invalid_country_is_rejected(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $inst = $this->proxmoxInstance($s);

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/exit-country', ['country' => 'xx'])
            ->assertRedirect();

        $this->assertArrayNotHasKey('exit_country', $inst->fresh()->meta ?? []);
    }

    public function test_customer_cannot_switch_another_customers_server(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $s = $this->cloudService($owner);
        $inst = $this->proxmoxInstance($s);

        $this->actingAs($other, 'customer')
            ->post('/account/cloud/'.$s->id.'/exit-country', ['country' => 'nl'])
            ->assertNotFound();   // ownedService → ۴۰۴

        $this->assertArrayNotHasKey('exit_country', $inst->fresh()->meta ?? []);
    }
}
