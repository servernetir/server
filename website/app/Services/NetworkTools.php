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

    /**
     * نام سرویس برای پورت‌های دلخواهی که در فهرست پیش‌فرض نیستند.
     * فقط برای برچسب‌گذاری نتیجه است — روی خود اسکن اثری ندارد.
     */
    private const KNOWN_PORTS = [
        20 => 'FTP-data', 23 => 'Telnet', 69 => 'TFTP', 111 => 'RPC', 123 => 'NTP',
        135 => 'MS-RPC', 137 => 'NetBIOS', 139 => 'NetBIOS-SSN', 161 => 'SNMP',
        389 => 'LDAP', 445 => 'SMB', 514 => 'Syslog', 636 => 'LDAPS',
        873 => 'rsync', 990 => 'FTPS', 1080 => 'SOCKS', 1194 => 'OpenVPN',
        1433 => 'MSSQL', 1521 => 'Oracle', 1723 => 'PPTP', 2049 => 'NFS',
        2082 => 'cPanel', 2083 => 'cPanel SSL', 2086 => 'WHM', 2087 => 'WHM SSL',
        2095 => 'Webmail', 2096 => 'Webmail SSL', 2181 => 'ZooKeeper',
        2375 => 'Docker', 2376 => 'Docker TLS', 3000 => 'Node/Grafana',
        4444 => 'Metasploit', 5000 => 'UPnP/Flask', 5060 => 'SIP', 5432 => 'PostgreSQL',
        5672 => 'AMQP', 5900 => 'VNC', 5985 => 'WinRM', 6379 => 'Redis',
        6660 => 'IRC', 7000 => 'Cassandra', 8000 => 'HTTP-alt', 8006 => 'Proxmox',
        8081 => 'HTTP-alt', 8086 => 'InfluxDB', 8888 => 'HTTP-alt',
        9000 => 'PHP-FPM/SonarQube', 9090 => 'Prometheus', 9200 => 'Elasticsearch',
        9300 => 'Elasticsearch', 10000 => 'Webmin', 11211 => 'Memcached',
        15672 => 'RabbitMQ UI', 25565 => 'Minecraft', 27017 => 'MongoDB',
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

    /**
     * گزارش کامل DNS — همه‌ی رکوردهای پرکاربرد یک دامنه.
     * درخواست‌ها موازی (curl_multi) اجرا می‌شوند تا کل زمان ≈ کندترین درخواست
     * باشد نه مجموع ۸ درخواست.
     */
    public function allDns(string $domain): array
    {
        $host = $this->host($domain);
        if ($host === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $types = ['A', 'AAAA', 'MX', 'NS', 'TXT', 'CNAME', 'SOA', 'CAA'];
        $endpoint = self::RESOLVERS['google']['url'];
        $mh = curl_multi_init();
        $handles = [];
        foreach ($types as $type) {
            $ch = curl_init($endpoint.'?name='.urlencode($host).'&type='.urlencode($type));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
                CURLOPT_USERAGENT      => 'ServerNet-Lookup/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$type] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $groups = [];
        $total = 0;
        foreach ($handles as $type => $ch) {
            $raw = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $recs = [];
            if ($raw !== null && $code === 200) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $recs = $this->extract($data, $type);
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $groups[] = ['type' => $type, 'records' => $recs, 'count' => count($recs)];
            $total += count($recs);
        }
        curl_multi_close($mh);

        return ['ok' => true, 'domain' => $host, 'groups' => $groups, 'total' => $total];
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

    /** حداکثر پورت در یک درخواست دلخواه — بالاتر از این، پاسخ به مهلت وب‌سرور می‌خورد */
    private const MAX_PORTS = 32;

    /** سقف زمان کل اسکن (ثانیه). میزبان فایروال‌دار هر پورت را تا آخر مهلت نگه می‌دارد. */
    private const SCAN_BUDGET = 24.0;

    /**
     * اسکن پورت.
     *
     * بدون ورودی دلخواه، فهرست پورت‌های پرکاربرد را می‌زند؛ با ورودی، دقیقاً
     * همان پورت‌هایی که کاربر خواسته: «80»، «80,443,3306» یا بازه‌ی «8000-8010».
     *
     * از اتصال سینک با مهلت کوتاه استفاده می‌کنیم: پورت باز فوری وصل می‌شود،
     * پورت بسته فوری refused می‌شود؛ فقط پورت فایروال‌شده تا سقف مهلت صبر می‌کند.
     * این روش برخلاف async-connect نتیجه‌ی مثبت کاذب نمی‌دهد.
     */
    public function ports(string $domain, ?string $spec = null): array
    {
        $ip = $this->resolveIp($domain);
        if ($ip === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $custom = $spec !== null && trim($spec) !== '';
        $list = $custom ? $this->parsePorts($spec) : self::PORTS;

        if ($custom && $list === []) {
            return ['ok' => false, 'error' => 'bad_ports'];
        }

        // با فهرست دلخواه مهلت کوتاه‌تر می‌گیریم تا پورت‌های بیشتری در بودجه جا شود
        $timeout = $custom ? 1.0 : 1.4;
        $started = microtime(true);

        $result = [];
        $skipped = 0;
        foreach ($list as $port => $name) {
            // بودجه تمام شد: بقیه را «اسکن‌نشده» علامت می‌زنیم، نه «بسته».
            // گزارش بسته بودنِ پورتی که اصلاً امتحان نشده، بدتر از نگفتن است.
            if ((microtime(true) - $started) >= self::SCAN_BUDGET) {
                $result[] = ['port' => $port, 'name' => $name, 'open' => false, 'state' => 'skipped'];
                $skipped++;
                continue;
            }

            $t0 = microtime(true);
            $sock = @stream_socket_client('tcp://'.$ip.':'.$port, $errno, $errstr, $timeout);
            $open = false;
            $state = 'closed';
            if ($sock) {
                $open = true;
                $state = 'open';
                fclose($sock);
            } elseif ((microtime(true) - $t0) >= $timeout - 0.1) {
                $state = 'filtered'; // مهلت تمام شد → احتمالاً فایروال
            }
            $result[] = ['port' => $port, 'name' => $name, 'open' => $open, 'state' => $state];
        }

        return [
            'ok'         => true,
            'domain'     => $this->host($domain) ?? $domain,
            'ip'         => $ip,
            'ports'      => $result,
            'open_count' => count(array_filter($result, fn ($r) => $r['open'])),
            'custom'     => $custom,
            'skipped'    => $skipped,
            'max_ports'  => self::MAX_PORTS,
        ];
    }

    /**
     * تبدیل ورودی کاربر به فهرست [پورت => نام سرویس].
     *
     * می‌پذیرد: «443» ، «80,443,3306» ، «8000-8010» و ترکیبشان.
     * جداکننده می‌تواند ویرگول انگلیسی یا فارسی، فاصله یا خط جدید باشد.
     * ارقام فارسی و عربی هم به لاتین برگردانده می‌شوند.
     */
    private function parsePorts(string $spec): array
    {
        $spec = strtr($spec, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '،' => ',', '–' => '-', '—' => '-',
        ]);

        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($spec), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (count($out) >= self::MAX_PORTS) {
                break;
            }

            if (preg_match('/^(\d{1,5})\s*-\s*(\d{1,5})$/', $token, $m)) {
                $from = (int) $m[1];
                $to = (int) $m[2];
                if ($from < 1 || $to > 65535 || $from > $to) {
                    continue;
                }
                for ($p = $from; $p <= $to && count($out) < self::MAX_PORTS; $p++) {
                    $out[$p] = self::PORTS[$p] ?? self::KNOWN_PORTS[$p] ?? '';
                }
                continue;
            }

            if (preg_match('/^\d{1,5}$/', $token)) {
                $p = (int) $token;
                if ($p >= 1 && $p <= 65535) {
                    $out[$p] = self::PORTS[$p] ?? self::KNOWN_PORTS[$p] ?? '';
                }
            }
        }

        ksort($out);

        return $out;
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

    /* ============================================================= سلامت ایمیل */

    /**
     * سلکتورهای رایج DKIM — نمی‌شود همه را دانست (سلکتور دست فرستنده است)،
     * پس فقط رایج‌ترین‌ها بررسی و «یافت‌نشدن» هرگز خطا اعلام نمی‌شود.
     */
    private const DKIM_SELECTORS = [
        'default', 'google', 'selector1', 'selector2', 'k1', 's1', 's2',
        'mail', 'dkim', 'zoho', 'mx',
    ];

    /**
     * بررسی سلامت ایمیل دامنه: MX، SPF، DMARC و DKIM.
     * همه از DoH خوانده می‌شوند؛ ارزیابی‌ها توابع خالص جدا هستند تا با
     * رکوردهای مرجع تست شوند.
     */
    public function emailHealth(string $domain): array
    {
        $host = $this->host($domain);
        if ($host === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $endpoint = self::RESOLVERS['google']['url'];

        $mxAns = $this->doh($endpoint, $host, 'MX');
        if ($mxAns === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }
        $mx = array_map(
            fn ($r) => $r['data'],
            $this->extract($mxAns, 'MX')
        );

        $txts = array_map(
            fn ($r) => $r['data'],
            $this->extract($this->doh($endpoint, $host, 'TXT') ?? [], 'TXT')
        );
        $dmarcTxts = array_map(
            fn ($r) => $r['data'],
            $this->extract($this->doh($endpoint, '_dmarc.'.$host, 'TXT') ?? [], 'TXT')
        );

        $spf = self::spfEvaluate($txts);
        $dmarc = self::dmarcEvaluate($dmarcTxts);
        $dkim = $this->dkimSelectors($host);

        // جمع‌بندی: MX پایه است؛ SPF و DMARC سالم یعنی «خوب»، ناقص یعنی «هشدار»
        $verdict = 'bad';
        if ($mx !== []) {
            $verdict = ($spf['ok'] && $dmarc['found']) ? 'good' : 'warn';
        }

        return [
            'ok'      => true,
            'domain'  => $host,
            'mx'      => $mx,
            'spf'     => $spf,
            'dmarc'   => $dmarc,
            'dkim'    => $dkim,
            'verdict' => $verdict,
        ];
    }

    /**
     * ارزیابی SPF از روی رکوردهای TXT دامنه — تابع خالص.
     *
     * «چند رکورد SPF» طبق RFC 7208 خطای قطعی است (permerror) و شایع‌ترین
     * خرابکاری واقعی؛ برای همین جدا پرچم می‌خورد.
     */
    public static function spfEvaluate(array $txts): array
    {
        $spf = array_values(array_filter(
            array_map('trim', $txts),
            fn ($t) => preg_match('~^v=spf1(\s|$)~i', $t) === 1
        ));

        $record = $spf[0] ?? null;
        $policy = 'none';
        // دلیمیتر / است نه ~ — خود ~ (softfail) داخل character class است
        if ($record !== null && preg_match('/([-~?+])all\b/i', $record, $m)) {
            $policy = ['-' => 'hard', '~' => 'soft', '?' => 'neutral', '+' => 'pass_all'][$m[1]] ?? 'none';
        }

        return [
            'found'    => $record !== null,
            'multiple' => count($spf) > 1,
            'record'   => $record,
            'policy'   => $policy,
            // سالم = دقیقاً یک رکورد که با مکانیزم all بسته شده و +all نیست
            'ok'       => $record !== null && count($spf) === 1
                && in_array($policy, ['hard', 'soft', 'neutral'], true),
        ];
    }

    /** ارزیابی DMARC از روی TXTهای ‎_dmarc — تابع خالص */
    public static function dmarcEvaluate(array $txts): array
    {
        $rows = array_values(array_filter(
            array_map('trim', $txts),
            fn ($t) => preg_match('~^v=DMARC1\b~i', $t) === 1
        ));

        $record = $rows[0] ?? null;
        $policy = null;
        if ($record !== null && preg_match('~\bp\s*=\s*(none|quarantine|reject)~i', $record, $m)) {
            $policy = strtolower($m[1]);
        }

        return [
            'found'  => $record !== null,
            'record' => $record,
            'policy' => $policy,
            // p=none یعنی فقط گزارش — از هیچ بهتر است ولی «اجرا» نیست
            'ok'     => in_array($policy, ['quarantine', 'reject'], true),
        ];
    }

    /** بررسی موازی سلکتورهای رایج DKIM (curl_multi مثل allDns) */
    protected function dkimSelectors(string $host): array
    {
        $endpoint = self::RESOLVERS['google']['url'];
        $mh = curl_multi_init();
        $handles = [];
        foreach (self::DKIM_SELECTORS as $sel) {
            $name = $sel.'._domainkey.'.$host;
            $ch = curl_init($endpoint.'?name='.urlencode($name).'&type=TXT');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
                CURLOPT_USERAGENT      => 'ServerNet-Lookup/1.0',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$sel] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $found = [];
        foreach ($handles as $sel => $ch) {
            $raw = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($raw === null || $code !== 200) {
                continue;
            }
            $data = json_decode($raw, true);
            if (! is_array($data)) {
                continue;
            }
            foreach ($this->extract($data, 'TXT') as $r) {
                if (stripos($r['data'], 'v=DKIM1') !== false || preg_match('~\bp=[A-Za-z0-9+/]~', $r['data'])) {
                    $found[] = $sel;
                    break;
                }
            }
        }
        curl_multi_close($mh);

        return ['found' => $found, 'checked' => count(self::DKIM_SELECTORS)];
    }

    /* ============================================================= بلک‌لیست (DNSBL) */

    /**
     * زون‌های DNSBL معتبر و پرمصرف. لیست کوتاه است تا کل بررسی زیر بودجه‌ی
     * زمانی صفحه بماند.
     */
    public const RBL_ZONES = [
        'zen.spamhaus.org'      => 'Spamhaus ZEN',
        'bl.spamcop.net'        => 'SpamCop',
        'dnsbl.sorbs.net'       => 'SORBS',
        'psbl.surriel.com'      => 'PSBL',
        'ix.dnsbl.manitu.net'   => 'Manitu',
        'db.wpbl.info'          => 'WPBL',
        'dnsbl-1.uceprotect.net' => 'UCEPROTECT L1',
        'spam.spamrats.com'     => 'SpamRats',
    ];

    /**
     * بررسی حضور IP در بلک‌لیست‌های ایمیل.
     *
     * ⚠️ عمداً از resolver خود سرور (dns_get_record) استفاده می‌شود نه DoH:
     * Spamhaus به resolverهای عمومی (Google/Cloudflare) پاسخ نمی‌دهد و
     * به‌جایش کد خطای 127.255.255.x می‌فرستد — که اگر «لیست‌شده» خوانده شود،
     * به هر IP سالمی برچسب اسپم زده‌ایم. آن کدها این‌جا «نامشخص» تفسیر می‌شوند.
     */
    public function blacklist(string $input): array
    {
        // IPv6 را پیش از resolveIp بگیر: آن متد پورت را با ':' جدا می‌کند و
        // ورودی IPv6 را له می‌کند — پس این‌جا آخرین جایی است که سالم دیده می‌شود.
        if (filter_var(trim($input), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ['ok' => false, 'error' => 'ipv6_unsupported'];
        }

        $ip = $this->resolveIp($input);
        if ($ip === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ok' => false, 'error' => 'ipv6_unsupported'];
        }

        $reversed = implode('.', array_reverse(explode('.', $ip)));

        /*
        | بودجه‌ی زمانی کل — همان الگوی اسکن پورت. dns_get_record مهلت‌پذیر
        | نیست و یک زون بی‌پاسخ می‌تواند چند ثانیه معطل کند؛ روی سرور زنده
        | مجموع ۸ زون از مهلت وب‌سرور رد می‌شد. زونِ نرسیده «نامشخص» علامت
        | می‌خورد — «نپرسیدیم» با «پاک است» یکی نیست.
        */
        $started = microtime(true);

        $zones = [];
        $listed = 0;
        $unchecked = 0;
        foreach (self::RBL_ZONES as $zone => $label) {
            if ((microtime(true) - $started) >= self::RBL_BUDGET) {
                $zones[] = ['zone' => $zone, 'label' => $label, 'state' => 'unknown', 'reason' => null];
                $unchecked++;
                continue;
            }

            $answer = $this->rblQuery($reversed.'.'.$zone);
            $state = self::rblInterpret($zone, $answer['ips']);
            if ($state === 'listed') {
                $listed++;
            }
            $zones[] = [
                'zone'   => $zone,
                'label'  => $label,
                'state'  => $state,
                'reason' => $state === 'listed' ? ($answer['txt'] ?? null) : null,
            ];
        }

        return [
            'ok'        => true,
            'domain'    => $this->host($input) ?? $ip,
            'ip'        => $ip,
            'zones'     => $zones,
            'listed'    => $listed,
            'clean'     => $listed === 0,
            'unchecked' => $unchecked,
        ];
    }

    /** سقف زمان کل بررسی بلک‌لیست (ثانیه) */
    private const RBL_BUDGET = 12.0;

    /**
     * تفسیر پاسخ DNSBL — تابع خالص.
     *
     * پاسخ در 127.0.0.0/8 یعنی «لیست‌شده»، جز کدهای خطای Spamhaus
     * (127.255.255.x = پرس‌وجو از resolver عمومی/بی‌اعتبار) که «نامشخص»‌اند.
     * هر پاسخ خارج از 127.x هم اعتبار ندارد (بعضی ISPها NXDOMAIN را hijack می‌کنند).
     */
    public static function rblInterpret(string $zone, array $ips): string
    {
        if ($ips === []) {
            return 'clean';
        }
        foreach ($ips as $ip) {
            if (str_starts_with($ip, '127.255.255.')) {
                return 'unknown';
            }
            if (str_starts_with($ip, '127.')) {
                return 'listed';
            }
        }

        return 'unknown';
    }

    /** پرس‌وجوی یک نام DNSBL از resolver سیستم (در تست جایگزین می‌شود) */
    protected function rblQuery(string $name): array
    {
        $ips = [];
        foreach (@dns_get_record($name, DNS_A) ?: [] as $r) {
            if (! empty($r['ip'])) {
                $ips[] = $r['ip'];
            }
        }

        $txt = null;
        if ($ips !== []) {
            foreach (@dns_get_record($name, DNS_TXT) ?: [] as $r) {
                if (! empty($r['txt'])) {
                    $txt = $r['txt'];
                    break;
                }
            }
        }

        return ['ips' => $ips, 'txt' => $txt];
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

    /** نرمال‌سازی و اعتبارسنجی نام دامنه/میزبان (public: WebProbe هم استفاده می‌کند) */
    public function host(string $input): ?string
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
