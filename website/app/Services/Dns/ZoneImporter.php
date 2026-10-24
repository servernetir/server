<?php

namespace App\Services\Dns;

/**
 * اسکنِ کمکیِ رکوردهای فعلیِ یک دامنه — قرارداد §7.
 *
 * ═══ 🔴 محدودیتی که رابط باید صریح بگوید ═══
 * DNS راهی برای فهرست‌کردنِ کاملِ یک zone ندارد. این‌جا فقط apex و فهرستی از نام‌های
 * رایج و selectorهای متداولِ DKIM پرسیده می‌شود. رکوردِ دلخواه، selectorِ دیگر یا
 * زیردامنهٔ ناشناخته **پیدا نمی‌شود**. مسیرِ قابلِ اتکا فایلِ zone است (ZoneFileParser).
 *
 * پرس‌وجوها مستقیم به نیم‌سرورهای **فعلیِ** دامنه (یافته از والد) و بی‌RD است؛
 * کشِ resolverِ عمومی می‌تواند دادهٔ کهنه بدهد و ما همان را به‌جای حقیقت وارد می‌کردیم.
 *
 * خروجی: ردیف‌هایی با همان شکلِ ورودیِ RecordValidator؛ تصمیمِ «تازه/تکراری/تعارض»
 * با ZoneService::importPreview است، مثلِ فایلِ zone.
 */
class ZoneImporter
{
    public const HOST_PROBES = ['www', 'mail', 'webmail', 'ftp', 'autodiscover', 'autoconfig', 'smtp', 'imap', 'pop',
        'cpanel', 'blog', 'shop', 'api', 'm', 'app', 'portal', 'vpn'];

    public const DKIM_SELECTORS = ['default', 'google', 'selector1', 'selector2', 'k1', 'mail', 'dkim', 's1', 's2'];

    public function __construct(private readonly DelegationInspector $inspector) {}

    /**
     * 🔴 کلِ اسکن زیرِ **یک** بودجه (۲۰ ثانیه / ۸۰ پرس‌وجو، `DelegationInspector::batch`).
     * پیش‌تر هر پرس‌وجو بودجهٔ تازه می‌گرفت: نیم‌سرورهای این دامنه را مشتری تعیین
     * می‌کند و با ۸ IPِ ساکت، یک درخواست ۶۰۰ پرس‌وجو و حدودِ ۲۰ دقیقه worker می‌گرفت.
     * پس از تمام‌شدنِ بودجه اسکن متوقف می‌شود و `partial=true` برمی‌گردد.
     *
     * @return array{ok:bool, error:?string, rows:list<array>, queried:int, partial:bool}
     */
    public function scan(string $domain): array
    {
        return $this->inspector->batch(fn (): array => $this->scanNow($domain));
    }

    private function scanNow(string $domain): array
    {
        $delegation = $this->inspector->parentDelegation($domain);
        if (! $delegation['ok'] && ($delegation['servers'] ?? []) === []) {
            return ['ok' => false, 'error' => (string) ($delegation['error'] ?? 'lookup_failed'), 'rows' => [], 'queried' => 0, 'partial' => false];
        }

        $plan = [];
        foreach (['A', 'AAAA', 'MX', 'TXT', 'CAA'] as $t) {
            $plan[] = [$domain, $t];
        }
        foreach (self::HOST_PROBES as $h) {
            $plan[] = [$h.'.'.$domain, 'CNAME'];
            $plan[] = [$h.'.'.$domain, 'A'];
            $plan[] = [$h.'.'.$domain, 'AAAA'];
        }
        $plan[] = ['_dmarc.'.$domain, 'TXT'];
        foreach (self::DKIM_SELECTORS as $s) {
            $plan[] = [$s.'._domainkey.'.$domain, 'TXT'];
            $plan[] = [$s.'._domainkey.'.$domain, 'CNAME'];
        }

        $rows = [];
        $seen = [];
        $cnamed = [];
        $queried = 0;
        $partial = false;
        foreach ($plan as [$name, $type]) {
            if (isset($cnamed[$name]) && $type !== 'CNAME') {
                continue;                       // نامِ مستعار رکوردِ دیگری ندارد
            }
            $r = $this->inspector->queryAuthoritative($domain, $name, $type, $delegation);
            $queried++;
            if (! $r['ok'] && $r['error'] === 'budget_exhausted') {
                $partial = true;

                break;
            }
            if (! $r['ok']) {
                continue;
            }
            foreach ($r['records'] as $rr) {
                if ($rr['type'] === 'CNAME' && $type !== 'CNAME') {
                    continue;
                }
                if ($rr['type'] !== $type) {
                    continue;
                }
                if ($type === 'CNAME') {
                    $cnamed[$name] = true;
                }
                $row = $this->row($name, $type, $rr);
                if ($row === null) {
                    continue;
                }
                $key = json_encode($row);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = $row;
                }
            }
        }

        return ['ok' => true, 'error' => null, 'rows' => $rows, 'queried' => $queried, 'partial' => $partial];
    }

    /** رکوردِ wire → ورودیِ RecordValidator */
    private function row(string $name, string $type, array $rr): ?array
    {
        $base = ['type' => $type, 'name' => $name.'.', 'ttl' => max(60, min(86400, (int) ($rr['ttl'] ?? 3600)))];
        $d = $rr['data'];

        return match ($type) {
            'A', 'AAAA' => $base + ['content' => (string) $d],
            'CNAME'     => $base + ['content' => rtrim((string) $d, '.').'.'],
            'MX'        => $base + ['prio' => (int) $d['preference'], 'content' => $d['exchange'] === '' ? '.' : rtrim($d['exchange'], '.').'.'],
            'TXT'       => $base + ['content' => implode(' ', array_map(fn ($s) => '"'.str_replace('"', '\"', $s).'"', (array) $d))],
            'CAA'       => $base + ['flags' => (int) $d['flags'], 'tag' => (string) $d['tag'], 'value' => (string) $d['value']],
            default     => null,
        };
    }
}
