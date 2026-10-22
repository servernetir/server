<?php

namespace App\Services\Dns;

/**
 * پذیرشِ zoneِ اثبات‌شده ممکن نیست — تراکنش باید برگردد تا ادعا «در انتظار» بماند.
 *
 * ⚠️ پیش‌تر `adoptVerified` در هر دو حالت null برمی‌گرداند و ادعا پیش از آن
 * VERIFIED ثبت شده بود: مشتری‌ای که درست وسطِ حذفِ zoneِ مالکِ قبلی اثبات
 * می‌کرد «به سقف رسیده‌اید» می‌دید و TXTِ منتشرشده‌اش هم بی‌مصرف می‌شد.
 */
final class AdoptRefused extends \RuntimeException
{
    /** needs_txt: انتقالِ zoneِ حسابِ دیگر فقط با TXT (نه با ردیفِ Domainِ محلی) */
    public const REASONS = ['zone_deleting', 'zone_limit', 'needs_txt'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
