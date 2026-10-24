<?php

namespace App\Services\Dns;

/**
 * همان قواعدِ `RecordValidator::conflicts()`، ولی با نمایه — برای واردکردن.
 *
 * 🔴 چرا: پیش‌نمایشِ واردکردن برای هر ردیف `conflicts()` را روی همهٔ ردیف‌های
 * موجود و پذیرفته‌شده اجرا می‌کرد و هر مقایسهٔ TXT هر دو رشته را از نو decode
 * می‌کرد — درجهٔ دو. ۸۰۰ ردیفِ TXTِ هم‌نام ۲۷ ثانیه طول کشید و فایلی زیرِ سقفِ
 * ۱MB حدودِ ۱۷ دقیقه CPU از یک worker می‌گرفت (و apply همان را زیرِ قفلِ zone).
 * این‌جا هر ردیف یک بار کلید می‌شود و بررسیِ تکراری/CNAME O(1) است؛ فقط بررسیِ
 * NS روی نام‌ها می‌گردد، و شمارِ نام‌ها با سقفِ رکوردِ zone محدود است.
 *
 * تفاوتِ عمدی با `conflicts()` فقط در دادهٔ از پیش نامعتبر است (CNAME کنارِ
 * رکوردِ دیگر): این‌جا «تکراری» بر «cname_exists» مقدم است.
 */
final class RecordSetIndex
{
    /** @var array<string,true> name|type|prio|rdata */
    private array $dup = [];

    /** @var array<string,string> name => کلیدِ اولین ردیفِ همان نام */
    private array $first = [];

    /** @var array<string,array<string,true>> name => نوع‌ها */
    private array $types = [];

    /** @var array<string,list<string>> نامِ برش => هدف‌های NS */
    private array $cuts = [];

    /** @param iterable<array|object> $rows ردیف‌های موجود (مثلِ ورودیِ conflicts) */
    public function __construct(iterable $rows = [])
    {
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : (method_exists($r, 'toArray') ? $r->toArray() : (array) $r);
            $this->add([
                'type'    => strtoupper((string) ($r['type'] ?? '')),
                'name'    => strtolower(rtrim((string) ($r['name'] ?? ''), '.')),
                'content' => (string) ($r['content'] ?? ''),
                'prio'    => isset($r['prio']) ? (int) $r['prio'] : null,
            ]);
        }
    }

    public function add(array $d): void
    {
        $key = self::key($d);
        $this->dup[$key] = true;
        $this->first[$d['name']] ??= $key;
        $this->types[$d['name']][$d['type']] = true;
        if ($d['type'] === 'NS') {
            $this->cuts[$d['name']][] = $d['content'];
        }
    }

    /** @return array{code:string,params:array}|null همان کدهای conflicts() */
    public function conflict(array $d): ?array
    {
        $name = $d['name'];
        $type = $d['type'];
        $key = self::key($d);

        if (isset($this->types[$name])) {
            if ($type === 'CNAME') {
                // در conflicts() اولین ردیفِ همین نام تصمیم می‌گیرد
                return $this->first[$name] === $key ? self::err('duplicate') : self::err('cname_conflict', ['name' => $name]);
            }
            if (isset($this->dup[$key])) {
                return self::err('duplicate');
            }
            if (isset($this->types[$name]['CNAME'])) {
                return self::err('cname_exists', ['name' => $name]);
            }
        }

        if ($type === 'NS') {
            $targets = array_merge($this->cuts[$name] ?? [], [$d['content']]);
            foreach ($this->types as $n => $types) {
                if ($n === $name) {
                    if (array_diff_key($types, ['NS' => true]) !== []) {
                        return self::err('ns_occludes', ['name' => $name, 'record' => $n]);
                    }

                    continue;
                }
                if (! str_ends_with($n, '.'.$name)) {
                    continue;
                }
                $glueOnly = in_array($n, $targets, true) && array_diff_key($types, ['A' => true, 'AAAA' => true]) === [];
                if (! $glueOnly) {
                    return self::err('ns_occludes', ['name' => $name, 'record' => $n]);
                }
            }
            foreach (array_keys($this->cuts) as $cut) {
                if ($cut !== $name && str_ends_with($name, '.'.$cut)) {
                    return self::err('delegated_name', ['name' => $cut]);
                }
            }

            return null;
        }

        foreach ($this->cuts as $cut => $targets) {
            if ($name === $cut) {
                return self::err('delegated_name', ['name' => $cut]);
            }
            if (str_ends_with($name, '.'.$cut)) {
                $isGlue = in_array($type, ['A', 'AAAA'], true) && in_array($name, $targets, true);
                if (! $isGlue) {
                    return self::err('delegated_name', ['name' => $cut]);
                }
            }
        }

        return null;
    }

    /** همان برابریِ `sameRdata`: prio، و برای TXT برابری در سطحِ سیم */
    private static function key(array $d): string
    {
        $rdata = $d['type'] === 'TXT'
            ? json_encode(TxtCodec::decode($d['content']))
            : strtolower(trim((string) $d['content']));

        return $d['name'].'|'.$d['type'].'|'.(int) ($d['prio'] ?? 0).'|'.$rdata;
    }

    private static function err(string $code, array $params = []): array
    {
        return ['code' => $code, 'params' => $params];
    }
}
