<?php

namespace Tests\Support\Dns;

use App\Services\Dns\Wire\DnsFailure;
use App\Services\Dns\Wire\DnsMessage;
use App\Services\Dns\Wire\DnsResponse;
use App\Services\Dns\Wire\DnsWireClient;

/**
 * سلسله‌مراتبِ جعلیِ DNS برای تست — بی‌شبکه.
 *
 * چرا جعلی: شبکهٔ این ایستگاه هر بستهٔ پورتِ ۵۳ را رهگیری و بازگشتی جواب
 * می‌دهد (حتی برای IPِ ناموجود)، پس پیمایشِ واقعی از ریشه این‌جا آزمودنی
 * نیست. هر پاسخِ جعلی **بایتِ واقعیِ wire** است و از `DnsMessage::parse` رد
 * می‌شود؛ یعنی تجزیه‌گر هم در همین تست‌ها آزموده می‌شود.
 *
 * هر سرور: ip => [zone => [rrs...], 'refuse' => bool, 'down' => bool, 'recursive' => bool]
 */
final class FakeDnsServers extends DnsWireClient
{
    /** @var array<string,array> */
    public array $servers = [];

    /** @var list<array{ip:string,name:string,type:string}> */
    public array $log = [];

    public function server(string $ip, array $zones, array $opts = []): self
    {
        $this->servers[$ip] = ['zones' => $zones] + $opts + ['refuse' => false, 'down' => false, 'recursive' => false];

        return $this;
    }

    public function query(string $server, int $port, string $name, string $type, bool $rd = false,
        float $timeout = self::DEFAULT_TIMEOUT, bool $tcpOnly = false): DnsResponse|DnsFailure
    {
        $name = DnsMessage::normalizeName($name);
        $type = strtoupper($type);
        $this->log[] = ['ip' => $server, 'name' => $name, 'type' => $type];

        $s = $this->servers[$server] ?? null;
        if ($s === null || $s['down']) {
            return new DnsFailure(DnsFailure::TIMEOUT, 'fake_timeout');
        }

        $q = DnsMessage::query($name, $type, $rd, false, 4242);
        $wire = $this->respond($s, $name, $type);

        return DnsMessage::parse(substr_replace($wire, pack('n', 4242), 0, 2), $tcpOnly ? 'tcp' : 'udp');
    }

    /** پاسخِ سرورِ authoritative: عمیق‌ترین zoneی که دارد را پیدا می‌کند */
    private function respond(array $s, string $qname, string $qtype): string
    {
        if ($s['refuse']) {
            return self::packet($qname, $qtype, aa: false, rcode: 5);
        }

        if ($s['recursive']) {
            // رهگیرِ بازگشتی: بی‌AA، با RA، و پاسخ برای هر چیزی
            return self::packet($qname, $qtype, aa: false, ra: true,
                answer: [[$qname, 'A', '203.0.113.99']]);
        }

        $zone = null;
        foreach (array_keys($s['zones']) as $z) {
            if (self::within($qname, $z) && ($zone === null || self::labels($z) > self::labels($zone))) {
                $zone = $z;
            }
        }
        if ($zone === null) {
            return self::packet($qname, $qtype, aa: false, rcode: 5);
        }

        $rrs = $s['zones'][$zone];

        // برشِ zone زیرِ این zone؟ (NS در مالکی غیر از apex که qname زیرِ آن است)
        $cut = null;
        foreach ($rrs as [$owner, $t]) {
            if ($t === 'NS' && $owner !== $zone && self::within($qname, $owner)
                && ($cut === null || self::labels($owner) > self::labels($cut))) {
                $cut = $owner;
            }
        }
        if ($cut !== null && ! ($qtype === 'DS' && $qname === $cut)) {
            $ns = array_values(array_filter($rrs, fn ($r) => $r[0] === $cut && $r[1] === 'NS'));
            $targets = array_map(fn ($r) => $r[2], $ns);
            $glue = array_values(array_filter($rrs, fn ($r) => in_array($r[0], $targets, true) && in_array($r[1], ['A', 'AAAA'], true)));

            return self::packet($qname, $qtype, aa: false, authority: $ns, additional: $glue);
        }

        $answer = array_values(array_filter($rrs, fn ($r) => $r[0] === $qname && $r[1] === $qtype));
        if ($answer !== []) {
            return self::packet($qname, $qtype, aa: true, answer: $answer);
        }

        $exists = array_filter($rrs, fn ($r) => $r[0] === $qname || self::within($r[0], $qname));
        $base = $zone === '.' ? 'root.test' : $zone;
        $soa = [[$zone, 'SOA', ['ns.'.$base, 'hostmaster.'.$base, 1, 3600, 600, 86400, 300]]];

        return self::packet($qname, $qtype, aa: true, rcode: $exists ? 0 : 3, authority: $soa);
    }

    /* ─────────────── سازندهٔ بسته (نام‌ها بی‌فشرده‌سازی) ─────────────── */

    /** @param list<array{0:string,1:string,2:mixed}> $answer */
    public static function packet(string $qname, string $qtype, bool $aa = false, int $rcode = 0, bool $ra = false,
        array $answer = [], array $authority = [], array $additional = [], bool $tc = false): string
    {
        $flags = 0x8000 | ($aa ? 0x0400 : 0) | ($tc ? 0x0200 : 0) | ($ra ? 0x0080 : 0) | ($rcode & 0xF);
        $out = pack('nnnnnn', 0, $flags, 1, count($answer), count($authority), count($additional));
        $out .= DnsMessage::encodeName($qname).pack('nn', DnsMessage::typeCode($qtype), 1);
        foreach ([$answer, $authority, $additional] as $section) {
            foreach ($section as $rr) {
                $out .= self::rr(...$rr);
            }
        }

        return $out;
    }

    public static function rr(string $owner, string $type, mixed $data, int $ttl = 300): string
    {
        $rdata = match ($type) {
            'A'     => inet_pton($data),
            'AAAA'  => inet_pton($data),
            'NS', 'CNAME', 'PTR' => DnsMessage::encodeName($data),
            'MX'    => pack('n', $data[0]).DnsMessage::encodeName($data[1]),
            'TXT'   => implode('', array_map(fn ($s) => chr(strlen($s)).$s, (array) $data)),
            'SOA'   => DnsMessage::encodeName($data[0]).DnsMessage::encodeName($data[1]).pack('NNNNN', ...array_slice($data, 2)),
            'SRV'   => pack('nnn', $data[0], $data[1], $data[2]).DnsMessage::encodeName($data[3]),
            'CAA'   => chr($data[0]).chr(strlen($data[1])).$data[1].$data[2],
            'DS'    => pack('nCC', $data[0], $data[1], $data[2]).hex2bin($data[3]),
            default => (string) $data,
        };

        return DnsMessage::encodeName($owner).pack('nnNn', DnsMessage::typeCode($type), 1, $ttl, strlen($rdata)).$rdata;
    }

    private static function within(string $name, string $zone): bool
    {
        return $zone === '' || $zone === '.' || $name === $zone || str_ends_with($name, '.'.$zone);
    }

    private static function labels(string $n): int
    {
        return $n === '' || $n === '.' ? 0 : substr_count($n, '.') + 1;
    }
}
