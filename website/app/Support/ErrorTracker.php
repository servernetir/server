<?php

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;

/**
 * ردیاب خطای سرور و ۴۰۴.
 *
 * ═══ چرا فایل و نه دیتابیس ═══
 *
 * اگر علتِ ۵۰۰ خودِ دیتابیس باشد (اتصال قطع، جدول نبود)، ثبت خطا در دیتابیس
 * هم می‌شکند و همان خطایی که می‌خواهیم بگیریم، ناپدید می‌شود. پس در یک فایل
 * ساده می‌نویسیم که به هیچ سرویسی وابسته نیست.
 *
 * ═══ چرا JSONL و سقف خط ═══
 *
 * هر خطا یک خط JSON. خواندن و برش آسان است و فایل بی‌نهایت بزرگ نمی‌شود —
 * فقط N خط آخر می‌ماند. لاگ اصلی لاراول همین‌جا بزرگ شد و از دسترس خارج.
 */
class ErrorTracker
{
    private const MAX_LINES = 400;

    private static function path(): string
    {
        return storage_path('logs/tracker.jsonl');
    }

    /** ثبت یک استثنا (۵۰۰) با جزئیات کامل */
    public static function exception(Throwable $e, ?Request $request): void
    {
        // ۴۰۴ و اعتبارسنجی و احراز هویت خطای سرور نیستند — جدا ثبت می‌شوند
        $status = self::statusOf($e);

        if ($status < 500) {
            return;
        }

        self::write([
            'type'    => 'error',
            'status'  => $status,
            'method'  => $request?->method(),
            'url'     => $request?->fullUrl(),
            'class'   => $e::class,
            'message' => mb_substr($e->getMessage(), 0, 500),
            'file'    => str_replace(base_path().DIRECTORY_SEPARATOR, '', $e->getFile()).':'.$e->getLine(),
            'frame'   => self::firstAppFrame($e),
        ], $request);
    }

    /** ثبت یک ۴۰۴ — خودِ آدرسِ گمشده همان اطلاعات است */
    public static function notFound(Request $request): void
    {
        self::write([
            'type'   => 'notfound',
            'status' => 404,
            'method' => $request->method(),
            'url'    => $request->fullUrl(),
        ], $request);
    }

    /** آخرین N خطا، تازه‌ترین اول */
    public static function recent(int $limit = 100): array
    {
        if (! is_file(self::path())) {
            return [];
        }

        $lines = @file(self::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -$limit);

        $out = [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public static function clear(): void
    {
        @file_put_contents(self::path(), '');
    }

    // ─────────────────────────────── درونی ───────────────────────────────

    private static function write(array $row, ?Request $request): void
    {
        try {
            $row = [
                'at'      => now()->toIso8601String(),
                'ip'      => $request?->ip(),
                'ua'      => mb_substr((string) $request?->userAgent(), 0, 120),
                'referer' => mb_substr((string) $request?->headers->get('referer'), 0, 200) ?: null,
                'who'     => self::who(),
            ] + $row;

            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;

            $file = self::path();
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

            // برش گاه‌به‌گاه تا فایل کنترل‌شده بماند (نه هر بار، برای سرعت)
            if (random_int(1, 25) === 1) {
                self::trim();
            }
        } catch (Throwable) {
            // ردیاب خطا نباید خودش منبع خطا شود
        }
    }

    private static function trim(): void
    {
        $file = self::path();
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        if (count($lines) > self::MAX_LINES) {
            @file_put_contents($file, implode(PHP_EOL, array_slice($lines, -self::MAX_LINES)).PHP_EOL, LOCK_EX);
        }
    }

    /** چه کسی خطا را دید — بدون افشای اطلاعات حساس */
    private static function who(): string
    {
        if ($id = auth('customer')->id()) {
            return 'customer#'.$id;
        }
        if ($id = auth('web')->id()) {
            return 'staff#'.$id;
        }

        return 'guest';
    }

    private static function statusOf(Throwable $e): int
    {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return $e->getStatusCode();
        }
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return 422;
        }
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return 401;
        }

        return 500;
    }

    /** اولین قابِ استک که در کد خودمان است — جایی که واقعاً باید درستش کرد */
    private static function firstAppFrame(Throwable $e): ?string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        foreach ($e->getTrace() as $f) {
            $file = $f['file'] ?? '';
            if ($file !== '' && ! str_contains($file, 'vendor'.DIRECTORY_SEPARATOR) && str_starts_with($file, $base)) {
                return str_replace($base, '', $file).':'.($f['line'] ?? '?');
            }
        }

        return null;
    }
}
