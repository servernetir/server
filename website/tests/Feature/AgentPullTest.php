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

    public function test_countryroutes_honors_meta_exit_country_override(): void
    {
        // ماشینِ عادی (بی‌مکانِ اکسیت) که از پنل روی nl سوییچ شده
        $this->mkInstance(['location_code' => null, 'ipv4' => '10.10.10.90', 'meta' => ['exit_country' => 'nl']]);
        // ماشینِ exit-de که override روی fi خورده → باید fi برگردد نه de
        $this->mkInstance(['location_code' => 'exit-de', 'ipv4' => '10.10.10.91', 'meta' => ['exit_country' => 'fi']]);
        // ماشینِ exit-de که روی «بدونِ اکسیت» (ir) خاموش شده → نباید در پاسخ باشد
        $this->mkInstance(['location_code' => 'exit-de', 'ipv4' => '10.10.10.92', 'meta' => ['exit_country' => 'ir']]);

        $data = collect(
            $this->getJson('/agent/countryroutes', ['X-Agent-Token' => $this->token])->assertOk()->json()
        )->keyBy('ip');

        $this->assertCount(2, $data);
        $this->assertSame('nl', $data['10.10.10.90']['cc']);   // override روی ماشینِ عادی
        $this->assertSame('fi', $data['10.10.10.91']['cc']);   // override بر location_code مقدم
        $this->assertArrayNotHasKey('10.10.10.92', $data->all()); // ir = خاموش، مسیرِ کشوری نمی‌گیرد
    }

    // ═══════════════════ portforwards ═══════════════════

    /**
     * 🔴 این تست جای دو تستِ قبلی را گرفت که **رفتارِ باگ‌دار را قفل کرده
     * بودند**: هر دو فرض می‌کردند یک GET از عامل باید پورت تخصیص دهد و در
     * `meta` ذخیره کند. یعنی سوئیت، نوشتن در یک مسیرِ خواندنی را «قرارداد»
     * می‌دانست و هر تلاشی برای رفعش را قرمز می‌کرد.
     *
     * قراردادِ تازه: GET هیچ‌چیز نمی‌نویسد.
     */
    public function test_portforwards_never_writes_during_a_get(): void
    {
        $inst = $this->mkInstance(['ipv4' => '10.10.10.71']);

        $payload = $this->getJson('/agent/portforwards', ['X-Agent-Token' => $this->token])
            ->assertOk()->json();

        $this->assertSame([], $payload, 'ماشینِ بی‌پورت نباید در خروجی باشد');
        $this->assertSame(0, $inst->fresh()->publicPort(), 'GET نباید پورت بسازد');
        $this->assertNull($inst->fresh()->meta['public_port'] ?? null);
    }

    public function test_portforwards_returns_allocated_ports_and_is_stable(): void
    {
        Setting::put('public_ip', '203.0.113.9');

        $linux = $this->mkInstance(['image_key' => 'ubuntu-24.04', 'ipv4' => '10.10.10.71']);
        $win   = $this->mkInstance(['image_key' => 'windows-2022', 'ipv4' => '10.10.10.72']);

        $alloc = app(\App\Services\Cloud\PublicPortAllocator::class);
        $p1 = $alloc->allocate($linux);
        $p2 = $alloc->allocate($win);

        $byIp = collect(
            $this->getJson('/agent/portforwards', ['X-Agent-Token' => $this->token])->assertOk()->json()
        )->keyBy('ip');

        $this->assertCount(2, $byIp);

        // پورتِ مقصد: لینوکس SSH، ویندوز RDP
        $this->assertSame(22, $byIp['10.10.10.71']['dest_port']);
        $this->assertSame(3389, $byIp['10.10.10.72']['dest_port']);
        $this->assertSame('203.0.113.9', $byIp['10.10.10.71']['public_ip']);

        $this->assertSame($p1, $byIp['10.10.10.71']['public_port']);
        $this->assertSame($p2, $byIp['10.10.10.72']['public_port']);
        $this->assertNotSame($p1, $p2);

        // بارِ دوم دقیقاً همان پورت‌ها
        $byIp2 = collect(
            $this->getJson('/agent/portforwards', ['X-Agent-Token' => $this->token])->assertOk()->json()
        )->keyBy('ip');

        $this->assertSame($p1, $byIp2['10.10.10.71']['public_port']);
        $this->assertSame($p2, $byIp2['10.10.10.72']['public_port']);
    }

    /**
     * ⚠️ ماشینِ خاموش پورتش را نگه می‌دارد و از خروجی نمی‌افتد. فیلترِ قبلی فقط
     * `building|running` بود، یعنی یک ری‌استارتِ مهمان می‌توانست قاعدهٔ ورودی‌اش
     * را بردارد و بعد پورتِ دیگری بگیرد — آدرسِ اتصالِ مشتری بی‌خبر عوض می‌شد.
     */
    public function test_a_stopped_guest_keeps_its_port_in_the_payload(): void
    {
        $inst = $this->mkInstance(['ipv4' => '10.10.10.73', 'status' => 'off']);
        $port = app(\App\Services\Cloud\PublicPortAllocator::class)->allocate($inst);

        $this->assertNotNull($port);

        $byIp = collect(
            $this->getJson('/agent/portforwards', ['X-Agent-Token' => $this->token])->assertOk()->json()
        )->keyBy('ip');

        $this->assertSame($port, $byIp['10.10.10.73']['public_port'] ?? null);
    }

    // ═══════════════════ کاتالوگِ per-country ═══════════════════

    public function test_fetch_catalog_emits_one_exit_plan_per_configured_country(): void
    {
        Setting::putSecret('proxmox_token_secret', 'x');   // درایور باید «تنظیم‌شده» باشد
        Setting::put('proxmox_exit_countries', 'de,nl');

        $cat = app(ProxmoxClient::class)->fetchCatalog();

        $this->assertTrue($cat['ok']);

        /*
         * ⚠️ فقط خطِ **اکسیت** شمرده می‌شود، نه کلِ کاتالوگ: از شهریور ۱۴۰۵
         * همین متد خطِ VPSِ ایران را هم می‌سازد (CloudProxmoxIranLineTest).
         * شمارشِ کل یعنی این تست با هر خطِ تازه قرمز می‌شود بی‌آنکه چیزی
         * خراب شده باشد.
         */
        $cat['locations'] = array_values(array_filter($cat['locations'],
            fn ($l) => str_starts_with((string) $l['code'], 'exit-')));
        $cat['plans'] = array_values(array_filter($cat['plans'],
            fn ($p) => str_starts_with((string) $p['location_code'], 'exit-')));

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

    // ═══════════════════ هر روتِ agent واقعاً اجرا می‌شود ═══════════════════

    /**
     * 🔴 چرا این تست لازم شد: `exitUpstreams()` از `ExitUpstream::query()`
     * استفاده می‌کرد و فایل **`use App\Models\ExitUpstream;` نداشت**. PHP
     * کلاس را در فضای‌نامِ خودِ کنترلر می‌جست (`…\Agent\ExitUpstream`)، پیدا
     * نمی‌کرد، و آن مسیر در پروداکشن ۵۰۰ می‌داد.
     *
     * هیچ‌کدام از تست‌های قبلی نگرفتندش، چون هیچ‌کدام آن مسیر را **صدا
     * نمی‌زدند**؛ `php -l` هم چنین چیزی را نمی‌بیند (نحو سالم است، خطا در
     * زمانِ اجراست). پس قاعده: **برای هر روتِ ثبت‌شده باید یک فراخوانِ واقعی
     * باشد** — وگرنه importِ جاافتاده تا روزِ دیپلوی پنهان می‌مانَد.
     *
     * ⚠️ ادعا «۵۰۰ نده» است، نه «محتوای درست بدهد» — محتوا تست‌های خودش را
     * دارد. این تست عمداً ارزان و فراگیر است تا هر روتِ تازه‌ای هم بپوشاند.
     */
    public function test_every_agent_route_actually_runs(): void
    {
        $this->mkInstance();

        $checked = 0;
        $passedAuth = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'agent/')) {
                continue;
            }
            // مسیرهای پارامتردار ورودیِ ساختگی می‌خواهند؛ خارج از دامنهٔ این گارد.
            if (str_contains($uri, '{')) {
                continue;
            }

            foreach ($route->methods() as $verb) {
                if (in_array($verb, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                // 🔴 `withHeaders()->call()` هدر را **نمی‌فرستد** — هدرهای
                // پیش‌فرض فقط در get/post/json اعمال می‌شوند، نه در call().
                // نسخهٔ اولِ همین گارد همین اشتباه را داشت: هر هشت روت ۴۰۳
                // می‌گرفتند، کنترلر اصلاً اجرا نمی‌شد، و تست سبز بود در حالی
                // که هیچ‌چیز را نسنجیده بود.
                $res = $this->call($verb, '/'.$uri, [], [], [], $this->transformHeadersToServerVars([
                    'X-Agent-Token' => $this->token,
                ]));

                $this->assertLessThan(
                    500,
                    $res->status(),
                    "روتِ {$verb} /{$uri} با کدِ {$res->status()} ترکید — "
                    .'احتمالاً importِ جاافتاده یا کلاسِ ناموجود.'
                );

                if ($res->status() !== 403) {
                    $passedAuth++;
                }

                $checked++;
            }
        }

        // 🔴 بی‌این، حلقهٔ خالی هم سبز می‌شد و گارد بی‌صدا می‌مُرد.
        $this->assertGreaterThanOrEqual(5, $checked, 'روت‌های agent پیدا نشدند — گارد چیزی نسنجید.');

        // 🔴 و این مهم‌تر است: ۴۰۳ هرگز به کنترلر نمی‌رسد، پس گاردی که همه‌جا
        // ۴۰۳ بگیرد «سبز» است و **هیچ کدی را اجرا نکرده**. این ادعا می‌گوید
        // دست‌کم پنج روت واقعاً از احراز رد شدند و بدنه‌شان دویده.
        $this->assertGreaterThanOrEqual(
            5,
            $passedAuth,
            'هیچ روتی از احراز رد نشد — گارد کنترلرها را اصلاً اجرا نکرده است.'
        );
    }

}
