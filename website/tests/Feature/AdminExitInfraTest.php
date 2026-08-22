<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ «زیرساختِ اکسیت» — دیدِ اپراتور به Exit VPSها و زنده‌بودنِ pull-agent.
 *
 * قفلِ دسترسی مثلِ بقیهٔ صفحاتِ مدیرِ ابری: مدیر می‌بیند، نویسنده ۴۰۳، مهمان به
 * ورود هدایت می‌شود (`['auth:web', 'admin']`).
 */
class AdminExitInfraTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** یک Exit VPSِ Proxmox که مکانش `exit-de` است */
    private function seedExitVps(): CloudInstance
    {
        return CloudInstance::create([
            'service_id'    => 1,
            'provider'      => 'proxmox',
            'location_code' => 'exit-de',
            'image_key'     => 'ubuntu-24.04',
            'ipv4'          => '10.10.10.60',
            'status'        => 'running',
            'meta'          => ['public_port' => 20001],
        ]);
    }

    public function test_admin_sees_a_seeded_exit_vps_row(): void
    {
        $this->seedExitVps();
        Setting::put('public_ip', '203.0.113.7');

        $this->actingAs($this->admin(), 'web')
            ->get('/admin/exit-infra')
            ->assertOk()
            ->assertSee('آلمان')            // کشورِ خروج از کدِ مکانِ exit-de
            ->assertSee('روشن')             // برچسبِ فارسیِ وضعیتِ running
            ->assertSee('10.10.10.60')      // آی‌پیِ داخلی
            ->assertSee('203.0.113.7:20001'); // دسترسیِ عمومیِ host:port
    }

    public function test_empty_state_is_shown_without_instances(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->get('/admin/exit-infra')
            ->assertOk()
            ->assertSee('هنوز Exit VPSی نیست');
    }

    public function test_an_author_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'author']), 'web')
            ->get('/admin/exit-infra')
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/exit-infra')->assertRedirect();
    }

    // ═══════════════════ سوییچِ کشور (فازِ A) ═══════════════════

    public function test_admin_sees_the_country_switch_dropdown(): void
    {
        $this->seedExitVps();

        $this->actingAs($this->admin(), 'web')
            ->get('/admin/exit-infra')
            ->assertOk()
            ->assertSee('سوییچِ کشور')                    // سرستونِ ستونِ سوییچ
            ->assertSee('بدونِ اکسیت');                    // گزینهٔ خاموش‌کردن در منو
    }

    public function test_admin_can_switch_exit_country(): void
    {
        $vps = $this->seedExitVps();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/'.$vps->id.'/country', ['country' => 'nl'])
            ->assertRedirect();

        $this->assertSame('nl', $vps->fresh()->meta['exit_country']);
        $this->assertSame('nl', $vps->fresh()->exitCountryCode());
    }

    public function test_admin_can_disable_exit_with_ir(): void
    {
        $vps = $this->seedExitVps();

        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/'.$vps->id.'/country', ['country' => 'ir'])
            ->assertRedirect();

        $this->assertSame('ir', $vps->fresh()->meta['exit_country']);
        $this->assertNull($vps->fresh()->exitCountryCode());   // ir → بدونِ اکسیت
    }

    public function test_admin_switch_rejects_country_without_a_pool(): void
    {
        $vps = $this->seedExitVps();

        // proxmox_exit_countries پیش‌فرض de,nl,fi است → us مجاز نیست
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/'.$vps->id.'/country', ['country' => 'us'])
            ->assertRedirect();

        $this->assertArrayNotHasKey('exit_country', $vps->fresh()->meta ?? []);
    }

    public function test_country_switch_requires_admin(): void
    {
        $vps = $this->seedExitVps();

        $this->actingAs(User::factory()->create(['role' => 'author']), 'web')
            ->post('/admin/exit-infra/'.$vps->id.'/country', ['country' => 'nl'])
            ->assertForbidden();

        $this->assertArrayNotHasKey('exit_country', $vps->fresh()->meta ?? []);
    }
}
