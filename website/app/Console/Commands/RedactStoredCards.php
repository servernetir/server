<?php

namespace App\Console\Commands;

use App\Support\CardRedactor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * پاک‌سازیِ شمارهٔ کاملِ کارت از متن‌هایی که **از قبل** ذخیره شده‌اند.
 *
 * محافظِ `TicketMessage::body()` از امروز به بعد را می‌گیرد؛ این فرمان دیروز
 * را. بی‌آن، همان یک ردیفی که کلِ این کار را لازم کرد سرِ جایش می‌مانَد.
 *
 * ⚠️ پیش‌فرض **خشک** است. این کار برگشت‌ناپذیر است و روی دیتابیسِ زنده اجرا
 * می‌شود؛ کسی که فقط اسمِ فرمان را می‌زند نباید ناخواسته چیزی بازنویسی کند.
 * نوشتن با `--force`.
 */
class RedactStoredCards extends Command
{
    protected $signature = 'security:redact-cards
                            {--force : واقعاً بنویس (بی این پرچم فقط گزارش می‌دهد)}';

    protected $description = 'شمارهٔ کاملِ کارت را در متنِ تیکت‌های ذخیره‌شده می‌پوشاند';

    /**
     * جدول → ستون‌هایی که **مشتری** متنشان را می‌نویسد.
     *
     * عمداً محدود است. گشتنِ کورِ همهٔ ستون‌های متنی یعنی روزی یک ستونِ فنی
     * (لاگ، پاسخِ خامِ API، یادداشتِ داخلی) هم بازنویسی شود.
     */
    private const TARGETS = [
        'ticket_messages' => ['body'],
        'tickets'         => ['subject'],
    ];

    public function handle(): int
    {
        $write = (bool) $this->option('force');
        $total = 0;

        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $this->warn("جدولِ {$table} روی این نصب نیست — رد شد.");

                continue;
            }

            foreach ($columns as $column) {
                $total += $this->sweep($table, $column, $write);
            }
        }

        $this->newLine();

        if ($total === 0) {
            $this->info('هیچ شمارهٔ کارتی در متن‌های ذخیره‌شده پیدا نشد.');

            return self::SUCCESS;
        }

        $write
            ? $this->info("✅ {$total} ردیف پوشانده شد.")
            : $this->warn("⚠️  {$total} ردیف شمارهٔ کارت دارد. برای نوشتن: --force");

        return self::SUCCESS;
    }

    /**
     * ⚠️ با کوئریِ مستقیم می‌نویسد، نه `$model->save()`.
     *
     * مدل `updated_at` را جلو می‌بَرد و رویداد می‌اندازد — یعنی یک پاک‌سازیِ
     * امنیتی می‌توانست ترتیبِ نمایشِ گفتگو را به‌هم بزند یا اعلانِ تازه بفرستد.
     * تنها چیزی که باید عوض شود، همان یک ستون است.
     */
    private function sweep(string $table, string $column, bool $write): int
    {
        $hit = 0;

        DB::table($table)->select('id', $column)->orderBy('id')->chunk(500,
            function ($rows) use ($table, $column, $write, &$hit) {
                foreach ($rows as $row) {
                    $before = (string) ($row->{$column} ?? '');
                    $after  = (string) CardRedactor::mask($before);

                    if ($after === $before) {
                        continue;
                    }

                    $hit++;
                    $this->line("  {$table}#{$row->id} → ".$this->preview($after));

                    if ($write) {
                        DB::table($table)->where('id', $row->id)->update([$column => $after]);
                    }
                }
            });

        return $hit;
    }

    /** فقط تکهٔ ماسک‌شده را نشان بده — نه کلِ متنِ خصوصیِ مشتری در ترمینال */
    private function preview(string $masked): string
    {
        preg_match_all('/\d{6}\*{6}\d{4}/', $masked, $m);

        return implode('، ', $m[0] ?: ['—']);
    }
}
