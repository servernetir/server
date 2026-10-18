<?php

namespace App\Services\Dns\Wire;

/**
 * نتیجهٔ ناموفقِ یک پرس‌وجوی DNS — به‌جای استثنا.
 *
 * فراخوان‌ها (تأیید مالکیت، بررسی نودها، کرون) باید «نتوانستیم بپرسیم» را از
 * «پاسخ منفی گرفتیم» جدا کنند؛ استثنای رهاشده این تمایز را در مسیرِ کرون
 * می‌کشد. پس خطا یک مقدار است با کدِ ثابت، نه متنِ آزاد.
 *
 * `detail` فقط کدِ کوتاهِ داخلی است (مثلاً `short_rdata`، `errno:10061`) و
 * هرگز رازی در آن نیست — این لایه اصلاً رازی نمی‌بیند.
 */
final class DnsFailure
{
    /** مهلت تمام شد و هیچ پاسخِ قابل‌قبولی نرسید */
    public const TIMEOUT = 'timeout';

    /** سوکت رد شد: ICMP port-unreachable روی UDP یا RST روی TCP */
    public const REFUSED_SOCKET = 'refused_socket';

    /** بسته رسید ولی ساختارش خراب/خصمانه بود */
    public const MALFORMED = 'malformed';

    /** پاسخ مالِ پرس‌وجوی ما نبود (شناسه یا سؤالِ ناهم‌خوان) */
    public const ID_MISMATCH = 'id_mismatch';

    /** خطای دیگرِ شبکه/سوکت یا ورودیِ نامعتبرِ سرور */
    public const NETWORK = 'network';

    public function __construct(
        public readonly string $code,
        public readonly ?string $detail = null,
    ) {}

    public function isTimeout(): bool
    {
        return $this->code === self::TIMEOUT;
    }
}
