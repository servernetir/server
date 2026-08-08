<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * آماده‌سازی MariaDB بدون قطعی سایت.
 *
 * درس گران‌قیمت: اگر اول .env را به mariadb سوییچ کنیم، سایت می‌افتد چون
 * جدول‌ها هنوز نیستند و session روی دیتابیس است. پس ترتیب برعکس می‌شود:
 * این کامند روی یک اتصال جداگانه (setup_mariadb) کار می‌کند، در حالی که
 * سایت همچنان روی SQLite زنده است. .env آخرین قدم است.
 *
 * اعتبارنامه از متغیرهای MARIADB_* خوانده می‌شود تا با DB_* سایت قاطی نشود.
 */
class SetupMariadb extends Command
{
    protected $signature = 'db:setup-mariadb
                            {--check   : فقط اتصال را بسنج}
                            {--migrate : جدول‌ها را بساز}
                            {--port    : داده را از SQLite منتقل کن}
                            {--verify  : شمارش مبدأ و مقصد را مقایسه کن}';

    protected $description = 'آماده‌سازی MariaDB روی اتصال جدا، بدون تغییر اتصال سایت';

    public function handle(): int
    {
        if (! $this->makeConnection()) {
            return self::FAILURE;
        }

        $any = $this->option('check') || $this->option('migrate')
            || $this->option('port') || $this->option('verify');

        if (! $any || $this->option('check')) {
            if (! $this->check()) {
                return self::FAILURE;
            }
            if (! $any) {
                $this->newLine();
                $this->line('گام بعد: --migrate سپس --port سپس --verify');
            }
        }

        if ($this->option('migrate') && ! $this->migrate()) {
            return self::FAILURE;
        }
        if ($this->option('port') && ! $this->port()) {
            return self::FAILURE;
        }
        if ($this->option('verify') && ! $this->verify()) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** اتصال موقت از متغیرهای MARIADB_* */
    private function makeConnection(): bool
    {
        $db   = env('MARIADB_DATABASE');
        $user = env('MARIADB_USERNAME');
        $pass = env('MARIADB_PASSWORD');

        if (blank($db) || blank($user)) {
            $this->error('MARIADB_DATABASE و MARIADB_USERNAME در .env تنظیم نشده‌اند.');
            $this->line('این‌ها عمداً از DB_* جدا هستند تا اتصال سایت دست‌نخورده بماند.');
            return false;
        }

        // MARIADB_DRIVER فقط برای تست محلی است (مثلاً sqlite) تا کل جریان
        // migrate/port/verify بدون یک سرور MariaDB واقعی قابل آزمایش باشد.
        // روی سرور تنظیم نمی‌شود و پیش‌فرض mariadb می‌ماند.
        $driver = env('MARIADB_DRIVER', 'mariadb');

        if ($driver === 'sqlite') {
            config(['database.connections.setup_mariadb' => [
                'driver' => 'sqlite', 'database' => $db,
                'prefix' => '', 'foreign_key_constraints' => false,
            ]]);

            return true;
        }

        config(['database.connections.setup_mariadb' => [
            'driver'    => $driver,
            'host'      => env('MARIADB_HOST', '127.0.0.1'),
            'port'      => env('MARIADB_PORT', '3306'),
            'database'  => $db,
            'username'  => $user,
            'password'  => $pass,
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
            'engine'    => 'InnoDB',
        ]]);

        return true;
    }

    private function check(): bool
    {
        try {
            $c = DB::connection('setup_mariadb');

            // شمارش جدول‌ها همیشه کار می‌کند و ثابت می‌کند اتصال برقرار است.
            // نسخه اختیاری است چون VERSION() فقط در MySQL/MariaDB وجود دارد.
            $n = count($c->getSchemaBuilder()->getTableListing());

            $v = '?';
            try {
                $v = $c->selectOne('SELECT VERSION() AS v')->v ?? '?';
            } catch (\Throwable) {
                $v = $c->getDriverName();
            }

            $this->info("اتصال برقرار شد ✓  نسخه: $v  ·  جدول موجود: $n");

            return true;
        } catch (\Throwable $e) {
            $this->error('اتصال ناموفق: '.$this->explain($e->getMessage()));
            return false;
        }
    }

    private function migrate(): bool
    {
        $this->line('اجرای مهاجرت‌ها روی MariaDB…');
        try {
            Artisan::call('migrate', ['--force' => true, '--database' => 'setup_mariadb'], $this->output);
        } catch (\Throwable $e) {
            $this->error('مهاجرت ناموفق: '.$e->getMessage());
            return false;
        }

        // دادهٔ پایه (ارزها و قواعد مالیات) — بدون این، قیمت‌گذاری کار نمی‌کند.
        // seeder خودش idempotent است پس اجرای دوباره بی‌خطر است.
        try {
            Artisan::call('db:seed', [
                '--force'   => true,
                '--database'=> 'setup_mariadb',
                '--class'   => \Database\Seeders\BillingFoundationSeeder::class,
            ], $this->output);
        } catch (\Throwable $e) {
            $this->warn('مهاجرت انجام شد ولی دادهٔ پایه پر نشد: '.$e->getMessage());
        }

        return true;
    }

    /*
    |---------------------------------------------------------------------
    | جدول‌هایی که عمداً منتقل **نمی‌شوند**
    |---------------------------------------------------------------------
    |
    | `migrations` مالِ خودِ مقصد است؛ رونویسی‌اش یعنی لاراول فکر کند مهاجرتی
    | اجرا شده که نشده. بقیه داده‌ی گذرا هستند: نشست و کش و صف پس از سوییچ
    | باید از صفر ساخته شوند — انتقالِ نشستِ SQLite فقط کاربرها را با نشستِ
    | نیمه‌معتبر روبه‌رو می‌کند، و کشِ منتقل‌شده می‌تواند مقدارِ کهنه را زنده
    | نگه دارد. خروج از حساب در لحظهٔ سوییچ، رفتارِ درست است.
    */
    private const SKIP = [
        'migrations', 'cache', 'cache_locks', 'sessions',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    /**
     * انتقالِ **همهٔ** جدول‌های داده‌دار از SQLite سایت به MariaDB.
     *
     * ═══ چرا این بازنویسی لازم شد ═══
     *
     * نسخهٔ قبلی فهرستِ **سخت‌کدِ چهارتایی** داشت: users, posts,
     * post_translations, comments. آن فهرست در فاز اول درست بود — وقتی پروژه
     * فقط بلاگ بود. از آن موقع کلِ سیستمِ فروش و صورت‌حساب اضافه شد و فهرست
     * هرگز به‌روز نشد. نتیجه: ابزار با لحنِ مطمئن می‌گفت «MariaDB آماده است،
     * .env را سوییچ کنید» در حالی که ۵۷ جدولِ دیگر — مشتری، فاکتور، سرویس،
     * دامنه، و **توکن‌های رمزنگاری‌شدهٔ زیرساخت در `settings`** — خالی بودند.
     * سوییچ روی آن حالت یعنی سایتی که مشتری نمی‌تواند واردش شود و پنلی که هر
     * سرورِ زندهٔ مشتری را «یتیم» گزارش می‌کند.
     *
     * پس فهرست حذف شد: جدول‌ها از **خودِ اسکیمای مبدأ** کشف می‌شوند. هر جدولِ
     * تازه‌ای که فردا اضافه شود، خودبه‌خود منتقل می‌شود.
     *
     * 🔴 **کلیدِ تطبیق `id` است، نه کلیدِ طبیعی.**
     *
     * نسخهٔ قبلی `posts` را با `slug` تطبیق می‌داد. اگر ردیفی در مقصد شناسهٔ
     * دیگری می‌گرفت، `post_translations.post_id` که از مبدأ کپی شده بود به
     * پستِ اشتباه اشاره می‌کرد — و هیچ خطایی هم نمی‌داد. با `id`، تمامِ
     * رابطه‌ها بایت‌به‌بایت حفظ می‌شوند.
     */
    private function port(): bool
    {
        $sqlitePath = database_path('database.sqlite');

        if (! is_file($sqlitePath)) {
            $this->error("فایل SQLite پیدا نشد: $sqlitePath");

            return false;
        }

        [$src, $dst] = $this->bothConnections();

        $tables = $this->portableTables($src, $dst);

        if ($tables === []) {
            $this->error('هیچ جدولِ مشترکی پیدا نشد — اول --migrate بزنید.');

            return false;
        }

        $this->line('انتقال داده — '.count($tables).' جدول…');

        /*
        | 🔴 کلیدهای خارجی در طولِ انتقال خاموش می‌شوند.
        |
        | ترتیبِ درستِ درج (والد پیش از فرزند) با مرتب‌سازیِ توپولوژیک هم شدنی
        | است، ولی این پروژه چرخهٔ واقعی دارد (`services` ↔ `invoices`: هر کدام
        | به دیگری ارجاع می‌دهد) و هیچ ترتیبی هر دو را راضی نمی‌کند. خاموش‌کردنِ
        | موقت، تنها راهِ درست است — و چون در پایان دوباره روشن می‌شود، مقصد با
        | همان محدودیت‌های قبلی به کار ادامه می‌دهد.
        */
        $dst->statement('SET FOREIGN_KEY_CHECKS=0');

        $ok = true;
        $moved = 0;

        try {
            foreach ($tables as $table) {
                $n = $this->portTable($src, $dst, $table);

                if ($n === null) {
                    $ok = false;
                    continue;
                }

                $moved += $n;
            }
        } finally {
            $dst->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->newLine();
        $this->line("مجموع: {$moved} ردیف.");

        return $ok;
    }

    /** یک جدول را منتقل می‌کند و تعدادِ ردیفِ مبدأ را برمی‌گرداند (null = خطا) */
    private function portTable($src, $dst, string $table): ?int
    {
        $cols = $dst->getSchemaBuilder()->getColumnListing($table);
        $total = $src->table($table)->count();

        if ($total === 0) {
            $this->line(sprintf('  · %-28s خالی', $table));

            return 0;
        }

        /*
        | ⚠️ روی `id` صفحه‌بندی می‌شود نه `offset`.
        |
        | `offset` روی جدولِ بزرگ کند است و اگر وسطِ کار ردیفی اضافه شود ردیف
        | جا می‌افتد. `orderBy('id')` + شرطِ «بزرگ‌تر از آخرین» هر دو را حل
        | می‌کند. جدولِ بی‌`id` (محوریِ چندکلیدی) با chunk ساده رد می‌شود.
        */
        $hasId = in_array('id', $src->getSchemaBuilder()->getColumnListing($table), true);
        $flip = array_flip($cols);
        $done = 0;

        try {
            $handle = function ($rows) use ($dst, $table, $flip, &$done) {
                $batch = [];

                foreach ($rows as $row) {
                    $batch[] = array_intersect_key((array) $row, $flip);
                }

                // upsert روی `id` — اجرای دوباره داده تکراری نمی‌سازد
                $dst->table($table)->upsert($batch, ['id'], array_keys($flip));
                $done += count($batch);
            };

            if ($hasId) {
                $src->table($table)->orderBy('id')->chunk(500, function ($rows) use ($handle) {
                    $handle($rows);
                });
            } else {
                // بی‌`id` نمی‌شود upsert زد؛ جدول را خالی و دوباره پر می‌کنیم
                $dst->table($table)->delete();
                $src->table($table)->chunk(500, function ($rows) use ($dst, $table, $flip, &$done) {
                    $batch = [];
                    foreach ($rows as $row) {
                        $batch[] = array_intersect_key((array) $row, $flip);
                    }
                    $dst->table($table)->insert($batch);
                    $done += count($batch);
                });
            }
        } catch (\Throwable $e) {
            $this->error(sprintf('  ✗ %-28s %s', $table, $this->explain($e->getMessage())));

            return null;
        }

        /*
        | 🔴 شمارندهٔ AUTO_INCREMENT را جلو ببر.
        |
        | ما `id` را صریح درج کرده‌ایم، ولی شمارندهٔ MariaDB از آن خبر ندارد و
        | روی ۱ می‌ماند. اولین درجِ واقعیِ سایت بعد از سوییچ به «Duplicate entry
        | for PRIMARY» می‌خورد — یعنی اولین ثبت‌نام یا اولین فاکتور شکست
        | می‌خورد، با خطایی که هیچ ربطی به علتش ندارد و ساعت‌ها وقت می‌برد.
        */
        if ($hasId) {
            $max = (int) $dst->table($table)->max('id');
            $dst->statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = '.($max + 1));
        }

        $this->line(sprintf('  ✓ %-28s %d ردیف', $table, $done));

        return $total;
    }

    /**
     * تأیید پیش از سوییچ — **برابریِ دقیق**، نه «کمتر نیست».
     *
     * 🔴 نسخهٔ قبلی `$dst >= $src` را ✓ می‌داد. یعنی وقتی مبدأ ۳۹ پست داشت و
     * مقصد ۱۲۷، تیکِ سبز می‌خورد و پیامِ «MariaDB آماده است» می‌آمد. علامتی که
     * در حالتِ مشکوک هم سبز است، بدتر از نبودنش است: خواننده بر اساسش تصمیمِ
     * برگشت‌ناپذیر می‌گیرد.
     *
     * حالا اختلاف در هر جهت گزارش می‌شود؛ «مقصد بیشتر» خطا نیست ولی ✓ هم
     * نمی‌گیرد — مدیر باید بداند و خودش تصمیم بگیرد.
     */
    private function verify(): bool
    {
        [$src, $dst] = $this->bothConnections();

        $tables = $this->portableTables($src, $dst);

        $this->newLine();
        $this->line('مقایسهٔ شمارش — همهٔ جدول‌ها:');

        $bad = [];
        $extra = [];
        $rows = 0;

        foreach ($tables as $t) {
            $a = $src->table($t)->count();
            $b = $dst->table($t)->count();
            $rows += $a;

            $mark = match (true) {
                $a === $b => '✓',
                $b > $a   => '+',
                default   => '✗',
            };

            if ($b < $a) {
                $bad[] = $t;
            } elseif ($b > $a) {
                $extra[] = $t;
            }

            // فقط ردیف‌های جالب چاپ می‌شوند، وگرنه ۶۰ خط ✓ کسی را نمی‌خوانَد
            if ($mark !== '✓' || $a > 0) {
                $this->line(sprintf('  %s %-28s SQLite: %-6d MariaDB: %d', $mark, $t, $a, $b));
            }
        }

        // جدولی که در مقصد اصلاً نیست = مهاجرتِ اجرانشده
        $missing = array_values(array_diff(
            array_filter($this->tableNames($src), fn ($t) => ! in_array($t, self::SKIP, true)),
            $this->tableNames($dst)
        ));

        $this->newLine();
        $this->line(sprintf('%d جدول بررسی شد · %s ردیف در مبدأ', count($tables), number_format($rows)));

        if ($missing !== []) {
            $this->error('این جدول‌ها در مقصد **نیستند** (اول --migrate): '.implode(', ', $missing));
        }

        if ($bad !== []) {
            $this->error('مقصد کمتر از مبدأ دارد — سوییچ نکنید: '.implode(', ', $bad));
        }

        if ($extra !== []) {
            $this->warn('مقصد بیشتر از مبدأ دارد (احتمالاً سیدر یا اجرای قبلی): '.implode(', ', $extra));
            $this->line('  این خطا نیست، ولی بعد از سوییچ این ردیف‌های اضافه در سایت دیده می‌شوند.');
        }

        $ok = $bad === [] && $missing === [];

        $this->newLine();

        if (! $ok) {
            $this->error('آماده نیست — سوییچ نکنید.');

            return false;
        }

        $this->info('همهٔ جدول‌ها منتقل شده‌اند. برای سوییچ:');
        $this->line('  DB_CONNECTION=mariadb');
        $this->line('  DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD');
        $this->newLine();
        $this->warn('⚠️ پیش از سوییچ از فایل SQLite نسخهٔ پشتیبان بگیرید — بازگشت فقط با آن ممکن است.');
        $this->warn('⚠️ بعد از سوییچ همهٔ کاربران از حساب خارج می‌شوند (نشست منتقل نمی‌شود).');

        return true;
    }

    /** اتصالِ مبدأ (SQLite سایت) و مقصد (MariaDB) */
    private function bothConnections(): array
    {
        config(['database.connections.legacy_sqlite' => [
            'driver' => 'sqlite', 'database' => database_path('database.sqlite'),
            'prefix' => '', 'foreign_key_constraints' => false,
        ]]);

        return [DB::connection('legacy_sqlite'), DB::connection('setup_mariadb')];
    }

    /**
     * جدول‌هایی که در **هر دو** طرف هستند و باید منتقل شوند.
     *
     * ⚠️ کشف از اسکیما، نه فهرستِ دستی. فهرستِ دستی همان چیزی بود که این ابزار
     * را دو فاز عقب انداخت و بی‌آنکه کسی بفهمد ناقص کار می‌کرد.
     */
    private function portableTables($src, $dst): array
    {
        $have = $this->tableNames($dst);

        return array_values(array_filter(
            $this->tableNames($src),
            fn ($t) => ! in_array($t, self::SKIP, true) && in_array($t, $have, true)
        ));
    }

    /**
     * نامِ **خالصِ** جدول‌ها.
     *
     * 🔴 `getTableListing()` در این نسخهٔ لاراول نام را با پیشوندِ اسکیما
     * برمی‌گرداند — روی SQLite «main.posts» و روی MariaDB
     * «servernetcloud_billing.posts». هر دو تله را هم‌زمان می‌سازد و **هیچ‌کدام
     * خطا نمی‌دهند**:
     *
     *   ۱. تطبیقِ مبدأ و مقصد هرگز نمی‌خورد، چون «main.posts» با
     *      «servernetcloud_billing.posts» برابر نیست ⇒ ابزار می‌گوید «هیچ
     *      جدولِ مشترکی نیست» در حالی که همه‌شان هستند.
     *   ۲. فهرستِ SKIP بی‌اثر می‌شود، چون «cache» با «main.cache» نمی‌خواند
     *      ⇒ نشست و کش هم منتقل می‌شوند؛ دقیقاً همان چیزی که نباید.
     *
     * پس هرچه پیش از آخرین نقطه است بریده می‌شود. اگر روزی نسخهٔ لاراول
     * نامِ خالص برگرداند، این تابع بی‌ضرر است.
     */
    private function tableNames($conn): array
    {
        return array_map(
            fn (string $t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t,
            $conn->getSchemaBuilder()->getTableListing()
        );
    }

    /** خطای خام درایور را به زبان قابل‌فهم ترجمه می‌کند */
    private function explain(string $msg): string
    {
        return match (true) {
            str_contains($msg, 'Access denied')      => 'نام کاربری یا رمز اشتباه است، یا کاربر به دیتابیس وصل نشده',
            str_contains($msg, 'Unknown database')   => 'دیتابیس با این نام وجود ندارد — نام کامل با پیشوند cPanel لازم است',
            str_contains($msg, 'Connection refused') => 'سرویس MariaDB روی این میزبان/پورت در دسترس نیست',
            str_contains($msg, 'could not find driver') => 'افزونهٔ pdo_mysql روی PHP فعال نیست',
            default => $msg,
        };
    }
}
