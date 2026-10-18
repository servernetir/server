<?php

namespace Tests\Unit\Dns;

use App\Services\Dns\RecordValidator;
use App\Services\Dns\ZoneFile\ZoneFileParser;
use App\Services\Dns\ZoneFile\ZoneFileWriter;
use Tests\TestCase;

/**
 * اعتبارسنجِ رکورد v2، تجزیه‌گر و نویسندهٔ فایلِ zone — و ویژگیِ رفت‌وبرگشت.
 */
class ZoneFileAndValidatorTest extends TestCase
{
    private RecordValidator $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = new RecordValidator;
    }

    /* ═════════════ validate() ═════════════ */

    public static function records(): array
    {
        return [
            'A apex'             => [['type' => 'A', 'name' => '@', 'content' => '185.10.20.30'], null, ['name' => 'example.com']],
            'A relative'         => [['type' => 'A', 'name' => 'www', 'content' => '185.10.20.30'], null, ['name' => 'www.example.com']],
            'A fqdn dot'         => [['type' => 'A', 'name' => 'api.example.com.', 'content' => '185.10.20.30'], null, ['name' => 'api.example.com']],
            'A outside zone'     => [['type' => 'A', 'name' => 'www.other.com.', 'content' => '185.10.20.30'], 'name', null],
            'A private'          => [['type' => 'A', 'name' => 'x', 'content' => '192.168.1.5'], 'content', null],
            'A cgnat'            => [['type' => 'A', 'name' => 'x', 'content' => '100.64.1.1'], 'content', null],
            'A multicast'        => [['type' => 'A', 'name' => 'x', 'content' => '239.1.1.1'], 'content', null],
            'AAAA canonical'     => [['type' => 'AAAA', 'name' => 'x', 'content' => '2A01:4F8:0:0:0:0:0:1'], null, ['content' => '2a01:4f8::1']],
            'AAAA link-local'    => [['type' => 'AAAA', 'name' => 'x', 'content' => 'fe80::1'], 'content', null],
            'wildcard'           => [['type' => 'A', 'name' => '*', 'content' => '185.10.20.30'], null, ['name' => '*.example.com']],
            'wildcard sub'       => [['type' => 'A', 'name' => '*.dev', 'content' => '185.10.20.30'], null, ['name' => '*.dev.example.com']],
            'wildcard middle'    => [['type' => 'A', 'name' => 'a.*', 'content' => '185.10.20.30'], 'name', null],
            'idn label'          => [['type' => 'A', 'name' => 'فروشگاه', 'content' => '185.10.20.30'], null, ['name' => 'xn--mgbtj4c7ad63e.example.com']],
            'underscore in A'    => [['type' => 'A', 'name' => 'a_b', 'content' => '185.10.20.30'], 'name', null],
            'CNAME apex'         => [['type' => 'CNAME', 'name' => '@', 'content' => 'x.net'], 'name', null],
            'CNAME url'          => [['type' => 'CNAME', 'name' => 'blog', 'content' => 'https://host.net/'], 'content', null],
            'CNAME ip'           => [['type' => 'CNAME', 'name' => 'blog', 'content' => '1.2.3.4'], 'content', null],
            'CNAME dkim'         => [['type' => 'CNAME', 'name' => 's1._domainkey', 'content' => 's1.domainkey.u1.wl.sendgrid.net.'], null, ['content' => 's1.domainkey.u1.wl.sendgrid.net']],
            'MX ok'              => [['type' => 'MX', 'name' => '@', 'content' => 'ASPMX.L.GOOGLE.COM.', 'prio' => 1], null, ['content' => 'aspmx.l.google.com', 'prio' => 1]],
            'MX ip'              => [['type' => 'MX', 'name' => '@', 'content' => '1.2.3.4', 'prio' => 10], 'content', null],
            'MX null'            => [['type' => 'MX', 'name' => '@', 'content' => '.', 'prio' => 0], null, ['content' => '.']],
            'MX bad prio'        => [['type' => 'MX', 'name' => '@', 'content' => 'mx.a.com', 'prio' => 70000], 'prio', null],
            'TXT plain'          => [['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 -all'], null, ['content' => '"v=spf1 -all"']],
            'TXT backslash'      => [['type' => 'TXT', 'name' => '@', 'content' => 'a\\b'], 'content', null],
            'NS apex'            => [['type' => 'NS', 'name' => '@', 'content' => 'ns.x.net'], 'name', null],
            'NS sub'             => [['type' => 'NS', 'name' => 'lab', 'content' => 'ns.x.net'], null, ['name' => 'lab.example.com']],
            'SRV ok'             => [['type' => 'SRV', 'name' => '_sip._tcp', 'prio' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.example.com'], null, ['content' => '5 5060 sip.example.com', 'prio' => 10]],
            'SRV bad owner'      => [['type' => 'SRV', 'name' => 'sip', 'prio' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'a.b.com'], 'name', null],
            'SRV dot target'     => [['type' => 'SRV', 'name' => '_x._udp', 'prio' => 0, 'weight' => 0, 'port' => 0, 'target' => '.'], null, ['content' => '0 0 .']],
            'CAA issue'          => [['type' => 'CAA', 'name' => '@', 'flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'], null, ['content' => '0 issue "letsencrypt.org"']],
            'CAA issue none'     => [['type' => 'CAA', 'name' => '@', 'flags' => 0, 'tag' => 'issue', 'value' => ';'], null, ['content' => '0 issue ";"']],
            'CAA iodef'          => [['type' => 'CAA', 'name' => '@', 'flags' => 128, 'tag' => 'iodef', 'value' => 'mailto:sec@example.com'], null, null],
            'CAA quote in value' => [['type' => 'CAA', 'name' => '@', 'flags' => 0, 'tag' => 'issue', 'value' => 'a"b.org'], 'value', null],
            'TTL low'            => [['type' => 'A', 'name' => 'x', 'content' => '185.10.20.30', 'ttl' => 10], 'ttl', null],
            'type unsupported'   => [['type' => 'PTR', 'name' => 'x', 'content' => 'a.b'], 'type', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('records')]
    public function test_validate(array $in, ?string $badField, ?array $expect): void
    {
        $r = $this->v->validate('example.com', $in + ['ttl' => 3600]);

        if ($badField !== null) {
            $this->assertFalse($r['ok'], 'should fail on '.$badField);
            $this->assertArrayHasKey($badField, $r['errors']);
            $this->assertContains($r['errors'][$badField]['code'], RecordValidator::ERROR_CODES);

            return;
        }
        $this->assertTrue($r['ok'], json_encode($r['errors']));
        foreach ((array) $expect as $k => $val) {
            $this->assertSame($val, $r['data'][$k], $k);
        }
    }

    /* ═════════════ conflicts() / ttlSiblings() ═════════════ */

    private function rec(int $id, string $type, string $name, string $content, int $ttl = 3600, ?int $prio = null): array
    {
        return compact('id', 'type', 'name', 'content', 'ttl', 'prio');
    }

    public function test_cname_is_exclusive_and_duplicates_are_rejected(): void
    {
        $existing = [$this->rec(1, 'A', 'www.example.com', '185.10.20.30')];

        $cname = $this->v->validate('example.com', ['type' => 'CNAME', 'name' => 'www', 'content' => 'x.net'])['data'];
        $this->assertSame('cname_conflict', $this->v->conflicts($cname, $existing)['code']);

        $a = $this->v->validate('example.com', ['type' => 'A', 'name' => 'www', 'content' => '185.10.20.30'])['data'];
        $this->assertSame('duplicate', $this->v->conflicts($a, $existing)['code']);

        // ویرایشِ همان رکورد: خودش را تکراری نمی‌بیند
        $this->assertNull($this->v->conflicts($a, $existing, ignoreId: 1));

        $second = $this->v->validate('example.com', ['type' => 'A', 'name' => 'www', 'content' => '185.10.20.31'])['data'];
        $this->assertNull($this->v->conflicts($second, $existing), 'multi-record RRset is fine');

        $onCname = $this->v->validate('example.com', ['type' => 'TXT', 'name' => 'blog', 'content' => 'x'])['data'];
        $this->assertSame('cname_exists', $this->v->conflicts($onCname, [$this->rec(2, 'CNAME', 'blog.example.com', 'h.net')])['code']);
    }

    public function test_delegation_occludes_everything_but_glue(): void
    {
        $ns = [$this->rec(1, 'NS', 'lab.example.com', 'ns1.lab.example.com'), $this->rec(2, 'NS', 'lab.example.com', 'ns.other.net')];

        $glue = $this->v->validate('example.com', ['type' => 'A', 'name' => 'ns1.lab', 'content' => '185.10.20.30'])['data'];
        $this->assertNull($this->v->conflicts($glue, $ns), 'glue for an in-zone NS target is allowed');

        $hidden = $this->v->validate('example.com', ['type' => 'A', 'name' => 'web.lab', 'content' => '185.10.20.30'])['data'];
        $this->assertSame('delegated_name', $this->v->conflicts($hidden, $ns)['code']);

        $atCut = $this->v->validate('example.com', ['type' => 'TXT', 'name' => 'lab', 'content' => 'x'])['data'];
        $this->assertSame('delegated_name', $this->v->conflicts($atCut, $ns)['code']);

        $newNs = $this->v->validate('example.com', ['type' => 'NS', 'name' => 'shop', 'content' => 'ns.x.net'])['data'];
        $this->assertSame('ns_occludes', $this->v->conflicts($newNs, [$this->rec(3, 'A', 'api.shop.example.com', '185.10.20.30')])['code']);
    }

    public function test_ttl_belongs_to_the_rrset(): void
    {
        $existing = [$this->rec(1, 'A', 'www.example.com', '185.10.20.30', 3600), $this->rec(2, 'A', 'www.example.com', '185.10.20.31', 3600), $this->rec(3, 'TXT', 'www.example.com', '"x"', 60)];
        $new = $this->v->validate('example.com', ['type' => 'A', 'name' => 'www', 'content' => '185.10.20.32', 'ttl' => 300])['data'];

        $this->assertEqualsCanonicalizing([1, 2], $this->v->ttlSiblings($new, $existing));
    }

    /* ═════════════ parser ═════════════ */

    private function parse(string $text, string $origin = 'example.com')
    {
        return (new ZoneFileParser)->parse($text, $origin);
    }

    public function test_parses_a_realistic_provider_export(): void
    {
        $zone = <<<'ZONE'
$ORIGIN example.com.
$TTL 1h
@   IN  SOA ns1.provider.net. hostmaster.provider.net. (
            2026101801 ; serial
            3600 600 1209600 300 )
@               IN NS    ns1.provider.net.
@               IN NS    ns2.provider.net.
@          300  IN A     185.10.20.30
www             IN CNAME @
                IN TXT   "on www? no - blank owner is the previous owner"
mail       IN   A        185.10.20.31
@               MX 10    mail
@               MX 20    mx2.backup.net.
@               TXT      "v=spf1 mx include:_spf.google.com ~all"
_dmarc          TXT      "v=DMARC1; p=quarantine; rua=mailto:d@example.com"
k1._domainkey   TXT      ( "v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA"
                           "mOBoVgaZ0" )    ; dkim split across lines
_sip._tcp       SRV      10 5 5060 sip
@               CAA      0 issue "letsencrypt.org"
lab             NS       ns1.lab
ns1.lab         A        185.10.20.40
old             SPF      "v=spf1 -all"
@               DNSKEY   257 3 13 abc
host            PTR      x.example.com.
note            TXT      "semi;colon inside quotes"
ZONE;
        $p = $this->parse($zone);
        $by = fn ($status) => array_values(array_filter($p->rows, fn ($r) => $r['status'] === $status));

        $parsed = $by('parsed');
        $this->assertCount(14, $parsed, json_encode(array_map(fn ($r) => [$r['line'], $r['type'], $r['name'], $r['code']], $p->rows)));
        $this->assertSame(['soa_managed', 'apex_ns_managed', 'apex_ns_managed', 'dnssec_unsupported'], array_column($by('skipped'), 'code'));
        $this->assertEqualsCanonicalizing(['spf_type', 'type_unsupported'], array_column($by('unsupported'), 'code'));

        $find = fn ($type, $name) => array_values(array_filter($parsed, fn ($r) => $r['type'] === $type && $r['name'] === $name))[0] ?? null;
        $this->assertSame(300, $find('A', 'example.com')['ttl']);
        $this->assertSame(3600, $find('A', 'mail.example.com')['ttl'], '$TTL 1h applies');
        $this->assertSame('example.com.', $find('CNAME', 'www.example.com')['input']['content']);
        $this->assertNotNull($find('TXT', 'www.example.com'), 'blank owner = previous owner');
        $this->assertSame('mail.example.com.', $find('MX', 'example.com')['input']['content'] ?? null);

        // هر ردیفِ تجزیه‌شده باید از همان اعتبارسنجِ فرم رد شود
        foreach ($parsed as $row) {
            $r = $this->v->validate('example.com', $row['input']);
            $this->assertTrue($r['ok'], 'line '.$row['line'].': '.json_encode($r['errors']));
        }
        $dkim = $this->v->validate('example.com', $find('TXT', 'k1._domainkey.example.com')['input'])['data']['content'];
        $this->assertSame('"v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA" "mOBoVgaZ0"', $dkim);
        $note = $this->v->validate('example.com', $find('TXT', 'note.example.com')['input'])['data']['content'];
        $this->assertSame('"semi;colon inside quotes"', $note);
    }

    public function test_bad_input_never_throws_and_reports_lines(): void
    {
        $p = $this->parse("good A 185.10.20.30\nbad IN XYZ 1\nopen TXT \"unterminated\nx CH A 1.2.3.4\n\$INCLUDE /etc/passwd\ny.other.com. A 185.10.20.30\n( )\n)");
        $codes = array_column($p->rows, 'code', 'line');
        $this->assertNull($codes[1]);
        $this->assertSame('type_syntax', $codes[2]);
        $this->assertSame('quote_unterminated', $codes[3]);
        $this->assertSame('class_unsupported', $codes[4]);
        $this->assertSame('directive_unsupported', $codes[5]);
        $this->assertSame('name_outside_zone', $codes[6]);
        $this->assertSame('paren_unbalanced', $codes[8]);
    }

    public function test_limits(): void
    {
        $big = (new ZoneFileParser(maxBytes: 100))->parse(str_repeat('a', 200), 'example.com');
        $this->assertSame('file_too_large', $big->fatal);

        $many = (new ZoneFileParser(maxLines: 3))->parse(str_repeat("x A 185.10.20.30\n", 10), 'example.com');
        $this->assertTrue($many->truncated);
        $this->assertCount(3, $many->parsed());
    }

    /* ═════════════ writer round-trip ═════════════ */

    public function test_export_then_import_gives_the_same_record_set(): void
    {
        $inputs = [
            ['type' => 'A', 'name' => '@', 'content' => '185.10.20.30', 'ttl' => 300],
            ['type' => 'A', 'name' => 'www', 'content' => '185.10.20.30'],
            ['type' => 'AAAA', 'name' => 'www', 'content' => '2a01:4f8::1'],
            ['type' => 'CNAME', 'name' => 'blog', 'content' => 'ghs.googlehosted.com'],
            ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com', 'prio' => 10],
            ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 mx -all'],
            ['type' => 'TXT', 'name' => 'k1._domainkey', 'content' => 'v=DKIM1; p='.str_repeat('B', 400)],
            ['type' => 'TXT', 'name' => 'utf', 'content' => 'سلام "دنیا" ✓'],
            ['type' => 'SRV', 'name' => '_sip._tcp', 'prio' => 10, 'weight' => 5, 'port' => 5060, 'target' => 'sip.example.com'],
            ['type' => 'CAA', 'name' => '@', 'flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
            ['type' => 'NS', 'name' => 'lab', 'content' => 'ns.other.net'],
        ];
        $records = [];
        foreach ($inputs as $i => $in) {
            $r = $this->v->validate('example.com', $in);
            $this->assertTrue($r['ok'], json_encode($r['errors']));
            $records[] = $r['data'] + ['id' => $i + 1];
        }

        $text = (new ZoneFileWriter)->write('example.com', $records, ['dns-a.example.net', 'dns-b.example.net'], 3600);
        $parsed = $this->parse($text);

        $back = [];
        foreach ($parsed->parsed() as $row) {
            $r = $this->v->validate('example.com', $row['input']);
            $this->assertTrue($r['ok'], $row['line'].': '.json_encode($r['errors']));
            $back[] = $r['data'];
        }
        $key = fn ($d) => implode('|', [$d['type'], $d['name'], $d['content'], $d['ttl'], (int) $d['prio']]);
        $this->assertEqualsCanonicalizing(array_map($key, $records), array_map($key, $back));
        $this->assertSame(2, count(array_filter($parsed->rows, fn ($r) => $r['code'] === 'apex_ns_managed')));
    }
}
