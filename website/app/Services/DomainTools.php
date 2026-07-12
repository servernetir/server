<?php

namespace App\Services;

/**
 * ابزارهای دامنه و IP — Whois خام (port 43) و ژئولوکیشن IP.
 * بدون وابستگی به سرویس خارجی برای whois؛ IP از ip-api.com (رایگان).
 */
class DomainTools
{
    /** سرورهای whois بر اساس پسوند (پرکاربردها + fallback IANA) */
    private const WHOIS = [
        'com' => 'whois.verisign-grs.com', 'net' => 'whois.verisign-grs.com',
        'org' => 'whois.pir.org', 'info' => 'whois.afilias.net',
        'io' => 'whois.nic.io', 'co' => 'whois.nic.co', 'ai' => 'whois.nic.ai',
        'dev' => 'whois.nic.google', 'app' => 'whois.nic.google', 'cloud' => 'whois.nic.cloud',
        'ir' => 'whois.nic.ir', 'shop' => 'whois.nic.shop', 'xyz' => 'whois.nic.xyz',
        'me' => 'whois.nic.me', 'tv' => 'whois.nic.tv', 'biz' => 'whois.nic.biz',
        'de' => 'whois.denic.de', 'uk' => 'whois.nic.uk', 'fr' => 'whois.nic.fr',
        'nl' => 'whois.domain-registry.nl', 'eu' => 'whois.eu', 'ru' => 'whois.tcinet.ru',
    ];

    public function whois(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('~^https?://~', '', $domain);
        $domain = preg_replace('~^www\.~', '', $domain);
        $domain = trim(explode('/', $domain)[0]);

        if (! preg_match('~^([a-z0-9-]+\.)+[a-z]{2,}$~', $domain)) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $parts = explode('.', $domain);
        $tld = end($parts);
        $server = self::WHOIS[$tld] ?? $this->ianaServer($tld);
        if (! $server) {
            return ['ok' => false, 'error' => 'unsupported_tld', 'tld' => $tld];
        }

        $raw = $this->query($server, $domain);
        // ثبت‌کننده thin (com/net) → پرس‌وجوی دوم از whois رجیسترار
        if ($raw && preg_match('~Registrar WHOIS Server:\s*([^\s]+)~i', $raw, $m)) {
            $deep = $this->query(trim($m[1]), $domain);
            if ($deep && strlen($deep) > strlen($raw) / 2) {
                $raw = $deep;
            }
        }
        if (! $raw) {
            return ['ok' => false, 'error' => 'no_data', 'domain' => $domain];
        }

        return [
            'ok'       => true,
            'domain'   => $domain,
            'tld'      => $tld,
            'server'   => $server,
            'parsed'   => $this->parseWhois($raw),
            'raw'      => mb_substr($raw, 0, 6000),
        ];
    }

    private function ianaServer(string $tld): ?string
    {
        $raw = $this->query('whois.iana.org', $tld);
        if ($raw && preg_match('~whois:\s*([^\s]+)~i', $raw, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function query(string $server, string $q): ?string
    {
        $fp = @fsockopen($server, 43, $errno, $errstr, 8);
        if (! $fp) {
            return null;
        }
        stream_set_timeout($fp, 8);
        // برخی سرورها (مثل verisign) پرچم لازم دارند
        $suffix = str_contains($server, 'verisign') ? '' : '';
        fwrite($fp, $q.$suffix."\r\n");
        $out = '';
        while (! feof($fp)) {
            $chunk = fread($fp, 4096);
            if ($chunk === false) {
                break;
            }
            $out .= $chunk;
            if (strlen($out) > 200000) {
                break;
            }
        }
        fclose($fp);

        return $out !== '' ? $out : null;
    }

    private function parseWhois(string $raw): array
    {
        $grab = function (array $keys) use ($raw): ?string {
            foreach ($keys as $k) {
                if (preg_match('~^\s*'.preg_quote($k, '~').'\s*:\s*(.+)$~im', $raw, $m)) {
                    $v = trim($m[1]);
                    if ($v !== '' && ! preg_match('~redacted|privacy|data protected~i', $v)) {
                        return $v;
                    }
                }
            }

            return null;
        };
        $grabAll = function (string $k) use ($raw): array {
            preg_match_all('~^\s*'.preg_quote($k, '~').'\s*:\s*(.+)$~im', $raw, $m);

            return array_values(array_unique(array_map('trim', $m[1] ?? [])));
        };

        $registered = ! preg_match('~^(No match|NOT FOUND|Domain not found|No Data Found|available)~im', $raw);

        return [
            'registered'  => $registered,
            'registrar'   => $grab(['Registrar', 'Sponsoring Registrar', 'registrar']),
            'created'     => $grab(['Creation Date', 'Created On', 'created', 'Domain Registration Date', 'Registered On']),
            'updated'     => $grab(['Updated Date', 'Last Updated', 'last-update', 'Modified']),
            'expires'     => $grab(['Registry Expiry Date', 'Registrar Registration Expiration Date', 'Expiration Date', 'Expiration Time', 'expire', 'Expiry Date', 'paid-till', 'Domain Expiration Date']),
            'status'      => array_slice($grabAll('Domain Status') ?: $grabAll('status'), 0, 4),
            'nameservers' => array_map('strtolower', array_slice($grabAll('Name Server') ?: $grabAll('nserver'), 0, 6)),
            'dnssec'      => $grab(['DNSSEC']),
            'org'         => $grab(['Registrant Organization', 'org', 'Organization']),
            'country'     => $grab(['Registrant Country', 'country']),
        ];
    }

    /* ============ IP ============ */

    public function ipInfo(string $ip): array
    {
        $ip = trim($ip);
        // نام دامنه → IP
        if ($ip !== '' && ! filter_var($ip, FILTER_VALIDATE_IP)) {
            $host = preg_replace('~^https?://|/.*$~', '', $ip);
            $resolved = gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $ip = $resolved;
            }
        }
        if ($ip !== '' && ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'invalid_ip'];
        }

        $fields = 'status,message,continent,country,countryCode,region,regionName,city,zip,lat,lon,timezone,offset,isp,org,as,asname,reverse,mobile,proxy,hosting,query';
        $url = 'http://ip-api.com/json/'.urlencode($ip).'?fields='.$fields.'&lang='.(app()->getLocale() === 'fa' ? 'en' : app()->getLocale());
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $d = json_decode($raw, true);
        if (($d['status'] ?? '') !== 'success') {
            return ['ok' => false, 'error' => 'lookup_failed', 'message' => $d['message'] ?? null];
        }
        $d['ok'] = true;
        $d['flag'] = $this->flag($d['countryCode'] ?? '');

        return $d;
    }

    private function flag(string $cc): string
    {
        $cc = strtoupper($cc);
        if (strlen($cc) !== 2) {
            return '🌐';
        }

        return mb_convert_encoding('&#'.(127397 + ord($cc[0])).';&#'.(127397 + ord($cc[1])).';', 'UTF-8', 'HTML-ENTITIES');
    }
}
