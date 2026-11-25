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
 * روی تغییرِ وضعیت یا یادآوریِ محدود خبر می‌دهد، نه هر ۱۵ دقیقه. هشدارِ
 * موقت نباید اسپم کند؛ هشدارِ پایدار یا معوق هم نباید برای همیشه فراموش شود.
 */
class SystemHealthCheck extends Command
{
    protected $signature = 'system:health {--notify : اعلان به مدیر حتی اگر وضعیت عوض نشده باشد}';

    protected $description = 'بررسیِ سلامتِ کرون، دیتابیس و صف‌های تحویل — با اعلان به مدیر';

    /** آخرین وضعیتِ اعلام‌شده. ⚠️ فایل، نه کش: کش خودش ممکن است بیمار باشد. */
    private const STATE = 'health-state';

    /** تغییرِ گذرای هشدارهای زرد نباید هر ربع ساعت یک خلاصهٔ تازه بسازد. */
    private const WARN_QUIET_SECONDS = 6 * 3600;
    private const FAIL_REMINDER_SECONDS = 6 * 3600;
    private const WARN_REMINDER_SECONDS = 24 * 3600;
    private const FAIL_IMPACT_QUIET_SECONDS = 3600;

    public function handle(SystemHealth $health, AdminNotifier $notifier): int
    {
        $checks = $health->checks();
        // Keep every check in console/dashboard; avoid a second owner for fresh GPU incidents.
        $salesOwnsGpu = ! $this->option('notify') && $this->salesMonitorOwnsGpu();
        $allBad = array_values(array_filter($checks, fn ($c) => ! $c['ok']));
        $bad = array_values(array_filter($allBad, fn ($c) => ! $salesOwnsGpu || ($c['key'] ?? '') !== 'gpu_sales'));
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
        // Ownership transfer is not a system recovery notification.
        if ($salesOwnsGpu) { unset($previous['gpu_sales']); }
        $now = now()->timestamp;
        // Track observations and notification attempts separately. A debounced
        // warning has been observed, but has not yet been reported to the owner.
        $attempted = array_intersect_key($state['attempted_at'] ?? [], $current, $previous);
        $signatures = [];
        foreach ($bad as $check) {
            $signature = $this->validImpactSignature($check['impact_signature'] ?? null);
            if ($signature !== null) { $signatures[(string) $check['key']] = $signature; }
        }
        $attemptedImpact = array_intersect_key($state['attempted_impact'] ?? [], $attempted, $signatures);
        // Older receipts already reported these incidents, but had no explicit
        // impact digest. Learn the current digest without a deployment alert.
        $attemptedImpact += array_intersect_key($signatures, $attempted);
        $seenSince = [];
        foreach ($current as $key => $level) {
            $seenSince[$key] = isset($previous[$key]) ? ($state['observed_since'][$key] ?? $now) : $now;
        }
        $toAnnounce = [];
        // Alert ownership does not change the overall health result.
        $announceRecovery = $current === [] && $previous !== [] && $allBad === [];

        if ($this->option('notify')) {
            $toAnnounce = $bad;
            $announceRecovery = $current === [];
        } elseif ($state === null) {
            // نصبِ سالم بی‌دلیل پیام نمی‌دهد؛ اولین خرابیِ واقعی چرا.
            $toAnnounce = $bad;
        } elseif (! $announceRecovery) {
            $due = [];
            $unannouncedWarnings = [];
            foreach ($bad as $check) {
                $key = (string) $check['key'];
                $level = (string) $check['level'];
                $lastAttempt = $attempted[$key] ?? null;
                $impactChanged = isset($signatures[$key], $attemptedImpact[$key])
                    && ! hash_equals($attemptedImpact[$key], $signatures[$key]);
                if ($level === 'fail') {
                    if (($previous[$key] ?? null) !== 'fail' || $lastAttempt === null
                        || $now - $lastAttempt >= self::FAIL_REMINDER_SECONDS
                        || ($impactChanged && $now - $lastAttempt >= self::FAIL_IMPACT_QUIET_SECONDS)) {
                        $due[$key] = $check;
                    }
                } elseif ($lastAttempt === null) {
                    $unannouncedWarnings[$key] = $check;
                    // Bounded deferral: unrelated ongoing alerts must not keep
                    // resetting the global quiet window for a persistent warn.
                    if ($this->warningWindowPassed($state, $now)
                        || $now - $seenSince[$key] >= self::WARN_QUIET_SECONDS) {
                        $due[$key] = $check;
                    }
                } elseif ($now - $lastAttempt >= self::WARN_REMINDER_SECONDS
                    || ($impactChanged && $now - $lastAttempt >= self::WARN_QUIET_SECONDS)) {
                    $due[$key] = $check;
                }
            }
            // If a red alert is already due, include pending warnings in the
            // same summary without creating an extra message.
            if (collect($due)->contains(fn ($c) => $c['level'] === 'fail')) {
                $due += $unannouncedWarnings;
            }
            $toAnnounce = array_values($due);
        }

        $announced = false;
        if ($announceRecovery) {
            $this->announce($notifier, 'ok', []);
            $announced = true;
        } elseif ($toAnnounce !== []) {
            $this->announce($notifier, SystemHealth::worst($toAnnounce), $toAnnounce);
            $announced = true;
            foreach ($toAnnounce as $check) {
                $key = (string) $check['key'];
                $attempted[$key] = $now;
                if (isset($signatures[$key])) { $attemptedImpact[$key] = $signatures[$key]; }
            }
        }

        $this->remember($current, $seenSince, $attempted, $attemptedImpact,
            $announced ? now()->toIso8601String() : ($state['last_announced_at'] ?? null));

        // ⚠️ کدِ خروجِ غیرِ صفر عمداً **نه**: `schedule:run` آن را شکستِ فرمان
        // می‌شمارد و در لاگِ کرون سروصدا می‌کند، در حالی که این فرمان کارش را
        // درست انجام داده — فقط خبرِ بد آورده.
        return self::SUCCESS;
    }

