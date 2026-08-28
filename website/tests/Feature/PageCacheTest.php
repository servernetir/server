<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * کشِ کاملِ صفحه (ممیزی ۳ — قلمِ «سه دور دست‌نخورده»).
 *
 * تعریفِ «انجام شد» از خودِ ممیزی آمده: دو درخواستِ پیاپیِ مهمان روی یک URL
 * فهرست‌شده، دومی `X-Cache: HIT` بدهد؛ و درخواستِ نشست‌دار `BYPASS`. این تست
 * همان قرارداد را قفل می‌کند + دو خطری که کشِ ساده‌لوحانه می‌سازد:
 *
 *   · توکنِ CSRF نباید بینِ بازدیدکننده‌ها مشترک شود (اولین POSTِ نفرِ دوم
 *     ۴۱۹ می‌گرفت — بی‌لاگ، فقط در کنسولِ مرورگرِ کاربر).
 *   · پاسخِ کش‌شده نباید Set-Cookieِ نفرِ قبلی را بازپخش کند.
 */
class PageCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml کشِ صفحه را برای کلِ سوئیت خاموش می‌کند (تا تست‌های
        // دیگر نسخهٔ کش‌شده نگیرند)؛ این پرونده تنها جایی است که روشن لازمش
        // دارد و صریح روشنش می‌کند.
        config(['pagecache.enabled' => true]);

        // هر تست با کشِ خالی شروع می‌شود؛ وگرنه HITِ تستِ قبلی، MISSِ این
        // تست را قرمز می‌کند و ترتیبِ اجرا معنی‌دار می‌شود.
        \Illuminate\Support\Facades\Cache::flush();
    }

    public function test_second_guest_visit_is_a_hit_and_content_survives(): void
    {
        $first = $this->get('/');

        $first->assertOk();
        $first->assertHeader('X-Cache', 'MISS');

        $second = $this->get('/');

        $second->assertOk();
        $second->assertHeader('X-Cache', 'HIT');

        // محتوا واقعاً همان صفحه است، نه پوسته‌ای خالی
        $second->assertSee('ServerNet', false);
    }

    /**
     * 🔴 مهم‌ترین ادعای این پرونده: HIT توکنِ نشستِ **همین** بازدیدکننده را
     * حمل می‌کند، نه توکنِ ذخیره‌شده‌ی نفرِ اول را.
     *
     * ⚠️ نسخهٔ قبلی دو بار `get('/')` می‌زد و انتظار داشت توکن‌ها فرق کنند،
     * با این توضیح که «کلاینتِ تست کوکی حمل نمی‌کند». آن فرض غلط است:
     * کلاینتِ تستِ لاراول نشست را بینِ درخواست‌های یک تست **نگه می‌دارد**، پس
     * هر دو درخواست یک توکن داشتند و تست قرمز می‌شد بی‌آنکه چیزی خراب باشد.
     *
     * 🔴 و بدتر از قرمزیِ کاذب: آن‌طور نوشته‌شده، تست **هرگز** نمی‌توانست
     * تعویضِ توکن را بسنجد — با گاردِ حذف‌شده هم دو توکن یکسان می‌ماندند و
     * باز هم قرمز بود. تستی که در هر دو حالت یک جواب می‌دهد، هیچ نمی‌سنجد.
     *
     * حالا نشستِ بازدیدکنندهٔ دوم **صریح** ساخته می‌شود و ادعا مستقیم است:
     * توکنِ او در HTML باشد و توکنِ نفرِ اول هیچ‌جا نمانده باشد.
     */
    public function test_a_cache_hit_carries_the_current_visitors_csrf_token(): void
    {
        $grab = function ($response): string {
            preg_match('/name="csrf-token" content="([^"]+)"/', $response->getContent(), $m);

            return $m[1] ?? '';
        };

        $first = $this->get('/');
        $first->assertHeader('X-Cache', 'MISS');
        $tokenA = $grab($first);
        $this->assertNotSame('', $tokenA, 'متا توکن در صفحه نیست — پیش‌فرضِ این تست عوض شده');

        // بازدیدکنندهٔ دوم: نشستی با توکنِ کاملاً متفاوت و قابلِ تشخیص
        $tokenB = 'SecondVisitorToken'.str_repeat('b', 22);
        $this->assertNotSame($tokenA, $tokenB);

        $second = $this->withSession(['_token' => $tokenB])->get('/');
        $second->assertHeader('X-Cache', 'HIT');

        $this->assertSame($tokenB, $grab($second),
            'HIT توکنِ نفرِ اول را بازپخش کرد — اولین POSTِ هر بازدیدکنندهٔ تازه ۴۱۹ می‌گیرد');

        // و هیچ ردی از توکنِ نفرِ اول نمانده باشد (input های _token هم عوض شوند)
        $this->assertStringNotContainsString($tokenA, $second->getContent(),
            'توکنِ نفرِ اول هنوز جایی در HTML مانده');
    }

    public function test_a_session_cookie_bypasses_the_cache(): void
    {
        // پر کردن کش با یک مهمان
        $this->get('/')->assertHeader('X-Cache', 'MISS');

        $withCookie = $this->withUnencryptedCookie((string) config('session.cookie'), 'x')
            ->get('/');

        $withCookie->assertHeader('X-Cache', 'BYPASS');
    }

    public function test_query_strings_and_unlisted_routes_bypass(): void
    {
        $this->get('/?utm_source=x')->assertHeader('X-Cache', 'BYPASS');

        // صفحهٔ وضعیت عمداً در فهرست نیست — باید لحظه‌ای بماند
        $this->get('/status')->assertHeader('X-Cache', 'BYPASS');
    }

    /**
     * ممیزی ۴ (CTO): «کش بدونِ ابطال بدهی است — بعد از تغییرِ قیمت، HIT تا
     * پایانِ TTL قیمتِ قدیمی را نشان می‌دهد.» purge باید **همان لحظه** همهٔ
     * نسخه‌ها را باطل کند، نه در انتهای TTL.
     */
    public function test_purge_invalidates_instantly(): void
    {
        $this->get('/')->assertHeader('X-Cache', 'MISS');
        $this->get('/')->assertHeader('X-Cache', 'HIT');

        \App\Http\Middleware\PageCache::purge();

        $this->get('/')->assertHeader('X-Cache', 'MISS');
        $this->get('/')->assertHeader('X-Cache', 'HIT');
    }

    public function test_all_three_locales_cache_independently(): void
    {
        $this->get('/')->assertHeader('X-Cache', 'MISS');
        $this->get('/en')->assertHeader('X-Cache', 'MISS');

        $fa = $this->get('/');
        $en = $this->get('/en');

        $fa->assertHeader('X-Cache', 'HIT');
        $en->assertHeader('X-Cache', 'HIT');

        $this->assertStringContainsString('lang="fa"', $fa->getContent());
        $this->assertStringContainsString('lang="en"', $en->getContent());
    }
}
