<?php

namespace App\Console\Commands;

use App\Services\ExchangeRate;
use Illuminate\Console\Command;

/**
 * دریافت ساعتی نرخ دلار آزاد و ذخیره در کش.
 * پنل مدیریت فقط از کش می‌خواند؛ این کامند تنها نویسندهٔ آن است.
 *
 * 🔴 باگی که این‌جا بود و **هر ساعت** روی سرورِ زنده تکرار می‌شد:
 * `ExchangeRate` کلیدِ `rate_toman` برمی‌گرداند، ولی این فرمان `usd_irt`
 * می‌خواند — کلیدی که وجود ندارد. نتیجه `Undefined array key` و خروج با کدِ ۱،
 * پس زمان‌بندِ لاراول هر ساعت یک خطای ۵۰۰ ثبت می‌کرد.
 *
 * ⚠️ نکتهٔ ظریف: نرخ **درست ذخیره می‌شد**. `Cache::put` پیش از `return` است و
 * انفجار فقط در خطِ پیامِ موفقیت رخ می‌داد. یعنی قیمت‌گذاری سالم بود و تنها
 * نشانه‌اش سیلِ خطای ساعتی بود — دقیقاً همان چیزی که وقتی ۴۰۴ها لاگ را پر
 * می‌کنند دیده نمی‌شود.
 */
class FetchDollar extends Command
{
    protected $signature = 'fx:dollar {--show : فقط نمایش مقدار فعلی بدون دریافت}';
    protected $description = 'دریافت نرخ دلار از alanchand.com و ذخیره برای قیمت‌گذاری';

    public function handle(ExchangeRate $fx): int
    {
        if ($this->option('show')) {
            $c = $fx->current();
            $this->info($c ? number_format($c['rate_toman']).' تومان — '.$c['at'] : 'هنوز نرخی ذخیره نشده');
            return self::SUCCESS;
        }

        $r = $fx->refresh();
        if ($r === null) {
            $this->error('دریافت یا استخراج ناموفق بود؛ مقدار قبلی حفظ شد.');
            return self::FAILURE;
        }

        $this->info('نرخ دلار به‌روز شد: '.number_format($r['rate_toman']).' تومان');
        return self::SUCCESS;
    }
}
