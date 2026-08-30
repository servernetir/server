<?php

namespace Tests\Feature;

use App\Console\Commands\SetupMariadb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * ابزارِ انتقال به MariaDB — محافظِ سه اشتباهی که هیچ‌کدام خطا نمی‌دهند.
 *
 * ═══ باگی که این فایل برای تکرارنشدنش نوشته شد ═══
 *
 * نسخهٔ قبلی فهرستِ **سخت‌کدِ چهارتایی** داشت (users, posts, post_translations,
 * comments) که در فاز اول درست بود — وقتی پروژه فقط بلاگ بود. بعد کلِ سیستمِ
 * فروش اضافه شد و فهرست هرگز به‌روز نشد. ابزار با لحنِ مطمئن می‌گفت «MariaDB
 * آماده است، .env را سوییچ کنید» در حالی که ۵۷ جدولِ دیگر خالی بودند — از جمله
 * `settings` که توکن‌های رمزنگاری‌شدهٔ زیرساخت در آن است.
 *
 * ⚠️ اینجا به MariaDB دسترسی نداریم، پس انتقالِ واقعی تست نمی‌شود. چیزی که
 * تست می‌شود، همان لایه‌ای است که **بی‌صدا** خراب می‌شد: کشفِ جدول‌ها.
 */
class MariadbPortCoverageTest extends TestCase
{
    // ⚠️ بدون این، دیتابیسِ تست خالی است و «کشفِ جدول‌ها» چیزی برای کشف ندارد
    use RefreshDatabase;

    private function invokePrivate(string $method, array $args = []): mixed
    {
        $cmd = new SetupMariadb;
        $m = (new ReflectionClass($cmd))->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($cmd, $args);
    }

    /**
     * 🔴 `getTableListing()` نام را با پیشوندِ اسکیما می‌دهد («main.posts»).
     *
     * دو خرابیِ بی‌صدا می‌ساخت: تطبیقِ مبدأ/مقصد هرگز نمی‌خورد (نامِ مقصد
     * پیشوندِ دیگری دارد) و فهرستِ SKIP بی‌اثر می‌شد، پس نشست و کش هم منتقل
     * می‌شدند. هیچ‌کدام استثنا پرتاب نمی‌کنند.
     */
    public function test_table_names_are_stripped_of_the_schema_prefix(): void
    {
        $names = $this->invokePrivate('tableNames', [DB::connection()]);

        $this->assertNotEmpty($names);

        foreach ($names as $n) {
            $this->assertStringNotContainsString('.', $n,
                "نامِ جدول «{$n}» هنوز پیشوندِ اسکیما دارد — تطبیقِ مبدأ و مقصد می‌شکند");
        }

        $this->assertContains('posts', $names);
        $this->assertContains('customers', $names);
    }

    /**
     * 🔴 ادعای اصلی: کشف باید **همهٔ** جدول‌های داده‌دار را بگیرد.
     *
     * اگر روزی کسی دوباره فهرستِ دستی بگذارد، این تست قرمز می‌شود — و همان
     * چیزی است که یک بار باعث شد ابزار دو فاز عقب بماند.
     */
    public function test_discovery_covers_the_whole_billing_system_not_just_the_blog(): void
    {
        $names = $this->invokePrivate('tableNames', [DB::connection()]);

        // نمونه‌ای از هر حوزه‌ای که نسخهٔ قبلی جا انداخته بود
        $mustCover = [
            'customers', 'invoices', 'payments', 'services', 'servers', 'products',
            'domains', 'cloud_plans', 'cloud_instances', 'physical_servers',
            'settings', 'notification_templates', 'bank_transfer_receipts',
        ];

        $missing = array_values(array_diff($mustCover, $names));

        $this->assertSame([], $missing,
            "\nاین جدول‌ها در کشف نیستند و بعد از سوییچ خالی می‌مانند:\n  ".implode(', ', $missing));

        $this->assertGreaterThan(40, count($names),
            'کشف باید ده‌ها جدول بدهد؛ عددِ کوچک یعنی دوباره فهرستِ دستی شده');
    }

    /**
     * دادهٔ گذرا نباید منتقل شود.
     *
     * `migrations` مالِ خودِ مقصد است — رونویسی‌اش یعنی لاراول فکر کند مهاجرتی
     * اجرا شده که نشده. نشست و کش هم باید از صفر ساخته شوند.
     */
    public function test_transient_tables_are_excluded(): void
    {
        $skip = (new ReflectionClass(SetupMariadb::class))->getConstant('SKIP');

        foreach (['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'] as $t) {
            $this->assertContains($t, $skip, "«{$t}» باید در فهرستِ ردشده‌ها باشد");
        }

        // ولی جدول‌های واقعی نباید تصادفاً رد شوند
        foreach (['customers', 'invoices', 'settings', 'posts'] as $t) {
            $this->assertNotContains($t, $skip, "«{$t}» داده‌ی واقعی است و باید منتقل شود");
        }
    }

