<?php

namespace App\Console\Commands;

use App\Http\Controllers\DomainCheckController;
use App\Services\Domain\TldPriceBook;
use Illuminate\Console\Command;

/**
 * گرم نگه‌داشتنِ دفترچهٔ قیمتِ پسوندها.
 *
 * ═══ چرا کرون، وقتی خودِ کش تنبل است ═══
 *
 * `TldPriceBook` با `Cache::remember` کار می‌کند، پس **اولین** درخواستِ بعد از
 * انقضای کش هزینهٔ استعلام را می‌دهد. آن یک نفر معمولاً یک بازدیدکنندهٔ واقعی
 * است که در جعبهٔ صفحهٔ اول دنبالِ دامنه می‌گردد — یعنی بدترین لحظهٔ ممکن برای
 * چند ثانیه انتظار.
 *
 * ⚠️ فاصله عمداً از TTL **کمتر** است (۳ ساعت در برابرِ ۶). با فاصلهٔ برابر،
 * هر بار مسابقه‌ای بین انقضا و کرون بود و گاهی بازدیدکننده برنده می‌شد.
 *
 * ⚠️ این فرمان خودش هیچ کشی را باطل نمی‌کند: فقط `forTlds()` را صدا می‌زند.
 * اگر کش هنوز زنده باشد، همان را برمی‌گرداند و **هیچ** تماسی با رجیسترار
 * نمی‌شود. پس اجرای مکررش بی‌خطر است.
 */
class RefreshTldPriceBook extends Command
{
    protected $signature = 'domains:price-book';

    protected $description = 'تازه‌سازیِ قیمتِ پایهٔ پسوندهای دامنه (کشِ ۶ ساعته)';

    public function handle(TldPriceBook $book): int
    {
        // ⚠️ از **همان** فهرستی که جعبهٔ صفحهٔ اول مصرف می‌کند. فهرستِ موازی
        //    یعنی روزی یکی کهنه شود و آن پسوند بی‌قیمت بمانَد.
        $tlds = DomainCheckController::SUGGEST;

        try {
            $prices = $book->forTlds($tlds);
        } catch (\Throwable $e) {
            $this->warn('استعلام نشد: '.mb_substr($e->getMessage(), 0, 140));

            // ⚠️ کدِ خروجِ صفر: `schedule:run` غیرِ صفر را شکستِ فرمان می‌شمارد و
            //    در لاگِ کرون سروصدا می‌کند، در حالی که خوابیدنِ موقتِ رجیسترار
            //    خرابیِ ما نیست — دفعهٔ بعد دوباره تلاش می‌شود.
            return self::SUCCESS;
        }

        $missing = array_values(array_diff($tlds, array_keys($prices)));

        $this->info(sprintf('%d از %d پسوند قیمت دارد.', count($prices), count($tlds)));

        if ($missing !== []) {
            // بی‌صدا ردنشدن: پسوندِ بی‌قیمت یعنی پیشنهادی که روی صفحهٔ اول
            // بدونِ عدد نشان داده می‌شود.
            $this->warn('بی‌قیمت: '.implode('، ', $missing));
        }

        return self::SUCCESS;
    }
}
