<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\ProxmoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * کلونِ Proxmox باید ماشین را داخلِ poolِ مشتریان بسازد.
 *
 * ═══ چرا این یک قرارداد است نه سلیقه (رخدادِ سرویس #74) ═══
 *
 * 🔴 ACLِ توکنِ کنترلر روی نودِ اکسیت عمداً کم‌دسترسی است: VM.Allocate فقط
 * روی `/pool/customers`. کلونِ بدونِ `pool` بررسیِ allocate را روی
 * `/vms/{newid}` می‌بَرد و رد می‌شود؛ و ماشینی هم که بیرونِ pool ساخته شود،
 * برای config/start/حذفِ بعدیِ همین توکن نامرئی است. حذفِ این پارامتر یعنی
 * برگشتِ همان «Permission check failed» با ظاهری دیگر.
 */
class ProxmoxClonePoolTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProxmox(): void
    {
        Setting::putSecret('proxmox_token_secret', 'test-secret-123');

        Http::fake([
            '*/cluster/nextid' => Http::response(['data' => '300']),
            // پاسخِ عمومی: هم فهرستِ خالیِ ماشین‌ها (عضوِ غیرآرایه حذف می‌شود)
            // هم وضعیتِ «تمام‌شده»ی تسک — تا createServer بدونِ انتظار جلو برود
            '*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        ]);
    }

    /** 🔴 درخواستِ clone باید pool را حمل کند — پیش‌فرض: customers. */
    public function test_the_clone_request_carries_the_customers_pool(): void
    {
        $this->fakeProxmox();

        app(ProxmoxClient::class)->createServer([
            'name' => 'vps-test1', 'plan_ref' => 'vps-2-2',
            'location_ref' => 'ir', 'image_ref' => '9002',
        ]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/qemu/9002/clone')
            && ($r['pool'] ?? null) === 'customers');
    }

    /** poolِ سفارشی از تنظیمات خوانده می‌شود، نه رشتهٔ سخت‌نویس. */
    public function test_the_pool_setting_overrides_the_default(): void
    {
        $this->fakeProxmox();
        Setting::put('proxmox_pool', 'vps-pool');

        app(ProxmoxClient::class)->createServer([
            'name' => 'vps-test2', 'plan_ref' => 'vps-2-2',
            'location_ref' => 'ir', 'image_ref' => '9002',
        ]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/qemu/9002/clone')
            && ($r['pool'] ?? null) === 'vps-pool');
    }
}
