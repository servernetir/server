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

        /*
        | 🔴 **یورو هم** تازه می‌شود، نه فقط دلار.
        |
        | `refresh()` بی‌آرگومان یعنی `USD`. پس این کرونِ ساعتی هرگز EUR را
        | تازه نمی‌کرد، و کشِ یورو فقط ۶ ساعت عمر دارد. زنجیرهٔ خرابی:
        |
        |   کشِ EUR منقضی → `CloudPricing::eurToToman()` صفر می‌دهد
        |     → `CloudCatalogSync::reprice()` روی **همهٔ** پلن‌ها `price_irt=0`
        |     → `scopeSellable` شرطِ `price_irt > 0` دارد
        |     → **همهٔ سرورهای مجازی از فروشگاه و صفحاتِ کشور غیب می‌شوند**
        |
        | با کدِ ۲۰۰، بی‌استثنا، تا اجرای موفقِ بعدی. قیمتِ دامنه هم روی همین
        | نرخ سوار است.
        */
        $ok = [];
        $bad = [];

        foreach (['USD', 'EUR'] as $cur) {
            $r = $fx->refresh($cur);

            if ($r === null) {
                $bad[] = $cur;

                continue;
            }

            $ok[] = $cur.': '.number_format($r['rate_toman']).' تومان';
        }

        if ($bad !== []) {
            // ⚠️ ردیاب، نه فقط لاگ: لاگِ لاراول روی cPanel عملاً خوانده نمی‌شود،
            //    و این خرابی مستقیم به «کاتالوگِ خالی» می‌رسد.
            \App\Support\ErrorTracker::note('pricing', 'نرخِ ارز تازه نشد: '.implode('، ', $bad));
            $this->error('ناموفق: '.implode('، ', $bad).' — مقدارِ قبلی حفظ شد.');
        }

        if ($ok !== []) {
            $this->info('نرخ به‌روز شد — '.implode(' · ', $ok));
        }

        /*
        | شکستِ **کامل** ⇒ FAILURE · شکستِ جزئی ⇒ SUCCESS.
        |
        | ⚠️ این تفکیک عمدی است. اگر یکی از دو ارز گرفته شود، کار عملاً انجام
        | شده و کدِ غیرِ صفر فقط لاگِ کرون را پر می‌کند. ولی وقتی **هیچ‌کدام**
        | نیامد، سکوت خطرناک است: قیمتِ کلِ کاتالوگ روی همین نرخ سوار است.
        */
        return $ok === [] ? self::FAILURE : self::SUCCESS;
    }
}
