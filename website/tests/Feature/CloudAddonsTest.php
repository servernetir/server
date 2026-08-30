<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudSshKey;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudAddons;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Support\Facades\Http;

/**
 * افزودنی‌های سرورِ ابری: کلیدِ SSH (رایگان) و IP اضافه (پولی).
 *
 * تمرکز روی سه چیزی که خرابی‌شان گران است:
 *  ۱) قیمت هرگز از ورودیِ کاربر نیاید و هرگز زیرِ بها نرود.
 *  ۲) چیزی فروخته نشود که زیرساخت نمی‌تواند تحویل دهد.
 *  ۳) کلیدِ **خصوصی** هرگز ذخیره نشود.
 */
class CloudAddonsTest extends CloudProvisionTest
{
    private function addons(): CloudAddons
    {
        return app(CloudAddons::class);
    }

    // ═══════════════ پاک‌سازیِ ورودی ═══════════════

    /**
     * 🔴 عددِ منفی می‌توانست مبلغِ کل را **کم** کند — یعنی مشتری با انتخابِ
     * «−۳ عدد IP» تخفیف می‌گرفت.
     */
    public function test_addon_quantity_is_clamped(): void
    {
        $a = $this->addons();

        $this->assertSame(0, $a->sanitize(['extra_ipv4' => -3])['extra_ipv4']);
        $this->assertSame(0, $a->sanitize(['extra_ipv4' => 'ابc'])['extra_ipv4']);
        $this->assertSame(0, $a->sanitize('نه‌آرایه')['extra_ipv4']);
        $this->assertSame(2, $a->sanitize(['extra_ipv4' => '2'])['extra_ipv4']);
        $this->assertSame(2, $a->sanitize(['extra_ipv4' => 2.9])['extra_ipv4']);

        // سقف
        $this->assertSame(CloudAddons::MAX_EXTRA_IP, $a->sanitize(['extra_ipv4' => 999])['extra_ipv4']);
    }

    // ═══════════════ قیمت ═══════════════

    public function test_extra_ip_price_uses_the_same_chain_as_plans(): void
    {
        Setting::put('cloud_extra_ip_eur_cents', '100');   // بها: ۱ یورو
        Setting::put('cloud_margin_pct', '50');
        Setting::put('pricing_rate_override', '100000');

        // ۱٫۰۰ + ۵۰٪ = ۱٫۵۰ یورو → ۱۵۰٬۰۰۰ تومان
        $this->assertSame(150000, $this->addons()->extraIpMonthlyToman());
        $this->assertSame(300000, $this->addons()->monthlyToman(['extra_ipv4' => 2]));
    }

    /** بی‌نرخِ یورو، افزودنی هم قیمت نمی‌گیرد (قیمتِ حدسی نمی‌سازیم) */
    public function test_no_euro_rate_means_no_addon_price(): void
    {
        Setting::put('pricing_rate_override', null);
        Setting::put('cloud_extra_ip_eur_cents', '100');

        $this->partialMock(\App\Services\Cloud\CloudPricing::class, function ($m) {
            $m->shouldReceive('eurToToman')->andReturn(0);
            $m->shouldReceive('sellEurCents')->andReturn(150);
            $m->shouldReceive('toman')->andReturn(0);
        });

        $this->assertSame(0, app(CloudAddons::class)->extraIpMonthlyToman());
    }

    /** افزودنی همان تخفیفِ دوره را می‌گیرد — دو نرخ در یک فاکتور گیج‌کننده است */
    public function test_addons_follow_the_cycle_discount(): void
    {
        Setting::put('cloud_extra_ip_eur_cents', '100');
        Setting::put('cloud_margin_pct', '50');
        Setting::put('pricing_rate_override', '100000');

        $monthly = $this->addons()->forCycle(['extra_ipv4' => 1], 'monthly');
        $yearly = $this->addons()->forCycle(['extra_ipv4' => 1], 'yearly');

        $this->assertSame(150000, $monthly);

        $discount = (int) (config('billing.cycles.yearly.discount_pct') ?? 0);
        $expected = \App\Models\Product::roundUpToman(150000 * 12 * (100 - $discount) / 100);

        $this->assertSame($expected, $yearly);
        $this->assertLessThan(150000 * 12, $yearly, 'دورهٔ سالانه باید تخفیف بخورد');
    }

