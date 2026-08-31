<?php

namespace App\Services\Dns;

/**
 * پرس‌وجوی خامِ DNS — نازک عمداً، تا بتوان در تست جایش را گرفت.
 *
 * چرا کلاسِ جدا و نه فراخوانیِ مستقیم داخلِ منطق: هر تستی که وضعیتِ DNSِ یک
 * دامنه را بسنجد وگرنه به اینترنت وابسته می‌شود — یعنی روی CI و روی ماشینی
 * که فیلتر دارد قرمز می‌شود، و بدتر: نتیجه‌اش با عوض‌شدنِ DNSِ یک مشتریِ
 * واقعی عوض می‌شود. این‌جا تنها لایه‌ای است که شبکه را لمس می‌کند.
 */
class DnsLookup
{
    /**
     * نیم‌سرورهای authoritativeِ یک دامنه.
     *
     * @return list<string> بدونِ نقطهٔ انتهایی، همه کوچک‌حرف
     */
    public function nameservers(string $domain): array
    {
        $rows = @dns_get_record($domain, DNS_NS) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn ($r) => strtolower(rtrim((string) ($r['target'] ?? ''), '. ')),
            $rows
        ))));
    }

    /**
     * IPی که دامنه به آن اشاره می‌کند، یا null.
     *
     * ⚠️ عمداً `gethostbyname()` و نه `dns_get_record(..., DNS_A)`:
     * روی PHPِ ویندوز دومی برای دامنه‌هایی که واقعاً رکوردِ A دارند آرایهٔ
     * **خالی** برمی‌گرداند (با `igniran.ir` سنجیده شد؛ nslookup جواب می‌داد و
     * PHP نه). `gethostbyname` از resolverِ خودِ سیستم‌عامل می‌رود و همان‌جا
     * درست جواب داد. تفاوتش بی‌صداست: «هیچ رکوردی نیست» و «نتوانستم بپرسم»
     * یک شکل دارند، و نتیجه‌اش برچسبِ قرمز روی سایتِ سالمِ مشتری بود.
     */
    public function ip(string $domain): ?string
    {
        $r = @gethostbyname($domain);

        return filter_var($r, FILTER_VALIDATE_IP) ? $r : null;
    }
}
