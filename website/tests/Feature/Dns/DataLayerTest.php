<?php

namespace Tests\Feature\Dns;

use App\Models\CdnEvent;
use App\Models\CdnZone;
use App\Models\Customer;
use App\Models\DnsOwnershipClaim;
use App\Models\DnsZoneNode;
use App\Models\Setting;
use App\Services\Dns\DnsConfig;
use App\Support\Rfc1982;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * لایهٔ داده و پیکربندیِ DNSِ مدیریت‌شده v2 (قراردادِ §2 و §3).
 *
 * ⚠️ هر ادعا این‌جا جلوی یک خرابیِ **خاموش** را می‌گیرد: نسخه‌ای که دو بار
 * «۲» نوشته شد و تغییرِ مشتری هرگز منتشر نشد، سریالی که پس از پیچش «عقب» دیده
 * شد، نیم‌سروری که از config حدس زده شد، ممیزی‌ای که بی‌«چه کسی» ثبت شد.
 * هیچ تستی به شبکه نمی‌رود.
 */
class DataLayerTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_03_000200_managed_dns_v2.php';

    private const ZONE_V2 = [
        'desired_version', 'applied_version', 'publish_state', 'publish_attempts',
        'next_publish_at', 'last_publish_error', 'primary_serial', 'published_at',
        'delegation_state', 'delegation_checked_at', 'delegation_ns', 'parent_ds',
        'records_reviewed_at', 'claim_id',
    ];

    private function customer(): Customer
    {
        return Customer::create([
            'email'    => 'dl'.random_int(1000, 999999).'@example.com',
            'phone'    => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ])->refresh();
    }

    private function zone(?Customer $c = null, array $attrs = []): CdnZone
    {
        $c ??= $this->customer();

        return CdnZone::create(array_merge([
            'customer_id'  => $c->id,
            'domain'       => 'z'.random_int(1000, 999999).'.com',
            'mode'         => 'ns',
            'status'       => 'pending',
            'verify_token' => CdnZone::freshToken(),
        ], $attrs))->refresh();
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /** دو نیم‌سرورِ آزمایشی با IPv4 — از بلوکِ مستندسازی (RFC 5737) */
    private function twoNameservers(): void
    {
        Setting::put('dns_nameservers', json_encode([
            ['host' => 'NS-A.Example-DNS.test.', 'ipv4' => '192.0.2.10', 'ipv6' => null, 'port' => 53],
            ['host' => 'ns-b.example-dns.test', 'ipv4' => '198.51.100.20', 'port' => 5302],
        ]));
    }

    private function openService(): void
    {
        $this->twoNameservers();
        Setting::put('dns_managed_enabled', '1');
        Setting::put('pdns_api_url', 'https://pdns-primary.example.test:8081/');
        Setting::putSecret('pdns_api_key', 'secret-key-never-shown');
    }

    /* ═══════════════════════════ مهاجرت ═══════════════════════════ */

    public function test_migration_creates_tables_and_columns_with_defaults(): void
    {
        $this->assertTrue(Schema::hasTable('dns_ownership_claims'));
        $this->assertTrue(Schema::hasTable('dns_zone_nodes'));
        $this->assertTrue(Schema::hasColumns('cdn_zones', self::ZONE_V2));
        $this->assertTrue(Schema::hasColumns('cdn_events', ['actor', 'ip']));
        $this->assertTrue(Schema::hasColumns('dns_ownership_claims', [
            'id', 'customer_id', 'domain', 'token', 'status', 'expires_at', 'verified_at', 'verified_via',
            'attempts', 'last_checked_at', 'last_result', 'zone_id', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('dns_zone_nodes', [
            'id', 'cdn_zone_id', 'node', 'serial', 'state', 'checked_at', 'last_error', 'created_at', 'updated_at',
        ]));

        // ردیفِ بی‌مقدارِ v2 (مثلِ ردیفِ v1) پیش‌فرض‌های قرارداد را می‌گیرد
        $z = $this->zone();
        $this->assertSame(1, $z->desired_version);
        $this->assertSame(0, $z->applied_version);
        $this->assertSame('pending', $z->publish_state);
        $this->assertSame(0, $z->publish_attempts);
        $this->assertSame('unknown', $z->delegation_state);
        $this->assertNull($z->primary_serial);
        $this->assertNull($z->next_publish_at);
        $this->assertNull($z->claim_id);
    }

    public function test_migration_down_removes_only_v2_and_up_is_rerunnable(): void
    {
        $m = $this->migration();

        $m->down();

        $this->assertFalse(Schema::hasTable('dns_ownership_claims'));
        $this->assertFalse(Schema::hasTable('dns_zone_nodes'));
        foreach (self::ZONE_V2 as $col) {
            $this->assertFalse(Schema::hasColumn('cdn_zones', $col), "ستونِ {$col} پس از down ماند");
        }
        $this->assertFalse(Schema::hasColumn('cdn_events', 'actor'));
        $this->assertFalse(Schema::hasColumn('cdn_events', 'ip'));

        // ستون‌های v1 دست نخوردند — کدِ v1 پس از rollback کار می‌کند
        $this->assertTrue(Schema::hasColumns('cdn_zones', ['verified_at', 'ownership', 'dirty', 'synced_at', 'verify_token']));
        $this->assertTrue(Schema::hasColumns('cdn_events', ['type', 'message', 'payload']));

        // down دوباره بی‌خطا (نگهبان‌ها)
        $m->down();

        // اجرای نیمه‌کاره (DDL در MariaDB تراکنشی نیست): یک ستون هست، بقیه نه
        Schema::table('cdn_zones', fn (Blueprint $t) => $t->unsignedBigInteger('desired_version')->default(1));

        $m->up();
        $this->assertTrue(Schema::hasColumns('cdn_zones', self::ZONE_V2));
        $this->assertTrue(Schema::hasTable('dns_zone_nodes'));

        // up دوباره بی‌خطا و بی‌تغییر
        $m->up();
        $this->assertTrue(Schema::hasColumns('cdn_events', ['actor', 'ip']));

        $z = $this->zone();
        $this->assertSame(1, $z->desired_version);
        $this->assertSame('pending', $z->publish_state);
    }

    public function test_zone_node_pair_is_unique(): void
    {
        $z = $this->zone();
        DnsZoneNode::create(['cdn_zone_id' => $z->id, 'node' => 'ns-a.example-dns.test', 'state' => 'in_sync']);

        $this->expectException(QueryException::class);
        // همان نود با نوشتنِ دیگر — mutator یکسانش می‌کند و یکتایی می‌گیردش
        DnsZoneNode::create(['cdn_zone_id' => $z->id, 'node' => 'NS-A.example-dns.test.', 'state' => 'lagging']);
    }

    /* ═══════════════════════════ مدل‌ها ═══════════════════════════ */

    public function test_zone_v2_casts_and_relations(): void
    {
        $c = $this->customer();
        $z = $this->zone($c);

        $claim = DnsOwnershipClaim::create([
            'customer_id' => $c->id, 'domain' => $z->domain, 'token' => DnsOwnershipClaim::freshToken(),
            'status' => 'verified', 'expires_at' => now()->addDays(7), 'verified_at' => now(),
            'verified_via' => 'txt', 'zone_id' => $z->id,
        ]);

        $z->update([
            'delegation_ns'       => ['ns-a.example-dns.test', 'ns-b.example-dns.test'],
            'parent_ds'           => [['key_tag' => 12345, 'algorithm' => 13, 'digest_type' => 2, 'digest' => 'ab']],
            'next_publish_at'     => '2026-10-18 10:00:00',
            'primary_serial'      => '4294967295',
            'claim_id'            => $claim->id,
            'records_reviewed_at' => now(),
        ]);

        $z = CdnZone::find($z->id);
        $this->assertSame(['ns-a.example-dns.test', 'ns-b.example-dns.test'], $z->delegation_ns);
        $this->assertSame(12345, $z->parent_ds[0]['key_tag']);
        $this->assertInstanceOf(Carbon::class, $z->next_publish_at);
        $this->assertInstanceOf(Carbon::class, $z->records_reviewed_at);
        $this->assertSame(4294967295, $z->primary_serial);
        $this->assertSame($claim->id, $z->claim->id);
        $this->assertSame($z->id, $claim->zone->id);
        $this->assertSame($c->id, $claim->customer->id);

        $node = $z->nodes()->create(['node' => ' NS-A.Example-DNS.TEST. ', 'serial' => '7', 'state' => 'in_sync',
            'checked_at' => now(), 'last_error' => str_repeat('x', 500)]);
        $node = DnsZoneNode::find($node->id);

        $this->assertSame('ns-a.example-dns.test', $node->node);
        $this->assertSame(7, $node->serial);
        $this->assertSame(120, mb_strlen($node->last_error));
        $this->assertInstanceOf(Carbon::class, $node->checked_at);
        $this->assertSame($z->id, $node->zone->id);
        $this->assertCount(1, $z->nodes);
    }

    public function test_claim_casts_scopes_and_token(): void
    {
        $a = $this->customer();
        $b = $this->customer();

        $token = DnsOwnershipClaim::freshToken();
        $this->assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $token);
        $this->assertNotSame($token, DnsOwnershipClaim::freshToken());

        $live = DnsOwnershipClaim::create(['customer_id' => $a->id, 'domain' => 'shared.example', 'token' => $token,
            'status' => 'pending', 'expires_at' => now()->addDays(7), 'attempts' => '3']);
        // ادعای هم‌زمانِ حسابِ دیگر روی همان دامنه مجاز است (هیچ یکتایی‌ای نیست)
        DnsOwnershipClaim::create(['customer_id' => $b->id, 'domain' => 'shared.example', 'token' => DnsOwnershipClaim::freshToken(),
            'status' => 'pending', 'expires_at' => now()->addDays(7)]);
        // سوخته به زمان ولی هنوز `pending` در ستون (کرونِ انقضا هنوز نچرخیده)
        $stale = DnsOwnershipClaim::create(['customer_id' => $a->id, 'domain' => 'old.example', 'token' => DnsOwnershipClaim::freshToken(),
            'status' => 'pending', 'expires_at' => now()->subMinute()]);
        DnsOwnershipClaim::create(['customer_id' => $a->id, 'domain' => 'gone.example', 'token' => DnsOwnershipClaim::freshToken(),
            'status' => 'cancelled', 'expires_at' => now()->addDays(7)]);

        $live = DnsOwnershipClaim::find($live->id);
        $this->assertSame(3, $live->attempts);
        $this->assertInstanceOf(Carbon::class, $live->expires_at);
        $this->assertTrue($live->isPending());

        $this->assertSame(2, DnsOwnershipClaim::pending()->where('domain', 'shared.example')->count());
        $this->assertSame([$live->id], DnsOwnershipClaim::pending()->forCustomer($a->id)->pluck('id')->all());
        $this->assertSame([$stale->id], DnsOwnershipClaim::stale()->pluck('id')->all());
        $this->assertFalse(DnsOwnershipClaim::find($stale->id)->isPending());
        $this->assertTrue(DnsOwnershipClaim::find($stale->id)->isExpired());
    }

    public function test_zone_node_scopes(): void
    {
        $a = $this->customer();
        $za = $this->zone($a);
        $zb = $this->zone();

        $za->nodes()->create(['node' => 'ns-a.example-dns.test', 'state' => 'in_sync', 'serial' => 1]);
        $za->nodes()->create(['node' => 'ns-b.example-dns.test', 'state' => 'unknown']);
        $zb->nodes()->create(['node' => 'ns-a.example-dns.test', 'state' => 'lagging', 'serial' => 1]);

        $this->assertSame(2, DnsZoneNode::forCustomer($a->id)->count());
        $this->assertSame(1, DnsZoneNode::forCustomer($a->id)->pending()->count());
        $this->assertSame(2, DnsZoneNode::pending()->count());
        $this->assertSame(1, DnsZoneNode::inSync()->count());
        $this->assertSame(2, DnsZoneNode::forZone($za->id)->count());
    }

    /* ═══════════════════════════ bumpVersion ═══════════════════════════ */

    public function test_bump_version_is_atomic_on_the_row_not_the_instance(): void
    {
        $z = $this->zone();
        $z->forceFill(['publish_state' => 'failed', 'next_publish_at' => now()->addHour(), 'applied_version' => 1, 'dirty' => false])->save();

        // دو نمونهٔ کهنه، مثلِ دو درخواستِ هم‌زمان
        $first = CdnZone::find($z->id);
        $second = CdnZone::find($z->id);

        $this->assertSame(2, $first->bumpVersion());
        $this->assertSame(1, $second->desired_version, 'نمونهٔ دوم عمداً کهنه است');
        $this->assertSame(3, $second->bumpVersion(), 'جمعِ درون‌حافظه «۲» را دوباره می‌نوشت');

        $fresh = CdnZone::find($z->id);
        $this->assertSame(3, $fresh->desired_version);
        $this->assertSame('pending', $fresh->publish_state);
        $this->assertNull($fresh->next_publish_at);
        $this->assertFalse($fresh->dirty, 'bump پرچمِ v1 را نوشت ⇒ dns:reconcileِ v1 zone را می‌سازد');
        $this->assertSame(1, $fresh->applied_version, 'bump به نسخهٔ اعمال‌شده دست نمی‌زند');

        // نمونهٔ فراخوان هم‌گام با ردیف است و dirtyِ Eloquent ندارد
        $this->assertSame(3, $second->desired_version);
        $this->assertSame('pending', $second->publish_state);
        $this->assertFalse($second->isDirty('desired_version'));
    }

    public function test_bump_version_inside_a_transaction_and_on_deleting_zone(): void
    {
        $z = $this->zone();

        // دو تغییر در یک تراکنش ⇒ هر کدام نسخهٔ خودش را می‌گیرد
        $seen = DB::transaction(fn () => [$z->bumpVersion(), $z->bumpVersion()]);
        $this->assertSame([2, 3], $seen);
        $this->assertSame(3, CdnZone::find($z->id)->desired_version);

        // zoneِ در حالِ حذف «در انتظارِ انتشار» نمی‌شود (§4.4)، ترتیب هرچه باشد
        $z->forceFill(['status' => 'deleting', 'publish_state' => 'deleting'])->save();
        $this->assertSame(4, $z->bumpVersion());
        $this->assertSame('deleting', CdnZone::find($z->id)->publish_state);
        $this->assertSame('deleting', $z->publish_state);
    }

    public function test_bump_version_refuses_an_unsaved_zone(): void
    {
        $this->expectException(\LogicException::class);
        (new CdnZone(['domain' => 'unsaved.example']))->bumpVersion();
    }

    public function test_bump_version_never_drops_unsaved_status_changes(): void
    {
        $z = $this->zone(null, ['status' => 'active']);

        // ترتیبِ «در حافظه ← bump ← save»: قبلاً publish_state بی‌صدا pending می‌شد
        try {
            DB::transaction(function () use ($z) {
                $z->forceFill(['status' => 'deleting', 'publish_state' => 'deleting']);
                $z->bumpVersion();
                $z->save();
            });
            $this->fail('bump روی وضعیتِ ذخیره‌نشده استثنا نداد');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('status', $e->getMessage());
        }

        $row = CdnZone::find($z->id);
        $this->assertSame(['active', 'pending', 1], [$row->status, $row->publish_state, $row->desired_version]);

        foreach (['publish_state' => 'failed', 'desired_version' => 9, 'next_publish_at' => now()] as $col => $val) {
            $fresh = CdnZone::find($z->id);
            $fresh->forceFill([$col => $val]);
            try {
                $fresh->bumpVersion();
                $this->fail("bump تغییرِ ذخیره‌نشدهٔ {$col} را بی‌صدا دور ریخت");
            } catch (\LogicException) {
                $this->assertSame(1, CdnZone::find($z->id)->desired_version);
            }
        }

        // دو ترتیبِ درست هر دو به حالتِ §4.4 می‌رسند
        $a = CdnZone::find($z->id);
        DB::transaction(function () use ($a) {
            $a->forceFill(['status' => 'deleting', 'publish_state' => 'deleting'])->save();
            $a->bumpVersion();
        });
        $this->assertSame(['deleting', 'deleting', 2], [$a->fresh()->status, $a->fresh()->publish_state, $a->fresh()->desired_version]);

        $b = $this->zone(null, ['status' => 'active']);
        DB::transaction(function () use ($b) {
            $b->bumpVersion();
            $b->forceFill(['status' => 'deleting', 'publish_state' => 'deleting'])->save();
        });
        $this->assertSame(['deleting', 'deleting', 2], [$b->fresh()->status, $b->fresh()->publish_state, $b->fresh()->desired_version]);

        // تغییرِ ستونِ بی‌ربط مانعِ bump نیست و ذخیره‌نشده می‌ماند
        $c = CdnZone::find($b->id);
        $c->forceFill(['last_publish_error' => 'x']);
        $this->assertSame(3, $c->bumpVersion());
        $this->assertTrue($c->isDirty('last_publish_error'));
    }

    public function test_bump_version_on_a_vanished_row_throws_and_rolls_back(): void
    {
        $z = $this->zone();
        DB::table('cdn_zones')->where('id', $z->id)->delete();      // حذفِ هم‌زمانِ §4.4

        try {
            DB::transaction(function () use ($z) {
                $z->records()->create(['type' => 'A', 'name' => $z->domain, 'content' => '192.0.2.1', 'ttl' => 300]);
                $z->bumpVersion();
            });
            $this->fail('bump روی ردیفِ ناموجود «۰» برگرداند و تراکنش commit شد');
        } catch (ModelNotFoundException $e) {
            $this->assertSame(CdnZone::class, $e->getModel());
        }

        $this->assertSame(0, DB::table('cdn_records')->where('cdn_zone_id', $z->id)->count(), 'رکوردِ یتیم commit شد');
    }

    /**
     * 🔴 `dns:reconcile`ِ v1 هنوز zoneهای `dirty` را با کلاینتِ v1 می‌سازد (Native،
     * بی‌catalog، expireِ ۷روزه) و کلید را به هر URLی می‌فرستد. bumpِ v2 نباید
     * آن را راه بیندازد. ادعاها برای `dns:reconcile`ِ v2 (§4.5) هم برقرارند:
     * zoneِ pending هیچ نوشتنی روی primary نمی‌سازد و آدرسِ ناامن هیچ درخواستی.
     */
    public function test_bump_version_does_not_feed_the_v1_writer(): void
    {
        $this->openService();
        Http::fake();

        $z = $this->zone(null, ['domain' => 'probe-v2.example']);
        $z->forceFill(['dirty' => false])->save();

        $z->bumpVersion();
        Artisan::call('dns:reconcile');

        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');

        // کلید هرگز بی‌رمز به IPِ عمومی نمی‌رود
        Setting::put('pdns_api_url', 'http://203.0.113.9:8081');
        $this->assertFalse(DnsConfig::apiUrlIsAcceptable());

        $z->bumpVersion();
        Artisan::call('dns:reconcile');

        Http::assertNotSent(fn ($r) => str_starts_with(strtolower($r->url()), 'http://203.0.113.9'));

        $row = CdnZone::find($z->id);
        $this->assertFalse($row->dirty);
        $this->assertSame('pending', $row->status);
        $this->assertSame(3, $row->desired_version);
    }

    /* ═══════════════════════ isPublishedEverywhere ═══════════════════════ */

    public function test_published_everywhere_needs_every_configured_node_in_sync(): void
    {
        $this->twoNameservers();
        $z = $this->zone();
        $z->forceFill(['publish_state' => 'published', 'primary_serial' => 2026101801])->save();

        $this->assertFalse($z->isPublishedEverywhere(), 'بی‌ردیفِ نود ادعای انتشار نه');

        $a = $z->nodes()->create(['node' => 'ns-a.example-dns.test', 'state' => 'in_sync', 'serial' => 2026101801]);
        $this->assertFalse($z->fresh()->isPublishedEverywhere(), 'یک نود از دو کافی نیست');

        $b = $z->nodes()->create(['node' => 'NS-B.EXAMPLE-DNS.TEST.', 'state' => 'in_sync', 'serial' => 2026101802]);
        $this->assertTrue($z->fresh()->isPublishedEverywhere());

        // ردیفِ نودی که دیگر در فهرست نیست بی‌اثر است
        $z->nodes()->create(['node' => 'retired.example-dns.test', 'state' => 'unreachable']);
        $this->assertTrue($z->fresh()->isPublishedEverywhere());

        // نودِ عقب‌مانده با سریالِ کمتر
        $b->update(['serial' => 2026101800]);
        $this->assertFalse($z->fresh()->isPublishedEverywhere());

        // state حرفِ آخر را می‌زند، حتی با سریالِ درست
        $b->update(['serial' => 2026101801, 'state' => 'lagging']);
        $this->assertFalse($z->fresh()->isPublishedEverywhere());
        $b->update(['state' => 'in_sync']);
        $this->assertTrue($z->fresh()->isPublishedEverywhere());

        // سریالِ نامعلوم روی نود
        $a->update(['serial' => null]);
        $this->assertFalse($z->fresh()->isPublishedEverywhere());
        $a->update(['serial' => 2026101801]);

        // انتشار روی primary تمام نشده
        $z->forceFill(['publish_state' => 'pending'])->save();
        $this->assertFalse($z->fresh()->isPublishedEverywhere());
        $z->forceFill(['publish_state' => 'published', 'primary_serial' => null])->save();
        $this->assertFalse($z->fresh()->isPublishedEverywhere());

        // فهرستِ خالیِ نیم‌سرور ⇒ هرگز «منتشر روی همه»
        $z->forceFill(['primary_serial' => 2026101801])->save();
        $this->assertTrue($z->fresh()->isPublishedEverywhere());
        Setting::put('dns_nameservers', '[]');
        $this->assertFalse($z->fresh()->isPublishedEverywhere());
    }

    public function test_published_everywhere_follows_rfc1982_wrap_around(): void
    {
        $this->twoNameservers();
        $z = $this->zone();
        $z->forceFill(['publish_state' => 'published', 'primary_serial' => 4294967295])->save();

        // سریالِ نودها از 2^32−1 پیچیده و کوچک شده — یعنی **تازه‌تر**
        $z->nodes()->create(['node' => 'ns-a.example-dns.test', 'state' => 'in_sync', 'serial' => 5]);
        $b = $z->nodes()->create(['node' => 'ns-b.example-dns.test', 'state' => 'in_sync', 'serial' => 4294967295]);
        $this->assertTrue($z->fresh()->isPublishedEverywhere(), 'سریالِ پیچیده «عقب» دیده شد');

        // عددِ بزرگ‌تر ولی در فضای سریال **قدیمی‌تر**
        $z->forceFill(['primary_serial' => 10])->save();
        $b->update(['serial' => 4294967290]);
        $this->assertFalse($z->fresh()->isPublishedEverywhere(), 'نودِ قدیمی فقط چون عددش بزرگ است هم‌گام خوانده شد');

        // فاصلهٔ دقیقاً 2^31 تعریف‌نشده است ⇒ ادعای انتشار نه
        $z->forceFill(['primary_serial' => 0])->save();
        $b->update(['serial' => 2147483648]);
        $this->assertFalse($z->fresh()->isPublishedEverywhere());
    }

    public function test_rfc1982_compare(): void
    {
        $this->assertSame(0, Rfc1982::compare(7, 7));
        $this->assertSame(-1, Rfc1982::compare(1, 2));
        $this->assertSame(1, Rfc1982::compare(2, 1));
        $this->assertSame(1, Rfc1982::compare(0, 4294967295), 'صفر پس از بیشینه می‌آید');
        $this->assertSame(-1, Rfc1982::compare(4294967295, 0));
        $this->assertSame(1, Rfc1982::compare(100, 4294967200));
        $this->assertSame(-1, Rfc1982::compare(1, 2147483648));
        $this->assertSame(1, Rfc1982::compare(1, 2147483650));
        $this->assertSame(Rfc1982::UNDEFINED, Rfc1982::compare(0, 2147483648));
        $this->assertSame(Rfc1982::UNDEFINED, Rfc1982::compare(2147483648, 0));
        $this->assertLessThan(0, Rfc1982::UNDEFINED, 'تعریف‌نشده باید با `>= 0` «نه» خوانده شود');

        $this->assertTrue(Rfc1982::atLeast(5, 4294967295));
        $this->assertTrue(Rfc1982::atLeast(9, 9));
        $this->assertFalse(Rfc1982::atLeast(4294967295, 5));
        $this->assertFalse(Rfc1982::atLeast(0, 2147483648));
        $this->assertSame(4, Rfc1982::add(4294967295, 5));
    }

    /* ═══════════════════════════ DnsConfig ═══════════════════════════ */

    public function test_nameservers_parse_json_and_normalize(): void
    {
        Setting::put('dns_nameservers', json_encode([
            ['host' => 'NS-A.Example-DNS.test.', 'ipv4' => ' 192.0.2.10 ', 'ipv6' => '2001:DB8:0:0::53', 'port' => '5301'],
            ['host' => 'ns-a.example-dns.test', 'ipv4' => '192.0.2.99'],               // تکراری ⇒ حذف
            ['host' => 'ns1.servernet.cloud', 'ipv4' => '65.109.176.14'],                // رزرو ⇒ حذف
            ['host' => 'NS2.SERVERNET.IR.', 'ipv4' => '85.9.108.116'],                   // رزرو ⇒ حذف
            ['host' => 'ns-b.example-dns.test', 'ipv4' => '999.1.1.1', 'port' => 70000], // IPِ بد ⇒ null، پورتِ بد ⇒ 53
            ['host' => 'not a host!', 'ipv4' => '192.0.2.1'],                            // نامِ بد ⇒ حذف
            ['host' => 'single-label', 'ipv4' => '192.0.2.2'],                           // یک‌برچسبی ⇒ حذف
            ['host' => '192.0.2.1', 'ipv4' => '192.0.2.1'],                              // IP به‌جای نام ⇒ حذف
            ['host' => 'ns.example.123', 'ipv4' => '192.0.2.3'],                         // TLDِ عددی ⇒ حذف
            'garbage',
        ]));

        $this->assertSame([
            ['host' => 'ns-a.example-dns.test', 'ipv4' => '192.0.2.10', 'ipv6' => '2001:db8::53', 'port' => 5301],
            ['host' => 'ns-b.example-dns.test', 'ipv4' => null, 'ipv6' => null, 'port' => 53],
        ], DnsConfig::nameservers());

        $this->assertSame(['ns-a.example-dns.test', 'ns-b.example-dns.test'], DnsConfig::nameserverHosts());
        $this->assertSame(DnsConfig::nameserverHosts(), CdnZone::ourNameservers());
        $this->assertSame(DnsConfig::nameserverHosts(), $this->zone()->nameservers());
    }

    public function test_validate_nameservers_reports_every_rejection_as_a_code(): void
    {
        $res = DnsConfig::validateNameservers([
            ['host' => 'ns-a.example-dns.test', 'ipv4' => '192.0.2.10'],
            ['host' => '10.0.0.53', 'ipv4' => '10.0.0.53'],
            ['host' => 'ns1.servernet.ir', 'ipv4' => '85.9.108.116'],
            ['host' => 'NS-A.example-dns.test.', 'ipv4' => '192.0.2.11'],
            ['host' => 'ns-b.example-dns.test', 'ipv4' => '127.0.0.1', 'ipv6' => 'fe80::1', 'port' => '0'],
            ['host' => 'ns-c.example-dns.test', 'ipv4' => '', 'ipv6' => null, 'port' => ''],
            42,
        ]);

        $this->assertSame([
            ['index' => 1, 'field' => 'host', 'code' => 'ns_host_invalid'],
            ['index' => 2, 'field' => 'host', 'code' => 'ns_host_reserved'],
            ['index' => 3, 'field' => 'host', 'code' => 'ns_host_duplicate'],
            ['index' => 4, 'field' => 'ipv4', 'code' => 'ns_ipv4_invalid'],
            ['index' => 4, 'field' => 'ipv6', 'code' => 'ns_ipv6_invalid'],
            ['index' => 4, 'field' => 'port', 'code' => 'ns_port_invalid'],
            ['index' => 5, 'field' => 'ipv4', 'code' => 'ns_no_address'],
            ['index' => 6, 'field' => 'nameservers', 'code' => 'ns_entry_invalid'],
            ['index' => null, 'field' => 'nameservers', 'code' => 'ns_too_few'],
        ], $res['errors']);

        // ردیف‌های نامِ معتبر می‌مانند تا NSِ zone همان چیزی باشد که مدیر نوشته
        $this->assertSame(['ns-a.example-dns.test', 'ns-b.example-dns.test', 'ns-c.example-dns.test'],
            array_column($res['nameservers'], 'host'));
        $this->assertSame($res['nameservers'], DnsConfig::normalizeNameservers([
            ['host' => 'ns-a.example-dns.test', 'ipv4' => '192.0.2.10'],
            ['host' => 'ns-b.example-dns.test', 'ipv4' => '127.0.0.1', 'ipv6' => 'fe80::1', 'port' => '0'],
            ['host' => 'ns-c.example-dns.test'],
        ]));

        $ok = DnsConfig::validateNameservers([
            ['host' => 'ns-a.example-dns.test', 'ipv4' => '192.0.2.10', 'port' => 5301],
            ['host' => 'ns-b.example-dns.test', 'ipv4' => '172.20.1.2', 'port' => '5302'],   // RFC1918 (آزمایشگاه) مجاز
            ['host' => 'ns-c.example-dns.test', 'ipv6' => '2001:db8::53'],                  // فقط IPv6 ⇒ قابلِ پرسش
        ]);
        $this->assertSame([], $ok['errors']);
        $this->assertSame([5301, 5302, 53], array_column($ok['nameservers'], 'port'));
    }

    public function test_unusable_nameserver_addresses_are_rejected(): void
    {
        foreach (['0.0.0.0', '0.1.2.3', '127.0.0.1', '127.255.0.9', '255.255.255.255', '224.0.0.1', '240.0.0.1', '1.2.3', '01.2.3.4'] as $bad) {
            $res = DnsConfig::validateNameservers([['host' => 'ns-a.example-dns.test', 'ipv4' => $bad]]);
            $this->assertNull($res['nameservers'][0]['ipv4'], $bad);
            $this->assertSame('ns_ipv4_invalid', $res['errors'][0]['code'], $bad);
        }

        foreach (['::', '::1', 'ff02::1', 'fe80::1', 'febf::1', '::ffff:192.0.2.1', '2001:db8::zz'] as $bad) {
            $res = DnsConfig::validateNameservers([['host' => 'ns-a.example-dns.test', 'ipv6' => $bad]]);
            $this->assertNull($res['nameservers'][0]['ipv6'], $bad);
            $this->assertSame('ns_ipv6_invalid', $res['errors'][0]['code'], $bad);
        }

        foreach (['192.0.2.10' => 'ipv4', '10.0.0.53' => 'ipv4', '1.1.1.1' => 'ipv4', '223.255.255.254' => 'ipv4',
            '2001:DB8::53' => 'ipv6', '2a01:4f8::1' => 'ipv6'] as $good => $field) {
            $res = DnsConfig::validateNameservers([['host' => 'ns-a.example-dns.test', $field => $good]]);
            $this->assertNotNull($res['nameservers'][0][$field], $good);
        }
    }

    public function test_service_closes_when_any_configured_nameserver_is_unusable(): void
    {
        $this->openService();

        // شواهدِ بازبینی: دو نامِ IPیِ «معتبر» + نودِ سومِ بی‌آدرس ⇒ قبلاً «باز»
        Setting::put('dns_nameservers', json_encode([
            ['host' => '192.0.2.1', 'ipv4' => '192.0.2.1'],
            ['host' => '10.0.0.53', 'ipv4' => '127.0.0.1'],
            ['host' => 'ns-c.example-dns.test', 'ipv4' => '192.0.2.300'],
        ]));
        $this->assertFalse(DnsConfig::serviceOpen());
        $this->assertSame(['ns-c.example-dns.test'], DnsConfig::nameserverHosts());

        // دو نودِ خوب + نودِ سومِ بی‌آدرس: هرگز «منتشر روی همه» ⇒ سرویس بسته
        $good = [
            ['host' => 'ns-a.example-dns.test', 'ipv4' => '192.0.2.10'],
            ['host' => 'ns-b.example-dns.test', 'ipv4' => '198.51.100.20'],
        ];
        Setting::put('dns_nameservers', json_encode([...$good, ['host' => 'ns-c.example-dns.test', 'ipv4' => '192.0.2.300']]));
        $this->assertFalse(DnsConfig::serviceOpen());
        $this->assertSame([['index' => 2, 'field' => 'ipv4', 'code' => 'ns_ipv4_invalid']], DnsConfig::nameserverErrors());

        Setting::put('dns_nameservers', json_encode([...$good, ['host' => 'ns-c.example-dns.test']]));
        $this->assertFalse(DnsConfig::serviceOpen());
        $this->assertSame('ns_no_address', DnsConfig::nameserverErrors()[0]['code']);

        // هر ایرادِ دیگر هم می‌بندد — Setting حرفِ آخر است، نه حدسِ «بقیه‌اش خوب است»
        Setting::put('dns_nameservers', json_encode([...$good, ['host' => 'ns-a.example-dns.test', 'ipv4' => '192.0.2.12']]));
        $this->assertFalse(DnsConfig::serviceOpen());
        Setting::put('dns_nameservers', json_encode([...$good, ['host' => 'ns-c.example-dns.test', 'ipv4' => '192.0.2.30', 'port' => 70000]]));
        $this->assertFalse(DnsConfig::serviceOpen());
        Setting::put('dns_nameservers', '{not json');
        $this->assertSame([['index' => null, 'field' => 'nameservers', 'code' => 'ns_json_invalid']], DnsConfig::nameserverErrors());
        $this->assertFalse(DnsConfig::serviceOpen());

        // نودِ سومِ فقط-IPv6 قابلِ پرسش است ⇒ باز
        Setting::put('dns_nameservers', json_encode([...$good, ['host' => 'ns-c.example-dns.test', 'ipv6' => '2001:db8::53']]));
        $this->assertSame([], DnsConfig::nameserverErrors());
        $this->assertTrue(DnsConfig::serviceOpen());
    }

    public function test_bad_nameserver_json_means_empty_not_a_fallback(): void
    {
        config(['edge.dns.nameservers' => [['host' => 'cfg.example-dns.test', 'ipv4' => '192.0.2.5']]]);

        foreach (['{not json', '"a string"', '{"host":"x.example.test"}', 'dns1.example-ns.net,dns2.example-ns.net', '[]', 'null'] as $bad) {
            Setting::put('dns_nameservers', $bad);
            $this->assertSame([], DnsConfig::nameservers(), 'Settingِ خراب به config برگشت: '.$bad);
        }

        // Settingِ نبود ⇒ config
        Setting::put('dns_nameservers', null);
        $this->assertSame(['cfg.example-dns.test'], DnsConfig::nameserverHosts());
    }

    public function test_no_nameserver_is_invented_and_old_key_is_not_the_source(): void
    {
        $this->assertSame([], config('edge.dns.nameservers'), 'پیش‌فرضِ config نباید نامِ حدسی داشته باشد');
        $this->assertArrayHasKey('nameservers', config('edge'), 'کلیدِ قدیمی برای سازگاری می‌ماند');

        config(['edge.nameservers' => ['legacy-a.example.test', 'legacy-b.example.test']]);
        Setting::put('dns_nameservers', null);

        $this->assertSame([], DnsConfig::nameservers());
        $this->assertSame([], CdnZone::ourNameservers());
    }

    public function test_reserved_names_and_defaults(): void
    {
        $this->assertEqualsCanonicalizing(
            ['ns1.servernet.cloud', 'ns2.servernet.cloud', 'ns1.servernet.ir', 'ns2.servernet.ir'],
            DnsConfig::reserved()
        );

        $this->assertSame('catalog.servernet.invalid', DnsConfig::catalog());
        $this->assertSame('xfr-servernet', DnsConfig::tsigKey());
        $this->assertSame('localhost', DnsConfig::serverId());

        Setting::put('dns_catalog_zone', 'Catalog.Invalid.');
        Setting::put('dns_xfr_tsig_key', 'xfr-lab');
        $this->assertSame('catalog.invalid', DnsConfig::catalog());
        $this->assertSame('xfr-lab', DnsConfig::tsigKey());

        // مقدارِ بد در Setting ⇒ پیش‌فرض، نه رشتهٔ خام در API
        Setting::put('dns_catalog_zone', 'bad zone/../');
        $this->assertSame('catalog.servernet.invalid', DnsConfig::catalog());

        $soa = DnsConfig::soa();
        $this->assertSame(2419200, $soa['expire']);
        $this->assertSame(['email' => 'hostmaster.servernet.cloud', 'refresh' => 3600, 'retry' => 600, 'expire' => 2419200, 'minimum' => 300], $soa);
        $this->assertSame(2419200, config('edge.dns.soa.expire'));

        $this->assertSame(50, DnsConfig::limit('zones_per_customer'));
        $this->assertSame(500, DnsConfig::limit('records_per_zone'));
        $this->assertSame(20, DnsConfig::limit('pending_claims_per_customer'));
        $this->assertSame(20, DnsConfig::limit('claims'));
        $this->assertSame(4, DnsConfig::connectTimeout());
        $this->assertSame(8, DnsConfig::timeout());

        config(['edge.dns.limits.records_per_zone' => 10]);
        $this->assertSame(10, DnsConfig::limit('records_per_zone'));

        $this->expectException(\InvalidArgumentException::class);
        DnsConfig::limit('typo_limit');
    }

    public function test_api_url_https_rule(): void
    {
        config(['edge.dns.allow_insecure_api' => false]);

        $this->assertTrue(DnsConfig::urlIsAcceptable('https://pdns.example.test:8081'));
        $this->assertTrue(DnsConfig::urlIsAcceptable('HTTPS://203.0.113.5'));
        $this->assertFalse(DnsConfig::urlIsAcceptable('http://127.0.0.1:8081'), 'http بی‌پرچم پذیرفته شد');
        $this->assertFalse(DnsConfig::urlIsAcceptable('http://10.0.0.9:8081'));
        $this->assertFalse(DnsConfig::urlIsAcceptable(null));
        $this->assertFalse(DnsConfig::urlIsAcceptable(''));
        $this->assertFalse(DnsConfig::urlIsAcceptable('https://user:pass@pdns.example.test'), 'اعتبارنامه در URL');
        $this->assertFalse(DnsConfig::urlIsAcceptable('ftp://pdns.example.test'));
        $this->assertFalse(DnsConfig::urlIsAcceptable('pdns.example.test'));
        $this->assertFalse(DnsConfig::urlIsAcceptable("https://pdns.example.test\n"));

        config(['edge.dns.allow_insecure_api' => true]);

        foreach ([
            'http://127.0.0.1:8081', 'http://127.9.9.9', 'http://localhost:8081', 'http://[::1]:8081',
            'http://10.0.0.9:8081/', 'http://172.16.0.1', 'http://172.31.255.254', 'http://192.168.1.5',
            'http://169.254.10.10', 'http://[fe80::1]', 'http://[::ffff:10.1.2.3]',
        ] as $ok) {
            $this->assertTrue(DnsConfig::urlIsAcceptable($ok), $ok);
        }

        foreach ([
            'http://8.8.8.8', 'http://172.32.0.1', 'http://172.15.255.255', 'http://192.169.0.1',
            'http://[2001:db8::1]', 'http://pdns.internal', 'http://localhost.example.test', 'http://[::ffff:8.8.8.8]',
        ] as $bad) {
            $this->assertFalse(DnsConfig::urlIsAcceptable($bad), $bad);
        }

        Setting::put('pdns_api_url', 'http://10.0.0.9:8081/');
        $this->assertSame('http://10.0.0.9:8081', DnsConfig::apiUrl());
        $this->assertTrue(DnsConfig::apiUrlIsAcceptable());

        config(['edge.dns.allow_insecure_api' => false]);
        $this->assertFalse(DnsConfig::apiUrlIsAcceptable());
    }

    public function test_setting_overrides_config_for_flag_and_url(): void
    {
        config(['edge.dns.enabled' => false, 'edge.dns.api_url' => 'https://from-config.example.test']);

        $this->assertFalse(DnsConfig::enabled());
        $this->assertSame('https://from-config.example.test', DnsConfig::apiUrl());

        Setting::put('dns_managed_enabled', '1');
        Setting::put('pdns_api_url', 'https://from-setting.example.test/');
        $this->assertTrue(DnsConfig::enabled());
        $this->assertSame('https://from-setting.example.test', DnsConfig::apiUrl());

        Setting::put('dns_managed_enabled', '0');
        config(['edge.dns.enabled' => true]);
        $this->assertFalse(DnsConfig::enabled(), 'Settingِ صریحِ «خاموش» بر config مقدم است');

        Setting::put('dns_managed_enabled', 'maybe');
        $this->assertFalse(DnsConfig::enabled(), 'مقدارِ نامفهوم یعنی خاموش');

        $this->assertNull(DnsConfig::apiKey());
        Setting::putSecret('pdns_api_key', '  k-123  ');
        $this->assertSame('k-123', DnsConfig::apiKey());
    }

    public function test_service_open_requires_flag_url_key_and_two_ipv4_nameservers(): void
    {
        $this->assertFalse(DnsConfig::serviceOpen());

        $this->openService();
        $this->assertTrue(DnsConfig::serviceOpen());
        $this->assertTrue(CdnZone::serviceOpen());

        Setting::put('dns_managed_enabled', '0');
        $this->assertFalse(CdnZone::serviceOpen(), 'پرچمِ خاموش');
        Setting::put('dns_managed_enabled', '1');

        Setting::putSecret('pdns_api_key', null);
        $this->assertFalse(CdnZone::serviceOpen(), 'بی‌کلید');
        Setting::putSecret('pdns_api_key', 'k');

        Setting::put('pdns_api_url', 'http://203.0.113.9:8081');
        $this->assertFalse(CdnZone::serviceOpen(), 'آدرسِ ناامن');
        Setting::put('pdns_api_url', null);
        config(['edge.dns.api_url' => null]);
        $this->assertFalse(CdnZone::serviceOpen(), 'بی‌آدرس');
        Setting::put('pdns_api_url', 'https://pdns-primary.example.test');

        Setting::put('dns_nameservers', json_encode([
            ['host' => 'ns-a.example-dns.test', 'ipv4' => '192.0.2.10'],
            ['host' => 'ns-b.example-dns.test', 'ipv6' => '2001:db8::53'],
        ]));
        $this->assertFalse(CdnZone::serviceOpen(), 'یکی از دو نیم‌سرور IPv4 ندارد');

        Setting::put('dns_nameservers', json_encode([
            ['host' => 'ns-a.example-dns.test', 'ipv4' => '192.0.2.10'],
            ['host' => 'ns1.servernet.cloud', 'ipv4' => '65.109.176.14'],
        ]));
        $this->assertFalse(CdnZone::serviceOpen(), 'نیم‌سرورِ رزروشده شمرده شد');

        $this->twoNameservers();
        $this->assertTrue(CdnZone::serviceOpen());
    }

    public function test_config_outputs_never_carry_the_api_key(): void
    {
        $this->openService();

        $dump = json_encode([
            DnsConfig::nameservers(), DnsConfig::soa(), DnsConfig::catalog(), DnsConfig::tsigKey(),
            DnsConfig::apiUrl(), DnsConfig::reserved(), config('edge.dns'),
        ]);

        $this->assertStringNotContainsString('secret-key-never-shown', (string) $dump);
    }

    /* ═══════════════════════════ CdnEvent ═══════════════════════════ */

    public function test_audit_stores_code_params_actor_and_ip(): void
    {
        $z = $this->zone(null, ['domain' => 'audited.example']);

        CdnEvent::audit('record_added', $z, ['type' => 'A', 'name' => 'www'], 'customer:'.$z->customer_id, '203.0.113.7');

        $e = CdnEvent::audits()->latest('id')->first();
        $this->assertNotNull($e);
        $this->assertSame(CdnEvent::AUDIT, $e->type);
        $this->assertSame('record_added', $e->message, 'پیام باید کد باشد، نه متن');
        $this->assertSame($z->id, (int) $e->cdn_zone_id);
        $this->assertSame(['type' => 'A', 'name' => 'www', 'domain' => 'audited.example'], $e->payload);
        $this->assertSame('customer:'.$z->customer_id, $e->actor);
        $this->assertSame('203.0.113.7', $e->ip);

        // IPv6 کامل، بازیگرِ بلند، IPِ نامعتبر
        CdnEvent::audit('settings_changed', null, [], 'admin:'.str_repeat('9', 60), '2001:0db8:0000:0000:0000:ff00:0042:8329');
        $e = CdnEvent::audits()->latest('id')->first();
        $this->assertNull($e->cdn_zone_id);
        $this->assertSame(40, mb_strlen($e->actor));
        $this->assertSame('2001:0db8:0000:0000:0000:ff00:0042:8329', $e->ip);

        CdnEvent::audit('zone_deleted', $z, ['domain' => 'kept.example'], 'system', 'not-an-ip');
        $e = CdnEvent::audits()->latest('id')->first();
        $this->assertNull($e->ip);
        $this->assertSame('system', $e->actor);
        $this->assertSame('kept.example', $e->payload['domain'], 'پارامترِ صریح بازنویسی شد');
    }

    public function test_note_keeps_the_v1_signature(): void
    {
        $z = $this->zone();

        CdnEvent::note('dns', $z->id, 'legacy text', ['a' => 1]);
        CdnEvent::note('purge', $z->id, null, [], 42);
        CdnEvent::note('dns', $z->id, 'with actor', [], null, 'admin:1', '10.0.0.1');

        $rows = CdnEvent::where('cdn_zone_id', $z->id)->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['a' => 1], $rows[0]->payload);
        $this->assertNull($rows[0]->actor);
        $this->assertSame(42, (int) $rows[1]->cdn_node_id);
        $this->assertNull($rows[1]->payload);
        $this->assertSame('admin:1', $rows[2]->actor);
        $this->assertSame('10.0.0.1', $rows[2]->ip);
    }

    public function test_audit_survives_unmigrated_columns_and_missing_table(): void
    {
        $z = $this->zone();

        // v1 بدونِ ستون‌های v2: «چه کسی» در payload نگه داشته می‌شود، ردیف گم نمی‌شود
        Schema::table('cdn_events', fn (Blueprint $t) => $t->dropColumn(['actor', 'ip']));

        CdnEvent::audit('zone_added', $z, ['x' => 1], 'customer:5', '198.51.100.4');
        $row = DB::table('cdn_events')->latest('id')->first();
        $this->assertNotNull($row);
        $payload = json_decode((string) $row->payload, true);
        $this->assertSame('customer:5', $payload['_actor']);
        $this->assertSame('198.51.100.4', $payload['_ip']);
        $this->assertSame(1, $payload['x']);

        // بی‌جدول: هرگز استثنا نه
        Schema::drop('cdn_events');

        CdnEvent::audit('zone_added', $z, [], 'customer:5', '198.51.100.4');
        CdnEvent::audit('zone_added', null);
        CdnEvent::note('dns', $z->id, 'x', ['y' => 2], null, 'system', '127.0.0.1');

        $this->assertFalse(Schema::hasTable('cdn_events'));
    }
}
