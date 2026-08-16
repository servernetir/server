<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * صفحاتِ **محتوایی** هم باید باز شوند و لینک‌هایشان سالم باشد.
 *
 * ═══ شکافی که این تست پر می‌کند ═══
 *
 * `InternalLinksResolveTest` پیمایش را از روت‌های **بی‌پارامتر** شروع می‌کند.
 * یعنی `blog/{slug}`، `docs/{slug}`، `servers/{slug}`، `solutions/{slug}` و
 * `{category}/{slug}` — که روی هم بیش از هزار آدرسِ سایت‌اند و بدنهٔ اصلیِ
 * محتوا — هرگز باز نمی‌شدند. یک لینکِ شکسته **داخلِ قالبِ سند یا پست** از دیدِ
 * آن تست کاملاً پنهان بود، در حالی که روی ۹۸ پست تکرار می‌شد.
 *
 * دقیقاً همان چیزی که ممیزی زیرِ عنوانِ «۵۸ لینکِ شکسته» گزارش کرد.
 *
 * ═══ چرا از sitemap، نه از فهرستِ دستیِ اسلاگ ═══
 *
 * 🔴 فهرستِ دستی همان روزی که نوعِ محتوای تازه‌ای اضافه شود کهنه می‌شود، و
 * چون تست همچنان سبز است کسی نمی‌فهمد. `sitemap.xml` از همان مخزن‌هایی ساخته
 * می‌شود که خودِ صفحات؛ پس نوعِ محتوای تازه **خودبه‌خود** زیرِ پوشش می‌آید.
 *
 * ⚠️ و اگر روزی sitemap خالی یا ناقص شود، این تست هم آن را می‌گیرد — پایینش
 * کفِ تعداد گذاشته شده.
 *
 * ═══ چرا نمونه‌برداری، و چرا قطعی ═══
 *
 * باز کردنِ هر ۱۱۰۰ آدرس تستِ کندی می‌سازد که در CI حذف می‌شود. ولی هدف
 * **قالب** است نه تک‌تکِ ردیف‌ها: یک لینکِ خرابِ داخلِ قالبِ پست روی همهٔ
 * پست‌ها یکسان است. پس از هر الگوی روت چند نمونه برداشته می‌شود.
 *
 * 🔴 نمونه‌برداری **قطعی** است (اول، وسط، آخر) نه تصادفی. تستی که هر اجرا
 * چیزِ دیگری بسنجد، بارِ دوم که قرمز شود «فلیکی» خوانده و نادیده می‌شود.
 *
 * ⚠️ فقط لینکِ **داخلی** سنجیده می‌شود. درسِ ثبت‌شده در
 * `InternalLinksResolveTest`: میزبان‌های بیرونی به ربات پاسخِ غیر‌۲۰۰ می‌دهند
 * (لینکدین کدِ ۹۹۹) و قضاوتشان با درخواستِ خودکار مثبتِ کاذب می‌سازد.
 */
class ContentPagesLinkSweepTest extends TestCase
{
    /*
    | `RefreshDatabase` لازم است: بی‌مهاجرت، صفحاتی که به جدول می‌خورند
    | (`servers/{slug}` از `physical_servers`) ۵۰۰ می‌دهند و تست «لینکِ شکسته»
    | گزارش می‌کند که فقط نبودِ جدول است.
    */
    use RefreshDatabase;

    /** حداکثر نمونه از هر الگوی روت. */
    private const PER_PATTERN = 3;

    /**
     * 🔴 محتوا باید **کاشته** شود، وگرنه این نگهبان تو‌خالی است.
     *
     * بلاگ و پایگاه دانش از `posts` + `post_translations` می‌آیند و در محیطِ
     * تست این جدول‌ها خالی‌اند. نتیجه: `sitemap.xml` هیچ آدرسِ مقاله‌ای ندارد و
     * `/docs/{slug}` و `/blog/{slug}` هر دو ۴۰۴ می‌دهند — یعنی دقیقاً همان دو
     * قالبی که روی صدها صفحه تکرار می‌شوند هرگز رندر نمی‌شدند.
     *
     * ⚠️ این را با تستِ جهش فهمیدم نه با خواندنِ کد: یک `<a>` شکسته را عمداً
     * داخلِ `docs-article.blade.php` گذاشتم و نسخهٔ اولِ همین تست **سبز ماند**.
     * نگهبانی که ندیده‌ای قرمز شود، نگهبان نیست.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedArticle('blog', 'guard-sweep-blog-post', 'پستِ نمونهٔ نگهبان');
        $this->seedArticle('kb', 'guard-sweep-doc', 'سندِ نمونهٔ نگهبان');
    }

    /** یک مقالهٔ منتشرشده با ترجمهٔ هر سه زبان. */
    private function seedArticle(string $type, string $slug, string $title): void
    {
        $post = Post::create([
            'slug'         => $slug,
            'type'         => $type,
            'category'     => $type === 'kb' ? 'getting-started' : 'hosting',
            'status'       => 'published',
            'published_at' => now()->subDay(),
        ]);

        // هر سه زبان: نبودِ ترجمه یعنی صفحهٔ en/tr به فارسی برمی‌گردد و
        // پیمایشِ آن زبان چیزی را که واقعاً سرو می‌شود نمی‌سنجد.
        foreach (['fa', 'en', 'tr'] as $locale) {
            PostTranslation::create([
                'post_id' => $post->id,
                'locale'  => $locale,
                'title'   => $title.' ('.$locale.')',
                'excerpt' => 'خلاصهٔ کوتاه برای پیمایشِ نگهبان.',
                'content' => '<p>متنِ نمونه.</p>',
                'tags'    => ['guard'],
            ]);
        }
    }

