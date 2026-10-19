<?php

namespace Tests\Feature\Dns;

use App\Models\CdnEvent;
use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\Customer;
use App\Models\DnsOwnershipClaim;
use App\Models\DnsZoneNode;
use App\Models\Setting;
use App\Services\Dns\DelegationInspector;
use App\Services\Dns\NodeVerifier;
use App\Services\Dns\OwnershipService;
use App\Services\Dns\ZonePublisher;
use App\Services\Dns\ZoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مالکیت، جداسازیِ حساب‌ها، تصاحبِ zone، CRUD، واردکردن و فعال‌سازی — بی‌شبکه.
 */
class DomainServicesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,list<string>> name => TXT values در DNSِ فعلیِ دامنه */
    public static array $txt = [];

    public static bool $lookupFails = false;

    public static array $parent = ['state' => 'not_delegated', 'ns' => [], 'ds' => []];

    public static int $publishRequests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$txt = [];
        self::$lookupFails = false;
        self::$parent = ['state' => 'not_delegated', 'ns' => [], 'ds' => []];
        self::$publishRequests = 0;
        Setting::put('dns_nameservers', json_encode([
            ['host' => 'dns-a.example.net', 'ipv4' => '203.0.113.10', 'port' => 53],
            ['host' => 'dns-b.example.net', 'ipv4' => '203.0.113.20', 'port' => 53],
        ]));

        $this->app->instance(DelegationInspector::class, new class extends DelegationInspector {
            public function authoritativeTxt(string $domain, string $name, ?array $delegation = null): array
            {
                if (DomainServicesTest::$lookupFails) {
                    return ['ok' => false, 'values' => [], 'answered' => 0, 'error' => 'timeout'];
                }

                return ['ok' => true, 'values' => DomainServicesTest::$txt[$name] ?? [], 'answered' => 2, 'error' => null];
            }

            public function checkDelegation(\App\Models\CdnZone $zone): array
            {
                $p = DomainServicesTest::$parent;
                $zone->forceFill(['delegation_state' => $p['state'], 'delegation_checked_at' => now(), 'delegation_ns' => $p['ns'], 'parent_ds' => $p['ds']])->save();

                return ['state' => $p['state'], 'ns' => $p['ns'], 'ds' => $p['ds'], 'error' => null];
            }

            public function queryAuthoritative(string $domain, string $name, string $type, ?array $delegation = null): array
            {
                return ['ok' => true, 'rcode' => 'NOERROR', 'records' => $type === 'MX' ? [['type' => 'MX', 'name' => $name, 'data' => ['preference' => 10, 'exchange' => 'mx.old.net']]] : [], 'server' => 'x', 'error' => null];
            }
        });
        $this->app->instance(ZonePublisher::class, new class extends ZonePublisher {
            public function __construct() {}

            public function requestPublish(\App\Models\CdnZone $zone): void
            {
                DomainServicesTest::$publishRequests++;
            }
        });
        $this->app->instance(NodeVerifier::class, new class extends NodeVerifier {
            public function __construct() {}

            public function check(\App\Models\CdnZone $zone, bool $expectAbsent = false): array
            {
                foreach (['dns-a.example.net', 'dns-b.example.net'] as $h) {
                    DnsZoneNode::updateOrCreate(['cdn_zone_id' => $zone->id, 'node' => $h], ['state' => 'in_sync', 'serial' => $zone->primary_serial, 'checked_at' => now()]);
                }

                return [];
            }
        });
    }

    private function customer(): Customer
    {
        return Customer::create(['email' => 'd'.random_int(1, 1e9).'@x.test', 'phone' => '0912'.random_int(1000000, 9999999), 'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa'])->refresh();
    }

    private function own(): OwnershipService
    {
        return app(OwnershipService::class);
    }

    private function zones(): ZoneService
    {
        return app(ZoneService::class);
    }

    private function proveTxt(DnsOwnershipClaim $claim): void
    {
        $c = $this->own()->challenge($claim);
        self::$txt[$c['name']] = [$c['value']];
    }

    /* ═════════════ مالکیت ═════════════ */

    public function test_a_claim_publishes_nothing_until_txt_is_proven(): void
    {
        $r = $this->own()->start($this->customer(), 'Shop.Example.');

        $this->assertSame('claim_pending', $r['code']);
        $this->assertSame(0, CdnZone::count());
        $this->assertSame(0, self::$publishRequests);
        $this->assertSame('shop.example', $r['claim']->domain);
        $this->assertSame(32, strlen($r['claim']->token));
    }

    public function test_txt_proof_creates_the_zone_and_requests_publish(): void
    {
        $r = $this->own()->start($c = $this->customer(), 'shop.example');
        $this->proveTxt($r['claim']);

        $v = $this->own()->verify($r['claim']);

        $this->assertSame('verified', $v['code']);
        $this->assertSame($c->id, (int) $v['zone']->customer_id);
        $this->assertSame('txt', $v['zone']->ownership);
        $this->assertSame('pending', $v['zone']->status);
        $this->assertSame(1, self::$publishRequests);
    }

    public function test_another_accounts_token_does_not_prove_ownership(): void
    {
        $mine = $this->own()->start($this->customer(), 'shop.example')['claim'];
        $theirs = $this->own()->start($this->customer(), 'shop.example')['claim'];
        $this->proveTxt($theirs);

        $this->assertSame('txt_mismatch', $this->own()->verify($mine)['code']);
        $this->assertNotSame($mine->token, $theirs->token);
    }

    /** 🔴 «نتوانستیم بپرسیم» هرگز «مالک نیستی» نیست */
    public function test_lookup_failure_keeps_the_claim_pending(): void
    {
        $claim = $this->own()->start($this->customer(), 'shop.example')['claim'];
        self::$lookupFails = true;

        $v = $this->own()->verify($claim);

        $this->assertSame('lookup_failed', $v['code']);
        $this->assertTrue($claim->fresh()->isPending());
    }

    /** 🔴 تصاحب: ادعای اثبات‌نشده هیچ‌کس را قفل نمی‌کند، و اثباتِ بعدی برنده است */
    public function test_squatter_cannot_lock_out_the_real_owner(): void
    {
        $squatter = $this->customer();
        $owner = $this->customer();
        $this->own()->start($squatter, 'victim.example');                    // ادعای بی‌اثبات

        $claim = $this->own()->start($owner, 'victim.example')['claim'];
        $this->proveTxt($claim);
        $v = $this->own()->verify($claim);

        $this->assertSame('verified', $v['code']);
        $this->assertSame($owner->id, (int) CdnZone::where('domain', 'victim.example')->value('customer_id'));
    }

    public function test_proven_control_transfers_an_existing_zone_with_audit(): void
    {
        $old = $this->customer();
        $new = $this->customer();
        $zone = CdnZone::create(['customer_id' => $old->id, 'domain' => 'moved.example', 'mode' => 'ns', 'status' => 'active', 'verify_token' => 'x', 'ownership' => 'txt']);
        $zone->records()->create(['type' => 'A', 'name' => 'moved.example', 'content' => '185.10.20.30', 'ttl' => 300]);

        $claim = $this->own()->start($new, 'moved.example')['claim'];
        $this->proveTxt($claim);
        $this->own()->verify($claim);

        $zone->refresh();
        $this->assertSame($new->id, (int) $zone->customer_id);
        $this->assertSame('transfer', $zone->ownership);
        $this->assertSame(1, $zone->records()->count(), 'records kept so the site does not break');
        $this->assertSame(2, CdnEvent::where('message', 'like', 'zone_transferred_%')->count());
    }

    public function test_expired_claims_cannot_verify(): void
    {
        $claim = $this->own()->start($this->customer(), 'shop.example')['claim'];
        $this->proveTxt($claim);
        $claim->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->own()->expireStale();

        $this->assertSame('expired', $this->own()->verify($claim->fresh())['code']);
        $this->assertSame(0, CdnZone::count());
    }

    public static function badDomains(): array
    {
        return [
            'subdomain'    => ['www.example.com', 'domain_not_registrable'],
            'suffix'       => ['co.ir', 'domain_is_suffix'],
            'garbage'      => ['not a domain', 'domain_invalid'],
            'our own'      => ['servernet.cloud', 'domain_reserved'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badDomains')]
    public function test_rejected_domains(string $in, string $code): void
    {
        $this->assertSame($code, $this->own()->start($this->customer(), $in)['code']);
    }

    public function test_registered_here_skips_txt(): void
    {
        $c = $this->customer();
        \App\Models\Domain::create(['customer_id' => $c->id, 'domain' => 'mine.example', 'sld' => 'mine', 'tld' => 'example', 'status' => 'active', 'registrar' => 'test']);

        $r = $this->own()->start($c, 'mine.example');

        $this->assertSame('zone_created', $r['code']);
        $this->assertSame('registered', $r['zone']->ownership);
    }

    /* ═════════════ رکوردها ═════════════ */

    private function zone(?Customer $c = null): CdnZone
    {
        $c ??= $this->customer();

        return CdnZone::create(['customer_id' => $c->id, 'domain' => 'rec.example', 'mode' => 'ns', 'status' => 'pending', 'verify_token' => 'x']);
    }

    public function test_add_record_bumps_version_audits_and_publishes(): void
    {
        $z = $this->zone();
        $r = $this->zones()->addRecord($z, ['type' => 'A', 'name' => 'www', 'content' => '185.10.20.30', 'ttl' => 300], 'customer:1', '198.51.100.7');

        $this->assertTrue($r['ok']);
        $this->assertSame(2, (int) $z->fresh()->desired_version);
        $this->assertSame('pending', $z->fresh()->publish_state);
        $this->assertSame(1, self::$publishRequests);
        $e = CdnEvent::where('message', 'record_added')->first();
        $this->assertSame('customer:1', $e->actor);
        $this->assertSame('198.51.100.7', $e->ip);
    }

    public function test_ttl_change_aligns_the_whole_rrset(): void
    {
        $z = $this->zone();
        $this->zones()->addRecord($z, ['type' => 'A', 'name' => 'www', 'content' => '185.10.20.30', 'ttl' => 3600], 'system');
        $r = $this->zones()->addRecord($z, ['type' => 'A', 'name' => 'www', 'content' => '185.10.20.31', 'ttl' => 300], 'system');

        $this->assertSame(1, $r['ttl_aligned']);
        $this->assertSame([300], CdnRecord::where('cdn_zone_id', $z->id)->pluck('ttl')->unique()->values()->all());
    }

    public function test_update_can_change_type_and_name(): void
    {
        $z = $this->zone();
        $rec = $this->zones()->addRecord($z, ['type' => 'A', 'name' => 'old', 'content' => '185.10.20.30'], 'system')['record'];

        $r = $this->zones()->updateRecord($z, $rec, ['type' => 'CNAME', 'name' => 'new', 'content' => 'target.example.net'], 'system');

        $this->assertTrue($r['ok'], json_encode($r['errors']));
        $this->assertSame(['CNAME', 'new.rec.example', 'target.example.net'], [$rec->fresh()->type, $rec->fresh()->name, $rec->fresh()->content]);
    }

    /** 🔴 IDOR از راهِ سرویس: رکوردِ zoneِ دیگر هرگز دست نمی‌خورد */
    public function test_a_record_of_another_zone_is_never_touched(): void
    {
        $mine = $this->zone();
        $other = CdnZone::create(['customer_id' => $this->customer()->id, 'domain' => 'other.example', 'mode' => 'ns', 'status' => 'pending', 'verify_token' => 'x']);
        $theirs = $other->records()->create(['type' => 'A', 'name' => 'other.example', 'content' => '185.10.20.30', 'ttl' => 300]);

        $this->assertFalse($this->zones()->updateRecord($mine, $theirs, ['type' => 'A', 'name' => '@', 'content' => '185.10.20.99'], 'x')['ok']);
        $this->assertFalse($this->zones()->deleteRecord($mine, $theirs, 'x'));
        $this->assertFalse($this->zones()->toggleRecord($mine, $theirs, 'x')['ok']);
        $this->assertSame('185.10.20.30', $theirs->fresh()->content);
    }

    public function test_enabling_a_record_rechecks_conflicts(): void
    {
        $z = $this->zone();
        $a = $this->zones()->addRecord($z, ['type' => 'A', 'name' => 'x', 'content' => '185.10.20.30'], 's')['record'];
        $this->zones()->toggleRecord($z, $a, 's');                                          // خاموش
        $a->refresh();
        $a->forceFill(['type' => 'A'])->save();
        CdnRecord::where('id', $a->id)->update(['disabled' => true]);
        $z->records()->create(['type' => 'CNAME', 'name' => 'x.rec.example', 'content' => 't.example.net', 'ttl' => 3600]);

        $r = $this->zones()->toggleRecord($z, $a->fresh(), 's');

        $this->assertFalse($r['ok']);
        $this->assertSame('cname_exists', $r['code']);
    }

    /* ═════════════ واردکردن ═════════════ */

    public function test_import_preview_classifies_and_apply_never_overwrites(): void
    {
        $z = $this->zone();
        $this->zones()->addRecord($z, ['type' => 'A', 'name' => 'www', 'content' => '185.10.20.30'], 's');
        $file = "\$ORIGIN rec.example.\nwww 3600 IN A 185.10.20.30\nwww IN CNAME x.example.net.\napi IN A 185.10.20.40\n@ IN NS ns1.old.net.\nbad IN A 10.0.0.1\nmail IN A 185.10.20.41\n";

        $p = $this->zones()->importPreview($z, $file);
        $status = array_column($p['rows'], 'status', 'line');
        $this->assertSame('duplicate', $status[2]);
        $this->assertSame('conflict', $status[3]);
        $this->assertSame('new', $status[4]);
        $this->assertSame('skipped', $status[5]);
        $this->assertSame('invalid', $status[6]);

        $before = (int) $z->fresh()->desired_version;
        $r = $this->zones()->importApply($z, $file, [2, 3, 4, 6, 7], 'customer:1');

        $this->assertSame(2, $r['added'], 'only the selected NEW rows');
        $this->assertSame(3, $r['skipped']);
        $this->assertSame($before + 1, (int) $z->fresh()->desired_version, 'one version bump for the whole import');
        $this->assertSame(['185.10.20.30'], CdnRecord::where('name', 'www.rec.example')->pluck('content')->all());
    }

    /* ═════════════ فعال‌سازی و حذف ═════════════ */

    public function test_activation_needs_parent_delegation_and_both_nodes_in_sync(): void
    {
        $z = $this->zone();
        $z->forceFill(['publish_state' => 'published', 'primary_serial' => 7, 'applied_version' => 1])->save();

        self::$parent = ['state' => 'partial', 'ns' => ['dns-a.example.net', 'old.registrar.net'], 'ds' => []];
        $this->assertFalse($this->zones()->checkDelegation($z)['active']);
        $this->assertSame('pending', $z->fresh()->status);

        self::$parent = ['state' => 'delegated', 'ns' => ['dns-a.example.net', 'dns-b.example.net'], 'ds' => []];
        $this->assertTrue($this->zones()->checkDelegation($z->fresh())['active']);
        $this->assertSame('active', $z->fresh()->status);
    }

    public function test_losing_delegation_never_deactivates_or_deletes(): void
    {
        $z = $this->zone();
        $z->forceFill(['status' => 'active', 'publish_state' => 'published', 'primary_serial' => 7])->save();
        self::$parent = ['state' => 'not_delegated', 'ns' => ['ns1.elsewhere.net'], 'ds' => []];

        $this->zones()->checkDelegation($z);

        $this->assertSame('active', $z->fresh()->status);
        $this->assertSame('not_delegated', $z->fresh()->delegation_state);
    }

    public function test_parent_ds_blocks_the_checklist(): void
    {
        $z = $this->zone();
        $z->forceFill(['parent_ds' => [['key_tag' => 1]], 'delegation_checked_at' => now()])->save();

        $c = $this->zones()->checklist($z->fresh());

        $this->assertTrue($c['blocking']);
        $this->assertContains('current_mx', array_column($c['items'], 'key'), 'MX at current provider but missing here is warned');
    }

    public function test_delete_request_keeps_the_row_until_the_publisher_confirms(): void
    {
        $z = $this->zone();
        $this->zones()->requestDelete($z, 'customer:1');

        $this->assertSame('deleting', $z->fresh()->status);
        $this->assertSame('deleting', $z->fresh()->publish_state);
        $this->assertFalse($this->zones()->addRecord($z->fresh(), ['type' => 'A', 'name' => 'x', 'content' => '185.10.20.30'], 's')['ok']);
    }
}
