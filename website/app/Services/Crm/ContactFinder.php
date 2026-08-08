<?php

namespace App\Services\Crm;

use App\Services\SafeUrl;

/**
 * پیدا کردنِ نشانیِ ایمیلِ **منتشرشدهٔ** کسب‌وکار از روی سایتِ خودش.
 *
 * ⚠️ این کلاس ایمیل حدس نمی‌زند و نمی‌سازد. `info@domain.com` را وقتی دامنه
 * دارد الکی نمی‌نویسد؛ فقط چیزی را برمی‌دارد که خودشان روی سایتشان گذاشته‌اند.
 * دلیلش هم فقط ادب نیست: نشانیِ حدسی معمولاً وجود ندارد، بانس می‌خورد، و چند
 * بانسِ پشتِ هم شهرتِ دامنهٔ فرستنده را می‌سوزاند.
 *
 * همین «منتشرشده بودن» است که ایمیلِ B2B را زیرِ CAN-SPAM/CASL قابلِ دفاع
 * می‌کند — و متنِ امضا هم دقیقاً همین را می‌گوید.
 */
class ContactFinder
{
    /** نشانی‌هایی که ایمیلِ کسب‌وکار نیستند */
    private const NOISE = [
        'example.com', 'sentry.io', 'wixpress.com', 'godaddy.com', 'squarespace.com',
        'domain.com', 'yourdomain', 'email.com', 'wordpress.com', 'schema.org',
    ];

    /** ترجیح: صندوقِ عمومیِ کسب‌وکار، نه ایمیلِ شخصیِ یک کارمند */
    private const PREFERRED = ['info@', 'contact@', 'hello@', 'reception@', 'clinic@', 'enquiries@', 'inquiries@'];

    /**
     * @return array{email: ?string, source: ?string}
     */
    public function find(string $website): array
    {
        $base = rtrim($website, '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $found = [];

        foreach ((array) config('crm.discovery.contact_paths', ['/']) as $path) {
            $url = $base.($path === '/' ? '' : $path);

            if (! SafeUrl::allowed($url)) {
                continue;
            }

            $html = $this->fetch($url);

            if ($html === '') {
                continue;
            }

            foreach ($this->extract($html) as $email) {
                $found[$email] ??= $url;
            }

            // اگر روی همین صفحه صندوقِ عمومیِ خودشان پیدا شد، بس است.
            foreach ($found as $email => $src) {
                if ($this->isPreferred($email) && str_ends_with($email, '@'.$this->apex($host))) {
                    return ['email' => $email, 'source' => $src];
                }
            }
        }

        if ($found === []) {
            return ['email' => null, 'source' => null];
        }

        // ۱) هم‌دامنه و عمومی ۲) هم‌دامنه ۳) هرچه هست
        $apex = $this->apex($host);
        $rank = function (string $e) use ($apex): int {
            $same = str_ends_with($e, '@'.$apex) || str_contains($e, '.'.$apex);

            return match (true) {
                $same && $this->isPreferred($e) => 0,
                $same                           => 1,
                default                         => 2,
            };
        };

        uksort($found, fn ($a, $b) => $rank($a) <=> $rank($b));
        $email = (string) array_key_first($found);

        return ['email' => $email, 'source' => $found[$email]];
    }

    /** @return array<int, string> */
    public function extract(string $html): array
    {
        $out = [];

        // mailto: اول — چون قطعاً نشانیِ تماس است، نه یک رشتهٔ تصادفی در JS
        if (preg_match_all('~mailto:([^"\'?\s>]+)~i', $html, $m)) {
            foreach ($m[1] as $e) {
                $out[] = $e;
            }
        }

        if (preg_match_all('~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}~i', $html, $m)) {
            foreach ($m[0] as $e) {
                $out[] = $e;
            }
        }

        $clean = [];

        foreach ($out as $e) {
            $e = mb_strtolower(trim(rawurldecode($e), " \t\n\r\0\x0B.,;:<>()[]"));

            if (! filter_var($e, FILTER_VALIDATE_EMAIL) || mb_strlen($e) > 190) {
                continue;
            }

            // فایلِ تصویر که شبیهِ ایمیل دیده می‌شود: logo@2x.png
            if (preg_match('~\.(png|jpe?g|gif|svg|webp|css|js)$~i', $e)) {
                continue;
            }

            foreach (self::NOISE as $bad) {
                if (str_contains($e, $bad)) {
                    continue 2;
                }
            }

            $clean[$e] = true;
        }

        return array_keys($clean);
    }

    private function isPreferred(string $email): bool
    {
        foreach (self::PREFERRED as $p) {
            if (str_starts_with($email, $p)) {
                return true;
            }
        }

        return false;
    }

    /** `www.a.co.uk` → `a.co.uk` — برای تشخیصِ «هم‌دامنه» کافی است */
    private function apex(string $host): string
    {
        return (string) preg_replace('~^www\.~', '', $host);
    }

    private function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, SafeUrl::curlOptions() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ServerNetBot/1.0; +https://servernet.cloud)',
            // صفحه‌ی تماس معمولاً کوچک است؛ سقف می‌گذاریم تا یک PDFِ ۵۰ مگابایتی
            // حافظه‌ی کرون را نخورد.
            CURLOPT_BUFFERSIZE     => 16384,
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => fn ($c, $dlTotal, $dlNow) => $dlNow > 2_000_000 ? 1 : 0,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        // یک پرشِ ریدایرکت را دستی دنبال می‌کنیم (خودکارش با نگهبانِ SSRF جور نیست)
        if (in_array($code, [301, 302, 303, 307, 308], true) && $loc !== '' && SafeUrl::allowed($loc)) {
            $ch = curl_init($loc);
            curl_setopt_array($ch, SafeUrl::curlOptions() + [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ServerNetBot/1.0; +https://servernet.cloud)',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        }

        return ($code >= 200 && $code < 300 && is_string($body)) ? $body : '';
    }
}
