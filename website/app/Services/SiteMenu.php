<?php

namespace App\Services;

use App\Models\CloudLocation;
use Illuminate\Support\Facades\Cache;

/**
 * منوی سایت — **همگام با کاتالوگِ زنده**، نه فهرستِ سخت‌کدِ کهنه.
 *
 * ═══ مشکلی که این کلاس حل می‌کند ═══
 *
 * زیرمنوی «سرور مجازی» در `config/servernet.php` دستی نوشته شده و گاهی با
 * واقعیتِ کاتالوگ drift می‌کند. این کلاس گروهِ «موقعیت مکانی» را زنده نگه
 * می‌دارد: کشورهای اصیلِ config می‌مانند و هر کشوری که واقعاً پلن دارد ولی در
 * config نیست هم اضافه می‌شود (مثلاً سنگاپور).
 *
 * ═══ قاعدهٔ نمایش (خواستهٔ صریحِ کارفرما، تیر ۱۴۰۵) ═══
 *
 * منو فقط **«سرور مجازی (نامِ کشور)»** را نشان می‌دهد — بی‌قیمت، بی‌شمارشِ پلن،
 * و **بی‌برچسبِ «اتمام ظرفیت»**. منطقِ کارفرما: «بگذار کاربر راغب شود، وارد
 * صفحهٔ همان کشور شود و آنجا ببیند چه پلن‌هایی هست؛ شاید بعضی پلن‌ها را نداشته
 * باشیم، نه همه را». پس منو دعوت‌کننده است و صفحهٔ کشور جای حقیقتِ موجودی.
 *
 *   قبلاً: «🇩🇪 آلمان — ۲۴ پلن · از ۵۷۰٬۰۰۰ تومان» یا «… — اتمام ظرفیت»
 *   حالا:  «سرور مجازی آلمان»
 *
 * برچسبِ بلندِ قبلی یک عارضهٔ جانبی هم داشت: در ستونِ باریکِ مگا-منو
 * (`minmax(185px,1fr)`) به دو-سه خط می‌شکست و پنِ «سرور مجازی» را بلندتر از
 * پنِ «دامنه»‌ی کناری می‌کرد؛ حرکتِ ماوس بینِ این دو تب باعثِ پرش/چشمک‌زدنِ
 * پنل می‌شد — همان «باگی که بینِ سرور مجازی و دامنه می‌خورد». برچسبِ کوتاه، رفعش.
 *
 * ═══ سه قاعدهٔ محافظه‌کارانه ═══
 *
 * ۱) **هرگز منوی خالی نده.** کشورهای اصیلِ config همیشه هستند، حتی وقتی کاتالوگ
 *    همگام نشده. منوی خالی در هدرِ سایت، بدتر از منوی کهنه است.
 * ۲) **گروه‌های بازاریابی دست نمی‌خورند.** «بر اساس کاربرد» و «سیستم‌عامل»
 *    صفحاتِ سئوی واقعی‌اند و فقط گروهِ «موقعیت مکانی» زنده می‌شود.
 * ۳) **کش.** هدر روی هر صفحه رندر می‌شود؛ بی‌کش یعنی پرس‌وجو روی هر بازدید.
 *    `forget()` بعد از هر همگام‌سازی صدا زده می‌شود.
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
     * ترنسفورم روی خودش می‌دود: لینکِ فراگیر و کشورهای زنده دوبرابر می‌شوند.
     *
     * روی پروداکشن نهفته بود (هر درخواست پروسهٔ تازه است و هدر یک بار رندر
     * می‌شود) ولی در تست که یک اپ چند رندر را می‌بیند، تست‌ها **ترتیب‌حساس**
     * می‌شدند — و باگی که فقط «بعضی وقت‌ها» می‌افتد، بدترین نوعِ باگ است.
     *
     * ⚠️ عکس باید **پیش از هر رندر** گرفته شود، پس `AppServiceProvider::boot()`
     * برش می‌دارد (boot همیشه قبل از ویوها اجرا می‌شود).
     */
    public const SOURCE = 'servernet.mega_source';

    /**
     * نگاشتِ اسلاگِ کشورهای config به کدِ کشور — برای اینکه کشورِ زنده‌ای که
     * config از قبل دارد، دوباره (به‌عنوانِ «زندهٔ تازه») اضافه نشود.
     */
    private const SLUG_COUNTRY = [
        'iran' => 'IR', 'germany' => 'DE', 'france' => 'FR', 'finland' => 'FI',
        'usa' => 'US', 'canada' => 'CA', 'netherlands' => 'NL', 'turkey' => 'TR',
        'england' => 'GB', 'uk' => 'GB', 'singapore' => 'SG', 'japan' => 'JP',
    ];

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

        // گروهِ «موقعیت مکانی»‌ی «سرور مجازی»: کشورهای اصیلِ config + کشورهای
        // زندهٔ تازه، همه به شکلِ سادهٔ «سرور مجازی (کشور)». گروه‌های بازاریابیِ
        // دیگر (کاربرد/سیستم‌عامل) دست‌نخورده می‌مانند.
        $this->fillVpsLocations($mega['vps']);

        // «سرور اختصاصی» دیگر «اتمام ظرفیت» نمی‌خورد — عمداً کاملاً دست‌نخورده
        // می‌مانَد (خواستهٔ کارفرما: در منو چیزی به‌عنوانِ اتمام ظرفیت ننویسیم).

        return $mega;
    }

    /**
     * گروهِ «موقعیت مکانی»‌ی سرورِ مجازی را بازسازی کن.
     *
     * @param  array<string, mixed>  $section  به ارجاع
     */
    private function fillVpsLocations(array &$section): void
    {
        foreach ($section['groups'] as $i => $group) {
            if (($group['en'] ?? '') !== 'Locations') {
                continue;
            }

            // فقط کشورهای اصیلِ config را نگه دار (که 'slug' دارند). آیتم‌های
            // زنده ('iso' دارند) و لینکِ فراگیر (بی‌'slug') را کنار بگذار تا اگر
            // مسیرِ fallback روی خروجیِ خودش دوید، دوباره چسبانده نشوند — همان
            // تلهٔ idempotency که SOURCE برای بستنش هست، از سمتِ fallback.
            $config = array_values(array_filter(
                (array) ($group['items'] ?? []),
                fn ($it) => isset($it['slug'])
            ));

            /*
            | 🔴 حذفِ تکراری باید کلِ **بخش** را ببیند، نه فقط همین گروه را.
            |
            | «سرور مجازی ایران» در گروهِ «سرور مجازی» است، نه «موقعیت مکانی».
            | نسخهٔ قبلی فقط آیتم‌های همین گروه را به‌عنوانِ «پوشش‌داده‌شده»
            | می‌شناخت، پس ایران — که فروشِ زنده دارد — دوباره به «موقعیت مکانی»
            | اضافه می‌شد و در منو **دو بار** می‌آمد.
            |
            | ⚠️ کاربر آن را «تکراری» می‌بیند ولی هیچ‌چیز خراب نیست: هر دو لینک
            | به `/vps/iran` می‌روند و صفحه ۲۰۰ است. برای همین ماه‌ها ماند.
            */
            $section['groups'][$i]['items'] = array_merge(
                $config,
                $this->extraLiveCountryItems($this->allSectionSlugItems($section)),
                [[
                    'route' => ['cloud.index', []],
                    'fa'    => 'همهٔ سرورهای مجازی',
                    'en'    => 'All virtual servers',
                    'tr'    => 'Tüm sanal sunucular',
                ]]
            );

            return;
        }
    }

    /**
     * همهٔ آیتم‌های اسلاگ‌دارِ **کلِ** تبِ سرور — پایهٔ حذفِ تکراری.
     *
     * ⚠️ عمداً از همهٔ گروه‌ها می‌خوانَد، نه فقط «موقعیت مکانی». کشوری که در
     * هر گروهی از این تب آمده باشد، دیگر نباید به‌عنوانِ «کشورِ زندهٔ تازه»
     * دوباره اضافه شود.
     *
     * @param  array<string, mixed>  $section
     * @return array<int, array<string, mixed>>
     */
    private function allSectionSlugItems(array $section): array
    {
        $out = [];

        foreach ((array) ($section['groups'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $it) {
                if (isset($it['slug'])) {
                    $out[] = $it;
                }
            }
        }

        return $out;
    }
    /**
     * کشورهایی که واقعاً پلنِ قابلِ فروش دارند ولی در فهرستِ config نیستند —
     * به شکلِ آیتمِ سادهٔ منو.
     *
     * چرا فقط «تازه‌ها»: کشورهای اصلی (ایران، آلمان، …) از قبل در config هستند و
     * همان‌جا نشان داده می‌شوند. این متد فقط کشوری را می‌افزاید که فروشِ زنده
     * دارد ولی config نمی‌شناسدش (مثلاً سنگاپور) — تا منو با کاتالوگ عقب نیفتد.
     *
     * برچسب عمداً ساده است: «سرور مجازی (کشور)»، بی‌قیمت و بی‌شمارشِ پلن.
     *
     * @param  array<int, array<string, mixed>>  $configItems  کشورهای اصیلِ config
     * @return array<int, array<string, mixed>>
     */
    private function extraLiveCountryItems(array $configItems): array
    {
        /*
        | ⚠️ کلیدِ **ثابت**، عمداً.
        |
        | یک بار این را به `KEY.'.'.md5($configItems)` تغییر دادم تا تعمیرِ
        | «ایرانِ تکراری» بی‌درنگ دیده شود. نتیجه‌اش این بود که `forget()` —
        | که کلیدِ ثابت را پاک می‌کند — دیگر به کلیدِ پویا نمی‌رسید، و
        | `cloud:sync` هرگز منو را تازه نمی‌کرد. یعنی یک مشکلِ ۱۰ دقیقه‌ای را
        | با یک مشکلِ همیشگی عوض کرده بودم.
        |
        | هزینهٔ کلیدِ ثابت کراندار است: حداکثر تا انقضای TTL (۱۰ دقیقه) پس از
        | تغییرِ فهرستِ config، منو کهنه می‌مانَد. `forget()` هم هست اگر کسی
        | بخواهد فوری تازه‌اش کند.
        */
        return Cache::remember(self::KEY, self::TTL, function () use ($configItems) {
            // ISOهایی که config از قبل پوشش می‌دهد
            $covered = [];

            foreach ($configItems as $it) {
                $iso = self::SLUG_COUNTRY[$it['slug'] ?? ''] ?? null;

                if ($iso !== null) {
                    $covered[] = $iso;
                }
            }

            $served = \App\Services\Cloud\CloudCountry::served();

            if ($served === []) {
                return [];
            }

            $items = [];

            foreach ($served as $iso => $row) {
                if (in_array($iso, $covered, true)) {
                    continue;                      // config قبلاً نشانش می‌دهد
                }

                /*
                | XX کشورِ ساختگیِ خطِ GPU است (شبکهٔ توزیع‌شده) — محصولش
                | /gpu است و منوی خودش را دارد. بی‌این گارد، منو و فوتر
                | «سرور مجازی شبکهٔ توزیع‌شده» می‌ساختند: قولِ VPS برای
                | محصولی که VPS نیست، با لینک به یک ۳۰۱.
                */
                if ($iso === 'XX') {
                    continue;
                }

                $name = CloudLocation::COUNTRIES[$iso] ?? null;

                if ($name === null) {
                    continue;
                }

                $items[] = [
                    'iso'   => $iso,               // نشانهٔ «زنده» — تست‌ها با آن می‌شمارند
                    'route' => $this->countryRoute($iso),
                    'fa'    => 'سرور مجازی '.($name['fa'] ?? $iso),
                    'en'    => ($name['en'] ?? $iso).' VPS',
                    'tr'    => ($name['tr'] ?? $iso).' VPS',
                ];
            }

            return $items;
        });
    }

    /**
     * مسیرِ صفحهٔ کشور برای منو.
     *
     * اگر کشور صفحهٔ بازاریابیِ سئودار دارد (`/vps/germany`)، به همان می‌رود؛
     * وگرنه به صفحهٔ اولین مکانش (`/cloud/{code}`).
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
     * کشِ منو را دور بریز — بعد از همگام‌سازیِ کاتالوگ صدا زده می‌شود.
     *
     * ⚠️ عکسِ دست‌نخوردهٔ منو (`self::SOURCE`) عمداً پاک **نمی‌شود**: آن عکس در
     * `boot()` و پیش از هر رندر گرفته شده، و دوباره‌خواندنش از config یعنی
     * خواندنِ مقداری که composer شاید همین حالا رویش نوشته باشد. این متد فقط
     * کشِ **دادهٔ زنده** است.
     */
    public static function forget(): void
    {
        Cache::forget(self::KEY);
        Cache::forget(self::KEY.'.countries');

        // 🔴 منو از CloudCountry::served() تغذیه می‌شود؛ اگر کشِ آن پاک نشود،
        // کشورِ تازه تا ۱۰ دقیقه در منو دیده نمی‌شود و مدیر فکر می‌کند سینک کار
        // نکرده — همان drift که این کلاس برای بستنش هست.
        \App\Services\Cloud\CloudCountry::forget();
    }
}
