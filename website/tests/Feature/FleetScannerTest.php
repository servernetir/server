<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\InfraAsset;
use App\Models\Service;
use App\Models\User;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudProvider;
use App\Services\Cloud\FleetScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * دفترِ ناوگان — چیزی که `CloudInventory` نمی‌تواند بگوید: **از کِی** و **چقدر**.
 *
 * سنگین‌ترین ادعاهای این فایل، به ترتیبِ گرانیِ خرابی‌شان:
 *
 *  ۱. زیرساختی که پاسخ نداد، ردیف‌هایش نه پاک می‌شوند نه ناپدید اعلام می‌شوند.
 *     (یک قطعیِ گذرای API نباید کلِ ناوگان را از دفتر بیرون بیندازد.)
 *  ۲. سنِ رهاشدگی بینِ دو اسکن **جلو می‌رود**. اگر هر اسکن تازه‌اش کند، ستونِ
 *     ضرر همیشه صفر می‌مانَد و کلِ ابزار تزئینی می‌شود.
 *  ۳. یادداشت و طبقه‌بندیِ مدیر از اسکن جان سالم به در می‌برد. اسکنی که
 *     یادداشت را پاک کند، بارِ دوم استفاده نمی‌شود.
 */
class FleetScannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    // ═══════════════════ داربست ═══════════════════

    private function driver(string $slug, array $servers, bool $ok = true, string $message = ''): CloudProvider
    {
        return new class($slug, $servers, $ok, $message) implements CloudProvider
        {
            public array $deleted = [];

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
            public function uploadSshKey(string $n, string $k): array { return ['ok' => true, 'message' => '']; }
            public function addExtraIps(string $r, int $c): array { return ['ok' => true, 'message' => '']; }

            public function deleteServer(string $r): array
            {
                $this->deleted[] = $r;

                return ['ok' => true, 'message' => ''];
            }

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
            public function realLabel(?string $p): string { return 'زیرساخت '.$p; }
        });
    }

    private function srv(array $over = []): array
    {
        return array_merge([
            'ref' => '111', 'name' => 'srv', 'status' => 'running',
            'ipv4' => '198.51.100.1', 'ipv6' => null,
            'plan' => 'CX22', 'location' => 'Falkenstein', 'created' => null,
        ], $over);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function makeInstance(string $provider, string $ref, string $status = 'active', ?string $ipv4 = '198.51.100.1'): CloudInstance
    {
        $service = Service::create([
            'customer_id' => $this->customer()->id, 'name' => 'سرور مجازی',
            'currency_code' => 'IRT', 'price' => 1000000, 'tax_percent' => 0,
            'cycle' => 'monthly', 'status' => $status, 'provision_status' => 'done',
            'activated_at' => now(), 'next_due_at' => now()->addMonth(),
        ]);

        return CloudInstance::create([
            'service_id' => $service->id, 'provider' => $provider, 'provider_ref' => $ref,
            'location_code' => 'de-falkenstein', 'hostname' => 'sn-svc-'.$service->id,
            'ipv4' => $ipv4, 'status' => 'running', 'password_seen' => true, 'synced_at' => now(),
        ]);
    }

    private function plan(string $provider, string $ref, int $costCents): CloudPlan
    {
        return CloudPlan::create([
            'provider' => $provider, 'provider_ref' => $ref, 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4', 'slug' => 'cv-2-4-de',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'traffic_gb' => 20000,
            'cost_eur_cents' => $costCents, 'price_eur_cents' => 0, 'price_irt' => 0,
        ]);
    }

    private function scan(?array $providers = null): array
    {
        return app(FleetScanner::class)->scan($providers);
    }

    private function admin(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 'a'.random_int(1, 999999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin']);
    }

    // ═══════════════════ طبقه‌بندی ═══════════════════

    public function test_an_unknown_machine_is_recorded_as_an_orphan(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);

        $this->scan();

        $a = InfraAsset::firstWhere('provider_ref', '777');

        $this->assertNotNull($a);
        $this->assertSame(InfraAsset::STATE_ORPHAN, $a->link_state);
        $this->assertNotNull($a->unlinked_since, 'شروعِ بی‌صاحبی باید همان اسکنِ اول ثبت شود');
    }

    public function test_a_machine_with_a_live_service_is_attached_and_not_leaking(): void
    {
        $ci = $this->makeInstance('hetzner', '777');
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);

        $this->scan();

        $a = InfraAsset::firstWhere('provider_ref', '777');

        $this->assertSame(InfraAsset::STATE_ATTACHED, $a->link_state);
        $this->assertSame($ci->service_id, $a->service_id);
        $this->assertNull($a->unlinked_since);
        $this->assertFalse($a->needsDecision());
    }

    /**
     * 🔴 هستهٔ چیزی که کارفرما خواست.
     *
     * مشتری سرویسش را حذف می‌کند، سمتِ زیرساخت پاک نمی‌شود، و ماشین ماه‌ها
     * اجاره می‌بَرد. چون `provider_ref` به یک ردیفِ `cloud_instances` می‌خورد،
     * هیچ‌کدام از دسته‌های «یتیم» و «شبح» نمی‌گیردش.
     */
    public function test_a_live_machine_behind_a_dead_service_becomes_a_zombie(): void
    {
        $ci = $this->makeInstance('hetzner', '881', 'terminated');
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '881'])])]);

        $this->scan();

        $a = InfraAsset::firstWhere('provider_ref', '881');

        $this->assertSame(InfraAsset::STATE_ZOMBIE, $a->link_state);
        $this->assertSame($ci->service_id, $a->service_id);
        $this->assertTrue($a->needsDecision(), 'باید در فهرستِ «نیازمندِ تصمیم» بیفتد');
    }

    /** ماشینی که ردیفِ سرویسش اصلاً پاک شده هم درآمدی ندارد */
    public function test_a_machine_whose_service_row_vanished_is_a_zombie(): void
    {
        $ci = $this->makeInstance('hetzner', '882');
        Service::whereKey($ci->service_id)->delete();

        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '882'])])]);

        $this->scan();

        $this->assertSame(InfraAsset::STATE_ZOMBIE, InfraAsset::firstWhere('provider_ref', '882')->link_state);
    }

    public function test_a_service_whose_machine_is_gone_becomes_a_ghost(): void
    {
        $this->makeInstance('hetzner', '999');
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [])]);

        $this->scan();

        $a = InfraAsset::firstWhere('provider_ref', '999');

        $this->assertSame(InfraAsset::STATE_GHOST, $a->link_state);
        $this->assertNotNull($a->missing_since);
        // شبح ماشین ندارد، پس نشتیِ پول نیست — نباید در فهرستِ حذف بیفتد
        $this->assertFalse($a->needsDecision());
    }

    // ═══════════════════ ایمنی ═══════════════════

    /**
     * 🔴 گران‌ترین خرابیِ ممکنِ این کلاس.
     *
     * توکنِ منقضی فهرستِ خالی می‌دهد. اگر آن را «همه‌چیز ناپدید شد» بخوانیم،
     * دفتر پاک می‌شود؛ و چون سنِ رهاشدگی از همین دفتر می‌آید، اسکنِ بعدی همه را
     * «تازه‌کشف‌شده» می‌سازد و عددِ ضرر برای همیشه صفر می‌شود.
     */
    public function test_a_failing_provider_never_erases_its_rows(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);
        $this->scan();

        $this->assertSame(1, InfraAsset::count());

        // همان زیرساخت، این بار خطا
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [], false, 'توکن نامعتبر')]);
        $res = $this->scan();

        $this->assertSame(1, InfraAsset::count(), 'ردیف نباید پاک شود');
        $this->assertSame(InfraAsset::STATE_ORPHAN, InfraAsset::first()->link_state,
            'حالتش نباید به «ناپدید» برود');
        $this->assertArrayHasKey('hetzner', $res['errors']);
        $this->assertFalse($res['ok']);
    }

    /** ماشینی که واقعاً حذف شده و سرویسی هم ندارد، از دفتر بیرون می‌رود */
    public function test_a_truly_deleted_orphan_is_dropped_from_the_ledger(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);
        $this->scan();

        $this->withDrivers(['hetzner' => $this->driver('hetzner', [])]);
        $res = $this->scan();

        $this->assertSame(0, InfraAsset::count());
        $this->assertSame(1, $res['removed']);
    }

    /** شناسهٔ یکسان نزدِ دو زیرساخت نباید هم را پوشش دهد */
    public function test_the_same_ref_on_two_providers_stays_two_rows(): void
    {
        $this->makeInstance('hetzner', '555');
        $this->withDrivers([
            'hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '555'])]),
            'aeza'    => $this->driver('aeza', [$this->srv(['ref' => '555'])]),
        ]);

        $this->scan();

        $this->assertSame(2, InfraAsset::count());
        $this->assertSame(InfraAsset::STATE_ATTACHED, InfraAsset::firstWhere('provider', 'hetzner')->link_state);
        $this->assertSame(InfraAsset::STATE_ORPHAN, InfraAsset::firstWhere('provider', 'aeza')->link_state);
    }

    // ═══════════════════ حافظهٔ زمانی ═══════════════════

    /** سنِ رهاشدگی بینِ دو اسکن باید جلو برود، نه اینکه صفر شود */
    public function test_idle_age_survives_a_rescan(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);

        $this->travelTo(now()->subDays(20));
        $this->scan();
        $this->travelBack();

        $this->scan();

        $a = InfraAsset::firstWhere('provider_ref', '777');

        $this->assertSame(20, $a->idleDays());
    }

    /** بازگشت به «متصل» تاریخِ رهاشدگی و تأییدِ کهنه را پاک می‌کند */
    public function test_re_attaching_clears_the_idle_clock(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);
        $this->scan();

        InfraAsset::first()->update(['acknowledged_at' => now()]);

        // حالا همان ماشین به یک سرویسِ زنده وصل می‌شود
        $this->makeInstance('hetzner', '777');
        $this->scan();

        $a = InfraAsset::firstWhere('provider_ref', '777');

        $this->assertSame(InfraAsset::STATE_ATTACHED, $a->link_state);
        $this->assertNull($a->unlinked_since);
        $this->assertNull($a->acknowledged_at, 'تأییدِ کهنه نباید نشتیِ بعدیِ همین ماشین را خاموش کند');
    }

    /** یادداشت و نقشِ مدیر از اسکن جان سالم به در می‌برند */
    public function test_the_scan_never_overwrites_the_admin_note(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);
        $this->scan();

        InfraAsset::first()->update(['role' => 'internal', 'note' => 'سرورِ مانیتورینگِ خودمان']);

        $this->scan();

        $a = InfraAsset::first();

        $this->assertSame('internal', $a->role);
        $this->assertSame('سرورِ مانیتورینگِ خودمان', $a->note);
        $this->assertFalse($a->needsDecision(), 'نقشِ داخلی باید از فهرستِ تصمیم بیرونش ببرد');
    }

    // ═══════════════════ پول ═══════════════════

    public function test_an_orphan_is_priced_from_the_catalog(): void
    {
        $this->plan('hetzner', 'CX22', 599);
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777', 'plan' => 'CX22'])])]);

        $this->scan();

        $a = InfraAsset::first();

        $this->assertSame(599, $a->cost_eur_cents);
        $this->assertSame('plan', $a->cost_source);
    }

    /** بهایِ نامعلوم صفر می‌مانَد — حدس نمی‌زنیم */
    public function test_an_unknown_plan_stays_unpriced(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777', 'plan' => 'ناشناخته'])])]);

        $this->scan();

        $this->assertSame(0, InfraAsset::first()->cost_eur_cents);
        $this->assertSame(0, InfraAsset::first()->wastedEurCents());
    }

    public function test_wasted_money_grows_with_the_idle_days(): void
    {
        $this->plan('hetzner', 'CX22', 600);
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [$this->srv(['ref' => '777'])])]);

        $this->travelTo(now()->subDays(30));
        $this->scan();
        $this->travelBack();

        $this->scan();

        // ۳۰ روز روی ۶ یورو در ماه ≈ ۶ یورو
        $this->assertSame(600, InfraAsset::first()->wastedEurCents());
    }

    // ═══════════════════ صفحه ═══════════════════

    public function test_the_page_finds_a_machine_by_its_ip(): void
    {
        $this->withDrivers(['hetzner' => $this->driver('hetzner', [
            $this->srv(['ref' => '777', 'name' => 'ماشینِ الف', 'ipv4' => '203.0.113.7']),
            $this->srv(['ref' => '778', 'name' => 'ماشینِ ب', 'ipv4' => '198.51.100.9']),
        ])]);
        $this->scan();

        $html = $this->actingAs($this->admin())->get('/admin/fleet?q=203.0.113.7')->assertOk()->getContent();

        $this->assertStringContainsString('ماشینِ الف', $html);
        $this->assertStringNotContainsString('ماشینِ ب', $html);
    }

    public function test_the_page_finds_a_machine_by_the_customer_code(): void
    {
        $ci = $this->makeInstance('hetzner', '777');
        $ci->service->customer->update(['code' => 'SN-4242']);

        $this->withDrivers(['hetzner' => $this->driver('hetzner', [
            $this->srv(['ref' => '777', 'name' => 'ماشینِ مشتری']),
            $this->srv(['ref' => '778', 'name' => 'ماشینِ دیگری']),
        ])]);
        $this->scan();

        $html = $this->actingAs($this->admin())->get('/admin/fleet?q=SN-4242')->assertOk()->getContent();

        $this->assertStringContainsString('ماشینِ مشتری', $html);
        $this->assertStringNotContainsString('ماشینِ دیگری', $html);
    }

    public function test_the_todo_filter_shows_only_undecided_leaks(): void
    {
        $this->makeInstance('hetzner', '700');                  // متصل
        $this->makeInstance('hetzner', '701', 'terminated');    // zombie

        $this->withDrivers(['hetzner' => $this->driver('hetzner', [
            $this->srv(['ref' => '700', 'name' => 'ماشینِ فعال']),
            $this->srv(['ref' => '701', 'name' => 'ماشینِ رهاشده']),
            $this->srv(['ref' => '702', 'name' => 'ماشینِ داخلی']),
        ])]);
        $this->scan();

        InfraAsset::firstWhere('provider_ref', '702')->update(['role' => 'internal']);

        $html = $this->actingAs($this->admin())->get('/admin/fleet?todo=1')->assertOk()->getContent();

        $this->assertStringContainsString('ماشینِ رهاشده', $html);
        $this->assertStringNotContainsString('ماشینِ فعال', $html);
        $this->assertStringNotContainsString('ماشینِ داخلی', $html);
    }

    /**
     * 🔴 پیش‌فرضِ صفحه باید نشتی‌ها را **اول** بگذارد.
     *
     * ساده‌ترین پیاده‌سازی («بر اساسِ تاریخِ رهاشدگی، صعودی») دقیقاً برعکس عمل
     * می‌کند: ماشینِ متصل `NULL` دارد و `NULL` در صعودی اول می‌نشیند. نتیجه‌اش
     * صفحه‌ای بود که با ردیف‌های سالم شروع می‌شد و ماشینِ ۱۰۰روزه‌ای که پول
     * می‌سوزاند می‌افتاد ته فهرست — یعنی ابزار درست بود ولی دیده نمی‌شد.
     */
    public function test_the_default_order_puts_the_money_leaks_first(): void
    {
        $this->makeInstance('hetzner', '700');   // متصل و سالم

        $this->withDrivers(['hetzner' => $this->driver('hetzner', [
            $this->srv(['ref' => '700', 'name' => 'ماشینِ سالم']),
            $this->srv(['ref' => '701', 'name' => 'رهاشدهٔ کهنه']),
        ])]);

        $this->travelTo(now()->subDays(100));
        $this->scan();
        $this->travelBack();
        $this->scan();

        $html = $this->actingAs($this->admin())->get('/admin/fleet')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'ماشینِ سالم'),
            strpos($html, 'رهاشدهٔ کهنه'),
            'ماشینِ رهاشده باید بالاتر از ماشینِ سالم بیاید'
        );
    }

    public function test_guests_cannot_see_the_fleet(): void
    {
        $this->get('/admin/fleet')->assertRedirect();
    }

    /** صفحه روی نصبی که هنوز مهاجرت نخورده باید توضیح بدهد، نه ۵۰۰ بدهد */
    public function test_the_page_survives_a_missing_table(): void
    {
        \Illuminate\Support\Facades\Schema::drop('infra_assets');

        $this->actingAs($this->admin())->get('/admin/fleet')
            ->assertOk()
            ->assertSee('infra_assets', false);
    }

    // ═══════════════════ حذفِ واقعی ═══════════════════

    public function test_deleting_a_machine_requires_its_exact_name(): void
    {
        $driver = $this->driver('hetzner', [$this->srv(['ref' => '777', 'name' => 'sn-loose-1'])]);
        $this->withDrivers(['hetzner' => $driver]);
        $this->scan();

        $id = InfraAsset::first()->id;

        $this->actingAs($this->admin())
            ->post('/admin/fleet/'.$id.'/release', ['confirm' => 'اشتباه'])
            ->assertSessionHasErrors();

        $this->assertSame([], $driver->deleted);
        $this->assertSame(InfraAsset::STATE_ORPHAN, InfraAsset::first()->link_state);
    }

    public function test_deleting_a_machine_with_the_right_name_calls_the_provider(): void
    {
        $driver = $this->driver('hetzner', [$this->srv(['ref' => '777', 'name' => 'sn-loose-1'])]);
        $this->withDrivers(['hetzner' => $driver]);
        $this->scan();

        $id = InfraAsset::first()->id;

        $this->actingAs($this->admin())
            ->post('/admin/fleet/'.$id.'/release', ['confirm' => 'sn-loose-1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['777'], $driver->deleted);

        $a = InfraAsset::first();
        $this->assertSame(InfraAsset::STATE_GHOST, $a->link_state, 'ردیف باید بماند تا ردِ حذف در تاریخچه بماند');
        $this->assertNotNull($a->acknowledged_at);
    }

    /**
     * 🔴 ماشینِ مشتریِ زنده هرگز از این صفحه حذف نمی‌شود.
     *
     * راهِ درستش خاتمهٔ سرویس است، که صورت‌حساب را هم می‌بندد. اگر این‌جا باز
     * بود، یک کلیکِ اشتباه روی جدولی با ده‌ها ردیف، سرورِ مشتریِ پرداخت‌کننده را
     * می‌بُرد و صورت‌حسابش هم باز می‌ماند.
     */
    public function test_an_attached_machine_can_never_be_deleted_from_here(): void
    {
        $this->makeInstance('hetzner', '777');
        $driver = $this->driver('hetzner', [$this->srv(['ref' => '777', 'name' => 'sn-svc-1'])]);
        $this->withDrivers(['hetzner' => $driver]);
        $this->scan();

        $a = InfraAsset::first();

        $this->actingAs($this->admin())
            ->post('/admin/fleet/'.$a->id.'/release', ['confirm' => $a->name])
            ->assertSessionHasErrors();

        $this->assertSame([], $driver->deleted);
    }
}
