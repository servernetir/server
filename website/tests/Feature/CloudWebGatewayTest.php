<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\NpmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 دروازهٔ وبِ ماشین‌های پشتِ NAT — ساخت **و** برچیدن.
 *
 * ═══ تیکتِ واقعی (۱۱ شهریور ۱۴۰۵، مشتری SN-978603) ═══
 *
 *   «وقتی به پورت ۸۰ سرور وصل می‌شوم صفحهٔ Nginx Proxy Manager باز می‌شود،
 *    در حالی که روی سرور Caddy نصب است و روی پورت ۸۰ گوش می‌دهد.»
 *
 * حق داشت: IPv4 بینِ همهٔ ماشین‌های میزبانِ ایران مشترک است و ۸۰/۴۴۳ آن به
 * پروکسیِ مرکزی می‌رود؛ از هر ماشین فقط SSH فوروارد می‌شد. یعنی VPSی فروخته
 * بودیم که کارِ اصلی‌اش را نمی‌توانست بکند.
 *
 * ⚠️ و نیمهٔ دوم به‌اندازهٔ نیمهٔ اول مهم است: ساختن بدونِ برچیدن یعنی هر
 * سرویسِ خاتمه‌یافته یک پروکسی‌هاستِ زنده جا می‌گذارد که به IP داخلیِ آزادشده
 * اشاره می‌کند — و آن IP بعداً به مشتریِ دیگری می‌رسد. آن‌وقت نامِ مشتریِ قبلی
 * روی سرورِ مشتریِ تازه است: نشتِ داده، نه آشغال.
 */
class CloudWebGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('npm_base_url', 'http://10.10.10.7:81');
        Setting::put('npm_base_domain', 'servernet.cloud');
        Setting::putSecret('npm_email', 'admin@example.com');
        Setting::putSecret('npm_password', 'secret-pass');
        Cache::flush();
    }

    private function npm(): NpmClient
    {
        return app(NpmClient::class);
    }

    // ═══════════ نام‌گذاری ═══════════

    /** نامِ اولِ هر مشتری کدِ خودش است. */
    public function test_the_first_server_takes_the_bare_customer_code(): void
    {
        $this->assertSame('sn-978603.servernet.cloud',
            $this->npm()->hostnameFor('SN-978603', 108, first: true));
    }

    /**
     * 🔴 سرورِ دومِ همان مشتری نباید نامِ اولی را بدزدد.
     *
     * کدِ مشتری یکتا به‌ازای **مشتری** است نه سرویس. بی‌این قید، ماشینِ دوم
     * همان نام را می‌گرفت و در NPM یکی دیگری را از کار می‌انداخت — سایتِ
     * مشتری بی‌هیچ خطایی به سرورِ اشتباه می‌رفت.
     */
    public function test_a_second_server_gets_a_distinct_name(): void
    {
        $this->assertSame('sn-978603-109.servernet.cloud',
            $this->npm()->hostnameFor('SN-978603', 109, first: false));
    }

    /** کدِ بدشکل نامِ میزبانِ نامعتبر نمی‌سازد. */
    public function test_a_messy_code_is_sanitised(): void
    {
        $this->assertSame('sn-9786.servernet.cloud',
            $this->npm()->hostnameFor('SN_9786!', 1, first: true));
    }

    /** دامنهٔ پایهٔ خالی = قابلیت خاموش، نه نامِ حدسی. */
    public function test_without_a_base_domain_there_is_no_hostname(): void
    {
        Setting::put('npm_base_domain', '');

        $this->assertNull($this->npm()->hostnameFor('SN-978603', 108));
    }

    // ═══════════ ساخت ═══════════

    public function test_a_proxy_host_is_created_for_the_internal_ip(): void
    {
        $sent = [];

        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->method().' '.$request->url();

            return match (true) {
                str_contains($request->url(), '/api/tokens')            => Http::response(['token' => 'tk']),
                str_contains($request->url(), '/api/nginx/proxy-hosts') => Http::response(
                    $request->method() === 'GET' ? [] : ['id' => 7]
                ),
                default => Http::response([]),
            };
        });

        $r = $this->npm()->ensureProxyHost('sn-978603.servernet.cloud', '10.10.10.64');

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(7, $r['id']);
        $this->assertContains('POST http://10.10.10.7:81/api/nginx/proxy-hosts', $sent);
    }

    /**
     * ⚠️ idempotent: تلاشِ دوباره نباید میزبانِ دوم بسازد.
     *
     * NPM اجازه می‌دهد دو میزبان با یک نام باشد و آن‌وقت معلوم نیست کدام برنده
     * است — یک خرابیِ مسیریابیِ بی‌صدا.
     */
    public function test_an_existing_host_is_reused_not_duplicated(): void
    {
        $posted = 0;

        Http::fake(function ($request) use (&$posted) {
            if (str_contains($request->url(), '/api/tokens')) {
                return Http::response(['token' => 'tk']);
            }

            if ($request->method() === 'POST') {
                $posted++;
            }

            return Http::response([
                ['id' => 3, 'domain_names' => ['sn-978603.servernet.cloud'], 'certificate_id' => 9],
            ]);
        });

        $r = $this->npm()->ensureProxyHost('sn-978603.servernet.cloud', '10.10.10.64');

        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['id']);
        $this->assertSame(0, $posted, 'میزبانِ تکراری ساخته شد');
    }

    /** بدونِ تنظیمات، ساکت رد می‌شود — نه استثنا. */
    public function test_an_unconfigured_npm_refuses_cleanly(): void
    {
        Setting::putSecret('npm_password', null);

        $r = $this->npm()->ensureProxyHost('x.servernet.cloud', '10.10.10.64');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('تنظیم نشده', $r['message']);
    }

    // ═══════════ برچیدن ═══════════

    /** 🔴 برچیدن، هم میزبان و هم گواهی را می‌برد. */
    public function test_removing_takes_the_host_and_its_certificate(): void
    {
        $deleted = [];

        Http::fake(function ($request) use (&$deleted) {
            if (str_contains($request->url(), '/api/tokens')) {
                return Http::response(['token' => 'tk']);
            }

            if ($request->method() === 'DELETE') {
                $deleted[] = $request->url();

                return Http::response([]);
            }

            return Http::response([
                ['id' => 5, 'domain_names' => ['sn-978603.servernet.cloud'], 'certificate_id' => 11],
            ]);
        });

        $r = $this->npm()->removeProxyHost('sn-978603.servernet.cloud');

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertContains('http://10.10.10.7:81/api/nginx/proxy-hosts/5', $deleted);
        $this->assertContains('http://10.10.10.7:81/api/nginx/certificates/11', $deleted,
            'گواهیِ یتیم در NPM می‌مانَد و سهمیهٔ Let\'s Encrypt را می‌سوزاند');
    }

    /**
     * ⚠️ میزبانی که از قبل نیست = کار انجام شده، نه خطا.
     *
     * کرونِ آزادسازی ممکن است دوباره بدود؛ اگر این «خطا» حساب شود، هر بار یک
     * هشدارِ دروغ می‌سازد و هشدارهای واقعی زیرش گم می‌شوند.
     */
    public function test_removing_a_missing_host_is_success_not_failure(): void
    {
        Http::fake(function ($request) {
            return str_contains($request->url(), '/api/tokens')
                ? Http::response(['token' => 'tk'])
                : Http::response([]);
        });

        $this->assertTrue($this->npm()->removeProxyHost('gone.servernet.cloud')['ok']);
    }

    // ═══════════ DNS ═══════════

    /** رکوردِ A خودکار ساخته می‌شود — و proxied نیست. */
    public function test_the_dns_record_is_created_unproxied(): void
    {
        Setting::putSecret('cloudflare_token', 'cf-token');
        Setting::put('cloudflare_zone_id', 'abc123');
        $sent = null;

        Http::fake(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'dns_records') && $request->method() === 'POST') {
                $sent = $request->data();
            }

            return Http::response(['success' => true, 'result' => []]);
        });

        $r = app(\App\Services\Dns\CloudflareDns::class)
            ->pointSubdomain('sn-978603.servernet.cloud', '85.9.108.118');

        $this->assertTrue($r['ok'], (string) $r['reason']);
        $this->assertSame('A', $sent['type']);
        $this->assertSame('85.9.108.118', $sent['content']);
        $this->assertFalse($sent['proxied'],
            '🔴 proxied یعنی چالشِ HTTP-01 به کلادفلر می‌خورد و گواهی هرگز صادر نمی‌شود');
    }

    /** 🔴 و برداشته هم می‌شود — وگرنه نامِ مشتریِ رفته در زون می‌مانَد. */
    public function test_the_dns_record_is_removed_on_release(): void
    {
        Setting::putSecret('cloudflare_token', 'cf-token');
        Setting::put('cloudflare_zone_id', 'abc123');
        $deleted = [];

        Http::fake(function ($request) use (&$deleted) {
            if ($request->method() === 'DELETE') {
                $deleted[] = $request->url();

                return Http::response(['success' => true, 'result' => []]);
            }

            return Http::response(['success' => true, 'result' => [['id' => 'rec9']]]);
        });

        $r = app(\App\Services\Dns\CloudflareDns::class)->removeSubdomain('sn-978603.servernet.cloud');

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $deleted);
        $this->assertStringContainsString('rec9', $deleted[0]);
    }

    /** رکوردی که نیست = کار انجام شده، نه خطا. */
    public function test_removing_a_missing_dns_record_is_success(): void
    {
        Setting::putSecret('cloudflare_token', 'cf-token');
        Setting::put('cloudflare_zone_id', 'abc123');

        Http::fake(fn () => Http::response(['success' => true, 'result' => []]));

        $this->assertTrue(app(\App\Services\Dns\CloudflareDns::class)
            ->removeSubdomain('gone.servernet.cloud')['ok']);
    }

    // ═══════════ نمایش در پرتال ═══════════

    /** نشانیِ سایت با نشانیِ SSH یکی نیست — مشتری هر دو را لازم دارد. */
    public function test_the_web_url_is_separate_from_the_ssh_address(): void
    {
        Setting::put('public_ip', '85.9.108.118');

        $customer = Customer::create([
            'code' => 'SN-978603', 'email' => 'w'.random_int(1, 999999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);

        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'سرور', 'currency_code' => 'IRT',
            'price' => 550000, 'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done',
        ]);

        $inst = CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'proxmox', 'provider_ref' => '117',
            'location_code' => 'ir-tehran', 'hostname' => 'sn-svc-'.$service->id,
            'ipv4' => '10.10.10.64', 'status' => 'running',
            'meta' => ['public_port' => 20001, 'public_domain' => 'sn-978603.servernet.cloud'],
        ]);

        $this->assertSame('https://sn-978603.servernet.cloud', $inst->webUrl());
        $this->assertSame('85.9.108.118:20001', $inst->address());
        $this->assertSame('ssh root@85.9.108.118 -p 20001', $inst->sshCommand());
    }

    /** سرورِ بی‌دروازه نشانیِ وب ندارد — و چیزی هم وعده نمی‌دهد. */
    public function test_without_a_gateway_there_is_no_web_url(): void
    {
        $inst = new CloudInstance(['meta' => ['public_port' => 20001]]);

        $this->assertNull($inst->webUrl());
    }
}
