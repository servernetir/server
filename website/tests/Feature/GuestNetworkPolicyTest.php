<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudProvider;
use App\Services\Cloud\PublicPortAllocator;
use App\Support\GuestPolicySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مدیریتِ ماشین‌های میزبانِ ایران از پنل: تخصیصِ پورت (زیرِ قفل و بیرونِ GET)،
 * سیاستِ دسترسی به شبکهٔ داخلی، و تخصیصِ یک ماشینِ یتیم به مشتری.
 *
 * 🔴 سه خرابیِ خاموش که این تست‌ها می‌بندند:
 *
 *   ۱ تخصیصِ پورت داخلِ `GET /agent/portforwards` انجام می‌شد — یک مسیرِ
 *     خواندنی که دیتابیس را عوض می‌کرد و هیچ قفلی نداشت.
 *   ۲ سیاستِ شبکهٔ داخلی اصلاً وجود نداشت: هر مهمانی هر مهمانِ دیگری را می‌دید.
 *   ۳ `CloudAttachController` هر رکوردِ موجود را رد می‌کرد، حتی یتیم — و پیامش
 *     `'سرویسِ شمارهٔ '.null` بود. یعنی تخصیصِ ماشینی که خودِ پنل وارد کرده بود
 *     ممکن نبود و مدیر یک جای خالی می‌دید.
 */
class GuestNetworkPolicyTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'agent-secret-token-xyz';

    private int $svc = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Setting::putSecret('agent_pull_token', $this->token);
    }

    private function mkInstance(array $over = []): CloudInstance
    {
        return CloudInstance::create(array_merge([
            'service_id'    => ++$this->svc,
            'provider'      => 'proxmox',
            'provider_ref'  => (string) (900 + $this->svc),
            'location_code' => 'exit-de',
            'image_key'     => 'ubuntu-24.04',
            'ipv4'          => '10.10.10.'.(60 + $this->svc),
            'status'        => 'running',
        ], $over));
    }

    private function admin(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('x'), 'role' => 'admin']);
    }

    private function alloc(): PublicPortAllocator
    {
        return app(PublicPortAllocator::class);
    }

    // ═══════════════════ تخصیصِ پورت ═══════════════════

    public function test_allocation_is_idempotent(): void
    {
        $inst = $this->mkInstance();

        $first = $this->alloc()->allocate($inst);
        $second = $this->alloc()->allocate($inst->fresh());

        $this->assertNotNull($first);
        $this->assertSame($first, $second, 'تخصیصِ دوباره نباید پورتِ تازه بدهد');
        $this->assertSame($first, $inst->fresh()->publicPort());
    }

    public function test_allocation_takes_the_lowest_free_port(): void
    {
        $this->mkInstance(['meta' => ['public_port' => 20000]]);
        $fresh = $this->mkInstance();

        $this->assertSame(20001, $this->alloc()->allocate($fresh));
    }

    /**
     * ⚠️ پورتِ یک ماشینِ **خاموش** هم رزرو می‌مانَد. اگر آزاد شمرده شود، ماشینِ
     * بعدی همان را می‌گیرد و قاعدهٔ قدیمیِ روی هاست ترافیک را به مقصدِ اشتباه
     * می‌برد — یعنی مشتریِ A به سرورِ مشتریِ B وصل می‌شود.
     */
    public function test_a_stopped_guest_still_reserves_its_port(): void
    {
        $this->mkInstance(['status' => 'off', 'meta' => ['public_port' => 20000]]);

        $this->assertSame(20001, $this->alloc()->allocate($this->mkInstance()));
    }

    public function test_sync_allocates_for_every_machine_without_a_port(): void
    {
        $a = $this->mkInstance();
        $b = $this->mkInstance();
        $c = $this->mkInstance(['meta' => ['public_port' => 20500]]);

        $res = $this->alloc()->syncMissing();

        $this->assertSame(2, $res['allocated']);
        $this->assertSame(0, $res['exhausted']);
        $this->assertGreaterThan(0, $a->fresh()->publicPort());
        $this->assertGreaterThan(0, $b->fresh()->publicPort());
        $this->assertSame(20500, $c->fresh()->publicPort(), 'ماشینِ دارای پورت نباید عوض شود');
    }

    public function test_an_exhausted_range_returns_null_instead_of_a_clashing_port(): void
    {
        config(['servernet.exit.sale_port_min' => 20000, 'servernet.exit.sale_port_max' => 20000]);

        $this->mkInstance(['meta' => ['public_port' => 20000]]);

        $this->assertNull($this->alloc()->allocate($this->mkInstance()));
    }

    // ═══════════════════ /agent/guestpolicy ═══════════════════

    public function test_guestpolicy_requires_the_agent_token(): void
    {
        $this->getJson('/agent/guestpolicy')->assertStatus(403);
        $this->getJson('/agent/guestpolicy', ['X-Agent-Token' => 'wrong'])->assertStatus(403);
    }

    /**
     * 🔴 پیش‌فرضِ نبودِ کلید باید **باز** باشد. اگر «بسته» معنا شود، اولین
     * دیپلوی هر مهمانی را که هرگز از پنل تنظیم نشده از شبکهٔ داخلی می‌بُرد —
     * از جمله NPM و Pritunlِ خودمان.
     */
    public function test_lan_access_defaults_to_open_when_never_set(): void
    {
        $this->mkInstance(['ipv4' => '10.10.10.71']);

        $rows = collect(
            $this->getJson('/agent/guestpolicy', ['X-Agent-Token' => $this->token])->assertOk()->json('policies')
        )->keyBy('ip');

        $this->assertTrue($rows['10.10.10.71']['lan']);
    }

    public function test_guestpolicy_reflects_an_explicit_block(): void
    {
        $this->mkInstance(['ipv4' => '10.10.10.72', 'meta' => ['lan_access' => false]]);

        $rows = collect(
            $this->getJson('/agent/guestpolicy', ['X-Agent-Token' => $this->token])->assertOk()->json('policies')
        )->keyBy('ip');

        $this->assertFalse($rows['10.10.10.72']['lan']);
    }

    public function test_guestpolicy_includes_stopped_guests(): void
    {
        $this->mkInstance(['ipv4' => '10.10.10.73', 'status' => 'off', 'meta' => ['lan_access' => false]]);

        $rows = collect(
            $this->getJson('/agent/guestpolicy', ['X-Agent-Token' => $this->token])->assertOk()->json('policies')
        )->keyBy('ip');

        $this->assertArrayHasKey('10.10.10.73', $rows->all(), 'سیاستِ ماشینِ خاموش نباید ناپدید شود');
    }

    public function test_guestpolicy_records_its_own_heartbeat(): void
    {
        $this->assertNull(Setting::get('agent_seen_guestpolicy'));

        $this->getJson('/agent/guestpolicy', ['X-Agent-Token' => $this->token])->assertOk();

        $this->assertNotNull(Setting::get('agent_seen_guestpolicy'));
    }

    /**
     * 🔴 پاسخ باید نشانهٔ صریحِ خودش را داشته باشد.
     *
     * صفحهٔ خطای Cloudflare و صفحهٔ نگه‌داری با کدِ ۲۰۰ می‌آیند. بی‌این کلید،
     * عاملِ هاست آن HTML را «پاسخِ معتبرِ خالی» می‌خواند و **همهٔ قواعد را پاک
     * می‌کند** — همان درسی که ایجنتِ روترِ مشتری با `SNET|1|` گرفت.
     */
    public function test_the_payload_carries_a_schema_marker(): void
    {
        $this->mkInstance();

        $this->getJson('/agent/guestpolicy', ['X-Agent-Token' => $this->token])
            ->assertOk()
            ->assertJsonPath('schema', 'servernet.guestpolicy.v1');
    }

    /**
     * ⚠️ نسخه باید به **محتوا** بند باشد نه به ترتیبِ ردیف‌های دیتابیس؛ وگرنه
     * هر پیمایش «در انتظار» می‌شد و تأیید هیچ معنایی نداشت.
     */
    public function test_the_revision_is_stable_across_row_order(): void
    {
        $a = $this->mkInstance(['ipv4' => '10.10.10.81']);
        $b = $this->mkInstance(['ipv4' => '10.10.10.80']);

        $first = app(GuestPolicySnapshot::class)->revision();

        // ترتیبِ درج را برعکس می‌کنیم (با به‌روزرسانیِ ستونِ مرتب‌سازیِ طبیعی)
        $a->touch();

        $this->assertSame($first, app(GuestPolicySnapshot::class)->revision());
    }

    public function test_the_revision_changes_when_a_policy_changes(): void
    {
        $inst = $this->mkInstance();
        $before = app(GuestPolicySnapshot::class)->revision();

        $inst->meta = ['lan_access' => false];
        $inst->save();

        $this->assertNotSame($before, app(GuestPolicySnapshot::class)->revision());
    }

    // ═══════════════════ تأییدِ اعمال ═══════════════════

    public function test_ack_requires_the_agent_token(): void
    {
        $this->postJson('/agent/guestpolicy/ack', ['revision' => 'x', 'ok' => true])->assertStatus(403);
    }

    /**
     * 🔴 «ضربان» با «اعمال شد» یکی نیست. تا پیش از این، پنل فقط می‌دانست عامل
     * زنده است و همان را به مدیر مثلِ «انجام شد» نشان می‌داد.
     */
    public function test_a_successful_ack_marks_the_policy_applied(): void
    {
        $this->mkInstance();
        $rev = app(GuestPolicySnapshot::class)->revision();

        $this->assertSame('never', app(GuestPolicySnapshot::class)->status()['state']);

        $this->postJson('/agent/guestpolicy/ack', ['revision' => $rev, 'ok' => true],
            ['X-Agent-Token' => $this->token])->assertOk();

        $this->assertSame('applied', app(GuestPolicySnapshot::class)->status()['state']);
    }

    public function test_a_changed_policy_goes_back_to_pending(): void
    {
        $inst = $this->mkInstance();
        $rev = app(GuestPolicySnapshot::class)->revision();

        $this->postJson('/agent/guestpolicy/ack', ['revision' => $rev, 'ok' => true],
            ['X-Agent-Token' => $this->token])->assertOk();

        $inst->meta = ['lan_access' => false];
        $inst->save();

        $this->assertSame('pending', app(GuestPolicySnapshot::class)->status()['state']);
    }

    /**
     * ⚠️ اعمالِ ناموفق نباید نسخهٔ تأییدشده را جلو ببرد، وگرنه یک شکست در پنل
     * «اعمال شد» دیده می‌شود — بدترین حالتِ ممکن برای یک سوییچِ امنیتی.
     */
    public function test_a_failed_ack_never_marks_it_applied(): void
    {
        $this->mkInstance();
        $rev = app(GuestPolicySnapshot::class)->revision();

        $this->postJson('/agent/guestpolicy/ack',
            ['revision' => $rev, 'ok' => false, 'error' => 'iptables نشد'],
            ['X-Agent-Token' => $this->token])->assertOk();

        $status = app(GuestPolicySnapshot::class)->status();

        $this->assertSame('failed', $status['state']);
        $this->assertSame('iptables نشد', $status['error']);
    }

    // ═══════════════════ سوییچِ پنل ═══════════════════

    public function test_an_admin_can_close_lan_access(): void
    {
        $inst = $this->mkInstance();

        $this->actingAs($this->admin())
            ->post('/admin/exit-infra/'.$inst->id.'/lan', ['lan' => '0'])
            ->assertRedirect();

        $this->assertFalse($inst->fresh()->lanAccess());
        $this->assertTrue($inst->fresh()->lanAccessIsExplicit());
    }

    public function test_a_protected_vmid_never_loses_lan_access_from_the_panel(): void
    {
        $inst = $this->mkInstance(['provider_ref' => '108']);

        $this->actingAs($this->admin())
            ->post('/admin/exit-infra/'.$inst->id.'/lan', ['lan' => '0'])
            ->assertRedirect();

        $this->assertTrue($inst->fresh()->lanAccess(), 'خطِ‌قرمز نباید از شبکهٔ داخلی بیفتد');
    }

    public function test_the_sync_button_allocates_missing_ports(): void
    {
        $inst = $this->mkInstance();

        $this->actingAs($this->admin())
            ->post('/admin/exit-infra/sync-ports')
            ->assertRedirect();

        $this->assertGreaterThan(0, $inst->fresh()->publicPort());
    }

    // ═══════════════════ تخصیصِ ماشینِ یتیم به مشتری ═══════════════════

    private function fakeProvider(): void
    {
        $driver = new class implements CloudProvider
        {
            public function slug(): string { return 'aeza'; }
            public function isConfigured(): bool { return true; }
            public function capabilities(): array { return []; }
            public function testConnection(): array { return ['ok' => true, 'message' => '']; }
            public function fetchCatalog(): array { return ['ok' => true, 'message' => '', 'locations' => [], 'plans' => [], 'images' => []]; }
            public function createServer(array $spec): array { return ['ok' => false, 'message' => 'نباید صدا زده شود']; }
            public function power(string $r, string $a): array { return ['ok' => true, 'message' => '']; }
            public function rebuild(string $r, string $i, ?string $p = null): array { return ['ok' => true, 'message' => '']; }
            public function resetPassword(string $r): array { return ['ok' => true, 'message' => '']; }
            public function console(string $r): array { return ['ok' => true, 'message' => '']; }
            public function metrics(string $r, string $w = '24h'): array { return ['ok' => true, 'message' => '']; }
            public function resize(string $r, string $p, bool $u = true): array { return ['ok' => true, 'message' => '']; }
            public function deleteServer(string $r): array { return ['ok' => true, 'message' => '']; }
            public function uploadSshKey(string $n, string $k): array { return ['ok' => true, 'message' => '']; }
            public function addExtraIps(string $r, int $c): array { return ['ok' => true, 'message' => '']; }
            public function listServers(): array { return ['ok' => true, 'message' => '', 'servers' => []]; }

            public function serverStatus(string $ref): array
            {
                return ['ok' => true, 'message' => '', 'status' => 'running', 'ipv4' => '198.51.100.9', 'ipv6' => null];
            }
        };

        $this->app->instance(CloudManager::class, new class($driver) extends CloudManager
        {
            public function __construct(private $d) {}

            public function driver(string $provider): ?CloudProvider { return $this->d; }

            public function label(?string $p): string { return 'زیرساخت'; }
        });
    }

    private function plan(): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-frankfurt'],
            ['country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'aeza', 'provider_ref' => 'eps-1',
            'provider_location' => 'fra', 'location_code' => 'de-frankfurt',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 400, 'price_eur_cents' => 600, 'price_irt' => 600000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'c'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);
    }

    /**
     * 🔴 ماشینی که صفحهٔ «زیرساختِ اکسیت» وارد کرده (`service_id = null`) باید
     * قابلِ تخصیص باشد — و همان رکورد بازاستفاده شود، نه رکوردِ دوم.
     */
    public function test_an_orphan_machine_can_be_assigned_to_a_customer(): void
    {
        $this->fakeProvider();
        $plan = $this->plan();

        $orphan = CloudInstance::create([
            'service_id'   => null,
            'provider'     => 'aeza',
            'provider_ref' => '999',
            'hostname'     => 'imported-vm',
            'ipv4'         => '10.10.10.99',
            'status'       => 'running',
            'meta'         => ['public_port' => 20777, 'lan_access' => false, 'exit_country' => 'de'],
        ]);

        $this->actingAs($this->admin())->post('/admin/cloud/attach', [
            'customer_id' => $this->customer()->id, 'cloud_plan_id' => $plan->id,
            'provider_ref' => '999', 'name' => 'سرور ایران', 'price' => 900000,
            'cycle' => 'monthly', 'activated_at' => now()->subMonth()->toDateString(),
            'next_due_at' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, CloudInstance::count(), 'رکوردِ دوم نباید ساخته شود');

        $row = $orphan->fresh();

        $this->assertNotNull($row->service_id);
        $this->assertSame(Service::first()->id, $row->service_id);

        // ⚠️ تنظیماتِ شبکه باید دست‌نخورده بمانَد، وگرنه تخصیص به مشتری
        // اتصالِ همان لحظهٔ ماشین را قطع می‌کند.
        $this->assertSame(20777, $row->publicPort());
        $this->assertFalse($row->lanAccess());
        $this->assertSame('de', $row->exitCountryCode());
    }

    public function test_a_machine_owned_by_another_service_is_still_refused(): void
    {
        $this->fakeProvider();
        $plan = $this->plan();

        CloudInstance::create([
            'service_id'   => 4242,
            'provider'     => 'aeza',
            'provider_ref' => '999',
            'status'       => 'running',
        ]);

        $this->actingAs($this->admin())->post('/admin/cloud/attach', [
            'customer_id' => $this->customer()->id, 'cloud_plan_id' => $plan->id,
            'provider_ref' => '999', 'name' => 'سرور', 'price' => 900000,
            'cycle' => 'monthly', 'activated_at' => now()->subMonth()->toDateString(),
            'next_due_at' => now()->addMonth()->toDateString(),
        ])->assertSessionHasErrors();

        $this->assertSame(1, CloudInstance::count());
        $this->assertSame(0, Service::count());
    }
}
