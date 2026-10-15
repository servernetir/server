<?php

namespace Tests\Feature;

use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dns\PowerDnsClient;
use App\Services\Dns\RecordValidator;
use App\Services\NetworkTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DNSِ مدیریت‌شده.
 *
 * ⚠️ «کدِ ۲۰۰ یعنی هیچ.» هر تست یک ادعای رفتاری را قفل می‌کند که اگر بشکند،
 * خرابی‌اش خاموش است: رکوردی که از پنل رفته ولی هنوز جواب می‌دهد، zoneی که
 * کسِ دیگری رزرو کرده، TXTی که PowerDNS کلِ PATCH را به‌خاطرش رد می‌کند.
 */
class ManagedDnsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,array<string,mixed>> rrsetهای «زنده» روی PowerDNSِ جعلی */
    private array $live = [];

    private bool $zoneExists = true;

    /** کدِ خطایی که PowerDNSِ جعلی برای **همه‌چیز** برمی‌گرداند (null = سالم).
     *  ⚠️ `Http::fake` دوم جای اولی را نمی‌گیرد — اولین stubِ منطبق برنده است. */
    private ?int $pdnsDown = null;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('pdns_api_url', 'http://pdns.test:8081');
        Setting::putSecret('pdns_api_key', 'test-key');
        Setting::put('dns_nameservers', 'dns1.example-ns.net,dns2.example-ns.net');

        Http::fake(function (HttpRequest $req) {
            $path = parse_url($req->url(), PHP_URL_PATH);

            if ($this->pdnsDown !== null) {
                return Http::response(['error' => 'down'], $this->pdnsDown);
            }

            if ($req->method() === 'GET' && str_ends_with($path, '/servers/localhost')) {
                return Http::response(['version' => '4.9.1']);
            }
            if ($req->method() === 'GET' && str_contains($path, '/zones/')) {
                return $this->zoneExists
                    ? Http::response(['rrsets' => $this->live])
                    : Http::response(['error' => 'Could not find domain'], 404);
            }
            if ($req->method() === 'POST' && str_ends_with($path, '/zones')) {
                $this->zoneExists = true;

                return Http::response(['rrsets' => []], 201);
            }

            return Http::response(null, 204);
        });
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email'    => 'c'.random_int(1000, 999999).'@x.com',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ])->refresh();
    }

    private function zone(Customer $c, array $attrs = []): CdnZone
    {
        return CdnZone::create(array_merge([
            'customer_id'  => $c->id,
            'domain'       => 'z'.random_int(1000, 999999).'.com',
            'mode'         => 'ns',
            'status'       => 'active',
            'verify_token' => CdnZone::freshToken(),
            'verified_at'  => now(),
        ], $attrs));
    }

    /** @return array<int,array<string,mixed>> */
    private function patches(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($p) => $p[0]->method() === 'PATCH')
            ->flatMap(fn ($p) => $p[0]->data()['rrsets'] ?? [])
            ->values()->all();
    }

    /** @var array<string,array<int,string>> */
    private array $dnsAnswers = [];

    private bool $dnsMocked = false;

    /**
     * ⚠️ یک mock با پاسخِ **تغییرپذیر**، نه mockِ تازه در هر فراخوانی: لاراول
     * نمونهٔ کنترلر را روی Route نگه می‌دارد، پس درخواستِ دومِ همان تست هنوز
     * سرویسی را دارد که با mockِ اول ساخته شده.
     */
    private function fakeDns(array $answers): void
    {
        $this->dnsAnswers = $answers;

        if ($this->dnsMocked) {
            return;
        }

        $net = \Mockery::mock(NetworkTools::class);
        $net->shouldReceive('dns')->andReturnUsing(function (string $name, string $type) {
            $rows = $this->dnsAnswers[$type.' '.$name] ?? [];

            return ['ok' => true, 'records' => array_map(fn ($d) => ['name' => $name, 'data' => $d], $rows), 'count' => count($rows)];
        });
        $this->app->instance(NetworkTools::class, $net);
        $this->dnsMocked = true;
    }

    /* ═════════════════════════ مالکیت و ایزوله‌بودن ═════════════════════════ */

    public function test_a_customer_can_never_touch_another_customers_zone(): void
    {
        $mine = $this->customer();
        $theirs = $this->zone($this->customer());
        $rec = $theirs->records()->create(['type' => 'A', 'name' => $theirs->domain, 'content' => '9.9.9.9', 'ttl' => 3600]);

        $this->actingAs($mine, 'customer')->get('/account/dns/'.$theirs->id)->assertNotFound();
        $this->actingAs($mine, 'customer')
            ->post('/account/dns/'.$theirs->id.'/records', ['type' => 'A', 'name' => 'evil', 'content' => '6.6.6.6', 'ttl' => 3600])
            ->assertNotFound();
        $this->actingAs($mine, 'customer')
            ->post('/account/dns/'.$theirs->id.'/records/'.$rec->id.'/delete')
            ->assertNotFound();
        $this->actingAs($mine, 'customer')->get('/account/dns/'.$theirs->id.'/export')->assertNotFound();

        $this->assertSame(1, $theirs->records()->count());
    }

    public function test_only_the_registrable_domain_can_become_a_zone(): void
    {
        $c = $this->customer();

        $this->actingAs($c, 'customer')->post('/account/dns', ['domain' => 'shop.example.com'])
            ->assertSessionHasErrors('domain');
        $this->actingAs($c, 'customer')->post('/account/dns', ['domain' => 'co.ir'])
            ->assertSessionHasErrors('domain');

        // پسوندِ دوبخشی: example.co.ir خودش دامنهٔ قابلِ ثبت است
        $this->actingAs($c, 'customer')->post('/account/dns', ['domain' => 'https://www.Example.co.ir/'])
            ->assertRedirect();

        $this->assertSame(['example.co.ir'], CdnZone::pluck('domain')->all());
    }

    /**
     * 🔴 «رزروِ zone»: مهاجم zoneِ دامنهٔ دیگری را زودتر می‌سازد. صاحبِ واقعی
     * باید بتواند با TXT روی DNSِ فعلی پسش بگیرد — و رکوردهای مهاجم باید بروند.
     */
    public function test_an_unproven_pending_zone_can_be_claimed_by_the_real_owner(): void
    {
        $squatter = $this->customer();
        $owner = $this->customer();
        $zone = $this->zone($squatter, ['domain' => 'victim.com', 'status' => 'pending', 'verified_at' => null]);
        $zone->records()->create(['type' => 'MX', 'name' => 'victim.com', 'content' => 'evil.net', 'prio' => 10, 'ttl' => 3600]);

        $this->actingAs($owner, 'customer')->post('/account/dns', ['domain' => 'victim.com'])
            ->assertRedirect()->assertSessionHas('dns_claim', 'victim.com');

        // TXTِ نادرست ⇒ هیچ تغییری
        $this->fakeDns(['TXT _servernet-claim.victim.com' => ['servernet-claim=wrong']]);
        $this->actingAs($owner, 'customer')->post('/account/dns/claim', ['domain' => 'victim.com'])
            ->assertSessionHasErrors('claim');
        $this->assertSame($squatter->id, (int) $zone->fresh()->customer_id);

        // مقدارِ مخصوصِ مالک ⇒ zone منتقل، رکوردهای مهاجم پاک
        $expected = app(\App\Services\Dns\ZoneService::class)->claimRecord($owner, 'victim.com')['content'];
        $this->fakeDns(['TXT _servernet-claim.victim.com' => ['"'.$expected.'"']]);
        $this->actingAs($owner, 'customer')->post('/account/dns/claim', ['domain' => 'victim.com'])
            ->assertRedirect();

        $zone->refresh();
        $this->assertSame($owner->id, (int) $zone->customer_id);
        $this->assertSame('txt', $zone->ownership);
        $this->assertSame(0, $zone->records()->count(), 'رکوردهای مهاجم ماندند');
    }

    public function test_an_active_zone_cannot_be_claimed(): void
    {
        $zone = $this->zone($this->customer(), ['domain' => 'taken.com', 'ownership' => 'delegated']);
        $this->actingAs($this->customer(), 'customer')->post('/account/dns', ['domain' => 'taken.com'])
            ->assertSessionHasErrors('domain');
        $this->assertNotNull($zone->fresh());
    }

    public function test_the_claim_value_differs_per_customer(): void
    {
        $svc = app(\App\Services\Dns\ZoneService::class);
        $this->assertNotSame(
            $svc->claimRecord($this->customer(), 'x.com')['content'],
            $svc->claimRecord($this->customer(), 'x.com')['content']
        );
    }

    public function test_unproven_pending_zones_expire_but_proven_ones_stay(): void
    {
        $c = $this->customer();
        $old = $this->zone($c, ['status' => 'pending', 'verified_at' => null]);
        $old->forceFill(['created_at' => now()->subDays(20)])->save();
        $proven = $this->zone($c, ['status' => 'pending', 'verified_at' => null, 'ownership' => 'registered']);
        $proven->forceFill(['created_at' => now()->subDays(20)])->save();
        $fresh = $this->zone($c, ['status' => 'pending', 'verified_at' => null]);

        $this->assertSame(1, app(\App\Services\Dns\ZoneService::class)->expirePending());
        $this->assertNull($old->fresh());
        $this->assertNotNull($proven->fresh());
        $this->assertNotNull($fresh->fresh());
    }

    /* ═════════════════════════ همگام‌سازی با PowerDNS ═════════════════════════ */

    /**
     * 🔴 خرابیِ اصلیِ نسخهٔ قبلی: رکوردِ حذف‌شده روی سرورِ DNS می‌ماند.
     */
    public function test_a_record_removed_in_the_panel_is_deleted_on_the_dns_server(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'sync.com']);
        $z->records()->create(['type' => 'A', 'name' => 'sync.com', 'content' => '1.1.1.1', 'ttl' => 3600]);

        $this->live = [
            ['name' => 'sync.com.', 'type' => 'SOA', 'ttl' => 3600, 'records' => [['content' => 'x', 'disabled' => false]]],
            ['name' => 'sync.com.', 'type' => 'A', 'ttl' => 3600, 'records' => [['content' => '1.1.1.1', 'disabled' => false]]],
            ['name' => 'old.sync.com.', 'type' => 'CNAME', 'ttl' => 3600, 'records' => [['content' => 'gone.net.', 'disabled' => false]]],
        ];

        app(PowerDnsClient::class)->syncZone($z);
        $p = collect($this->patches());

        $this->assertTrue($p->contains(fn ($r) => $r['name'] === 'old.sync.com.' && $r['changetype'] === 'DELETE'), 'رکوردِ حذف‌شده پاک نشد');
        $this->assertFalse($p->contains(fn ($r) => $r['type'] === 'SOA'), 'SOA نباید دست بخورد');
        $this->assertFalse($p->contains(fn ($r) => $r['type'] === 'A'), 'A بی‌تغییر نباید دوباره فرستاده شود');
    }

    public function test_apex_ns_is_always_ours_even_if_a_row_says_otherwise(): void
    {
        $z = $this->zone($this->customer(), ['domain' => 'ns.com']);
        CdnRecord::create(['cdn_zone_id' => $z->id, 'type' => 'NS', 'name' => 'ns.com', 'content' => 'evil-ns.net', 'ttl' => 3600]);

        $ns = collect(app(PowerDnsClient::class)->buildRrsets($z))->firstWhere(fn ($s) => $s['type'] === 'NS' && $s['name'] === 'ns.com.');

        $this->assertSame(['dns1.example-ns.net.', 'dns2.example-ns.net.'], array_column($ns['records'], 'content'));
    }

    public function test_records_with_the_same_name_and_type_become_one_rrset(): void
    {
        $z = $this->zone($this->customer(), ['domain' => 'rr.com']);
        foreach (['1.1.1.1', '2.2.2.2'] as $ip) {
            $z->records()->create(['type' => 'A', 'name' => 'www.rr.com', 'content' => $ip, 'ttl' => 300]);
        }
        $z->records()->create(['type' => 'A', 'name' => 'off.rr.com', 'content' => '3.3.3.3', 'ttl' => 300, 'disabled' => true]);

        $sets = collect(app(PowerDnsClient::class)->buildRrsets($z));
        $www = $sets->where('name', 'www.rr.com.')->where('type', 'A');

        $this->assertCount(1, $www);
        $this->assertCount(2, $www->first()['records']);
        $this->assertNull($sets->firstWhere('name', 'off.rr.com.'), 'رکوردِ غیرفعال منتشر شد');
    }

    public function test_long_txt_is_split_into_255_byte_strings(): void
    {
        $out = app(PowerDnsClient::class)->quoteTxt(str_repeat('a', 300).'"x');

        preg_match_all('~"((?:[^"\\\\]|\\\\.)*)"~', $out, $m);
        $this->assertCount(2, $m[1]);
        $this->assertSame(255, strlen($m[1][0]));
        $this->assertStringContainsString('\\"x', $m[1][1]);
    }

    public function test_missing_zone_is_created_then_populated(): void
    {
        $this->zoneExists = false;
        $z = $this->zone($this->customer(), ['domain' => 'new.com']);
        $z->records()->create(['type' => 'A', 'name' => 'new.com', 'content' => '5.5.5.5', 'ttl' => 3600]);

        $res = app(PowerDnsClient::class)->syncZone($z);

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['created']);
        $post = collect(Http::recorded())->first(fn ($p) => $p[0]->method() === 'POST')[0];
        $this->assertSame(['dns1.example-ns.net.', 'dns2.example-ns.net.'], $post->data()['nameservers']);
        $this->assertStringStartsWith('dns1.example-ns.net. ', $post->data()['rrsets'][0]['records'][0]['content']);
    }

    public function test_an_unknown_server_error_never_recreates_the_zone(): void
    {
        $this->pdnsDown = 500;
        $z = $this->zone($this->customer());

        $res = app(PowerDnsClient::class)->syncZone($z);

        $this->assertFalse($res['ok']);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_a_failed_push_marks_the_zone_dirty_and_reconcile_retries_it(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'retry.com', 'dirty' => false]);
        $this->pdnsDown = 503;

        $this->actingAs($c, 'customer')
            ->post('/account/dns/'.$z->id.'/records', ['type' => 'A', 'name' => 'www', 'content' => '8.8.4.4', 'ttl' => 300])
            ->assertSessionHas('ok');

        $z->refresh();
        $this->assertTrue($z->dirty);
        $this->assertNotNull($z->sync_error);
        $this->assertStringContainsString('تأخیر', session('ok'), 'پیامِ موفقیت دربارهٔ انتشار دروغ گفت');

        $this->pdnsDown = null;
        $this->artisan('dns:reconcile')->assertSuccessful();
        $this->assertFalse($z->fresh()->dirty);
    }

    /* ═════════════════════════ اعتبارسنجی ═════════════════════════ */

    /** @return array<string,array{0:array,1:?string}> */
    public static function records(): array
    {
        return [
            'A ok'             => [['type' => 'A', 'name' => 'www', 'content' => '185.10.20.30'], null],
            'A private'        => [['type' => 'A', 'name' => 'www', 'content' => '192.168.1.5'], 'content'],
            'AAAA bad'         => [['type' => 'AAAA', 'name' => '@', 'content' => '1.2.3.4'], 'content'],
            'CNAME apex'       => [['type' => 'CNAME', 'name' => '@', 'content' => 'x.net'], 'name'],
            'CNAME ok'         => [['type' => 'CNAME', 'name' => 'blog', 'content' => 'https://host.example.net/'], null],
            'MX ip'            => [['type' => 'MX', 'name' => '@', 'content' => '1.2.3.4', 'prio' => 10], 'content'],
            'MX ok'            => [['type' => 'MX', 'name' => '@', 'content' => 'mx.google.com', 'prio' => 1], null],
            'TXT persian'      => [['type' => 'TXT', 'name' => '@', 'content' => 'سلام'], 'content'],
            'TXT dkim chunks'  => [['type' => 'TXT', 'name' => 'default._domainkey', 'content' => '"v=DKIM1; " "p=ABC"'], null],
            'NS apex'          => [['type' => 'NS', 'name' => '@', 'content' => 'ns.x.net'], 'name'],
            'NS sub ok'        => [['type' => 'NS', 'name' => 'lab', 'content' => 'ns.x.net'], null],
            'SRV bad name'     => [['type' => 'SRV', 'name' => 'sip', 'prio' => 1, 'weight' => 1, 'port' => 5060, 'target' => 'a.b.com'], 'name'],
            'SRV ok'           => [['type' => 'SRV', 'name' => '_sip._tcp', 'prio' => 1, 'weight' => 5, 'port' => 5060, 'target' => 'sip.b.com'], null],
            'CAA ok'           => [['type' => 'CAA', 'name' => '@', 'flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'], null],
            'CAA bad tag'      => [['type' => 'CAA', 'name' => '@', 'flags' => 0, 'tag' => 'nope', 'value' => 'x.org'], 'tag'],
            'underscore in A'  => [['type' => 'A', 'name' => 'a_b', 'content' => '8.8.8.8'], 'name'],
            'wildcard ok'      => [['type' => 'A', 'name' => '*', 'content' => '8.8.8.8'], null],
            'mid wildcard'     => [['type' => 'A', 'name' => 'a.*', 'content' => '8.8.8.8'], 'name'],
            'foreign fqdn'     => [['type' => 'A', 'name' => 'www.other.com.', 'content' => '8.8.8.8'], null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('records')]
    public function test_record_validation(array $in, ?string $badField): void
    {
        $res = (new RecordValidator)->validate('example.com', $in + ['ttl' => 3600]);

        if ($badField === null) {
            $this->assertTrue($res['ok'], json_encode($res['errors'], JSON_UNESCAPED_UNICODE));
        } else {
            $this->assertFalse($res['ok']);
            $this->assertArrayHasKey($badField, $res['errors']);
        }
    }

    public function test_multi_string_txt_is_joined_before_storage(): void
    {
        $res = (new RecordValidator)->validate('example.com', ['type' => 'TXT', 'name' => 'k', 'content' => '"v=DKIM1; " "p=ABC"', 'ttl' => 300]);
        $this->assertSame('v=DKIM1; p=ABC', $res['data']['content']);
    }

    public function test_cname_cannot_share_a_name_with_other_records(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'cn.com']);
        $z->records()->create(['type' => 'A', 'name' => 'www.cn.com', 'content' => '1.1.1.1', 'ttl' => 3600]);

        $this->actingAs($c, 'customer')
            ->post('/account/dns/'.$z->id.'/records', ['type' => 'CNAME', 'name' => 'www', 'content' => 'x.net', 'ttl' => 3600])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, $z->records()->count());
    }

    public function test_txt_form_field_does_not_clobber_other_types(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'form.com']);

        // مرورگرِ بی‌جاوااسکریپت هر دو فیلد را می‌فرستد
        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/records', [
            'type' => 'A', 'name' => 'www', 'content' => '8.8.8.8', 'content_txt' => '', 'ttl' => 300,
        ])->assertSessionHasNoErrors();
        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/records', [
            'type' => 'TXT', 'name' => '@', 'content' => '', 'content_txt' => 'v=spf1 -all', 'ttl' => 300,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['8.8.8.8', 'v=spf1 -all'], $z->records()->orderBy('id')->pluck('content')->all());
    }

    public function test_edit_keeps_the_type_and_updates_the_value(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'ed.com']);
        $r = $z->records()->create(['type' => 'MX', 'name' => 'ed.com', 'content' => 'a.ed.com', 'prio' => 10, 'ttl' => 3600]);

        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/records/'.$r->id, [
            'type' => 'A', 'name' => '@', 'content' => 'b.ed.com', 'prio' => 20, 'ttl' => 3600,
        ])->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame(['MX', 'b.ed.com', 20], [$r->type, $r->content, $r->prio]);
    }

    /* ═════════════════════════ تأیید، خروجی، حذف ═════════════════════════ */

    public function test_verify_requires_all_nameservers_to_be_ours(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'v.com', 'status' => 'pending', 'verified_at' => null]);

        $this->fakeDns(['NS v.com' => ['dns1.example-ns.net.', 'old.registrar.net.']]);
        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/verify')->assertSessionHasErrors('verify');
        $this->assertSame('pending', $z->fresh()->status);

        $this->fakeDns(['NS v.com' => ['dns1.example-ns.net.', 'DNS2.example-ns.net.']]);
        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/verify')->assertSessionHas('ok');
        $this->assertSame('active', $z->fresh()->status);
        $this->assertSame('delegated', $z->fresh()->ownership);
    }

    public function test_the_hourly_command_activates_delegated_zones(): void
    {
        $z = $this->zone($this->customer(), ['domain' => 'cron.com', 'status' => 'pending', 'verified_at' => null]);
        $this->fakeDns(['NS cron.com' => ['dns1.example-ns.net', 'dns2.example-ns.net']]);

        $this->artisan('dns:verify-pending')->assertSuccessful();

        $this->assertSame('active', $z->fresh()->status);
    }

    public function test_export_is_a_valid_bind_file(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'ex.com']);
        $z->records()->create(['type' => 'MX', 'name' => 'ex.com', 'content' => 'mail.ex.com', 'prio' => 10, 'ttl' => 3600]);
        $z->records()->create(['type' => 'TXT', 'name' => 'ex.com', 'content' => 'v=spf1 -all', 'ttl' => 3600]);

        $body = $this->actingAs($c, 'customer')->get('/account/dns/'.$z->id.'/export')->assertOk()->getContent();

        $this->assertStringContainsString('$ORIGIN ex.com.', $body);
        $this->assertMatchesRegularExpression('~^@\s+3600\s+IN\s+MX\s+10 mail\.ex\.com\.$~m', $body);
        $this->assertStringContainsString('"v=spf1 -all"', $body);
        $this->assertStringContainsString('NS     dns1.example-ns.net.', $body);
    }

    public function test_delete_needs_the_typed_domain_and_removes_from_dns_first(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'del.com']);

        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/delete', ['confirm' => 'nope.com'])
            ->assertSessionHasErrors('delete');
        $this->assertNotNull($z->fresh());

        // حذف از PowerDNS شکست ⇒ ردیف می‌مانَد (یتیمِ نامرئی ساخته نمی‌شود)
        $this->pdnsDown = 500;
        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/delete', ['confirm' => 'del.com'])
            ->assertSessionHasErrors('delete');
        $this->assertNotNull($z->fresh());

        $this->pdnsDown = null;
        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/delete', ['confirm' => 'DEL.com'])
            ->assertRedirect();
        $this->assertNull($z->fresh());
    }

    /* ═════════════════════════ صفحه‌ها ═════════════════════════ */

    public function test_pages_render_with_real_content(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'page.com', 'status' => 'pending', 'verified_at' => null]);
        $z->records()->create(['type' => 'TXT', 'name' => 'page.com', 'content' => 'hello-txt', 'ttl' => 3600]);

        $this->actingAs($c, 'customer')->get('/account/dns')->assertOk()
            ->assertSee('page.com')->assertSee('dns1.example-ns.net')->assertSee('مدیریت DNS');

        $this->actingAs($c, 'customer')->get('/account/dns/'.$z->id)->assertOk()
            ->assertSee('hello-txt')->assertSee('راه‌اندازی در سه قدم')->assertSee('data-dns-form', false);
    }

    public function test_admin_page_and_settings(): void
    {
        $admin = User::create(['name' => 'مدیر', 'email' => 'dns'.random_int(1000, 9999).'@example.test', 'password' => bcrypt('x'), 'role' => 'admin']);
        $this->zone($this->customer(), ['domain' => 'adm.com']);

        $this->actingAs($admin)->get('/admin/dns')->assertOk()->assertSee('adm.com')->assertDontSee('test-key');

        // کلیدِ خالی = عوض نکن
        $this->actingAs($admin)->post('/admin/dns/settings', [
            'pdns_api_url' => 'http://10.0.0.9:8081/', 'pdns_api_key' => '', 'dns_nameservers' => "a.ns-x.net\nb.ns-x.net",
        ])->assertSessionHasNoErrors();

        $this->assertSame('test-key', Setting::getSecret('pdns_api_key'));
        $this->assertSame('http://10.0.0.9:8081', Setting::get('pdns_api_url'));
        $this->assertSame(['a.ns-x.net', 'b.ns-x.net'], CdnZone::ourNameservers());
        $this->assertTrue(CdnZone::first()->dirty, 'تغییرِ نیم‌سرور zoneها را برای همگام‌سازی علامت نزد');

        $this->actingAs($admin)->post('/admin/dns/settings', [
            'pdns_api_url' => 'http://10.0.0.9:8081', 'dns_nameservers' => 'only-one.net',
        ])->assertSessionHasErrors('dns_nameservers');
    }

    /**
     * 🔴 تا سرورِ DNS وصل نشده، نیم‌سرورها به wildcardِ Cloudflare می‌رسند. مشتری
     * که در این حالت نیم‌سرور عوض کند سایتش می‌میرد — پس سرویس اصلاً عرضه نمی‌شود.
     */
    public function test_the_service_is_hidden_until_powerdns_is_connected(): void
    {
        Setting::putSecret('pdns_api_key', null);
        $c = $this->customer();

        $this->assertFalse(CdnZone::serviceOpen());
        $this->actingAs($c, 'customer')->get('/account')->assertDontSee('مدیریت DNS');
        $this->actingAs($c, 'customer')->get('/account/dns')->assertOk()->assertSee('به‌زودی فعال')->assertDontSee('name="domain"', false);
        $this->actingAs($c, 'customer')->post('/account/dns', ['domain' => 'early.com'])->assertNotFound();
        $this->assertSame(0, CdnZone::count());

        Setting::putSecret('pdns_api_key', 'k');
        $this->assertTrue(CdnZone::serviceOpen());
        $this->actingAs($c, 'customer')->get('/account')->assertSee('مدیریت DNS');
    }

    public function test_default_nameservers_are_the_new_dedicated_pair(): void
    {
        Setting::put('dns_nameservers', null);

        $this->assertSame(['derek.servernet.cloud', 'linda.servernet.cloud'], CdnZone::ourNameservers());
        $this->assertEmpty(array_intersect(CdnZone::ourNameservers(), ['ns1.servernet.cloud', 'ns2.servernet.cloud']),
            'نیم‌سرورهای DNSِ مدیریت‌شده نباید همان BINDِ سرورِ هاست باشند');
    }

    public function test_pages_survive_an_unmigrated_install(): void
    {
        \Illuminate\Support\Facades\Schema::drop('cdn_records');
        \Illuminate\Support\Facades\Schema::drop('cdn_zones');

        $this->actingAs($this->customer(), 'customer')->get('/account/dns')->assertOk()->assertSee('به‌زودی فعال');
        $this->artisan('dns:verify-pending')->assertSuccessful();
        $this->artisan('dns:reconcile')->assertSuccessful();
    }
}
