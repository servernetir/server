<?php

namespace App\Console\Commands;

use App\Services\Cloud\CloudCatalogSync;
use Illuminate\Console\Command;

/**
 * `cloud:sync` — کاتالوگِ سرورِ ابری را از ارائه‌دهنده‌ها تازه می‌کند.
 *
 * کارفرما: «دو روز یک‌بار قیمت‌ها آپدیت شود». دو حالت دارد:
 *
 *   cloud:sync            → تماس با API، مشخصات + بهایِ تمام‌شده + قیمتِ فروش
 *   cloud:sync --prices   → فقط بازمحاسبهٔ قیمت با نرخِ روزِ یورو (بی‌تماسِ API)
 *
 * حالتِ دوم ارزان است و می‌تواند روزانه بدود؛ حالتِ اول دو روز یک‌بار.
 */
class SyncCloudCatalog extends Command
{
    protected $signature = 'cloud:sync
                            {--provider= : فقط یک ارائه‌دهنده (hetzner|aeza)}
                            {--prices : فقط بازمحاسبهٔ قیمت، بدونِ تماس با API}';

    protected $description = 'همگام‌سازیِ کاتالوگِ سرورِ ابری (پلن، مکان، سیستم‌عامل) و قیمت‌گذاری';

    public function handle(CloudCatalogSync $sync): int
    {
        if ($this->option('prices')) {
            $n = $sync->reprice();
            $this->info("قیمتِ {$n} پلن بازمحاسبه شد.");

            return self::SUCCESS;
        }

        $report = $sync->sync($this->option('provider') ?: null);

        if (isset($report['message'])) {
            $this->warn($report['message']);

            return self::SUCCESS;
        }

        if (($report['providers'] ?? []) === []) {
            $this->warn('هیچ ارائه‌دهندهٔ ابری تنظیم نشده — توکن را در /admin/settings وارد کنید.');

            return self::SUCCESS;
        }

        /*
        | ⚠️ همهٔ خواندن‌های زیر محافظ دارند و این عمدی است.
        |
        | این فرمان دو بار با `TypeError` روی همین چند خط مرده — یعنی
        | `cloud:sync` با کدِ ۱ تمام می‌شد و کاتالوگ و قیمتِ سرورها **تازه
        | نمی‌شد**. یک گزارشِ ناقص (کلیدِ نبود، مقدارِ غیرِ عددی) نباید کارِ
        | انجام‌شده را دور بریزد؛ خودِ همگام‌سازی پیش از این خط تمام شده است.
        */
        $rate = $report['rate'] ?? 0;
        $rate = is_numeric($rate) ? (int) $rate : 0;

        $this->line($rate > 0 ? 'نرخِ یورو: '.number_format($rate).' تومان' : 'نرخِ یورو در دسترس نیست — قیمتِ تومانی ساخته نشد.');

        foreach ((array) ($report['providers'] ?? []) as $slug => $r) {
            $r = (array) $r;

            if ($r['ok'] ?? false) {
                $this->info(sprintf(
                    '%s: %d مکان، %d پلن، %d ایمیج.',
                    $slug, (int) ($r['locations'] ?? 0), (int) ($r['plans'] ?? 0), (int) ($r['images'] ?? 0)
                ));
            } else {
                $this->error($slug.': '.($r['message'] ?? 'بی‌پیام'));
            }
        }

        return self::SUCCESS;
    }
}
