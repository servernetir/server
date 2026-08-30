<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  رابط پیامک سرورنت — روی سرور ایران
 * ═══════════════════════════════════════════════════════════════════════
 *
 * چرا وجود دارد: آی‌پی‌پنل به آی‌پی‌های خارج از ایران سرویس نمی‌دهد. سرور
 * اصلی سایت در آلمان است، پس درخواست پیامک از این فایل — که روی سرور ایران
 * می‌نشیند — رد می‌شود.
 *
 * ┌── سرور آلمان ──┐        ┌── سرور ایران ──┐        ┌── آی‌پی‌پنل ──┐
 * │  اپ لاراول     │──HMAC──▶│  همین فایل     │───────▶│  edge API     │
 * └────────────────┘        └────────────────┘        └───────────────┘
 *
 * ═══ این فایل عمداً بسیار محدود است ═══
 *
 * یک رابطِ باز، یک پروکسی باز است: هرکس پیدایش کند می‌تواند از آی‌پی سرور
 * ایرانی شما به هر جایی درخواست بزند. پس:
 *
 *   ۱) مقصد **هاردکد** است. هیچ URLای از درخواست خوانده نمی‌شود.
 *   ۲) هر درخواست باید با کلید مشترک امضا شده باشد (HMAC-SHA256).
 *   ۳) امضا شامل زمان است و بیش از ۱۲۰ ثانیه اعتبار ندارد.
 *   ۴) هر nonce فقط یک بار — تکرار درخواست ضبط‌شده کار نمی‌کند.
 *   ۵) توکن آی‌پی‌پنل اینجا **ذخیره نمی‌شود**؛ از سرور آلمان می‌آید و فقط
 *      عبور داده می‌شود. اگر روزی همین فایل لو برود، کلید پیامک لو نرفته.
 *
 * ═══ نصب ═══
 *
 *   ۱) این فایل را در public_html سرور ایران بگذارید.
 *   ۲) کنارش فایل sms-relay-secret.php بسازید با محتوای:
 *          <?php return 'یک رشتهٔ تصادفی بلند';
 *      (همان رشته را در .env سرور آلمان با نام SMS_RELAY_SECRET بگذارید)
 *   ۳) پوشهٔ sms-relay-nonce/ کنارش ساخته می‌شود؛ دست نزنید.
 *
 * کلید مشترک هرگز داخل همین فایل نوشته نمی‌شود تا بشود فایل را بدون نگرانی
 * جابه‌جا و بازبینی کرد.
 */

declare(strict_types=1);

// ── مقصد مجاز: هاردکد، غیرقابل تغییر از بیرون ────────────────────────────
const UPSTREAM     = 'https://edge.ippanel.com/v1/api/send';
const MAX_SKEW     = 120;      // ثانیه
const MAX_BODY     = 16384;    // بایت — پیامک هیچ‌وقت بزرگ‌تر از این نیست
const NONCE_DIR    = __DIR__ . '/sms-relay-nonce';
const NONCE_TTL    = 300;      // ثانیه

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/** پاسخ خطا با شکلی که از پاسخ آی‌پی‌پنل قابل تشخیص باشد */
function relayFail(int $status, string $reason): never
{
    http_response_code($status);
    echo json_encode(['relay' => false, 'reason' => $reason], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── ۱) فقط POST ──────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    relayFail(405, 'method_not_allowed');
}

// ── ۲) کلید مشترک ────────────────────────────────────────────────────────
$secretFile = __DIR__ . '/sms-relay-secret.php';

if (! is_file($secretFile)) {
    relayFail(500, 'relay_not_configured');
}

$secret = require $secretFile;

if (! is_string($secret) || strlen($secret) < 24) {
    relayFail(500, 'relay_secret_too_weak');
}

// ── ۳) بدنه ──────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');

if ($raw === false || $raw === '' || strlen($raw) > MAX_BODY) {
    relayFail(400, 'bad_body');
}

// ── ۴) امضا ──────────────────────────────────────────────────────────────
$ts    = $_SERVER['HTTP_X_RELAY_TIMESTAMP'] ?? '';
$nonce = $_SERVER['HTTP_X_RELAY_NONCE'] ?? '';
$sig   = $_SERVER['HTTP_X_RELAY_SIGNATURE'] ?? '';

if ($ts === '' || $nonce === '' || $sig === '') {
    relayFail(401, 'missing_signature');
}

if (preg_match('/^[A-Za-z0-9._-]{8,64}$/', $nonce) !== 1) {
    relayFail(400, 'bad_nonce');
}

// پنجرهٔ زمانی: درخواست ضبط‌شده بعد از دو دقیقه بی‌اثر است
if (! ctype_digit((string) $ts) || abs(time() - (int) $ts) > MAX_SKEW) {
    relayFail(401, 'stale_timestamp');
}

$expected = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $raw, $secret);

// hash_equals و نه == : مقایسهٔ ساده از روی زمان پاسخ قابل حدس زدن است
if (! hash_equals($expected, (string) $sig)) {
    relayFail(401, 'bad_signature');
}

// ── ۵) nonce یک‌بارمصرف ──────────────────────────────────────────────────
if (! is_dir(NONCE_DIR) && ! @mkdir(NONCE_DIR, 0700, true) && ! is_dir(NONCE_DIR)) {
    relayFail(500, 'nonce_store_unavailable');
}

// پاکسازی تنبل — گاه‌به‌گاه، نه در هر درخواست
if (random_int(1, 20) === 1) {
    foreach ((array) glob(NONCE_DIR . '/*') as $old) {
        if (is_file($old) && filemtime($old) < time() - NONCE_TTL) {
            @unlink($old);
        }
    }
}

$nonceFile = NONCE_DIR . '/' . hash('sha256', $nonce);

// x یعنی «فقط اگر وجود ندارد» — همین اتمی بودن، تکرار هم‌زمان را می‌گیرد
$fh = @fopen($nonceFile, 'x');

if ($fh === false) {
    relayFail(409, 'nonce_replayed');
}

fclose($fh);

// ── ۶) عبور دادن به آی‌پی‌پنل ────────────────────────────────────────────
// توکن از سرور آلمان می‌آید و اینجا ذخیره نمی‌شود
$auth = $_SERVER['HTTP_X_RELAY_AUTHORIZATION'] ?? '';

if ($auth === '') {
    relayFail(400, 'missing_upstream_auth');
}

$ch = curl_init(UPSTREAM);

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $raw,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: ' . $auth,
    ],
    // مقصد ثابت است، پس تغییر مسیر لازم نیست و فقط سطح حمله می‌سازد
    CURLOPT_FOLLOWLOCATION => false,
]);

$body   = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);

curl_close($ch);

if ($body === false) {
    relayFail(502, 'upstream_unreachable: ' . substr($err, 0, 120));
}

// پاسخ آی‌پی‌پنل عیناً برگردانده می‌شود تا سمت لاراول هیچ منطق جدیدی
// لازم نباشد — همان کدی که مستقیم کار می‌کرد، از این مسیر هم کار می‌کند
http_response_code($status);
header('X-Relay: servernet-ir');
echo $body;
