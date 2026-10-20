<?php

namespace App\Services\Dns;

use App\Models\CdnZone;
use App\Models\DnsZoneNode;
use App\Support\Rfc1982;

/**
 * پرس‌وجوی مستقیمِ SOA از هر نیم‌سرورِ عمومی و ثبتِ وضعیتش — قرارداد §4.3.
 *
 * 🔴 «منتشر شد» برای مشتری فقط از همین‌جا می‌آید، نه از پاسخِ ۲۰۴ِ API: یعنی
 * هر دو نیم‌سرور با AA و serialِ ≥ serialِ primary (RFC 1982) جواب داده‌اند.
 *
 * پرس‌وجو بی‌RD و مستقیم به IPِ پیکربندی‌شده است؛ کشِ هیچ resolverی در کار نیست.
 * نودِ دارای IPv6ِ پیکربندی‌شده فقط وقتی in_sync است که **هر دو** نشانی باشند.
 */
class NodeVerifier
{
    public function __construct(private readonly DelegationInspector $inspector) {}

    /**
     * @param  bool  $expectAbsent  برای تأییدِ حذف: فقط وضعیت‌ها را ثبت می‌کند
     * @return array<string, array{state:string, serial:?int, error:?string}>
     */
    public function check(CdnZone $zone, bool $expectAbsent = false): array
    {
        $out = [];
        $primary = $zone->primary_serial !== null ? (int) $zone->primary_serial : null;

        foreach (DnsConfig::nameservers() as $ns) {
            $addresses = array_values(array_filter([$ns['ipv4'] ?? null, $ns['ipv6'] ?? null]));
            if ($addresses === []) {
                $out[$ns['host']] = ['state' => DnsZoneNode::UNKNOWN, 'serial' => null, 'error' => 'no_address'];

                continue;
            }

            $worst = null;
            foreach ($addresses as $ip) {
                $one = $this->classify($this->inspector->soaAt($ip, (int) ($ns['port'] ?? 53), $zone->domain), $primary);
                $worst = $worst === null ? $one : $this->worse($worst, $one);
            }
            $out[$ns['host']] = $worst;
        }

        if ($zone->exists) {
            foreach ($out as $host => $s) {
                try {
                    DnsZoneNode::query()->updateOrCreate(
                        ['cdn_zone_id' => $zone->getKey(), 'node' => $host],
                        ['state' => $s['state'], 'serial' => $s['serial'], 'checked_at' => now(), 'last_error' => $s['error']],
                    );
                } catch (\Throwable) {
                    // جدول هنوز نیست یا zone هم‌زمان حذف شد — گزارش همچنان برمی‌گردد
                }
            }
        }

        return $out;
    }

    /** @return array{state:string, serial:?int, error:?string} */
    private function classify(array $soa, ?int $primary): array
    {
        $state = $soa['state'] ?? 'error';

        if ($state === 'answer' && ($soa['aa'] ?? false) && isset($soa['serial'])) {
            $serial = (int) $soa['serial'];
            $ok = $primary === null || Rfc1982::atLeast($serial, $primary);

            return ['state' => $ok ? DnsZoneNode::IN_SYNC : DnsZoneNode::LAGGING, 'serial' => $serial, 'error' => null];
        }

        // فقط این چهار پاسخ یعنی «zone این‌جا نیست» (هنوز نرسیده یا حذف شده).
        // ⚠️ SERVFAIL و دیگر rcodeها «نیست» نیستند — نود شاید zone را دارد و
        // backendش موقتاً خراب است؛ اگر MISSING حساب شوند، تأییدِ حذف (همه MISSING)
        // ردیف‌های zoneی را پاک می‌کند که هنوز روی نیم‌سرور زنده است.
        if (in_array($state, ['refused', 'notauth', 'nxdomain', 'no_aa', 'answer'], true)) {
            return ['state' => DnsZoneNode::MISSING, 'serial' => null, 'error' => $state];
        }

        return ['state' => DnsZoneNode::UNREACHABLE, 'serial' => null, 'error' => (string) ($soa['error'] ?? $state)];
    }

    /** بدترین وضعیت بینِ IPv4 و IPv6 */
    private function worse(array $a, array $b): array
    {
        $rank = [DnsZoneNode::IN_SYNC => 0, DnsZoneNode::LAGGING => 1, DnsZoneNode::MISSING => 2, DnsZoneNode::UNREACHABLE => 3, DnsZoneNode::UNKNOWN => 4];

        return ($rank[$b['state']] ?? 4) > ($rank[$a['state']] ?? 4) ? $b : $a;
    }
}
