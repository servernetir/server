<?php

namespace App\Services\Dns\Wire;

/**
 * پاسخِ تجزیه‌شدهٔ DNS — فقط داده، بدونِ تفسیر.
 *
 * تفسیر (ارجاع است؟ پاسخِ معتبر است؟) کارِ DelegationInspector است؛ این شیء
 * عمداً بی‌نظر می‌مانَد تا یک پاسخ را بشود با دو سیاستِ متفاوت سنجید.
 *
 * هر رکورد یک آرایه است:
 *   ['name' => string, 'type' => string, 'typeCode' => int, 'class' => int, 'ttl' => int, 'data' => mixed]
 * نام‌ها حروف‌کوچک، بی نقطهٔ انتهایی؛ ریشه = '.'. بایت‌های غیرچاپی/نقطهٔ درونِ
 * برچسب به شکلِ presentation (`\DDD`، `\.`) فرار داده می‌شوند.
 *
 * شکلِ `data` به ازای نوع:
 *   A/AAAA → string (IP)          NS/CNAME/PTR → string (نام)
 *   SOA → array{mname,rname,serial,refresh,retry,expire,minimum}
 *   MX → array{preference,exchange}   SRV → array{priority,weight,port,target}
 *   TXT → string[] (رشته‌های بایتیِ خام، مرزها حفظ)
 *   CAA → array{flags,tag,value}   DS/CDS → array{key_tag,algorithm,digest_type,digest(hex)}
 *   ناشناخته → string (بایت‌های خامِ RDATA)
 */
final class DnsResponse
{
    /**
     * @param  array{name:string,type:int,class:int}|null  $question
     * @param  list<array<string,mixed>>  $answers
     * @param  list<array<string,mixed>>  $authority
     * @param  list<array<string,mixed>>  $additional  (بدونِ OPT؛ OPT در $edns است)
     * @param  array{udp_size:int,version:int,do:bool,options:string}|null  $edns
     */
    public function __construct(
        public readonly int $id,
        public readonly int $opcode,
        public readonly bool $qr,
        public readonly bool $aa,
        public readonly bool $tc,
        public readonly bool $rd,
        public readonly bool $ra,
        public readonly bool $ad,
        public readonly bool $cd,
        public readonly int $rcode,
        public readonly ?array $question,
        public readonly array $answers,
        public readonly array $authority,
        public readonly array $additional,
        public readonly ?array $edns,
        public readonly string $transport = 'udp',
        public readonly int $size = 0,
    ) {}

    public function rcodeName(): string
    {
        return DnsMessage::rcodeName($this->rcode);
    }

    public function isNoError(): bool
    {
        return $this->rcode === 0;
    }

    public function isNxDomain(): bool
    {
        return $this->rcode === 3;
    }

    /**
     * رکوردهای یک بخش، با فیلترِ اختیاریِ نوع و مالک.
     *
     * @param  'answer'|'authority'|'additional'  $section
     * @return list<array<string,mixed>>
     */
    public function records(string $section, ?string $type = null, ?string $owner = null): array
    {
        $rows = match ($section) {
            'answer'     => $this->answers,
            'authority'  => $this->authority,
            'additional' => $this->additional,
            default      => [],
        };

        $type = $type !== null ? strtoupper($type) : null;
        $owner = $owner !== null ? DnsMessage::normalizeName($owner) : null;

        return array_values(array_filter(
            $rows,
            fn (array $r) => ($type === null || $r['type'] === $type) && ($owner === null || $r['name'] === $owner)
        ));
    }
}
