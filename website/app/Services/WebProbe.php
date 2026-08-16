<?php

namespace App\Services;

/**
 * بررسی‌های HTTPمحورِ ابزارهای lookup — سرعت/TTFB، هدرهای امنیتی،
 * زنجیره‌ی ریدایرکت و دسترسی از داخل ایران.
 *
 * جدا از NetworkTools است چون جنسِ کار فرق دارد: آن‌جا DNS و سوکت، این‌جا
 * درخواستِ کامل HTTP به آدرسی که **کاربر** داده — پس هر fetch این کلاس باید
 * از SafeUrl رد شود، وگرنه ابزار به دروازه‌ی SSRF تبدیل می‌شود.
 *
 * نقطه‌ی دید دوم (ایران) از راه یک probe‌ی پیکربندی‌شده می‌آید
 * (services.iran_probe.url — وب‌هوکی روی سرور ایرانی). اگر تنظیم نشده باشد،
 * ابزار با همان نقطه‌ی دید اروپا کار می‌کند و ردیف ایران صادقانه
 * «پیکربندی‌نشده» می‌ماند — نه خطا، نه حدس.
 *
 * تماس‌های شبکه در متدهای protected جمع شده‌اند تا تست بتواند جایگزینشان کند؛
 * منطقِ نمره‌دهی و پارس، توابع خالصِ public است و با مقدار مرجع سنجیده می‌شود.
 */
class WebProbe
{
    /** حداکثر پرش در ابزار زنجیره‌ی ریدایرکت (بیشتر از SafeUrl::MAX_REDIRECTS، چون خودِ زنجیره موضوعِ ابزار است) */
    public const MAX_HOPS = 8;

    /** resolverهای ایرانی که پاسخ‌شان سیاستِ فیلترینگ را بازتاب می‌دهد */
    public const IRAN_RESOLVERS = [
        '217.218.127.127' => 'TIC 1',
        '217.218.155.155' => 'TIC 2',
    ];

    public function __construct(private NetworkTools $net) {}

    /* ============================================================= سرعت / TTFB */

    /**
     * سنجش سرعت بارگذاری از دو نقطه‌ی دید: سرور ما (اروپا) و probe ایران.
     */
    public function speed(string $target): array
    {
        $url = $this->normalizeUrl($target);
        if ($url === null || ! $this->urlAllowed($url)) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $eu = $this->curlTimings($url);
        if ($eu === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }

        return [
            'ok'     => true,
            'domain' => parse_url($url, PHP_URL_HOST),
            'url'    => $url,
            'eu'     => $eu,
            'iran'   => $this->iranRow($url),
        ];
    }

    /* ============================================================= هدرهای امنیتی */

    public function headers(string $target): array
    {
        $url = $this->normalizeUrl($target);
        if ($url === null || ! $this->urlAllowed($url)) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $res = $this->fetchHeaders($url);
        if ($res === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }

        $grade = self::gradeHeaders($res['headers']);

        return [
            'ok'      => true,
            'domain'  => parse_url($url, PHP_URL_HOST),
            'status'  => $res['status'],
            'grade'   => $grade['grade'],
            'score'   => $grade['score'],
            'checks'  => $grade['checks'],
            // افشای نسخه‌ی نرم‌افزار سرور — هشدار اطلاعاتی است، در نمره نیست
            'server'  => $res['headers']['server'] ?? null,
        ];
    }

    /**
     * نمره‌دهی هدرهای امنیتی — تابع خالص، ورودی: هدرها با کلید حروف‌کوچک.
     *
     * وزن‌ها ثابت‌اند تا نمره بین دو اجرا و در تستِ مرجع تکرارپذیر باشد:
     * HSTS ۲۵ · CSP ۲۵ · ضد-iframe ۱۵ · nosniff ۱۵ · Referrer ۱۰ · Permissions ۱۰.
     */
    public static function gradeHeaders(array $headers): array
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $csp = (string) ($h['content-security-policy'] ?? '');

