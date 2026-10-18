<?php

namespace Tests\Feature\Dns;

use App\Services\Dns\DelegationInspector;
use Tests\Support\Dns\FakeDnsServers;
use Tests\TestCase;

/**
 * پیمایشِ تفویض از ریشه روی یک سلسله‌مراتبِ جعلی (بایتِ واقعیِ wire).
 *
 * ⚠️ این ایستگاه پورتِ ۵۳ را رهگیری می‌کند، پس پیمایشِ واقعی این‌جا آزمودنی
 * نیست؛ آزمونِ زنده باید روی سرورِ تولید با `php artisan dns:inspect` انجام شود.
 */
class DelegationInspectorTest extends TestCase
{
    private const ROOT = '192.0.2.1';
    private const TLD_A = '192.0.2.10';
    private const TLD_B = '192.0.2.11';
    private const AUTH1 = '198.51.100.1';
    private const AUTH2 = '198.51.100.2';

    private function world(array $override = []): FakeDnsServers
    {
        $f = new FakeDnsServers;
        $f->server(self::ROOT, ['.' => [
            ['com', 'NS', 'a.gtld.test'],
            ['com', 'NS', 'b.gtld.test'],
            ['a.gtld.test', 'A', self::TLD_A],
            ['b.gtld.test', 'A', self::TLD_B],
        ]]);
        $com = ['com' => array_merge([
            ['example.com', 'NS', 'ns1.example.com'],
            ['example.com', 'NS', 'ns.other.net'],
            ['ns1.example.com', 'A', self::AUTH1],
            ['signed.com', 'NS', 'ns1.example.com'],
            ['signed.com', 'DS', [12345, 13, 2, str_repeat('ab', 32)]],
        ], $override['com'] ?? [])];
        $f->server(self::TLD_A, $com, $override['tldA'] ?? []);
        $f->server(self::TLD_B, $com, $override['tldB'] ?? []);
        $zone = ['example.com' => [
            ['example.com', 'NS', 'ns1.example.com'],
            ['example.com', 'NS', 'ns.other.net'],
            ['_servernet-challenge.example.com', 'TXT', ['servernet-domain-verification=abc']],
            ['ns1.example.com', 'A', self::AUTH1],
        ]];
        $f->server(self::AUTH1, $zone, $override['auth1'] ?? []);
        $f->server(self::AUTH2, $zone, $override['auth2'] ?? []);

        return $f;
    }

    private function inspector(FakeDnsServers $f): DelegationInspector
    {
        return new DelegationInspector(
            wire: $f,
            rootHints: [['host' => 'root.test', 'ip' => self::ROOT]],
            fallbackResolver: fn (string $host) => $host === 'ns.other.net' ? [self::AUTH2] : [],
            queryTimeout: 0.5,
            budget: 10.0,
            allowPrivate: true,
        );
    }

    public function test_walks_from_root_to_the_parent_referral(): void
    {
        $r = $this->inspector($this->world())->parentDelegation('Example.COM.');

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('referral', $r['status']);
        $this->assertSame('com', $r['parent']);
        $this->assertEqualsCanonicalizing(['ns1.example.com', 'ns.other.net'], $r['ns']);
        $this->assertSame([], $r['ds']);

        // glue برای نامِ داخلِ bailiwick، و resolver فقط برای نامِ بیرونی
        $ips = array_column($r['servers'], 'ip', 'host');
        $this->assertSame(self::AUTH1, $ips['ns1.example.com']);
        $this->assertSame(self::AUTH2, $ips['ns.other.net']);
    }

