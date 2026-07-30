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

        // ═══ منوی **کشورمحور** (خواستهٔ کارفرما) ═══
        // تا امروز زیرمنو در سطحِ **شهر** بود (فالکن‌اشتاین، نورنبرگ…) و شلوغ.
        // حالا در سطحِ کشور است و شهرها داخلِ صفحهٔ همان کشور می‌آیند.
        $countries = $this->countryItems();

        if ($countries === []) {
            return $mega;                        // قاعدهٔ ۱: منوی خالی نده
        }

        // «سرور مجازی» = پردازندهٔ اشتراکی. گروهِ «موقعیت مکانی»‌اش با کشورها پر
        // می‌شود؛ گروه‌های بازاریابیِ دیگر (کاربرد/سیستم‌عامل) دست‌نخورده می‌مانند.
        $this->fillLocationsGroup($mega['vps'], $countries, 'cloud.index',
            'همهٔ سرورهای مجازی', 'All virtual servers', 'Tüm sanal sunucular');

        // «سرور اختصاصی» هم به تفکیکِ کشور — ولی صفحاتش هنوز از config می‌آیند
        // (کاتالوگِ سرورِ فیزیکی هنوز ساخته نشده). پس فقط برچسبِ «اتمام ظرفیت»
        // به کشورهای نداشته می‌خورد، بی‌آنکه چیزی به قیمتِ زنده وصل شود.
        if (isset($mega['dedicated'])) {
            $this->markDedicatedSoldOut($mega['dedicated']);
        }

        return $mega;
    }

    /**
     * گروهِ «موقعیت مکانی» را با کشورها پر کن: زنده‌ها اول، اتمام‌ظرفیت‌ها بعد،
     * لینکِ فراگیر آخر.
     *
     * @param  array<string, mixed>  $section  به ارجاع
     * @param  array<int, array<string, mixed>>  $countries
     */
    private function fillLocationsGroup(array &$section, array $countries, string $allRoute, string $allFa, string $allEn, string $allTr): void
    {
        foreach ($section['groups'] as $i => $group) {
            if (($group['en'] ?? '') !== 'Locations') {
                continue;
            }

            $section['groups'][$i]['items'] = array_merge(
                $countries,
                $this->soldOutItems((array) ($group['items'] ?? [])),
                [[
                    'route' => [$allRoute, []],
                    'fa'    => $allFa, 'en' => $allEn, 'tr' => $allTr,
                ]]
            );

            return;
        }
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

            // برچسبِ اتمام‌ظرفیت را روی متنِ **خامِ** config بگذار، نه روی
            // چیزی که ممکن است شمارشِ پلن به آن چسبیده باشد.

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
     * کشورهایی که پلنِ قابلِ فروش دارند، به شکلِ آیتمِ منو.
     *
     * ⚠️ **کشور** و نه شهر — خواستهٔ کارفرما. با ۶ لوکیشنِ شهری منو شلوغ بود؛
     * حالا «آلمان» یک ردیف است و دو شهرش داخلِ صفحه می‌آید. شمارشِ پلن و
     * ارزان‌ترین قیمت در برچسب می‌آید تا کاربر پیش از کلیک بداند چه می‌بیند.
     *
     * لینک به صفحهٔ کشور (`CloudCountry::url`) که همان صفحهٔ سئودارِ موجود است،
     * نه یک URL تازه — تا رتبهٔ صفحه شکسته نشود.
     *
     * @return array<int, array<string, mixed>>
     */
    private function countryItems(): array
    {
        return Cache::remember(self::KEY, self::TTL, function () {
            $served = \App\Services\Cloud\CloudCountry::served();

            if ($served === []) {
                return [];
            }

            $items = [];

            foreach ($served as $iso => $row) {
                $name = CloudLocation::COUNTRIES[$iso] ?? null;
                $flag = $name['flag'] ?? '🏳️';

                // «۲۴ پلن · از ۵۷۰٬۰۰۰ تومان» — دو عددِ تصمیم‌ساز کنارِ هم.
                // قیمتِ فارسی تومان، بقیه یورو (قاعدهٔ site_price).
                $fa = $flag.' '.($name['fa'] ?? $iso).' — '
                    .fa_num((string) $row['plans']).' پلن'
                    .($row['cheapest_irt'] > 0 ? ' · از '.fa_num(number_format($row['cheapest_irt'])).' تومان' : '');

                $eur = $row['cheapest_eur_cents'] > 0
                    ? ' · from €'.number_format($row['cheapest_eur_cents'] / 100, 2) : '';

                $items[] = [
                    'iso'   => $iso,               // برای soldOutItems که تکرار نشود
                    'route' => $this->countryRoute($iso),
                    'fa'    => $fa,
                    'en'    => $flag.' '.($name['en'] ?? $iso).' — '.$row['plans'].' plans'.$eur,
                    'tr'    => $flag.' '.($name['tr'] ?? $iso).' — '.$row['plans'].' plan'.$eur,
                ];
            }

            return $items;
        });
    }

    /**
     * مسیرِ صفحهٔ کشور برای منو.
     *
     * اگر کشور صفحهٔ بازاریابیِ سئودار دارد (`/vps/germany`)، به همان می‌رود؛
     * وگرنه به صفحهٔ اولین مکانش. `CloudCountry::url` را نمی‌شود مستقیم در آیتمِ
     * منو گذاشت چون آیتم‌ها با `route`ِ سه‌زبانه ساخته می‌شوند، پس این‌جا به
     * ساختارِ مناسبِ منو ترجمه‌اش می‌کنیم.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function countryRoute(string $iso): array
    {
        $slug = \App\Services\Cloud\CloudCountry::marketingSlug($iso);

        if ($slug !== null) {
            return ['catalog', ['category' => 'vps', 'slug' => $slug]];
        }

        $code = CloudLocation::where('country', $iso)->where('is_active', true)
            ->orderBy('sort')->value('code');

        return $code
            ? ['cloud.location', ['location' => $code]]
            : ['cloud.index', []];
    }

    /**
     * منوی «سرور اختصاصی» — کشورهای نداشته «اتمام ظرفیت» بخورند.
     *
     * ⚠️ چرا فقط برچسب و نه قیمتِ زنده: کاتالوگِ سرورِ فیزیکی هنوز وجود ندارد،
     * پس چیزی برای وصل‌کردن نیست. ولی همان قاعدهٔ صداقت برقرار است: کشوری که
     * موجودی نداریم نباید مثلِ کشوری که داریم به نظر برسد.
     *
     * @param  array<string, mixed>  $section  به ارجاع
     */
    private function markDedicatedSoldOut(array &$section): void
    {
        // فعلاً هیچ کشوری سرورِ فیزیکیِ زنده ندارد، پس **همه** اتمام‌ظرفیت‌اند.
        // وقتی کاتالوگِ فیزیکی ساخته شد، این‌جا از یک منبعِ زنده می‌خوانَد.
        $served = [];   // TODO: DedicatedCatalog::servedCountries() وقتی ساخته شد

        $labels = $this->soldOutLabels();

        foreach ($section['groups'] as $gi => $group) {
            if (($group['en'] ?? '') !== 'Locations') {
                continue;
            }

            foreach ($group['items'] as $ii => $item) {
                $iso = self::SLUG_COUNTRY[$item['slug'] ?? ''] ?? null;

                if ($iso !== null && in_array($iso, $served, true)) {
                    continue;
                }

                foreach (['fa', 'en', 'tr'] as $lang) {
                    if (isset($item[$lang]) && is_string($item[$lang])
                        && ! str_ends_with($item[$lang], ' — '.$labels[$lang])) {
                        $section['groups'][$gi]['items'][$ii][$lang] = $item[$lang].' — '.$labels[$lang];
                    }
                }
            }
        }
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

        // 🔴 منو حالا از CloudCountry::served() تغذیه می‌شود؛ اگر کشِ آن پاک
        // نشود، کشورِ تازه تا ۱۰ دقیقه در منو دیده نمی‌شود و مدیر فکر می‌کند
        // سینک کار نکرده — همان drift که این کلاس برای بستنش هست.
        \App\Services\Cloud\CloudCountry::forget();
    }
}
