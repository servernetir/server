<?php

namespace App\Services;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * منوی سایت — **همگام با کاتالوگِ زنده**، نه فهرستِ سخت‌کدِ کهنه.
 *
 * ═══ مشکلی که این کلاس حل می‌کند ═══
 *
 * زیرمنوی «سرور مجازی» در `config/servernet.php` دستی نوشته شده بود و با
 * واقعیت drift داشت: «سرور مجازی فرانسه» و «ایران» را تبلیغ می‌کرد که در
 * کاتالوگ **نداریم**، و سنگاپور را که **داریم** نداشت. یعنی هم مشتری روی
 * لینکی می‌زد که محصولی پشتش نبود، هم محصولِ واقعی‌مان در منو دیده نمی‌شد.
 *
 * هر بار که مکانی اضافه/کم شود، منو باید خودش درست باشد. پس مکان‌ها از
 * `cloud_locations` خوانده می‌شوند — فقط آن‌هایی که **واقعاً پلنِ قابلِ فروش**
 * دارند.
 *
 * ═══ سه قاعدهٔ محافظه‌کارانه ═══
 *
 * ۱) **هرگز منوی خالی نده.** اگر جدول‌ها نساخته شده‌اند، همگام‌سازی نشده، یا
 *    قیمت‌ها صفرند، همان فهرستِ config برگردانده می‌شود. منوی خالی در هدرِ
 *    سایت، بدتر از منوی کهنه است.
 * ۲) **گروه‌های بازاریابی دست نمی‌خورند.** «بر اساس کاربرد» و «سیستم‌عامل»
 *    صفحاتِ سئوی واقعی‌اند (ترید، GPU، لینوکس، ویندوز) و به کاتالوگ ربط ندارند.
 *    فقط گروهِ «موقعیت مکانی» زنده می‌شود.
 * ۳) **کش.** هدر روی هر صفحهٔ سایت رندر می‌شود؛ بی‌کش یعنی دو پرس‌وجو روی هر
 *    بازدید. ۱۰ دقیقه کافی است و `forget()` بعد از هر همگام‌سازی صدا زده می‌شود.
 */
class SiteMenu
{
    private const TTL = 600;

    private const KEY = 'site.menu.mega';

    /**
     * کلیدی که نسخهٔ **دست‌نخوردهٔ** منو در آن نگه داشته می‌شود.
     *
     * 🔴 باگی که این کلید می‌بندد: `AppServiceProvider` خروجیِ `mega()` را در
     * `config('servernet.mega')` می‌نویسد (تا هدر بی‌تغییرِ ویو زنده شود). اگر
     * `mega()` هم از **همان** کلید بخواند، خروجی‌اش ورودیِ پاسِ بعدی می‌شود و
     * ترنسفورم روی خودش می‌دود: برچسبِ «اتمام ظرفیت» دو بار می‌چسبد، لینکِ
     * فراگیر تکرار می‌شود و مکان‌های زنده دوبرابر می‌شوند.
     *
     * روی پروداکشن نهفته بود (هر درخواست پروسهٔ تازه است و هدر یک بار رندر
     * می‌شود) ولی در تست که یک اپ چند رندر را می‌بیند، تست‌ها **ترتیب‌حساس**
     * می‌شدند — و باگی که فقط «بعضی وقت‌ها» می‌افتد، بدترین نوعِ باگ است.
     *
     * ⚠️ عکس باید **پیش از هر رندر** گرفته شود، پس `AppServiceProvider::boot()`
     * برش می‌دارد (boot همیشه قبل از ویوها اجرا می‌شود). اگر به‌جایش همین‌جا
     * تنبل‌وار می‌گرفتیم، «اولین صدا زدن» تعیین‌کنندهٔ مقدار می‌شد و همان
     * ترتیب‌حساسی از درِ دیگر برمی‌گشت.
     */
    public const SOURCE = 'servernet.mega_source';

