<?php

namespace App\Services\Dns\ZoneFile;

/**
 * خروجیِ ZoneFileParser — ردیف به ردیف، هرگز استثنا نه.
 *
 * هر ردیف: line, name (FQDNِ ASCII بی‌نقطه), type, ttl (?int), input (آرایهٔ ورودیِ
 * RecordValidator), status (`parsed|skipped|unsupported|invalid`), code (?string, کلیدِ dns.err/dns.import).
 */
final class ParsedZone
{
    /**
     * @param  list<array{line:int,name:?string,type:?string,ttl:?int,input:array,status:string,code:?string}>  $rows
     */
    public function __construct(
        public readonly string $origin,
        public readonly array $rows,
        public readonly bool $truncated = false,
        public readonly ?string $fatal = null,
    ) {}

    /** @return list<array> */
    public function parsed(): array
    {
        return array_values(array_filter($this->rows, fn ($r) => $r['status'] === 'parsed'));
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        $c = ['parsed' => 0, 'skipped' => 0, 'unsupported' => 0, 'invalid' => 0];
        foreach ($this->rows as $r) {
            $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
        }

        return $c;
    }
}
