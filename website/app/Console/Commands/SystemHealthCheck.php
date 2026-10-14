<?php

namespace App\Console\Commands;

use App\Services\Notify\AdminNotifier;
use App\Services\SystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

    /** تغییرِ گذرای هشدارهای زرد نباید هر ربع ساعت یک خلاصهٔ تازه بسازد. */
    private const WARN_REPEAT_SECONDS = 6 * 3600;

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

        $current = collect($bad)->mapWithKeys(fn ($c) => [(string) $c['key'] => (string) $c['level']])->all();
        $state = $this->lastState($current);
        $previous = (array) ($state['bad'] ?? []);
        $toAnnounce = [];
        $announceRecovery = $current === [] && $previous !== [];

        if ($this->option('notify')) {
            $toAnnounce = $bad;
            $announceRecovery = $current === [];
        } elseif ($state === null) {
            // نصبِ سالم بی‌دلیل پیام نمی‌دهد؛ اولین خرابیِ واقعی چرا.
            $toAnnounce = $bad;
        } elseif (! $announceRecovery) {
            $candidates = array_values(array_filter($bad, function ($check) use ($previous) {
                $key = (string) $check['key'];

                return ! isset($previous[$key])
                    || $this->severity((string) $check['level']) > $this->severity((string) $previous[$key]);
            }));

            // خرابیِ قرمزِ تازه فوراً می‌رود. تغییرِ زردِ گذرا فقط اگر از آخرین
            // خلاصه شش ساعت گذشته باشد؛ بهبودِ جزئی اصلاً پیام جدا نمی‌سازد.
            $hasFreshFailure = collect($candidates)->contains(fn ($c) => $c['level'] === 'fail');
            if ($hasFreshFailure || ($candidates !== [] && $this->warningWindowPassed($state))) {
                $toAnnounce = $candidates;
            }
        }

        $announced = false;
        if ($announceRecovery) {
            $this->announce($notifier, 'ok', []);
            $announced = true;
        } elseif ($toAnnounce !== []) {
            $this->announce($notifier, SystemHealth::worst($toAnnounce), $toAnnounce);
            $announced = true;
        }

        $this->remember($current, $announced ? now()->toIso8601String() : ($state['last_announced_at'] ?? null));

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

    /** @return array{bad:array<string,string>,last_announced_at:?string}|null */
    private function lastState(array $current): ?array
    {
        $path = storage_path('app/'.self::STATE);

        if (! File::exists($path)) {
            return null;
        }

        $raw = trim((string) File::get($path));
        $json = json_decode($raw, true);

        if (is_array($json) && is_array($json['bad'] ?? null)) {
            return [
                'bad' => array_map('strval', $json['bad']),
                'last_announced_at' => filled($json['last_announced_at'] ?? null)
                    ? (string) $json['last_announced_at'] : null,
            ];
        }

        // مهاجرتِ بی‌صدا از فرمتِ قدیمی `fail|db,errors,...`: همان وضعیت قبلاً
        // اعلام شده، پس deploy نباید خودش یک موجِ تازهٔ بله بسازد.
        [, $keys] = array_pad(explode('|', $raw, 2), 2, '');
        $bad = [];
        foreach (array_filter(explode(',', $keys)) as $key) {
            $bad[$key] = $current[$key] ?? 'fail';
        }

        return ['bad' => $bad, 'last_announced_at' => now()->toIso8601String()];
    }

    /** @param array<string,string> $bad */
    private function remember(array $bad, ?string $lastAnnouncedAt): void
    {
        try {
            File::put(storage_path('app/'.self::STATE), json_encode([
                'version' => 2,
                'bad' => $bad,
                'last_announced_at' => $lastAnnouncedAt,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // بی‌حافظه یعنی هشدارِ تکراری — آزاردهنده، ولی بی‌خطر
        }
    }

    /** @param array{last_announced_at:?string} $state */
    private function warningWindowPassed(array $state): bool
    {
        try {
            $at = filled($state['last_announced_at'] ?? null)
                ? Carbon::parse((string) $state['last_announced_at']) : null;

            return $at === null || $at->lte(now()->subSeconds(self::WARN_REPEAT_SECONDS));
        } catch (\Throwable) {
            return true;
        }
    }

    private function severity(string $level): int
    {
        return match ($level) {
            'fail' => 2,
            'warn' => 1,
            default => 0,
        };
    }
}
