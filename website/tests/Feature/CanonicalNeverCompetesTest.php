<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * دو آدرسِ یکسان نباید هرکدام **خودشان** را canonical اعلام کنند.
 *
 * ═══ خرابیِ واقعی که این فایل برایش نوشته شد ═══
 *
 * `LookupController::index()` صریح `return $this->show('a', $request)` است، پس
 * `/lookup` و `/lookup/a` بایت‌به‌بایت یک صفحه‌اند — روی سایتِ زنده سنجیده شد:
 * هر دو ۷۳۰۴ کاراکتر، همان `<title>`، و هرکدام canonicalِ خودش.
 *
 * یعنی گوگل دو نسخهٔ یکسان می‌بیند که هیچ‌کدام دیگری را به رسمیت نمی‌شناسد،
 * سیگنال‌ها بینشان تقسیم می‌شود، و انتخابِ نهایی دستِ گوگل است. در هر سه زبان.
 *
 * ⚠️ هیچ خطایی تولید نمی‌کند و هر دو آدرس ۲۰۰ می‌دهند — همان «کدِ ۲۰۰ یعنی
 * هیچ» در CLAUDE.md. تنها راهِ دیدنش سنجیدنِ خودِ تگ است.
 *
 * ⚠️ چرا ممیزیِ بیرونی این را ندید و به‌جایش «۲۰۳ صفحهٔ ایندکس‌نشدنی» گزارش
 * کرد: بررسی نشان داد آن ادعا **غلط** بود — نقشهٔ سایت ۱۱۲۵ آدرس دارد، منو
 * HTMLِ واقعی است و هر هاب به عمق لینک می‌دهد. مشکلِ واقعی خیلی باریک‌تر و
 * از دید پنهان‌تر بود.
 */
class CanonicalNeverCompetesTest extends TestCase
{
    use RefreshDatabase;

    private function canonicalOf(string $path): ?string
    {
        $res = $this->get($path);
        $res->assertOk();

        preg_match('~<link[^>]+rel="canonical"[^>]+href="([^"]+)"~', $res->getContent(), $m);

        return $m[1] ?? null;
    }

    /** 🔴 ادعای اصلی: آدرسِ پیش‌فرض باید به صفحهٔ واقعی اشاره کند. */
    public function test_the_lookup_index_points_at_the_page_it_actually_renders(): void
    {
        $this->assertSame(
            $this->canonicalOf('/lookup/a'),
            $this->canonicalOf('/lookup'),
            '/lookup همان /lookup/a را رندر می‌کند ولی خودش را canonical می‌کند — دو صفحهٔ یکسانِ رقیب'
        );
    }

    /** و هر ابزارِ دیگری همچنان خودش را canonical می‌کند، نه صفحهٔ پیش‌فرض را. */
    public function test_each_tool_still_canonicalises_to_itself(): void
    {
        foreach (['mx', 'ssl', 'ping'] as $type) {
            $this->assertStringEndsWith("/lookup/{$type}", (string) $this->canonicalOf("/lookup/{$type}"),
                "ابزارِ «{$type}» باید خودش را canonical کند");
        }
    }

    /**
     * ⚠️ در هر سه زبان — چون روت‌ها سه بار ثبت می‌شوند، خرابی هم سه‌برابر است.
     */
    public function test_the_same_holds_in_every_locale(): void
    {
        foreach (['en', 'tr'] as $loc) {
            $this->assertSame(
                $this->canonicalOf("/{$loc}/lookup/a"),
                $this->canonicalOf("/{$loc}/lookup"),
                "در زبانِ {$loc} هم /lookup خودش را canonical می‌کند"
            );
        }
    }

    /**
     * 🔴 سنجهٔ عمومی‌تر: هیچ **جفتِ** صفحهٔ عمومیِ هم‌محتوا نباید canonicalِ
     * متفاوت داشته باشد.
     *
     * این‌جا فقط آن‌هایی سنجیده می‌شوند که واقعاً یک بدنه دارند؛ صفحاتِ متفاوت
     * طبیعتاً canonicalِ متفاوت دارند و آن درست است.
     */
    public function test_no_two_identical_public_pages_claim_different_canonicals(): void
    {
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{')) {
                continue;
            }
            if (preg_match('~^(en/|tr/)?(admin|account|api|system|login|register|logout|bale)~', $uri)) {
                continue;
            }
            if (preg_match('~^(en|tr)/~', $uri)) {
                continue;                       // یک زبان کافی است
            }
            $paths[] = '/'.ltrim($uri, '/');
        }

        $this->assertGreaterThan(20, count($paths), 'صفحهٔ کافی برای سنجش پیدا نشد');

        $byBody = [];
        foreach ($paths as $p) {
            $res = $this->get($p);
            if ($res->getStatusCode() !== 200) {
                continue;
            }
            $html = $res->getContent();
            preg_match('~<link[^>]+rel="canonical"[^>]+href="([^"]+)"~', $html, $m);
            // بدنه بدونِ خودِ تگِ canonical، وگرنه دو صفحهٔ یکسان هرگز یکی نمی‌شوند
            $body = md5(preg_replace('~<link[^>]+rel="canonical"[^>]+>~', '', $html));
            $byBody[$body][] = [$p, $m[1] ?? null];
        }

        $bad = [];
        foreach ($byBody as $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $canons = array_unique(array_column($rows, 1));
            if (count($canons) > 1) {
                $bad[] = implode(' ≡ ', array_column($rows, 0)).' → '.implode(' / ', $canons);
            }
        }

        $this->assertSame([], $bad,
            "این صفحات محتوای یکسان دارند ولی canonicalِ متفاوت — با هم رقابت می‌کنند:\n"
            .implode("\n", $bad));
    }
}
