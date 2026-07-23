<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  فرستندهٔ پیامک سرورنت — روی سرور ایران
 * ═══════════════════════════════════════════════════════════════════════
 *
 * ═══ چرا این شکلی و نه رابطِ ساده ═══
 *
 * آی‌پی‌پنل به آی‌پی خارج از ایران سرویس نمی‌دهد، پس سرور آلمان نمی‌تواند
 * مستقیم پیامک بفرستد. طرح اول یک رابط بود که آلمان صدایش بزند — ولی سنجش
 * نشان داد سرور ایران **اتصال ورودی از آلمان را نمی‌پذیرد**: حتی ریشهٔ
 * دامنه هم timeout می‌دهد، نه فقط مسیر رابط.
 *
 * پس جهت برعکس شد. خروجی از ایران باز است، پس این فایل خودش می‌رود سراغ
 * آلمان:
 *
 *   ۱) صف را می‌گیرد (pull)
 *   ۲) هر پیام را به آی‌پی‌پنل می‌دهد
 *   ۳) نتیجه را گزارش می‌کند (report)
 *
 * ═══ نصب ═══
 *
 *   ۱) این فایل را کنار sms-relay-secret.php بگذارید
 *   ۲) فایل sms-poller-config.php کنارش بسازید:
 *
 *        <?php return [
 *            'bridge' => 'https://servernet.cloud/api/sms',
 *            'secret' => 'همان SMS_RELAY_SECRET سرور آلمان',
 *            'token'  => 'کلید API آی‌پی‌پنل',
 *            'from'   => '+983000505',
 *            'patterns' => ['otp' => 'کد الگوی کد ورود'],
 *        ];
 *
 *   ۳) کران هر دقیقه:
 *        * * * * * /usr/local/bin/php /home/servernetir/public_html/file/sms-poller.php >/dev/null 2>&1
 *
 * ═══ چرا حلقه، و نه فقط یک بار در هر اجرای کران ═══
 *
 * کران کمترین بازه‌اش یک دقیقه است. کد یک‌بارمصرف سه دقیقه اعتبار دارد؛
 * تا یک دقیقه انتظار یعنی یک‌سوم عمر کد از دست رفته. پس هر اجرا حدود ۵۵
 * ثانیه زنده می‌ماند و هر ۳ ثانیه سر می‌زند — یعنی کد معمولاً در چند ثانیه
 * می‌رسد، با همان یک خط کران.
 *
 * قفل فایل جلوی هم‌پوشانی دو اجرا را می‌گیرد.
 */

declare(strict_types=1);

const IPPANEL   = 'https://edge.ippanel.com/v1/api/send';
const RUN_FOR   = 55;   // ثانیه
const INTERVAL  = 3;    // ثانیه بین دو سرکشی
const BATCH     = 10;

// ── پیکربندی ─────────────────────────────────────────────────────────────
$cfgFile = __DIR__ . '/sms-poller-config.php';

if (! is_file($cfgFile)) {
    fwrite(STDERR, "پیکربندی پیدا نشد: {$cfgFile}\n");
    exit(1);
}

$cfg = require $cfgFile;

foreach (['bridge', 'secret', 'token', 'from'] as $k) {
    if (empty($cfg[$k])) {
        fwrite(STDERR, "پیکربندی ناقص: {$k}\n");
        exit(1);
    }
}

// ── قفل: دو اجرای هم‌زمان یعنی پیامک تکراری و هزینهٔ دوبرابر ─────────────
$lock = fopen(__DIR__ . '/.sms-poller.lock', 'c');

if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // اجرای قبلی هنوز تمام نشده — ساکت خارج شو
}

$deadline = time() + RUN_FOR;

