<?php

namespace App\Services;

/**
 * ابزارهای شبکه و DNS — کاملاً پورتابل روی هاست اشتراکی.
 *
 * رکوردهای DNS از طریق DNS-over-HTTPS (Google / Cloudflare / Quad9) خوانده می‌شوند
 * تا مستقل از resolver سرور و قابل‌اتکا در همه‌جا باشند. SSL با OpenSSL،
 * اسکن پورت و پینگ با سوکت TCP انجام می‌شود (بدون نیاز به exec یا raw socket).
 */
class NetworkTools
{
    /** resolverهای DoH با endpoint JSON */
    private const RESOLVERS = [
        'google'     => ['label' => 'Google', 'loc' => 'US', 'url' => 'https://dns.google/resolve'],
        'cloudflare' => ['label' => 'Cloudflare', 'loc' => 'US', 'url' => 'https://cloudflare-dns.com/dns-query'],
        'quad9'      => ['label' => 'Quad9', 'loc' => 'CH', 'url' => 'https://dns.quad9.net:5053/dns-query'],
    ];

    /** شماره‌ی نوع رکورد DNS → نام */
    private const RR = [
        1 => 'A', 2 => 'NS', 5 => 'CNAME', 6 => 'SOA', 12 => 'PTR',
        15 => 'MX', 16 => 'TXT', 28 => 'AAAA', 33 => 'SRV', 43 => 'DS',
        46 => 'RRSIG', 48 => 'DNSKEY', 257 => 'CAA',
    ];

    /** پورت‌های پرکاربرد برای اسکن */
    private const PORTS = [
        21 => 'FTP', 22 => 'SSH', 25 => 'SMTP', 53 => 'DNS', 80 => 'HTTP',
        110 => 'POP3', 143 => 'IMAP', 443 => 'HTTPS', 465 => 'SMTPS',
        587 => 'Submission', 993 => 'IMAPS', 995 => 'POP3S',
        3306 => 'MySQL', 3389 => 'RDP', 8080 => 'HTTP-alt', 8443 => 'HTTPS-alt',
    ];

    /* ============================================================= DNS records */

    /** یک نوع رکورد DNS را از Google DoH می‌خواند */
    public function dns(string $domain, string $type): array
    {
        $host = $this->host($domain);
        if ($host === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $type = strtoupper($type);
        $ans = $this->doh(self::RESOLVERS['google']['url'], $host, $type);
        if ($ans === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }

        $records = $this->extract($ans, $type);

        return [
            'ok'      => true,
            'domain'  => $host,
            'type'    => $type,
            'records' => $records,
            'count'   => count($records),
        ];
    }

    /** بررسی DNSSEC — flag امنیتی AD و وجود رکوردهای DS/DNSKEY/RRSIG */
    public function dnssec(string $domain): array
    {
        $host = $this->host($domain);
        if ($host === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $ad = $this->doh(self::RESOLVERS['google']['url'], $host, 'A', true);
        $ds = $this->doh(self::RESOLVERS['google']['url'], $host, 'DS');
        $dnskey = $this->doh(self::RESOLVERS['google']['url'], $host, 'DNSKEY');

        if ($ad === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }

        $hasDs = ! empty($this->extract($ds ?? [], 'DS'));
        $hasKey = ! empty($this->extract($dnskey ?? [], 'DNSKEY'));
        $authenticated = (bool) ($ad['AD'] ?? false);
        $enabled = $authenticated || $hasDs || $hasKey;

        return [
            'ok'            => true,
            'domain'        => $host,
            'enabled'       => $enabled,
            'authenticated' => $authenticated,
            'has_ds'        => $hasDs,
            'has_dnskey'    => $hasKey,
            'ds'            => array_map(fn ($r) => $r['data'], $this->extract($ds ?? [], 'DS')),
            'dnskey'        => array_map(fn ($r) => $r['data'], $this->extract($dnskey ?? [], 'DNSKEY')),
        ];
    }

    /** انتشار DNS — مقایسه‌ی پاسخ چند resolver عمومی جهانی */
    public function propagation(string $domain, string $type = 'A'): array
    {
        $host = $this->host($domain);
        if ($host === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }
        $type = strtoupper($type);

        $nodes = [];
        $allValues = [];
        foreach (self::RESOLVERS as $key => $r) {
            $ans = $this->doh($r['url'], $host, $type);
            $records = $ans !== null ? $this->extract($ans, $type) : [];
            $values = array_map(fn ($x) => $x['data'], $records);
            sort($values);
            $nodes[] = [
                'resolver' => $r['label'],
                'loc'      => $r['loc'],
                'ok'       => $ans !== null,
                'values'   => $values,
            ];
            if ($values) {
                $allValues[implode(',', $values)] = true;
            }
        }

        return [
            'ok'         => true,
            'domain'     => $host,
            'type'       => $type,
            'nodes'      => $nodes,
            'consistent' => count($allValues) <= 1,
        ];
    }

    /** Reverse DNS / PTR — از IP به نام دامنه */
    public function reverse(string $ip): array
    {
        $ip = trim($ip);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['ok' => false, 'error' => 'invalid_ip'];
        }

        $ptrName = $this->ptrName($ip);
        $ans = $this->doh(self::RESOLVERS['google']['url'], $ptrName, 'PTR');
        $records = $ans !== null ? $this->extract($ans, 'PTR') : [];
        $names = array_map(fn ($r) => rtrim($r['data'], '.'), $records);

        // fallback بومی سرور
        if (! $names) {
            $h = @gethostbyaddr($ip);
            if ($h && $h !== $ip) {
                $names = [$h];
            }
        }

        return [
            'ok'    => true,
            'ip'    => $ip,
            'ptr'   => $ptrName,
            'names' => array_values(array_unique($names)),
        ];
    }

    /* ============================================================= SSL / TLS */

    /** بررسی گواهی SSL/TLS دامنه */
    public function ssl(string $domain, int $port = 443): array
    {
        $host = $this->host($domain);
        if ($host === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert'       => true,
            'capture_peer_cert_chain' => true,
            'verify_peer'             => false,
            'verify_peer_name'        => false,
            'SNI_enabled'             => true,
            'peer_name'               => $host,
        ]]);

