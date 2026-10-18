<?php

namespace App\Services\Dns\ZoneFile;

use App\Services\Dns\DomainName;

/**
 * فایلِ zone به قالبِ BIND — برای پشتیبانِ مشتری و بردن به هر ارائه‌دهندهٔ دیگری.
 *
 * ویژگیِ لازم (تست قفلش کرده): parse(write(z)) همان مجموعهٔ رکورد را می‌دهد.
 * خروجی قطعی است (ترتیبِ ثابت) تا دو خروجیِ پشتِ‌هم diffِ معنادار داشته باشند.
 */
class ZoneFileWriter
{
    private const ORDER = ['A' => 1, 'AAAA' => 2, 'CNAME' => 3, 'MX' => 4, 'TXT' => 5, 'SRV' => 6, 'CAA' => 7, 'NS' => 8];

    /**
     * @param  iterable<object|array>  $records  قالبِ ذخیره (type,name,content,ttl,prio,disabled)
     * @param  array<int,string>  $nameservers  NSِ apex (مدیریت‌شده)
     */
    public function write(string $zone, iterable $records, array $nameservers, int $defaultTtl = 3600, ?string $stamp = null): string
    {
        $zone = strtolower(rtrim($zone, '.'));
        $rows = [];
        foreach ($records as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $rows[] = $r;
        }
        usort($rows, fn ($a, $b) => [self::ORDER[$a['type']] ?? 99, $a['name'] === $zone ? '' : $a['name'], (int) ($a['prio'] ?? 0), $a['content']]
            <=> [self::ORDER[$b['type']] ?? 99, $b['name'] === $zone ? '' : $b['name'], (int) ($b['prio'] ?? 0), $b['content']]);

        $out = [
            '; zone file for '.$zone.' ('.DomainName::toUnicode($zone).')',
            '; exported from ServerNet Managed DNS'.($stamp ? ' at '.$stamp : ''),
            '; SOA and the apex NS records are managed by ServerNet.',
            '$ORIGIN '.$zone.'.',
            '$TTL '.$defaultTtl,
            '',
        ];

        foreach ($nameservers as $ns) {
            $out[] = $this->line('@', 86400, 'NS', rtrim(strtolower($ns), '.').'.');
        }

        foreach ($rows as $r) {
            $owner = $r['name'] === $zone ? '@' : substr($r['name'], 0, -strlen($zone) - 1);
            $value = match ($r['type']) {
                'CNAME', 'NS' => $r['content'].'.',
                'MX'          => (int) $r['prio'].' '.($r['content'] === '.' ? '.' : $r['content'].'.'),
                'SRV'         => (int) $r['prio'].' '.$this->srv($r['content']),
                default       => $r['content'],     // A/AAAA/TXT (presentation)/CAA ('0 issue "x"')
            };
            $line = $this->line($owner, (int) $r['ttl'], $r['type'], $value);
            $out[] = ! empty($r['disabled']) ? '; (disabled) '.$line : $line;
        }

        return implode("\n", $out)."\n";
    }

    private function srv(string $content): string
    {
        $p = preg_split('~\s+~', trim($content)) ?: [];
        $target = (string) end($p);
        $p[count($p) - 1] = $target === '.' ? '.' : $target.'.';

        return implode(' ', $p);
    }

    private function line(string $owner, int $ttl, string $type, string $value): string
    {
        return sprintf('%-40s %-7d IN %-6s %s', $owner, $ttl, $type, $value);
    }
}
