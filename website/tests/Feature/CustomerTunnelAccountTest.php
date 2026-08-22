<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Support\TunnelProfile;
use App\Support\WireGuardKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اکانت‌های «WireGuard روی TCP» در پنلِ مشتری.
 *
 * قواعدی که این تست‌ها قفل می‌کنند:
 *  · بخش فقط وقتی دیده می‌شود که پروفایلِ تونل برای آن سرور تنظیم شده باشد،
 *  · کلیدِ خصوصی هیچ‌وقت در دیتابیس نمی‌نشیند،
 *  · کلیدِ عمومیِ ذخیره‌شده واقعاً متناظرِ کلیدِ خصوصیِ تحویل‌شده است،
 *  · مالکیت، اعتبارسنجیِ نام/آدرس و گیتِ تعلیق برقرارند.
 */
class CustomerTunnelAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function plan(): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'tun-'.random_int(1, 999999),
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function cloudService(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرور مجازی tunnel-test', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $this->plan()->id, 'activated_at' => now(),
        ], $over));
    }

    private function profileArray(array $over = []): array
    {
        return array_merge([
            'enabled' => true,
            'host' => '203.0.113.10', 'port' => 8443,
            'uuid' => 'c42dd9b9-ef65-4f93-bbb2-314208b67a1b',
            'sni' => 'www.example.com',
            'pbk' => str_repeat('A', 43), 'sid' => '6af2b0cebf76ee7a',
            'wg_pub' => base64_encode(random_bytes(32)),
            'wg_host' => '172.20.0.1', 'wg_port' => 13231,
            'iface' => 'wg-tcp', 'subnet' => '10.77.0.0/24',
            'reserved' => ['10.77.0.11'],
        ], $over);
    }

    private function vm(Service $s, ?array $tunnel = null, array $over = []): CloudInstance
    {
        $meta = [];

        if ($tunnel !== null) {
            $meta['tunnel'] = $tunnel;
        }

        return CloudInstance::create(array_merge([
            'service_id' => $s->id, 'provider' => 'proxmox', 'provider_ref' => 'qemu/'.$s->id,
            'location_code' => 'exit-de', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '10.10.10.'.(100 + $s->id),
            'status' => 'running', 'password_seen' => true, 'meta' => $meta,
        ], $over));
    }

    // ───────────────────────── نمایش ─────────────────────────

    public function test_section_is_hidden_when_no_profile_is_set(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s);

        $this->actingAs($c, 'customer')
            ->get('/account/cloud/'.$s->id)
            ->assertOk()
            ->assertDontSee('اکانت‌های اتصال');
    }

    public function test_section_shows_when_profile_is_set(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->get('/account/cloud/'.$s->id)
            ->assertOk()
            ->assertSee('اکانت‌های اتصال', false)
            ->assertSee('10.77.0.2', false); // آدرسِ پیشنهادیِ بعدی
    }

    public function test_incomplete_profile_keeps_the_section_hidden(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s, $this->profileArray(['pbk' => '']));

        $this->actingAs($c, 'customer')
            ->get('/account/cloud/'.$s->id)
            ->assertOk()
            ->assertDontSee('اکانت‌های اتصال');
    }

    // ───────────────────────── صدور ─────────────────────────

    public function test_customer_can_issue_an_account_and_key_is_never_stored(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $i = $this->vm($s, $this->profileArray());

        $res = $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1', 'ip' => '10.77.0.31']);

        $res->assertRedirect();
        $res->assertSessionHas('tunnel_issued');

        $issued = session('tunnel_issued');
        $this->assertSame('phone1', $issued['name']);
        $this->assertStringContainsString('/interface/wireguard/peers/add', $issued['command']);
        $this->assertStringContainsString('allowed-address=10.77.0.31/32', $issued['command']);

        $config = json_decode($issued['config'], true);
        $this->assertIsArray($config);
        $private = $config['endpoints'][0]['private_key'];
        $this->assertTrue(WireGuardKey::looksValid($private));

        // کلیدِ عمومیِ ذخیره‌شده باید دقیقاً از همین کلیدِ خصوصی مشتق شده باشد
        $peers = $i->fresh()->meta['tunnel']['peers'];
        $this->assertCount(1, $peers);
        $this->assertSame(WireGuardKey::publicFrom($private), $peers[0]['pub']);

        // 🔴 کلیدِ خصوصی نباید هیچ‌جای رکورد باشد
        $this->assertStringNotContainsString($private, json_encode($i->fresh()->meta));
    }

    /**
     * بلوکِ «تازه صادر شد» واقعاً رندر می‌شود.
     *
     * کدِ ۲۰۰ کافی نیست: این بلوک فقط در همین یک حالت رندر می‌شود، پس اگر
     * Blade داخلش بشکند هیچ تستِ دیگری متوجه نمی‌شود.
     */
    public function test_the_issued_block_renders_after_the_redirect(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1', 'ip' => '10.77.0.31']);

        $html = $this->actingAs($c, 'customer')
            ->get('/account/cloud/'.$s->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/interface/wireguard/peers/add', $html);
        $this->assertStringContainsString('tunnel-phone1.json', $html);
        $this->assertStringContainsString('tn-data', $html);
        // ردیفِ اکانت هم باید در جدول باشد
        $this->assertStringContainsString('10.77.0.31', $html);
        // و کلیدِ خصوصی نباید موجودیتِ HTML شکسته تولید کرده باشد
        $this->assertStringNotContainsString('@json', $html);
    }

    public function test_issued_config_carries_the_servers_own_parameters(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1', 'ip' => '10.77.0.31']);

        $config = json_decode(session('tunnel_issued')['config'], true);

        $this->assertSame('203.0.113.10', $config['outbounds'][0]['server']);
        $this->assertSame(8443, $config['outbounds'][0]['server_port']);
        $this->assertSame('www.example.com', $config['outbounds'][0]['tls']['server_name']);
        $this->assertSame(['10.77.0.31/32'], $config['endpoints'][0]['address']);
        $this->assertSame('172.20.0.1', $config['endpoints'][0]['peers'][0]['address']);
        // آی‌پیِ سرور باید از تونل بیرون بماند وگرنه حلقه می‌شود
        $this->assertSame(['203.0.113.10/32'], $config['route']['rules'][2]['ip_cidr']);
    }

    public function test_legacy_config_uses_the_old_schema(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1']);

        $legacy = json_decode(session('tunnel_issued')['legacy'], true);

        $this->assertArrayNotHasKey('endpoints', $legacy);
        $this->assertSame('wireguard', $legacy['outbounds'][0]['type']);
        $this->assertSame('vless-out', $legacy['outbounds'][0]['detour']);
    }

    public function test_ip_defaults_to_the_next_free_address(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        // .1 روتر است و .11 رزرو — پس اولین آزاد .2 است
        $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1']);

        $this->assertSame('10.77.0.2', session('tunnel_issued')['ip']);
    }

    public function test_duplicate_name_and_ip_are_rejected(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1', 'ip' => '10.77.0.31']);

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1', 'ip' => '10.77.0.32'])
            ->assertSessionHasErrors();

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone2', 'ip' => '10.77.0.31'])
            ->assertSessionHasErrors();
    }

    public function test_reserved_and_out_of_range_addresses_are_rejected(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'a1', 'ip' => '10.77.0.11'])
            ->assertSessionHasErrors();

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'a2', 'ip' => '192.168.1.5'])
            ->assertSessionHasErrors();

        // آدرسِ خودِ روتر هم رزرو است
        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'a3', 'ip' => '10.77.0.1'])
            ->assertSessionHasErrors();
    }

    public function test_bad_names_are_rejected(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s, $this->profileArray());

        foreach (['', 'a', 'با فاصله', 'موبایل', str_repeat('x', 25), '-lead'] as $bad) {
            $this->actingAs($c, 'customer')
                ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => $bad])
                ->assertSessionHasErrors();
        }
    }

    public function test_suspended_service_cannot_issue(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c, ['status' => 'suspended']);
        $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1'])
            ->assertSessionHasErrors();
    }

    public function test_customer_cannot_issue_on_another_customers_server(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();
        $s = $this->cloudService($theirs);
        $this->vm($s, $this->profileArray());

        $this->actingAs($mine, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1'])
            ->assertNotFound();
    }

    public function test_server_without_a_profile_cannot_issue(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s);

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1'])
            ->assertSessionHasErrors();
    }

    // ───────────────────────── حذف ─────────────────────────

    public function test_customer_can_remove_an_account_and_gets_the_router_command(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $i = $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel', ['name' => 'phone1', 'ip' => '10.77.0.31']);

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel/remove', ['name' => 'phone1'])
            ->assertSessionHas('tunnel_removed');

        $this->assertStringContainsString(
            '/interface/wireguard/peers/remove [find name="phone1"]',
            session('tunnel_removed')
        );
        $this->assertSame([], $i->fresh()->meta['tunnel']['peers']);
    }

    public function test_removing_an_unknown_account_errors(): void
    {
        $c = $this->customer();
        $s = $this->cloudService($c);
        $this->vm($s, $this->profileArray());

        $this->actingAs($c, 'customer')
            ->post('/account/cloud/'.$s->id.'/tunnel/remove', ['name' => 'ghost'])
            ->assertSessionHasErrors();
    }

    // ───────────────────── واحدِ TunnelProfile ─────────────────────

    public function test_profile_rejects_a_non_slash24_subnet(): void
    {
        $p = TunnelProfile::fromArray($this->profileArray(['subnet' => '10.77.0.0/16']));

        $this->assertContains('subnet', $p->missingKeys());
        $this->assertNull($p->subnetBase());
    }

    public function test_next_ip_skips_reserved_and_used(): void
    {
        $p = TunnelProfile::fromArray($this->profileArray([
            'reserved' => ['10.77.0.2', '10.77.0.3'],
            'peers' => [['name' => 'a', 'ip' => '10.77.0.4', 'pub' => 'x', 'at' => '']],
        ]));

        $this->assertSame('10.77.0.5', $p->nextIp());
        $this->assertSame('user5', $p->suggestedName());
    }

    public function test_generated_keys_match_the_reference_derivation(): void
    {
        $k = WireGuardKey::generate();

        $this->assertTrue(WireGuardKey::looksValid($k['private']));
        $this->assertTrue(WireGuardKey::looksValid($k['public']));
        $this->assertSame($k['public'], WireGuardKey::publicFrom($k['private']));
        $this->assertNull(WireGuardKey::publicFrom('not-a-key'));
    }
}
