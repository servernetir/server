<?php

namespace App\Services\Dns;

/**
 * اعتبارسنجی و نرمال‌سازیِ یک رکوردِ DNS — قرارداد §6.2/§6.3.
 *
 * سه مسیر رکورد می‌سازند (فرم، واردکردنِ فایلِ zone، و بعداً API) و هر سه از
 * همین کلاس رد می‌شوند. دلیلِ سخت‌گیری: PowerDNS کلِ PATCHِ zone را با یک
 * رکوردِ خراب رد می‌کند، پس یک رکوردِ بد جلوی همهٔ تغییراتِ بعدیِ همان zone را
 * می‌گیرد.
 *
 * خروجیِ خطا همیشه **کد** است (`dns.err.<code>`)، نه متن؛ ترجمه کارِ کنترلر است.
 *
 * قالبِ ذخیره (§6.1):
 *   A/AAAA → IP کانونی · CNAME/NS → FQDN بی‌نقطه · MX → exchange + prio
 *   SRV → "weight port target" + prio · CAA → 'flags tag "value"' · TXT → presentationِ کانونی
 */
class RecordValidator
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA', 'NS'];

    /**
     * نوع‌هایی که نامِ مالکشان نامِ میزبان است و زیرخط نمی‌پذیرند — مگر زیرِ والدِ «_»دار
     * (§6.2؛ RFC 8552). NS عمداً این‌جا نیست: تفویضِ `_acme-challenge` (acme-dns) رایج است.
     */
    private const HOST_OWNER_TYPES = ['A', 'AAAA', 'MX'];

    public const TTL_MIN = 60;
    public const TTL_MAX = 86400;

    /** همهٔ کدهای خطای این کلاس (کلیدِ ترجمه: dns.err.<code>) */
    public const ERROR_CODES = [
        'type_unsupported', 'name_invalid', 'name_outside_zone', 'name_too_long', 'name_wildcard_position',
        'name_underscore', 'ttl_range', 'comment_too_long',
        'a_invalid', 'a_not_public', 'aaaa_invalid', 'aaaa_not_public',
        'cname_apex', 'cname_self', 'target_invalid', 'target_is_ip',
        'mx_prio', 'srv_name', 'srv_prio', 'srv_weight', 'srv_port',
        'caa_flags', 'caa_tag', 'caa_value',
        'ns_apex',
        'txt_empty', 'txt_too_long', 'txt_backslash', 'txt_control', 'txt_encoding', 'txt_syntax',
        // conflicts()
        'duplicate', 'cname_conflict', 'cname_exists', 'ns_occludes', 'delegated_name',
        // وضعیتِ zone (ZoneService)
        'zone_deleting', 'zone_suspended', 'record_not_found', 'record_limit',
    ];

    /**
     * @param  string  $zone  apexِ zone (ASCII، بی‌نقطه)
     * @param  array<string,mixed>  $in  type,name,content,ttl,prio,weight,port,target,flags,tag,value,comment
     * @return array{ok:bool, errors:array<string,array{code:string,params:array}>, data:array<string,mixed>}
     */
    public function validate(string $zone, array $in): array
    {
        $zone = strtolower(rtrim(trim($zone), '.'));
        $errors = [];

        $type = strtoupper(trim((string) ($in['type'] ?? '')));
        if (! in_array($type, self::TYPES, true)) {
            return $this->fail(['type' => $this->err('type_unsupported')]);
        }

        [$name, $nameErr] = $this->ownerName((string) ($in['name'] ?? ''), $zone, $type);
        if ($nameErr !== null) {
            $errors['name'] = $nameErr;
        }

        $ttlRaw = $in['ttl'] ?? null;
        $ttl = ($ttlRaw === null || $ttlRaw === '') ? (int) config('edge.default_ttl', 3600) : $this->int($ttlRaw);
        if ($ttl === null || $ttl < self::TTL_MIN || $ttl > self::TTL_MAX) {
            $errors['ttl'] = $this->err('ttl_range', ['min' => self::TTL_MIN, 'max' => self::TTL_MAX]);
        }

        $comment = trim((string) ($in['comment'] ?? ''));
        if (mb_strlen($comment) > 120) {
            $errors['comment'] = $this->err('comment_too_long', ['max' => 120]);
        }

        $prio = null;
        $content = '';

        switch ($type) {
            case 'A':
                $content = trim((string) ($in['content'] ?? ''));
                if (! filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $errors['content'] = $this->err('a_invalid');
                } elseif (! $this->publicIpv4($content)) {
                    $errors['content'] = $this->err('a_not_public');
                }
                break;

            case 'AAAA':
                $raw = trim((string) ($in['content'] ?? ''));
                if (! filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $errors['content'] = $this->err('aaaa_invalid');
                } else {
                    $content = (string) inet_ntop(inet_pton($raw));
                    if (! $this->publicIpv6($content)) {
                        $errors['content'] = $this->err('aaaa_not_public');
                    }
                }
                break;

            case 'CNAME':
                [$content, $e] = $this->target((string) ($in['content'] ?? ''), allowUnderscore: true);
                // نامِ نامعتبر به apex برمی‌گردد؛ آن‌وقت «CNAME در apex» پیامِ دروغ است
                if ($nameErr === null && $name === $zone) {
                    $errors['name'] = $this->err('cname_apex');
                } elseif ($e !== null) {
                    $errors['content'] = $e;
                } elseif ($content === $name) {
                    $errors['content'] = $this->err('cname_self');
                }
                break;

            case 'NS':
                [$content, $e] = $this->target((string) ($in['content'] ?? ''), allowUnderscore: false);
                if ($nameErr === null && $name === $zone) {
                    $errors['name'] = $this->err('ns_apex');
                } elseif ($e !== null) {
                    $errors['content'] = $e;
                }
                break;

            case 'MX':
                $prio = $this->int($in['prio'] ?? 10);
                if ($prio === null || $prio < 0 || $prio > 65535) {
                    $errors['prio'] = $this->err('mx_prio');
                }
                [$content, $e] = $this->target((string) ($in['content'] ?? ''), allowUnderscore: false, allowRoot: true);
                if ($e !== null) {
                    $errors['content'] = $e;
                }
                break;

            case 'TXT':
                $r = TxtCodec::fromUserInput((string) ($in['content'] ?? ''));
                if (! $r['ok']) {
                    $errors['content'] = $this->err($r['code'], $r['params'] ?? []);
                } else {
                    $content = $r['value'];
                }
                break;

            case 'SRV':
                if (! $this->srvOwner($name, $zone)) {
                    $errors['name'] = $this->err('srv_name');
                }
                $prio = $this->int($in['prio'] ?? null);
                $weight = $this->int($in['weight'] ?? null);
                $port = $this->int($in['port'] ?? null);
                if ($prio === null || $prio < 0 || $prio > 65535) {
                    $errors['prio'] = $this->err('srv_prio');
                }
                if ($weight === null || $weight < 0 || $weight > 65535) {
                    $errors['weight'] = $this->err('srv_weight');
                }
                if ($port === null || $port < 0 || $port > 65535) {
                    $errors['port'] = $this->err('srv_port');
                }
                [$target, $e] = $this->target((string) ($in['target'] ?? $in['content'] ?? ''), allowUnderscore: false, allowRoot: true);
                if ($e !== null) {
                    $errors['target'] = $e;
                }
                $content = ($weight ?? 0).' '.($port ?? 0).' '.$target;
                break;

            case 'CAA':
                $flags = $this->int($in['flags'] ?? 0);
                $tag = strtolower(trim((string) ($in['tag'] ?? 'issue')));
                $value = trim((string) ($in['value'] ?? ''), " \t");
                if ($flags !== 0 && $flags !== 128) {
                    $errors['flags'] = $this->err('caa_flags');
                }
                if (! in_array($tag, ['issue', 'issuewild', 'iodef'], true)) {
                    $errors['tag'] = $this->err('caa_tag');
                } elseif (! $this->caaValue($tag, $value)) {
                    $errors['value'] = $this->err('caa_value');
                }
                $content = ($flags ?? 0).' '.$tag.' "'.$value.'"';
                break;
        }

        if ($errors !== []) {
            return $this->fail($errors);
        }

        return [
            'ok'     => true,
            'errors' => [],
            'data'   => [
                'type'    => $type,
                'name'    => $name,
                'content' => $content,
                'ttl'     => $ttl,
                'prio'    => in_array($type, ['MX', 'SRV'], true) ? $prio : null,
                'comment' => $comment !== '' ? $comment : null,
            ],
        ];
    }

    /**
     * قاعده‌هایی که فقط با دیدنِ بقیهٔ رکوردهای zone معنا دارند.
     *
     * @param  array<string,mixed>  $data  خروجیِ validate()['data']
     * @param  iterable<object|array>  $existing  رکوردهای فعلی (id,type,name,content,prio,ttl)
     * @return array{code:string,params:array}|null
     */
    public function conflicts(array $data, iterable $existing, ?int $ignoreId = null): ?array
    {
        $rows = $this->rows($existing, $ignoreId);
        $name = $data['name'];
        $type = $data['type'];

        foreach ($rows as $r) {
            if ($r['name'] !== $name) {
                continue;
            }
            if ($r['type'] === $type && $this->sameRdata($type, $r, $data)) {
                return $this->err('duplicate');
            }
            if ($type === 'CNAME') {
                return $this->err('cname_conflict', ['name' => $name]);
            }
            if ($r['type'] === 'CNAME') {
                return $this->err('cname_exists', ['name' => $name]);
            }
        }

        // ── برشِ تفویض (NS زیرِ apex) ──
        $delegations = [];
        foreach ($rows as $r) {
            if ($r['type'] === 'NS') {
                $delegations[$r['name']][] = $r['content'];
            }
        }

        if ($type === 'NS') {
            $targets = array_merge($delegations[$name] ?? [], [$data['content']]);
            foreach ($rows as $r) {
                if ($r['type'] === 'NS' && $r['name'] === $name) {
                    continue;
                }
                $atCut = $r['name'] === $name;
                $below = str_ends_with($r['name'], '.'.$name);
                $isGlue = $below && in_array($r['type'], ['A', 'AAAA'], true) && in_array($r['name'], $targets, true);
                if ($atCut || ($below && ! $isGlue)) {
                    return $this->err('ns_occludes', ['name' => $name, 'record' => $r['name']]);
                }
            }
            // NS زیرِ برشِ دیگری هم پشتِ آن تفویض پنهان است (§6.2: «جز glue هیچ‌چیز زیرِ برش»)
            foreach (array_keys($delegations) as $cut) {
                if ($cut !== $name && str_ends_with($name, '.'.$cut)) {
                    return $this->err('delegated_name', ['name' => $cut]);
                }
            }

            return null;
        }

        foreach ($delegations as $cut => $targets) {
            if ($name === $cut) {
                return $this->err('delegated_name', ['name' => $cut]);
            }
            if (str_ends_with($name, '.'.$cut)) {
                $isGlue = in_array($type, ['A', 'AAAA'], true) && in_array($name, $targets, true);
                if (! $isGlue) {
                    return $this->err('delegated_name', ['name' => $cut]);
                }
            }
        }

        return null;
    }

    /**
     * idِ رکوردهای هم‌نام و هم‌نوع که TTLشان باید با رکوردِ تازه یکی شود.
     * TTL مالِ RRset است، نه تک‌رکورد (RFC 2181 §5.2) و PowerDNS هم یک TTL می‌گیرد.
     *
     * @return array<int,int>
     */
    public function ttlSiblings(array $data, iterable $existing, ?int $ignoreId = null): array
    {
        $ids = [];
        foreach ($this->rows($existing, $ignoreId) as $r) {
            if ($r['name'] === $data['name'] && $r['type'] === $data['type'] && (int) $r['ttl'] !== (int) $data['ttl'] && $r['id'] !== null) {
                $ids[] = (int) $r['id'];
            }
        }

        return $ids;
    }

    /* ───────────────────────── نام ───────────────────────── */

    /** @return array{0:string,1:?array} */
    private function ownerName(string $raw, string $zone, string $type): array
    {
        $raw = trim($raw);

        if ($raw === '' || $raw === '@') {
            return [$zone, null];
        }

        $absolute = str_ends_with($raw, '.');
        $raw = rtrim($raw, '.');

        $labels = explode('.', $raw);
        $ascii = [];
        foreach ($labels as $l) {
            $a = $l === '*' ? '*' : DomainName::labelToAscii($l);
            if ($a === null) {
                return [$zone, $this->err('name_invalid', ['label' => $l])];
            }
            $ascii[] = $a;
        }
        $name = implode('.', $ascii);

        if ($absolute) {
            if ($name !== $zone && ! str_ends_with($name, '.'.$zone)) {
                return [$zone, $this->err('name_outside_zone')];
            }
        } elseif ($name !== $zone && ! str_ends_with($name, '.'.$zone)) {
            // نسبی؛ «www.example.com» بی‌نقطه هم اگر به zone ختم شود همان FQDN است
            $name .= '.'.$zone;
        }

        if (strlen($name) > 253) {
            return [$zone, $this->err('name_too_long')];
        }

        $rel = $name === $zone ? [] : explode('.', substr($name, 0, -strlen($zone) - 1));
        foreach ($rel as $i => $label) {
            if ($label === '*') {
                if ($i !== 0) {
                    return [$zone, $this->err('name_wildcard_position')];
                }
                continue;
            }
            if (strlen($label) > 63 || ! preg_match('~^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?$~', $label)) {
                return [$zone, $this->err('name_invalid', ['label' => $label])];
            }
            if (! DomainName::isValidALabel($label)) {
                return [$zone, $this->err('name_invalid', ['label' => $label])];
            }
        }

        if (in_array($type, self::HOST_OWNER_TYPES, true)) {
            // `host._sub` مجاز است (زیرِ گرهٔ صفتیِ «_»دار)؛ `_x` و `a_b` و `x.a_b` نه
            $underParent = false;
            foreach (array_slice($rel, 1) as $parent) {
                if (str_starts_with($parent, '_')) {
                    $underParent = true;
                    break;
                }
            }
            foreach ($rel as $label) {
                if (str_contains($label, '_') && ! $underParent) {
                    return [$zone, $this->err('name_underscore')];
                }
            }
        }

        return [$name, null];
    }

    /**
     * نامِ مقصد (CNAME/NS/MX/SRV). URL و IP رد می‌شوند — رایج‌ترین اشتباه.
     *
     * @return array{0:string,1:?array}
     */
    private function target(string $raw, bool $allowUnderscore, bool $allowRoot = false): array
    {
        $t = strtolower(trim($raw));

        if ($allowRoot && ($t === '.' || $t === '')) {
            return $t === '.' ? ['.', null] : ['', $this->err('target_invalid')];
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~', $t)) {
            return ['', $this->err('target_invalid')];
        }
        $t = rtrim($t, '.');
        if (filter_var($t, FILTER_VALIDATE_IP)) {
            return ['', $this->err('target_is_ip')];
        }

        $labels = $t === '' ? [] : explode('.', $t);
        if (count($labels) < 2 || strlen($t) > 253) {
            return ['', $this->err('target_invalid')];
        }

        $out = [];
        foreach ($labels as $l) {
            $a = DomainName::labelToAscii($l);
            $re = $allowUnderscore ? '~^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?$~' : '~^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$~';
            if ($a === null || strlen($a) > 63 || ! preg_match($re, $a) || ! DomainName::isValidALabel($a)) {
                return ['', $this->err('target_invalid')];
            }
            $out[] = $a;
        }

        return [implode('.', $out), null];
    }

    private function srvOwner(string $name, string $zone): bool
    {
        if ($name === $zone) {
            return false;
        }
        $labels = explode('.', substr($name, 0, -strlen($zone) - 1));

        return count($labels) >= 2
            && preg_match('~^_[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$~', $labels[0])
            && in_array($labels[1], ['_tcp', '_udp', '_tls', '_sctp'], true);
    }

    private function caaValue(string $tag, string $value): bool
    {
        if ($value === '' || preg_match('~["\\\\\x00-\x1F\x7F]~', $value) || strlen($value) > 255) {
            return false;
        }
        if ($tag === 'iodef') {
            return (bool) preg_match('~^(mailto:[^\s@]+@[^\s@]+|https?://\S+)$~i', $value);
        }
        if ($value === ';') {
            return true;     // «هیچ صادرکننده‌ای مجاز نیست»
        }
        [$issuer] = explode(';', $value, 2);
        $issuer = trim($issuer);

        return $issuer === '' || (bool) preg_match('~^([a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$~i', $issuer);
    }

    /* ───────────────────────── IP ───────────────────────── */

    /** IPv4ِ قابلِ رسیدن از اینترنت — 192.168.x رایج‌ترین کپیِ اشتباه از پنلِ مودم است */
    private function publicIpv4(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $first = (int) explode('.', $ip)[0];
        $long = ip2long($ip);

        // multicast، و CGNAT ‏100.64/10 که فیلترِ PHP نمی‌گیرد
        return $first < 224 && ! ($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
    }

    private function publicIpv6(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $bin = inet_pton($ip);

        // multicast (ff00::/8) و link-local (fe80::/10)
        return ord($bin[0]) !== 0xFF && ! (ord($bin[0]) === 0xFE && (ord($bin[1]) & 0xC0) === 0x80);
    }

    /* ───────────────────────── کمکی ───────────────────────── */

    /** @return array<int,array{id:?int,type:string,name:string,content:string,prio:?int,ttl:int}> */
    private function rows(iterable $existing, ?int $ignoreId): array
    {
        $out = [];
        foreach ($existing as $r) {
            $r = is_array($r) ? $r : (method_exists($r, 'toArray') ? $r->toArray() : (array) $r);
            $id = isset($r['id']) ? (int) $r['id'] : null;
            if ($ignoreId !== null && $id === $ignoreId) {
                continue;
            }
            $out[] = [
                'id'      => $id,
                'type'    => strtoupper((string) ($r['type'] ?? '')),
                'name'    => strtolower(rtrim((string) ($r['name'] ?? ''), '.')),
                'content' => (string) ($r['content'] ?? ''),
                'prio'    => isset($r['prio']) ? (int) $r['prio'] : null,
                'ttl'     => (int) ($r['ttl'] ?? 0),
            ];
        }

        return $out;
    }

    private function sameRdata(string $type, array $r, array $d): bool
    {
        if ((int) ($r['prio'] ?? 0) !== (int) ($d['prio'] ?? 0)) {
            return false;
        }
        if ($type === 'TXT') {
            return TxtCodec::equals($r['content'], $d['content']);
        }

        return strtolower(trim($r['content'])) === strtolower(trim($d['content']));
    }

    private function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('~^\s*\d{1,10}\s*$~', $v)) {
            return (int) $v;
        }

        return null;
    }

    /** @return array{code:string,params:array} */
    private function err(string $code, array $params = []): array
    {
        return ['code' => $code, 'params' => $params];
    }

    private function fail(array $errors): array
    {
        return ['ok' => false, 'errors' => $errors, 'data' => []];
    }
}
