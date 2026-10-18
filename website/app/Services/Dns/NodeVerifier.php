<?php

namespace App\Services\Dns;

use App\Models\CdnZone;

/**
 * پرس‌وجوی مستقیمِ SOA از هر نیم‌سرورِ عمومی و ثبتِ وضعیتش در dns_zone_nodes — طراحی §4.3.
 *
 * SKELETON (wave 2 پیاده‌سازی می‌کند). امضاها قرارداد است و تغییر نمی‌کند.
 */
class NodeVerifier
{
    /**
     * @return array<string, array{state:string, serial:?int, error:?string}>  کلید = hostِ نیم‌سرور
     *         state: unknown|in_sync|lagging|missing|unreachable
     */
    public function check(CdnZone $zone): array
    {
        throw new \LogicException('NodeVerifier::check not implemented yet');
    }
}
