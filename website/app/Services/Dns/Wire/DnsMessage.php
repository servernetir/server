<?php

namespace App\Services\Dns\Wire;

/**
 * ساختِ پرس‌وجو و تجزیهٔ پاسخِ DNS در سطحِ بایت (RFC 1035، EDNS0 از RFC 6891).
 *
 * ═══ چرا خودمان، نه dns_get_record ═══
 *
 * `dns_get_record` همیشه از resolverِ سیستم می‌پرسد (RD=1) و نه پرچمِ AA را
 * نشان می‌دهد نه rcode را. برای «آیا این نیم‌سرور واقعاً zone را سرو می‌کند؟»
 * و «والد چه NSی اعلام کرده؟» باید مستقیم و غیربازگشتی از خودِ سرور بپرسیم و
 * سرآیندِ پاسخ را ببینیم.
 *
 * ═══ ورودی خصمانه است ═══
 *
 * بستهٔ پاسخ از اینترنت می‌آید (سرورِ دامنهٔ هر مشتری، یا جعل‌کننده). پس:
 *  • هر خواندن قبلش مرزِ بسته را چک می‌کند؛
 *  • اشاره‌گرِ فشرده‌سازی فقط **به عقب** و حداکثر ۶۴ پرش، و نامِ کامل ≤ ۲۵۵
 *    بایت — حلقهٔ اشاره‌گر نمی‌تواند پردازش را معطل کند؛
 *  • RDATAی نوع‌های شناخته‌شده باید **دقیقاً** طولِ اعلام‌شده را مصرف کند؛
 *  • هیچ خطایی بیرون نمی‌رود: `parse()` همیشه DnsResponse یا DnsFailure می‌دهد.
 */
final class DnsMessage
{
    public const TYPES = [
        'A' => 1, 'NS' => 2, 'CNAME' => 5, 'SOA' => 6, 'PTR' => 12, 'MX' => 15,
        'TXT' => 16, 'AAAA' => 28, 'SRV' => 33, 'OPT' => 41, 'DS' => 43,
        'RRSIG' => 46, 'NSEC' => 47, 'DNSKEY' => 48, 'NSEC3' => 50, 'NSEC3PARAM' => 51,
        'CDS' => 59, 'CDNSKEY' => 60, 'SPF' => 99, 'ANY' => 255, 'CAA' => 257,
    ];

    public const RCODES = [
        0 => 'NOERROR', 1 => 'FORMERR', 2 => 'SERVFAIL', 3 => 'NXDOMAIN', 4 => 'NOTIMP',
        5 => 'REFUSED', 6 => 'YXDOMAIN', 7 => 'YXRRSET', 8 => 'NXRRSET', 9 => 'NOTAUTH',
        10 => 'NOTZONE', 16 => 'BADVERS',
    ];

    public const CLASS_IN = 1;

    /** اندازهٔ UDPِ EDNS — توصیهٔ DNS Flag Day 2020 تا از تکه‌شدنِ IP دور بمانیم */
    public const UDP_PAYLOAD = 1232;

    private const HEADER = 12;

    private const MAX_POINTER_HOPS = 64;

    private const MAX_NAME_WIRE = 255;

    private function __construct(
        public readonly int $id,
        /** نامِ نرمال: حروف‌کوچک، بی نقطهٔ انتهایی؛ ریشه = '.' */
        public readonly string $name,
        public readonly int $type,
        public readonly bool $rd,
        public readonly bool $edns,
    ) {}

    /**
     * پرس‌وجوی تازه با شناسهٔ تصادفیِ ۱۶ بیتی.
     *
     * @throws \InvalidArgumentException نامِ نامعتبر یا نوعِ پشتیبانی‌نشده
     */
    public static function query(string $name, string $type, bool $rd = false, bool $edns = true, ?int $id = null): self
    {
        $code = self::typeCode($type);
        if ($code === null || $code === self::TYPES['OPT']) {
            throw new \InvalidArgumentException('unsupported_type');
        }

        $norm = self::normalizeName($name);
        self::encodeName($norm);   // فقط اعتبارسنجی؛ خطا همین‌جا بیرون می‌آید نه وقتِ ارسال

        if ($id !== null && ($id < 0 || $id > 0xFFFF)) {
            throw new \InvalidArgumentException('bad_id');
        }

        // شناسهٔ تصادفیِ رمزنگارانه: شناسهٔ قابل‌حدس یعنی جعلِ پاسخِ UDP آسان
        return new self($id ?? random_int(0, 0xFFFF), $norm, $code, $rd, $edns);
    }

