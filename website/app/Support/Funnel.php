<?php

namespace App\Support;

/**
 * رویدادنگاریِ قیف — ممیزی ۶ (مدیر رشد): «تا این رویدادها وجود نداشته باشند،
 * هر بحثِ نرخِ تبدیل حدس است.» شش رویداد، همه در یک JSONLِ ماهانه:
 *
 *   product_page_view · order_summary_view · cycle_selected · checkout_click
 *   (سمتِ سایت) · handoff_landed · order_placed (پشتِ مرزِ console)
 *
 * 🔴 فایل، نه دیتابیس: شمارنده حق ندارد صفحهٔ فروش را به یک قطعیِ گذرای DB
 * گره بزند. و هرگز پرتاب نمی‌کند — بدترین حالت، یک ردیفِ گم‌شده است.
 *
 * ⚠️ صفحاتِ کش‌شده (HIT) به PHP نمی‌رسند، پس رویدادهای صفحه‌ای از مرورگر
 * با `navigator.sendBeacon` به `/api/funnel` می‌آیند (FunnelController)، نه از
 * کنترلرِ صفحه. رویدادهای مرزِ console سمتِ سرور ثبت می‌شوند چون آن صفحات
 * هرگز کش نمی‌شوند.
 *
 * تحلیل: `php artisan funnel:report` یا یک grep روی storage/app/funnel/.
 */
class Funnel
{
    public const EVENTS = [
        'product_page_view', 'order_summary_view', 'cycle_selected',
        'checkout_click', 'handoff_landed', 'handoff_invalid', 'order_placed',
    ];

    /** @param array<string,scalar|null> $attrs */
    public static function log(string $event, array $attrs = []): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            return;
        }

        try {
            // در تست، فایلِ واقعیِ ماهِ جاری کثیف نشود (شورا/QA)
            $dir = storage_path(app()->runningUnitTests() ? 'framework/testing/funnel' : 'app/funnel');

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $row = ['t' => date('c'), 'e' => $event] + array_map(
                fn ($v) => is_scalar($v) || $v === null ? (is_string($v) ? mb_substr($v, 0, 120) : $v) : null,
                $attrs
            );

            @file_put_contents(
                $dir.'/events-'.date('Y-m').'.jsonl',
                json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // شمارنده هرگز نباید صفحه‌ای را بیندازد
        }
    }

    /** دسته‌بندیِ Referer به «از کجا آمد» — بدونِ ذخیرهٔ URLِ کامل. */
    public static function refBucket(?string $referer): string
    {
        $ref = (string) $referer;

        if ($ref === '') {
            return 'direct';
        }

        $host = (string) parse_url($ref, PHP_URL_HOST);
        $path = (string) parse_url($ref, PHP_URL_PATH);
        $ours = $host === (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return match (true) {
            $ours && str_contains($path, '/blog') => 'blog',
            $ours && str_contains($path, '/order') => 'order',
            $ours                                  => 'site',
            default                                => 'external',
        };
    }
}