    public function test_zero_addons_cost_nothing(): void
    {
        $this->assertTrue($this->addons()->isEmpty(['extra_ipv4' => 0]));
        $this->assertSame(0, $this->addons()->forCycle(['extra_ipv4' => 0], 'yearly'));
        $this->assertSame([], $this->addons()->lines(['extra_ipv4' => 0], 'monthly'));
    }

    // ═══════════════ چیزی نفروش که تحویلش ممکن نیست ═══════════════

    /**
     * عرضه‌ها روی چند زیرساخت گروه می‌شوند، ولی همه IP اضافه نمی‌دهند. اگر
     * نسنجیم، مشتری IP می‌خرد و تحویل روی زیرساختی می‌افتد که نمی‌تواند بدهد.
     */
    public function test_addon_requires_a_capable_provider(): void
    {
        $manager = app(CloudManager::class);

        // فقط زیرساختِ ۲ (که IP اضافه ندارد) این اسلاگ را دارد
        $aeza = $this->plan('aeza', ['provider_ref' => '77']);

        $this->assertFalse($this->addons()->planSupports($aeza, ['extra_ipv4' => 1], $manager));
        $this->assertNull($this->addons()->bestPlanFor((string) $aeza->slug, ['extra_ipv4' => 1], $manager));

        // بی‌افزودنی، همان پلن قابلِ فروش است
        $this->assertTrue($this->addons()->planSupports($aeza, ['extra_ipv4' => 0], $manager));
    }

    /** با وجودِ زیرساختِ توانمند، همان اسلاگ قابلِ فروش می‌شود */
    public function test_capable_provider_is_chosen_for_addons(): void
    {
        $manager = app(CloudManager::class);

        $this->plan('aeza', ['provider_ref' => '77', 'cost_eur_cents' => 300]);
        $hetzner = $this->plan('hetzner', ['cost_eur_cents' => 500]);

        $best = $this->addons()->bestPlanFor((string) $hetzner->slug, ['extra_ipv4' => 2], $manager);

        $this->assertNotNull($best);
        $this->assertSame($hetzner->id, $best->id, 'باید زیرساختی انتخاب شود که IP اضافه می‌دهد');
    }

    // ═══════════════ کلیدِ SSH ═══════════════

    /**
     * 🔴 مهم‌ترین بررسیِ این حوزه: اگر مشتری اشتباهی کلیدِ **خصوصی**‌اش را
     * بچسباند و ما ذخیره کنیم، رازش در دیتابیسِ ما نشسته است.
     */
    public function test_private_key_is_rejected_with_a_clear_message(): void
    {
        $priv = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----";

        $r = CloudSshKey::inspect($priv);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('خصوصی', $r['message']);
        $this->assertNull($r['normalized'], 'کلیدِ خصوصی نباید حتی نرمال‌سازی شود');
    }

    public function test_valid_public_key_is_accepted_and_fingerprinted(): void
    {
        // کلیدِ ed25519 با بدنهٔ ساختگیِ **معتبر**: طولِ نوع + نامِ نوع + کلید
        $type = 'ssh-ed25519';
        $body = pack('N', strlen($type)).$type.pack('N', 32).random_bytes(32);
        $key = $type.' '.base64_encode($body).' me@laptop';

        $r = CloudSshKey::inspect($key);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame($type, $r['type']);
        $this->assertMatchesRegularExpression('/^([0-9a-f]{2}:){15}[0-9a-f]{2}$/', (string) $r['fingerprint']);
    }

    public function test_malformed_keys_are_rejected(): void
    {
        foreach (['', 'سلام', 'ssh-ed25519', 'ssh-ed25519 !!!notbase64!!!', 'ssh-magic AAAA'] as $bad) {
            $this->assertFalse(CloudSshKey::inspect($bad)['ok'], "«{$bad}» باید رد شود");
        }
    }

    /** نوعِ اعلام‌شده باید با بدنه بخواند — کلیدِ دست‌کاری‌شده رد شود */
    public function test_key_type_must_match_its_body(): void
    {
        $body = pack('N', strlen('ssh-rsa')).'ssh-rsa'.pack('N', 32).random_bytes(32);
        $lying = 'ssh-ed25519 '.base64_encode($body);

        $this->assertFalse(CloudSshKey::inspect($lying)['ok']);
    }

