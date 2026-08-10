<?php

namespace Tests;

use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static bool $trackerCleanupRegistered = false;

    /**
     * 🔴 گلوگاه‌های **فایلی** بین تست‌ها پاک می‌شوند.
     *
     * هشدارهای این پروژه عمداً روی فایل گلوگاه دارند نه روی کش، چون کش روی
     * همان دیتابیسی است که گاهی می‌میرد و هشداری که با مرگِ وابستگی خفه شود
     * بی‌فایده است (CLAUDE.md: «هیچ چیزی که قرار است از مرگِ یک وابستگی خبر دهد
     * نباید روی همان وابستگی بنشیند»).
     *
     * ولی فایل — برخلافِ کشِ `array` در تست — بینِ تست‌ها **زنده می‌مانَد**. بی
     * این پاک‌سازی، اولین تستی که هشدار می‌سازد گلوگاه را می‌بندد و تستِ بعدی
     * سکوت می‌بیند و سبز می‌شود: یعنی گاردِ «این خرابی باید داد بزند» دقیقاً در
     * سوئیتِ کامل از کار می‌افتد و تنها وقتی تنها اجرا شود کار می‌کند. این را
     * یک بار واقعاً دیدیم (سوئیتِ کامل قرمز، فیلترِ تکی سبز).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $dir = $this->isolatedTrackerDirectory();

        foreach ((array) @glob($dir.'/app/'.ErrorTracker::THROTTLE_PREFIX.'*') as $f) {
            @unlink($f);
        }

        // آهنگِ برش ممکن است تستِ قبلی عوضش کرده باشد — استاتیک است و می‌مانَد.
        ErrorTracker::trimOneWriteIn(null);

        /*
        | 🔴 و **خودِ ردیاب** هم فایلی است، با همان بیماری از سمتِ دیگر.
        |
        | `storage/logs/tracker*.jsonl` بینِ تست‌ها زنده می‌مانَد. تستی که ادعا
        | می‌کند «هیچ ۴۰۴ی ثبت نشده» یا «هیچ خطایی ثبت نشده»، در سوئیتِ کامل
        | ردیفِ تستِ قبلی را می‌بیند و قرمز می‌شود — بی‌آنکه چیزی در کد خراب شده
        | باشد. (نمونهٔ واقعی: `TrackNotFoundTest` که ۴۰۴ِ
        | `/account/services/1/terminate/start` از `ServiceTerminateOtpTest` را
        | می‌دید.) چند تست خودشان `ErrorTracker::clear()` می‌زدند؛ همان کار
        | این‌جا یک‌جا انجام می‌شود تا تستِ بعدی هم که یادش می‌رود، امن باشد.
        */
        ErrorTracker::clear();
    }

    /**
     * 🔴 هر پروسهٔ PHPUnit پوشهٔ ردیابِ **خودش** را دارد.
     *
     * مسیرهای ردیاب ثابت و سراسری‌اند (`storage/logs/tracker*.jsonl` و
     * `storage/app/throttle-*`). پس دو اجرای هم‌زمان روی همین نصب — یک سوئیتِ
     * کامل در یک پنجره و یک `--filter` در پنجرهٔ دیگر — **یک** فایل را
     * می‌نویسند، و `setUp`ِ بالا فایلِ آن یکی را وسطِ کار خالی می‌کند.
     *
     * نتیجه دقیقاً همان چیزی است که یک بار ساعت‌ها وقت گرفت:
     * `test_a_flood_of_404s_does_not_evict_real_errors` حدود یک بار از هر سه
     * قرمز می‌شد، در حالی که هیچ چیزی در کد خراب نبود. مظنونِ طبیعی —
     * `random_int` در `write()` — بی‌گناه بود: فایلِ خطاها **یک** خط دارد و
     * `trim()` زیرِ ۴۰۰ خط اصلاً چیزی برنمی‌دارد.
     *
     * و بهایش از یک تستِ قرمز بیشتر است: در سوئیتی با ۱۷۰۰ تست، قرمزِ تصادفی
     * یاد می‌دهد که قرمز را نادیده بگیرند.
     */
    private function isolatedTrackerDirectory(): string
    {
        $dir = storage_path('framework/testing/tracker/'.getmypid());

        foreach (['logs', 'app'] as $sub) {
            if (! is_dir($dir.'/'.$sub)) {
                @mkdir($dir.'/'.$sub, 0777, true);
            }
        }

        ErrorTracker::useDirectory($dir);

        // پوشه را در پایانِ همین پروسه جمع کن، وگرنه با چرخشِ PID روی هم انبار می‌شود.
        if (! self::$trackerCleanupRegistered) {
            self::$trackerCleanupRegistered = true;

            register_shutdown_function(static function () use ($dir) {
                foreach ((array) @glob($dir.'/{logs,app}/*', GLOB_BRACE) as $f) {
                    @unlink($f);
                }
                foreach (['logs', 'app', ''] as $sub) {
                    @rmdir(rtrim($dir.'/'.$sub, '/'));
                }
            });
        }

        return $dir;
    }
}
