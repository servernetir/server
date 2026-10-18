<?php

namespace App\Services\Dns;

use App\Models\CdnZone;

/**
 * انتشارِ وضعیتِ مطلوبِ یک zone روی primaryِ PowerDNS — طراحی §4.
 *
 * SKELETON (wave 2 پیاده‌سازی می‌کند). امضاها قرارداد است و تغییر نمی‌کند.
 */
class ZonePublisher
{
    /**
     * پس از commitِ تراکنشِ جاری یک تلاشِ فوری برای انتشار می‌کند (هرگز استثنا نمی‌دهد).
     * بیرون از تراکنش، همان لحظه تلاش می‌کند.
     */
    public function requestPublish(CdnZone $zone): void
    {
        throw new \LogicException('ZonePublisher::requestPublish not implemented yet');
    }

    /** یک اجرای کامل با قفلِ zone، snapshotِ نسخه، diff، PATCH، خواندنِ serial، backoff */
    public function publish(CdnZone $zone): PublishResult
    {
        throw new \LogicException('ZonePublisher::publish not implemented yet');
    }
}
