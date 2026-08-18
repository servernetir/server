<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\User;
use App\Services\Cloud\ProxmoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مدیریتِ ماشین‌ها در سیستمِ اکسیت از پنل: وارد کردن (اسکنِ Proxmox یا دستی)،
 * تنظیمِ پورت، و حذف از فهرست.
 *
 * 🔴 خطِ‌قرمز: VM 108 (محمدی) هرگز نباید وارد شود یا کشور/پورتش عوض شود — سه
 * لایه (import, setCountry, setPort) جدا تستش می‌کنند.
 */
class ExitInfraVmManageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function manual(array $over = []): array
    {
        return array_merge([
            'hostname' => 'personal-vm109',
            'ipv4'     => '10.10.10.50',
            'os'       => 'ubuntu-24.04',
        ], $over);
    }

    private function proxmoxInstance(array $over = []): CloudInstance
    {
        return CloudInstance::create(array_merge([
            'service_id' => null, 'provider' => 'proxmox', 'provider_ref' => '200',
            'location_code' => null, 'image_key' => 'ubuntu-24.04',
            'ipv4' => '10.10.10.70', 'status' => 'running',
        ], $over));
    }

    // ═══════════════════ دسترسی + رندر ═══════════════════

    public function test_import_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin(), 'web')->get('/admin/exit-infra/import')
            ->assertOk()->assertSee('ثبتِ دستی');
    }

    public function test_import_is_closed_to_non_admins(): void
    {
        $this->get('/admin/exit-infra/import')->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => 'author']), 'web')
            ->get('/admin/exit-infra/import')->assertForbidden();
    }

    // ═══════════════════ وارد کردن ═══════════════════

    public function test_manual_import_creates_an_orphan_instance(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/import', $this->manual(['ref' => '109']))
            ->assertRedirect(route('admin.exit-infra'));

        $inst = CloudInstance::firstWhere('provider_ref', '109');
        $this->assertNotNull($inst);
        $this->assertSame('proxmox', $inst->provider);
        $this->assertNull($inst->service_id);           // یتیم
        $this->assertSame('10.10.10.50', $inst->ipv4);
        $this->assertSame('ubuntu-24.04', $inst->image_key);
    }

    public function test_import_blocks_the_protected_vmid(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/import', $this->manual(['ref' => '108']))
            ->assertRedirect();

        $this->assertSame(0, CloudInstance::count());
    }

    public function test_import_rejects_a_duplicate_vmid(): void
    {
        $this->proxmoxInstance(['provider_ref' => '109']);

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/import', $this->manual(['ref' => '109']))
            ->assertRedirect();

        $this->assertSame(1, CloudInstance::where('provider_ref', '109')->count());
    }

    public function test_import_with_a_valid_country_sets_the_exit_location(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/import', $this->manual(['ref' => '109', 'country' => 'de']))
            ->assertRedirect();

        $inst = CloudInstance::firstWhere('provider_ref', '109');
        $this->assertSame('exit-de', $inst->location_code);
        $this->assertSame('de', $inst->exitCountryCode());
    }

    public function test_import_rejects_a_country_without_a_pool(): void
    {
        // پیش‌فرضِ proxmox_exit_countries = de,nl,fi → us رد
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/import', $this->manual(['ref' => '109', 'country' => 'us']))
            ->assertRedirect();

        $this->assertSame(0, CloudInstance::count());
    }

    public function test_import_with_a_port_stores_it_in_meta(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/import', $this->manual(['ref' => '109', 'port' => 20055]))
            ->assertRedirect();

        $this->assertSame(20055, CloudInstance::firstWhere('provider_ref', '109')->meta['public_port']);
    }

    public function test_import_requires_a_valid_ipv4(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/import', $this->manual(['ipv4' => 'not-an-ip']))
            ->assertRedirect();

        $this->assertSame(0, CloudInstance::count());
    }

    // ═══════════════════ پورت ═══════════════════

    public function test_set_port_writes_meta_and_rejects_a_clash(): void
    {
        $admin = $this->admin();
        $a = $this->proxmoxInstance(['provider_ref' => '201', 'ipv4' => '10.10.10.71']);
        $b = $this->proxmoxInstance(['provider_ref' => '202', 'ipv4' => '10.10.10.72', 'meta' => ['public_port' => 20001]]);

        $this->actingAs($admin, 'web')->post('/admin/exit-infra/'.$a->id.'/port', ['port' => 20000])
            ->assertRedirect();
        $this->assertSame(20000, $a->fresh()->meta['public_port']);

        // پورتِ گرفته‌شده‌ی b روی a رد می‌شود
        $this->actingAs($admin, 'web')->post('/admin/exit-infra/'.$a->id.'/port', ['port' => 20001])
            ->assertRedirect();
        $this->assertSame(20000, $a->fresh()->meta['public_port']);   // عوض نشد
    }

    public function test_set_port_blocked_for_protected_vmid(): void
    {
        $p = $this->proxmoxInstance(['provider_ref' => '108']);

        $this->actingAs($this->admin(), 'web')->post('/admin/exit-infra/'.$p->id.'/port', ['port' => 20009])
            ->assertRedirect();

        $this->assertArrayNotHasKey('public_port', $p->fresh()->meta ?? []);
    }

    // ═══════════════════ خطِ‌قرمز روی سوییچِ کشور ═══════════════════

    public function test_set_country_blocked_for_protected_vmid(): void
    {
        $p = $this->proxmoxInstance(['provider_ref' => '108']);

        $this->actingAs($this->admin(), 'web')->post('/admin/exit-infra/'.$p->id.'/country', ['country' => 'de'])
            ->assertRedirect();

        $this->assertArrayNotHasKey('exit_country', $p->fresh()->meta ?? []);
    }

    // ═══════════════════ حذف از فهرست ═══════════════════

    public function test_detach_removes_an_orphan_row(): void
    {
        $inst = $this->proxmoxInstance(['service_id' => null]);

        $this->actingAs($this->admin(), 'web')->post('/admin/exit-infra/'.$inst->id.'/detach')
            ->assertRedirect();

        $this->assertSame(0, CloudInstance::count());
    }

    public function test_detach_refuses_a_customer_linked_instance(): void
    {
        $inst = $this->proxmoxInstance(['service_id' => 777]);   // به یک سرویس وصل

        $this->actingAs($this->admin(), 'web')->post('/admin/exit-infra/'.$inst->id.'/detach')
            ->assertRedirect();

        $this->assertSame(1, CloudInstance::count());            // پاک نشد
    }

    // ═══════════════════ اسکنِ Proxmox ═══════════════════

    public function test_scan_lists_proxmox_vms_marking_registered_and_protected(): void
    {
        // 116 از قبل ثبت شده
        $this->proxmoxInstance(['provider_ref' => '116', 'ipv4' => '10.10.10.61']);

        $this->mock(ProxmoxClient::class, function ($m) {
            $m->shouldReceive('isConfigured')->andReturn(true);
            $m->shouldReceive('listServers')->andReturn(['ok' => true, 'message' => '', 'servers' => [
                ['ref' => '109', 'name' => 'personal', 'status' => 'running', 'ipv4' => '10.10.10.50'],
                ['ref' => '108', 'name' => 'mohammadi', 'status' => 'off', 'ipv4' => '10.10.10.99'],
                ['ref' => '116', 'name' => 'selmi', 'status' => 'running', 'ipv4' => '10.10.10.61'],
            ]]);
        });

        $this->actingAs($this->admin(), 'web')->get('/admin/exit-infra/import?scan=1')
            ->assertOk()
            ->assertSee('personal')                 // ماشینِ قابلِ ورود
            ->assertSee('خطِ‌قرمز')                  // 108 مسدود
            ->assertSee('از قبل ثبت شده')            // 116 ثبت‌شده
            ->assertSee('imp-109', false);           // فرمِ ورودِ ماشینِ آزاد
    }
}
