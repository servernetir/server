<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * هیچ لینکِ داخلیِ سایت نباید به ۴۰۴ برسد.
 *
 * ═══ چرا این تست، و چرا **محلی** ═══
 *
 * ممیزیِ بیرونی گزارش داد «۵۸ لینک شکسته از ۳۱۹ مقصد» و به‌طور مشخص گفت دکمهٔ
 * اشتراک‌گذاریِ هر ۹۸ پست خراب است. بررسی نشان داد آن بخشِ مشخص **غلط** بود:
 * `t.me/share/url` و `linkedin.com/sharing/share-offsite` نقطه‌های رسمیِ
 * اشتراک‌گذاری‌اند، پارامترهایشان درست ساخته می‌شود و به آدرسِ همان پست اشاره
 * می‌کنند. آن دو میزبان به **ربات** پاسخِ غیر‌۲۰۰ می‌دهند (لینکدین کدِ ۹۹۹
 * می‌دهد)، و لینک‌چکرِ ممیزی همان را «شکسته» شمرده بود.
 *
 * 🔴 درسِ عملی که این‌جا کد شده: **لینکِ بیرونی را با درخواستِ خودکار قضاوت
 * نکن.** این تست فقط لینک‌های **داخلی** را می‌سنجد، جایی که پاسخ قطعی است و
 * ۴۰۴ واقعاً یعنی خراب.
 *
 * ⚠️ و چرا محلی و نه روی سایتِ زنده: پیمایشِ سریعِ سایتِ زنده محافظِ ضدِ ربات را
 * فعال می‌کند و **همه‌چیز** ۴۰۳ می‌شود — از جمله صفحهٔ اصلی. آن‌وقت گزارش پر
 * می‌شود از «لینکِ شکسته»ای که فقط بلاک‌شدنِ خودِ ابزار است. دقیقاً همان جنسِ
 * خطایی که این تست برای جلوگیری از آن نوشته شده.
 *
 * ⚠️ محدودیتِ صادقانه: بدنهٔ پست‌های بلاگ در دیتابیسِ محلی نیست، پس لینک‌هایی
 * که **داخلِ متنِ مقاله** نوشته شده‌اند این‌جا سنجیده نمی‌شوند. برای آن‌ها
 * دیتابیسِ پروداکشن لازم است.
 */
class InternalLinksResolveTest extends TestCase
{
    /*
    | 🔴 `RefreshDatabase` لازم است و نبودش خودِ این تست را دروغ‌گو کرد.
    |
    | نسخهٔ اولِ همین فایل بی‌آن نوشته شد و ۳۶ صفحهٔ `/cloud/*` را «شکسته»
    | گزارش کرد. بررسی نشان داد همان آدرس بیرونِ تست ۲۰۰ می‌دهد: محیطِ تست
    | روی sqliteِ `:memory:` است و بی‌مهاجرت، جدول‌های ابری وجود ندارند، پس
    | صفحه ۵۰۰ می‌شد.
    |
    | یعنی ابزارِ ساخته‌شده برای گرفتنِ «لینکِ شکستهٔ کاذب»، خودش دقیقاً همان را
    | تولید کرد — همان اشتباهی که در ممیزیِ بیرونی نقد شد. گزارشی که مثبتِ
    | کاذب بدهد، بعد از دو بار نادیده گرفته می‌شود.
    */
    use RefreshDatabase;

    /** مسیرهایی که پشتِ ورودند و ۳۰۲ گرفتنشان طبیعی است. */
    private const GATED = ['account', 'admin', 'api', 'system', 'login', 'register', 'logout'];

    /** صفحه‌هایی که عمداً رفته‌اند و باید ۴۱۰ بدهند. */
    private const GONE = ['panel-preview'];

    private function isGated(string $path): bool
    {
        $head = trim($path, '/');

        foreach (self::GATED as $g) {
            if ($head === $g || str_starts_with($head, $g.'/')
                || preg_match('~^(en|tr)/'.preg_quote($g, '~').'(/|$)~', $head)) {
                return true;
            }
        }

        return false;
    }