        $checks = [
            'hsts'        => ['ok' => isset($h['strict-transport-security']), 'weight' => 25],
            'csp'         => ['ok' => $csp !== '', 'weight' => 25],
            // حفاظ کلیک‌جکینگ: یا XFO یا frame-ancestors در CSP
            'frame'       => ['ok' => isset($h['x-frame-options']) || str_contains($csp, 'frame-ancestors'), 'weight' => 15],
            'nosniff'     => ['ok' => stripos((string) ($h['x-content-type-options'] ?? ''), 'nosniff') !== false, 'weight' => 15],
            'referrer'    => ['ok' => isset($h['referrer-policy']), 'weight' => 10],
            'permissions' => ['ok' => isset($h['permissions-policy']), 'weight' => 10],
        ];

        $score = 0;
        foreach ($checks as $c) {
            $score += $c['ok'] ? $c['weight'] : 0;
        }

        $grade = match (true) {
            $score >= 100 => 'A+',
            $score >= 85  => 'A',
            $score >= 70  => 'B',
            $score >= 55  => 'C',
            $score >= 40  => 'D',
            $score >= 20  => 'E',
            default       => 'F',
        };

        return [
            'score'  => $score,
            'grade'  => $grade,
            'checks' => array_map(fn ($c) => $c['ok'], $checks),
        ];
    }

    /* ============================================================= زنجیره‌ی ریدایرکت */

    public function redirects(string $target): array
    {
        $url = $this->normalizeUrl($target);
        if ($url === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $hops = [];
        $seen = [];
        $loop = false;
        $current = $url;

        for ($i = 0; $i <= self::MAX_HOPS; $i++) {
            if (! $this->urlAllowed($current)) {
                // پرشی که به مقصد ناامن/نامعتبر رسید — زنجیره همین‌جا تمام می‌شود
                $hops[] = ['url' => $current, 'status' => null, 'blocked' => true];
                break;
            }
            if (isset($seen[$current])) {
                $loop = true;
                break;
            }
            $seen[$current] = true;

            $res = $this->fetchHop($current);
            if ($res === null) {
                $hops[] = ['url' => $current, 'status' => null];
                break;
            }

            $hops[] = ['url' => $current, 'status' => $res['status']];

            $loc = $res['location'] ?? '';
            if ($loc === '' || $res['status'] < 300 || $res['status'] >= 400) {
                break;
            }
            $current = $this->absolutize($current, $loc);
        }

        $first = parse_url($url);
        $lastHop = end($hops) ?: null;
        $final = $lastHop['url'] ?? $url;

        return [
            'ok'            => true,
            'domain'        => $first['host'] ?? $url,
            'hops'          => $hops,
            'count'         => max(0, count($hops) - 1),
            'loop'          => $loop,
            'final'         => $final,
            'final_status'  => $lastHop['status'] ?? null,
            // آیا زنجیره کاربر HTTP را به HTTPS می‌رساند؟
            'https_upgrade' => ($first['scheme'] ?? '') === 'http'
                && str_starts_with((string) $final, 'https://'),
            'too_many'      => ! $loop && count($hops) > self::MAX_HOPS,
        ];
    }

    /* ============================================================= دسترسی از ایران */

    /**
     * سه لایه‌ی مستقل، هر کدام با پاسخ صادقانه‌ی «نمی‌دانم» وقتی سنجیدنی نیست:
     *   ۱) DNS جهانی (Google DoH)
     *   ۲) DNS از resolverهای ایرانی — پاسخ 10.10.34.x یعنی فیلتر
     *   ۳) HTTP از اروپا، و اگر probe تنظیم باشد، HTTP از ایران
     */
    public function iranAccess(string $target): array
    {
        @set_time_limit(75);

        $host = $this->net->host($target);
        if ($host === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        // ۱) دید جهانی
        $global = $this->net->dns($host, 'A');
        $globalIps = $global['ok'] ? array_column($global['records'], 'data') : [];

        // ۲) دید resolverهای ایرانی (UDP خام — DoH ندارند)
        $iranNodes = [];
        $filtered = false;
        $iranAnswered = false;
        foreach (self::IRAN_RESOLVERS as $server => $label) {
            $ips = $this->iranResolve($server, $host);
            $blocked = $ips !== null && array_filter($ips, [self::class, 'isIranBlockIp']) !== [];
            if ($ips !== null) {
                $iranAnswered = true;
            }
            if ($blocked) {
                $filtered = true;
            }
            $iranNodes[] = [
                'resolver' => $label,
                'ok'       => $ips !== null,
                'ips'      => $ips ?? [],
                'blocked'  => $blocked,
            ];
        }

        // ۳) HTTP
        $world = $this->fetchStatus('https://'.$host.'/');
        $iran = $this->iranRow('https://'.$host.'/');

        $verdict = self::accessVerdict($filtered, $iranAnswered, $world, $iran);

        return [
            'ok'         => true,
            'domain'     => $host,
            'global_ips' => $globalIps,
            'iran_dns'   => $iranNodes,
            'world_http' => $world,
            'iran_http'  => $iran,
            'verdict'    => $verdict,
        ];
    }

    /**
     * جمع‌بندی — تابع خالص تا با مقدار مرجع تست شود.
     *
     * «فیلتر» فقط وقتی اعلام می‌شود که مدرک مستقیم داریم (IP صفحه‌ی فیلترینگ).
     * بقیه‌ی حالت‌ها درجه‌بندی صادقانه‌اند؛ حدس جای داده نمی‌نشیند.
     */
    public static function accessVerdict(bool $filtered, bool $iranAnswered, ?int $world, ?array $iranHttp): string
    {
        if ($filtered) {
            return 'filtered';
        }
        if (($iranHttp['ok'] ?? false) && ($iranHttp['status'] ?? 600) < 400) {
            return 'accessible';           // از داخل ایران واقعاً باز شد
        }
        if (($iranHttp['ok'] ?? false) || ($iranHttp['state'] ?? '') === 'failed') {
            return 'unreachable_iran';     // probe جواب داد ولی سایت از ایران باز نشد
        }
        if ($iranAnswered && $world !== null && $world < 400) {
            return 'likely_ok';            // DNS ایران سالم + از اروپا باز است؛ probe نداریم
        }

        return 'unknown';
    }

    /** IP صفحه‌ی فیلترینگ ایران؟ (10.10.34.0/24) */
    public static function isIranBlockIp(string $ip): bool
    {
        return str_starts_with($ip, '10.10.34.');
    }

    /* ============================================================= DNS-over-UDP خام */

    /**
     * بسته‌ی پرس‌وجوی DNS برای رکورد A — استاندارد RFC1035.
     * تابع خالص است و در تست با بایت‌های مرجع سنجیده می‌شود.
     */
    public static function dnsQueryPacket(string $host, int $id): string
    {
        $header = pack('n6', $id & 0xFFFF, 0x0100, 1, 0, 0, 0);   // RD=1، یک پرسش

        $qname = '';
        foreach (explode('.', rtrim($host, '.')) as $label) {
            $qname .= chr(strlen($label)).$label;
        }
        $qname .= "\0";

        return $header.$qname.pack('n2', 1, 1);                    // QTYPE=A، QCLASS=IN
    }

    /**
     * استخراج IPهای IPv4 از پاسخ DNS. فشرده‌سازی نام (اشاره‌گر 0xC0) را
     * می‌شناسد؛ پاسخ خراب یا خطادار آرایه‌ی خالی می‌دهد، نه exception.
     */
    public static function dnsAnswerIps(string $packet): array
    {
        if (strlen($packet) < 12) {
            return [];
        }
        $h = unpack('nid/nflags/nqd/nan/nns/nar', substr($packet, 0, 12));
        if (($h['flags'] & 0x8000) === 0 || ($h['flags'] & 0x000F) !== 0) {
            return [];                    // پاسخ نیست، یا RCODE خطا
        }

        $pos = 12;
        $len = strlen($packet);

        // عبور از بخش پرسش‌ها
        for ($q = 0; $q < $h['qd']; $q++) {
            while ($pos < $len) {
                $l = ord($packet[$pos]);
                if ($l === 0) {
                    $pos++;
                    break;
                }
                if (($l & 0xC0) === 0xC0) {
                    $pos += 2;
                    break;
                }
                $pos += $l + 1;
            }
            $pos += 4;                    // QTYPE + QCLASS
        }

        $ips = [];
        for ($a = 0; $a < $h['an'] && $pos < $len; $a++) {
            // نام: اشاره‌گر یا زنجیره‌ی برچسب
            while ($pos < $len) {
                $l = ord($packet[$pos]);
                if (($l & 0xC0) === 0xC0) {
                    $pos += 2;
                    break;
                }
                if ($l === 0) {
                    $pos++;
                    break;
                }
                $pos += $l + 1;
            }
            if ($pos + 10 > $len) {
                break;
            }
            $rr = unpack('ntype/nclass/Nttl/nrdlen', substr($packet, $pos, 10));
            $pos += 10;
            if ($pos + $rr['rdlen'] > $len) {
                break;
            }
            if ($rr['type'] === 1 && $rr['rdlen'] === 4) {
                $ips[] = implode('.', array_map('ord', str_split(substr($packet, $pos, 4))));
            }
            $pos += $rr['rdlen'];
        }

        return $ips;
    }

    /* ============================================================= درزهای شبکه (در تست جایگزین می‌شوند) */

    /**
     * نگهبان SSRF — دور SafeUrl::allowed تا تست بتواند بدون DNS واقعی
     * جایگزینش کند؛ رفتار پروداکشن همان SafeUrl است.
     */
    protected function urlAllowed(string $url): bool
    {
        return SafeUrl::allowed($url);
    }

    /** پرس‌وجوی A از یک resolver ایرانی. null یعنی پاسخی نیامد (نه «فیلتر نیست»). */
    protected function iranResolve(string $server, string $host): ?array
    {
        $sock = @stream_socket_client('udp://'.$server.':53', $errno, $errstr, 2);
        if (! $sock) {
            return null;
        }
        stream_set_timeout($sock, 2);
        $id = random_int(0, 0xFFFF);
        @fwrite($sock, self::dnsQueryPacket($host, $id));
        $answer = @fread($sock, 1500);
        fclose($sock);

        if (! is_string($answer) || strlen($answer) < 12) {
            return null;
        }

        return self::dnsAnswerIps($answer);
    }

    /** زمان‌سنجی کامل curl برای ابزار سرعت */
    protected function curlTimings(string $url): ?array
    {
        $body = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, SafeUrl::curlOptions() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'ServerNet-Speed/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            // فقط برای زمان‌سنجی است — بعد از ۲۵۶KB دانلود را قطع می‌کنیم
            CURLOPT_WRITEFUNCTION  => function ($c, string $chunk) use (&$body): int {
                $body += strlen($chunk);

                return $body > 262144 ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $status = (int) ($info['http_code'] ?? 0);
        if ($status === 0) {
            return null;
        }

        $ms = fn (string $key) => isset($info[$key]) ? (int) round($info[$key] * 1000) : null;

        return [
            'status'     => $status,
            'dns_ms'     => $ms('namelookup_time'),
            'connect_ms' => $ms('connect_time'),
            'tls_ms'     => $ms('appconnect_time'),
            'ttfb_ms'    => $ms('starttransfer_time'),
            'total_ms'   => $ms('total_time'),
            'size'       => $body,
        ];
    }

    /** هدرهای پاسخ (کلید حروف‌کوچک). بدنه بعد از اولین بایت‌ها قطع می‌شود. */
    protected function fetchHeaders(string $url): ?array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, SafeUrl::curlOptions() + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'ServerNet-Headers/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => fn () => 0,   // بدنه لازم نیست
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $status > 0 ? ['status' => $status, 'headers' => $headers] : null;
    }

    /** یک پرش زنجیره‌ی ریدایرکت: وضعیت + Location (بدون دنبال‌کردن) */
    protected function fetchHop(string $url): ?array
    {
        $res = $this->fetchHeaders($url);
        if ($res === null) {
            return null;
        }

        return ['status' => $res['status'], 'location' => $res['headers']['location'] ?? ''];
    }

    /** فقط کد وضعیت HTTP از دید سرور ما */
    protected function fetchStatus(string $url): ?int
    {
        if (! $this->urlAllowed($url)) {
            return null;
        }
        $res = $this->fetchHeaders($url);

        return $res['status'] ?? null;
    }

    /** فراخوانی probe ایران؛ null یعنی تنظیم نشده یا در دسترس نیست */
    protected function probeFetch(string $url): ?array
    {
        $probe = (string) config('services.iran_probe.url', '');
        if ($probe === '' || ! str_starts_with($probe, 'https://')) {
            return null;
        }

        $ch = curl_init($probe);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Probe-Token: '.config('services.iran_probe.token', ''),
            ],
            CURLOPT_POSTFIELDS     => json_encode(['target' => $url]),
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (! is_string($raw) || $code !== 200) {
            return null;
        }
        $d = json_decode($raw, true);

        return is_array($d) ? $d : null;
    }

    /* ============================================================= کمکی */

    public function probeConfigured(): bool
    {
        return str_starts_with((string) config('services.iran_probe.url', ''), 'https://');
    }

    /**
     * ردیف «از ایران» برای خروجی ابزارها.
     * سه حالت: unconfigured (probe نداریم) · unreachable (probe جواب نداد) · سنجیده‌شده.
     */
    private function iranRow(string $url): array
    {
        if (! $this->probeConfigured()) {
            return ['state' => 'unconfigured'];
        }

        $d = $this->probeFetch($url);
        if ($d === null) {
            return ['state' => 'unreachable'];      // زیرساخت probe جواب نداد
        }
        if (! ($d['ok'] ?? false)) {
            /*
            | 🔴 «fetch شکست خورد» با «probe خراب است» یکی نیست: probe زنده است
            | و از داخل ایران به سایت نرسیده — این خودش مدرکِ دسترسی است.
            | یکی‌گرفتنشان باعث می‌شد سایتِ واقعاً بسته (توییتر در تست زنده)
            | حکمِ «به‌احتمال زیاد در دسترس» بگیرد.
            */
            return ($d['error'] ?? '') === 'fetch_failed'
                ? ['state' => 'failed']
                : ['state' => 'unreachable'];
        }

        return [
            'state'    => 'ok',
            'ok'       => ($d['status'] ?? 0) > 0 && ($d['status'] ?? 600) < 400,
            'status'   => $d['status'] ?? null,
            'total_ms' => $d['total_ms'] ?? null,
        ];
    }

    /** ورودی کاربر → URL کامل (پیش‌فرض https) یا null */
    private function normalizeUrl(string $input): ?string
    {
        $s = trim($input);
        if ($s === '') {
            return null;
        }
        if (! preg_match('~^https?://~i', $s)) {
            $s = 'https://'.$s;
        }
        $p = parse_url($s);
        if (! $p || empty($p['host']) || $this->net->host($p['host']) === null) {
            return null;
        }

        return strtolower($p['scheme']).'://'.strtolower($p['host'])
            .(isset($p['path']) ? $p['path'] : '/')
            .(isset($p['query']) ? '?'.$p['query'] : '');
    }

    private function absolutize(string $base, string $next): string
    {
        if (preg_match('~^https?://~i', $next)) {
            return $next;
        }
        $p = parse_url($base);
        $root = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '');

        return $root.'/'.ltrim($next, '/');
    }
}
