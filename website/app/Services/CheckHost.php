<?php

namespace App\Services;

/**
 * پینگ و HTTP چندنقطه‌ای از شبکه‌ی نودهای check-host.net — ۵۰+ نقطه‌ی جهان،
 * شامل چند نود داخل ایران (تهران/اصفهان/شیراز) که برای مشتری ایرانی
 * جذاب‌ترین بخش ماجراست.
 *
 * چرا API بیرونی: نقطه‌ی دید چندجغرافیایی را نمی‌شود از یک سرور ساخت؛
 * check-host.net یک API عمومی JSON دارد (بدون کلید) و ما فقط نمایش‌دهنده‌ایم.
 * جریان دو‌مرحله‌ای است: «شروع بررسی» شناسه می‌دهد، بعد نتیجه‌ها را نودبه‌نود
 * poll می‌کنیم. نودی که در بودجه‌ی زمانی جواب نداد صادقانه «در انتظار» علامت
 * می‌خورد — نه «خطا».
 *
 * ⚠️ SSRF این‌جا موضوعیت ندارد: تنها مقصد HTTP ما check-host.net است و هدفِ
 * واردشده‌ی کاربر فقط به‌عنوان پارامتر به آن می‌رود؛ با این‌حال ورودی از همان
 * اعتبارسنج‌های NetworkTools رد می‌شود تا آشغال هم نفرستیم.
 *
 * تماس‌های شبکه در متدهای protected جمع‌اند (تست جایگزین می‌کند)؛
 * نرمال‌سازها توابع خالص‌اند و با پاسخ‌های واقعیِ ضبط‌شده تست می‌شوند.
 */
class CheckHost
{
    private const BASE = 'https://check-host.net';

    /** کل بودجه‌ی انتظار برای نتیجه‌ها (ثانیه) — بعدش «در انتظار» گزارش می‌شود */
    public const POLL_BUDGET = 14;

    /** فاصله‌ی بین pollها (ثانیه) */
    private const POLL_INTERVAL = 3;

    public function __construct(private NetworkTools $net) {}

    /* ============================================================= پینگ جهانی */

