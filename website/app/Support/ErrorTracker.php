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

    /**
     * 🔴 ۴۰۴ها فایلِ **جدا** دارند.
     *
     * قبلاً هر دو در یک فایل با سقفِ ۴۰۰ خط بودند. ۴۰۴ در هر سایتی ده‌ها برابرِ
     * ۵۰۰ است (خزنده، لینکِ قدیمی، اسکنرِ خودکار)، پس سیلِ ۴۰۴ خطاهای ۵۰۰ را از
     * پنجره بیرون می‌انداخت — دقیقاً همان چیزهایی که این ابزار برایشان ساخته
     * شده. روی همین نصب نسبت ۴۶۱ به ۲ بود.
     */
    private static function path(string $type = 'error'): string
    {
        return storage_path($type === 'notfound' ? 'logs/tracker-404.jsonl' : 'logs/tracker.jsonl');
    }

    /**
     * ثبتِ خرابیِ **گرفته‌شده** — خطایی که عمداً `catch` شده و جریان را نشکسته.
     *
     * 🔴 چرا لازم بود: `exception()` فقط چیزی را می‌بیند که تا بالای پشته
     * می‌رسد. ولی قاعدهٔ این پروژه — که در کامنتِ تک‌تکِ `catch`ها نوشته شده —
     * این است که مسیرهای پول و تحویل بگیرند و ادامه دهند، تا یک درگاهِ پیامکِ
     * خراب پرداختِ واقعی را برنگرداند. آن قاعده درست است، ولی نتیجه‌اش این بود
     * که ردیاب ساختاراً **نابینا** بود نسبت به همان کلاسی از باگ که بارها این
     * پروژه را زده: «تحویل شکست نمی‌خورد، فقط اتفاق نمی‌افتد».
     *
     * ⚠️ این را **داخلِ** همان `catch` صدا بزن، نه به‌جایش. رفتارِ بگیر-و-ادامه‌بده
     * باید دست‌نخورده بماند؛ این فقط چشمِ ما را باز می‌کند.
     *
     * @param  string  $area  حوزه: `payment` / `provision` / `notify` / …
     * @param  array<string,scalar|null>  $ctx  شناسه‌هایی که برای پیگیری لازم است
     */
    public static function note(string $area, Throwable|string $what, array $ctx = []): void
    {
        $isThrowable = $what instanceof Throwable;

        // 🔴 سقفِ سختِ ctx. بی‌این، یک فراخوان با بدنهٔ خامِ درگاه (چند
        // مگابایت) یک خطِ غول در فایل می‌نویسد؛ بعد `trim()` که با `file()`
        // کلِ فایل را به حافظه می‌آورد، **fatal** می‌دهد — و fatal را
        // `catch (Throwable)` نمی‌گیرد. چون این متد از داخلِ catchِ مسیرِ
        // پول صدا زده می‌شود، یعنی وب‌هوکِ پرداخت وسطِ کار می‌مُرد.
        $ctx = array_slice($ctx, 0, 12, true);

        foreach ($ctx as $k => $v) {
            $ctx[$k] = is_scalar($v) || $v === null
                ? (is_string($v) ? mb_substr($v, 0, 200) : $v)
                : mb_substr(json_encode($v, JSON_UNESCAPED_UNICODE) ?: '', 0, 200);
        }

        self::write([
            'type'    => 'incident',
            'status'  => 0,
            'area'    => $area,
            'class'   => $isThrowable ? $what::class : null,
            'message' => mb_substr($isThrowable ? $what->getMessage() : $what, 0, 500),
            'file'    => $isThrowable
                ? str_replace(base_path().DIRECTORY_SEPARATOR, '', $what->getFile()).':'.$what->getLine()
                : null,
            'frame'   => $isThrowable ? self::firstAppFrame($what) : null,
            'ctx'     => $ctx === [] ? null : $ctx,
        ], request());
    }

    /** پیشوندِ فایل‌های گلوگاه — تستْ همین را پاک می‌کند */
    public const THROTTLE_PREFIX = 'throttle-';

    /**
     * مثلِ `note()`، ولی حداکثر یک بار در هر بازه.
     *
     * 🔴 چرا لازم است: پنجرهٔ ردیاب ۴۰۰ خط است. یک خرابیِ کوچک اما پرتکرار —
     * مثلِ ردیفِ ناهم‌شکلِ نرخِ ارز که در **رندرِ هر صفحه** خوانده می‌شود — همان
     * پنجره را پر می‌کند و خطاهای گران‌قیمت را بیرون می‌اندازد. همان اتفاقی که
     * با سیلِ ۴۰۴ افتاد (نسبتِ ۴۶۱ به ۲) و برای رفعش فایلِ ۴۰۴ جدا شد.
     *
     * ⚠️ گلوگاه روی **فایل** است، نه کش. قاعدهٔ نوشته‌شدهٔ این پروژه: «هیچ چیزی
     * که قرار است از مرگِ یک وابستگی خبر دهد، نباید روی همان وابستگی بنشیند» —
     * و کشِ پیش‌فرضِ پروداکشن روی همان دیتابیسی است که گاهی می‌میرد.
     *
     * ⚠️ اگر گلوگاه خودش خطا داد، پیش‌فرض **ثبت‌کردن** است: خطِ تکراری آزارنده
     * است، خطِ نوشته‌نشده گران.
     */
    public static function noteOnce(string $area, Throwable|string $what, int $seconds = 900, array $ctx = []): void
    {
        $key = $area.'-'.md5($what instanceof Throwable ? $what->getMessage() : $what);

        if (! self::throttlePassed($key, $seconds)) {
            return;
        }

        self::note($area, $what, $ctx);
    }

    /**
     * آیا اجازهٔ «داد زدن» با این کلید در این بازه هست؟
     *
     * `$signature` وقتی عوض شود گلوگاه فوراً باز می‌شود — تا خرابیِ **تازه** پشتِ
     * گلوگاهِ خرابیِ قبلی نماند (همان درسِ امضای `SystemHealthCheck`).
     */
    public static function throttlePassed(string $key, int $seconds, string $signature = ''): bool
    {
        try {
            $path = storage_path('app/'.self::THROTTLE_PREFIX
                .substr((string) preg_replace('/[^a-z0-9\-]/i', '', $key), 0, 60)
                .'-'.substr(md5($key), 0, 8));

            if (is_file($path)
                && (time() - (int) @filemtime($path)) < $seconds
                && trim((string) @file_get_contents($path)) === $signature) {
                return false;
            }

            @file_put_contents($path, $signature);

            return true;
        } catch (Throwable) {
            return true;
        }
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

    /**
     * آخرین N ردیف، تازه‌ترین اول.
     *
     * `$type` می‌گوید کدام فایل: `error` (شاملِ `incident`) یا `notfound`.
     */
    public static function recent(int $limit = 100, string $type = 'error'): array
    {
        if (! is_file(self::path($type))) {
            return [];
        }

        $lines = @file(self::path($type), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        // 🔴 اول **نوع** را فیلتر کن، بعد ببُر.
        //
        // فایلِ قدیمی هر دو نوع را با هم داشت و روی نصبِ زنده نسبتش ۴۶۱
        // به ۲ بود. اگر اول ببُریم، همان ردیف‌های کهنهٔ ۴۰۴ کلِ پنجره را
        // پر می‌کنند و خطاهای واقعی — که تازه‌ترند ولی کمترند — دیده
        // نمی‌شوند؛ یعنی دقیقاً همان کوریِ که این تغییر برای رفعش بود،
        // بعد از دپلوی هم ادامه پیدا می‌کرد چون ردیف‌های کهنه با سقفِ
        // ۴۰۰ خطی عملاً هرگز کهنه نمی‌شوند.
        $want = $type === 'notfound' ? ['notfound'] : ['error', 'incident'];

        $out = [];

        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);

            if (! is_array($row) || ! in_array(($row['type'] ?? ''), $want, true)) {
                continue;
            }

            $out[] = $row;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public static function clear(): void
    {
        @file_put_contents(self::path('error'), '');
        @file_put_contents(self::path('notfound'), '');
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

            $file = self::path((string) ($row['type'] ?? 'error'));
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

            // برش گاه‌به‌گاه تا فایل کنترل‌شده بماند (نه هر بار، برای سرعت)
            if (random_int(1, 25) === 1) {
                self::trim($file);
            }
        } catch (Throwable) {
            // ردیاب خطا نباید خودش منبع خطا شود
        }
    }

    private static function trim(string $file): void
    {
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
