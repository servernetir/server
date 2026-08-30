<?php

namespace App\Console\Commands;

use App\Services\Notify\AdminNotifier;
use App\Services\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * نگهبانِ سلامت — هر ۱۵ دقیقه می‌پرسد «خودکارسازی واقعاً کار می‌کند؟»
 *
 * ═══ چرا این فرمان لازم شد ═══
 *
 * الگویِ تکرارشوندهٔ این پروژه «شکست نمی‌خورد، فقط اتفاق نمی‌افتد» است:
 * `domains:provision` که هرگز زمان‌بندی نشده بود، `provision:run` که سرویسِ
 * ابری را بی‌صدا رد می‌کرد، و کرونی که با یک قطعیِ گذرایِ MariaDB می‌مُرد.
 * هیچ‌کدام خطا تولید نکردند. همه با کدِ ۲۰۰ و لاگِ خالی گذشتند.
 *
 * چیزی که این کلاس از باگ‌ها را نمی‌گیرد، ولی **نتیجه**شان را می‌گیرد: پولی که
 * گرفته شده و سرویسی که تحویل نشده، صفی که پیش نمی‌رود، کرونی که ایستاده.
 *
 * ═══ ضدِ اسپم ═══
 *
 * فقط روی **تغییرِ وضعیت** خبر می‌دهد، نه هر ۱۵ دقیقه. وگرنه مدیر روزی ۹۶
 * پیام می‌گرفت و از روزِ دوم همه را نادیده می‌گرفت — که بدتر از نداشتنِ هشدار
 * است، چون توهمِ پایش می‌سازد.
 */
class SystemHealthCheck extends Command
{
    protected $signature = 'system:health {--notify : اعلان به مدیر حتی اگر وضعیت عوض نشده باشد}';

    protected $description = 'بررسیِ سلامتِ کرون، دیتابیس و صف‌های تحویل — با اعلان به مدیر';

    /** آخرین وضعیتِ اعلام‌شده. ⚠️ فایل، نه کش: کش خودش ممکن است بیمار باشد. */
    private const STATE = 'health-state';

    public function handle(SystemHealth $health, AdminNotifier $notifier): int
    {
        $checks = $health->checks();
        $bad = array_values(array_filter($checks, fn ($c) => ! $c['ok']));
        $worst = SystemHealth::worst($checks);

        foreach ($checks as $c) {
            $this->line(match ($c['level']) {
                'fail' => '<fg=red>✖</> ',
                'warn' => '<fg=yellow>▲</> ',
                default => '<fg=green>✔</> ',
            }.$c['title'].' — '.$c['detail']);
        }

        // امضا شاملِ **کدام** چک‌ها خرابند، نه فقط شدت. وگرنه وقتی مشکلِ کرون
        // برطرف شود و هم‌زمان صفِ دامنه گیر کند، هر دو `fail`اند و هیچ خبری
        // نمی‌رود — یعنی دقیقاً همان کوریِ که این ابزار برایش ساخته شده.
        $signature = $worst.'|'.implode(',', array_column($bad, 'key'));
        $changed = $this->lastSignature() !== $signature;

        if ($changed || $this->option('notify')) {
            $this->announce($notifier, $worst, $bad);
        }

        $this->remember($signature);

        // ⚠️ کدِ خروجِ غیرِ صفر عمداً **نه**: `schedule:run` آن را شکستِ فرمان
        // می‌شمارد و در لاگِ کرون سروصدا می‌کند، در حالی که این فرمان کارش را
        // درست انجام داده — فقط خبرِ بد آورده.
        return self::SUCCESS;
    }

    /** @param array<int,array<string,mixed>> $bad */
    private function announce(AdminNotifier $notifier, string $worst, array $bad): void
    {
        try {
            if ($worst === 'ok') {
                $notifier->event('سلامتِ سامانه به حالتِ عادی برگشت',
                    ['وضعیت' => 'همهٔ چک‌ها سبز'], url('/admin/errors'), '✅');

                return;
            }

            $rows = [];
            foreach ($bad as $c) {
                $rows[$c['title']] = $c['detail'];
            }

            $notifier->event(
                $worst === 'fail' ? '🔴 خودکارسازی مشکل دارد' : '⚠️ هشدارِ سلامتِ سامانه',
                $rows,
                url('/admin/errors'),
                $worst === 'fail' ? '🔴' : '⚠️',
            );
        } catch (\Throwable $e) {
            // نگهبان نباید خودش منبعِ خطا شود
            $this->warn('اعلان نرفت: '.mb_substr($e->getMessage(), 0, 120));
        }
    }

    private function lastSignature(): ?string
    {
        $path = storage_path('app/'.self::STATE);

        return File::exists($path) ? trim(File::get($path)) : null;
    }

    private function remember(string $signature): void
    {
        try {
            File::put(storage_path('app/'.self::STATE), $signature);
        } catch (\Throwable) {
            // بی‌حافظه یعنی هشدارِ تکراری — آزاردهنده، ولی بی‌خطر
        }
    }
}