    public function ping(string $target): array
    {
        $host = $this->normalizeHost($target);
        if ($host === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        $start = $this->startCheck('check-ping', $host);
        if ($start === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }

        $result = $this->pollResult($start['request_id']);
        $rows = self::normalizePing($start['nodes'], $result);

        return [
            'ok'     => true,
            'domain' => $host,
            'link'   => $start['permanent_link'] ?? null,
            'rows'   => $rows,
        ] + self::pingSummary($rows);
    }

    /**
     * نرمال‌سازی نتیجه‌ی پینگ — تابع خالص.
     *
     * شکل ورودی (پاسخ واقعی API):
     *   nodes:  {"ir1.node.check-host.net": ["ir","Iran","Tehran","1.2.3.4","AS..."], ...}
     *   result: {"node": [[["OK",0.024,"ip"],["OK",0.023],...]]} · null = هنوز نیامده
     *           عضو تکی می‌تواند ["TIMEOUT",3.0] یا ["MALFORMED",...] باشد
     */
    public static function normalizePing(array $nodes, array $result): array
    {
        $rows = [];
        foreach ($nodes as $node => $meta) {
            [$cc, $country, $city] = [$meta[0] ?? '', $meta[1] ?? '', $meta[2] ?? ''];

            $r = $result[$node] ?? null;
            if ($r === null) {
                $rows[] = self::row($node, $cc, $country, $city, 'pending');
                continue;
            }

            $pings = is_array($r[0] ?? null) ? $r[0] : [];
            if ($pings === []) {
                // نود جواب داد ولی بی‌داده — از دید ما «خطا»ی همان نود است
                $rows[] = self::row($node, $cc, $country, $city, 'error');
                continue;
            }

            $times = [];
            $sent = 0;
            foreach ($pings as $p) {
                $sent++;
                if (($p[0] ?? '') === 'OK' && is_numeric($p[1] ?? null)) {
                    $times[] = round(((float) $p[1]) * 1000, 1);
                }
            }

            $rows[] = self::row($node, $cc, $country, $city, $times === [] ? 'timeout' : 'ok', [
                'sent' => $sent,
                'recv' => count($times),
                'loss' => $sent > 0 ? (int) round((1 - count($times) / $sent) * 100) : 100,
                'min'  => $times === [] ? null : min($times),
                'avg'  => $times === [] ? null : round(array_sum($times) / count($times), 1),
                'max'  => $times === [] ? null : max($times),
            ]);
        }

        return self::iranFirst($rows);
    }

    /** @return array{nodes:int,answered:int,ok:int} */
    public static function pingSummary(array $rows): array
    {
        return [
            'nodes'    => count($rows),
            'answered' => count(array_filter($rows, fn ($r) => $r['state'] !== 'pending')),
            'ok'       => count(array_filter($rows, fn ($r) => $r['state'] === 'ok')),
        ];
    }

    /* ============================================================= HTTP جهانی */

    public function http(string $target): array
    {
        $host = $this->normalizeHost($target);
        if ($host === null) {
            return ['ok' => false, 'error' => 'invalid_domain'];
        }

        // check-http بدون scheme خودش https می‌زند؛ صریح می‌فرستیم که ابهام نماند
        $start = $this->startCheck('check-http', 'https://'.$host.'/');
        if ($start === null) {
            return ['ok' => false, 'error' => 'unreachable'];
        }

        $result = $this->pollResult($start['request_id']);
        $rows = self::normalizeHttp($start['nodes'], $result);

        return [
            'ok'     => true,
            'domain' => $host,
            'link'   => $start['permanent_link'] ?? null,
            'rows'   => $rows,
        ] + self::httpSummary($rows);
    }

    /**
     * نرمال‌سازی نتیجه‌ی HTTP — تابع خالص.
     *
     * شکل هر نود (پاسخ واقعی API):
     *   [[1, 0.638, "OK", "200", "65.109.176.14"]]   موفق (کد گاهی int می‌آید)
     *   [[0, 3.0, "Connect timeout", null, null]]     ناموفق
     *   [null, {"message": "Connect timeout"}]        خطای نود
     *   null                                          هنوز نیامده
     */
    public static function normalizeHttp(array $nodes, array $result): array
    {
        $rows = [];
        foreach ($nodes as $node => $meta) {
            [$cc, $country, $city] = [$meta[0] ?? '', $meta[1] ?? '', $meta[2] ?? ''];

            $r = $result[$node] ?? null;
            if ($r === null) {
                $rows[] = self::row($node, $cc, $country, $city, 'pending');
                continue;
            }

            $first = $r[0] ?? null;
            if (! is_array($first)) {
                $msg = is_array($r[1] ?? null) ? (string) ($r[1]['message'] ?? '') : '';
                $rows[] = self::row($node, $cc, $country, $city, 'error', ['message' => $msg ?: null]);
                continue;
            }

            $up = (int) ($first[0] ?? 0) === 1;
            $rows[] = self::row($node, $cc, $country, $city, $up ? 'ok' : 'down', [
                'time_ms' => is_numeric($first[1] ?? null) ? (int) round(((float) $first[1]) * 1000) : null,
                'status'  => isset($first[3]) && $first[3] !== null ? (string) $first[3] : null,
                'message' => $up ? null : ((string) ($first[2] ?? '') ?: null),
            ]);
        }

        return self::iranFirst($rows);
    }

    /** @return array{nodes:int,answered:int,ok:int,iran_ok:bool|null} */
    public static function httpSummary(array $rows): array
    {
        $iran = array_filter($rows, fn ($r) => $r['cc'] === 'ir' && $r['state'] !== 'pending');

        return [
            'nodes'    => count($rows),
            'answered' => count(array_filter($rows, fn ($r) => $r['state'] !== 'pending')),
            'ok'       => count(array_filter($rows, fn ($r) => $r['state'] === 'ok')),
            // جمع‌بندی ایران: null یعنی هیچ نود ایرانی جواب نداد — قضاوت نکن
            'iran_ok'  => $iran === [] ? null
                : count(array_filter($iran, fn ($r) => $r['state'] === 'ok')) > 0,
        ];
    }

    /* ============================================================= کمکی */

    private static function row(string $node, string $cc, string $country, string $city, string $state, array $extra = []): array
    {
        return [
            'node'    => explode('.', $node)[0],
            'cc'      => strtolower($cc),
            'country' => $country,
            'city'    => $city,
            'state'   => $state,
        ] + $extra;
    }

    /** نودهای ایران اول — جذاب‌ترین ردیف‌ها برای مخاطب ما؛ بقیه بر اساس کشور */
    private static function iranFirst(array $rows): array
    {
        usort($rows, function ($a, $b) {
            $ai = $a['cc'] === 'ir' ? 0 : 1;
            $bi = $b['cc'] === 'ir' ? 0 : 1;

            return $ai <=> $bi ?: strcmp($a['country'], $b['country']) ?: strcmp($a['city'], $b['city']);
        });

        return $rows;
    }

    /** میزبان معتبر (دامنه یا IP) یا null */
    private function normalizeHost(string $input): ?string
    {
        $s = trim($input);
        $s = preg_replace('~^https?://~i', '', $s) ?? '';
        $s = trim(explode('/', $s)[0]);

        if (filter_var($s, FILTER_VALIDATE_IP)) {
            return SafeUrl::isPublicIp($s) ? $s : null;
        }

        return $this->net->host($s);
    }

    /* ---- درزهای شبکه (در تست جایگزین می‌شوند) ---- */

    /** شروع بررسی؛ null = check-host در دسترس نیست */
    protected function startCheck(string $type, string $target): ?array
    {
        $raw = $this->get(self::BASE.'/'.$type.'?host='.urlencode($target));
        $d = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($d) || (int) ($d['ok'] ?? 0) !== 1 || empty($d['request_id']) || empty($d['nodes'])) {
            return null;
        }

        return $d;
    }

    /** poll نتیجه تا سقف بودجه؛ نودهای دیرکرده pending می‌مانند */
    protected function pollResult(string $requestId): array
    {
        if (! app()->runningInConsole()) {
            @set_time_limit(self::POLL_BUDGET + 30);
        }

        $deadline = microtime(true) + self::POLL_BUDGET;
        $result = [];

        while (true) {
            sleep(self::POLL_INTERVAL);

            $raw = $this->get(self::BASE.'/check-result/'.rawurlencode($requestId));
            $d = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($d)) {
                $result = $d;
                // همه جواب دادند؟ زودتر تمام کن
                if (! in_array(null, $d, true)) {
                    break;
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }
        }

        return array_filter($result, fn ($v) => $v !== null);
    }

    protected function get(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'ServerNet-Tools/1.0 (+https://servernet.cloud)',
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return is_string($raw) && $code === 200 ? $raw : null;
    }
}
