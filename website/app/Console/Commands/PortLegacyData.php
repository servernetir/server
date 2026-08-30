<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * انتقال دادهٔ بلاگ/پایگاه دانش/نظرات از SQLite قدیمی به اتصال فعلی (MariaDB).
 *
 * idempotent است: با updateOrInsert روی کلید طبیعی کار می‌کند، پس اجرای دوباره
 * داده تکراری نمی‌سازد. حجم داده ناچیز است (~۱۰۰ رکورد) و همین الان
 * ارزان‌ترین لحظه برای مهاجرت است.
 */
class PortLegacyData extends Command
{
    protected $signature = 'db:port-legacy
                            {--sqlite= : مسیر فایل SQLite مبدأ (پیش‌فرض database/database.sqlite)}
                            {--dry     : فقط گزارش بده، چیزی ننویس}';

    protected $description = 'انتقال پست‌ها، ترجمه‌ها، نظرات و کاربران از SQLite به دیتابیس فعلی';

    public function handle(): int
    {
        $path = $this->option('sqlite') ?: database_path('database.sqlite');

        if (! is_file($path)) {
            $this->error("فایل SQLite پیدا نشد: $path");
            return self::FAILURE;
        }

        // اتصال موقت به مبدأ
        config(['database.connections.legacy_sqlite' => [
            'driver' => 'sqlite', 'database' => $path, 'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $src = DB::connection('legacy_sqlite');
        $dst = DB::connection();

        if ($dst->getDriverName() === 'sqlite' && realpath($dst->getDatabaseName()) === realpath($path)) {
            $this->error('مبدأ و مقصد یکی هستند. اول DB_CONNECTION را به mariadb تغییر دهید.');
            return self::FAILURE;
        }

        $this->info('مبدأ : sqlite — '.$path);
        $this->info('مقصد : '.$dst->getDriverName().' — '.$dst->getDatabaseName());
        if ($this->option('dry')) {
            $this->warn('حالت آزمایشی: چیزی نوشته نمی‌شود.');
        }
        $this->newLine();

        $dry = (bool) $this->option('dry');
        $ok = true;

        $ok = $this->port($src, $dst, 'users',             ['email'],            $dry) && $ok;
        $ok = $this->port($src, $dst, 'posts',             ['slug'],             $dry) && $ok;
        $ok = $this->port($src, $dst, 'post_translations', ['post_id','locale'], $dry) && $ok;
        $ok = $this->port($src, $dst, 'comments',          ['id'],               $dry) && $ok;

        $this->newLine();
        $this->info($ok ? 'انتقال کامل شد.' : 'انتقال با خطا همراه بود — بالا را ببینید.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** یک جدول را منتقل می‌کند و شمارش مبدأ/مقصد را می‌سنجد */
    private function port($src, $dst, string $table, array $keys, bool $dry): bool
    {
        if (! $src->getSchemaBuilder()->hasTable($table)) {
            $this->line("  — $table در مبدأ نیست، رد شد");
            return true;
        }
        if (! $dst->getSchemaBuilder()->hasTable($table)) {
            $this->error("  ✗ $table در مقصد نیست — اول migrate بزنید");
            return false;
        }

        $rows = $src->table($table)->get();
        $srcCount = $rows->count();

        if (! $dry) {
            // فقط ستون‌هایی که در مقصد هم وجود دارند
            $dstCols = $dst->getSchemaBuilder()->getColumnListing($table);

            foreach ($rows as $row) {
                $data = array_intersect_key((array) $row, array_flip($dstCols));
                $match = [];
                foreach ($keys as $k) {
                    $match[$k] = $data[$k] ?? null;
                }
                $dst->table($table)->updateOrInsert($match, $data);
            }
        }

        $dstCount = $dry ? 0 : $dst->table($table)->count();
        $mark = $dry ? '·' : ($dstCount >= $srcCount ? '✓' : '✗');
        $this->line(sprintf('  %s %-20s مبدأ: %-5d مقصد: %s', $mark, $table, $srcCount, $dry ? '—' : $dstCount));

        return $dry || $dstCount >= $srcCount;
    }
}
