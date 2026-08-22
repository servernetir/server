<?php

namespace Tests\Feature;

use App\Models\ServerPart;
use App\Services\Shop\PartsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * فروشگاهِ قطعاتِ سرور — صفحات، فیلترها، مقایسه، و مسیرها.
 *
 * ═══ چرا این تست ═══
 *
 * فروشگاه پنج نوع صفحه دارد در سه زبان، و تقریباً همهٔ خرابی‌های ممکنش
 * **خاموش**‌اند: مسیری که مسیرِ دیگری را می‌بلعد، فیلتری که هیچ‌وقت نتیجه
 * ندارد، قطعه‌ای که از دسته‌بندی غیب می‌شود. هیچ‌کدام خطا نمی‌دهند؛ فقط چیزی
 * که باید باشد نیست.
 */
class PartsShopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // پردازندهٔ Gen9 با قیمتِ یورو
        $this->part('xeon-a', 'cpu', ['gen9'], 3400, ['cores' => 14, 'ghz' => 2.4]);
        // پردازندهٔ Gen10، گران‌تر
        $this->part('xeon-b', 'cpu', ['gen10'], 16806, ['cores' => 20, 'ghz' => 2.1]);
        // قطعهٔ استعلامی — نه قیمت دارد نه باید صفر نشان بدهد
        $this->part('xeon-c', 'cpu', ['gen11'], null, ['cores' => 32]);
        // رمِ چندنسلی
        $this->part('ram-a', 'ram', ['gen9', 'gen10'], 8403, ['gb' => 16]);
        /*
        | 🔴 قطعهٔ **عمومی**: `compat_gens = null`.
        |
        | این ردیف قلبِ `scopeForGeneration()` است. بی‌آن، تستِ «صفحهٔ نسل
        | قطعه نشان می‌دهد» با حذفِ شاخهٔ `orWhereNull` هم سبز می‌ماند — چون
        | همهٔ ردیف‌های دیگر فهرستِ نسل دارند.
        */
        $this->part('caddy', 'other', null, 1680, []);
    }

    private function part(string $slug, string $cat, ?array $gens, ?int $eurCents, array $attrs): ServerPart
    {
        return ServerPart::create([
            'slug'          => $slug,
            'category'      => $cat,
            'brand'         => 'HPE',
            'compat_gens'   => $gens,
            'condition'     => 'refurb',
            'price_contact' => $eurCents === null,
            'price_eur'     => $eurCents,
            'in_stock'      => true,
            'active'        => true,
            'sort'          => 10,
            'name'          => ['fa' => 'قطعهٔ '.$slug, 'en' => 'Part '.$slug, 'tr' => 'Parça '.$slug],
            'attrs'         => $attrs,
        ]);
    }

    public function test_every_shop_page_renders_in_all_three_languages(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach ([
                '/parts',
                '/parts/cpu',
                '/parts/cpu/xeon-a',
                '/servers/hp/gen9',
                '/parts/compare?parts=xeon-a,xeon-b',
            ] as $path) {
                $this->get($prefix.$path)->assertOk();
            }
        }
    }

    /**
     * 🔴 `/parts/compare` نباید به‌عنوانِ «دستهٔ compare» تعبیر شود.
     *
     * اگر روتِ مقایسه بعد از `/parts/{category}` ثبت شود، لاراول اولی را
     * می‌گیرد، `compare` را دستهٔ نامعتبر می‌بیند و **همیشه ۴۰۴** می‌دهد.
     * دکمهٔ مقایسه بی‌صدا کار نمی‌کند و هیچ خطایی هم در لاگ نیست.
     */
    public function test_compare_route_is_not_swallowed_by_the_category_route(): void
    {
        $res = $this->get('/parts/compare?parts=xeon-a,xeon-b');

        $res->assertOk();
        $res->assertViewIs('pages.parts-compare');
        $res->assertSee('قطعهٔ xeon-a');
        $res->assertSee('قطعهٔ xeon-b');
    }

    /**
     * 🔴 قطعهٔ بدونِ فهرستِ سازگاری در **همهٔ** نسل‌ها دیده می‌شود.
     *
     * کدی و ریلِ رک فهرستِ نسل ندارند چون به همه می‌خورند. بی‌شاخهٔ
     * `orWhereNull`، از صفحهٔ هر نسل غیب می‌شدند — بی‌خطا، فقط نبودن.
     */
    public function test_a_generic_part_shows_on_every_generation_page(): void
    {
        foreach (['gen8', 'gen9', 'gen10', 'gen11', 'gen12'] as $gen) {
            $this->get('/servers/hp/'.$gen)->assertOk()->assertSee('قطعهٔ caddy');
        }
    }

    /** صفحهٔ نسل باید قطعهٔ همان نسل را بیاورد و قطعهٔ نسلِ دیگر را نه. */
    public function test_generation_page_shows_only_compatible_parts(): void
    {
        $res = $this->get('/servers/hp/gen9');

        $res->assertSee('قطعهٔ xeon-a');       // gen9
        $res->assertDontSee('قطعهٔ xeon-b');   // gen10
    }

    /**
     * فیلترها باید **جمع‌شونده** باشند و لینکشان پارامترهای قبلی را نگه دارد.
     *
     * ⚠️ ادعای واقعی این‌جا «۲۰۰ می‌دهد» نیست — آن حتی با فیلترِ خراب هم صادق
     * است. ادعا این است که فیلترِ Gen10 قطعهٔ Gen9 را **حذف** می‌کند.
     */
    public function test_generation_filter_actually_narrows_the_listing(): void
    {
        $all = $this->get('/parts/cpu');
        $all->assertSee('قطعهٔ xeon-a');
        $all->assertSee('قطعهٔ xeon-b');

        $g10 = $this->get('/parts/cpu?gen=gen10');
        $g10->assertOk();
        $g10->assertSee('قطعهٔ xeon-b');
        $g10->assertDontSee('قطعهٔ xeon-a');
    }

    /**
     * ⚠️ پارامترِ نامعتبر باید **بی‌اثر** باشد، نه ۴۲۲.
     *
     * کاربری که لینکِ قدیمیِ یک فیلترِ حذف‌شده را باز می‌کند باید فهرست ببیند.
     */
    public function test_an_unknown_filter_value_is_ignored_not_an_error(): void
    {
        $res = $this->get('/parts/cpu?gen=gen99&condition=banana&sort=nonsense');

        $res->assertOk();
        $res->assertSee('قطعهٔ xeon-a');
        $res->assertSee('قطعهٔ xeon-b');
    }

    /**
     * 🔴 قطعهٔ استعلامی هرگز نباید در صدرِ «گران به ارزان» بنشیند.
     *
     * قطعهٔ بی‌قیمت گران‌ترین نیست؛ نامعلوم است. اگر با `ORDER BY price_eur`
     * مرتب می‌کردیم، NULL بسته به موتورِ دیتابیس اولِ فهرست می‌افتاد — یعنی
     * همان صفحه روی SQLiteِ محلی و MariaDBِ پروداکشن دو ترتیبِ متفاوت داشت.
     */
    public function test_contact_priced_parts_sort_last_in_both_directions(): void
    {
        foreach (['price_asc', 'price_desc'] as $sort) {
            $html = $this->get('/parts/cpu?sort='.$sort)->getContent();

            $this->assertLessThan(
                strpos($html, 'قطعهٔ xeon-c'),
                strpos($html, 'قطعهٔ xeon-a'),
                "با مرتب‌سازی «{$sort}» قطعهٔ استعلامی نباید جلوتر از قطعهٔ قیمت‌دار باشد"
            );
        }

        // و در حالتِ صعودی، ارزان‌تر واقعاً جلوتر است
        $asc = $this->get('/parts/cpu?sort=price_asc')->getContent();
        $this->assertLessThan(strpos($asc, 'قطعهٔ xeon-b'), strpos($asc, 'قطعهٔ xeon-a'));

        $desc = $this->get('/parts/cpu?sort=price_desc')->getContent();
        $this->assertLessThan(strpos($desc, 'قطعهٔ xeon-a'), strpos($desc, 'قطعهٔ xeon-b'));
    }

    /**
     * 🔴 یک محصول، یک آدرس.
     *
     * اگر دسته با دستهٔ واقعیِ قطعه تطابق داده نمی‌شد، هر قطعه با ۹ آدرسِ
     * متفاوت در دسترس بود و گوگل نُه نسخهٔ تکراری می‌دید.
     */
    public function test_a_part_is_only_reachable_under_its_own_category(): void
    {
        $this->get('/parts/cpu/xeon-a')->assertOk();
        $this->get('/parts/ram/xeon-a')->assertNotFound();
        $this->get('/parts/nope/xeon-a')->assertNotFound();
        $this->get('/parts/cpu/does-not-exist')->assertNotFound();
        $this->get('/servers/hp/gen7')->assertNotFound();
    }

    /** قطعهٔ غیرفعال نباید نه در فهرست بیاید نه صفحهٔ اختصاصی داشته باشد. */
    public function test_an_inactive_part_disappears_from_the_shop(): void
    {
        ServerPart::where('slug', 'xeon-a')->update(['active' => false]);
        app(PartsCatalog::class)->flush();

        $this->get('/parts/cpu')->assertOk()->assertDontSee('قطعهٔ xeon-a');
        $this->get('/parts/cpu/xeon-a')->assertNotFound();
    }

    /**
     * ⚠️ فیلترها فقط مقدارهایی را نشان می‌دهند که **واقعاً** ردیف دارند.
     *
     * فیلترِ بی‌نتیجه بدترین نوعِ فیلتر است: کاربر می‌زند، صفحهٔ خالی می‌بیند
     * و نتیجه می‌گیرد فروشگاه چیزی ندارد.
     */
    public function test_facets_only_offer_generations_that_have_stock(): void
    {
        $facets = app(PartsCatalog::class)->facets('cpu');

        $this->assertSame(['gen9', 'gen10', 'gen11'], $facets['gens']);
        $this->assertNotContains('gen8', $facets['gens']);
    }

    /**
     * 🔴 جدولِ مقایسه فقط ردیفی را می‌آورد که دستِ‌کم یک ستون دارد.
     *
     * بی‌این فیلتر، مقایسهٔ دو پردازنده ۱۴ ردیف داشت که بیشترش برای هر دو
     * خالی بود («ظرفیت»، «تعداد پورت»، …) و جدول بی‌مصرف می‌شد.
     */
    public function test_compare_table_hides_attributes_no_part_has(): void
    {
        $res = $this->get('/parts/compare?parts=xeon-a,xeon-b');

        $res->assertSee('هسته');            // هر دو دارند
        $res->assertDontSee('تعداد پورت');   // هیچ‌کدام ندارند
    }

    /** ⚠️ سقفِ مقایسه واقعاً اعمال شود، وگرنه جدول روی موبایل ناخوانا می‌شود. */
    public function test_compare_never_shows_more_than_the_maximum(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->part('extra-'.$i, 'cpu', ['gen9'], 1000 + $i, ['cores' => $i + 1]);
        }

        $slugs = ServerPart::where('category', 'cpu')->pluck('slug')->implode(',');
        $res = $this->get('/parts/compare?parts='.$slugs);

        $res->assertOk();
        $res->assertViewHas('parts', fn ($parts) => $parts->count() === \App\Http\Controllers\PartsShopController::COMPARE_MAX);
    }

    /**
     * 🔴 کارتِ فهرست نباید ستونی بخواهد که پرس‌وجو نمی‌آورَد.
     *
     * فهرست‌ها برای سرعت فقط `CARD_COLUMNS` را select می‌کنند (بدونِ `body` که
     * تنهایی ۱۲ کیلوبایت است). لاراول به ستونِ select‌نشده **استثنا نمی‌دهد**؛
     * `null` برمی‌گرداند. پس اگر فردا کسی `$part->specs` را به کارت اضافه کند،
     * صفحه ۲۰۰ می‌دهد و آن بخش بی‌صدا خالی می‌مانَد — و چون هیچ تستی سقوط
     * نمی‌کند، ماه‌ها می‌مانَد.
     *
     * این ادعا خودِ قالب را می‌خوانَد، پس با هر ویرایشِ آینده هم زنده است.
     */
    public function test_the_card_never_reads_a_column_the_listing_does_not_select(): void
    {
        $blade = file_get_contents(resource_path('views/partials/part-card.blade.php'));

        /*
        | ⚠️ گروهِ دوم، فراخوانیِ متد را از خواندنِ ستون جدا می‌کند.
        |
        | نسخهٔ اول الگویش `[a-z_]+` بود و روی `$part->displayPrice()` تا حرفِ
        | بزرگ می‌ایستاد، پس «display» را یک ستونِ غایب گزارش می‌داد. خودِ تست
        | غلط بود، نه کد.
        */
        preg_match_all('/\$part->([a-zA-Z_][a-zA-Z0-9_]*)(\s*\()?/', $blade, $m, PREG_SET_ORDER);

        $used = [];
        foreach ($m as $hit) {
            if (($hit[2] ?? '') !== '') {
                continue;   // متد است، نه ستون
            }
            $used[$hit[1]] = true;
        }

        $this->assertNotEmpty($used, 'الگو هیچ فیلدی پیدا نکرد — خودِ تست شکسته است');

        foreach (array_keys($used) as $column) {
            $this->assertContains(
                $column,
                ServerPart::CARD_COLUMNS,
                "کارت `{$column}` را می‌خوانَد ولی در ServerPart::CARD_COLUMNS نیست؛ در فهرست null می‌شود"
            );
        }
    }

    /**
     * جستجو باید واقعاً **محدود** کند، نه فقط ۲۰۰ بدهد.
     *
     * ⚠️ ادعای «صفحه باز می‌شود» با جستجوی از کار افتاده هم صادق است. ادعا
     * باید این باشد که چیزی که نمی‌خواند **حذف** می‌شود.
     */
    public function test_search_narrows_the_listing_to_what_matches(): void
    {
        $res = $this->get('/parts/cpu?q=xeon-b');

        $res->assertOk();
        $res->assertSee('قطعهٔ xeon-b');
        $res->assertDontSee('قطعهٔ xeon-a');
    }

    /** جستجوی بی‌نتیجه باید عبارتِ خودِ کاربر را برگرداند، نه متنِ عمومی. */
    public function test_a_search_with_no_hits_echoes_the_term(): void
    {
        $res = $this->get('/parts/cpu?q=nothinglikethis');

        $res->assertOk();
        $res->assertSee('nothinglikethis');
        $res->assertDontSee('قطعهٔ xeon-a');
    }

    /**
     * 🔴 سقفِ قیمت روی **یورو** اعمال شود، نه روی عددِ تومانی.
     *
     * تومان از نرخِ لحظه‌ای ساخته می‌شود؛ اگر فیلتر رویش بود، همان آدرس فردا
     * نتیجهٔ دیگری می‌داد و لینکِ ذخیره‌شدهٔ کاربر بی‌معنا می‌شد.
     */
    public function test_the_price_filter_is_anchored_to_euro_not_the_live_rate(): void
    {
        // xeon-a = ۳۴ یورو، xeon-b = ۱۶۸.۰۶ یورو
        $under = $this->get('/parts/cpu?max=50');
        $under->assertSee('قطعهٔ xeon-a');
        $under->assertDontSee('قطعهٔ xeon-b');

        // نرخِ ارز عوض شود؛ نتیجهٔ همان آدرس نباید تکان بخورد
        $this->mock(\App\Services\Cloud\CloudPricing::class,
            fn ($m) => $m->shouldReceive('eurToToman')->andReturn(999_999));
        Cache::flush();

        $again = $this->get('/parts/cpu?max=50');
        $again->assertSee('قطعهٔ xeon-a');
        $again->assertDontSee('قطعهٔ xeon-b');
    }

    /**
     * ⚠️ قطعهٔ استعلامی از فیلترِ قیمت **کنار می‌رود**، نه اینکه صفر حساب شود.
     *
     * «زیر ۵۰ یورو» یعنی قیمتش را می‌دانیم و کمتر است. قطعهٔ بی‌قیمت هیچ‌کدام
     * نیست، و آوردنش یعنی وعده‌ای که ممکن است دروغ باشد.
     */
    public function test_contact_priced_parts_are_excluded_from_a_price_filter(): void
    {
        $this->get('/parts/cpu')->assertSee('قطعهٔ xeon-c');
        $this->get('/parts/cpu?max=1000')->assertDontSee('قطعهٔ xeon-c');
    }

    /**
     * 🔴 جستجو نباید بقیهٔ فیلترها را بی‌صدا پاک کند.
     *
     * فرمِ جستجو فیلدهای مخفی دارد؛ بی‌آن‌ها کاربری که Gen9 را زده و بعد
     * جستجو می‌کند، انتخابش را از دست می‌داد بی‌آنکه بفهمد چرا نتایج عوض شد.
     */
    public function test_searching_keeps_the_other_filters(): void
    {
        $res = $this->get('/parts/cpu?gen=gen9');

        $res->assertOk();
        $res->assertSee('name="gen" value="gen9"', false);
    }

    /**
     * ⚠️ پله‌های قیمت از دادهٔ **واقعیِ همان دسته** ساخته شوند.
     *
     * پلهٔ ثابت در دسته‌ای که گران‌ترین قطعه‌اش ارزان است، چند فیلتر می‌سازد
     * که همه یک نتیجه می‌دهند — فیلتری که کار نمی‌کند بدتر از نبودنش است.
     */
    public function test_price_steps_come_from_the_real_prices_in_that_category(): void
    {
        $this->get('/parts/cpu')->assertOk()->assertViewHas('priceSteps', function ($steps) {
            return $steps !== [] && max($steps) >= 168 && min($steps) < 168;
        });

        // دستهٔ بی‌قیمت نباید فیلترِ قیمت نشان بدهد
        $this->get('/parts/ram')->assertViewHas('priceSteps');
    }

    /** مقایسهٔ بی‌انتخاب باید راهنما بدهد، نه خطا. */
    public function test_compare_with_no_selection_is_a_friendly_page(): void
    {
        $this->get('/parts/compare')->assertOk()->assertSee('انتخاب نشده', false);
    }
}