    /**
     * مگا-منو با مکان‌های زندهٔ سرورِ مجازی.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mega(): array
    {
        // از عکسِ دست‌نخورده می‌خوانیم، نه از کلیدی که خودمان رویش می‌نویسیم.
        // fallback فقط برای حالتی است که provider نرسیده باشد عکس بگیرد.
        $mega = (array) config(self::SOURCE, config('servernet.mega', []));

        if (! isset($mega['vps'])) {
            return $mega;
        }

        $live = $this->vpsLocationItems();

        if ($live === []) {
            return $mega;                        // قاعدهٔ ۱: منوی خالی نده
        }

        foreach ($mega['vps']['groups'] as $i => $group) {
            // گروهِ مکان را با شناسهٔ چندزبانه‌اش پیدا کن، نه با ایندکسِ ثابت —
            // اگر روزی ترتیبِ گروه‌ها عوض شود، این نباید بشکند.
            if (($group['en'] ?? '') !== 'Locations') {
                continue;
            }

            // مکان‌های زندهٔ **قابلِ خرید** اول، بعد مکان‌های تبلیغاتیِ config که
            // فعلاً موجودی نداریم، با برچسبِ «اتمام ظرفیت».
            // ترتیب مهم است: اول آنچه می‌شود خرید، بعد آنچه موجودی نداریم، و
            // «همهٔ سرورها» **آخرِ همه** — یک لینکِ فراگیر وسطِ فهرست، جای
            // اشتباهی است و کاربر فکر می‌کند فهرست تمام شده.
            $mega['vps']['groups'][$i]['items'] = array_merge(
                $live,
                $this->soldOutItems((array) ($group['items'] ?? [])),
                [[
                    'route' => ['cloud.index', []],
                    'fa'    => 'همهٔ سرورهای مجازی',
                    'en'    => 'All virtual servers',
                    'tr'    => 'Tüm sanal sunucular',
                ]]
            );
            break;
        }

        return $mega;
    }

    /**
     * نگاشتِ اسلاگِ مکان‌های تبلیغاتیِ config به کدِ کشور.
     *
     * چرا لازم است: config در سطحِ **کشور** می‌نویسد («سرور مجازی آلمان») ولی
     * کاتالوگ در سطحِ **شهر** است («فالکن‌اشتاین»، «نورنبرگ»). بی‌این نگاشت،
     * «آلمان» را «اتمام ظرفیت» علامت می‌زدیم در حالی که دو شهرِ آلمان را
     * فعالانه می‌فروشیم — یعنی با دستِ خودمان فروش را می‌خواباندیم.
     */
    private const SLUG_COUNTRY = [
        'iran' => 'IR', 'germany' => 'DE', 'france' => 'FR', 'finland' => 'FI',
        'usa' => 'US', 'canada' => 'CA', 'netherlands' => 'NL', 'turkey' => 'TR',
        'england' => 'GB', 'uk' => 'GB', 'singapore' => 'SG', 'japan' => 'JP',
    ];

