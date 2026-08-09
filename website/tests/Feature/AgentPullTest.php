<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudNaming;
use App\Services\Cloud\ProxmoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مسیرهای token-authِ `/agent/*` که موتورِ هاستِ ایران با آنها «حالتِ مطلوب» را
 * یاد می‌گیرد: مسیرِ کشوریِ خروج و port-forwardهای ورودی. به‌علاوهٔ قراردادِ
 * کاتالوگِ per-country که این محصول را می‌سازد.
 */
class AgentPullTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'agent-secret-token-xyz';

    /** شمارندهٔ service_id تا یکتا بماند (ستون unique است، بی‌FK). */
    private int $svc = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putSecret('agent_pull_token', $this->token);
    }

    /** یک نمونهٔ ذخیره‌شده با پیش‌فرضهای Proxmoxِ زنده؛ قابلِ بازنویسی. */
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

    // ═══════════════════ احراز ═══════════════════

    public function test_endpoints_forbid_missing_or_wrong_token(): void
    {
        $this->getJson('/agent/countryroutes')->assertStatus(403);
        $this->getJson('/agent/portforwards')->assertStatus(403);

        $this->getJson('/agent/countryroutes', ['X-Agent-Token' => 'wrong'])->assertStatus(403);
        $this->getJson('/agent/portforwards', ['X-Agent-Token' => 'wrong'])->assertStatus(403);
    }

    public function test_endpoints_forbid_when_no_token_configured(): void
    {
        Setting::putSecret('agent_pull_token', null);

        // حتی با هدر، وقتی توکنی تنظیم نشده باشد باید ۴۰۳ بدهد (نه عبور).
        $this->getJson('/agent/countryroutes', ['X-Agent-Token' => 'anything'])->assertStatus(403);
        $this->getJson('/agent/portforwards', ['X-Agent-Token' => 'anything'])->assertStatus(403);
    }

    // ═══════════════════ countryroutes ═══════════════════

    public function test_countryroutes_returns_only_proxmox_exit_instances(): void
    {
        $this->mkInstance(['location_code' => 'exit-de', 'ipv4' => '10.10.10.61']);
        $this->mkInstance(['location_code' => 'exit-nl', 'ipv4' => '10.10.10.62', 'status' => 'building']);

        // این‌ها نباید در پاسخ باشند:
        $this->mkInstance(['location_code' => 'de-falkenstein', 'ipv4' => '10.10.10.63']); // مکان exit- نیست
        $this->mkInstance(['provider' => 'hetzner', 'location_code' => 'exit-fi', 'ipv4' => '1.2.3.4']); // proxmox نیست
        $this->mkInstance(['location_code' => 'exit-us', 'ipv4' => null]);                   // بی‌IP
        $this->mkInstance(['location_code' => 'exit-gb', 'ipv4' => '10.10.10.64', 'status' => 'off']); // وضعیتِ نامناسب

        $data = $this->getJson('/agent/countryroutes', ['X-Agent-Token' => $this->token])
            ->assertOk()->json();

        $this->assertCount(2, $data);

        $byCc = collect($data)->keyBy('cc');
        $this->assertSame('10.10.10.61', $byCc['de']['ip']);
        $this->assertSame('10.10.10.62', $byCc['nl']['ip']);
    }

    // ═══════════════════ portforwards ═══════════════════

    public function test_portforwards_allocates_persists_and_is_stable(): void
    {
        Setting::put('public_ip', '203.0.113.9');

        $linux = $this->mkInstance(['image_key' => 'ubuntu-24.04', 'ipv4' => '10.10.10.71']);
        $win   = $this->mkInstance(['image_key' => 'windows-2022', 'ipv4' => '10.10.10.72']);

        $first = $this->getJson('/agent/portforwards', ['X-Agent-Token' => $this->token])
            ->assertOk()->json();

        $this->assertCount(2, $first);

        $byIp = collect($first)->keyBy('ip');

        // پورتِ مقصد: لینوکس SSH، ویندوز RDP
        $this->assertSame(22, $byIp['10.10.10.71']['dest_port']);
        $this->assertSame(3389, $byIp['10.10.10.72']['dest_port']);
        $this->assertSame('203.0.113.9', $byIp['10.10.10.71']['public_ip']);

        $p1 = $byIp['10.10.10.71']['public_port'];
        $p2 = $byIp['10.10.10.72']['public_port'];

        // پورتِ عمومی در محدوده و یکتا
        $this->assertGreaterThanOrEqual(20000, min($p1, $p2));
        $this->assertLessThanOrEqual(20999, max($p1, $p2));
        $this->assertNotSame($p1, $p2);

        // در meta ذخیره شده
        $this->assertSame($p1, $linux->fresh()->meta['public_port']);
        $this->assertSame($p2, $win->fresh()->meta['public_port']);

        // بارِ دوم دقیقاً همان پورت‌ها را می‌دهد (idempotent)
        $second = $this->getJson('/agent/portforwards', ['X-Agent-Token' => $this->token])
            ->assertOk()->json();
        $byIp2 = collect($second)->keyBy('ip');

        $this->assertSame($p1, $byIp2['10.10.10.71']['public_port']);
        $this->assertSame($p2, $byIp2['10.10.10.72']['public_port']);
    }

    public function test_portforwards_picks_the_lowest_free_port(): void
    {
        // نمونه‌ای که از قبل پایین‌ترین پورت را گرفته
        $taken = $this->mkInstance(['ipv4' => '10.10.10.81', 'meta' => ['public_port' => 20000]]);
        $fresh = $this->mkInstance(['ipv4' => '10.10.10.82']);

        $byIp = collect(
            $this->getJson('/agent/portforwards', ['X-Agent-Token' => $this->token])->assertOk()->json()
        )->keyBy('ip');

        $this->assertSame(20000, $byIp['10.10.10.81']['public_port']);
        $this->assertSame(20001, $byIp['10.10.10.82']['public_port'], 'باید پایین‌ترین پورتِ آزاد را بدهد');
    }

    // ═══════════════════ کاتالوگِ per-country ═══════════════════

    public function test_fetch_catalog_emits_one_exit_plan_per_configured_country(): void
    {
        Setting::putSecret('proxmox_token_secret', 'x');   // درایور باید «تنظیم‌شده» باشد
        Setting::put('proxmox_exit_countries', 'de,nl');

        $cat = app(ProxmoxClient::class)->fetchCatalog();

        $this->assertTrue($cat['ok']);
        $this->assertCount(2, $cat['locations']);
        $this->assertCount(2, $cat['plans']);

        $this->assertSame('exit-de', $cat['locations'][0]['code']);
        $this->assertSame('DE', $cat['locations'][0]['country']);
        $this->assertSame('exit-vps-de', $cat['plans'][0]['provider_ref']);
        $this->assertSame('exit-de', $cat['plans'][0]['location_code']);
        $this->assertSame(2048, $cat['plans'][0]['ram_mb']);
        $this->assertSame(400, $cat['plans'][0]['cost_eur_cents']);
    }

    /**
     * عرضه‌ها: هر کشور یک عرضهٔ «Exit VPS» با همان مشخصات/نامِ محصول.
     *
     * ⚠️ نکتهٔ سیم‌کشی: `offers()` بر اساسِ ستونِ `slug` گروه می‌کند و
     * `CloudNaming::planSlug` **مکان** را در اسلاگ می‌آورد، پس هر کشور اسلاگ و
     * عرضهٔ جداگانهٔ خودش می‌شود (یک عرضه به‌ازای هر کشور، نه یک ردیفِ جمع‌شده).
     * تجربهٔ «یک محصول، انتخابِ کشور» از location-pickerِ فروشگاه می‌آید که
     * مکان‌ها را بر اساسِ کشور گروه می‌کند. این تست همان قرارداد را قفل می‌کند.
     */
    public function test_offers_present_the_exit_vps_as_one_product_per_country(): void
    {
        foreach (['de', 'nl', 'fi'] as $cc) {
            CloudPlan::create([
                'provider'          => 'proxmox',
                'provider_ref'      => 'exit-vps-'.$cc,
                'provider_location' => 'ir',
                'location_code'     => 'exit-'.$cc,
                'public_name'       => CloudNaming::planName(2, 2048, 'shared'),
                'slug'              => CloudNaming::planSlug(2, 2048, 30, 'exit-'.$cc, 'shared'),
                'vcpu'              => 2, 'ram_mb' => 2048, 'disk_gb' => 30, 'disk_type' => 'ssd',
                'traffic_gb'        => 1000, 'cpu_kind' => 'shared', 'arch' => 'x86',
                'cost_eur_cents'    => 400, 'price_eur_cents' => 600, 'price_irt' => 6000000,
                'is_active'         => true, 'in_stock' => true,
            ]);
        }

        $exit = CloudPlan::offers()
            ->filter(fn ($o) => str_starts_with((string) $o->location_code, 'exit-'));

        // یک عرضه به‌ازای هر کشور، همه با یک نامِ محصولِ یکسان (همان مشخصات)
        $this->assertCount(3, $exit);
        $this->assertSame(['CV-2-2'], $exit->pluck('public_name')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(
            ['exit-de', 'exit-nl', 'exit-fi'],
            $exit->pluck('location_code')->values()->all()
        );

        // انتخابِ یک کشور دقیقاً یک عرضه می‌دهد
        $this->assertCount(1, CloudPlan::offers('exit-de'));
    }
}
