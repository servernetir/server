<?php

namespace Tests\Feature;

use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * دو تغییرِ سئویی آبان ۱۴۰۵، از گزارش Search Console.
 *
 * ۱) `/vps/discord` ساخته شد — خوشهٔ دیسکورد ~۷۰۰ نمایش در جایگاه ۵.۸ تا ۷.۳
 *    داشت و روی یک مطلبِ بلاگ می‌نشست، و خودِ نشانی در هر سه زبان ۴۰۴ می‌داد
 *    در حالی که `/en/vps/discord` ۸۳ نمایش در AI Overviews گرفته بود.
 *
 * ۲) `/vps/gpu` در `/gpu` ادغام شد — دو صفحهٔ ایندکس‌پذیر سرِ یک موضوع
 *    می‌جنگیدند و عبارت‌های اصلی در جایگاه ۲۵ تا ۵۲ گیر کرده بودند.
 */
class DiscordAndGpuMergeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public static function locales(): array
    {
        return ['fa' => [''], 'en' => ['/en'], 'tr' => ['/tr']];
    }

    // ───────────────────────── دیسکورد ─────────────────────────

    /** @dataProvider locales */
    public function test_the_discord_page_answers_in_every_locale(string $prefix): void
    {
        $this->get($prefix.'/vps/discord')->assertOk();
    }

    /**
     * 🔴 صفحه‌ای که در نقشهٔ سایت نباشد ممکن است هفته‌ها کشف نشود — و کلِ دلیلِ
     * ساختِ این صفحه این بود که گوگل همین نشانی را می‌خواست.
     */
    public function test_the_discord_page_is_in_the_sitemap_three_times(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach (['', '/en', '/tr'] as $prefix) {
            $this->assertStringContainsString(url($prefix.'/vps/discord').'</loc>', $xml);
        }
    }

    /**
     * ⚠️ فهرستِ مگامنو دستی است. صفحه‌ای که از هیچ‌جای سایت لینک نگیرد ممکن
     * است هرگز ایندکس نشود — همان هشداری که §۵ راهنما دربارهٔ `webdesign`
     * ثبت کرده.
     */
    public function test_the_menu_links_to_the_discord_page(): void
    {
        $this->assertStringContainsString(
            'href="'.url('/vps/discord').'"',
            $this->get('/')->assertOk()->getContent()
        );
    }

    /**
     * 🔴 بند ۷ AUP ارائهٔ «سرویس عبور» روی زیرساخت ایران را منع می‌کند، و
     * مستقل از آن، Gateway دیسکورد از داخل ایران در دسترس نیست — پس باتی که
     * روی سرورِ ایران تحویل شود هرگز آنلاین نمی‌شود. صفحه باید همین را صریح
     * بگوید، نه اینکه مبهم رهایش کند و مشتری پول بدهد و بات بالا نیاید.
     */
    public function test_the_discord_page_is_honest_about_iranian_servers(): void
    {
        $html = $this->get('/vps/discord')->assertOk()->getContent();

        $this->assertStringContainsString('Gateway', $html);
        $this->assertStringContainsString('در دسترس نیست', $html);
    }

    /** متنِ FAQ با `{{ }}` رندر می‌شود، پس تگِ HTML در آن عیناً چاپ می‌شود */
    public function test_no_raw_html_tag_leaks_into_the_faq_text(): void
    {
        foreach ((array) config('catalog.vps.discord.faqs') as $faq) {
            if (! is_array($faq)) {
                continue; // کلیدِ مشترک از faq_pool
            }
            foreach (['fa', 'en', 'tr'] as $lang) {
                $answer = (string) ($faq[$lang]['a'] ?? '');
                $this->assertSame(
                    $answer,
                    strip_tags($answer),
                    'متنِ پاسخ نباید تگ HTML داشته باشد: '.$answer
                );
            }
        }
    }

    // ───────────────────────── ادغام GPU ─────────────────────────

    /** @dataProvider locales */
    public function test_the_old_gpu_vps_url_redirects_to_the_gpu_page(string $prefix): void
    {
        $this->get($prefix.'/vps/gpu')
            ->assertStatus(301)
            ->assertRedirect(url($prefix.'/gpu'));
    }

    /** مقصدِ ریدایرکت باید زنده باشد، وگرنه ۳۰۱ به ۴۰۴ ساخته‌ایم */
    public function test_the_gpu_page_itself_still_answers(): void
    {
        $this->get('/gpu')->assertOk();
    }

    /**
     * 🔴 نشانیِ ۳۰۱ در نقشهٔ سایت یعنی گوگل هر بار می‌رود، ریدایرکت می‌گیرد و
     * بودجهٔ خزش را همان‌جا می‌سوزاند — همان درسی که بخش ارومیه ثبت کرده.
     */
    public function test_the_merged_url_is_gone_from_the_sitemap_and_llms(): void
    {
        $this->assertStringNotContainsString(
            '/vps/gpu</loc>',
            $this->get('/sitemap.xml')->assertOk()->getContent()
        );

        $this->assertStringNotContainsString(
            '/vps/gpu)',
            $this->get('/llms.txt')->assertOk()->getContent()
        );
    }

    /**
     * ⚠️ ادعای منفی روی لینکِ داخلی: منو و نوارِ «مرتبط» هم نباید به ۳۰۱
     * بخورند. همان ثابتی که کنترلر برای ریدایرکت می‌خواند، همه‌جا مرجع است.
     */
    public function test_no_internal_link_points_at_the_merged_url(): void
    {
        foreach (['/', '/vps/germany', '/vps/trading'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('/vps/gpu"', $html, $url.' هنوز به ۳۰۱ لینک می‌دهد');
        }
    }

    /** صفحهٔ VPS باید همچنان لینکِ لنگردار به خطِ محصولِ گرافیکی بدهد */
    public function test_vps_pages_still_link_to_the_gpu_product_line(): void
    {
        $this->assertStringContainsString(
            'href="'.url('/gpu').'"',
            $this->get('/vps/trading')->assertOk()->getContent()
        );
    }

    /** ثابت و تابعِ کمکی باید با هم بخوانند — دو تعریفِ موازی نشوند */
    public function test_the_merge_map_is_the_single_source(): void
    {
        $this->assertTrue(CatalogController::mergedToGpu('vps', 'gpu'));
        $this->assertTrue(CatalogController::mergedToGpu('cloud', 'gpuaas'));
        $this->assertFalse(CatalogController::mergedToGpu('vps', 'trading'));
        $this->assertFalse(CatalogController::mergedToGpu('dedicated', 'gpu'));
    }
}
