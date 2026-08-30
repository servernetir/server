<?php

namespace Tests\Feature;

use App\Http\Controllers\CatalogController;
use App\Services\Domain\DomainSearch;
use App\Support\ErrorTracker;
use Database\Seeders\BillingFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 «The domains limit exceeded. Please send less domains in one request.»
 *     code 701 · domains=64
 *
 * ═══ چرا ۶۴ ═══
 *
 * `DomainSearch::SUGGEST_TLDS` دقیقاً ۶۴ عضو دارد، و `Account\DomainController`
 * بی‌آرگومانِ دوم `search()` را صدا می‌زد — پس **هر** جستجوی پنلِ مشتری کلِ
 * فهرست را در یک درخواست می‌فرستاد و همیشه رد می‌شد. صفحهٔ عمومیِ `/domains`
 * سالم بود چون خودش در جاوااسکریپت دسته‌دسته می‌فرستاد. یعنی سه فراخوان از
 * چهارتا دسته می‌کردند و همان یکی که نمی‌کرد، خرابی را می‌ساخت.
 *
 * ⚠️ یک پاسِ قبلی عمداً دسته‌بندی نکرد چون علت اثبات نشده بود، و دسته‌کردنِ یک
 * حسابِ علامت‌خورده روی حدس، اوضاع را بدتر می‌کرد. حالا از پروداکشن اثبات شده.
 *
 * ═══ چه چیزی این‌جا قفل می‌شود ═══
 *
 * ۱) هیچ درخواستی بیش از `BATCH` دامنه ندارد.
 * ۲) تعدادِ کلِ درخواست از چیزی که صفحهٔ عمومی از قبل می‌فرستاد بیشتر نیست.
 * ۳) شکستِ یک دسته فقط ردیف‌های **همان دسته** را «استعلام نشد» می‌کند.
 * ۴) گلوگاهِ «پاسخِ ناقص» با دسته‌بندی به سیل تبدیل نشده.
 * ۵) و پروندهٔ جدا: `code 199 · domains=1` — پسوندهای الکی‌ای که خودمان
 *    می‌ساختیم و می‌پرسیدیم.
 */
class DomainSearchBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingFoundationSeeder::class);

        config([
            'services.openprovider.username' => 'u',
            'services.openprovider.password' => 'p',
            'services.openprovider.margin'   => ['default' => 25],
        ]);

        Cache::put('fx.usd_irt', [
            'currency' => 'USD', 'rate_toman' => 100000,
            'source' => 'test', 'at' => now()->toIso8601String(),
        ], now()->addHour());

        ErrorTracker::clear();
    }

    /**
     * رجیسترارِ ساختگی که **اندازهٔ هر درخواست** را ثبت می‌کند.
     *
     * @param  callable|null  $reject  دسته‌هایی که باید ۷۰۱ بگیرند (بر اساسِ شمارهٔ دسته)
     */
    private function recordingRegistrar(?callable $reject = null): void
    {
        $sizes = [];
        $n = 0;

        Http::fake(function ($request) use (&$sizes, &$n, $reject) {
            if (str_contains($request->url(), '/auth/login')) {
                return Http::response(['code' => 0, 'data' => ['token' => 'tok']], 200);
            }

            $domains = (array) (($request->data()['domains']) ?? []);
            $sizes[] = count($domains);
            $i = $n++;

            if ($reject !== null && $reject($i)) {
                // شکلِ واقعیِ پاسخِ رجیسترار: HTTP 200 با کدِ خطا در بدنه
                return Http::response([
                    'code' => 701,
                    'desc' => 'The domains limit exceeded. Please send less domains in one request.',
                ], 200);
            }

            return Http::response(['code' => 0, 'data' => ['results' => array_map(
                fn ($d) => [
                    'domain' => $d['name'].'.'.$d['extension'], 'status' => 'free',
                    'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']],
                ],
                $domains
            )]], 200);
        });
    }

    // ═══════════════ ۱ و ۲) اندازه و تعدادِ درخواست ═══════════════

    /**
     * 🔴 ادعای مرکزی: جستجوی پیش‌فرضِ پنل دیگر ۶۴ دامنه در یک درخواست نمی‌فرستد.
     *
     * ⚠️ عمداً `search()` بی‌آرگومانِ دوم صدا زده می‌شود — دقیقاً همان‌طور که
     * `Account\DomainController::index()` صدا می‌زند. اگر تست خودش فهرستِ
     * کوتاهی بدهد، چیزی دربارهٔ خرابیِ واقعی ثابت نمی‌کند.
     */
    public function test_the_default_panel_search_never_sends_more_than_one_batch_per_request(): void
    {
        $sizes = [];

        Http::fake(function ($request) use (&$sizes) {
            if (str_contains($request->url(), '/auth/login')) {
                return Http::response(['code' => 0, 'data' => ['token' => 'tok']], 200);
            }

            $domains = (array) (($request->data()['domains']) ?? []);
            $sizes[] = count($domains);

            return Http::response(['code' => 0, 'data' => ['results' => array_map(
                fn ($d) => [
                    'domain' => $d['name'].'.'.$d['extension'], 'status' => 'free',
                    'price' => ['reseller' => ['price' => 10.0, 'currency' => 'USD']],
                ],
                $domains
            )]], 200);
        });

        $out = app(DomainSearch::class)->search('servernettest');

        $this->assertNotEmpty($sizes, 'هیچ استعلامی نرفت — تست چیزی نمی‌سنجد');

        $this->assertSame([], array_values(array_filter($sizes, fn ($n) => $n > DomainSearch::BATCH)),
            'دسته‌ای بزرگ‌تر از BATCH فرستاده شد — همان چیزی که code 701 می‌گیرد. اندازه‌ها: '
            .implode(',', $sizes));

        // ⚠️ سقفِ تعدادِ درخواست هم بخشی از قرارداد است: حساب از قبل به‌خاطرِ
        //    تماسِ زیاد علامت خورده، پس «دسته‌ای کوچک‌تر» نباید به «خیلی
        //    بیشتر تماس» ترجمه شود.
        $this->assertLessThanOrEqual((int) ceil(count($out) / DomainSearch::BATCH), count($sizes),
            'تعدادِ درخواست بیشتر از حدِ لازم است');

        $this->assertCount(count($out), array_unique(array_column($out, 'domain')),
            'ادغامِ دسته‌ها ردیفِ تکراری ساخت');
    }

    /** هر پسوندی که پرسیده شد، دقیقاً یک ردیف در خروجی دارد و ترتیب حفظ می‌شود */
    public function test_every_asked_extension_comes_back_exactly_once_and_in_order(): void
    {
        $this->recordingRegistrar();

        $tlds = ['com', 'net', 'org', 'shop', 'io', 'dev', 'app', 'ai', 'cloud', 'site', 'store', 'blog'];

        $out = app(DomainSearch::class)->search('servernettest', $tlds);

        $this->assertSame($tlds, array_column($out, 'tld'),
            'ترتیبِ درخواست باید حفظ شود — کاربر همان ترتیبی را می‌بیند که ما چیده‌ایم');
    }

    // ═══════════════ ۳) شکستِ جزئی، حقیقتِ ردیفی ═══════════════

    /**
     * 🔴 مهم‌ترین قیدِ این تغییر.
     *
     * شکلِ قبلی روی هر شکستی **همهٔ** ردیف‌ها را «استعلام نشد» می‌کرد. با
     * دسته‌بندی، همان رفتار یعنی یک قطعیِ لحظه‌ای روی دستهٔ دوم، ۶۰ ردیفِ سالم
     * را هم دور می‌ریزد — یعنی دسته‌بندی وضع را **بدتر** می‌کرد.
     *
     * ⚠️ جهتِ خطا هم سنجیده می‌شود: ردیفِ استعلام‌نشده هرگز نباید «آزاد» یا
     * «ثبت‌شده» خوانده شود.
     */
    public function test_one_failed_batch_only_marks_its_own_rows_unchecked(): void
    {
        $this->recordingRegistrar(fn (int $i) => $i === 1);   // فقط دستهٔ دوم

        $tlds = ['com', 'net', 'org', 'shop', 'io', 'dev', 'app', 'ai', 'cloud', 'site',   // دستهٔ ۱
            'store', 'blog', 'tech', 'space', 'online'];                                    // دستهٔ ۲

        $out = app(DomainSearch::class)->search('servernettest', $tlds);
        $byTld = collect($out)->keyBy('tld');

        foreach (['com', 'net', 'org', 'shop', 'io', 'dev', 'app', 'ai', 'cloud', 'site'] as $ok) {
            $this->assertSame(DomainSearch::STATE_FREE, $byTld[$ok]['state'],
                '«'.$ok.'» در دستهٔ سالم بود ولی حقیقتش دور ریخته شد');
        }

        foreach (['store', 'blog', 'tech', 'space', 'online'] as $bad) {
            $this->assertSame(DomainSearch::STATE_UNCHECKED, $byTld[$bad]['state'],
                '«'.$bad.'» در دستهٔ شکست‌خورده بود و باید «استعلام نشد» باشد');
            $this->assertFalse((bool) $byTld[$bad]['orderable'],
                'ردیفِ استعلام‌نشده هرگز نباید قابلِ سفارش باشد — یعنی فروختنِ چیزی که نمی‌دانیم آزاد است');
        }
    }

    /** و بنرِ «استعلام کامل نشد» بالا می‌آید، چون یک دسته واقعاً شکست خورد */
    public function test_a_partial_failure_still_reports_that_something_went_wrong(): void
    {
        $this->recordingRegistrar(fn (int $i) => $i === 0);

        $svc = app(DomainSearch::class);
        $svc->search('servernettest', ['com', 'net', 'org', 'shop', 'io', 'dev', 'app', 'ai', 'cloud', 'site', 'store']);

        $this->assertFalse($svc->lookupOk(),
            'شکستِ یک دسته باید دیده شود — سکوت یعنی مشتری فکر می‌کند نتیجه کامل است');
        $this->assertSame('lookup_failed', $svc->lookupReason());
    }

    // ═══════════════ ۴) گلوگاهِ «پاسخِ ناقص» سیل نمی‌شود ═══════════════

    /**
     * 🔴 تلهٔ ظریفِ دسته‌بندی.
     *
     * سنجهٔ «کمتر از آنچه پرسیدیم جواب داد» با **کلِ** فهرست مقایسه می‌شد. با
     * دسته‌بندی، دستهٔ اول همیشه کمتر از کلِ فهرست جواب می‌دهد — یعنی این
     * یادداشت روی **هر** جستجو شلیک می‌شد و پنجرهٔ ۴۰۰ خطیِ ردیاب را می‌شست
     * (همان سیلِ ۴۰۴ که یک بار خطاهای واقعی را بیرون انداخت).
     */
    public function test_a_complete_answer_across_batches_raises_no_incomplete_warning(): void
    {
        $this->recordingRegistrar();

        app(DomainSearch::class)->search('servernettest', [
            'com', 'net', 'org', 'shop', 'io', 'dev', 'app', 'ai', 'cloud', 'site', 'store', 'blog',
        ]);

        $notes = collect(ErrorTracker::recent(200, 'error'))
            ->map(fn ($e) => (string) ($e['message'] ?? ''))
            ->filter(fn ($m) => str_contains($m, 'answered fewer domains'));

        $this->assertCount(0, $notes,
            'یادداشتِ «پاسخِ ناقص» روی یک جستجوی کاملاً موفق شلیک شد — ردیاب پر می‌شود');
    }

    // ═══════════════ ۵) پروندهٔ جدا: code 199 · domains=1 ═══════════════

    /**
     * 🔴 پسوندهایی که **وجود ندارند** و خودمان می‌ساختیم.
     *
     * `CatalogController` پسوند را با بریدنِ اولین واژهٔ **نامِ بازاریابیِ** پلن
     * می‌ساخت. روی `.com` درست بود؛ روی «IDN .com» می‌شد `idn`، روی «Starter»
     * می‌شد `starter`. یعنی `sn7price9check4base.idn` را از رجیسترار می‌پرسیدیم
     * و او `code 199 · An unknown error has occurred! · domains=1` می‌داد —
     * روی حسابی که از قبل به‌خاطرِ تماسِ زیاد علامت خورده.
     *
     * ⚠️ عمداً `$tldOf` واقعیِ کنترلر سنجیده می‌شود، نه یک کپیِ محلی: کپی، فقط
     * ادعای تست را ثابت می‌کند نه رفتارِ کد را.
     */
    public function test_marketing_plan_names_never_become_fake_tlds(): void
    {
        $tldOf = $this->catalogTldResolver();

        // نام‌هایی که واقعاً در config/catalog/domain.php هستند
        $this->assertSame('com', $tldOf(['name' => '.com']));
        $this->assertSame('ir', $tldOf(['name' => '.ir ×5']));
        $this->assertSame('co.ir', $tldOf(['name' => '.co.ir']));

        // و آن‌هایی که پسوند **نیستند**
        foreach (['Starter', 'Business', 'Enterprise', 'Single', 'Pack ×5', 'Monitor'] as $bundle) {
            $this->assertSame('', $tldOf(['name' => $bundle]),
                '«'.$bundle.'» نامِ بستهٔ فروش است، نه پسوند — نباید از رجیسترار پرسیده شود');
        }

        // «IDN .com» نامش با پسوندش نمی‌خوانَد، پس کلیدِ صریح می‌گیرد
        $this->assertSame('', $tldOf(['name' => 'IDN .com']),
            'بی‌کلیدِ صریح باید کنار گذاشته شود، نه اینکه «idn» بسازد');
        $this->assertSame('com', $tldOf(['name' => 'IDN .com', 'tld' => 'com']));
    }

    /** و همان کلیدِ صریح واقعاً در کاتالوگ نشسته — وگرنه قیمتِ زندهٔ آن پلن می‌رود */
    public function test_the_persian_idn_plan_carries_an_explicit_tld_in_the_catalogue(): void
    {
        $plans = config('catalog.domain.persian.plans', config('catalog/domain')['persian']['plans'] ?? []);

        $idn = collect($plans)->firstWhere('name', 'IDN .com');

        $this->assertNotNull($idn, 'پلنِ «IDN .com» از کاتالوگ رفته — این تست را به‌روز کن');
        $this->assertSame('com', $idn['tld'] ?? null,
            'بی‌این کلید، صفحهٔ /domain/persian دوباره «.idn» را از رجیسترار می‌پرسد');
    }

    /**
     * صفحهٔ بازاریابی که هیچ پسوندِ واقعی ندارد، **اصلاً** رجیسترار را صدا نمی‌زند.
     *
     * این نیمهٔ دومِ رفع است: فیلترکردنِ آشغال بی‌فایده است اگر با فهرستِ خالی
     * باز هم یک تماس برود.
     */
    public function test_a_page_with_no_real_tlds_never_touches_the_registrar(): void
    {
        Http::fake(fn () => Http::response(['code' => 0, 'data' => ['results' => []]], 200));

        $this->get('/domain/reseller')->assertOk();

        Http::assertNothingSent();
    }

    /**
     * `$tldOf`ِ **واقعیِ** کنترلر را از سورس بیرون می‌کشد.
     *
     * ⚠️ چرا از سورس و نه کپی: تستی که منطق را دوباره می‌نویسد، فقط خودش را
     * می‌سنجد. اگر روزی امضای این closure عوض شود، این تست به‌درستی می‌شکند و
     * کسی مجبور می‌شود دوباره نگاهش کند.
     */
    private function catalogTldResolver(): callable
    {
        $src = (string) file_get_contents((new \ReflectionClass(CatalogController::class))->getFileName());

        $this->assertTrue(
            (bool) preg_match('~\$tldOf = (function \(\$p\): string \{.*?\};)~s', $src, $m),
            'شکلِ $tldOf در CatalogController عوض شده — این تست باید بازبینی شود'
        );

        return eval('return '.$m[1]);
    }
}