    /**
     * الگوهایی که عمداً پیمایش نمی‌شوند.
     *
     * ⚠️ هیچ‌کدام «صفحهٔ محتوایی» نیستند: یا توکن می‌خواهند، یا کنشِ مدیریتی‌اند،
     * یا عمداً ۴۱۰ می‌دهند. اضافه کردن به این فهرست یعنی از پوشش خارج کردن، پس
     * هر ردیف باید دلیلِ نوشته داشته باشد.
     */
    private const SKIP = [
        'report/{token}',              // لینکِ یک‌بارمصرفِ گزارش
        'report/unsubscribe/{token}',  // همان
        'payment/callback/{gateway}',  // درگاه صدا می‌زند، نه کاربر
        'blog/mod/{comment}/{action}', // کنشِ مدیریتی
        'storage/{path}',              // فایل، نه صفحه
        'panel-preview/{any?}',        // عمداً ۴۱۰ — `InternalLinksResolveTest` می‌سنجدش
    ];

    /** @var array<string,string>|null نگاشتِ آدرس ⇒ الگوی روت */
    private ?array $sitemapUrls = null;

    /** همهٔ آدرس‌های sitemap، به مسیرِ نسبی. */
    private function sitemapPaths(): array
    {
        $res = $this->get('/sitemap.xml');
        $res->assertOk();

        preg_match_all('~<loc>([^<]+)</loc>~', $res->getContent(), $m);

        $paths = [];
        foreach ($m[1] as $loc) {
            $p = parse_url(html_entity_decode($loc), PHP_URL_PATH) ?: '/';
            $paths[$p] = true;
        }

        return array_keys($paths);
    }

    /**
     * آدرس‌ها را زیرِ الگوی روتی که واقعاً می‌سازدشان گروه می‌کند.
     *
     * گروه‌بندی روی **الگو** است نه روی پیشوندِ آدرس: `/hosting/linux` و
     * `/servers/hp-dl380` هر دو از `{category}/{slug}` می‌آیند یا نمی‌آیند، و
     * فقط روتر می‌داند کدام.
     */
    private function byPattern(): array
    {
        if ($this->sitemapUrls !== null) {
            return $this->sitemapUrls;
        }

        $groups = [];

        foreach ($this->sitemapPaths() as $path) {
            $pattern = $this->patternFor($path);

            if ($pattern === null || in_array($pattern, self::SKIP, true)) {
                continue;
            }

            $groups[$pattern][] = $path;
        }

        ksort($groups);

        return $this->sitemapUrls = $groups;
    }

    /**
     * الگوی روتی که این مسیر به آن می‌خورد — با **خودِ روترِ لاراول**.
     *
     * 🔴 نسخهٔ اول این را با رگکسِ دست‌ساز روی `$route->uri()` حساب می‌کرد و
     * غلط بود: `/en/domains` زیرِ `{category}/{slug}` گروه می‌شد چون آن الگو
     * زودتر در فهرست می‌آمد. ترتیبِ تطبیقِ لاراول با ترتیبِ پیمایشِ من یکی
     * نیست، و گروه‌بندیِ غلط یعنی نمونه‌برداری از قالبی که فکر می‌کنم دارم
     * می‌سنجم انجام نمی‌شود.
     */
    private function patternFor(string $path): ?string
    {
        /*
        | ⚠️ فقط `HttpException` — یعنی «روتی برای این مسیر نیست»، که پاسخِ
        | معتبر است. نسخهٔ اول این‌جا `\Throwable` می‌گرفت و وقتی importِ
        | `Request` جا افتاده بود، خطای «کلاس پیدا نشد» را بلعید و **کلِ
        | پیمایش را بی‌صدا خالی کرد**؛ تست فقط شکایت کرد «لینکِ کافی جمع نشد»
        | که هیچ ربطی به علتِ واقعی نداشت.
        */
        try {
            return Route::getRoutes()
                ->match(Request::create($path, 'GET'))
                ->uri();
        } catch (HttpException) {
            return null;
        }
    }

    /** نمونهٔ قطعی: اول، وسط، آخر. */
    private function sample(array $items): array
    {
        $n = count($items);

        if ($n <= self::PER_PATTERN) {
            return $items;
        }

        sort($items);

        return array_values(array_unique([
            $items[0],
            $items[intdiv($n, 2)],
            $items[$n - 1],
        ]));
    }

