<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `catch` خالیِ تازه بی‌تصمیم وارد کد نشود.
 *
 * ═══ خرابیِ واقعی که این را لازم کرد ═══
 *
 * روتِ `/system/migrate` چهار `catch` خالی داشت. وقتی یک سیدر شکست خورد،
 * صفحه «موفق» گفت و ردیفِ الگوی پیام ساخته نشد. از بیرون شبیهِ «سیدر خراب
 * است» بود؛ در واقع خطا **گرفته و دور ریخته** شده بود. همان الگویی که
 * `mailbox:sync` را هم ماه‌ها بی‌صدا نگه داشت.
 *
 * ═══ چرا «هیچ catch خالی نباشد» قاعدهٔ غلطی است ═══
 *
 * 🔴 هر ۳۴ موردِ موجود را خواندم؛ همه عمدی‌اند و شکست را **جای دیگری**
 * گزارش می‌کنند:
 *
 *   · `routes/web.php:771` سه سرویسِ آی‌پی را پشتِ‌سرِ‌هم امتحان می‌کند و
 *     نتیجهٔ شکستِ همه، خودِ `$outIp === null` است.
 *   · `routes/web.php:1780` هر دستورِ موفق را در `$cleared` می‌گذارد؛ نبودنِ
 *     نام در پاسخ یعنی همان دستور شکست خورده.
 *   · `CloudMeterHourly.php:248` دورِ **خودِ** `ErrorTracker` پیچیده — شکستِ
 *     گزارشگر را نمی‌شود با گزارشگر گزارش کرد.
 *
 * پس قاعدهٔ درست «هرگز نبلع» نیست؛ «بلعِ بی‌ردّ نگذار» است. و آن را نمی‌شود
 * ایستا سنجید. کاری که **می‌شود** ایستا سنجید این است: تعدادشان بی‌آنکه کسی
 * تصمیم بگیرد بالا نرود.
 *
 * ⚠️ پس این خط‌کش است نه صدور حکم. با افزودنِ `catch` خالیِ تازه قرمز می‌شود
 * و نویسنده باید یا ردّی بگذارد یا آگاهانه خطِ پایه را بالا ببرد. با **کم**
 * شدن هم قرمز می‌شود — تا خطِ پایه سفت شود و امتیازِ به‌دست‌آمده پس نرود.
 *
 * ⚠️ شمارش به تفکیکِ **فایل** است نه `file:line`: شمارهٔ خط با هر ویرایشِ
 * بالادست جابه‌جا می‌شود و نگهبانی که برای تغییرِ بی‌ربط قرمز شود، حذف
 * می‌شود.
 */
class SwallowedFailuresRatchetTest extends TestCase
{
    /**
     * خطِ پایه — وضعیتِ شناخته‌شده و بررسی‌شده در مرداد ۱۴۰۵.
     *
     * @var array<string,int>
     */
    private const BASELINE = [
        'app/Console/Commands/CloudMeterHourly.php'               => 2,
        'app/Console/Commands/CloudReleaseRetry.php'              => 1,
        'app/Console/Commands/RunServiceLifecycle.php'            => 1,
        'app/Http/Controllers/Account/CloudStoreController.php'   => 2,
        'app/Http/Controllers/Account/ServiceController.php'      => 3,
        'app/Http/Controllers/Account/VerificationController.php' => 2,
        'app/Http/Controllers/Admin/BankTransferController.php'   => 1,
        /*
        | ⚠️ این یکی با ادغامِ «سایت‌ساز فاز C» آمد و **کارِ من نیست**؛ خط‌پایه
        | را بالا بردم تا نگهبان قرمزِ دائمی نشود، ولی بی‌بررسی نگذاشتمش:
        | `logAction()` نوشتنِ `ResellerApiLog` را می‌بلعد. بقیهٔ موارد شکست را
        | جایی ثبت می‌کنند، این یکی **خودش** ثبتِ ممیزیِ APIِ نمایندگی است — پس
        | شکستش یعنی رکوردِ ممیزی بی‌صدا گم می‌شود. به کارفرما گزارش شد تا
        | نویسنده‌اش تصمیم بگیرد.
        */
        'app/Http/Controllers/Api/DomainApiController.php'        => 1,
        'app/Http/Controllers/Admin/VerificationController.php'   => 2,
        'app/Http/Middleware/CustomerApiToken.php'                => 1,
        'app/Services/Bale/Admin/AdminBaleGate.php'               => 1,
        'app/Services/Cloud/CloudProvisioner.php'                 => 11,
        'app/Services/Otp/OtpService.php'                         => 1,
        'app/Services/Payment/PaymentService.php'                 => 2,
        'app/Services/Provisioning/ProvisioningService.php'       => 1,
        'routes/web.php'                                          => 3,
    ];

    /** شمارشِ `catch` با بدنهٔ کاملاً خالی، به تفکیکِ فایل. */
    private function counts(): array
    {
        $found = [];

        foreach (['app', 'routes', 'database'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $n = preg_match_all(
                    '~catch\s*\([^)]*\)\s*\{\s*\}~',
                    (string) file_get_contents($file->getPathname())
                );

                if ($n > 0) {
                    $rel = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
                    $found[$rel] = $n;
                }
            }
        }

        ksort($found);

        return $found;
    }

    public function test_no_new_silent_catch_slips_in(): void
    {
        $now = $this->counts();
        $base = self::BASELINE;
        ksort($base);

        $added = [];
        $removed = [];

        foreach ($now as $file => $n) {
            $was = $base[$file] ?? 0;
            if ($n > $was) {
                $added[] = "{$file}: {$was} ← {$n}";
            }
            if ($n < $was) {
                $removed[] = "{$file}: {$was} ← {$n}";
            }
        }

        foreach ($base as $file => $was) {
            if (! isset($now[$file])) {
                $removed[] = "{$file}: {$was} ← 0";
            }
        }

        $this->assertSame([], $added,
            "‏catch خالیِ تازه اضافه شده:\n".implode("\n", $added)
            ."\n\nیا شکست را جایی ثبت کن (ErrorTracker / مقدارِ بازگشتی / لاگ)، "
            ."یا اگر عمدی است خطِ پایه را در همین فایل بالا ببر و **دلیلش را بنویس**.");

        $this->assertSame([], $removed,
            "‏catch خالی کم شده — خبرِ خوبی است، خطِ پایه را به وضعیتِ تازه به‌روز کن:\n"
            .implode("\n", $removed));
    }

    /** خطِ پایه نباید بی‌سروصدا پوسیده باشد (فایلی که دیگر وجود ندارد). */
    public function test_the_baseline_still_describes_real_files(): void
    {
        $missing = [];

        foreach (array_keys(self::BASELINE) as $file) {
            if (! is_file(base_path($file))) {
                $missing[] = $file;
            }
        }

        $this->assertSame([], $missing,
            "خطِ پایه به فایلی اشاره دارد که دیگر نیست:\n".implode("\n", $missing));
    }
}