    public function typeName(): string
    {
        return self::typeNameOf($this->type);
    }

    public function toWire(): string
    {
        $flags = $this->rd ? 0x0100 : 0x0000;   // QR=0، opcode=QUERY
        $wire = pack('nnnnnn', $this->id, $flags, 1, 0, 0, $this->edns ? 1 : 0);
        $wire .= self::encodeName($this->name).pack('nn', $this->type, self::CLASS_IN);

        if ($this->edns) {
            // OPT: مالکِ ریشه، class = اندازهٔ UDP، TTL = extended-rcode/version/flags (همه صفر، DO خاموش)
            $wire .= "\x00".pack('nnNn', self::TYPES['OPT'], self::UDP_PAYLOAD, 0, 0);
        }

        return $wire;
    }

    /**
     * آیا این پاسخ واقعاً مالِ همین پرس‌وجوست؟ null یعنی بله.
     *
     * سؤالِ ناهم‌خوان هم `id_mismatch` است: پاسخی که شناسه‌اش جور است ولی
     * دربارهٔ نامِ دیگری است یا جعل است یا پاسخِ دیررسِ پرس‌وجوی دیگر.
     */
    public function check(DnsResponse $r): ?DnsFailure
    {
        if ($r->id !== $this->id) {
            return new DnsFailure(DnsFailure::ID_MISMATCH, 'id');
        }
        if (! $r->qr) {
            return new DnsFailure(DnsFailure::MALFORMED, 'not_response');
        }
        if ($r->opcode !== 0) {
            return new DnsFailure(DnsFailure::MALFORMED, 'opcode');
        }

        if ($r->question === null) {
            // برخی سرورها FORMERR/REFUSED/NOTIMP را بی بخشِ سؤال می‌دهند؛ پاسخِ «موفق» بی سؤال پذیرفته نیست
            return $r->rcode === 0 ? new DnsFailure(DnsFailure::ID_MISMATCH, 'no_question') : null;
        }

        if ($r->question['name'] !== $this->name
            || $r->question['type'] !== $this->type
            || $r->question['class'] !== self::CLASS_IN) {
            return new DnsFailure(DnsFailure::ID_MISMATCH, 'question');
        }

        return null;
    }

    /* ───────────────────────── نام‌ها و نوع‌ها ───────────────────────── */

    /** حروف‌کوچکِ ASCII، حذفِ یک نقطهٔ انتهایی؛ رشتهٔ خالی یا '.' = ریشه */
    public static function normalizeName(string $name): string
    {
        $n = strtolower(trim($name));
        if ($n === '' || $n === '.') {
            return '.';
        }

        return str_ends_with($n, '.') ? substr($n, 0, -1) : $n;
    }

    /**
     * نام → قالبِ سیمی (بی فشرده‌سازی).
     *
     * فقط بایت‌های چاپیِ ASCII بی بک‌اسلش: نامِ ورودیِ ما همیشه punycode است و
     * پذیرفتنِ escape یعنی یک مسیرِ تجزیهٔ دیگر برای چیزی که نیازش نداریم.
     *
     * @throws \InvalidArgumentException
     */
    public static function encodeName(string $name): string
    {
        $name = self::normalizeName($name);
        if ($name === '.') {
            return "\x00";
        }

        $wire = '';
        foreach (explode('.', $name) as $label) {
            $len = strlen($label);
            if ($len < 1 || $len > 63) {
                throw new \InvalidArgumentException('invalid_name');
            }
            if (preg_match('/[^\x21-\x7E]|\\\\/', $label)) {
                throw new \InvalidArgumentException('invalid_name');
            }
            $wire .= chr($len).$label;
        }
        $wire .= "\x00";

        if (strlen($wire) > self::MAX_NAME_WIRE) {
            throw new \InvalidArgumentException('invalid_name');
        }

        return $wire;
    }

