<?php

namespace Tests\Feature\Dns;

use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\Customer;
use App\Models\Setting;
use App\Services\Dns\NodeVerifier;
use App\Services\Dns\ZonePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ناشر روی یک primaryِ جعلی که مثلِ PowerDNSِ واقعی رفتار می‌کند (rrsetها را
 * نگه می‌دارد، serial را بالا می‌برد). رفتارِ واقعی در ZonePublisherLabTest.
 */
class ZonePublisherTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,array> zone => data */
    private array $server = [];

    private ?int $failWith = null;

    private int $patches = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['edge.dns.allow_insecure_api' => false]);
        Setting::put('pdns_api_url', 'https://dns-api.example.net');
        Setting::putSecret('pdns_api_key', 'SECRET-KEY-123');
        Setting::put('dns_nameservers', json_encode([
            ['host' => 'dns-a.example.net', 'ipv4' => '203.0.113.10', 'port' => 53],
            ['host' => 'dns-b.example.net', 'ipv4' => '203.0.113.20', 'port' => 53],
        ]));
        Http::fake(fn (Request $r) => $this->fakePdns($r));
        $this->app->instance(NodeVerifier::class, new class extends NodeVerifier {
            public function __construct() {}

            public function check(CdnZone $zone, bool $expectAbsent = false): array
            {
                return ['dns-a.example.net' => ['state' => 'missing', 'serial' => null, 'error' => null]];
            }
        });
    }

    private function fakePdns(Request $r)
    {
        if ($this->failWith !== null) {
            return Http::response(['error' => 'boom with SECRET-KEY-123 at https://dns-api.example.net/x'], $this->failWith);
        }
        $path = parse_url($r->url(), PHP_URL_PATH);
        if (! preg_match('~/zones/([^/]+)(/(notify|metadata/.+))?$~', $path, $m)) {
            if ($r->method() === 'POST' && str_ends_with($path, '/zones')) {
                $z = $r->data()['name'];
                $this->server[$z] = ['name' => $z, 'catalog' => $r->data()['catalog'], 'serial' => 1, 'rrsets' => $r->data()['rrsets'], 'meta' => []];

                return Http::response($this->server[$z], 201);
            }

            return Http::response(['version' => '5.0.2'], 200);
        }
        $z = urldecode($m[1]);
        $sub = $m[3] ?? null;
        if (! isset($this->server[$z])) {
            return Http::response(['error' => 'Could not find domain'], 404);
        }
        if ($sub === 'notify') {
            return Http::response(['result' => 'Notification queued'], 200);
        }
        if ($sub !== null && str_starts_with($sub, 'metadata/')) {
            $kind = substr($sub, 9);
            if ($r->method() === 'PUT') {
                $this->server[$z]['meta'][$kind] = $r->data()['metadata'];
            }

            return Http::response(['kind' => $kind, 'metadata' => $this->server[$z]['meta'][$kind] ?? []], 200);
        }

        return match ($r->method()) {
            'GET'    => Http::response($this->server[$z], 200),
            'DELETE' => (function () use ($z) { unset($this->server[$z]);

                return Http::response(null, 204); })(),
            'PUT'    => (function () use ($z, $r) { $this->server[$z] = array_merge($this->server[$z], $r->data());

                return Http::response(null, 204); })(),
            'PATCH'  => (function () use ($z, $r) {
                $this->patches++;
                $sets = collect($this->server[$z]['rrsets'])->keyBy(fn ($s) => $s['name'].'|'.$s['type']);
                foreach ($r->data()['rrsets'] as $s) {
                    $k = $s['name'].'|'.$s['type'];
                    $s['changetype'] === 'DELETE' ? $sets->forget($k) : $sets->put($k, $s);
                }
                $this->server[$z]['rrsets'] = $sets->values()->all();
                $this->server[$z]['serial']++;

                return Http::response(null, 204);
            })(),
        };
    }

    private function zone(array $attrs = []): CdnZone
    {
        $c = Customer::create(['email' => 'p'.random_int(1, 1e9).'@x.test', 'phone' => '0912'.random_int(1000000, 9999999), 'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa']);

        return CdnZone::create($attrs + ['customer_id' => $c->id, 'domain' => 'shop.example', 'mode' => 'ns', 'status' => 'pending', 'verify_token' => 'x']);
    }

    private function live(string $zone = 'shop.example.'): array
    {
        return collect($this->server[$zone]['rrsets'] ?? [])->mapWithKeys(fn ($s) => [$s['name'].'|'.$s['type'] => $s])->all();
    }

    public function test_creates_the_zone_as_catalog_primary_with_tsig_and_our_apex_ns(): void
    {
        $z = $this->zone();
        $z->records()->create(['type' => 'A', 'name' => 'www.shop.example', 'content' => '185.10.20.30', 'ttl' => 300]);

        $r = app(ZonePublisher::class)->publish($z);

        $this->assertTrue($r->ok, (string) $r->error);
        $this->assertSame('catalog.servernet.invalid.', $this->server['shop.example.']['catalog']);
        $this->assertSame(['xfr-servernet'], $this->server['shop.example.']['meta']['TSIG-ALLOW-AXFR']);
        $this->assertEqualsCanonicalizing(['dns-a.example.net.', 'dns-b.example.net.'], array_column($this->live()['shop.example.|NS']['records'], 'content'));
        $this->assertSame('published', $z->fresh()->publish_state);
        $this->assertSame(1, (int) $z->fresh()->applied_version);
        $this->assertNotNull($z->fresh()->primary_serial);
    }

    public function test_second_publish_without_changes_sends_no_patch(): void
    {
        $z = $this->zone();
        $z->records()->create(['type' => 'TXT', 'name' => 'shop.example', 'content' => '"v=spf1 -all" "x"', 'ttl' => 300]);
        $pub = app(ZonePublisher::class);
        $pub->publish($z);
        $before = $this->patches;

        $r = $pub->publish($z->fresh());

        $this->assertSame('unchanged', $r->code);
        $this->assertSame($before, $this->patches, 'no phantom drift');
    }

    public function test_deleting_one_record_keeps_its_rrset_siblings_and_removes_stale_rrsets(): void
    {
        $z = $this->zone();
        $keep = $z->records()->create(['type' => 'A', 'name' => 'www.shop.example', 'content' => '185.10.20.30', 'ttl' => 300]);
        $gone = $z->records()->create(['type' => 'A', 'name' => 'www.shop.example', 'content' => '185.10.20.31', 'ttl' => 300]);
        $z->records()->create(['type' => 'CNAME', 'name' => 'old.shop.example', 'content' => 'x.example.net', 'ttl' => 300]);
        $pub = app(ZonePublisher::class);
        $pub->publish($z);
        $this->server['shop.example.']['rrsets'][] = ['name' => '_acme-challenge.shop.example.', 'type' => 'TXT', 'ttl' => 60, 'records' => [['content' => '"tok"', 'disabled' => false]]];

        $gone->delete();
        CdnRecord::where('name', 'old.shop.example')->delete();
        $z->bumpVersion();
        $pub->publish($z->fresh());

        $live = $this->live();
        $this->assertSame(['185.10.20.30'], array_column($live['www.shop.example.|A']['records'], 'content'));
        $this->assertArrayNotHasKey('old.shop.example.|CNAME', $live);
        $this->assertArrayHasKey('_acme-challenge.shop.example.|TXT', $live, 'ACME challenges are never touched');
        $this->assertArrayHasKey('shop.example.|SOA', $live);
    }

    public function test_an_apex_ns_row_can_never_replace_ours(): void
    {
        $z = $this->zone();
        CdnRecord::create(['cdn_zone_id' => $z->id, 'type' => 'NS', 'name' => 'shop.example', 'content' => 'evil.example.org', 'ttl' => 300]);

        app(ZonePublisher::class)->publish($z);

        $this->assertEqualsCanonicalizing(['dns-a.example.net.', 'dns-b.example.net.'], array_column($this->live()['shop.example.|NS']['records'], 'content'));
    }

    /** 🔴 snapshotِ قدیمی هرگز «تازه‌ترین» علامت نمی‌خورد */
    public function test_a_change_during_publish_keeps_the_zone_pending(): void
    {
        $z = $this->zone();
        $z->records()->create(['type' => 'A', 'name' => 'www.shop.example', 'content' => '185.10.20.30', 'ttl' => 300]);

        // تغییرِ هم‌زمان: درست پیش از PATCH نسخه بالا می‌رود
        Http::fake(function (Request $r) use ($z) {
            if ($r->method() === 'PATCH') {
                CdnZone::whereKey($z->id)->increment('desired_version');
            }

            return $this->fakePdns($r);
        });

        app(ZonePublisher::class)->publish($z);

        $fresh = $z->fresh();
        $this->assertSame(1, (int) $fresh->applied_version);
        $this->assertSame(2, (int) $fresh->desired_version);
        $this->assertNotSame('published', $fresh->publish_state, 'newer version must still be published');
    }

    public function test_a_concurrent_publisher_is_locked_out(): void
    {
        $z = $this->zone();
        $lock = Cache::lock('dns:publish:'.$z->id, 60);
        $lock->get();

        $r = app(ZonePublisher::class)->publish($z);

        $this->assertSame('locked', $r->code);
        $this->assertSame([], $this->server);
        $lock->release();
    }

    public function test_failures_back_off_and_never_leak_the_key_or_url(): void
    {
        $z = $this->zone();
        $this->failWith = 500;
        $pub = app(ZonePublisher::class);

        $pub->publish($z);
        $first = $z->fresh();
        $pub->publish($first);
        $second = $z->fresh();

        $this->assertSame('failed', $second->publish_state);
        $this->assertSame(2, (int) $second->publish_attempts);
        $this->assertTrue($second->next_publish_at->gt(now()->addSeconds(50)), 'backoff grows');
        $this->assertStringNotContainsString('SECRET-KEY-123', (string) $second->last_publish_error);
        $this->assertStringNotContainsString('dns-api.example.net', (string) $second->last_publish_error);
    }

    public function test_an_unknown_read_error_never_recreates_the_zone(): void
    {
        $z = $this->zone();
        $this->failWith = 503;

        app(ZonePublisher::class)->publish($z);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_http_api_url_is_refused_and_the_key_is_never_sent(): void
    {
        Setting::put('pdns_api_url', 'http://dns-api.example.net');
        $z = $this->zone();

        $r = app(ZonePublisher::class)->publish($z);

        $this->assertSame('not_configured', $r->code);
        Http::assertNothingSent();
    }

    public function test_suspended_zone_is_removed_from_the_server_but_kept_in_db(): void
    {
        $z = $this->zone();
        $z->records()->create(['type' => 'A', 'name' => 'www.shop.example', 'content' => '185.10.20.30', 'ttl' => 300]);
        app(ZonePublisher::class)->publish($z);

        $z->update(['status' => 'suspended']);
        $r = app(ZonePublisher::class)->publish($z->fresh());

        $this->assertSame('suspended', $r->code);
        $this->assertArrayNotHasKey('shop.example.', $this->server);
        $this->assertSame(1, $z->records()->count());
    }

    public function test_delete_removes_the_row_only_after_nodes_stop_answering(): void
    {
        $z = $this->zone(['status' => 'deleting']);
        $z->records()->create(['type' => 'A', 'name' => 'www.shop.example', 'content' => '185.10.20.30', 'ttl' => 300]);

        $r = app(ZonePublisher::class)->publish($z);

        $this->assertSame('deleted', $r->code);
        $this->assertNull(CdnZone::find($z->id));
        $this->assertSame(0, CdnRecord::where('cdn_zone_id', $z->id)->count());
    }

    public function test_delete_waits_while_a_node_still_serves_the_zone(): void
    {
        $this->app->instance(NodeVerifier::class, new class extends NodeVerifier {
            public function __construct() {}

            public function check(CdnZone $zone, bool $expectAbsent = false): array
            {
                return ['dns-a.example.net' => ['state' => 'in_sync', 'serial' => 5, 'error' => null]];
            }
        });
        $z = $this->zone(['status' => 'deleting']);

        $r = app(ZonePublisher::class)->publish($z);

        $this->assertSame('deleting', $r->code);
        $this->assertNotNull($z->fresh());
        $this->assertSame('deleting', $z->fresh()->publish_state);
    }
}
