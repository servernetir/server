<?php

namespace Tests\Feature;

use App\Mail\ServiceReadyMail;
use App\Models\Customer;
use App\Models\Server;
use App\Models\Service;
use App\Services\Dns\DnsLookup;
use App\Services\Dns\HostingDnsStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * «نیم‌سرورم را چه بزنم؟» — پرتکرارترین تیکتِ بعد از تحویلِ هاست.
 *
 * تا پیش از این کار، این جواب **هیچ‌جا** به مشتری داده نمی‌شد: نه در پنل، نه در
 * ایمیلِ تحویل. این تست‌ها هم خودِ مقدار را می‌سنجند و هم اینکه واقعاً به چشمِ
 * مشتری می‌رسد.
 *
 * ⚠️ چرا صفحهٔ واقعی رندر می‌شود و نه فقط متدِ مدل: درسِ ثبت‌شدهٔ این پروژه
 * (`fixture-from-the-source-you-fixed`) — سنجیدنِ همان چیزی که تازه ساخته‌ای
 * فقط خودِ ساخت را می‌سنجد. اگر بلوکِ Blade پشتِ یک `@if` اشتباه بیفتد، متدها
 * سبز می‌مانند و مشتری همچنان چیزی نمی‌بیند.
 */
class HostingNameserversTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();      // `publicIp()` و وضعیتِ DNS هر دو کش دارند
    }

    private function server(array $over = []): Server
    {
        return Server::create(array_merge([
            'name' => 'core', 'type' => 'whm', 'hostname' => 'core.example.test',
            'port' => 2087, 'username' => 'root', 'api_token' => 'T',
            'verify_tls' => false, 'status' => 'active',
            'country' => 'IR', 'server_ip' => '85.9.108.116',
        ], $over));
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'x', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function service(Server $s, Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'server_id' => $s->id, 'name' => 'هاست لینوکس',
            'currency_code' => 'IRT', 'price' => 250000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
            'domain' => 'client-site.com', 'username' => 'clientsi', 'plan' => 'WP-5',
        ], $over));
    }

    /** یک resolverِ ساختگی — هیچ تستی نباید به DNSِ واقعی وابسته باشد */
    private function fakeDns(array $ns, ?string $ip = null): void
    {
        $this->app->instance(DnsLookup::class, new class($ns, $ip) extends DnsLookup
        {
            public function __construct(private array $ns, private ?string $a) {}

            public function nameservers(string $domain): array
            {
                return $this->ns;
            }

            public function ip(string $domain): ?string
            {
                return $this->a;
            }
        });
    }

    // ─────────────────────────── خودِ مقدار ───────────────────────────

    public function test_iran_server_gets_the_ir_nameservers_and_everything_else_gets_cloud(): void
    {
        $this->assertSame(
            ['ns1.servernet.ir', 'ns2.servernet.ir'],
            $this->server(['country' => 'IR'])->nameserverList()
        );

        $this->assertSame(
            ['ns1.servernet.cloud', 'ns2.servernet.cloud'],
            $this->server(['country' => 'DE', 'hostname' => 'de.example.test'])->nameserverList()
        );

        // کشورِ ناشناخته هم باید جوابِ کارآمد بدهد، نه آرایهٔ خالی
        $this->assertSame(
            ['ns1.servernet.cloud', 'ns2.servernet.cloud'],
            $this->server(['country' => 'NL', 'hostname' => 'nl.example.test'])->nameserverList()
        );
    }

    public function test_an_admin_entered_value_wins_over_the_country_default(): void
    {
        $s = $this->server(['country' => 'IR', 'nameservers' => 'NS1.Custom.Net, ns2.custom.net']);

        // کوچک‌حرف و بی‌فاصله — همان چیزی که با DNS مقایسه می‌شود
        $this->assertSame(['ns1.custom.net', 'ns2.custom.net'], $s->nameserverList());
    }

    /**
     * ⚠️ تقریباً همهٔ رجیستری‌ها کمتر از دو نیم‌سرور را رد می‌کنند. یک پیش‌فرضِ
     * تک‌عضوی یعنی مشتری‌ای که مقدارِ ما را وارد می‌کند و رجیسترار قبولش نمی‌کند.
     */
    public function test_every_configured_default_has_at_least_two_usable_nameservers(): void
    {
        foreach ((array) config('provisioning.nameservers') as $key => $list) {
            $this->assertGreaterThanOrEqual(2, count((array) $list),
                "پیش‌فرضِ «{$key}» کمتر از دو نیم‌سرور دارد؛ رجیسترار ردش می‌کند.");

            foreach ((array) $list as $ns) {
                $this->assertMatchesRegularExpression('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $ns,
                    "«{$ns}» شکلِ یک نامِ میزبان را ندارد.");
            }
        }
    }

    // ─────────────────────────── وضعیتِ DNS ───────────────────────────

    public function test_matching_nameservers_read_as_connected(): void
    {
        $this->fakeDns(['ns1.servernet.ir', 'ns2.servernet.ir']);

        $r = app(HostingDnsStatus::class)->check($this->service($this->server(), $this->customer()));

        $this->assertSame('ok', $r['state']);
        $this->assertContains($r['state'], HostingDnsStatus::HEALTHY);
    }

    /**
     * 🔴 مهم‌ترین تستِ این فایل.
     *
     * مشتری‌ای که دامنه‌اش روی Cloudflare است و از آن‌جا به IPِ ما اشاره می‌دهد،
     * سایتش کاملاً بالاست. سنجیدنِ صرفِ نیم‌سرور به او برچسبِ قرمز می‌داد و
     * دقیقاً همان تیکتی را می‌ساخت که این قابلیت برای حذفش نوشته شده — این بار
     * با اضطراب.
     */
    public function test_external_dns_that_still_points_at_us_is_green_not_red(): void
    {
        $this->fakeDns(['derek.ns.cloudflare.com', 'linda.ns.cloudflare.com'], '85.9.108.116');

        $r = app(HostingDnsStatus::class)->check($this->service($this->server(), $this->customer()));

        $this->assertSame('ok_external', $r['state']);
        $this->assertContains($r['state'], HostingDnsStatus::HEALTHY);
    }

    public function test_external_dns_pointing_somewhere_else_is_a_mismatch(): void
    {
        $this->fakeDns(['ns1.other-host.com', 'ns2.other-host.com'], '203.0.113.9');

        $r = app(HostingDnsStatus::class)->check($this->service($this->server(), $this->customer()));

        $this->assertSame('mismatch', $r['state']);
        $this->assertNotContains($r['state'], HostingDnsStatus::HEALTHY);
    }

    /**
     * 🔴 دامنهٔ پشتِ کلادفلر نباید قرمز شود.
     *
     * روی سرورِ ایران دو دامنهٔ واقعی همین حالا این‌طورند
     * (`namakaran-alu.com` → 172.67.178.94 و `onlinemarket24.ir` → 104.21.7.189،
     * هر دو IPِ کلادفلر). رکوردِ A هرگز IPِ ما را برنمی‌گرداند حتی اگر مبدأ
     * دقیقاً ما باشیم — پس DNS به‌تنهایی نمی‌تواند قضاوت کند و «نمی‌دانم» تنها
     * جوابِ صادقانه است.
     *
     * پیش از این اصلاحیه، هر دو `mismatch` می‌گرفتند: چراغِ قرمز روی سایتی که
     * ممکن است کاملاً سالم باشد.
     */
    public function test_a_domain_behind_cloudflare_is_never_reported_as_broken(): void
    {
        $this->fakeDns(['derek.ns.cloudflare.com', 'linda.ns.cloudflare.com'], '172.67.178.94');

        $r = app(HostingDnsStatus::class)->check($this->service($this->server(), $this->customer()));

        $this->assertSame('proxied', $r['state']);
        $this->assertNotSame('mismatch', $r['state']);
    }

    /** پسوند سنجیده می‌شود، پس زیردامنهٔ ارائه‌دهنده هم شناخته می‌شود */
    public function test_the_proxy_list_matches_on_suffix_not_exact_name(): void
    {
        foreach ((array) config('provisioning.dns_proxies') as $p) {
            $this->assertStringNotContainsString(' ', (string) $p, 'نامِ ارائه‌دهنده نباید فاصله داشته باشد.');
        }

        $this->fakeDns(['ns1.arvancloud.ir'], '185.143.233.238');

        $r = app(HostingDnsStatus::class)->check($this->service($this->server(), $this->customer()));

        $this->assertSame('proxied', $r['state']);
    }

    public function test_half_migrated_nameservers_are_flagged_as_partial(): void
    {
        $this->fakeDns(['ns1.servernet.ir', 'ns2.other-host.com']);

        $r = app(HostingDnsStatus::class)->check($this->service($this->server(), $this->customer()));

        $this->assertSame('partial', $r['state']);
    }

    /**
     * ⚠️ «نتوانستم بپرسم» هرگز نباید «خراب است» شود.
     *
     * روی همین ماشینِ توسعه `gethostbyname('igniran.ir')` شکست می‌خورد در حالی
     * که دامنه سالم است. اگر شکستِ resolver به قرمز ترجمه می‌شد، هر مشتری‌ای
     * که در لحظهٔ بدی صفحه را باز کند فکر می‌کرد سایتش خوابیده.
     */
    public function test_a_resolver_that_answers_nothing_is_pending_not_broken(): void
    {
        $this->fakeDns([], null);

        $r = app(HostingDnsStatus::class)->check($this->service($this->server(), $this->customer()));

        $this->assertSame('pending', $r['state']);
        $this->assertNotSame('mismatch', $r['state']);
    }

    /** زیردامنهٔ رایگانِ خودمان: مشتری هیچ نیم‌سروری برای عوض‌کردن ندارد */
    public function test_our_own_free_subdomain_never_asks_the_customer_to_change_anything(): void
    {
        $this->fakeDns(['derek.ns.cloudflare.com']);   // عمداً «غیرِ ما»

        $zone = (string) config('servernet.subdomain_zone', 'servernet.cloud');
        $svc  = $this->service($this->server(), $this->customer(), ['domain' => 'ali.'.$zone]);

        $r = app(HostingDnsStatus::class)->check($svc);

        $this->assertSame('managed', $r['state']);
        $this->assertContains($r['state'], HostingDnsStatus::HEALTHY);
    }

    // ─────────────────────────── مسیرِ JSON ───────────────────────────

    public function test_the_status_endpoint_answers_for_the_owner(): void
    {
        $this->fakeDns(['ns1.servernet.ir', 'ns2.servernet.ir']);

        $c   = $this->customer();
        $svc = $this->service($this->server(), $c);

        $this->actingAs($c, 'customer')
            ->getJson('/account/services/'.$svc->id.'/dns')
            ->assertOk()
            ->assertJson([
                'state'   => 'ok',
                'healthy' => true,
                'ns'      => ['ns1.servernet.ir', 'ns2.servernet.ir'],
            ]);
    }

    public function test_the_status_endpoint_is_not_readable_by_another_customer(): void
    {
        $svc = $this->service($this->server(), $this->customer());

        $this->actingAs($this->customer(), 'customer')
            ->getJson('/account/services/'.$svc->id.'/dns')
            ->assertNotFound();
    }

    /**
     * ⚠️ حتی وقتی وضعیت را نمی‌دانیم، «چه بزنم» باید برگردد.
     *
     * وگرنه دقیقاً در بدترین لحظه (سرویسِ تازه، DNSِ منتشرنشده) پاسخ خالی است و
     * همان تیکت ساخته می‌شود.
     */
    public function test_the_answer_carries_the_nameservers_even_when_the_state_is_unknown(): void
    {
        $c   = $this->customer();
        $svc = $this->service($this->server(), $c, ['provision_status' => 'pending', 'domain' => null]);

        $this->actingAs($c, 'customer')
            ->getJson('/account/services/'.$svc->id.'/dns')
            ->assertOk()
            ->assertJson(['state' => 'unknown', 'ns' => ['ns1.servernet.ir', 'ns2.servernet.ir']]);
    }

    // ─────────────────── واقعاً به چشمِ مشتری می‌رسد؟ ───────────────────

    public function test_the_hosting_page_actually_prints_the_nameservers_and_the_ip(): void
    {
        $c = $this->customer();
        $this->service($this->server(), $c);

        $html = $this->actingAs($c, 'customer')->get('/account/hosting')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ns1.servernet.ir', $html);
        $this->assertStringContainsString('ns2.servernet.ir', $html);
        $this->assertStringContainsString('85.9.108.116', $html);

        // چراغِ وضعیت و لینکِ بررسی هم باید باشند، وگرنه نیمی از کار نامرئی است
        $this->assertStringContainsString('dns-pill', $html);
        $this->assertStringContainsString('data-dns=', $html);
        $this->assertStringContainsString('domain='.urlencode('client-site.com'), $html);

        // و هیچ کلیدِ خامی نشت نکند
        $this->assertStringNotContainsString('ui.dns_', $html);
    }

    public function test_the_delivery_email_carries_the_nameservers(): void
    {
        $server = $this->server();

        $mail = new ServiceReadyMail(
            'هاست لینوکس', 'client-site.com', 'https://core.example.test:2083', 'clientsi', 'pw', 'fa',
            false, false, $server->nameserverList(), $server->publicIp(),
        );

        $rendered = $mail->render();

        $this->assertStringContainsString('ns1.servernet.ir', $rendered);
        $this->assertStringContainsString('ns2.servernet.ir', $rendered);
        $this->assertStringContainsString('85.9.108.116', $rendered);
    }

    public function test_provisioning_sends_an_email_that_contains_them(): void
    {
        Http::fake([
            '*/json-api/accountsummary*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'account does not exist']]),
            '*/json-api/createacct*'     => Http::response(['metadata' => ['result' => 1, 'reason' => 'ok'], 'data' => ['ip' => '85.9.108.116']]),
            '*'                          => Http::response(['metadata' => ['result' => 1, 'reason' => 'ok']]),
        ]);

        $c   = $this->customer();
        $svc = $this->service($this->server(), $c, [
            'status' => 'awaiting_provision', 'provision_status' => 'pending', 'username' => null,
        ]);

        app(\App\Services\Provisioning\ProvisioningService::class)->provision($svc);

        Mail::assertSent(ServiceReadyMail::class, function (ServiceReadyMail $m) {
            return $m->nameservers === ['ns1.servernet.ir', 'ns2.servernet.ir']
                && $m->serverIp === '85.9.108.116';
        });
    }

    // ─────────────────── لینکِ یک‌کلیکیِ ابزارِ whois ───────────────────

    public function test_the_whois_tool_prefills_and_auto_runs_for_a_valid_domain(): void
    {
        $html = $this->get('/tools/whois?domain=client-site.com')->assertOk()->getContent();

        $this->assertStringContainsString('value="client-site.com"', $html);
        $this->assertStringContainsString('data-autorun="1"', $html);
    }

    /**
     * ⚠️ مقدار مستقیم در `value=""` می‌نشیند و از کوئریِ عمومی می‌آید.
     */
    public function test_the_whois_tool_refuses_a_junk_domain_parameter(): void
    {
        $html = $this->get('/tools/whois?domain='.urlencode('"><script>alert(1)</script>'))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('data-autorun', $html);
    }
}
