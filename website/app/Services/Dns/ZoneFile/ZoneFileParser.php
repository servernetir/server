<?php

namespace App\Services\Dns\ZoneFile;

use App\Services\Dns\DomainName;

/**
 * تجزیهٔ فایلِ zone به قالبِ BIND (RFC 1035 §5) — قرارداد §7.
 *
 * مسیرِ قابلِ اتکای مهاجرت همین است، نه اسکنِ چند نامِ رایج: اسکن نمی‌تواند
 * selectorهای DKIM یا زیردامنه‌های ناشناخته را پیدا کند. این تجزیه‌گر فقط
 * «خواندن» می‌کند؛ اعتبارسنجی و تشخیصِ تکراری/تعارض کارِ RecordValidator و
 * سرویس است، تا فرم و واردکردن یک قاعده داشته باشند.
 *
 * ورودی دشمن فرض می‌شود: سقفِ اندازه و خط، بی‌استثنا، بی‌حلقهٔ بی‌پایان.
 */
class ZoneFileParser
{
    /** نوع‌هایی که وارد می‌شوند */
    public const SUPPORTED = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

    /** DNSSEC — در این فاز امضا نمی‌کنیم */
    private const DNSSEC = ['DNSKEY', 'RRSIG', 'NSEC', 'NSEC3', 'NSEC3PARAM', 'DS', 'CDS', 'CDNSKEY'];