    /**
     * ⚠️ محافظِ ادعای مستندات: تطبیق باید روی `id` باشد نه کلیدِ طبیعی.
     *
     * نسخهٔ قبلی `posts` را با `slug` تطبیق می‌داد؛ اگر ردیفی در مقصد شناسهٔ
     * دیگری می‌گرفت، `post_translations.post_id` به پستِ اشتباه اشاره می‌کرد و
     * هیچ خطایی هم نمی‌داد.
     */
    public function test_the_port_upserts_on_the_primary_key(): void
    {
        $code = file_get_contents(app_path('Console/Commands/SetupMariadb.php'));

        $this->assertStringContainsString("upsert(\$batch, ['id']", $code,
            'تطبیق باید روی id باشد تا همهٔ رابطه‌ها حفظ شوند');

        $this->assertStringContainsString('AUTO_INCREMENT = ', $code,
            'بعد از درجِ صریحِ id باید شمارنده جلو برود، وگرنه اولین درجِ واقعیِ سایت شکست می‌خورد');

        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $code,
            'services ↔ invoices چرخه دارند؛ بی‌خاموش‌کردنِ موقت هیچ ترتیبی جواب نمی‌دهد');
    }

    /**
     * 🔴 تیکِ سبز نباید روی شمارشِ نابرابر بخورد.
     *
     * نسخهٔ قبلی `$dst >= $src` را ✓ می‌داد، پس «۳۹ در مقابل ۱۲۷» سبز بود و
     * پیامِ «MariaDB آماده است» می‌آمد. علامتی که در حالتِ مشکوک هم سبز است،
     * از نبودنش بدتر است — خواننده بر اساسش تصمیمِ برگشت‌ناپذیر می‌گیرد.
     */
    public function test_verify_does_not_call_unequal_counts_a_success(): void
    {
        $code = file_get_contents(app_path('Console/Commands/SetupMariadb.php'));

        $this->assertStringNotContainsString('$dstCount >= $srcCount', $code);
        $this->assertStringNotContainsString('$b >= $a', $code,
            'برابری باید سنجیده شود، نه «کمتر نیست»');
        $this->assertStringContainsString('$a === $b', $code);
    }

    /**
     * 🔴 وقتی سوییچ از قبل انجام شده، انتقال باید **رد** شود.
     *
     * ماجرا: سایت روی MariaDB بالا آمده بود ولی فایلِ SQLiteِ پیش از سوییچ سرِ
     * جایش مانده بود (تاریخِ تغییر: دو هفته پیش). این کامند مسیرِ آن فایل را
     * سخت‌کد می‌خواند، پس مبدأ عکسِ کهنه بود و مقصد دیتابیسِ زنده — یعنی
     * `--port` عملاً «بازگردانی از بکاپِ قدیمی روی داده‌ی زنده» می‌شد، بی‌هیچ
     * خطایی. و چون دامنهٔ کامند را به همهٔ جدول‌ها گسترش دادیم، شعاعِ آسیب از
     * بلاگ به مشتری و فاکتور و تنظیمات می‌رسید.
     */
    public function test_the_port_refuses_to_run_once_the_site_left_sqlite(): void
    {
        $code = file_get_contents(app_path('Console/Commands/SetupMariadb.php'));

        $this->assertStringContainsString('guardAgainstOverwritingLiveData', $code,
            'انتقال باید محافظ داشته باشد');

        // محافظ باید **پیش از** هر نوشتنی صدا زده شود
        $guardPos = strpos($code, 'if (! $this->guardAgainstOverwritingLiveData())');
        $writePos = strpos($code, 'SET FOREIGN_KEY_CHECKS=0');

        $this->assertNotFalse($guardPos, 'محافظ در port فراخوانی نشده');
        $this->assertLessThan($writePos, $guardPos,
            'محافظ باید قبل از شروعِ نوشتن اجرا شود، نه بعدش');

        // و شرطش باید درایورِ زندهٔ سایت باشد
        $this->assertStringContainsString("\$liveDriver === 'sqlite'", $code,
            'شرط باید درایورِ اتصالِ پیش‌فرض را بسنجد');
    }
}