    /**
     * مکان‌های تبلیغاتی که موجودی نداریم — با برچسبِ «اتمام ظرفیت».
     *
     * خواستهٔ کارفرما: «اونایی که فعلا نداریم … مثلا اتمام ظرفیت». این از
     * حذفشان بهتر است، چون آن صفحات سئو دارند و رتبه‌شان با حذفِ لینکِ داخلی
     * افت می‌کند. لینک هم باز می‌مانَد تا صفحه‌اش دیده شود.
     *
     * ⚠️ برچسب داخلِ **متنِ عنوان** می‌نشیند و نه یک فیلدِ تازه، چون قالبِ هدر
     * فقط عنوان را چاپ می‌کند؛ این‌طور بی‌هیچ تغییری در ویو کار می‌کند.
     *
     * @param  array<int, array<string, mixed>>  $configItems
     * @return array<int, array<string, mixed>>
     */
    private function soldOutItems(array $configItems): array
    {
        $servedCountries = $this->servedCountries();

        // «خارج» یعنی هر کشورِ غیرِ ایران؛ اگر حتی یکی داریم، اتمام ظرفیت نیست
        $hasForeign = $servedCountries !== [] && $servedCountries !== ['IR'];

        $labels = $this->soldOutLabels();
        $out = [];

        foreach ($configItems as $item) {
            $slug = (string) ($item['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            $served = $slug === 'international'
                ? $hasForeign
                : in_array(self::SLUG_COUNTRY[$slug] ?? '—', $servedCountries, true);

            if ($served) {
                continue;                      // زنده‌اش را بالاتر نشان داده‌ایم
            }

            foreach (['fa', 'en', 'tr'] as $lang) {
                if (! isset($item[$lang]) || ! is_string($item[$lang])) {
                    continue;
                }

                $suffix = ' — '.$labels[$lang];

                // کمربندِ دومِ همان تلهٔ بالا: اگر برچسب از قبل چسبیده، دوباره
                // نچسبان. روی مسیرِ عادی هرگز صدا نمی‌دهد (چون `mega()` همیشه از
                // عکسِ دست‌نخورده می‌سازد) و فقط مسیرِ fallback را می‌پوشاند.
                //
                // ⚠️ **پایانِ** رشته سنجیده می‌شود و نه «جایی از» آن: متنِ برچسب
                // را مدیر عوض می‌کند، و اگر روزی چیزی مثلِ «سرور» بگذارد، سنجشِ
                // زیررشته‌ای روی *همهٔ* عنوان‌ها درست می‌شد و هیچ مکانی دیگر
                // برچسب نمی‌خورد. این‌طور فقط چیزی که خودمان چسبانده‌ایم می‌شمارد.
                if (str_ends_with($item[$lang], $suffix)) {
                    continue;
                }

                $item[$lang] = $item[$lang].$suffix;
            }

            $out[] = $item;
        }

        return $out;
    }

    /** کدِ کشورهایی که واقعاً در آنها پلنِ قابلِ فروش داریم @return array<int,string> */
    private function servedCountries(): array
    {
        return Cache::remember(self::KEY.'.countries', self::TTL, function () {
            if (! Schema::hasTable('cloud_locations') || ! Schema::hasTable('cloud_plans')) {
                return [];
            }

            $codes = CloudPlan::query()->sellable()->distinct()->pluck('location_code');

            return CloudLocation::whereIn('code', $codes)->where('is_active', true)
                ->pluck('country')->map(fn ($c) => strtoupper((string) $c))
                ->unique()->values()->all();
        });
    }

    /**
     * متنِ برچسب. مدیر می‌تواند در تنظیمات عوضش کند — مثلاً به «به‌زودی» که
     * برای مکانی که هرگز نداشته‌ایم دقیق‌تر است.
     *
     * @return array{fa:string,en:string,tr:string}
     */
    private function soldOutLabels(): array
    {
        $fa = (string) (\App\Models\Setting::get('menu_soldout_label_fa') ?: 'اتمام ظرفیت');

        return [
            'fa' => $fa,
            'en' => (string) (\App\Models\Setting::get('menu_soldout_label_en') ?: 'Out of stock'),
            'tr' => (string) (\App\Models\Setting::get('menu_soldout_label_tr') ?: 'Stokta yok'),
        ];
    }

    /**
     * مکان‌هایی که پلنِ قابلِ فروش دارند، به شکلِ آیتمِ منو.
     *
     * برچسبِ هر زبان از `CloudLocation` می‌آید که خودش سه‌زبانه است، و به
     * `/cloud/{code}` لینک می‌شود (صفحهٔ اختصاصیِ همان مکان).
     *
     * @return array<int, array<string, mixed>>
     */
    private function vpsLocationItems(): array
    {
        return Cache::remember(self::KEY, self::TTL, function () {
            if (! Schema::hasTable('cloud_locations') || ! Schema::hasTable('cloud_plans')) {
                return [];
            }

            // فقط مکان‌هایی که **الان** می‌شود در آنها سرور فروخت
            $codes = CloudPlan::query()->sellable()->distinct()->pluck('location_code');

            if ($codes->isEmpty()) {
                return [];
            }

            $items = [];

            foreach (CloudLocation::whereIn('code', $codes)->where('is_active', true)
                ->orderBy('sort')->orderBy('country')->orderBy('city')->get() as $loc) {
                $items[] = [
                    // مسیرِ صفحهٔ مکان — با `route` تا سه‌زبانه ساخته شود
                    'route' => ['cloud.location', ['location' => $loc->code]],
                    'fa'    => 'سرور مجازی '.$loc->cityLabel('fa'),
                    'en'    => $loc->cityLabel('en').' VPS',
                    'tr'    => $loc->cityLabel('tr').' VPS',
                ];
            }

            return $items;
        });
    }

    /**
     * کشِ منو را دور بریز — بعد از همگام‌سازیِ کاتالوگ صدا زده می‌شود.
     *
     * بی‌این، مکانِ تازه تا ۱۰ دقیقه در منو دیده نمی‌شد و مدیر فکر می‌کرد
     * همگام‌سازی کار نکرده.
     */
    /**
     * ⚠️ عکسِ دست‌نخوردهٔ منو (`self::SOURCE`) عمداً پاک **نمی‌شود**: آن عکس در
     * `boot()` و پیش از هر رندر گرفته شده، و دوباره‌خواندنش از config یعنی
     * خواندنِ مقداری که composer شاید همین حالا رویش نوشته باشد — یعنی برگشتِ
     * همان باگی که عکس برای بستنش هست. این متد فقط کشِ **دادهٔ زنده** است.
     */
    public static function forget(): void
    {
        Cache::forget(self::KEY);
        Cache::forget(self::KEY.'.countries');
    }
}
