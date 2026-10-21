<?php

namespace App\Services\Hermes;

use App\Support\ErrorTracker;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/** Bounded diagnostics: never persist request bodies, headers, URLs or model text. */
final class HermesMiniAppErrors
{
    private string $directory;

    private const MESSAGES = [
        'owner_unauthorized' => 'برای تأیید دوبارهٔ حساب، مینی‌اپ را ببندید و از داخل بله باز کنید.',
        'hermes_disabled' => 'اتصال Hermes در حال حاضر فعال نیست.',
        'ingress_unconfigured' => 'اتصال امن Hermes نیاز به بررسی دارد.',
        'upstream_timeout' => 'پاسخ Hermes دیر رسید؛ وضعیت همین درخواست را پیگیری کنید. درخواست خودکار تکرار نشده است.',
        'upstream_unavailable' => 'ارتباط با Hermes موقتاً برقرار نشد؛ وضعیت درخواست را دوباره بررسی کنید.',
        'upstream_non_json' => 'سرور به‌جای پاسخ گفتگو یک صفحه برگرداند؛ این خطا برای بررسی ثبت شد.',
        'upstream_invalid_json' => 'پاسخ سرور کامل یا معتبر نبود؛ این خطا برای بررسی ثبت شد.',
        'upstream_protocol_error' => 'وضعیت گفتگو معتبر دریافت نشد؛ درخواست خودکار تکرار نشده است.',
        'upstream_rejected' => 'ورودی امن Hermes درخواست را نپذیرفت؛ اتصال باید بررسی شود.',
        'validation_failed' => 'پیام و شناسهٔ درخواست را بررسی کنید؛ پیام باید بین ۱ تا ۴۰۰۰ نویسه باشد.',
        'request_not_found' => 'درخواست موردنظر پیدا نشد.',
        'throttled' => 'تعداد درخواست‌ها زیاد شده است؛ کمی بعد دوباره تلاش کنید.',
        'state_unavailable' => 'گزارش پایش موقتاً در دسترس نیست.',
        'internal_error' => 'این درخواست کامل نشد؛ شناسهٔ پیگیری برای بررسی ثبت شده است.',
        'client_non_json' => 'مرورگر به‌جای دادهٔ گفتگو یک صفحه دریافت کرده است.',
        'client_invalid_json' => 'مرورگر پاسخ نامعتبر دریافت کرده است.',
        'client_network' => 'دریافت پاسخ در مرورگر با اختلال شبکه روبه‌رو شده است.',
        'client_timeout' => 'زمان انتظار دریافت پاسخ در مرورگر پایان یافته است.',
        'client_http' => 'یک درخواست مرورگر با پاسخ ناموفق روبه‌رو شده است.',
        'client_error' => 'خطایی در رابط گفتگو ثبت شده است.',
        'agent_failed' => 'Hermes نتوانست این درخواست را کامل کند؛ نتیجه را بررسی کنید و در صورت نیاز درخواست تازه‌ای بفرستید.',
        'interrupted' => 'پردازش این درخواست متوقف شده است؛ درخواست خودکار تکرار نشده است.',
        'busy' => 'یک درخواست دیگر در حال بررسی است؛ ابتدا نتیجهٔ همان درخواست را پیگیری کنید.',
    ];

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? storage_path('app/private/hermes-miniapp');
    }

    public static function requestId(Request $request): string
    {
        $id = $request->attributes->get('hermes_miniapp_request_id');
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{32}$/D', $id)) {
            $id = bin2hex(random_bytes(16));
            $request->attributes->set('hermes_miniapp_request_id', $id);
        }
        return $id;
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES['internal_error'];
    }

    public static function knownCode(string $code): bool
    {
        return array_key_exists($code, self::MESSAGES);
    }

    public static function failure(string $code, string $requestId, int $status = 503): array
    {
        $code = array_key_exists($code, self::MESSAGES) ? $code : 'internal_error';
        return ['ok' => false, 'error' => self::message($code), 'code' => $code,
            'request_id' => self::safeRequestId($requestId), 'http_status' => max(400, min(599, $status))];
    }

    public static function operation(string $operation): string
    {
        $path = explode('?', $operation, 2)[0];
        if (preg_match('~^/bale/hermes/chat/[A-Za-z0-9_-]{12,80}$~D', $path)) {
            return 'chat_poll';
        }
        return [
            '/bale/hermes/chat' => 'chat', '/bale/hermes/session' => 'overview',
            '/bale/hermes/automation' => 'automation', '/bale/hermes/errors' => 'errors',
            '/bale/hermes/client-errors' => 'client_errors', '/bale/hermes/chat/history' => 'history',
            '/bale/hermes/state' => 'overview',
        ][$path] ?? (in_array($path, ['chat', 'history', 'send', 'poll', 'overview', 'automation', 'errors', 'client_errors', 'auth', 'route'], true) ? $path : 'unknown');
    }

    public static function clientCode(string $code): string
    {
        return [
            'non_json_response' => 'client_non_json', 'non_json' => 'client_non_json',
            'unexpected_redirect' => 'client_non_json', 'empty_response' => 'client_invalid_json',
            'malformed_json' => 'client_invalid_json', 'invalid_envelope' => 'client_invalid_json',
            'invalid_job_state' => 'client_invalid_json', 'network_error' => 'client_network',
            'timeout' => 'client_timeout', 'http_error' => 'client_http', 'job_failed' => 'agent_failed',
        ][$code] ?? 'client_error';
    }

    public function record(string $code, string $operation, int $status, string $contentType, string $requestId): void
    {
        $code = array_key_exists($code, self::MESSAGES) ? $code : 'internal_error';
        $operation = self::operation($operation);
        $status = max(0, min(599, $status));
        $contentType = self::safeContentType($contentType);
        $requestId = self::safeRequestId($requestId);
        $key = substr(hash('sha256', implode('|', [$code, $operation, $status, $contentType])), 0, 32);
        try {
            $this->mutate(function (array $rows) use ($key, $code, $operation, $status, $contentType, $requestId): array {
                $previous = $rows[$key] ?? [];
                $rows[$key] = ['utc' => gmdate('c'), 'first_utc' => $previous['first_utc'] ?? gmdate('c'),
                    'code' => $code, 'message' => self::message($code), 'count' => (int) ($previous['count'] ?? 0) + 1,
                    'request_id' => $requestId, 'operation' => $operation, 'http_status' => $status, 'content_type' => $contentType];
                uasort($rows, static fn (array $a, array $b): int => strcmp($a['utc'], $b['utc']));
                return array_slice($rows, -400, null, true);
            });
        } catch (Throwable) {
            // Diagnostics must never replace the explicit API failure returned to the owner.
        }
        try {
            ErrorTracker::noteOnce('hermes.miniapp', $code, 900, [
                'request_id' => $requestId, 'operation' => $operation, 'status' => $status, 'content_type' => $contentType,
            ]);
        } catch (Throwable) {
            // The private diagnostic file is independent of Laravel's shared tracker.
        }
    }

    public function recent(int $limit = 100): array
    {
        $rows = $this->mutate(static fn (array $rows): array => $rows, false);
        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => strcmp($b['utc'], $a['utc']));
        return array_slice($rows, 0, max(1, min(400, $limit)));
    }

    private function mutate(callable $callback, bool $write = true): array
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Hermes diagnostics directory unavailable');
        }
        chmod($this->directory, 0700);
        $lock = fopen($this->directory.'/.lock', 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Hermes diagnostics lock unavailable');
        }
        chmod($this->directory.'/.lock', 0600);
        try {
            $path = $this->directory.'/errors.json';
            $rows = is_file($path) ? json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
            if (! is_array($rows)) {
                throw new RuntimeException('Hermes diagnostics invalid');
            }
            $next = $callback($rows);
            if ($write) {
                $tmp = $path.'.'.bin2hex(random_bytes(8));
                if (file_put_contents($tmp, json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
                    throw new RuntimeException('Hermes diagnostics write failed');
                }
                chmod($tmp, 0600);
                if (! rename($tmp, $path)) {
                    @unlink($tmp);
                    throw new RuntimeException('Hermes diagnostics activation failed');
                }
            }
            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function safeContentType(string $value): string
    {
        $mime = strtolower(trim(explode(';', $value, 2)[0]));
        return preg_match('~^[a-z0-9!#$&^_.+-]{1,40}/[a-z0-9!#$&^_.+-]{1,80}$~D', $mime) ? $mime : 'unknown';
    }

    private static function safeRequestId(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $value) ? $value : 'unavailable';
    }
}