    /** `href` را به مسیرِ داخلی تبدیل می‌کند، یا `null` اگر داخلی نیست. */
    private function internalPath(string $href): ?string
    {
        $href = html_entity_decode(trim($href));

        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        foreach (['mailto:', 'tel:', 'javascript:', 'data:'] as $scheme) {
            if (str_starts_with(strtolower($href), $scheme)) {
                return null;
            }
        }

        if (preg_match('~^https?://~i', $href)) {
            $host = parse_url($href, PHP_URL_HOST);
            if ($host === null || $host !== parse_url(config('app.url'), PHP_URL_HOST)) {
                return null;
            }
            $href = parse_url($href, PHP_URL_PATH) ?: '/';
        }

        if (! str_starts_with($href, '/')) {
            return null;
        }

        return strtok($href, '#') ?: '/';
    }

    private function isGated(string $path): bool
    {
        return (bool) preg_match(
            '~^/(en/|tr/)?(account|admin|api|system|login|register|logout)(/|$)~',
            $path
        );
    }

    /**
     * 🔴 نگهبانِ خودِ نگهبان: پوشش واقعاً به قالبِ مقاله می‌رسد.
     *
     * بی‌این، هر خرابی در کاشتِ محتوا یا در ساختِ sitemap این تست را بی‌صدا
     * تو‌خالی می‌کند و بقیهٔ تست‌ها همچنان سبز می‌مانند — بدترین حالتِ ممکن،
     * چون پوششی که وجود ندارد از نبودِ تست هم بدتر است: به آن اعتماد می‌شود.
     */
    public function test_the_sweep_actually_reaches_content_pages(): void
    {
        $groups = $this->byPattern();
        $seen = array_keys($groups);

        foreach (['blog/{slug}', 'docs/{slug}'] as $must) {
            $this->assertContains($must, $seen,
                "قالبِ «{$must}» اصلاً پیمایش نشد. الگوهای دیده‌شده: ".implode(', ', $seen));
        }

        $withParams = array_filter($seen, fn ($p) => str_contains($p, '{'));

        $this->assertGreaterThanOrEqual(6, count($withParams),
            'پیمایش به الگوی پارامتردارِ کافی نرسید — یعنی sitemap لاغر شده یا '
            .'گروه‌بندی خراب است. الگوهای دیده‌شده: '.implode(', ', $seen));

        $total = array_sum(array_map('count', $groups));
        $this->assertGreaterThan(300, $total,
            "sitemap فقط {$total} آدرسِ قابلِ پیمایش داد؛ پیش از این بیش از هزار بود.");
    }

    /**
     * 🔴 هر صفحهٔ محتوایی باید باز شود.
     *
     * ۵۰۰ روی یک پستِ بلاگ یعنی همان قالب روی هر ۹۸ پست خراب است.
     */
    public function test_every_kind_of_content_page_opens(): void
    {
        $failed = [];

        foreach ($this->byPattern() as $pattern => $paths) {
            foreach ($this->sample($paths) as $path) {
                $code = $this->get($path)->getStatusCode();

                if ($code !== 200) {
                    $failed[] = "{$path} → {$code}   (الگو: {$pattern})";
                }
            }
        }

        $this->assertSame([], $failed,
            "صفحهٔ محتوایی که باز نمی‌شود:\n".implode("\n", $failed));
    }

    /**
     * 🔴 و لینک‌های داخلیِ همان صفحات نباید به ۴۰۴ برسند.
     *
     * این همان چیزی است که تا امروز اصلاً سنجیده نمی‌شد.
     */
    public function test_internal_links_inside_content_pages_resolve(): void
    {
        $targets = [];   // مسیر ⇒ صفحه‌ای که از آن آمده

        foreach ($this->byPattern() as $paths) {
            foreach ($this->sample($paths) as $path) {
                $res = $this->get($path);

                if ($res->getStatusCode() !== 200) {
                    continue;   // تستِ بالا جداگانه گزارشش می‌کند
                }

                preg_match_all('~<a[^>]+href="([^"]+)"~i', $res->getContent(), $m);

                foreach ($m[1] as $href) {
                    $target = $this->internalPath($href);

                    if ($target !== null && ! isset($targets[$target])) {
                        $targets[$target] = $path;
                    }
                }
            }
        }

        $this->assertGreaterThan(40, count($targets), 'لینکِ داخلیِ کافی جمع نشد');

        $broken = [];

        foreach ($targets as $target => $from) {
            if ($this->isGated($target)) {
                continue;
            }

            $code = $this->get($target)->getStatusCode();

            /*
            | ۴۱۰ یعنی صفحه عمداً حذف شده. خودش درست است، ولی **لینک داشتنش**
            | غلط است: کاربر روی چیزی کلیک می‌کند که ما خودمان برداشته‌ایم.
            */
            if ($code === 410) {
                $broken[] = "{$target} → ۴۱۰ حذف‌شده ولی هنوز از {$from} لینک دارد";

                continue;
            }

            if ($code >= 400) {
                $broken[] = "{$target} → {$code}   (از {$from})";
            }
        }

        $this->assertSame([], $broken,
            "لینکِ شکسته داخلِ صفحاتِ محتوایی:\n".implode("\n", $broken));
    }
}
