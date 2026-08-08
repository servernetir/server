<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 🔴 «Undefined array key "eur"» — ۱۱۰ بار در ردیابِ زنده.
 *
 * صفحاتِ `/en/domain/*` و `/tr/domain/*` برای **هر بازدیدکنندهٔ خارجی** ۵۰۰
 * می‌دادند، در حالی که نسخهٔ فارسی سالم بود و کسی متوجه نمی‌شد.
 *
 * علت: `CatalogController` برای دامنه عمداً `unset($p['eur'])` می‌کند (قیمتِ
 * دامنه زنده و تومانی از رجیسترار می‌آید و برای هر نام فرق دارد)، ولی شاخهٔ
 * یوروییِ `site_price()` مستقیم `$item['eur']` را می‌خواند.
 *
 * ═══ چرا این فایل لازم بود، در حالی که آن باگ «قبلاً رفع شده» ═══
 *
 * رفعِ قبلی فقط نیمی از مسیر را گرفت: `site_price()` محافظ گرفت، ولی
 * نشانه‌گذاریِ ساختاریافتهٔ **همان صفحه** `?? 0` ماند. یعنی صفحه دیگر ۵۰۰
 * نمی‌داد ولی به گوگل و مدل‌های زبانی می‌گفت دامنه «€۰» است — رایگان. و آن
 * خرابی هیچ ردیابی ندارد؛ هیچ‌کس نمی‌دیدش. کامنتِ خودِ `site_price()` صریح
 * می‌گوید «€۰ یعنی رایگان، و آن از خطا بدتر است» — این تست همان جمله را روی
 * کلِ صفحه اعمال می‌کند، نه فقط روی یک تابع.
 *
 * ⚠️ هیچ تماسِ واقعی: استعلامِ زندهٔ پسوندها fake می‌شود.
 */
class ForeignLocalePriceGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory);
        Http::fake(fn () => Http::response([], 500));

        /*
        | 🔴 این استاب **حیاتی** است و اولین نسخهٔ این تست بی‌آن نوشته شد.
        |
        | وقتی رجیسترار جواب نمی‌دهد، `CatalogController` روی هر پلن
        | `quote = true` می‌گذارد و ویو به‌جای قیمت «استعلام» چاپ می‌کند — یعنی
        | `site_price()` **اصلاً صدا زده نمی‌شود** و تست با گاردِ شکسته هم سبز
        | می‌مانْد. (این را واقعاً سنجیدیم: با خرابکردنِ عمدیِ محافظ، صفحه‌ها
        | همچنان ۲۰۰ می‌دادند.)
        |
        | خرابیِ واقعی وقتی رخ می‌دهد که قیمتِ زنده **هست**: آن‌وقت `irt` ست
        | می‌شود، `eur` حذف شده، و شاخهٔ یوروییِ `site_price()` اجرا می‌شود.
        */
        $this->app->bind(\App\Services\Domain\TldPriceBook::class, fn () => new class
        {
            /** @return array<string,int> */
            public function forTlds(array $tlds): array
            {
                $out = [];

                foreach ($tlds as $t) {
                    $out[strtolower(ltrim(trim((string) $t), '.'))] = 2_016_000;
                }

                return $out;
            }
        });
    }

    public static function pages(): array
    {
        $out = [];

        foreach (['popular-tlds', 'ir', 'persian', 'premium', 'reseller', 'backorder'] as $slug) {
            foreach (['', 'en/', 'tr/'] as $prefix) {
                $out[$prefix.$slug] = [$prefix.'domain/'.$slug];
            }
        }

        return $out;
    }

    /**
     * ۵۰۰ روی صفحهٔ محصول = صفحهٔ سفید برای بازدیدکننده و درآمدِ نرفته.
     *
     * ⚠️ هر سه زبان و **همهٔ** محصولاتِ دامنه: باگِ قبلی فقط در دو زبان دیده
     * می‌شد و برای همین ماه‌ها زنده ماند.
     */
    #[DataProvider('pages')]
    public function test_a_foreign_visitor_never_gets_a_500(string $path): void
    {
        $this->get('/'.$path)->assertOk();
    }

    /**
     * 🔴 نیمهٔ فراموش‌شدهٔ همان رفع.
     *
     * نشانه‌گذاری نباید قیمتِ صفر منتشر کند. «€۰» یعنی رایگان، و مدلِ زبانی که
     * یک بار آن را نقل کند ماه‌ها تکرارش می‌کند.
     */
    #[DataProvider('pages')]
    public function test_no_page_ever_publishes_a_zero_price(string $path): void
    {
        $html = $this->get('/'.$path)->assertOk()->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        foreach ($m[1] as $json) {
            $ld = json_decode($json, true);

            $this->assertIsArray($ld, 'نشانه‌گذاری باید JSONِ معتبر باشد');

            foreach (self::pricesIn($ld) as $price) {
                $this->assertGreaterThan(0, (float) $price,
                    'قیمتِ صفر در نشانه‌گذاری یعنی «رایگان» — از نبودِ قیمت بدتر است ('.$path.')');
            }
        }
    }

    /** همهٔ `price`های تو در تو */
    private static function pricesIn(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $out = [];

        foreach ($node as $k => $v) {
            if ($k === 'price' && is_scalar($v)) {
                $out[] = $v;

                continue;
            }

            $out = array_merge($out, self::pricesIn($v));
        }

        return $out;
    }

    /**
     * محافظِ خودِ تابع، مستقیم — چون همان خط دو بار شکسته است.
     *
     * ⚠️ «—» عمدی است و نه «€۰»: نبودِ قیمت صادقانه‌تر از عددِ ساختگی است.
     */
    public function test_site_price_survives_an_item_without_eur_in_every_locale(): void
    {
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);

            $this->assertIsString(site_price(['irt' => 1290000]));
            $this->assertIsString(site_price_yearly(['irt' => 1290000]));

            // هیچ قیمتی هم که نبود، نباید «۰» چاپ شود
            $this->assertSame('—', site_price([]), 'ارزِ '.$locale);
        }
    }
}
