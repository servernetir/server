<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\ProxmoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اسکنِ Proxmox باید **کانتینرهای LXC** را هم بیاورد، نه فقط ماشین‌های مجازی.
 *
 * 🔴 خرابی‌ای که این تست‌ها می‌بندند: `listServers()` فقط
 * `/nodes/<node>/qemu` را می‌خواند. کانتینرها در آن مسیر **نیستند** — و نبودشان
 * خطا نمی‌سازد، فقط فهرست کوتاه‌تر می‌شود. یعنی صفحهٔ «وارد کردنِ ماشین به
 * اکسیت» کانتینرها را اصلاً نشان نمی‌داد و اپراتور راهی نداشت اینترنتشان را
 * از پنل عوض کند. همان الگوی «کدِ ۲۰۰ یعنی هیچ».
 *
 * قاعدهٔ پروژه: یک `Http::fake` در هر تست (استابِ `'*'` بعدی‌ها را می‌بلعد).
 */
class ProxmoxContainerScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putSecret('proxmox_token_secret', 'test-secret-uuid');
    }

    /**
     * پاسخِ `/cluster/resources?type=vm` — دو ماشین، دو کانتینر، یک قالب،
     * روی دو نودِ متفاوت.
     */
    private function clusterRows(): array
    {
        return [
            ['type' => 'qemu', 'vmid' => 101, 'name' => 'web-vm',   'status' => 'running', 'node' => 'ir'],
            ['type' => 'lxc',  'vmid' => 201, 'name' => 'proxy-ct', 'status' => 'running', 'node' => 'ir'],
            ['type' => 'lxc',  'vmid' => 202, 'name' => 'dns-ct',   'status' => 'stopped', 'node' => 'ir2'],
            ['type' => 'qemu', 'vmid' => 102, 'name' => 'far-vm',   'status' => 'running', 'node' => 'ir2'],
            ['type' => 'qemu', 'vmid' => 9002, 'name' => 'tpl',     'status' => 'stopped', 'node' => 'ir', 'template' => 1],
        ];
    }

    private function fakeCluster(): void
    {
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            if (str_contains($path, '/cluster/resources')) {
                return Http::response(['data' => $this->clusterRows()], 200);
            }

            // کانفیگِ QEMU آدرس را در ipconfig0 دارد، LXC در net0
            if (str_contains($path, '/qemu/101/config')) {
                return Http::response(['data' => ['ipconfig0' => 'ip=10.10.10.11/24,gw=10.10.10.1']], 200);
            }
            if (str_contains($path, '/qemu/102/config')) {
                return Http::response(['data' => ['ipconfig0' => 'ip=10.10.10.12/24,gw=10.10.10.1']], 200);
            }
            if (str_contains($path, '/lxc/201/config')) {
                return Http::response(['data' => ['net0' => 'name=eth0,bridge=vmbr1,ip=10.10.10.21/24,gw=10.10.10.1']], 200);
            }
            if (str_contains($path, '/lxc/202/config')) {
                return Http::response(['data' => ['net0' => 'name=eth0,bridge=vmbr1,ip=dhcp']], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }

    // ═══════════════ فهرست ═══════════════

    public function test_the_scan_returns_containers_not_just_virtual_machines(): void
    {
        $this->fakeCluster();

        $servers = app(ProxmoxClient::class)->listServers()['servers'];
        $kinds = array_column($servers, 'kind', 'ref');

        $this->assertSame('lxc', $kinds['201'] ?? null, 'کانتینر باید در فهرست باشد');
        $this->assertSame('lxc', $kinds['202'] ?? null);
        $this->assertSame('qemu', $kinds['101'] ?? null);
    }

    public function test_machines_on_other_nodes_are_returned_too(): void
    {
        // نودِ تنظیم‌شده `ir` است؛ ۱۰۲ و ۲۰۲ روی `ir2` اند.
        $this->fakeCluster();

        $refs = array_column(app(ProxmoxClient::class)->listServers()['servers'], 'ref');

        $this->assertContains('102', $refs, 'ماشینِ نودِ دیگر نباید از فهرست بیفتد');
        $this->assertContains('202', $refs);
    }

    public function test_templates_are_never_listed_as_machines(): void
    {
        $this->fakeCluster();

        $refs = array_column(app(ProxmoxClient::class)->listServers()['servers'], 'ref');

        $this->assertNotContains('9002', $refs, 'قالب ماشین نیست');
    }

    public function test_a_container_ip_is_read_from_net0(): void
    {
        $this->fakeCluster();

        $byRef = collect(app(ProxmoxClient::class)->listServers()['servers'])->keyBy('ref');

        $this->assertSame('10.10.10.21', $byRef['201']['ipv4']);
        $this->assertSame('10.10.10.11', $byRef['101']['ipv4'], 'ماشینِ مجازی هنوز از ipconfig0');
        $this->assertNull($byRef['202']['ipv4'], 'ip=dhcp آدرسِ ثابت نیست');
    }

    // ═══════════════ عملیات روی مسیرِ درست ═══════════════

    public function test_power_on_a_container_uses_the_lxc_endpoint(): void
    {
        $seen = [];

        Http::fake(function ($request) use (&$seen) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
            $seen[] = $request->method().' '.$path;

            if (str_contains($path, '/cluster/resources')) {
                return Http::response(['data' => $this->clusterRows()], 200);
            }

            return Http::response(['data' => 'UPID:x'], 200);
        });

        app(ProxmoxClient::class)->power('201', 'on');

        $posts = array_values(array_filter($seen, fn ($l) => str_starts_with($l, 'POST ')));

        $this->assertCount(1, $posts);
        $this->assertStringContainsString('/lxc/201/status/start', $posts[0]);
        $this->assertStringNotContainsString('/qemu/', $posts[0]);
        // و روی نودِ خودش، نه نودِ پیش‌فرض
        $this->assertStringContainsString('/nodes/ir/', $posts[0]);
    }

    public function test_power_on_a_machine_on_another_node_targets_that_node(): void
    {
        $seen = [];

        Http::fake(function ($request) use (&$seen) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
            $seen[] = $request->method().' '.$path;

            if (str_contains($path, '/cluster/resources')) {
                return Http::response(['data' => $this->clusterRows()], 200);
            }

            return Http::response(['data' => 'UPID:x'], 200);
        });

        app(ProxmoxClient::class)->power('102', 'on');

        $posts = array_values(array_filter($seen, fn ($l) => str_starts_with($l, 'POST ')));

        $this->assertStringContainsString('/nodes/ir2/qemu/102/', $posts[0]);
    }

    public function test_deleting_a_container_omits_the_qemu_only_disk_parameter(): void
    {
        $urls = [];

        Http::fake(function ($request) use (&$urls) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            if (str_contains($path, '/cluster/resources')) {
                return Http::response(['data' => $this->clusterRows()], 200);
            }

            if ($request->method() === 'DELETE') {
                $urls[] = $request->url();
            }

            return Http::response(['data' => 'UPID:x'], 200);
        });

        app(ProxmoxClient::class)->deleteServer('201');

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('/lxc/201', $urls[0]);
        $this->assertStringContainsString('purge=1', $urls[0]);
        // ⚠️ این پارامتر فقط مالِ QEMU است و روی LXC خطای «پارامترِ ناشناخته»
        // می‌دهد — یعنی حذف اصلاً انجام نمی‌شود.
        $this->assertStringNotContainsString('destroy-unreferenced-disks', $urls[0]);
    }

    // ═══════════════ عقب‌نشینیِ امن ═══════════════

    public function test_when_cluster_resources_is_forbidden_the_old_single_node_path_still_works(): void
    {
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            if (str_contains($path, '/cluster/resources')) {
                return Http::response(['message' => 'Permission check failed'], 403);
            }
            if (str_ends_with($path, '/nodes/ir/qemu')) {
                return Http::response(['data' => [['vmid' => 101, 'name' => 'web-vm', 'status' => 'running']]], 200);
            }
            if (str_contains($path, '/qemu/101/config')) {
                return Http::response(['data' => ['ipconfig0' => 'ip=10.10.10.11/24']], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $res = app(ProxmoxClient::class)->listServers();

        $this->assertTrue($res['ok'], 'نبودِ دسترسی به cluster نباید اسکن را بخواباند');
        $this->assertSame('101', $res['servers'][0]['ref']);
        $this->assertSame('qemu', $res['servers'][0]['kind']);
        $this->assertSame('10.10.10.11', $res['servers'][0]['ipv4']);
    }

    // ═══════════════ صفحهٔ پنل ═══════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_the_import_page_lists_containers_and_counts_them(): void
    {
        $this->fakeCluster();

        $res = $this->actingAs($this->admin())->get('/admin/exit-infra/import?scan=1');

        $res->assertOk();
        $res->assertSee('proxy-ct');
        $res->assertSee('dns-ct');
        $res->assertSee('کانتینر');
        $res->assertDontSee('tpl');
    }

    public function test_importing_a_container_records_it_as_a_container(): void
    {
        $res = $this->actingAs($this->admin())->post('/admin/exit-infra/import', [
            'ref' => '201', 'hostname' => 'proxy-ct', 'ipv4' => '10.10.10.21',
            'os' => 'debian-12', 'kind' => 'lxc', 'node' => 'ir',
        ]);

        $res->assertRedirect();

        $inst = CloudInstance::where('provider_ref', '201')->firstOrFail();

        $this->assertSame('lxc', $inst->meta['kind']);
        $this->assertSame('ir', $inst->meta['node']);
    }

    public function test_a_manual_import_without_a_kind_defaults_to_a_virtual_machine(): void
    {
        $this->actingAs($this->admin())->post('/admin/exit-infra/import', [
            'ref' => '303', 'hostname' => 'manual-vm', 'ipv4' => '10.10.10.33', 'os' => 'ubuntu-24.04',
        ])->assertRedirect();

        $this->assertSame('qemu', CloudInstance::where('provider_ref', '303')->firstOrFail()->meta['kind']);
    }

    /**
     * 🔴 خطِ‌قرمز (VM108) باید برای کانتینر هم برقرار باشد — گارد روی vmid است،
     * نه روی نوعِ منبع.
     */
    public function test_the_red_line_guard_still_blocks_a_protected_id_even_as_a_container(): void
    {
        $this->actingAs($this->admin())->post('/admin/exit-infra/import', [
            'ref' => '108', 'hostname' => 'nope', 'ipv4' => '10.10.10.8',
            'os' => 'debian-12', 'kind' => 'lxc',
        ])->assertRedirect();

        $this->assertDatabaseMissing('cloud_instances', ['provider_ref' => '108']);
    }
}
