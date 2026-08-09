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
}
