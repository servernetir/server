<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عنوان و توضیحِ جست‌وجوی صفحاتِ پرنمایشِ کم‌کلیک (Search Console، ۲۴ سپتامبر ۲۰۲۶).
 *
 * ═══ چرا این تست وجود دارد ═══
 *
 * صفحاتی که `seo_t`/`seo_d` ندارند به `t` و `hero_d` سقوط می‌کنند — عنوانِ
 * برندی («هاست ویندوز — سرورنت کلاود») که کلیدواژهٔ تراکنشی ندارد. دادهٔ
 * سه‌ماههٔ GSC: `/hosting/windows` ۶۷۶ نمایش و **صفر** کلیک، `/vps/linux`
 * ۶۴۸ نمایش و صفر کلیک، `/dedicated/france` ۴۳۵ و صفر.
 *
 * ⚠️ `hosting/download` و `cloud/cdn` عمداً «خرید» در عنوان ندارند: مسیرِ
 * خریدشان باز نیست (§۱۶) و عنوانِ تراکنشی برای صفحه‌ای که نمی‌فروشد همان
 * وعدهٔ بی‌پشتوانه است.
 */
class CatalogSeoMetaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0:string,1:array<int,string>}> */
    public static function pages(): array
    {
        return [
            'windows'        => ['/hosting/windows', ['config' => 'hosting.products.windows']],
            'linux'          => ['/hosting/linux', ['config' => 'hosting.products.linux']],
            'download'       => ['/hosting/download', ['config' => 'hosting.products.download']],
            'reseller-linux' => ['/hosting/reseller-linux', ['config' => 'hosting.products.reseller-linux']],
            'russia'         => ['/vps/russia', ['config' => 'catalog.vps.russia']],
            'cdn'            => ['/cloud/cdn', ['config' => 'catalog.cloud.cdn']],
            'france'         => ['/dedicated/france', ['config' => 'catalog.dedicated.france']],
        ];
    }

    public function test_the_page_title_and_description_come_from_seo_keys(): void
    {
        foreach (self::pages() as [$path, $meta]) {
            $this->assertRenders($path, config($meta['config']));
        }
    }

    /** @param  array<string, mixed>  $cfg */
    private function assertRenders(string $path, array $cfg): void
    {
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $html = $this->get($prefix.$path.'?qa=1')->assertOk()->getContent();

            $title = $cfg[$locale]['seo_t'] ?? null;
            $desc = $cfg[$locale]['seo_d'] ?? null;

            $this->assertNotEmpty($title, "$path/$locale بدونِ seo_t");
            $this->assertNotEmpty($desc, "$path/$locale بدونِ seo_d");

            // عنوانِ واقعیِ رندرشده، نه فقط وجودِ کلید در config
            $this->assertStringContainsString(e($title), $html, "$path/$locale: seo_t در <title> نیست");
            $this->assertStringContainsString(e($desc), $html, "$path/$locale: seo_d در متای توضیحات نیست");
        }
    }

    public function test_titles_stay_inside_the_length_google_shows_and_are_unique(): void
    {
        $seen = [];

        foreach (self::pages() as $key => [$path, $meta]) {
            foreach (['fa', 'en', 'tr'] as $locale) {
                $title = config($meta['config'].'.'.$locale.'.seo_t');
                $desc = config($meta['config'].'.'.$locale.'.seo_d');

                $this->assertLessThanOrEqual(65, mb_strlen($title), "$key/$locale: عنوان بلند است");
                $this->assertGreaterThanOrEqual(110, mb_strlen($desc), "$key/$locale: توضیح کوتاه است");
                $this->assertLessThanOrEqual(180, mb_strlen($desc), "$key/$locale: توضیح بلند است");

                // 🔴 عنوانِ تکراریِ هم‌زبان همان چیزی است که site:gate (RG-META-UNIQ-13) قرمز می‌کند
                $this->assertArrayNotHasKey($locale.'|'.$title, $seen, "عنوانِ تکراری: $key/$locale");
                $seen[$locale.'|'.$title] = true;
            }
        }
    }

    /** صفحه‌ای که مسیرِ خرید ندارد نباید عنوانِ «خرید …» بگیرد */
    public function test_pages_without_a_buy_path_do_not_promise_a_purchase(): void
    {
        foreach ([['hosting.products.download', 'هاست دانلود'], ['catalog.cloud.cdn', 'CDN']] as [$cfg, $label]) {
            $this->assertStringNotContainsString('خرید', config($cfg.'.fa.seo_t'), "$label: عنوانِ تراکنشی بدونِ مسیرِ خرید");
            $this->assertStringNotContainsString('Buy', config($cfg.'.en.seo_t'), $label);
            $this->assertStringNotContainsString('Satın Al', config($cfg.'.tr.seo_t'), $label);
        }
    }
}
