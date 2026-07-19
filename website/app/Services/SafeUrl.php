<?php

namespace App\Services;

/**
 * نگهبان SSRF.
 *
 * ابزارهای سایت (بررسی سئو، SSL، پورت، DNS) آدرسی را می‌گیرند که کاربر وارد کرده
 * و به آن درخواست می‌زنند. بدون این نگهبان، مهاجم می‌تواند سرور ما را وادار کند
 * به شبکه‌ی داخلی یا سرویس متادیتای ابری درخواست بزند و پاسخ را ببیند.
 *
 * دو لایه‌ی محافظت:
 *   ۱) فقط http/https — جلوی file:// و gopher:// و … را می‌گیرد
 *   ۲) آدرس مقصد پس از resolve باید عمومی باشد؛ لوکال‌هاست، شبکه‌ی خصوصی،
 *      link-local (شامل 169.254.169.254 متادیتا) و بازه‌های رزرو رد می‌شوند
 *
 * نکته‌ی مهم: ریدایرکت باید دستی و با اعتبارسنجی مجدد هر پرش دنبال شود، وگرنه
 * یک دامنه‌ی عمومی می‌تواند به آدرس داخلی ریدایرکت کند و همه‌ی این بررسی‌ها بی‌اثر شود.
 */
class SafeUrl
{
    /** حداکثر پرش ریدایرکت که دستی دنبال می‌کنیم */
    public const MAX_REDIRECTS = 4;

    /** آیا این آدرس برای درخواست سرور-ساید امن است؟ */
    public static function allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return self::hostResolvesPublicly($parts['host']);
    }

    /** هاست به آدرس عمومی resolve می‌شود؟ (اگر خودش IP باشد، همان بررسی می‌شود) */
    public static function hostResolvesPublicly(string $host): bool
    {
        $host = trim($host, "[]");

        // اگر ورودی خودش IP است
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        $ips = self::resolveAll($host);
        if (! $ips) {
            return false;   // resolve نشد → اجازه نده
        }

        // اگر حتی یک رکورد به آدرس غیرعمومی اشاره کند، کل هاست رد می‌شود
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    public static function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /** همه‌ی رکوردهای A و AAAA هاست */
    private static function resolveAll(string $host): array
    {
        $ips = [];

        $a = @dns_get_record($host, DNS_A);
        foreach ($a ?: [] as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach ($aaaa ?: [] as $r) {
            if (! empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        if (! $ips) {
            $one = @gethostbyname($host);
            if ($one && $one !== $host) {
                $ips[] = $one;
            }
        }

        return array_unique($ips);
    }

    /** گزینه‌های curl که باید روی هر درخواست به آدرس کاربر اعمال شود */
    public static function curlOptions(): array
    {
        $opts = [
            CURLOPT_FOLLOWLOCATION => false,   // ریدایرکت دستی دنبال می‌شود
        ];

        // محدودکردن پروتکل‌ها (نام گزینه بین نسخه‌های curl فرق دارد)
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLPROTO_HTTP')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        return $opts;
    }

    /**
     * دنبال‌کردن امن ریدایرکت: هر پرش دوباره اعتبارسنجی می‌شود.
     * خروجی: آدرس نهایی امن، یا null اگر زنجیره به جای ناامن رسید.
     */
    public static function resolveRedirects(string $url, callable $head): ?string
    {
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            if (! self::allowed($url)) {
                return null;
            }
            $next = $head($url);
            if (! $next) {
                return $url;                      // ریدایرکتی نبود
            }
            $url = self::absolutize($url, $next);
        }

        return null;                              // حلقه‌ی ریدایرکت
    }

    private static function absolutize(string $base, string $next): string
    {
        if (preg_match('~^https?://~i', $next)) {
            return $next;
        }
        $p = parse_url($base);
        $root = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');

        return $root.'/'.ltrim($next, '/');
    }
}