while (time() < $deadline) {
    $batch = pull($cfg, BATCH);

    if ($batch === null) {
        sleep(INTERVAL);
        continue;
    }

    if ($batch['messages'] === []) {
        sleep(INTERVAL);
        continue;
    }

    $results = [];

    foreach ($batch['messages'] as $m) {
        $results[] = deliver($cfg, $m);
    }

    report($cfg, $results);
    // بدون مکث ادامه بده: شاید پیام بیشتری در صف باشد
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);

// ═══════════════════════════════════════════════════════════════════════

/** درخواست امضاشده به پل آلمان */
function signedPost(array $cfg, string $path, array $body): ?array
{
    $raw   = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ts    = (string) time();
    $nonce = bin2hex(random_bytes(12));

    $ch = curl_init(rtrim($cfg['bridge'], '/') . '/' . $path);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $raw,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Relay-Timestamp: ' . $ts,
            'X-Relay-Nonce: ' . $nonce,
            'X-Relay-Signature: ' . hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $raw, $cfg['secret']),
        ],
    ]);

    $out = curl_exec($ch);
    curl_close($ch);

    if ($out === false) {
        return null;
    }

    $json = json_decode((string) $out, true);

    return is_array($json) ? $json : null;
}

function pull(array $cfg, int $limit): ?array
{
    $r = signedPost($cfg, 'pull', ['limit' => $limit]);

    return isset($r['messages']) && is_array($r['messages']) ? $r : null;
}

function report(array $cfg, array $results): void
{
    if ($results !== []) {
        signedPost($cfg, 'report', ['results' => $results]);
    }
}

/** یک پیام را به آی‌پی‌پنل بده */
function deliver(array $cfg, array $m): array
{
    $to      = e164((string) ($m['destination'] ?? ''));
    $pattern = $m['event'] ? ($cfg['patterns'][$m['event']] ?? null) : null;

    if ($to === '') {
        return ['id' => $m['id'], 'ok' => false, 'code' => 'bad_dest', 'message' => 'شمارهٔ نامعتبر'];
    }

    if ($pattern !== null) {
        // مسیر الگو — تحویل فوری، برای کد ورود حیاتی است
        $body = [
            'sending_type' => 'pattern',
            'from_number'  => e164((string) $cfg['from']),
            'code'         => $pattern,
            'recipients'   => [$to],          // در حالت pattern بیرون params است
            'params'       => array_map('strval', (array) ($m['params'] ?? [])),
        ];
    } elseif (! empty($m['body'])) {
        $body = [
            'sending_type' => 'webservice',
            'from_number'  => e164((string) $cfg['from']),
            'message'      => (string) $m['body'],
            'params'       => ['recipients' => [$to]],
        ];
    } else {
        return ['id' => $m['id'], 'ok' => false, 'code' => 'no_content', 'message' => 'نه الگو نه متن'];
    }

    [$status, $json] = ippanel($cfg['token'], $body);

    // مثل بقیهٔ سرویس‌های ایرانی: کد HTTP معیار نیست، بدنه معیار است
    $ok = ($json['meta']['status'] ?? null) === true;

    return [
        'id'      => $m['id'],
        'ok'      => $ok,
        'code'    => (string) ($json['meta']['message_code'] ?? $status),
        'message' => mb_substr((string) ($json['meta']['message'] ?? ''), 0, 200),
    ];
}

/** @return array{0:int,1:array} */
function ippanel(string $token, array $body): array
{
    $ch = curl_init(IPPANEL);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: ' . $token,   // بدون Bearer — عمدی
        ],
    ]);

    $out    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = $out === false ? [] : (json_decode((string) $out, true) ?: []);

    return [$status, $json];
}

/** 09121234567 → +989121234567 */
function e164(string $n): string
{
    $d = preg_replace('/\D/', '', $n) ?? '';

    if ($d === '') {
        return '';
    }

    if (str_starts_with($d, '0098')) return '+' . substr($d, 2);
    if (str_starts_with($d, '98'))   return '+' . $d;
    if (str_starts_with($d, '0'))    return '+98' . substr($d, 1);

    return '+98' . $d;
}