    /** شناسهٔ کلید نزدِ هر زیرساخت جدا نگه داشته می‌شود */
    public function test_provider_refs_are_kept_per_provider(): void
    {
        $key = CloudSshKey::create([
            'customer_id' => $this->customer()->id,
            'name' => 'k', 'public_key' => 'ssh-ed25519 AAAA', 'fingerprint' => 'aa:bb',
            'key_type' => 'ssh-ed25519',
        ]);

        $key->rememberRef('hetzner', '111');
        $key->rememberRef('aeza', '222');

        $this->assertSame('111', $key->fresh()->refFor('hetzner'));
        $this->assertSame('222', $key->fresh()->refFor('aeza'));
        $this->assertNull($key->fresh()->refFor('nope'));
    }

    /** شناسه‌های زیرساخت نباید در JSON مدل بیرون بروند */
    public function test_provider_refs_are_hidden_from_json(): void
    {
        $key = new CloudSshKey(['name' => 'k', 'public_key' => 'ssh-ed25519 AAAA']);
        $key->provider_refs = ['hetzner' => '999'];

        $json = $key->toJson();

        $this->assertStringNotContainsString('hetzner', $json);
        $this->assertStringNotContainsString('999', $json);
    }

    // ═══════════════ تحویل با افزودنی ═══════════════

    public function test_ssh_key_is_uploaded_once_and_reused(): void
    {
        $plan = $this->plan();
        $this->image();

        $key = CloudSshKey::create([
            'customer_id' => $this->customer()->id,
            'name' => 'laptop', 'public_key' => 'ssh-ed25519 AAAAC3Nz test',
            'fingerprint' => 'aa:bb:cc', 'key_type' => 'ssh-ed25519',
        ]);

        $service = $this->service($plan, ['customer_id' => $key->customer_id, 'cloud_ssh_key_id' => $key->id]);

        $uploads = 0;
        $sentKeys = null;

        Http::fake(function ($request) use (&$uploads, &$sentKeys) {
            $url = $request->url();

            if (str_contains($url, '/ssh_keys') && $request->method() === 'POST') {
                $uploads++;

                return Http::response(['ssh_key' => ['id' => 4242]], 201);
            }

            if (str_ends_with($url, '/servers') && $request->method() === 'POST') {
                $sentKeys = $request->data()['ssh_keys'] ?? null;

                return Http::response([
                    'server' => [
                        'id' => 700, 'status' => 'running',
                        'public_net' => ['ipv4' => ['ip' => '203.0.113.9'], 'ipv6' => ['ip' => null]],
                    ],
                    // کلید داده شده، پس زیرساخت رمز نمی‌سازد
                    'root_password' => null,
                ], 201);
            }

            return Http::response([], 200);
        });

        $this->assertTrue(app(CloudProvisioner::class)->provision($service));

        $this->assertSame(1, $uploads);
        $this->assertSame(['4242'], $sentKeys, 'شناسهٔ کلید باید سرِ ساخت فرستاده شود');
        $this->assertSame('4242', $key->fresh()->refFor('hetzner'), 'شناسه باید ذخیره شود تا دوباره بارگذاری نشود');
    }

    /**
     * 🔴 اگر کلیدِ SSH داده شده، **رمز ساخته نشود**. وگرنه همان امنیتی که مشتری
     * با انتخابِ کلید خواسته بود پس گرفته می‌شود (ورودِ رمزی باز می‌مانَد).
     */
    public function test_no_root_password_is_created_when_a_key_is_used(): void
    {
        $plan = $this->plan();
        $this->image();

        $key = CloudSshKey::create([
            'customer_id' => $this->customer()->id, 'name' => 'k',
            'public_key' => 'ssh-ed25519 AAAA', 'fingerprint' => 'x', 'key_type' => 'ssh-ed25519',
        ]);
        $key->rememberRef('hetzner', '4242');

        $service = $this->service($plan, ['customer_id' => $key->customer_id, 'cloud_ssh_key_id' => $key->id]);

        $resetCalled = false;

        Http::fake(function ($request) use (&$resetCalled) {
            if (str_contains($request->url(), 'reset_password')) {
                $resetCalled = true;
            }

            if (str_ends_with($request->url(), '/servers') && $request->method() === 'POST') {
                return Http::response(['server' => [
                    'id' => 701, 'status' => 'running',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.10'], 'ipv6' => ['ip' => null]],
                ], 'root_password' => null], 201);
            }

            return Http::response([], 200);
        });

        app(CloudProvisioner::class)->provision($service);

        $this->assertFalse($resetCalled, 'با کلیدِ SSH نباید رمز ساخته شود');
        $this->assertFalse(CloudInstance::where('service_id', $service->id)->first()->hasPassword());
    }

