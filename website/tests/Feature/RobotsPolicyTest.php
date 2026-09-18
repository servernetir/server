<?php

namespace Tests\Feature;

use App\Support\RobotsPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotsPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_checked_in_robots_file_is_deterministically_generated(): void
    {
        $this->assertSame(
            RobotsPolicy::render((array) config('seo.crawler_policy')),
            file_get_contents(public_path('robots.txt'))
        );
    }

    public function test_generator_writes_the_complete_file_without_leaving_a_temporary_file(): void
    {
        $this->artisan('seo:robots')->assertSuccessful();

        $this->assertFileDoesNotExist(public_path('robots.txt.tmp'));
        $this->assertSame(
            RobotsPolicy::render((array) config('seo.crawler_policy')),
            file_get_contents(public_path('robots.txt'))
        );
    }

    public function test_search_and_training_policies_are_explicit_and_independent(): void
    {
        $policy = (array) config('seo.crawler_policy');
        $policy['search_discovery']['allow'] = true;
        $policy['model_training']['allow'] = false;
        $robots = RobotsPolicy::render($policy);

        foreach (['OAI-SearchBot', 'PerplexityBot', 'Googlebot', 'Bingbot'] as $agent) {
            $group = $this->group($robots, $agent);
            $this->assertStringContainsString("Allow: /\n", $group);
            foreach ($policy['private_paths'] as $privatePath) {
                $this->assertStringContainsString('Disallow: '.$privatePath, $group, $agent.' can crawl '.$privatePath);
            }
        }

        foreach (['GPTBot', 'Google-Extended'] as $agent) {
            $this->assertSame("User-agent: {$agent}\nDisallow: /", $this->group($robots, $agent));
        }
    }

    public function test_an_incomplete_policy_fails_closed_without_overwriting_robots(): void
    {
        $before = file_get_contents(public_path('robots.txt'));
        config(['seo.crawler_policy.private_paths' => []]);

        $this->artisan('seo:robots')->assertFailed();

        $this->assertSame($before, file_get_contents(public_path('robots.txt')));
    }

    public function test_one_malformed_private_path_rejects_the_entire_policy(): void
    {
        $policy = (array) config('seo.crawler_policy');
        $policy['private_paths'] = ['/account', 'admin'];

        $this->expectException(\InvalidArgumentException::class);

        RobotsPolicy::render($policy);
    }

    /**
     * تله‌های خزش در **هر** گروهِ مجاز می‌آیند، از جمله `*`.
     *
     * ⚠️ گوگل فقط **دقیق‌ترین** گروهِ user-agent را می‌خوانَد و بقیه را نادیده
     * می‌گیرد. پس الگویی که فقط در `*` باشد، برای Googlebot (که گروهِ خودش را
     * دارد) عملاً وجود ندارد — دقیقاً همان خزنده‌ای که برایش نوشته شده.
     */
    public function test_crawl_traps_reach_every_allowed_group(): void
    {
        $policy = (array) config('seo.crawler_policy');
        $robots = RobotsPolicy::render($policy);

        $this->assertNotEmpty($policy['crawl_traps']);

        foreach (['Googlebot', 'Bingbot', 'OAI-SearchBot', 'PerplexityBot', '*'] as $agent) {
            $group = $this->group($robots, $agent);
            foreach ($policy['crawl_traps'] as $trap) {
                $this->assertStringContainsString("Disallow: {$trap}\n", $group."\n", $agent.' misses '.$trap);
            }
        }
    }

    /** نمونه‌های واقعیِ همان گزارشِ GSC — اگر الگو این‌ها را نبندد، بی‌اثر است. */
    public function test_crawl_traps_block_the_urls_search_console_kept_recrawling(): void
    {
        foreach ([
            '/?attachment_id=3040',
            '/en/parts/disk?gen=gen12&sort=price_asc',
            '/tr/parts/other?gen=gen11&sort=price_desc',
            '/parts/disk?gen=gen9&sort=price_asc&max=650',
            '/tr/blog?tag=Cloudflare',
            '/blog?page=2&tag=seo',
            '/blog?q=vpn',
        ] as $url) {
            $this->assertTrue($this->blockedByAnyTrap($url), $url.' should be blocked');
        }
    }

    /**
     * 🔴 مهم‌ترین ادعا: هیچ الگویی هیچ صفحهٔ ایندکس‌شدنی را نمی‌بندد.
     *
     * این جهتِ مخالف است و خطرناک‌تر: یک `/*?` به‌جای `/*?tag=` کلِ
     * صفحه‌بندیِ بلاگ و فهرستِ دسته‌ها را از خزش بیرون می‌گذاشت — همان
     * صفحاتی که پشتیبانِ لینکِ ۶۴۴ مقالهٔ «Discovered»اند. هیچ خطایی هم
     * نمی‌داد؛ فقط ایندکس آرام‌آرام کوچک می‌شد.
     *
     * فهرست از **خودِ نقشهٔ سایت** می‌آید نه از فهرستِ دستی، تا نوعِ صفحهٔ
     * تازه‌ای که فردا اضافه شود خودبه‌خود زیرِ پوشش باشد.
     */
    public function test_no_crawl_trap_or_private_path_blocks_an_indexable_url(): void
    {
        $post = \App\Models\Post::create([
            'slug' => 'robots-guard', 'type' => 'blog', 'category' => 'seo',
            'status' => 'published', 'published_at' => now()->subDay(),
        ]);
        foreach (['fa', 'en', 'tr'] as $locale) {
            \App\Models\PostTranslation::create([
                'post_id' => $post->id, 'locale' => $locale, 'title' => 'guard '.$locale,
                'excerpt' => 'x', 'content' => '<p>x</p>', 'tags' => ['seo'],
            ]);
        }

        preg_match_all('~<loc>([^<]+)</loc>~', $this->get('/sitemap.xml')->assertOk()->getContent(), $m);
        $urls = array_map(fn ($u) => html_entity_decode($u, ENT_QUOTES | ENT_XML1), $m[1]);
        $this->assertGreaterThan(50, count($urls), 'sitemap نباید خالی باشد — وگرنه این تست هیچ نمی‌سنجد');

        // صفحاتِ ایندکس‌شدنی‌ای که عمداً در نقشه نیستند ولی باید خزیده شوند
        $mustStayOpen = ['/blog?page=2', '/en/blog?cat=seo&page=3', '/parts/cpu', '/tr/parts/ram'];

        $paths = array_merge($mustStayOpen, array_map(function (string $u): string {
            $parts = parse_url($u);

            return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }, $urls));

        $policy = (array) config('seo.crawler_policy');
        $blocked = [];
        foreach ($paths as $path) {
            foreach ([...$policy['private_paths'], ...$policy['crawl_traps']] as $pattern) {
                if (RobotsPolicy::blocks($pattern, $path)) {
                    $blocked[] = $path.'  ←  '.$pattern;
                }
            }
        }

        $this->assertSame([], $blocked, "robots.txt صفحهٔ ایندکس‌شدنی را می‌بندد:\n".implode("\n", $blocked));
    }

    public function test_a_sitewide_or_non_wildcard_trap_rejects_the_entire_policy(): void
    {
        foreach (['/*', '/*?', '/blog', 'tag=', '//x?y'] as $bad) {
            $policy = (array) config('seo.crawler_policy');
            $policy['crawl_traps'] = [$bad];

            try {
                RobotsPolicy::render($policy);
                $this->fail($bad.' should have been rejected');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_matcher_follows_google_semantics(): void
    {
        $this->assertTrue(RobotsPolicy::blocks('/parts/*?', '/parts/cpu?sort=name'));
        $this->assertFalse(RobotsPolicy::blocks('/parts/*?', '/parts/cpu'), 'بی‌query نباید بسته شود');
        $this->assertFalse(RobotsPolicy::blocks('/parts/*?', '/partsx?y'));
        $this->assertTrue(RobotsPolicy::blocks('/*?tag=', '/en/blog?tag=x'));
        $this->assertFalse(RobotsPolicy::blocks('/*?tag=', '/blog?page=2'));
        $this->assertTrue(RobotsPolicy::blocks('/up', '/up'));
        $this->assertTrue(RobotsPolicy::blocks('/*.pdf$', '/a/b.pdf'));
        $this->assertFalse(RobotsPolicy::blocks('/*.pdf$', '/a/b.pdf?x=1'));
    }

    private function blockedByAnyTrap(string $path): bool
    {
        foreach ((array) config('seo.crawler_policy.crawl_traps') as $pattern) {
            if (RobotsPolicy::blocks($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function group(string $robots, string $agent): string
    {
        preg_match('~User-agent: '.preg_quote($agent, '~')."\n.*?(?=\n\n|\z)~s", $robots, $match);

        return $match[0] ?? '';
    }
}
