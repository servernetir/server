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

    /** انتقال داده از SQLite فعلی سایت به MariaDB */
    private function port(): bool
    {
        $sqlitePath = database_path('database.sqlite');
        if (! is_file($sqlitePath)) {
            $this->error("فایل SQLite پیدا نشد: $sqlitePath");
            return false;
        }

        config(['database.connections.legacy_sqlite' => [
            'driver' => 'sqlite', 'database' => $sqlitePath,
            'prefix' => '', 'foreign_key_constraints' => false,
        ]]);

        $src = DB::connection('legacy_sqlite');
        $dst = DB::connection('setup_mariadb');

        $this->line('انتقال داده…');
        $ok = true;

        foreach ([
            'users'             => ['email'],
            'posts'             => ['slug'],
            'post_translations' => ['post_id', 'locale'],
            'comments'          => ['id'],
        ] as $table => $keys) {
            if (! $src->getSchemaBuilder()->hasTable($table)) {
                $this->line("  — $table در مبدأ نیست");
                continue;
            }
            if (! $dst->getSchemaBuilder()->hasTable($table)) {
                $this->error("  ✗ $table در مقصد نیست — اول --migrate بزنید");
                $ok = false;
                continue;
            }

            $cols = $dst->getSchemaBuilder()->getColumnListing($table);
            $rows = $src->table($table)->get();

            $dst->transaction(function () use ($rows, $dst, $table, $cols, $keys) {
                foreach ($rows as $row) {
                    $data = array_intersect_key((array) $row, array_flip($cols));
                    $match = [];
                    foreach ($keys as $k) {
                        $match[$k] = $data[$k] ?? null;
                    }
                    $dst->table($table)->updateOrInsert($match, $data);
                }
            });

            $this->line(sprintf('  ✓ %-20s %d ردیف', $table, $rows->count()));
        }

        return $ok;
    }

    private function verify(): bool
    {
        config(['database.connections.legacy_sqlite' => [
            'driver' => 'sqlite', 'database' => database_path('database.sqlite'),
            'prefix' => '', 'foreign_key_constraints' => false,
        ]]);

        $src = DB::connection('legacy_sqlite');
        $dst = DB::connection('setup_mariadb');

        $this->newLine();
        $this->line('مقایسهٔ شمارش:');
        $ok = true;

        foreach (['users', 'posts', 'post_translations', 'comments'] as $t) {
            if (! $src->getSchemaBuilder()->hasTable($t) || ! $dst->getSchemaBuilder()->hasTable($t)) {
                continue;
            }
            $a = $src->table($t)->count();
            $b = $dst->table($t)->count();
            $good = $b >= $a;
            $ok = $ok && $good;
            $this->line(sprintf('  %s %-20s SQLite: %-5d MariaDB: %d', $good ? '✓' : '✗', $t, $a, $b));
        }

        $this->newLine();
        if ($ok) {
            $this->info('MariaDB آماده است. حالا می‌توانید .env را سوییچ کنید:');
            $this->line('  DB_CONNECTION=mariadb');
            $this->line('  DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD');
        } else {
            $this->error('شمارش‌ها نمی‌خوانند — سوییچ نکنید.');
        }

        return $ok;
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