    public function test_reads_ds_from_the_parent(): void
    {
        $r = $this->inspector($this->world())->parentDelegation('signed.com');

        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['ds']);
    }

    public function test_nxdomain_at_the_parent_is_a_definite_answer(): void
    {
        $r = $this->inspector($this->world())->parentDelegation('nope-404.com');

        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['ns']);
        $this->assertSame('nxdomain', $r['status']);
    }

    /** 🔴 همان وضعِ همین ایستگاه: رهگیرِ بازگشتی نباید «پاسخِ والد» خوانده شود */
    public function test_an_intercepting_recursive_resolver_is_never_trusted(): void
    {
        $f = $this->world();
        $f->server(self::ROOT, [], ['recursive' => true]);

        $r = $this->inspector($f)->parentDelegation('example.com');

        $this->assertFalse($r['ok']);
        $this->assertSame('intercepted', $r['error']);
        $this->assertSame([], $r['ns']);
    }

    public function test_one_parent_server_down_falls_back_to_the_other(): void
    {
        $r = $this->inspector($this->world(['tldA' => ['down' => true]]))->parentDelegation('example.com');

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertContains('ns1.example.com', $r['ns']);
    }

    /** «نتوانستیم بپرسیم» هرگز «تفویض نشده» نیست */
    public function test_all_parent_servers_down_is_a_lookup_failure_not_a_verdict(): void
    {
        $r = $this->inspector($this->world(['tldA' => ['down' => true], 'tldB' => ['down' => true]]))->parentDelegation('example.com');

        $this->assertFalse($r['ok']);
        $this->assertNotNull($r['error']);
        $this->assertSame([], $r['ns']);
    }

    public function test_authoritative_txt_is_read_directly_from_the_current_nameservers(): void
    {
        $f = $this->world(['auth2' => ['refuse' => true]]);
        $r = $this->inspector($f)->authoritativeTxt('example.com', '_servernet-challenge.example.com');

        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(['servernet-domain-verification=abc'], $r['values']);
        $this->assertGreaterThanOrEqual(1, $r['answered']);

        // هیچ پرس‌وجوی بازگشتی (RD) برای خودِ TXT نرفته
        $txt = array_filter($f->log, fn ($q) => $q['type'] === 'TXT');
        $this->assertNotEmpty($txt);
        foreach ($txt as $q) {
            $this->assertContains($q['ip'], [self::AUTH1, self::AUTH2]);
        }
    }

    public function test_authoritative_txt_missing_is_ok_with_no_values(): void
    {
        $r = $this->inspector($this->world())->authoritativeTxt('example.com', '_other.example.com');

        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['values']);
    }

    public function test_soa_at_reports_each_server_state(): void
    {
        $f = $this->world();
        $f->server('203.0.113.5', ['example.com' => [['example.com', 'SOA', ['ns1.example.com', 'h.example.com', 2026101801, 3600, 600, 2419200, 300]]]]);
        $f->server('203.0.113.6', [], ['refuse' => true]);
        $f->server('203.0.113.7', [], ['down' => true]);
        $i = $this->inspector($f);

        $ok = $i->soaAt('203.0.113.5', 53, 'example.com');
        $this->assertSame('answer', $ok['state']);
        $this->assertTrue($ok['aa']);
        $this->assertSame(2026101801, $ok['serial']);

        $this->assertSame('refused', $i->soaAt('203.0.113.6', 53, 'example.com')['state']);
        $this->assertSame('timeout', $i->soaAt('203.0.113.7', 53, 'example.com')['state']);
    }

    public static function classifications(): array
    {
        $ours = ['dns-a.example.net', 'dns-b.example.net'];

        return [
            'equal, case and dot'  => [['DNS-A.example.net.', 'dns-b.example.net'], $ours, 'delegated'],
            'one of ours only'     => [['dns-a.example.net', 'old.registrar.net'], $ours, 'partial'],
            'subset of ours'       => [['dns-a.example.net'], $ours, 'partial'],
            'none of ours'         => [['ns1.other.net'], $ours, 'not_delegated'],
            'empty parent answer'  => [[], $ours, 'not_delegated'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('classifications')]
    public function test_classify(array $parent, array $ours, string $expected): void
    {
        $this->assertSame($expected, DelegationInspector::classify($parent, $ours));
    }
}
