<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\ExitUpstream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * مدیریتِ ماشین‌ها و آپ‌استریم‌های اکسیت — وارد کردن، پورت، حذف از فهرست، رله/نودها.
 *
 * ═══ چرا این تست وجود دارد ═══
 *
 * این فیچر ماه‌ها فقط **روی سرور** زندگی می‌کرد و در گیت نبود: بلید و کنترلر
 * دیپلوی شده بودند ولی روت‌هایشان نه. نتیجه‌اش این بود که هر دیپلویِ تازه
 * روت‌ها را پاک می‌کرد و `/admin/exit-infra` با
 * «Route [admin.exit-infra.import] not defined» پانصد می‌داد — دو بار در یک
 * روز (۲۰۲۶-۰۸-۲۱).
 *
 * پس اولین تستِ این کلاس عمداً دربارهٔ **سیم‌کشی** است، نه رفتار: بلید هر روتی
 * را که `route()` می‌کند باید واقعاً ثبت شده باشد. یک بلیدِ سالم با روتِ
 * جاافتاده، صفحه را کامل می‌خواباند و هیچ تستِ رفتاری‌ای آن را نمی‌گیرد چون
 * اصلاً به رندر نمی‌رسد.
 */
class AdminExitManageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function vm(array $overrides = []): CloudInstance
    {
        return CloudInstance::create(array_merge([
            'service_id'    => 1,
            'provider'      => 'proxmox',
            'location_code' => 'exit-de',
            'image_key'     => 'ubuntu-24.04',
            'ipv4'          => '10.10.10.60',
            'status'        => 'running',
            'meta'          => ['public_port' => 20001],
        ], $overrides));
    }

    /**
     * 🔴 هر `route('admin.exit-*')` در بلیدهای اکسیت باید ثبت شده باشد.
     *
     * این ادعا از خودِ فایل‌ها خوانده می‌شود، نه از یک فهرستِ دستی — پس اگر
     * فردا کسی دکمهٔ تازه‌ای با روتی ناموجود اضافه کند، همین‌جا قرمز می‌شود.
     */
    public function test_every_route_the_exit_blades_reference_is_registered(): void
    {
        $blades = glob(resource_path('views/admin/exit-*.blade.php'));

        $this->assertNotEmpty($blades, 'بلیدهای اکسیت پیدا نشدند.');

        $referenced = [];

        foreach ($blades as $file) {
            preg_match_all(
                "/route\(\s*'(admin\.exit-[a-zA-Z0-9._-]+)'/",
                (string) file_get_contents($file),
                $m
            );
            $referenced = array_merge($referenced, $m[1] ?? []);
        }

        $referenced = array_values(array_unique($referenced));

        $this->assertNotEmpty($referenced, 'هیچ route() در بلیدهای اکسیت نبود — الگو را بررسی کن.');

        foreach ($referenced as $name) {
            $this->assertNotNull(
                Route::getRoutes()->getByName($name),
                "روتِ «{$name}» در بلید صدا زده شده ولی ثبت نشده — همان خرابی‌ای که صفحه را ۵۰۰ می‌کند."
            );
        }
    }

    public function test_admin_can_open_the_import_form(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->get('/admin/exit-infra/import')
            ->assertOk()
            ->assertSee('ثبتِ دستیِ یک ماشین');
    }

    /**
     * بازکردنِ فرم نباید به Proxmox تماس بزند؛ اسکن فقط با `?scan=1`.
     * بی‌این، هر بازکردنِ صفحه یک تماسِ API است.
     */
    public function test_opening_the_form_does_not_scan_by_default(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->get('/admin/exit-infra/import')
            ->assertOk()
            ->assertDontSee('هیچ ماشینی روی نود پیدا نشد');
    }

    public function test_manual_import_creates_a_machine_with_country_and_port(): void
    {
        $this->actingAs($this->admin(), 'web')
            ->post('/admin/exit-infra/import', [
                'hostname' => 'sn-exit-test',
                'ref'      => '999',
                'ipv4'     => '10.10.10.99',
                'os'       => 'ubuntu-24.04',
                'country'  => 'de',
                'port'     => 20999,
            ])
            ->assertRedirect();

        $inst = CloudInstance::where('ipv4', '10.10.10.99')->first();

        $this->assertNotNull($inst, 'ماشین ثبت نشد.');
        $this->assertSame('proxmox', $inst->provider);
        $this->assertSame(20999, (int) ($inst->meta['public_port'] ?? 0));
    }

    /**
     * 🔴 «ایران» یعنی ماشین از حالتِ مطلوبِ کشوری **بیرون** می‌رود، نه اینکه با
     * cc خالی بماند. عاملِ ایران هرچه در پاسخ نباشد را از مسیریابی برمی‌دارد.
     */
    public function test_switching_to_iran_removes_the_machine_from_country_routes(): void
    {
        $inst = $this->vm();
        $admin = $this->admin();

        $this->actingAs($admin, 'web')
            ->post("/admin/exit-infra/{$inst->id}/country", ['country' => ''])
            ->assertRedirect();

        $this->assertNull(
            $inst->fresh()->exitCountryCode(),
            'بعد از سوییچ به ایران نباید کدِ کشوری بماند.'
        );
    }

    public function test_admin_can_set_a_public_port(): void
    {
        $inst = $this->vm();

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/exit-infra/{$inst->id}/port", ['port' => 20555])
            ->assertRedirect();

        $this->assertSame(20555, (int) ($inst->fresh()->meta['public_port'] ?? 0));
    }

    public function test_upstreams_page_opens_and_lists_a_relay(): void
    {
        ExitUpstream::create([
            'name'     => 'de-relay-1',
            'role'     => 'relay',
            'type'     => 'ssh',
            'host'     => '198.51.100.10',
            'port'     => 22,
            'username' => 'root',
            'enabled'  => true,
            'priority' => 10,
        ]);

        $this->actingAs($this->admin(), 'web')
            ->get('/admin/exit-infra/upstreams')
            ->assertOk()
            ->assertSee('de-relay-1');
    }

    public function test_a_writer_cannot_reach_exit_management(): void
    {
        $writer = User::factory()->create(['role' => 'writer']);

        $this->actingAs($writer, 'web')
            ->get('/admin/exit-infra/import')
            ->assertForbidden();

        $this->actingAs($writer, 'web')
            ->get('/admin/exit-infra/upstreams')
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/exit-infra/import')->assertRedirect();
        $this->get('/admin/exit-infra/upstreams')->assertRedirect();
    }
}
