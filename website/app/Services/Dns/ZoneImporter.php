<?php

namespace App\Services\Dns;

use App\Models\CdnZone;
use App\Services\NetworkTools;

/**
 * واردکردنِ رکوردهای فعلیِ یک دامنه، پیش از تعویضِ نیم‌سرور.
 *
 * ═══ 🔴 محدودیتی که باید همه‌جا صریح گفته شود ═══
 *
 * DNS راهی برای **فهرست‌کردنِ** یک zone ندارد (AXFR روی هیچ سرورِ سالمی برای
 * عموم باز نیست). پس این واردکننده فقط می‌تواند بپرسد «آیا این نامِ مشخص
 * وجود دارد؟» — یعنی apex به‌علاوهٔ فهرستی از زیردامنه‌های متعارف.
 *
 * نتیجه: خروجی **کامل نیست و نمی‌تواند باشد.** اگر این جمله در پنل به مشتری
 * گفته نشود، او نیم‌سرور را عوض می‌کند و زیردامنه‌ای که ما ندیده‌ایم بی‌صدا
 * ناپدید می‌شود — دقیقاً همان خرابیِ خاموشی که در این پروژه گران‌ترین‌ها
 * بوده‌اند. تأییدِ آگاهانهٔ مشتری روی همین فهرست، بخشی از طراحی است نه تشریفات.
 *
 * ⚠️ رکوردهای MX/TXT/NS در `config('edge.critical_types')` علامت‌دار
 * برمی‌گردند تا رابط بتواند جداگانه نشانشان دهد: نبودنشان یعنی قطعِ ایمیل.
 */
class ZoneImporter
{
    /**
     * زیردامنه‌هایی که واقعاً پرسیده می‌شوند.
     *
     * فهرست عمداً کوتاه است: هر نام یک پرس‌وجوی جداست و این تابع در مسیرِ
     * تعاملیِ کاربر اجرا می‌شود. اینها پوششِ عملیِ هاستِ اشتراکی و ایمیلِ
     * شرکتی‌اند — همان‌هایی که نبودشان بیشترین درد را می‌سازد.
     */
    private const PROBES = [
        'www', 'mail', 'webmail', 'ftp', 'cpanel', 'autodiscover',
        'autoconfig', 'smtp', 'imap', 'pop', 'blog', 'shop', 'api', 'panel', 'm',
    ];

    /**
     * نام‌های TXTِ ایمیل. 🔴 DMARC و DKIM زیرِ نام‌های ثابت‌اند و نبودشان پس
     * از تعویضِ نیم‌سرور یعنی ایمیلِ شرکت بی‌صدا به اسپم می‌رود — هیچ خطایی،
     * فقط «مشتری‌ها می‌گویند ایمیلتان نمی‌رسد».
     */
    private const TXT_PROBES = [
        '_dmarc', 'default._domainkey', 'google._domainkey',
        'selector1._domainkey', 'selector2._domainkey', 'k1._domainkey', 'mail._domainkey',
    ];

    public function __construct(private readonly NetworkTools $net = new NetworkTools) {}

    /**
     * @return array{ok:bool, records:array<int,array<string,mixed>>, partial:bool}
     */
    public function preview(string $domain): array
    {
        $domain = CdnZone::normalizeDomain($domain);
        $out = [];

        $apex = $this->net->allDns($domain);

        if (($apex['ok'] ?? false) !== true) {
            return ['ok' => false, 'records' => [], 'partial' => true];
        }

        foreach ($apex['groups'] ?? [] as $group) {
            $type = (string) ($group['type'] ?? '');

            /*
             * SOA و NS ِ فعلی وارد نمی‌شوند.
             *
             * SOA را PowerDNS خودش می‌سازد، و NSِ قدیمی اگر وارد شود، zoneِ
             * تازه به نیم‌سرورهای **قبلی** اشاره می‌کند — یعنی حلقه‌ای که
             * تفویض را می‌شکند و عیب‌یابی‌اش بسیار سخت است.
             */
            if (in_array($type, ['SOA', 'NS'], true)) {
                continue;
            }

            foreach ($group['records'] ?? [] as $rec) {
                $out[] = $this->normalize($domain, $type, $rec);
            }
        }

        foreach (self::PROBES as $label) {
            $host = $label.'.'.$domain;

            foreach (['A', 'CNAME'] as $type) {
                $res = $this->net->dns($host, $type);

                if (($res['ok'] ?? false) !== true) {
                    continue;
                }

                foreach ($res['records'] ?? [] as $rec) {
                    // DoH برای CNAME هم رکوردِ A مقصد را برمی‌گرداند؛ فقط
                    // ردیفی را می‌گیریم که نامش دقیقاً همین میزبان است،
                    // وگرنه رکوردِ مقصد به نامِ مشتری ثبت می‌شود.
                    if (mb_strtolower(rtrim((string) ($rec['name'] ?? ''), '.')) !== $host) {
                        continue;
                    }

                    $out[] = $this->normalize($domain, $type, $rec);
                }

                // اگر CNAME دارد، رکوردِ A بی‌معناست (و هم‌زمان نامعتبر است)
                if ($type === 'CNAME' && $res['count'] > 0) {
                    break;
                }
            }
        }

        foreach (self::TXT_PROBES as $label) {
            $host = $label.'.'.$domain;
            $res = $this->net->dns($host, 'TXT');

            foreach (($res['ok'] ?? false) ? ($res['records'] ?? []) : [] as $rec) {
                if (mb_strtolower(rtrim((string) ($rec['name'] ?? ''), '.')) === $host) {
                    $out[] = $this->normalize($domain, 'TXT', $rec);
                }
            }
        }

        return ['ok' => true, 'records' => $this->dedupe($out), 'partial' => true];
    }

    /* ───────────────────────── داخلی ───────────────────────── */

    private function normalize(string $domain, string $type, array $rec): array
    {
        // گیومه‌های TXT دست نمی‌خورند: «"a" "b"» (رکوردِ تکه‌تکه) را
        // `RecordValidator` درست به‌هم می‌چسباند؛ trimِ این‌جا وسطش را می‌شکست
        $data = $type === 'TXT'
            ? trim((string) ($rec['data'] ?? ''))
            : trim((string) ($rec['data'] ?? ''), " \t\"");
        $prio = null;

        // MX از DoH به شکلِ «10 mail.example.com.» می‌آید؛ اولویت را جدا می‌کنیم
        if ($type === 'MX' && preg_match('~^(\d+)\s+(.+)$~', $data, $m)) {
            $prio = (int) $m[1];
            $data = rtrim($m[2], '.');
        }

        $name = rtrim((string) ($rec['name'] ?? $domain), '.');

        return [
            'type'     => $type,
            'name'     => $name === $domain ? '@' : preg_replace('~\.'.preg_quote($domain, '~').'$~', '', $name),
            'content'  => rtrim($data, '.') !== '' && in_array($type, ['CNAME', 'MX', 'NS'], true) ? rtrim($data, '.') : $data,
            'ttl'      => (int) ($rec['ttl'] ?? config('edge.default_ttl', 3600)),
            'prio'     => $prio,
            'critical' => in_array($type, (array) config('edge.critical_types', []), true),
        ];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function dedupe(array $rows): array
    {
        $seen = [];
        $out = [];

        foreach ($rows as $r) {
            $key = $r['type'].'|'.$r['name'].'|'.$r['content'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $r;
        }

        return $out;
    }
}