    /** همهٔ صفحاتِ عمومیِ بی‌پارامتر — نقطهٔ شروعِ پیمایش. */
    private function seedPages(): array
    {
        $seeds = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();
            if (str_contains($uri, '{') || $this->isGated($uri)) {
                continue;
            }
            $seeds[] = '/'.ltrim($uri, '/');
        }

        return array_values(array_unique($seeds));
    }

    public function test_every_internal_link_on_every_public_page_resolves(): void
    {
        $seeds = $this->seedPages();
        $this->assertGreaterThan(30, count($seeds), 'پیمایش صفحهٔ کافی پیدا نکرد');

        $targets = [];   // path => صفحه‌ای که از آن آمده

        foreach ($seeds as $page) {
            $res = $this->get($page);
            if ($res->getStatusCode() !== 200) {
                continue;
            }

            preg_match_all('~<a[^>]+href="([^"]+)"~i', $res->getContent(), $m);

            foreach ($m[1] as $href) {
                $path = $this->internalPath($href);
                if ($path !== null && ! isset($targets[$path])) {
                    $targets[$path] = $page;
                }
            }
        }

        $this->assertGreaterThan(40, count($targets), 'لینکِ داخلیِ کافی جمع نشد');

        $broken = [];
        foreach ($targets as $path => $from) {
            if ($this->isGated($path)) {
                continue;
            }

            $code = $this->get($path)->getStatusCode();

            // ۴۱۰ برای صفحاتِ عمداً حذف‌شده درست است، ولی نباید از جایی **لینک** شود
            if ($code === 410) {
                $broken[] = "{$path} → ۴۱۰ (حذف‌شده ولی هنوز از {$from} لینک دارد)";

                continue;
            }

            if ($code >= 400) {
                $broken[] = "{$path} → {$code}  (از {$from})";
            }
        }

        $this->assertSame([], $broken,
            "لینکِ داخلیِ شکسته:\n".implode("\n", $broken));
    }

    /**
     * مسیرِ داخلی از یک href، یا `null` اگر بیرونی/غیرقابل‌بررسی باشد.
     *
     * ⚠️ `mailto:`، `tel:`، لنگر و لینکِ بیرونی عمداً کنار می‌روند — قضاوتشان
     * با درخواستِ خودکار همان اشتباهی است که ممیزی کرد.
     */
    private function internalPath(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '' || str_starts_with($href, '#')
            || preg_match('~^(mailto:|tel:|javascript:|data:)~i', $href)) {
            return null;
        }

        if (preg_match('~^https?://~i', $href)) {
            $host = parse_url($href, PHP_URL_HOST);
            if ($host !== parse_url(config('app.url'), PHP_URL_HOST) && $host !== 'localhost') {
                return null;                       // بیرونی — بررسی نمی‌شود
            }
            $href = (string) parse_url($href, PHP_URL_PATH);
        }

        $href = (string) strtok($href, '#');
        $href = (string) strtok($href, '?');

        if ($href === '' || ! str_starts_with($href, '/')) {
            return null;
        }

        return rtrim($href, '/') ?: '/';
    }

    /**
     * صفحهٔ حذف‌شده باید ۴۱۰ بدهد و **از هیچ‌جا لینک نشود**.
     *
     * تستِ بالا خودش این را می‌گیرد، ولی این یکی صریح است تا اگر روزی کسی
     * لینکِ ماک را برگرداند، پیام روشن باشد.
     */
    public function test_removed_pages_are_not_linked_from_anywhere(): void
    {
        foreach ($this->seedPages() as $page) {
            $res = $this->get($page);
            if ($res->getStatusCode() !== 200) {
                continue;
            }

            foreach (self::GONE as $gone) {
                $this->assertStringNotContainsString('href="'.url('/'.$gone), $res->getContent(),
                    "صفحهٔ {$page} به «{$gone}» لینک می‌دهد که حذف شده");
            }
        }
    }
}
