<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Cloud\CloudInventory;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تطبیقِ «سرورهایی که نزدِ زیرساخت داریم» با «سرورهایی که ثبت کرده‌ایم».
 *
 * سنگین‌ترین ادعای این تست‌ها این است: **زیرساختی که جواب نداد، هیچ‌چیزش شبح
 * نمی‌شود.** بی‌آن، یک توکنِ منقضی گزارشی می‌سازد که می‌گوید همهٔ سرورهای مشتریان
 * ناپدید شده‌اند — و مدیر بر اساسِ آن سرویسِ سالم را می‌بندد.
 */
class CloudInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    /**
     * درایورِ ساختگی با فهرستِ دلخواه.
     *
     * @param  array<int,array>  $servers
     */
    private function driver(string $slug, array $servers, bool $ok = true, string $message = ''): CloudProvider
    {
        return new class($slug, $servers, $ok, $message) implements CloudProvider
        {
            public function __construct(
                private string $s, private array $list, private bool $ok, private string $msg
            ) {}

            public function slug(): string { return $this->s; }
            public function isConfigured(): bool { return true; }
            public function capabilities(): array { return []; }
            public function testConnection(): array { return ['ok' => true, 'message' => '']; }
            public function fetchCatalog(): array { return ['ok' => true, 'message' => '', 'locations' => [], 'plans' => [], 'images' => []]; }
            public function createServer(array $spec): array { return ['ok' => false, 'message' => 'نباید صدا زده شود']; }
            public function serverStatus(string $r): array { return ['ok' => true, 'message' => '', 'status' => 'running', 'ipv4' => null, 'ipv6' => null, 'traffic_used_gb' => null]; }
            public function power(string $r, string $a): array { return ['ok' => true, 'message' => '']; }
            public function rebuild(string $r, string $i, ?string $p = null): array { return ['ok' => true, 'message' => '']; }
            public function resetPassword(string $r): array { return ['ok' => true, 'message' => '']; }
            public function console(string $r): array { return ['ok' => true, 'message' => '']; }
            public function metrics(string $r, string $w = '24h'): array { return ['ok' => true, 'message' => '']; }
            public function resize(string $r, string $p, bool $u = true): array { return ['ok' => true, 'message' => '']; }
            public function deleteServer(string $r): array { return ['ok' => true, 'message' => '']; }
            public function uploadSshKey(string $n, string $k): array { return ['ok' => true, 'message' => '']; }
            public function addExtraIps(string $r, int $c): array { return ['ok' => true, 'message' => '']; }

            public function listServers(): array
            {
                return ['ok' => $this->ok, 'message' => $this->msg, 'servers' => $this->list];
            }
        };
    }

    /** @param array<string,CloudProvider> $drivers */
    private function withDrivers(array $drivers): void
    {
        $this->app->instance(CloudManager::class, new class($drivers) extends CloudManager
        {
            public function __construct(private array $d) {}
            public function driver(string $provider): ?CloudProvider { return $this->d[$provider] ?? null; }
            public function all(): array { return $this->d; }
            public function configured(): array { return $this->d; }
            public function label(?string $p): string { return 'زیرساخت '.$p; }
        });
    }

    private function srv(array $over = []): array
    {
        return array_merge([
            'ref' => '111', 'name' => 'srv', 'status' => 'running',
            'ipv4' => '198.51.100.1', 'ipv6' => null,
            'plan' => 'CV-2-4', 'location' => 'Falkenstein', 'created' => null,
        ], $over);
    }

    private function makeInstance(string $provider, string $ref, ?string $ipv4 = '198.51.100.1'): CloudInstance
    {
        $customer = Customer::create(['email' => 'c'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);

        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'سرور مجازی',
            'currency_code' => 'IRT', 'price' => 1000000, 'tax_percent' => 0,
            'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done',
            'activated_at' => now(), 'next_due_at' => now()->addMonth(),
        ]);

        return CloudInstance::create([
            'service_id' => $service->id, 'provider' => $provider, 'provider_ref' => $ref,
            'location_code' => 'de-falkenstein', 'hostname' => 'sn-svc-'.$service->id,
            'ipv4' => $ipv4, 'status' => 'running', 'password_seen' => true, 'synced_at' => now(),
        ]);
    }

    private function report(): array
    {
        return app(CloudInventory::class)->reconcile();
    }

    // ═══════════════════ یتیم و شبح ═══════════════════

    public function test_a_server_with_no_service_is_an_orphan(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);

        $r = $this->report();

        $this->assertCount(1, $r['orphans']);
        $this->assertSame('777', $r['orphans'][0]['ref']);
        $this->assertEmpty($r['ghosts']);
    }

    public function test_a_server_that_has_a_service_is_not_an_orphan(): void
    {
        $this->makeInstance('hetzner', '777');
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);

        $r = $this->report();

        $this->assertEmpty($r['orphans']);
        $this->assertEmpty($r['ghosts']);
        $this->assertCount(1, $r['attached']);
    }

    public function test_a_service_whose_server_is_gone_is_a_ghost(): void
    {
        $this->makeInstance('hetzner', '777');
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [])]);

        $r = $this->report();

        $this->assertCount(1, $r['ghosts']);
        $this->assertSame('777', $r['ghosts'][0]['ref']);
    }

    /**
     * 🔴 مهم‌ترین تستِ این فایل.
     *
     * توکنِ منقضی فهرستِ خالی می‌دهد. اگر خطا را نادیده بگیریم، **هر** سرویسِ آن
     * زیرساخت شبح شمرده می‌شود و مدیر سرورِ زندهٔ مشتری را می‌بندد.
     */
    public function test_a_provider_that_errored_produces_no_ghosts(): void
    {
        $this->makeInstance('hetzner', '777');
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [], false, 'توکن نامعتبر')]);

        $r = $this->report();

        $this->assertEmpty($r['ghosts'], 'زیرساختِ خطادار نباید سرویس‌هایش را شبح کند');
        $this->assertSame('توکن نامعتبر', $r['errors']['hetzner']);
    }

    /** فهرستِ ناقص (ok ولی با پیام) هم نباید شبح بسازد */
    public function test_a_partial_listing_produces_no_ghosts(): void
    {
        $this->makeInstance('arvan', 'ir-thr-c2:5');
        $this->withDrivers(['arvan' => $this->driver('arvan', [], true, 'فهرستِ این مناطق خوانده نشد: ir-thr-c1')]);

        $r = $this->report();

        $this->assertEmpty($r['ghosts']);
        $this->assertArrayHasKey('arvan', $r['errors']);
    }

    /** 🔴 شناسهٔ یکسان نزدِ دو زیرساخت نباید هم را پوشش دهد */
    public function test_same_ref_on_two_providers_is_not_confused(): void
    {
        $this->makeInstance('hetzner', '999');
        $this->withDrivers([
            'hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '999'])]),
            'aeza'    => $this->driver('aeza', [$this->srv(['ref' => '999'])]),
        ]);

        $r = $this->report();

        $this->assertCount(1, $r['orphans'], 'سرورِ ۹۹۹ زیرساختِ دوم باید یتیم بماند');
        $this->assertSame('aeza', $r['orphans'][0]['provider']);
        $this->assertEmpty($r['ghosts']);
    }

    public function test_ip_drift_is_flagged(): void
    {
        $this->makeInstance('hetzner', '777', '198.51.100.1');
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777', 'ipv4' => '203.0.113.9'])])]);

        $r = $this->report();

        $this->assertTrue($r['attached'][0]['ip_mismatch']);
    }

    public function test_no_configured_provider_means_nothing_is_judged(): void
    {
        $this->makeInstance('hetzner', '777');
        $this->withDrivers([]);

        $r = $this->report();

        $this->assertEmpty($r['ghosts']);
        $this->assertEmpty($r['orphans']);
        $this->assertEmpty($r['checked']);
    }

    /**
     * 🔴 شناسهٔ نهایی‌نشدهٔ `order:` نباید یک ماشین را هم‌زمان یتیم و شبح کند.
     *
     * زیرساختِ ۲ دومرحله‌ای است: ردیفِ نمونه پیش از تماسِ API ساخته می‌شود و اگر
     * شناسهٔ واقعی در پنجرهٔ کوتاهِ نظرسنجی برنگردد، `order:۸۸۱۲۳` ذخیره می‌شود.
     * آن‌وقت شناسهٔ واقعیِ زیرساخت با هیچ ردیفی نمی‌خورد. نتیجه‌اش دو توصیهٔ
     * ویرانگرِ هم‌زمان دربارهٔ سرورِ زندهٔ یک مشتری بود: «حذفش کن» و «سرویسش را ببند».
     */
    public function test_a_pending_order_ref_is_matched_by_hostname(): void
    {
        $ci = $this->makeInstance('aeza', 'order:88123');
        $this->withDrivers(['aeza' => $this->driver('aeza', [
            $this->srv(['ref' => '55555', 'name' => $ci->hostname]),
        ])]);

        $r = $this->report();

        $this->assertEmpty($r['orphans'], 'سرورِ مشتری نباید یتیم شمرده شود');
        $this->assertEmpty($r['ghosts'], 'سرویسِ همان مشتری نباید شبح شمرده شود');
        $this->assertCount(1, $r['attached']);
    }

    /** شناسهٔ `order:` بی‌تطبیقِ نام هم نباید شبح شود — هنوز نمی‌دانیم کدام است */
    public function test_a_pending_order_ref_is_never_a_ghost(): void
    {
        $this->makeInstance('aeza', 'order:88123');
        $this->withDrivers(['aeza' => $this->driver('aeza', [])]);

        $this->assertEmpty($this->report()['ghosts']);
    }

    /** 🔴 سرویسِ بسته‌شده شبح نیست — نبودِ سرورش دقیقاً همان انتظارِ ماست */
    public function test_a_terminated_service_is_not_a_ghost(): void
    {
        $ci = $this->makeInstance('hetzner', '777');
        $ci->service->update(['status' => 'terminated']);

        $this->withDrivers(['hetzner' => $this->driver('hetzner', [])]);

        $this->assertEmpty($this->report()['ghosts'],
            'هر خاتمهٔ موفق وگرنه برای همیشه یک هشدارِ دروغین می‌گذاشت');
    }

    public function test_a_cancelled_service_is_not_a_ghost(): void
    {
        $ci = $this->makeInstance('hetzner', '778');
        $ci->service->update(['status' => 'cancelled']);

        $this->withDrivers(['hetzner' => $this->driver('hetzner', [])]);

        $this->assertEmpty($this->report()['ghosts']);
    }
    /**
     * 🔴 نیمهٔ گم‌شدهٔ دو تستِ بالا.
     *
     * «سرویسِ بسته‌شده شبح نیست» درست است — ولی فقط وقتی سرورش **واقعاً رفته
     * باشد**. اگر ماشین هنوز نزدِ زیرساخت باشد، همان شرط آن را از تنها سطلِ
     * هشدار بیرون می‌انداخت و در «وصل و سالم» می‌نشاند: اجاره می‌رود، هیچ
     * مشتری‌ای پشتش نیست، و صفحه سبز است.
     *
     * برعکسِ شبح است و از آن گران‌تر — چون شبح فقط دکمهٔ خراب می‌سازد، این
     * هر ماه پول می‌بَرد.
     */
    public function test_a_live_machine_behind_a_dead_service_is_not_called_healthy(): void
    {
        $dead = $this->makeInstance('hetzner', '881');
        $dead->service->update(['status' => 'cancelled']);

        $live = $this->makeInstance('hetzner', '882');
        $live->service->update(['status' => 'active']);

        $this->withDrivers(['hetzner' => $this->driver('hetzner', [
            $this->srv(['ref' => '881']),
            $this->srv(['ref' => '882']),
        ])]);

        $report = $this->report();

        $this->assertEmpty($report['ghosts'], 'هیچ‌کدام شبح نیستند — هر دو ماشین هستند.');

        $flagged = array_values(array_filter($report['attached'], fn ($a) => $a['service_dead'] ?? false));

        $this->assertCount(1, $flagged, 'ماشینِ زندهٔ سرویسِ بسته‌شده علامت نخورد.');
        $this->assertSame($dead->service_id, $flagged[0]['service_id']);

        $healthy = array_values(array_filter($report['attached'], fn ($a) => ! ($a['service_dead'] ?? false)));

        $this->assertCount(1, $healthy, 'سرویسِ زنده اشتباهاً مرده علامت خورد.');
        $this->assertSame($live->service_id, $healthy[0]['service_id']);
    }

    // ═══════════════════ صفحه‌ها ═══════════════════

    private function admin(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin']);
    }

    public function test_inventory_page_lists_orphans_and_ghosts(): void
    {
        $this->makeInstance('hetzner', 'gone-1');
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => 'loose-9', 'name' => 'یتیمِ من'])])]);

        $html = $this->actingAs($this->admin())->get('/admin/cloud/inventory')->assertOk()->getContent();

        $this->assertStringContainsString('یتیمِ من', $html);
        $this->assertStringContainsString('loose-9', $html);
        $this->assertStringContainsString('gone-1', $html);
    }

    /** خطای زیرساخت باید در صفحه دیده شود، نه اینکه فهرستِ خالی آرامش بدهد */
    public function test_inventory_page_shows_the_provider_error(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [], false, 'توکن منقضی شده')]);

        $html = $this->actingAs($this->admin())->get('/admin/cloud/inventory')->assertOk()->getContent();

        $this->assertStringContainsString('توکن منقضی شده', $html);
    }

    /** اسکن فقط با ?scan=1 اجرا می‌شود؛ بازکردنِ ساده نباید زیرساخت را صدا بزند */
    public function test_attach_form_only_scans_on_demand(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => 'aaa-1', 'name' => 'کشف‌شده'])])]);

        $plain = $this->actingAs($this->admin())->get('/admin/cloud/attach')->assertOk()->getContent();
        $this->assertStringNotContainsString('کشف‌شده', $plain);
        $this->assertStringContainsString('سرورهای وصل‌نشده را پیدا کن', $plain);

        $scanned = $this->actingAs($this->admin())->get('/admin/cloud/attach?scan=1')->assertOk()->getContent();
        $this->assertStringContainsString('کشف‌شده', $scanned);
        $this->assertStringContainsString('aaa-1', $scanned);
    }

    /** انتخابِ یک سرورِ کشف‌شده باید فرم را پر کند */
    public function test_selecting_a_discovered_server_prefills_the_form(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [])]);

        $html = $this->actingAs($this->admin())
            ->get('/admin/cloud/attach?ref=abc-42&sname='.urlencode('سرور امیر'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="abc-42"', $html);
        $this->assertStringContainsString('value="سرور امیر"', $html);
    }

    public function test_guests_cannot_see_the_inventory(): void
    {
        $this->get('/admin/cloud/inventory')->assertRedirect();
    }
}
