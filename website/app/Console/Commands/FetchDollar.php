<?php

namespace App\Console\Commands;

use App\Services\ExchangeRate;
use Illuminate\Console\Command;

/**
 * دریافت ساعتی نرخ دلار آزاد و ذخیره در کش.
 * پنل مدیریت فقط از کش می‌خواند؛ این کامند تنها نویسندهٔ آن است.
 */
class FetchDollar extends Command
{
    protected $signature = 'fx:dollar {--show : فقط نمایش مقدار فعلی بدون دریافت}';
    protected $description = 'دریافت نرخ دلار از alanchand.com و ذخیره برای قیمت‌گذاری';

    public function handle(ExchangeRate $fx): int
    {
        if ($this->option('show')) {
            $c = $fx->current();
            $this->info($c ? number_format($c['usd_irt']).' تومان — '.$c['at'] : 'هنوز نرخی ذخیره نشده');
            return self::SUCCESS;
        }

        $r = $fx->refresh();
        if ($r === null) {
            $this->error('دریافت یا استخراج ناموفق بود؛ مقدار قبلی حفظ شد.');
            return self::FAILURE;
        }

        $this->info('نرخ دلار به‌روز شد: '.number_format($r['usd_irt']).' تومان');
        return self::SUCCESS;
    }
}