        $client = @stream_socket_client(
            'ssl://'.$host.':'.$port,
            $errno, $errstr, 10,
            STREAM_CLIENT_CONNECT, $ctx
        );
        if (! $client) {
            return ['ok' => false, 'error' => 'no_ssl', 'message' => $errstr ?: null];
        }

        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];
        if (! $cert) {
            return ['ok' => false, 'error' => 'no_cert'];
        }

        $info = openssl_x509_parse($cert);
        $now = time();
        $from = $info['validFrom_time_t'] ?? 0;
        $to = $info['validTo_time_t'] ?? 0;
        $daysLeft = $to ? (int) floor(($to - $now) / 86400) : null;

        $san = [];
        if (! empty($info['extensions']['subjectAltName'])) {
            foreach (explode(',', $info['extensions']['subjectAltName']) as $s) {
                $s = trim(str_replace('DNS:', '', $s));
                if ($s !== '') {
                    $san[] = $s;
                }
            }
        }

        return [
            'ok'         => true,
            'domain'     => $host,
            'valid'      => $now >= $from && $now <= $to,
            'expired'    => $to && $now > $to,
            'days_left'  => $daysLeft,
            'subject'    => $info['subject']['CN'] ?? ($san[0] ?? $host),
            'issuer'     => $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? '—'),
            'issuer_cn'  => $info['issuer']['CN'] ?? '—',
            'valid_from' => $from ? date('Y-m-d', $from) : null,
            'valid_to'   => $to ? date('Y-m-d', $to) : null,
            'sig_alg'    => $info['signatureTypeSN'] ?? null,
            'san'        => array_slice(array_values(array_unique($san)), 0, 30),
            'chain_len'  => count($chain),
        ];
    }

    /* ============================================================= Ports / Ping */

    /**
     * اسکن پورت‌های پرکاربرد.
     *
     * از اتصال سینک با مهلت کوتاه استفاده می‌کنیم: پورت باز فوری وصل می‌شود،
     * پورت بسته فوری refused می‌شود؛ فقط پورت فایروال‌شده تا سقف مهلت صبر می‌کند.
     * این روش برخلاف async-connect نتیجه‌ی مثبت کاذب نمی‌دهد.
     */
    public function ports(string $domain): array
    {
        $ip = $this->resolveIp($domain);
        if ($ip === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $result = [];
        foreach (self::PORTS as $port => $name) {
            $t0 = microtime(true);
            $sock = @stream_socket_client('tcp://'.$ip.':'.$port, $errno, $errstr, 1.4);
            $open = false;
            $state = 'closed';
            if ($sock) {
                $open = true;
                $state = 'open';
                fclose($sock);
            } elseif ((microtime(true) - $t0) >= 1.3) {
                $state = 'filtered'; // مهلت تمام شد → احتمالاً فایروال
            }
            $result[] = ['port' => $port, 'name' => $name, 'open' => $open, 'state' => $state];
        }
        $open = array_filter($result, fn ($r) => $r['open']);

        return [
            'ok'         => true,
            'domain'     => $this->host($domain) ?? $domain,
            'ip'         => $ip,
            'ports'      => $result,
            'open_count' => count($open),
        ];
    }

    /** پینگ TCP — تأخیر اتصال به پورت 443 (fallback 80) در ۴ تلاش */
    public function ping(string $domain): array
    {
        $ip = $this->resolveIp($domain);
        if ($ip === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $port = $this->firstOpen($ip, [443, 80, 22, 21]) ?? 443;
        $times = [];
        $loss = 0;
        for ($i = 0; $i < 4; $i++) {
            $t0 = microtime(true);
            $sock = @stream_socket_client('tcp://'.$ip.':'.$port, $errno, $errstr, 3);
            if ($sock) {
                $times[] = round((microtime(true) - $t0) * 1000, 1);
                fclose($sock);
            } else {
                $loss++;
            }
        }

        return [
            'ok'     => true,
            'domain' => $this->host($domain) ?? $domain,
            'ip'     => $ip,
            'port'   => $port,
            'sent'   => 4,
            'recv'   => count($times),
            'loss'   => (int) round($loss / 4 * 100),
            'min'    => $times ? min($times) : null,
            'avg'    => $times ? round(array_sum($times) / count($times), 1) : null,
            'max'    => $times ? max($times) : null,
            'times'  => $times,
        ];
    }

    /* ============================================================= helpers */

    /** پرس‌وجوی DoH و بازگرداندن پاسخ JSON دیکود‌شده */
    private function doh(string $endpoint, string $name, string $type, bool $dnssec = false): ?array
    {
        $url = $endpoint.'?name='.urlencode($name).'&type='.urlencode($type).($dnssec ? '&do=1' : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
            CURLOPT_USERAGENT      => 'ServerNet-Lookup/1.0',
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code !== 200) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** استخراج رکوردهای یک نوع خاص از پاسخ DoH */
    private function extract(array $ans, string $type): array
    {
        $wanted = array_search($type, self::RR, true);
        $out = [];
        foreach ($ans['Answer'] ?? [] as $row) {
            if ($wanted !== false && (int) $row['type'] !== $wanted) {
                continue;
            }
            $out[] = [
                'name' => rtrim($row['name'] ?? '', '.'),
                'ttl'  => $row['TTL'] ?? null,
                'type' => self::RR[$row['type']] ?? $row['type'],
                'data' => trim($row['data'] ?? '', '"'),
            ];
        }

        return $out;
    }

    /** نرمال‌سازی و اعتبارسنجی نام دامنه/میزبان */
    private function host(string $input): ?string
    {
        $h = strtolower(trim($input));
        $h = preg_replace('~^https?://~', '', $h);
        $h = preg_replace('~^www\.~', '', $h);
        $h = trim(explode('/', $h)[0]);
        $h = explode(':', $h)[0];

        if ($h === '' || ! preg_match('~^([a-z0-9_-]+\.)+[a-z]{2,}$~i', $h)) {
            return null;
        }

        return $h;
    }

    /** دامنه یا IP → IP */
    private function resolveIp(string $input): ?string
    {
        $s = trim($input);
        $s = preg_replace('~^https?://~', '', $s);
        $s = trim(explode('/', $s)[0]);
        $s = explode(':', $s)[0];

        if (filter_var($s, FILTER_VALIDATE_IP)) {
            return $s;
        }
        $host = $this->host($s);
        if ($host === null) {
            return null;
        }
        // ابتدا از DoH (پورتابل)، سپس fallback بومی
        $ans = $this->doh(self::RESOLVERS['google']['url'], $host, 'A');
        $a = $ans !== null ? $this->extract($ans, 'A') : [];
        if ($a) {
            return $a[0]['data'];
        }
        $ip = @gethostbyname($host);

        return ($ip && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null;
    }

    /** ساخت نام PTR از IPv4/IPv6 */
    private function ptrName(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        }
        $unpacked = unpack('H*hex', inet_pton($ip))['hex'];

        return implode('.', array_reverse(str_split($unpacked))).'.ip6.arpa';
    }

    /** اولین پورت باز از یک لیست */
    private function firstOpen(string $ip, array $ports): ?int
    {
        foreach ($ports as $p) {
            $sock = @stream_socket_client('tcp://'.$ip.':'.$p, $e, $s, 2);
            if ($sock) {
                fclose($sock);

                return $p;
            }
        }

        return null;
    }
}
