<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ کاربردیِ «سرور مجازی ربات دیسکورد» — /vps/discord (fa / en / tr).
 *
 * ═══ چرا ═══
 *
 * دادهٔ GSC (سه ماه منتهی به ۲۲ سپتامبر ۲۰۲۶): `/blog/iranian-discord-servers`
 * با ۱۱۱۴ نمایش و ۶۵ کلیک در رتبهٔ ۶.۵ — قوی‌ترین صفحهٔ غیربرندِ سایت، بدونِ
 * هیچ مسیری به محصول. این صفحه مقصدِ آن نیت است و پستِ بلاگ حالا مستقیم به
 * آن لینک می‌دهد.
 *
 * قفل‌ها:
 *   ۱) سه زبان بالا می‌آید و کلیدِ خامِ config چاپ نمی‌شود
 *   ۲) هیچ قیمتِ ثابتی ندارد (پلن‌های زنده فقط برای اسلاگِ کشوری‌اند)
 *   ۳) 🔴 مرزِ /aup — نه ادعای VPN/پروکسی، نه «دور زدن»
 *   ۴) پلِ پست→محصول واقعاً به همین صفحه می‌رسد و نگاشتِ دسته‌ای را نمی‌شکند
 */
class DiscordVpsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_in_three_languages_with_the_transactional_title(): void
    {
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $html = $this->get($prefix.'/vps/discord?qa=1')->assertOk()->getContent();

            $this->assertStringNotContainsString('catalog.vps.discord', $html);
            $this->assertSame(1, preg_match_all('~<h1[\s>]~', $html), "$locale: باید دقیقاً یک h1 باشد");

            $cfg = config('catalog.vps.discord.'.$locale);
            $this->assertStringContainsString(e($cfg['seo_t']), $html, "$locale: seo_t در صفحه نیست");
            $this->assertStringContainsString(e($cfg['seo_d']), $html, "$locale: seo_d در صفحه نیست");
        }

        $this->get('/vps/discord?qa=1')->assertSee('ربات دیسکورد');
        $this->get('/en/vps/discord?qa=1')->assertSee('Discord bot', false);
    }

    /** 🔴 عددِ دستی این‌جا = قیمتی که با کاتالوگ جلو نمی‌آید */
    public function test_the_page_carries_no_hand_typed_price(): void
    {
        $cfg = config('catalog.vps.discord');

        $this->assertSame([], $cfg['plans'] ?? [], 'صفحهٔ کاربردی نباید پلنِ ثابت داشته باشد');

        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        $this->assertDoesNotMatchRegularExpression('~\d[\d,٬]{4,}\s*(تومان|تومن|IRT|€)~u', $json, 'قیمتِ سخت‌کد در متنِ صفحه');
    }

    public function test_it_makes_no_circumvention_claim(): void
    {
        $banned = '~(vpn|وی\s*پی\s*ان|فیلترشکن|فیلتر\s*شکن|proxy|پروکسی|v2ray|دور\s*زدن|تحریم\s*شکن|bypass|circumvent)~iu';

        $this->assertDoesNotMatchRegularExpression($banned, json_encode(config('catalog.vps.discord'), JSON_UNESCAPED_UNICODE));

        foreach (['', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix.'/vps/discord?qa=1')->getContent();
            preg_match('~<main[^>]*>(.*)</main>~s', $html, $main);
            $this->assertDoesNotMatchRegularExpression($banned, strip_tags($main[1] ?? ''), $prefix);
        }
    }

    public function test_the_blog_post_bridge_points_at_this_page_without_breaking_the_category_map(): void
    {
        $byPost = blog_related_product('tech', 'iranian-discord-servers');

        $this->assertNotNull($byPost);
        $this->assertSame(lroute('catalog', ['category' => 'vps', 'slug' => 'discord']), $byPost['href']);

        // پستِ بی‌نگاشتِ اختصاصی باید همان مسیرِ دسته‌ای را برود
        $byCategory = blog_related_product('tech', 'some-other-post');
        $this->assertNotNull($byCategory);
        $this->assertNotSame($byPost['href'], $byCategory['href']);

        // و فراخوانِ قدیمی (بی‌اسلاگ) نباید بشکند
        $this->assertNotNull(blog_related_product('tech'));
    }

    public function test_it_is_reachable_from_the_menu_and_the_sitemap(): void
    {
        $this->assertStringContainsString("'slug' => 'discord'", file_get_contents(config_path('servernet.php')));
        $this->get('/sitemap.xml')->assertOk()->assertSee('/vps/discord', false);
    }
}
