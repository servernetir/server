<?php

namespace Tests\Feature\Dns;

use App\Models\CdnRecord;
use App\Models\CdnZone;
use App\Models\Customer;
use App\Models\DnsOwnershipClaim;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dns\DelegationInspector;
use App\Services\Dns\OwnershipService;
use App\Services\Dns\RecordValidator;
use App\Services\Dns\TxtCodec;
use App\Services\Dns\ZonePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * لایهٔ HTTP: پرچمِ عرضه، IDOR روی همهٔ مسیرها، سه زبان، واردکردن، حذف، و پنلِ مدیر.
 */
class ManagedDnsHttpTest extends TestCase
{
    use RefreshDatabase;

    public static array $txt = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$txt = [];
        Setting::put('dns_managed_enabled', '1');
        Setting::put('pdns_api_url', 'https://dns-api.example.net');
        Setting::putSecret('pdns_api_key', 'TOP-SECRET-KEY');
        Setting::put('dns_nameservers', json_encode([
            ['host' => 'dns-a.example.net', 'ipv4' => '203.0.113.10', 'port' => 53],
            ['host' => 'dns-b.example.net', 'ipv4' => '203.0.113.20', 'port' => 53],
        ]));
        $this->app->instance(ZonePublisher::class, new class extends ZonePublisher {
            public function __construct() {}

            public function requestPublish(CdnZone $zone): void {}
        });
        $this->app->instance(DelegationInspector::class, new class extends DelegationInspector {
            public function authoritativeTxt(string $domain, string $name, ?array $delegation = null): array
            {
                return ['ok' => true, 'values' => ManagedDnsHttpTest::$txt[$name] ?? [], 'answered' => 1, 'error' => null];
            }

            public function checkDelegation(CdnZone $zone): array
            {
                $zone->forceFill(['delegation_state' => 'not_delegated', 'delegation_checked_at' => now()])->save();

                return ['state' => 'not_delegated', 'ns' => [], 'ds' => [], 'error' => null];
            }

            public function queryAuthoritative(string $domain, string $name, string $type, ?array $delegation = null): array
            {
                return ['ok' => false, 'rcode' => null, 'records' => [], 'server' => null, 'error' => 'x'];
            }
        });
    }

    private function customer(string $locale = 'fa'): Customer
    {
        return Customer::create(['email' => $locale.random_int(1, 1e9).'@x.test', 'phone' => '0912'.random_int(1000000, 9999999), 'password' => bcrypt('x'), 'status' => 'active', 'locale' => $locale])->refresh();
    }

    private function zone(Customer $c, array $attrs = []): CdnZone
    {
        return CdnZone::create($attrs + ['customer_id' => $c->id, 'domain' => 'z'.random_int(1000, 999999).'.example', 'mode' => 'ns', 'status' => 'pending', 'verify_token' => 'x', 'ownership' => 'txt']);
    }

    private function p(string $locale): string
    {
        return $locale === 'fa' ? '' : '/'.$locale;
    }

    /* ═════════════ پرچم ═════════════ */

    public function test_service_is_hidden_until_the_flag_and_connection_are_ready(): void
    {
        Setting::put('dns_managed_enabled', '0');
        $c = $this->customer();

        $this->actingAs($c, 'customer')->get('/account')->assertOk()->assertDontSee('/account/dns');
        $this->actingAs($c, 'customer')->get('/account/dns')->assertOk()->assertSee(__('dns.coming_soon_title'));
        $this->actingAs($c, 'customer')->post('/account/dns', ['domain' => 'early.example'])->assertNotFound();
        $this->assertSame(0, DnsOwnershipClaim::count());

        Setting::put('dns_managed_enabled', '1');
        $this->actingAs($c, 'customer')->get('/account')->assertSee('/account/dns', false);
    }

    /* ═════════════ جریانِ مشتری ═════════════ */

    public function test_add_prove_and_land_on_import(): void
    {
        $c = $this->customer();
        $r = $this->actingAs($c, 'customer')->post('/account/dns', ['domain' => 'Shop.Example']);
        $claim = DnsOwnershipClaim::firstOrFail();
        $r->assertRedirect(lroute('account.dns.claim', $claim->id));

        $page = $this->actingAs($c, 'customer')->get('/account/dns/claims/'.$claim->id)->assertOk();
        $page->assertSee('_servernet-challenge.shop.example')->assertSee('servernet-domain-verification='.$claim->token);

        $this->actingAs($c, 'customer')->post('/account/dns/claims/'.$claim->id.'/verify')->assertSessionHasErrors('claim');

        $ch = app(OwnershipService::class)->challenge($claim);
        self::$txt[$ch['name']] = [$ch['value']];
        $zone = null;
        $this->actingAs($c, 'customer')->post('/account/dns/claims/'.$claim->id.'/verify')
            ->assertRedirect()->assertSessionHas('ok');
        $zone = CdnZone::where('domain', 'shop.example')->firstOrFail();
        $this->assertSame($c->id, (int) $zone->customer_id);
    }

    /** 🔴 IDOR: هر مسیری که zone یا ادعای دیگری را نام ببرد 404 است و هیچ چیزی عوض نمی‌شود */
    public function test_every_endpoint_rejects_another_customers_zone_and_claim(): void
    {
        $owner = $this->customer();
        $attacker = $this->customer();
        $z = $this->zone($owner);
        $rec = $z->records()->create(['type' => 'A', 'name' => $z->domain, 'content' => '185.10.20.30', 'ttl' => 300]);
        $claim = DnsOwnershipClaim::create(['customer_id' => $owner->id, 'domain' => 'c.example', 'token' => str_repeat('a', 32), 'status' => 'pending', 'expires_at' => now()->addDay()]);

        $u = '/account/dns/'.$z->id;
        $gets = [$u, $u.'/export', $u.'/import', '/account/dns/claims/'.$claim->id];
        $posts = [
            $u.'/check' => [], $u.'/reviewed' => [], $u.'/delete' => ['confirm' => $z->domain],
            $u.'/records' => ['type' => 'A', 'name' => 'evil', 'content' => '185.10.20.66'],
            $u.'/records/'.$rec->id => ['type' => 'A', 'name' => '@', 'content' => '185.10.20.66'],
            $u.'/records/'.$rec->id.'/toggle' => [], $u.'/records/'.$rec->id.'/delete' => [],
            $u.'/import/preview' => ['source' => 'file', 'text' => 'x A 185.10.20.66'],
            $u.'/import/apply' => ['source' => 'file', 'text' => 'x A 185.10.20.66', 'lines' => [1]],
            '/account/dns/claims/'.$claim->id.'/verify' => [], '/account/dns/claims/'.$claim->id.'/cancel' => [],
        ];

        foreach ($gets as $path) {
            $this->actingAs($attacker, 'customer')->get($path)->assertNotFound();
        }
        foreach ($posts as $path => $body) {
            $this->actingAs($attacker, 'customer')->post($path, $body)->assertNotFound();
        }

        $this->assertSame(1, CdnRecord::count());
        $this->assertSame('185.10.20.30', $rec->fresh()->content);
        $this->assertFalse((bool) $rec->fresh()->disabled);
        $this->assertSame('pending', $z->fresh()->status);
        $this->assertTrue($claim->fresh()->isPending());
    }

    public function test_another_zones_record_id_through_my_zone_is_404(): void
    {
        $me = $this->customer();
        $mine = $this->zone($me);
        $other = $this->zone($this->customer());
        $theirs = $other->records()->create(['type' => 'A', 'name' => $other->domain, 'content' => '185.10.20.30', 'ttl' => 300]);

        $this->actingAs($me, 'customer')->post('/account/dns/'.$mine->id.'/records/'.$theirs->id.'/delete')->assertNotFound();
        $this->assertNotNull($theirs->fresh());
    }

    public static function locales(): array
    {
        return [['fa'], ['en'], ['tr']];
    }

    #[DataProvider('locales')]
    public function test_pages_render_in_every_language(string $locale): void
    {
        $c = $this->customer($locale);
        $z = $this->zone($c, ['domain' => 'render.example']);
        $z->records()->create(['type' => 'TXT', 'name' => 'render.example', 'content' => '"v=spf1 -all"', 'ttl' => 300]);
        $z->records()->create(['type' => 'MX', 'name' => 'render.example', 'content' => 'mail.render.example', 'prio' => 10, 'ttl' => 300]);
        $claim = DnsOwnershipClaim::create(['customer_id' => $c->id, 'domain' => 'claim.example', 'token' => str_repeat('b', 32), 'status' => 'pending', 'expires_at' => now()->addDay()]);
        $p = $this->p($locale);

        $pages = [
            $p.'/account/dns',
            $p.'/account/dns/claims/'.$claim->id,
            $p.'/account/dns/'.$z->id,
            $p.'/account/dns/'.$z->id.'/import',
        ];
        foreach ($pages as $path) {
            $html = $this->actingAs($c, 'customer')->get($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('dns.', $this->visible($html), "raw lang key on $path");
            if ($locale !== 'fa') {
                $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $this->visible($html), "$locale $path");
            }
        }

        $preview = $this->actingAs($c, 'customer')->post($p.'/account/dns/'.$z->id.'/import/preview', ['source' => 'file', 'text' => "www A 185.10.20.30\n@ NS ns.old.net.\nbad A 10.0.0.1\n"])->assertOk()->getContent();
        $this->assertStringContainsString(__('dns.import.status_new', [], $locale), $preview);
        if ($locale !== 'fa') {
            $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $this->visible($preview), "$locale preview");
        }
    }

    public function test_validation_errors_are_translated(): void
    {
        $c = $this->customer('en');
        $z = $this->zone($c);

        $this->actingAs($c, 'customer')->from('/en/account/dns/'.$z->id)
            ->post('/en/account/dns/'.$z->id.'/records', ['type' => 'A', 'name' => 'www', 'content' => '192.168.1.1', 'ttl' => 300])
            ->assertSessionHasErrors(['content' => __('dns.err.a_not_public', [], 'en')]);
    }

    public function test_import_apply_reparses_on_the_server(): void
    {
        $c = $this->customer();
        $z = $this->zone($c);
        $text = "www A 185.10.20.30\napi A 185.10.20.31\n";

        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/import/apply', ['source' => 'file', 'text' => $text, 'lines' => [2]])->assertRedirect();

        $this->assertSame(['api.'.$z->domain], CdnRecord::pluck('name')->all(), 'only the selected line');
    }

    public function test_delete_needs_the_typed_domain(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'del.example']);

        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/delete', ['confirm' => 'nope.example'])->assertSessionHasErrors('delete');
        $this->assertSame('pending', $z->fresh()->status);

        $this->actingAs($c, 'customer')->post('/account/dns/'.$z->id.'/delete', ['confirm' => 'DEL.example.'])->assertRedirect();
        $this->assertSame('deleting', $z->fresh()->status);
    }

    public function test_export_is_plain_text_and_not_cached(): void
    {
        $c = $this->customer();
        $z = $this->zone($c, ['domain' => 'exp.example']);
        $z->records()->create(['type' => 'A', 'name' => 'exp.example', 'content' => '185.10.20.30', 'ttl' => 300]);

        $r = $this->actingAs($c, 'customer')->get('/account/dns/'.$z->id.'/export')->assertOk();
        $this->assertStringContainsString('$ORIGIN exp.example.', $r->getContent());
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
    }

    /* ═════════════ مدیر ═════════════ */

    private function admin(): User
    {
        return User::create(['name' => 'مدیر', 'email' => 'dnsadm'.random_int(1, 1e6).'@example.test', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    public function test_admin_page_never_renders_the_api_key(): void
    {
        $this->zone($this->customer(), ['domain' => 'adm.example', 'publish_state' => 'failed', 'last_publish_error' => 'boom']);

        $html = $this->actingAs($this->admin())->get('/admin/dns?f=failed')->assertOk()->getContent();

        $this->assertStringContainsString('adm.example', $html);
        $this->assertStringNotContainsString('TOP-SECRET-KEY', $html);
    }

    public function test_admin_settings_validate_url_and_nameservers(): void
    {
        $a = $this->admin();
        $ns = [['host' => 'dns-a.example.net', 'ipv4' => '203.0.113.10', 'port' => '53'], ['host' => 'dns-b.example.net', 'ipv4' => '203.0.113.20', 'port' => '53']];
        $base = ['pdns_api_url' => 'https://dns-api.example.net', 'tsig_key' => 'xfr-servernet', 'catalog' => 'catalog.servernet.invalid', 'ns' => $ns, 'enabled' => '1'];

        $this->actingAs($a)->post('/admin/dns/settings', ['pdns_api_url' => 'http://dns-api.example.net'] + $base)->assertSessionHasErrors('pdns_api_url');
        $this->actingAs($a)->post('/admin/dns/settings', ['ns' => [['host' => 'ns1.servernet.cloud', 'ipv4' => '65.109.176.14'], $ns[1]]] + $base)->assertSessionHasErrors('ns');
        $this->actingAs($a)->post('/admin/dns/settings', ['ns' => [$ns[0]]] + $base)->assertSessionHasErrors('ns');

        $this->actingAs($a)->post('/admin/dns/settings', $base + ['pdns_api_key' => ''])->assertSessionHasNoErrors();
        $this->assertSame('TOP-SECRET-KEY', Setting::getSecret('pdns_api_key'), 'empty key = unchanged');
        $this->assertSame(['dns-a.example.net', 'dns-b.example.net'], \App\Services\Dns\DnsConfig::nameserverHosts());
    }

    public function test_admin_actions_on_a_zone(): void
    {
        $a = $this->admin();
        $z = $this->zone($this->customer(), ['domain' => 'act.example']);

        $this->actingAs($a)->post('/admin/dns/zones/'.$z->id.'/status', ['status' => 'suspended'])->assertRedirect();
        $this->assertSame('suspended', $z->fresh()->status);
        $this->actingAs($a)->post('/admin/dns/zones/'.$z->id.'/status', ['status' => 'active'])->assertRedirect();
        $this->assertSame('pending', $z->fresh()->status, 'never-delegated zone goes back to pending, not active');
        $this->actingAs($a)->post('/admin/dns/zones/'.$z->id.'/republish')->assertRedirect();
        $this->actingAs($a)->post('/admin/dns/zones/'.$z->id.'/delete', ['confirm' => 'act.example'])->assertRedirect();
        $this->assertSame('deleting', $z->fresh()->status);
    }

    /* ═════════════ ترجمه‌ها ═════════════ */

    public function test_lang_files_have_identical_keys_and_cover_every_code(): void
    {
        $flat = fn (string $l) => array_keys(\Illuminate\Support\Arr::dot(require lang_path($l.'/dns.php')));
        $fa = $flat('fa');
        $this->assertSame([], array_values(array_diff($fa, $flat('en'))), 'missing in en');
        $this->assertSame([], array_values(array_diff($fa, $flat('tr'))), 'missing in tr');
        $this->assertSame([], array_values(array_diff($flat('en'), $fa)), 'extra in en');
        $this->assertSame([], array_values(array_diff($flat('tr'), $fa)), 'extra in tr');

        foreach (['fa', 'en', 'tr'] as $l) {
            foreach (array_merge(RecordValidator::ERROR_CODES, TxtCodec::CODES) as $code) {
                $this->assertTrue(Lang::has('dns.err.'.$code, $l), "$l dns.err.$code");
            }
            foreach (OwnershipService::RESULTS as $code) {
                $this->assertTrue(Lang::has('dns.claim.'.$code, $l), "$l dns.claim.$code");
            }
            foreach (['origin_outside_zone', 'ttl_syntax', 'directive_unsupported', 'type_syntax', 'class_unsupported', 'soa_managed',
                'apex_ns_managed', 'dnssec_unsupported', 'spf_type', 'rdata_syntax', 'paren_unbalanced', 'quote_unterminated', 'file_too_large'] as $code) {
                $this->assertTrue(Lang::has('dns.import.'.$code, $l), "$l dns.import.$code");
            }
        }
    }

    private function visible(string $html): string
    {
        if (preg_match('~<main\b[^>]*>(.*?)</main>~si', $html, $m)) {
            $html = $m[1];
        }
        $html = preg_replace('~<!--.*?-->|<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>~si', '', $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