    /** کلیدِ مشتریِ دیگر نباید روی سرورِ این مشتری بنشیند */
    public function test_another_customers_key_is_ignored(): void
    {
        $plan = $this->plan();
        $this->image();

        $stranger = CloudSshKey::create([
            'customer_id' => $this->customer()->id, 'name' => 'k',
            'public_key' => 'ssh-ed25519 AAAA', 'fingerprint' => 'y', 'key_type' => 'ssh-ed25519',
        ]);

        // سرویس مالِ مشتریِ دیگری است
        $service = $this->service($plan, ['cloud_ssh_key_id' => $stranger->id]);

        $sentKeys = 'unset';

        Http::fake(function ($request) use (&$sentKeys) {
            if (str_ends_with($request->url(), '/servers') && $request->method() === 'POST') {
                $sentKeys = $request->data()['ssh_keys'] ?? null;

                return Http::response(['server' => [
                    'id' => 702, 'status' => 'running',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.11'], 'ipv6' => ['ip' => null]],
                ], 'root_password' => 'Pw'], 201);
            }

            return Http::response([], 200);
        });

        app(CloudProvisioner::class)->provision($service);

        $this->assertNull($sentKeys, 'کلیدِ کسِ دیگر نباید فرستاده شود');
    }

    public function test_extra_ips_are_attached_and_recorded(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan, ['cloud_addons' => ['extra_ipv4' => 2]]);

        $created = 0;

        Http::fake(function ($request) use (&$created) {
            $url = $request->url();

            if (str_contains($url, '/floating_ips') && $request->method() === 'POST') {
                $created++;

                return Http::response(['floating_ip' => ['ip' => '198.51.100.'.$created]], 201);
            }

            if (str_ends_with($url, '/servers') && $request->method() === 'POST') {
                return Http::response(['server' => [
                    'id' => 703, 'status' => 'running',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.12'], 'ipv6' => ['ip' => null]],
                ], 'root_password' => 'Pw'], 201);
            }

            return Http::response([], 200);
        });

        $this->assertTrue(app(CloudProvisioner::class)->provision($service));

        $this->assertSame(2, $created);

        $meta = CloudInstance::where('service_id', $service->id)->first()->meta;
        $this->assertSame(['198.51.100.1', '198.51.100.2'], $meta['extra_ips']);
    }

    /**
     * شکستِ IP اضافه نباید تحویل را شکست‌خورده کند — سرور کار می‌کند و مشتری
     * منتظرش است. ولی باید ثبت شود تا کسی دستی کاملش کند.
     */
    public function test_failed_extra_ip_does_not_fail_the_delivery(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan, ['cloud_addons' => ['extra_ipv4' => 1]]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/floating_ips')) {
                return Http::response(['error' => ['code' => 'resource_limit_exceeded', 'message' => 'limit']], 403);
            }

            if (str_ends_with($request->url(), '/servers') && $request->method() === 'POST') {
                return Http::response(['server' => [
                    'id' => 704, 'status' => 'running',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.13'], 'ipv6' => ['ip' => null]],
                ], 'root_password' => 'Pw'], 201);
            }

            return Http::response([], 200);
        });

        $this->assertTrue(app(CloudProvisioner::class)->provision($service), 'سرور ساخته شده، پس تحویل موفق است');
        $this->assertSame('done', $service->fresh()->provision_status);
        $this->assertSame([], CloudInstance::where('service_id', $service->id)->first()->meta['extra_ips']);
    }

    /** سرویسِ بی‌افزودنی نباید هیچ تماسِ IP بزند */
    public function test_no_addon_means_no_ip_calls(): void
    {
        $plan = $this->plan();
        $this->image();
        $service = $this->service($plan);

        $ipCalls = 0;

        Http::fake(function ($request) use (&$ipCalls) {
            if (str_contains($request->url(), 'floating_ips')) {
                $ipCalls++;
            }

            if (str_ends_with($request->url(), '/servers') && $request->method() === 'POST') {
                return Http::response(['server' => [
                    'id' => 705, 'status' => 'running',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.14'], 'ipv6' => ['ip' => null]],
                ], 'root_password' => 'Pw'], 201);
            }

            return Http::response([], 200);
        });

        app(CloudProvisioner::class)->provision($service);

        $this->assertSame(0, $ipCalls);
    }
}
