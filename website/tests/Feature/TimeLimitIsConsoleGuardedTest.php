<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 🔴 `set_time_limit()` روی ویندوز زمانِ **دیواریِ کلِ پروسه** را محدود می‌کند،
 * نه زمانِ همین درخواست را.
 *
 * ═══ خرابی‌ای که این تست برای رفعش نوشته شد ═══
 *
 * در اجرای سوئیت، هر تستی که از مسیرِ یکی از این کدها رد شود سقفی روی **کلِ
 * پروسهٔ phpunit** می‌گذارد. چند دقیقه بعد، یک تستِ کاملاً بی‌ربط با
 *
 *     Fatal error: Maximum execution time of 200 seconds exceeded
 *     Premature end of PHP process when running <یک تستِ تصادفی>
 *
 * می‌میرد و **کلِ سوئیت همان‌جا قطع می‌شود** — بدونِ خلاصه، بدونِ شمارش.
 * ردگیری‌اش تقریباً ناممکن است چون خطا جایی می‌افتد که هیچ ربطی به علت ندارد؛
 * تستِ قربانی هر بار عوض می‌شود و به‌تنهایی هم سبز است.
 *
 * `AiContent::call()` و `WebProbe::psi()` این را کشف و مستند کرده بودند، ولی
 * `AiBuilderController` (هر دو متدش) از قلم افتاده بود — و سوئیت را روی
 * ۲۰۰ ثانیه می‌بست.
 *
 * ⚠️ این تست **ایستا** است و عمداً: خرابی فقط در یک اجرای طولانیِ کامل خودش
 * را نشان می‌دهد، پس تستِ رفتاری‌اش یا باید سوئیت را نیم‌ساعت بدواند یا هیچ
 * چیزی نمی‌سنجد. سنجیدنِ خودِ قاعده ارزان و قطعی است.
 */
class TimeLimitIsConsoleGuardedTest extends TestCase
{
    public function test_every_set_time_limit_call_is_guarded_against_console_runs(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $i => $line) {
                if (! preg_match('/(?<![a-zA-Z_])set_time_limit\s*\(/', $line)) {
                    continue;
                }

                // کامنت که قاعده را توضیح می‌دهد، فراخوانی نیست
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '|')) {
                    continue;
                }

                /*
                | گارد باید در همان بلوکِ نزدیک باشد. پنج خط عقب کافی است:
                | الگوی جاافتادهٔ پروژه `if (! app()->runningInConsole()) {` را
                | بلافاصله بالای فراخوانی می‌گذارد.
                */
                $window = implode("\n", array_slice($lines, max(0, $i - 5), 5));

                if (! str_contains($window, 'runningInConsole')) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders,
            "این فراخوانی‌های set_time_limit گاردِ کنسول ندارند و کلِ سوئیت را روی همان عدد می‌بندند.\n".
            "الگوی درست:\n\n    if (! app()->runningInConsole()) {\n        @set_time_limit(\$seconds);\n    }\n");
    }

    /**
     * ⚠️ تستی که هیچ فایلی پیدا نکند هم سبز می‌شود. پس خودِ پیمایش هم سنجیده
     * می‌شود، وگرنه یک تغییرِ مسیر این گارد را بی‌صدا به no-op تبدیل می‌کند.
     */
    public function test_the_scan_actually_reaches_the_known_call_sites(): void
    {
        $found = 0;

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $found += preg_match_all('/(?<![a-zA-Z_])set_time_limit\s*\(/', (string) file_get_contents($file));
        }

        $this->assertGreaterThanOrEqual(4, $found,
            'پیمایش باید فراخوانی‌های شناخته‌شده (AiContent، WebProbe، AiBuilderController) را ببیند — '.
            'اگر صفر شد یعنی گارد دیگر هیچ‌چیز را نمی‌سنجد.');
    }

    /** @return iterable<string> */
    private function phpFilesUnder(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                yield $f->getPathname();
            }
        }
    }
}
