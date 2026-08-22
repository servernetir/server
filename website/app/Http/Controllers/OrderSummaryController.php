<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Service;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * خلاصهٔ سفارشِ **پیش از ورود** — قلمِ «اگر فقط یک کار در ۳۰ روز»ِ ممیزی ۴.
 *
 * ═══ چرا این صفحه وجود دارد ═══
 *
 * چهار دور ممیزی یک چیز را تکرار کرد: کاربر برای دیدنِ قیمتِ نهایی مجبور به
 * ورود بود («تعهد را می‌گیریم، اطلاعات را نمی‌دهیم — بدترین ترتیبِ ممکن») و
 * پرشِ ناگهانی به console در حساس‌ترین لحظهٔ قیف، یک دیوارِ ورود بود. این
 * صفحه روی **خودِ سایت** است، بی‌نشست و بی‌وابستگی به console: قیمت، همهٔ
 * دوره‌ها با تخفیفشان، جمعِ کلِ با مالیات، و ضمانتِ ۱۴روزه — و فقط در گامِ
 * پرداخت به console تحویل می‌دهد. («در همان مرزی که ثابت شده تحویل می‌شود.»)
 *
 * ⚠️ قیمت از همان `Product::priceForCycle()` می‌آید که فاکتورِ واقعی را
 * می‌سازد — دو منبعِ حقیقت همان بیماریِ «قیمتی که نمی‌شود خرید» است که
 * این پروژه سه بار درمان کرده.
 */
class OrderSummaryController extends Controller
{
    public function show(string $slug): View
    {
        abort_unless(Schema::hasTable('products'), 404);

        $product = Product::where('slug', $slug)->where('is_active', true)->firstOrFail();

        /*
        | یک ردیف به ازای هر دورهٔ تعریف‌شده در config/billing — همان منبعی
        | که تسویه از آن می‌خواند. «once» فقط وقتی می‌آید که پکیج اصلاً
        | دوره‌ای نباشد (لایسنس/کارِ یک‌بار).
        */
        $rows = [];

        foreach (Service::cycles() as $cycle) {
            $months = Service::monthsIn($cycle);

            if ($months <= 0) {
                continue;
            }

            $total = $product->priceForCycle($cycle);
            $tax = (int) round($total * $product->tax_percent / 100);

            $rows[] = [
                'cycle'   => $cycle,
                'label'   => Service::labelFor($cycle),
                'months'  => $months,
                'monthly' => $product->monthlyEquivalent($cycle),
                'saving'  => $product->savingPct($cycle),
                'grand'   => $total + $tax,
            ];
        }

        if ($rows === []) {
            $total = $product->effectivePrice();
            $tax = (int) round($total * $product->tax_percent / 100);

            $rows[] = [
                'cycle'   => 'once',
                'label'   => Service::labelFor('once'),
                'months'  => 0,
                'monthly' => $total,
                'saving'  => 0,
                'grand'   => $total + $tax,
            ];
        }

        $this->logFunnel($slug);

        return view('pages.order-summary', [
            'product' => $product,
            'rows'    => $rows,
        ]);
    }

    /**
     * شمارندهٔ قیفِ بلاگ→سفارش — سنجهٔ شمارهٔ ۳ مدیر رشد (ممیزی ۴).
     *
     * «سهم شروع‌های سفارش که سشنشان شاملِ یک صفحهٔ بلاگ بوده» — پایهٔ چهار
     * دورِ قبل صفر بود، پس این تمیزترین آزمونِ قبل/بعدی است که گیرمان می‌آید.
     * این صفحه عمداً کش نمی‌شود، پس هر بازدید واقعاً به این‌جا می‌رسد و
     * برخلافِ صفحات HIT، شمارش کامل است.
     *
     * ⚠️ فایل، نه دیتابیس: شمارنده حق ندارد صفحهٔ فروش را به یک قطعیِ گذرای
     * DB گره بزند (قاعدهٔ ثبت‌شدهٔ پروژه). JSONL ماهانه؛ تحلیل با یک grep.
     */
    private function logFunnel(string $slug): void
    {
        try {
            $ref = (string) request()->headers->get('referer', '');
            $host = (string) parse_url($ref, PHP_URL_HOST);
            $path = (string) parse_url($ref, PHP_URL_PATH);
            $ours = $host === (string) parse_url((string) config('app.url'), PHP_URL_HOST);

            $bucket = match (true) {
                $ref === ''                                  => 'direct',
                $ours && str_contains($path, '/blog')        => 'blog',
                $ours                                        => 'site',
                default                                      => 'external',
            };

            $dir = storage_path('app/funnel');

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            @file_put_contents(
                $dir.'/order-summary-'.date('Y-m').'.jsonl',
                json_encode(['t' => date('c'), 'slug' => $slug, 'ref' => $bucket], JSON_UNESCAPED_SLASHES)."\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // شمارنده هرگز نباید صفحهٔ فروش را بیندازد
        }
    }
}