    private const KNOWN = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS', 'SOA', 'PTR', 'SPF', 'HINFO',
        'NAPTR', 'SSHFP', 'TLSA', 'SVCB', 'HTTPS', 'LOC', 'RP', 'DNAME', 'ALIAS', 'URI', 'OPENPGPKEY', 'SMIMEA',
        'DNSKEY', 'RRSIG', 'NSEC', 'NSEC3', 'NSEC3PARAM', 'DS', 'CDS', 'CDNSKEY', 'AFSDB', 'CERT', 'KX', 'DHCID'];

    private const CLASSES = ['IN', 'CH', 'CS', 'HS', 'ANY'];

    public function __construct(
        private readonly int $maxBytes = 1048576,
        private readonly int $maxLines = 5000,
    ) {}

    public function parse(string $text, string $origin): ParsedZone
    {
        $zone = DomainName::normalize($origin) ?? strtolower(rtrim($origin, '.'));

        if (strlen($text) > $this->maxBytes) {
            return new ParsedZone($zone, [], true, 'file_too_large');
        }

        $text = preg_replace('~^\xEF\xBB\xBF~', '', $text) ?? $text;   // BOM
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = explode("\n", $text);
        $truncated = false;
        if (count($lines) > $this->maxLines) {
            $lines = array_slice($lines, 0, $this->maxLines);
            $truncated = true;
        }

        $rows = [];
        $currentOrigin = $zone;
        $originBad = false;          // پس از $ORIGINِ ردشده، نام‌های نسبی مقصدِ معلومی ندارند
        $defaultTtl = null;
        $lastTtl = null;             // RFC 1035 §5.1: بی‌$TTL، آخرین TTLِ صریح
        $lastOwner = $zone;

        foreach ($this->logicalLines($lines) as [$lineNo, $tokens, $blankOwner, $syntaxError]) {
            if ($syntaxError !== null) {
                $rows[] = $this->row($lineNo, null, null, null, [], 'invalid', $syntaxError);

                continue;
            }
            if ($tokens === []) {
                continue;
            }

            $first = $tokens[0]['v'];

            // ── directives ──
            if (! $blankOwner && ! $tokens[0]['q'] && str_starts_with($first, '$')) {
                $dir = strtoupper($first);
                if ($dir === '$ORIGIN') {
                    $o = $tokens[1]['v'] ?? '';
                    $abs = $this->absolute($o, $currentOrigin);
                    if ($abs === null || ($abs !== $zone && ! str_ends_with($abs, '.'.$zone))) {
                        $rows[] = $this->row($lineNo, null, null, null, [], 'invalid', 'origin_outside_zone');
                        // ⚠️ پیش‌تر origin عوض نمی‌شد و ردیف‌های بعدی (نوشته‌شده برای دامنهٔ دیگر)
                        // بی‌صدا به originِ قبلی می‌چسبیدند و «تازه» وارد می‌شدند
                        $originBad = true;
                    } else {
                        $currentOrigin = $abs;
                        $originBad = false;
                    }
                } elseif ($dir === '$TTL') {
                    $t = $this->ttl($tokens[1]['v'] ?? '');
                    if ($t === null) {
                        $rows[] = $this->row($lineNo, null, null, null, [], 'invalid', 'ttl_syntax');
                    } else {
                        $defaultTtl = $t;
                    }
                } else {
                    $rows[] = $this->row($lineNo, null, null, null, [], 'unsupported', 'directive_unsupported');
                }

                continue;
            }

            if ($originBad) {
                $rows[] = $this->row($lineNo, null, null, null, [], 'invalid', 'origin_outside_zone');

                continue;
            }

            // ── owner ──
            $i = 0;
            if ($blankOwner) {
                $owner = $lastOwner;
            } else {
                $owner = $first === '@' ? $currentOrigin : $this->absolute($first, $currentOrigin);
                $i = 1;
                if ($owner === null) {
                    $rows[] = $this->row($lineNo, null, null, null, [], 'invalid', 'name_invalid');

                    continue;
                }
                $lastOwner = $owner;
            }

            // ── [ttl] [class] type، به هر ترتیب ──
            $ttl = null;
            $class = null;
            $type = null;
            for ($k = 0; $k < 3 && isset($tokens[$i]); $k++) {
                $v = strtoupper($tokens[$i]['v']);
                if ($ttl === null && ! $tokens[$i]['q'] && ($t = $this->ttl($v)) !== null) {
                    $ttl = $t;
                    $lastTtl = $t;
                    $i++;
                } elseif ($class === null && in_array($v, self::CLASSES, true)) {
                    $class = $v;
                    $i++;
                } else {
                    break;
                }
            }
            $type = isset($tokens[$i]) ? strtoupper($tokens[$i]['v']) : null;
            $i++;
            $rdata = array_slice($tokens, $i);
            $ttl ??= $defaultTtl ?? $lastTtl;

            if ($type === null || (! in_array($type, self::KNOWN, true) && ! preg_match('~^TYPE\d+$~', $type))) {
                $rows[] = $this->row($lineNo, $owner, $type, $ttl, [], 'invalid', 'type_syntax');

                continue;
            }
            if ($class !== null && $class !== 'IN') {
                $rows[] = $this->row($lineNo, $owner, $type, $ttl, [], 'invalid', 'class_unsupported');

                continue;
            }
            if ($owner !== $zone && ! str_ends_with($owner, '.'.$zone)) {
                $rows[] = $this->row($lineNo, $owner, $type, $ttl, [], 'invalid', 'name_outside_zone');

                continue;
            }
            if ($type === 'SOA') {
                $rows[] = $this->row($lineNo, $owner, $type, $ttl, [], 'skipped', 'soa_managed');

                continue;
            }
            if ($type === 'NS' && $owner === $zone) {
                $rows[] = $this->row($lineNo, $owner, $type, $ttl, [], 'skipped', 'apex_ns_managed');

                continue;
            }
            if (in_array($type, self::DNSSEC, true)) {
                $rows[] = $this->row($lineNo, $owner, $type, $ttl, [], 'skipped', 'dnssec_unsupported');

                continue;
            }
            if ($type === 'SPF') {
                $rows[] = $this->row($lineNo, $owner, $type, $ttl, [], 'unsupported', 'spf_type');

                continue;
            }
            if (! in_array($type, self::SUPPORTED, true)) {
                $rows[] = $this->row($lineNo, $owner, $type, $ttl, [], 'unsupported', 'type_unsupported');

                continue;
            }

            [$input, $err] = $this->rdata($type, $rdata, $currentOrigin);
            if ($err !== null) {
                $rows[] = $this->row($lineNo, $owner, $type, $ttl, [], 'invalid', $err);

                continue;
            }

            $rows[] = $this->row($lineNo, $owner, $type, $ttl, $input + [
                'type' => $type, 'name' => $owner.'.', 'ttl' => $ttl,
            ], 'parsed', null);
        }

        return new ParsedZone($zone, $rows, $truncated);
    }

    /**
     * RDATA → ورودیِ RecordValidator.
     *
     * @param  list<array{v:string,q:bool}>  $t
     * @return array{0:array,1:?string}
     */
    private function rdata(string $type, array $t, string $origin): array
    {
        $v = fn (int $i) => $t[$i]['v'] ?? null;
        $host = function (?string $s) use ($origin): ?string {
            if ($s === null || $s === '') {
                return null;
            }
            if ($s === '.') {
                return '.';
            }

            return $s === '@' ? $origin.'.' : (($a = $this->absolute($s, $origin)) === null ? null : $a.'.');
        };

        switch ($type) {
            case 'A':
            case 'AAAA':
                return count($t) === 1 ? [['content' => $v(0)], null] : [[], 'rdata_syntax'];

            case 'CNAME':
            case 'NS':
                $h = $host($v(0));

                return count($t) === 1 && $h !== null ? [['content' => $h], null] : [[], 'rdata_syntax'];

            case 'MX':
                $h = $host($v(1));

                return count($t) === 2 && ctype_digit((string) $v(0)) && $h !== null
                    ? [['prio' => (int) $v(0), 'content' => $h], null] : [[], 'rdata_syntax'];

            case 'SRV':
                $h = $host($v(3));
                if (count($t) !== 4 || ! ctype_digit((string) $v(0)) || ! ctype_digit((string) $v(1)) || ! ctype_digit((string) $v(2)) || $h === null) {
                    return [[], 'rdata_syntax'];
                }

                return [['prio' => (int) $v(0), 'weight' => (int) $v(1), 'port' => (int) $v(2), 'target' => $h], null];

            case 'CAA':
                if (count($t) !== 3 || ! ctype_digit((string) $v(0))) {
                    return [[], 'rdata_syntax'];
                }

                return [['flags' => (int) $v(0), 'tag' => strtolower((string) $v(1)), 'value' => (string) $v(2)], null];

            case 'TXT':
                if ($t === []) {
                    return [[], 'rdata_syntax'];
                }
                // هر توکن یک character-string؛ گیومه‌دار یا نه. شکلِ presentationِ
                // ورودیِ TxtCodec (همان قاعدهٔ فرم) — escapeهای نامجاز همان‌جا رد می‌شوند.
                $parts = array_map(fn ($tok) => '"'.($tok['q'] ? $tok['raw'] : str_replace('"', '\"', $tok['v'])).'"', $t);

                return [['content' => implode(' ', $parts)], null];
        }

        return [[], 'type_unsupported'];
    }

    /**
     * خط‌ها → «خطِ منطقی» با توکن‌ها؛ پرانتزها چند خط را یکی می‌کنند، `;` بیرونِ
     * گیومه توضیح است، و escape داخلِ گیومه حفظ می‌شود (برای تشخیص در TxtCodec).
     *
     * @return \Generator<array{0:int,1:list<array{v:string,q:bool,raw:string}>,2:bool,3:?string}>
     */
    private function logicalLines(array $lines): \Generator
    {
        $tokens = [];
        $depth = 0;
        $startLine = 0;
        $blankOwner = false;
        $inQuote = false;
        $buf = '';
        $raw = '';
        $quotedTok = false;

        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            if ($depth === 0 && ! $inQuote) {
                $tokens = [];
                $startLine = $lineNo;
                $blankOwner = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");
            }

            $len = strlen($line);
            for ($p = 0; $p < $len; $p++) {
                $c = $line[$p];

                if ($inQuote) {
                    if ($c === '\\' && $p + 1 < $len) {
                        $raw .= $c.$line[$p + 1];
                        $buf .= $line[$p + 1];
                        $p++;

                        continue;
                    }
                    if ($c === '"') {
                        $inQuote = false;
                        $tokens[] = ['v' => $buf, 'q' => true, 'raw' => $raw];
                        $buf = $raw = '';

                        continue;
                    }
                    $buf .= $c;
                    $raw .= $c;

                    continue;
                }

                if ($c === ';') {
                    break;                              // توضیح تا آخرِ خط
                }
                if ($c === '"') {
                    $this->flush($tokens, $buf);
                    $inQuote = true;
                    $buf = $raw = '';

                    continue;
                }
                if ($c === '(') {
                    $this->flush($tokens, $buf);
                    $depth++;

                    continue;
                }
                if ($c === ')') {
                    $this->flush($tokens, $buf);
                    if ($depth === 0) {
                        yield [$startLine, [], $blankOwner, 'paren_unbalanced'];
                        $tokens = [];

                        continue 2;
                    }
                    $depth--;

                    continue;
                }
                if ($c === ' ' || $c === "\t") {
                    $this->flush($tokens, $buf);

                    continue;
                }
                $buf .= $c;
            }

            if ($inQuote) {
                // گیومهٔ باز تا آخرِ خط: RFC اجازهٔ شکستنِ خط داخلِ رشته را نمی‌دهد
                $inQuote = false;
                $buf = $raw = '';
                yield [$startLine, [], $blankOwner, 'quote_unterminated'];
                $tokens = [];
                $depth = 0;

                continue;
            }
            $this->flush($tokens, $buf);

            if ($depth === 0) {
                yield [$startLine, $tokens, $blankOwner, null];
            } elseif ($lineNo - $startLine > 100) {
                yield [$startLine, [], $blankOwner, 'paren_unbalanced'];
                $tokens = [];
                $depth = 0;
            }
        }

        if ($depth > 0) {
            yield [$startLine, [], $blankOwner, 'paren_unbalanced'];
        }
    }

    private function flush(array &$tokens, string &$buf): void
    {
        if ($buf !== '') {
            $tokens[] = ['v' => $buf, 'q' => false, 'raw' => $buf];
            $buf = '';
        }
    }

    /** نامِ نسبی/مطلق → FQDNِ ASCIIِ بی‌نقطه، یا null */
    private function absolute(string $name, string $origin): ?string
    {
        if ($name === '' || $name === '@') {
            return $origin;
        }
        if (str_ends_with($name, '.')) {
            $name = substr($name, 0, -1);
        } else {
            $name .= '.'.$origin;
        }

        $labels = [];
        foreach (explode('.', $name) as $l) {
            $a = $l === '*' ? '*' : DomainName::labelToAscii($l);
            if ($a === null || strlen($a) > 63) {
                return null;
            }
            $labels[] = $a;
        }
        $out = implode('.', $labels);

        return strlen($out) <= 253 ? $out : null;
    }

    /** TTLِ BIND: عدد یا واحد‌دار (1h30m، 2d، 1w) */
    private function ttl(string $v): ?int
    {
        $v = strtolower(trim($v));
        if ($v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            return strlen($v) <= 10 ? (int) $v : null;
        }
        if (! preg_match('~^(\d+[smhdw])+$~', $v)) {
            return null;
        }
        $mult = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
        preg_match_all('~(\d+)([smhdw])~', $v, $m, PREG_SET_ORDER);
        $total = 0;
        foreach ($m as [, $n, $u]) {
            $total += (int) $n * $mult[$u];
        }

        return $total <= 2147483647 ? $total : null;
    }

    private function row(int $line, ?string $name, ?string $type, ?int $ttl, array $input, string $status, ?string $code): array
    {
        return ['line' => $line, 'name' => $name, 'type' => $type, 'ttl' => $ttl, 'input' => $input, 'status' => $status, 'code' => $code];
    }
}