    public static function typeCode(string $type): ?int
    {
        $t = strtoupper(trim($type));
        if (isset(self::TYPES[$t])) {
            return self::TYPES[$t];
        }
        if (preg_match('/^TYPE(\d{1,5})$/', $t, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 65535) {
            return (int) $m[1];
        }

        return null;
    }

    public static function typeNameOf(int $code): string
    {
        static $flip = null;
        $flip ??= array_flip(self::TYPES);

        return $flip[$code] ?? 'TYPE'.$code;
    }

    public static function rcodeName(int $rcode): string
    {
        return self::RCODES[$rcode] ?? 'RCODE'.$rcode;
    }

    /* ───────────────────────── تجزیه ───────────────────────── */

    /**
     * بایت‌های خام → DnsResponse؛ هر خرابی → DnsFailure(malformed). هرگز استثنا نمی‌دهد.
     *
     * شناسه/سؤال این‌جا با پرس‌وجو سنجیده نمی‌شود — آن کارِ `check()` است.
     */
    public static function parse(string $wire, string $transport = 'udp'): DnsResponse|DnsFailure
    {
        try {
            return self::decode($wire, $transport);
        } catch (\UnexpectedValueException $e) {
            return new DnsFailure(DnsFailure::MALFORMED, $e->getMessage());
        } catch (\Throwable) {
            // هر چیزِ پیش‌بینی‌نشده هم «بستهٔ خراب» است، نه کرشِ فراخوان
            return new DnsFailure(DnsFailure::MALFORMED, 'parse_error');
        }
    }

    private static function decode(string $wire, string $transport): DnsResponse
    {
        $len = strlen($wire);
        if ($len < self::HEADER) {
            throw new \UnexpectedValueException('short_header');
        }
        if ($len > 65535) {
            throw new \UnexpectedValueException('oversize');
        }

        $h = unpack('nid/nflags/nqd/nan/nns/nar', $wire);
        $flags = $h['flags'];
        $tc = (bool) ($flags & 0x0200);

        if ($h['qd'] > 1) {
            throw new \UnexpectedValueException('multiple_questions');
        }

        $pos = self::HEADER;
        $question = null;
        if ($h['qd'] === 1) {
            $qname = self::readName($wire, $pos, $len);
            if ($pos + 4 > $len) {
                throw new \UnexpectedValueException('short_question');
            }
            $q = unpack('ntype/nclass', $wire, $pos);
            $pos += 4;
            $question = ['name' => $qname, 'type' => $q['type'], 'class' => $q['class']];
        }

        $sections = ['an' => [], 'ns' => [], 'ar' => []];
        $edns = null;
        try {
            foreach (['an', 'ns', 'ar'] as $sec) {
                for ($i = 0; $i < $h[$sec]; $i++) {
                    $rr = self::readRecord($wire, $pos, $len);
                    if ($rr['typeCode'] === self::TYPES['OPT']) {
                        // OPT فقط یک بار، فقط در additional و با مالکِ ریشه (RFC 6891 §6.1.1)
                        if ($sec !== 'ar' || $edns !== null || $rr['name'] !== '.') {
                            throw new \UnexpectedValueException('bad_opt');
                        }
                        $edns = $rr['opt'];
                        continue;
                    }
                    $sections[$sec][] = $rr;
                }
            }
        } catch (\UnexpectedValueException $e) {
            // پاسخِ TC=1 ممکن است وسطِ رکورد بریده شده باشد؛ فقط سرآیند/سؤالش به کار
            // می‌آید (کلاینت روی TCP دوباره می‌پرسد). بستهٔ بریدهٔ بی TC یعنی خراب.
            if (! $tc) {
                throw $e;
            }
        }

        $rcode = $flags & 0x000F;
        if ($edns !== null) {
            $rcode |= ($edns['extended_rcode'] << 4);
            unset($edns['extended_rcode']);
        }

        return new DnsResponse(
            id: $h['id'],
            opcode: ($flags >> 11) & 0x0F,
            qr: (bool) ($flags & 0x8000),
            aa: (bool) ($flags & 0x0400),
            tc: $tc,
            rd: (bool) ($flags & 0x0100),
            ra: (bool) ($flags & 0x0080),
            ad: (bool) ($flags & 0x0020),
            cd: (bool) ($flags & 0x0010),
            rcode: $rcode,
            question: $question,
            answers: $sections['an'],
            authority: $sections['ns'],
            additional: $sections['ar'],
            edns: $edns,
            transport: $transport,
            size: $len,
        );
    }

    /**
     * یک نام با پشتیبانیِ کاملِ فشرده‌سازی؛ $pos به بعد از نامِ درجا می‌رود.
     *
     * 🔴 «فقط به عقب» به تنهایی کافی نیست: برچسبی در ۱۲ و اشاره‌گری در ۱۴ که به
     * ۱۲ برمی‌گردد هر دو قاعده را رعایت می‌کنند و حلقهٔ بی‌پایان می‌سازند. سقفِ
     * پرش و سقفِ ۲۵۵ بایت آن را هم می‌بندد.
     */
    private static function readName(string $wire, int &$pos, int $len): string
    {
        $labels = [];
        $wireLen = 1;   // بایتِ صفرِ پایانی
        $cursor = $pos;
        $jumped = false;
        $hops = 0;

        while (true) {
            if ($cursor >= $len) {
                throw new \UnexpectedValueException('name_overrun');
            }
            $b = ord($wire[$cursor]);

            if ($b === 0) {
                if (! $jumped) {
                    $pos = $cursor + 1;
                }
                break;
            }

            $kind = $b & 0xC0;
            if ($kind === 0xC0) {
                if ($cursor + 1 >= $len) {
                    throw new \UnexpectedValueException('pointer_overrun');
                }
                $target = (($b & 0x3F) << 8) | ord($wire[$cursor + 1]);
                if ($target >= $cursor) {
                    throw new \UnexpectedValueException('forward_pointer');
                }
                if ($target < self::HEADER) {
                    throw new \UnexpectedValueException('pointer_into_header');
                }
                if (++$hops > self::MAX_POINTER_HOPS) {
                    throw new \UnexpectedValueException('pointer_loop');
                }
                if (! $jumped) {
                    $pos = $cursor + 2;
                    $jumped = true;
                }
                $cursor = $target;

                continue;
            }

            if ($kind !== 0) {
                // 0x40/0x80: برچسب‌های توسعه‌یافتهٔ منسوخ — هیچ سرورِ امروزی نمی‌فرستد
                throw new \UnexpectedValueException('bad_label_type');
            }

            if ($cursor + 1 + $b > $len) {
                throw new \UnexpectedValueException('label_overrun');
            }
            $wireLen += $b + 1;
            if ($wireLen > self::MAX_NAME_WIRE) {
                throw new \UnexpectedValueException('name_too_long');
            }
            $labels[] = self::presentLabel(substr($wire, $cursor + 1, $b));
            $cursor += 1 + $b;
        }

        return $labels === [] ? '.' : implode('.', $labels);
    }

    /** برچسبِ خام → presentation: حروف‌کوچک، و فرارِ نقطه/بک‌اسلش/بایتِ غیرچاپی */
    private static function presentLabel(string $raw): string
    {
        if (preg_match('/^[A-Za-z0-9_*-]+$/', $raw)) {
            return strtolower($raw);
        }

        $out = '';
        foreach (str_split($raw) as $ch) {
            $o = ord($ch);
            if ($o <= 0x20 || $o >= 0x7F) {
                $out .= sprintf('\\%03d', $o);
            } elseif ($ch === '.' || $ch === '\\') {
                $out .= '\\'.$ch;
            } else {
                $out .= strtolower($ch);
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function readRecord(string $wire, int &$pos, int $len): array
    {
        $name = self::readName($wire, $pos, $len);
        if ($pos + 10 > $len) {
            throw new \UnexpectedValueException('short_rr');
        }
        $h = unpack('ntype/nclass/Nttl/nrdlen', $wire, $pos);
        $pos += 10;
        $start = $pos;
        $end = $start + $h['rdlen'];
        if ($end > $len) {
            throw new \UnexpectedValueException('short_rdata');
        }
        $pos = $end;

        $type = $h['type'];
        $rr = [
            'name'     => $name,
            'type'     => self::typeNameOf($type),
            'typeCode' => $type,
            'class'    => $h['class'],
            // RFC 2181 §8: TTL با بیتِ بالای روشن صفر حساب می‌شود
            'ttl'      => $h['ttl'] > 0x7FFFFFFF ? 0 : $h['ttl'],
        ];

        if ($type === self::TYPES['OPT']) {
            $rr['opt'] = [
                'udp_size'       => $h['class'],
                'extended_rcode' => ($h['ttl'] >> 24) & 0xFF,
                'version'        => ($h['ttl'] >> 16) & 0xFF,
                'do'             => (bool) ($h['ttl'] & 0x8000),
                'options'        => substr($wire, $start, $h['rdlen']),
            ];
            $rr['data'] = $rr['opt']['options'];

            return $rr;
        }

        $rr['data'] = self::decodeRdata($type, $wire, $start, $end, $len);

        return $rr;
    }

    private static function decodeRdata(int $type, string $wire, int $start, int $end, int $len): mixed
    {
        $rdlen = $end - $start;
        $p = $start;

        switch ($type) {
            case 1:   // A
                if ($rdlen !== 4) {
                    throw new \UnexpectedValueException('bad_a');
                }

                return inet_ntop(substr($wire, $start, 4));

            case 28:  // AAAA — inet_ntop شکلِ فشرده و حروف‌کوچک می‌دهد
                if ($rdlen !== 16) {
                    throw new \UnexpectedValueException('bad_aaaa');
                }

                return inet_ntop(substr($wire, $start, 16));

            case 2:   // NS
            case 5:   // CNAME
            case 12:  // PTR
                $n = self::readName($wire, $p, $len);
                self::expectEnd($p, $end);

                return $n;

            case 6:   // SOA
                $mname = self::readName($wire, $p, $len);
                $rname = self::readName($wire, $p, $len);
                if ($p + 20 !== $end) {
                    throw new \UnexpectedValueException('bad_soa');
                }
                $s = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminimum', $wire, $p);

                return ['mname' => $mname, 'rname' => $rname] + $s;

            case 15:  // MX
                if ($rdlen < 3) {
                    throw new \UnexpectedValueException('bad_mx');
                }
                $pref = unpack('n', $wire, $p)[1];
                $p += 2;
                $ex = self::readName($wire, $p, $len);
                self::expectEnd($p, $end);

                return ['preference' => $pref, 'exchange' => $ex];

            case 16:  // TXT
            case 99:  // SPF — همان قالب
                $strings = [];
                while ($p < $end) {
                    $l = ord($wire[$p]);
                    $p++;
                    if ($p + $l > $end) {
                        throw new \UnexpectedValueException('txt_overrun');
                    }
                    $strings[] = substr($wire, $p, $l);
                    $p += $l;
                }

                return $strings;

            case 33:  // SRV
                if ($rdlen < 7) {
                    throw new \UnexpectedValueException('bad_srv');
                }
                $s = unpack('npriority/nweight/nport', $wire, $p);
                $p += 6;
                $s['target'] = self::readName($wire, $p, $len);
                self::expectEnd($p, $end);

                return $s;

            case 257: // CAA
                if ($rdlen < 2) {
                    throw new \UnexpectedValueException('bad_caa');
                }
                $flags = ord($wire[$p]);
                $tagLen = ord($wire[$p + 1]);
                if ($tagLen < 1 || 2 + $tagLen > $rdlen) {
                    throw new \UnexpectedValueException('bad_caa');
                }

                return [
                    'flags' => $flags,
                    'tag'   => strtolower(substr($wire, $p + 2, $tagLen)),
                    'value' => substr($wire, $p + 2 + $tagLen, $rdlen - 2 - $tagLen),
                ];

            case 43:  // DS
            case 59:  // CDS
                if ($rdlen < 4) {
                    throw new \UnexpectedValueException('bad_ds');
                }
                $d = unpack('nkey_tag/Calgorithm/Cdigest_type', $wire, $p);
                $d['digest'] = bin2hex(substr($wire, $p + 4, $rdlen - 4));

                return $d;

            default:
                // نوعِ ناشناخته: بایت‌های خام، دست‌نخورده (RFC 3597)
                return substr($wire, $start, $rdlen);
        }
    }

    private static function expectEnd(int $p, int $end): void
    {
        if ($p !== $end) {
            throw new \UnexpectedValueException('rdata_length');
        }
    }
}