    /** The dedicated monitor owns alerts only while its actual provider probe is fresh. */
    private function salesMonitorOwnsGpu(): bool
    {
        try {
            $path = storage_path('app/cloud-sales-watch.json');
            if (! File::exists($path)) { return false; }
            $state = json_decode((string) File::get($path), true);
            if (! is_array($state) || (int) ($state['version'] ?? 0) < 2
                || ! is_string($state['checked_at'] ?? null) || trim($state['checked_at']) === ''
                || ! is_array($state['providers']['salad'] ?? null)) { return false; }
            if (($state['providers']['catalogue']['ok'] ?? true) === false) { return false; }
            $observed = (int) ($state['providers']['salad']['observed_at'] ?? 0);
            $checked = Carbon::parse((string) ($state['checked_at'] ?? ''))->timestamp;
            $at = now()->timestamp;
            return $observed > 0 && $at - $observed >= 0 && $at - $observed <= 180
                && $at - $checked >= 0 && $at - $checked <= 180;
        } catch (\Throwable) {
            return false;
        }
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
        } catch (\Throwable) {
            // نگهبان نباید خودش منبعِ خطا شود
            $this->warn('اعلان سلامت با خطا روبرو شد.');
        }
    }

    /** @return array{bad:array<string,string>,observed_since:array<string,int>,attempted_at:array<string,int>,attempted_impact:array<string,string>,last_announced_at:?string}|null */
    private function lastState(array $current): ?array
    {
        $path = storage_path('app/'.self::STATE);

        if (! File::exists($path)) {
            return null;
        }

        try { $raw = trim((string) File::get($path)); }
        catch (\Throwable) { return null; }
        $json = json_decode($raw, true);
        $now = now()->timestamp;

        if (is_array($json) && is_array($json['bad'] ?? null)) {
            $bad = array_filter($json['bad'], fn ($level) => in_array($level, ['warn', 'fail'], true));
            $lastAnnouncedAt = is_string($json['last_announced_at'] ?? null) ? $json['last_announced_at'] : null;
            $seen = [];
            $attempted = [];
            $impact = [];
            foreach ($bad as $key => $level) {
                if (($json['version'] ?? null) === 3) {
                    $seen[$key] = $this->validTimestamp($json['observed_since'][$key] ?? null, $now) ?? $now;
                    $at = $this->validTimestamp($json['attempted_at'][$key] ?? null, $now);
                    if ($at !== null) {
                        $attempted[$key] = $at;
                        $digest = $this->validImpactSignature($json['attempted_impact'][$key] ?? null);
                        if ($digest !== null) { $impact[$key] = $digest; }
                    }
                } else {
                    // v2 did not distinguish observed from attempted. Carry
                    // forward its last summary conservatively; unresolved
                    // incidents will receive their regular bounded reminder.
                    $seen[$key] = $attempted[$key] = $this->parsedTimestamp($lastAnnouncedAt, $now) ?? $now;
                }
            }
            return [
                'bad' => $bad,
                'observed_since' => $seen,
                'attempted_at' => $attempted,
                'attempted_impact' => $impact,
                'last_announced_at' => $lastAnnouncedAt,
            ];
        }

        // مهاجرتِ بی‌صدا از فرمتِ قدیمی `fail|db,errors,...`: همان وضعیت قبلاً
        // اعلام شده، پس deploy نباید خودش یک موجِ تازهٔ بله بسازد.
        if (! preg_match('/^(?:ok|warn|fail)\|([a-zA-Z0-9_,.-]*)$/D', $raw, $matches)) { return null; }
        $bad = [];
        foreach (array_filter(explode(',', $matches[1])) as $key) {
            $bad[$key] = $current[$key] ?? 'fail';
        }
        $at = array_fill_keys(array_keys($bad), $now);

        return ['bad' => $bad, 'observed_since' => $at, 'attempted_at' => $at,
            'attempted_impact' => [], 'last_announced_at' => now()->toIso8601String()];
    }

    /** @param array<string,string> $bad */
    private function remember(array $bad, array $seenSince, array $attempted, array $attemptedImpact, ?string $lastAnnouncedAt): void
    {
        try {
            File::replace(storage_path('app/'.self::STATE), json_encode([
                'version' => 3,
                'bad' => $bad,
                'observed_since' => $seenSince,
                'attempted_at' => $attempted,
                'attempted_impact' => $attemptedImpact,
                'last_announced_at' => $lastAnnouncedAt,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // بی‌حافظه یعنی هشدارِ تکراری — آزاردهنده، ولی بی‌خطر
        }
    }

    /** @param array{last_announced_at:?string} $state */
    private function warningWindowPassed(array $state, int $now): bool
    {
        $at = $this->parsedTimestamp($state['last_announced_at'] ?? null, $now);
        return $at === null || $now - $at >= self::WARN_QUIET_SECONDS;
    }

    private function validTimestamp(mixed $at, int $now): ?int
    {
        return is_int($at) && $at > 0 && $at <= $now ? $at : null;
    }

    private function validImpactSignature(mixed $signature): ?string
    {
        return is_string($signature) && preg_match('/^[a-f0-9]{64}$/D', $signature) ? $signature : null;
    }

    private function parsedTimestamp(?string $at, int $now): ?int
    {
        try { return $at === null || trim($at) === '' ? null : $this->validTimestamp(Carbon::parse($at)->timestamp, $now); }
        catch (\Throwable) { return null; }
    }
}
