<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

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

    public function test_an_ordinary_guest_session_cookie_can_hit_with_its_own_token(): void
    {
        $first = $this->get('/')->assertHeader('X-Cache', 'MISS');
        preg_match('/name="csrf-token" content="([^"]+)"/', $first->getContent(), $match);
        $firstToken = $match[1];
        $secondToken = 'GuestSessionToken'.str_repeat('z', 23);

        $withCookie = $this->withSession([
            '_token' => $secondToken,
            '_previous' => ['url' => 'https://servernet.cloud/blog', 'route' => 'blog.index'],
            '_flash' => ['old' => [], 'new' => []],
        ])->withUnencryptedCookie((string) config('session.cookie'), 'guest-session-id')
            ->get('/');

        $withCookie->assertHeader('X-Cache', 'HIT');
        $withCookie->assertSee('name="csrf-token" content="'.$secondToken.'"', false);
        $this->assertStringNotContainsString($firstToken, $withCookie->getContent());
        $this->assertStringContainsString('private', $withCookie->headers->get('Cache-Control'));
        $this->assertStringContainsString('Cookie', $withCookie->headers->get('Vary'));
        $this->assertStringNotContainsString('public', $withCookie->headers->get('Cache-Control'));
    }

    public function test_personal_session_state_bypasses_warm_cache(): void
    {
        $this->get('/')->assertHeader('X-Cache', 'MISS');

        // Even empty or unknown business keys are unsafe: future page code may
        // use their presence. Do not limit this to today's known private keys.
        $privateData = [
            'errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['email' => ['Visitor-specific error']])),
            'url' => ['intended' => 'https://servernet.cloud/account/order/vps'],
            'snet_analytics' => ['event' => 'visitor-only'],
            'pending_order' => 'visitor-only',
            'future_key' => '',
        ];
        foreach ($privateData as $key => $value) {
            $this->withSession([$key => $value])->get('/')
                ->assertHeader('X-Cache', 'BYPASS');
            session()->forget($key);
        }
    }

    public function test_encrypted_response_cookie_reloads_the_guest_session_on_a_fresh_request(): void
    {
        // A persisted session and a new Store on every request avoid the test
        // client's usual in-memory session shortcut. Replay exactly the issued
        // encrypted cookie through EncryptCookies and StartSession.
        $directory = storage_path('framework/testing/cache-cookie-'.getmypid());
        mkdir($directory, 0777, true);
        config(['session.driver' => 'file', 'session.files' => $directory]);
        $freshSession = function (): void {
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
            app('auth')->forgetGuards();
        };

        try {
            $freshSession();
            $first = $this->get('/')->assertOk()->assertHeader('X-Cache', 'MISS');
            $cookieName = (string) config('session.cookie');
            $cookie = $first->getCookie($cookieName, false);
            $this->assertNotNull($cookie);
            $firstId = session()->getId();
            $firstStore = app('session')->driver();
            $firstToken = session()->token();
            $this->assertNotSame($firstId, $cookie->getValue(), 'Response cookie must actually be encrypted');

            $freshSession();
            $second = $this->withUnencryptedCookie($cookieName, $cookie->getValue())
                ->get('/')->assertOk()->assertHeader('X-Cache', 'HIT');
            $this->assertNotSame($firstStore, app('session')->driver());
            $this->assertSame($firstId, session()->getId());
            $this->assertSame($firstToken, session()->token());
            $second->assertSee('name="csrf-token" content="'.$firstToken.'"', false);

            $this->unencryptedCookies = [];
            $freshSession();
            $otherVisitor = $this->get('/')->assertOk()->assertHeader('X-Cache', 'HIT');
            $this->assertNotSame($firstId, session()->getId());
            $this->assertNotSame($firstToken, session()->token());
            $otherVisitor->assertSee('name="csrf-token" content="'.session()->token().'"', false);
            $this->assertStringNotContainsString($firstToken, $otherVisitor->getContent());
        } finally {
            foreach ((array) glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_flash_state_bypasses_warm_cache_until_consumed(): void
    {
        $this->get('/')->assertHeader('X-Cache', 'MISS');
        session()->flash('comment_status', 'pending');

        $this->get('/')->assertHeader('X-Cache', 'BYPASS');
        $this->get('/')->assertHeader('X-Cache', 'BYPASS');
        $this->get('/')->assertHeader('X-Cache', 'HIT');
    }

    public function test_both_authenticated_guards_bypass_warm_cache(): void
    {
        $staff = \App\Models\User::factory()->create();
        $customer = \App\Models\Customer::create([
            'email' => 'guest-cache@example.test', 'password' => 'cache-test-password',
            'status' => 'active', 'locale' => 'fa',
        ]);
        $this->get('/')->assertHeader('X-Cache', 'MISS');

        $this->actingAs($staff, 'web')->get('/')->assertHeader('X-Cache', 'BYPASS');
        auth('web')->logout();
        $this->actingAs($customer, 'customer')->get('/')
            ->assertHeader('X-Cache', 'BYPASS');
    }

    public function test_authorization_header_bypasses_warm_cache(): void
    {
        $this->get('/')->assertHeader('X-Cache', 'MISS');
        $this->withHeader('Authorization', 'Bearer guest-cache-test')->get('/')
            ->assertHeader('X-Cache', 'BYPASS');
    }

    public function test_state_added_during_render_is_not_saved_for_another_guest(): void
    {
        $middleware = app(\App\Http\Middleware\PageCache::class);
        $request = \Illuminate\Http\Request::create('https://servernet.cloud/blog', 'GET');
        $request->setRouteResolver(fn () => \Illuminate\Support\Facades\Route::getRoutes()->getByName('blog.index'));
        $request->setLaravelSession(app('session')->driver());
        $request->session()->flush();
        $request->session()->regenerateToken();

        $first = $middleware->handle($request, function ($request) {
            $request->session()->put('new_private_state', 'visitor-secret');

            return response('<p>visitor-secret</p>', 200, ['Content-Type' => 'text/html']);
        });
        $this->assertSame('MISS', $first->headers->get('X-Cache'));

        $request->session()->forget('new_private_state');
        $second = $middleware->handle($request, fn () => response('<p>public</p>', 200, ['Content-Type' => 'text/html']));
        $this->assertSame('MISS', $second->headers->get('X-Cache'));
        $this->assertStringNotContainsString('visitor-secret', $second->getContent());
    }

    public function test_guest_session_reuse_can_be_disabled_without_disabling_page_cache(): void
    {
        $this->get('/')->assertHeader('X-Cache', 'MISS');
        config(['pagecache.guest_sessions' => false]);

        $this->withUnencryptedCookie((string) config('session.cookie'), 'guest-session-id')
            ->get('/')->assertHeader('X-Cache', 'BYPASS');
    }

    public function test_render_time_private_state_cannot_be_replaced_with_a_stale_copy(): void
    {
        config(['pagecache.ttl' => -1]);
        $middleware = app(\App\Http\Middleware\PageCache::class);
        $request = \Illuminate\Http\Request::create('https://servernet.cloud/blog', 'GET');
        $request->setRouteResolver(fn () => \Illuminate\Support\Facades\Route::getRoutes()->getByName('blog.index'));
        $request->setLaravelSession(app('session')->driver());
        $request->session()->flush();
        $request->session()->regenerateToken();

        $middleware->handle($request, fn () => response('<p>public copy</p>', 200, ['Content-Type' => 'text/html']));
        $failed = $middleware->handle($request, function ($request) {
            $request->session()->put('private_result', 'visitor-only');

            return response('<p>private failure</p>', 503, ['Content-Type' => 'text/html']);
        });
        $this->assertSame(503, $failed->getStatusCode());
        $this->assertSame('MISS', $failed->headers->get('X-Cache'));
        $this->assertStringNotContainsString('public copy', $failed->getContent());

        $request->session()->forget('private_result');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('private rendering failed');
        $middleware->handle($request, function ($request) {
            $request->session()->put('private_result', 'visitor-only');

            throw new \RuntimeException('private rendering failed');
        });
    }

    public function test_a_cookie_without_an_initialized_session_fails_closed(): void
    {
        $request = \Illuminate\Http\Request::create('https://servernet.cloud/', 'GET', [], [
            (string) config('session.cookie') => 'unresolved-session',
        ]);
        $request->setRouteResolver(fn () => \Illuminate\Support\Facades\Route::getRoutes()->getByName('home'));

        $response = app(\App\Http\Middleware\PageCache::class)->handle($request,
            fn () => response('<p>public</p>', 200, ['Content-Type' => 'text/html']));

        $this->assertSame('BYPASS', $response->headers->get('X-Cache'));
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

    public function test_front_controller_alias_cannot_replace_the_clean_urls_cached_html(): void
    {
        $middleware = app(\App\Http\Middleware\PageCache::class);
        $alias = \Illuminate\Http\Request::create(
            'https://servernet.cloud/index.php/parts/ram',
            'GET', [], [], [],
            ['SCRIPT_FILENAME' => '/var/www/index.php', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php/parts/ram']
        );
        $clean = \Illuminate\Http\Request::create('https://servernet.cloud/parts/ram', 'GET');
        // Establish the collision that caused the defect, rather than assuming
        // the URI still contains the script after Symfony normalizes path().
        $this->assertSame('/index.php', $alias->getBaseUrl());
        $this->assertSame('', $clean->getBaseUrl());
        $this->assertSame($alias->path(), $clean->path());
        foreach ([$alias, $clean] as $request) {
            $request->setRouteResolver(fn () => \Illuminate\Support\Facades\Route::getRoutes()->getByName('home'));
        }
        $render = fn ($request) => response(
            '<link rel="canonical" href="'.$request->url().'">',
            200, ['Content-Type' => 'text/html; charset=UTF-8']
        );

        $firstAlias = $middleware->handle($alias, $render);
        $this->assertSame('MISS', $firstAlias->headers->get('X-Cache'));
        $firstClean = $middleware->handle($clean, $render);
        $this->assertSame('MISS', $firstClean->headers->get('X-Cache'));
        $this->assertSame('<link rel="canonical" href="https://servernet.cloud/parts/ram">', $firstClean->getContent());

        foreach ([$alias, $clean] as $request) {
            $hit = $middleware->handle($request, $render);
            $this->assertSame('HIT', $hit->headers->get('X-Cache'));
            $this->assertSame('<link rel="canonical" href="'.$request->url().'">', $hit->getContent());
        }
    }

}
