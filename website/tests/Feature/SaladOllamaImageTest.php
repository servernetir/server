<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\SaladOllamaImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 «همیشه تازه‌ترین Ollama» — و بی‌آنکه یک سکسکهٔ رجیستری محصول را بخواباند.
 *
 * ═══ رخداد (۱ مهر ۱۴۰۵، تیکت TK-260923-0856) ═══
 * ایمیجِ برنامهٔ آمادهٔ Ollama در کد سفت شده بود روی یک recipe از
 * ۲۰۲۴-۰۹-۱۹. مشتری RTX 3090 ساعتی خرید تا qwen3:8b اجرا کند و Ollama گفت
 * «به نسخهٔ جدیدتر نیاز است». این خط سرویس SSH ندارد، پس نه ارتقا ممکن بود
 * نه تعویضِ ایمیج. تحویل از دیدِ ما «موفق» بود و هیچ خطایی ثبت نشد.
 */
class SaladOllamaImageTest extends TestCase
{
    use RefreshDatabase;

    private function hub(array $tags): void
    {
        Http::fake(['hub.docker.com/*' => Http::response([
            'results' => array_map(fn ($t) => ['name' => $t], $tags),
        ], 200)]);
    }

    public function test_it_picks_the_newest_stable_version(): void
    {
        $this->hub(['0.9.1', '0.24.0', '0.16.2', '0.17.6']);

        $this->assertSame('0.24.0', app(SaladOllamaImage::class)->refresh());
        $this->assertSame('saladtechnologies/ollama:0.24.0', app(SaladOllamaImage::class)->ref());
    }

    /**
     * ⚠️ مرتب‌سازیِ رشته‌ای «0.9.1» را بزرگ‌تر از «0.24.0» می‌خوانَد — یعنی
     * دقیقاً برعکسِ چیزی که می‌خواهیم، و بی‌هیچ خطایی.
     */
    public function test_versions_are_compared_numerically_not_alphabetically(): void
    {
        $this->hub(['0.24.0', '0.9.1']);

        $this->assertSame('0.24.0', app(SaladOllamaImage::class)->refresh());
    }

    /** نسخهٔ آزمایشی و بیلدِ AMD نباید به مشتری برسد */
    public function test_pre_release_and_variant_tags_are_ignored(): void
    {
        $this->hub(['0.24.0', '0.25.0-rc1', '0.26.0-rocm', 'latest', 'main']);

        $this->assertSame('0.24.0', app(SaladOllamaImage::class)->refresh());
    }

    /**
     * 🔴 شکستِ خواندن هرگز «نسخهٔ تازه‌ای نیست» نیست. اگر این‌جا سقوط کنیم،
     * ماه‌ها روی نسخهٔ کهنه می‌مانیم و کسی خبردار نمی‌شود — همان خرابیِ اصلی.
     */
    public function test_a_registry_outage_keeps_the_last_known_version(): void
    {
        Setting::put(SaladOllamaImage::SETTING, '0.30.0');
        Http::fake(['hub.docker.com/*' => Http::response('', 503)]);

        $this->assertSame('0.30.0', app(SaladOllamaImage::class)->refresh());
        $this->assertSame('saladtechnologies/ollama:0.30.0', app(SaladOllamaImage::class)->ref());
    }

    /** فهرستِ بی‌معنا نباید ما را عقب ببرد */
    public function test_a_junk_listing_never_drops_below_the_floor(): void
    {
        $this->hub(['0.1.0', 'nightly']);

        $this->assertSame(SaladOllamaImage::FLOOR, app(SaladOllamaImage::class)->refresh());
    }

    public function test_it_never_ships_the_stale_image_that_caused_the_ticket(): void
    {
        Http::fake(['hub.docker.com/*' => Http::response('', 500)]);

        $ref = app(SaladOllamaImage::class)->ref();

        $this->assertStringNotContainsString('llama3.1-recipe', $ref,
            'ایمیجِ سپتامبر ۲۰۲۴ برگشته — qwen3 و هر مدلِ بعد از آن pull نمی‌شود.');
        $this->assertTrue(version_compare(explode(':', $ref)[1], '0.6.6', '>='),
            'نسخهٔ تحویلی از کمینهٔ لازم برای qwen3 پایین‌تر است.');
    }

    /**
     * 🔴 مهم‌ترین ادعای این فایل.
     *
     * ردیفِ `cloud_images`ِ مشتری با تگِ روزِ خرید ذخیره می‌شود. وقتی کاتالوگ
     * فردا به نسخهٔ بعدی برود، تطبیقِ **رشته‌ای** دیگر نمی‌خوانَد و `appFor()`
     * نال می‌دهد: نه `OLLAMA_HOST=::` تزریق می‌شود (کانتینر فقط IPv4 گوش
     * می‌دهد و پشتِ دروازهٔ IPv6 بی‌صدا تایم‌اوت می‌شود) و نه پورتِ ۱۱۴۳۴ باز
     * می‌شود. یعنی اولین ارتقای نسخه، سفارش‌های روی ردیفِ قدیمی را می‌کشت.
     */
    public function test_any_tag_of_our_repo_still_matches_the_app(): void
    {
        foreach (['0.9.1', '0.24.0', '9.99.9'] as $tag) {
            $this->assertTrue(SaladOllamaImage::isOurs('saladtechnologies/ollama:'.$tag),
                "تگِ {$tag} دیگر به برنامهٔ Ollama تطبیق داده نمی‌شود.");
        }

        $this->assertFalse(SaladOllamaImage::isOurs('saladtechnologies/comfyui:x'));
        $this->assertFalse(SaladOllamaImage::isOurs('saladtechnologies/ollama-llama3.1-recipe:1.0.0'),
            'مخزنِ recipe جداست و نباید با مخزنِ تازه یکی گرفته شود.');
    }

    /** نامِ میزبانِ پوردار نباید با تگ اشتباه شود */
    public function test_a_registry_port_is_not_mistaken_for_a_tag(): void
    {
        $this->assertSame('reg.example.com:5000/team/app',
            SaladOllamaImage::repoOf('reg.example.com:5000/team/app'));
        $this->assertSame('reg.example.com:5000/team/app',
            SaladOllamaImage::repoOf('reg.example.com:5000/team/app:2.1.0'));
    }
}
