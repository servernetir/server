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
 * 🔴 کارفرما: «سرورِ مجازی ساختم ولی کاربر هیچی نمی‌بیند، فقط وضعیت. حتی IP هم
 * معلوم نیست.»
 *
 * علت: ردیفِ جزئیاتِ فهرستِ سرویس‌ها پشتِ `@if($s->server_id)` بود و **سرورِ
 * ابری `server_id` ندارد** (پیش از خرید وجود ندارد — همان تلهٔ ثبت‌شده در
 * CLAUDE.md که یک بار کرونِ تحویل را هم بی‌صدا شکسته بود). پس صفحهٔ کاملِ
 * مدیریت (`/account/cloud/{service}`) ساخته و ترجمه‌شده بود ولی **هیچ لینکی
 * به آن نمی‌رفت**.
 *
 * ⚠️ این تست‌ها عمداً **مقدارِ دیداری** را می‌سنجند نه کدِ ۲۰۰. درسِ ثبت‌شدهٔ
 * پروژه: صفحه بارها ۲۰۰ داده و محتوایش مرده بوده. مخصوصاً خطِ SSH، که با
 * `ssh root@{{ $ip }}` بی‌هیچ خطایی **بدونِ IP** چاپ می‌شود.
 */
class CloudServiceRowTest extends TestCase
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
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function cloudService(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرور مجازی vps-test', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $this->plan()->id, 'activated_at' => now(),
        ], $over));
    }

    private function delivered(Service $s, string $ip = '203.0.113.45'): CloudInstance
    {
        return CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'hetzner', 'provider_ref' => '42',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => $ip, 'ipv6' => '2a01:4f8::1',
            'status' => 'running', 'password_seen' => true,
        ]);
    }

    public function test_customer_sees_the_ip_of_a_delivered_cloud_server(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->delivered($s);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('203.0.113.45', $html, 'IP باید دیده شود');
        $this->assertStringContainsString('2a01:4f8::1', $html, 'IPv6 هم');
    }

    /** 🔴 تلهٔ `@{{` — این خط بی‌هیچ خطایی بدونِ IP چاپ می‌شود */
    public function test_ssh_line_actually_contains_the_ip(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->delivered($s);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->getContent();

        $this->assertStringContainsString('ssh root@203.0.113.45', $html,
            'اگر «ssh root{{ $ip }}» چاپ شده باشد یعنی به تلهٔ Blade خورده‌ایم');
    }

    public function test_customer_gets_a_link_to_the_management_page(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->delivered($s);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->getContent();

        $this->assertStringContainsString(route('account.cloud.show', $s, false), $html,
            'بی‌این لینک، صفحهٔ مدیریت برای مشتری وجود ندارد');
        $this->assertStringContainsString('مدیریت سرور', $html);
        $this->assertStringNotContainsString('ui.svc_manage_server', $html, 'کلیدِ خام نباید چاپ شود');
    }

    /** سرورِ تحویل‌نشده هم باید راهی به صفحهٔ مدیریت داشته باشد */
    public function test_undelivered_cloud_service_still_links_to_management(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c, ['status' => 'awaiting_provision', 'provision_status' => 'pending']);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringContainsString(route('account.cloud.show', $s, false), $html);
    }

    /** سرویسِ غیرابری نباید ردیفِ ابری بگیرد */
    public function test_non_cloud_service_shows_no_cloud_row(): void
    {
        $c = $this->customer();
        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'هاست اشتراکی', 'currency_code' => 'IRT',
            'price' => 100000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done', 'activated_at' => now(),
        ]);

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringNotContainsString('/account/cloud/'.$s->id, $html);
    }

    /** 🔴 سرویسِ لغوشده اصلاً نباید در فهرست باشد */
    public function test_cancelled_service_is_hidden(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c, ['status' => 'cancelled', 'name' => 'سرور لغو شده']);
        $this->delivered($s, '198.51.100.7');

        $html = $this->actingAs($c, 'customer')
            ->get(route('account.services', [], false))->assertOk()->getContent();

        $this->assertStringNotContainsString('سرور لغو شده', $html);
        $this->assertStringNotContainsString('198.51.100.7', $html);
    }
}
