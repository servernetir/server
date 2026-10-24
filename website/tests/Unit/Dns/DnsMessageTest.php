<?php

namespace Tests\Unit\Dns;

use App\Services\Dns\Wire\DnsFailure;
use App\Services\Dns\Wire\DnsMessage;
use App\Services\Dns\Wire\DnsResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\Dns\FakeDnsServers as P;

/**
 * تجزیه‌گرِ wire — هر ورودی دشمن فرض می‌شود: نباید گیر کند، نباید استثنا بدهد،
 * و نباید دادهٔ بیرونِ بسته را بخواند.
 */
class DnsMessageTest extends TestCase
{
    private function parse(string $wire): DnsResponse|DnsFailure
    {
        return DnsMessage::parse($wire);
    }

    public function test_query_round_trip_and_flags(): void
    {
        $q = DnsMessage::query('Example.COM.', 'TXT', rd: false, edns: true, id: 77);
        $wire = $q->toWire();
        $this->assertSame(77, unpack('n', $wire)[1]);
        $this->assertSame(0, unpack('n', $wire, 2)[1] & 0x0100, 'RD must be off');
        $this->assertSame(1, unpack('n', $wire, 10)[1], 'one OPT record (EDNS0)');
    }

    public function test_parses_all_supported_rdata(): void
    {
        $w = P::packet('example.com', 'TXT', aa: true, answer: [
            ['example.com', 'A', '192.0.2.1'],
            ['example.com', 'AAAA', '2001:DB8:0:0:0:0:0:1'],
            ['example.com', 'MX', [10, 'mail.example.com']],
            ['example.com', 'TXT', ['v=spf1 -all', 'سلام ✓']],
            ['_sip._tcp.example.com', 'SRV', [10, 5, 5060, 'sip.example.com']],
            ['example.com', 'CAA', [0, 'issue', 'letsencrypt.org']],
            ['example.com', 'SOA', ['ns1.example.com', 'h.example.com', 4294967295, 3600, 600, 2419200, 300]],
        ]);
        $r = $this->parse($w);
        $this->assertInstanceOf(DnsResponse::class, $r);
        $this->assertTrue($r->aa);

        $by = fn ($t) => $r->records('answer', $t)[0]['data'];
        $this->assertSame('192.0.2.1', $by('A'));
        $this->assertSame('2001:db8::1', $by('AAAA'));
        $this->assertSame(['preference' => 10, 'exchange' => 'mail.example.com'], $by('MX'));
        $this->assertSame(['v=spf1 -all', 'سلام ✓'], $by('TXT'));
        $this->assertSame('sip.example.com', $by('SRV')['target']);
        $this->assertSame(4294967295, $by('SOA')['serial']);
    }

    public function test_compression_pointer_is_followed(): void
    {
        // answer owner = pointer to the question name at offset 12
        $w = pack('nnnnnn', 1, 0x8400, 1, 1, 0, 0)
            .DnsMessage::encodeName('example.com').pack('nn', 1, 1)
            ."\xC0\x0C".pack('nnNn', 1, 1, 60, 4).inet_pton('192.0.2.9');
        $r = $this->parse($w);
        $this->assertInstanceOf(DnsResponse::class, $r);
        $this->assertSame('example.com', $r->answers[0]['name']);
    }

    public static function hostile(): array
    {
        $hdr = fn (int $an) => pack('nnnnnn', 1, 0x8400, 1, $an, 0, 0);
        $q = DnsMessage::encodeName('a.test').pack('nn', 1, 1);

        return [
            'empty'                 => [''],
            'short header'          => ["\x00\x01\x84"],
            'pointer to itself'     => [$hdr(1).$q."\xC0\x18".pack('nnNn', 1, 1, 1, 4).'abcd'],
            'pointer loop pair'     => [$hdr(1).$q."\xC0\x1A\xC0\x18".pack('nnNn', 1, 1, 1, 4).'abcd'],
            'pointer past end'      => [$hdr(1).$q."\xC0\xFF".pack('nnNn', 1, 1, 1, 4).'abcd'],
            'label overruns packet' => [$hdr(0).chr(40).'short'],
            'rdlength overruns'     => [$hdr(1).$q."\xC0\x0C".pack('nnNn', 1, 1, 1, 200).'abcd'],
            'txt string overrun'    => [$hdr(1).$q."\xC0\x0C".pack('nnNn', 16, 1, 1, 3)."\x09ab"],
            'bad A length'          => [$hdr(1).$q."\xC0\x0C".pack('nnNn', 1, 1, 1, 3).'abc'],
            'answer count lies'     => [$hdr(5).$q],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostile')]
    public function test_hostile_packets_fail_cleanly(string $wire): void
    {
        $t = microtime(true);
        $r = $this->parse($wire);
        $this->assertLessThan(0.5, microtime(true) - $t, 'parser must not spin');
        $this->assertInstanceOf(DnsFailure::class, $r);
        $this->assertSame(DnsFailure::MALFORMED, $r->code);
    }

    public function test_response_id_and_question_must_match_the_query(): void
    {
        $q = DnsMessage::query('example.com', 'A', id: 100);

        $good = $this->parse(substr_replace(P::packet('example.com', 'A', aa: true), pack('n', 100), 0, 2));
        $this->assertNull($q->check($good));

        $wrongId = $this->parse(substr_replace(P::packet('example.com', 'A', aa: true), pack('n', 101), 0, 2));
        $this->assertSame(DnsFailure::ID_MISMATCH, $q->check($wrongId)?->code);

        $wrongName = $this->parse(substr_replace(P::packet('evil.com', 'A', aa: true), pack('n', 100), 0, 2));
        $this->assertNotNull($q->check($wrongName), 'answer for another question must be rejected (cache-poisoning shape)');
    }

    public function test_truncation_flag_is_exposed(): void
    {
        $r = $this->parse(P::packet('example.com', 'TXT', aa: true, tc: true));
        $this->assertTrue($r->tc);
    }
}
