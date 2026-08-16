<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `llms.txt` تنها سندی است که **خودمان** به مدل‌های زبانی می‌دهیم.
 *
 * ═══ چرا اهمیت دارد ═══
 *
 * بخشی از خریدارها دیگر از گوگل نمی‌پرسند؛ از ChatGPT و Perplexity می‌پرسند.
 * آن‌ها این فایل را می‌خوانند تا بفهمند این شرکت چه می‌فروشد. پس هرچه این‌جا
 * نباشد، از دیدِ آن خریدار **وجود ندارد**.
 *
 * ═══ دو خرابی که ممیزی پیدا شد ═══
 *
 * ۱) **کاتالوگ ناقص بود.** فقط hosting/vps/dedicated فهرست می‌شدند. یعنی
 *    سرورِ ابری — تازه‌ترین خطِ محصول و پرصفحه‌ترینشان — و دامنه و خدمات و
 *    راهکارها اصلاً نامشان برده نمی‌شد. اگر کسی می‌پرسید «سرورنت سرورِ ابری
 *    دارد؟»، تنها سندی که خودمان گذاشته‌ایم می‌گفت نه.
 * ۲) **`/lookup`** فهرست شده بود — تنها آدرسِ این فایل که در نقشهٔ سایت
 *    **نبود**، چون همان `/lookup/a` را رندر می‌کند و حالا به آن canonical
 *    می‌شود. فرستادنِ مدل به آدرسِ غیرِcanonical یعنی یک صفحه را زیرِ دو نام
 *    معرفی کردن.
 *
 * ⚠️ هیچ‌کدام خطا تولید نمی‌کردند و فایل همیشه ۲۰۰ بود.
 */
class LlmsTxtIsCompleteTest extends TestCase
{
    use RefreshDatabase;

    private function body(): string
    {
        return $this->get('/llms.txt')->assertOk()->getContent();
    }

    /**
     * 🔴 هر خطِ محصولی که واقعاً می‌فروشیم باید این‌جا باشد.
     *
     * ⚠️ روی **همان configهایی** می‌ایستد که خودِ صفحات از آن ساخته می‌شوند،
     * نه روی فهرستِ دستی: وگرنه همین تست هم همان هفتهٔ اول کهنه می‌شود.
     */
    public function test_every_catalogue_line_is_advertised(): void
    {
        $body = $this->body();
        $missing = [];

        foreach (['vps', 'dedicated', 'cloud', 'domain', 'services'] as $cat) {
            foreach (array_keys((array) config("catalog.$cat", [])) as $slug) {
                if (! str_contains($body, "/{$cat}/{$slug})")) {
                    $missing[] = "{$cat}/{$slug}";
                }
            }
        }

        foreach (array_keys((array) config('hosting.products', [])) as $slug) {
            if (! str_contains($body, "/hosting/{$slug})")) {
                $missing[] = "hosting/{$slug}";
            }
        }

        // راهکارهای ادغام‌شده ۳۰۱ می‌خورند و عمداً فهرست نمی‌شوند
        $merged = \App\Http\Controllers\SolutionController::MERGED;

        foreach (array_keys(array_diff_key((array) config('solutions', []), $merged)) as $slug) {
            if (! str_contains($body, "/solutions/{$slug})")) {
                $missing[] = "solutions/{$slug}";
            }
        }

        $this->assertSame([], $missing,
            "این محصولات را می‌فروشیم ولی llms.txt نامشان را نمی‌برد:\n".implode("\n", $missing));
    }

    /**
     * 🔴 هیچ آدرسی نباید به صفحه‌ای برود که خودش را canonical نمی‌کند.
     *
     * این دقیقاً همان چیزی است که `/lookup` بود.
     */
    public function test_every_link_points_at_a_canonical_page(): void
    {
        preg_match_all('~\]\((https?://[^)]+)\)~', $this->body(), $m);

        $this->assertNotEmpty($m[1], 'هیچ لینکی در فایل نیست');

        $bad = [];

        foreach (array_unique($m[1]) as $url) {
            $path = parse_url($url, PHP_URL_PATH) ?: '/';

            $res = $this->get($path);
            if ($res->getStatusCode() !== 200) {
                $bad[] = "{$path} → {$res->getStatusCode()}";

                continue;
            }

            preg_match('~<link[^>]+rel="canonical"[^>]+href="([^"]+)"~', $res->getContent(), $c);

            // فایل‌های متنی canonical ندارند و لازم هم نیست
            if (! isset($c[1])) {
                continue;
            }

            $canon = rtrim(parse_url($c[1], PHP_URL_PATH) ?: '/', '/') ?: '/';
            if ($canon !== (rtrim($path, '/') ?: '/')) {
                $bad[] = "{$path} → canonical می‌گوید {$canon}";
            }
        }

        $this->assertSame([], $bad,
            "llms.txt به آدرسی می‌فرستد که صفحه خودش را با آن نمی‌شناسد:\n".implode("\n", $bad));
    }

    /**
     * 🔴 هشدارِ ریال باید بمانَد.
     *
     * schema واقعاً `IRR` می‌دهد (تأییدشده روی سایتِ زنده: `IRR 2500000` در
     * برابرِ «۲۵۰٬۰۰۰ تومان» روی صفحه). بی‌این جمله، مدل قیمت را **ده برابر**
     * گران نقل می‌کند — درست همان لحظه‌ای که خریدار دارد ما را با رقیب مقایسه
     * می‌کند، و بی‌آنکه در هیچ آنالیتیکسی دیده شود.
     */
    public function test_the_rial_warning_survives(): void
    {
        $body = $this->body();

        $this->assertStringContainsString('IRR', $body);
        $this->assertMatchesRegularExpression('~divide by 10~i', $body,
            'هشدارِ تبدیلِ ریال به تومان برداشته شده');
    }

    /** فایل باید برای عاملِ خودکار پیدا شدنی باشد. */
    public function test_robots_points_at_it(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('llms.txt', $robots,
            'robots.txt به llms.txt اشاره نمی‌کند — تنها جایی که عاملِ خودکار قبلش نگاه می‌کند');
    }
}
