<?php

namespace Tests\Feature;

use App\Models\ExitUpstream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مدیریتِ «آپ‌استریم‌های اکسیت» از پنل — افزودنِ SSH-VPN و VLESSِ تازه به زیرساختِ
 * اکسیت. قفلِ دسترسی مثلِ بقیه‌ی صفحاتِ مدیرِ ابری، و مهم‌تر: اعتبارنامه هرگز خام
 * روی صفحه نمی‌آید و در ویرایش write-only است.
 */
class ExitUpstreamAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** فرمِ کاملِ یک رله‌ی SSH — قابلِ بازنویسی برای هر تست */
    private function relayPayload(array $over = []): array
    {
        return array_merge([
            'name'     => 'رله‌ی آلمان ۱',
            'role'     => 'relay',
            'type'     => 'ssh',
            'host'     => '91.107.173.226',
            'port'     => 22,
            'username' => 'root',
            'secret'   => "-----BEGIN KEY-----\nRELAYKEYAAA\n-----END KEY-----",
            'priority' => 100,
            'enabled'  => '1',
        ], $over);
    }

    // ═══════════════════ دسترسی ═══════════════════

    public function test_guest_is_redirected(): void
    {
        $this->get('/admin/exit-infra/upstreams')->assertRedirect();
    }

    public function test_author_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'author']), 'web')
            ->get('/admin/exit-infra/upstreams')
            ->assertForbidden();
    }

    public function test_admin_sees_the_empty_state(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->get('/admin/exit-infra/upstreams')
            ->assertOk()
            ->assertSee('هنوز رله‌ای نیست')
            ->assertSee('هنوز اکسیتِ اختصاصی');
    }

    public function test_create_and_edit_pages_render(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'web')->get('/admin/exit-infra/upstreams/create')
            ->assertOk()->assertSee('افزودنِ رله');

        $u = ExitUpstream::create($this->relayPayload());

        $this->actingAs($admin, 'web')->get('/admin/exit-infra/upstreams/'.$u->id.'/edit')
            ->assertOk()->assertSee('ویرایشِ');
    }

    // ═══════════════════ ساخت ═══════════════════

    public function test_admin_can_create_an_ssh_relay(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/upstreams', $this->relayPayload())
            ->assertRedirect(route('admin.exit-upstreams'));

        $u = ExitUpstream::firstWhere('name', 'رله‌ی آلمان ۱');

        $this->assertNotNull($u);
        $this->assertSame('relay', $u->role);
        $this->assertSame('ssh', $u->type);
        $this->assertNull($u->country_code);          // رله کشور ندارد
        $this->assertTrue($u->enabled);
        $this->assertTrue($u->hasSecret());
        $this->assertStringContainsString('RELAYKEYAAA', (string) $u->secret);   // رمزگشایی درست
    }

    public function test_admin_can_create_a_vless_country_exit(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/upstreams', [
                'name'     => 'اکسیتِ آلمانِ اختصاصی',
                'role'     => 'exit',
                'type'     => 'vless',
                'country_code' => 'de',
                'secret'   => 'vless://uuid@1.2.3.4:443?type=tcp&security=reality#DE',
                'sni'      => 'dl.google.com',
                'priority' => 10,
                'enabled'  => '1',
            ])
            ->assertRedirect();

        $u = ExitUpstream::firstWhere('name', 'اکسیتِ آلمانِ اختصاصی');

        $this->assertNotNull($u);
        $this->assertSame('exit', $u->role);
        $this->assertSame('vless', $u->type);
        $this->assertSame('de', $u->country_code);
        $this->assertSame('dl.google.com', $u->sni);
    }

    // ═══════════════════ اعتبارسنجی ═══════════════════

    public function test_country_exit_requires_a_configured_country(): void
    {
        // پیش‌فرضِ proxmox_exit_countries = de,nl,fi → us مجاز نیست
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/upstreams', [
                'name' => 'US', 'role' => 'exit', 'type' => 'vless',
                'country_code' => 'us', 'secret' => 'vless://x@1.2.3.4:443#US',
            ])
            ->assertRedirect();

        $this->assertSame(0, ExitUpstream::count());
    }

    public function test_host_type_requires_host_and_port(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/upstreams',
                $this->relayPayload(['host' => '', 'port' => '']))
            ->assertRedirect();

        $this->assertSame(0, ExitUpstream::count());
    }

    public function test_ssh_requires_a_secret_on_create(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/upstreams',
                $this->relayPayload(['secret' => '']))
            ->assertRedirect();

        $this->assertSame(0, ExitUpstream::count());
    }

    public function test_socks_relay_may_be_created_without_a_secret(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/upstreams',
                $this->relayPayload(['name' => 'socks-open', 'type' => 'socks', 'secret' => '', 'port' => 1080]))
            ->assertRedirect(route('admin.exit-upstreams'));

        $u = ExitUpstream::firstWhere('name', 'socks-open');
        $this->assertNotNull($u);
        $this->assertFalse($u->hasSecret());
    }

    public function test_invalid_type_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/upstreams',
                $this->relayPayload(['type' => 'magic']))
            ->assertRedirect();

        $this->assertSame(0, ExitUpstream::count());
    }

    // ═══════════════════ اعتبارنامه‌ی write-only ═══════════════════

    public function test_secret_is_kept_on_edit_when_left_blank_and_replaced_when_supplied(): void
    {
        $admin = $this->admin();
        $u = ExitUpstream::create($this->relayPayload(['secret' => 'FIRSTKEY']));

        // ویرایش بدونِ secret ⇒ مقدارِ قبلی حفظ می‌شود
        $this->actingAs($admin, 'web')
            ->post('/admin/exit-infra/upstreams/'.$u->id, $this->relayPayload(['secret' => '', 'priority' => 50]))
            ->assertRedirect();

        $u->refresh();
        $this->assertSame('FIRSTKEY', $u->secret);
        $this->assertSame(50, $u->priority);

        // ویرایش با secretِ تازه ⇒ جایگزین می‌شود
        $this->actingAs($admin, 'web')
            ->post('/admin/exit-infra/upstreams/'.$u->id, $this->relayPayload(['secret' => 'SECONDKEY']))
            ->assertRedirect();

        $this->assertSame('SECONDKEY', $u->fresh()->secret);
    }

    // ═══════════════════ toggle / delete ═══════════════════

    public function test_toggle_flips_enabled(): void
    {
        $admin = $this->admin();
        $u = ExitUpstream::create($this->relayPayload(['enabled' => true]));

        $this->actingAs($admin, 'web')
            ->post('/admin/exit-infra/upstreams/'.$u->id.'/toggle')
            ->assertRedirect();

        $this->assertFalse($u->fresh()->enabled);

        $this->actingAs($admin, 'web')
            ->post('/admin/exit-infra/upstreams/'.$u->id.'/toggle')
            ->assertRedirect();

        $this->assertTrue($u->fresh()->enabled);
    }

    public function test_delete_removes_the_row(): void
    {
        $admin = $this->admin();
        $u = ExitUpstream::create($this->relayPayload());

        $this->actingAs($admin, 'web')
            ->post('/admin/exit-infra/upstreams/'.$u->id.'/delete')
            ->assertRedirect();

        $this->assertSame(0, ExitUpstream::count());
    }

    // ═══════════════════ 🔴 نشتِ اعتبارنامه ═══════════════════

    public function test_secret_never_appears_in_any_admin_page(): void
    {
        $admin = $this->admin();
        $marker = 'SUPERSECRETKEYZZZ9182';
        $u = ExitUpstream::create($this->relayPayload(['secret' => $marker]));

        // فهرست
        $this->actingAs($admin, 'web')->get('/admin/exit-infra/upstreams')
            ->assertOk()->assertDontSee($marker);

        // فرمِ ویرایش (نباید مقدار را در textarea برگرداند)
        $this->actingAs($admin, 'web')->get('/admin/exit-infra/upstreams/'.$u->id.'/edit')
            ->assertOk()->assertDontSee($marker);
    }

    public function test_secret_never_leaks_through_model_serialization(): void
    {
        $u = ExitUpstream::create($this->relayPayload(['secret' => 'LEAKTESTKEY']));

        $this->assertArrayNotHasKey('secret', $u->toArray());
        $this->assertStringNotContainsString('LEAKTESTKEY', $u->toJson());
    }
}
