<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * مکان‌یابیِ سبکِ IP → کشور + استان، برای لاگِ فعالیتِ کاربر.
 *
 * از همان منبعِ ابزارِ /tools/ip (ip-api.com) استفاده می‌کند ولی با تایم‌اوتِ
 * کوتاه و کشِ ۲۴ساعته per-IP، تا هرگز ورودِ کاربر را کند نکند. IPهای خصوصی/
 * محلی مکان ندارند و آرایهٔ خالی برمی‌گردانند. شکستِ گذرا کش نمی‌شود تا دفعهٔ
 * بعد دوباره تلاش شود.
 */
class GeoIp
{
    /** @return array{cc:string,country:string,region:string,flag:string}|array{} */
    public function locate(?string $ip): array
    {
        $ip = trim((string) $ip);

        // IP خالی، نامعتبر، یا خصوصی/رزرو → بدونِ مکان (بدونِ تماسِ بیرونی)
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [];
        }

        $key = 'geoip:'.$ip;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $geo = $this->fetch($ip);
        if ($geo !== []) {
            Cache::put($key, $geo, now()->addHours(24));
        }

        return $geo;
    }

    /** @return array{cc:string,country:string,region:string,flag:string}|array{} */
    private function fetch(string $ip): array
    {
        $url = 'http://ip-api.com/json/'.urlencode($ip).'?fields=status,countryCode,country,regionName';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw === false) {
            return [];
        }
        $d = json_decode($raw, true);
        if (! is_array($d) || ($d['status'] ?? '') !== 'success') {
            return [];
        }

        $cc = strtoupper((string) ($d['countryCode'] ?? ''));

        return [
            'cc'      => $cc,
            'country' => (string) ($d['country'] ?? ''),
            'region'  => (string) ($d['regionName'] ?? ''),
            'flag'    => self::flag($cc),
        ];
    }

    /** پرچمِ اموجی از کدِ دوحرفیِ کشور (ISO-3166 alpha-2) */
    public static function flag(string $cc): string
    {
        $cc = strtoupper($cc);
        if (strlen($cc) !== 2 || ! ctype_alpha($cc)) {
            return '🌐';
        }

        return mb_convert_encoding(
            '&#'.(127397 + ord($cc[0])).';&#'.(127397 + ord($cc[1])).';',
            'UTF-8',
            'HTML-ENTITIES'
        );
    }
}
