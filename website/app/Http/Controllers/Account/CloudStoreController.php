<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRate;
use App\Services\Cloud\CloudAddons;
use App\Services\Notify\AdminNotifier;
use App\Support\Funnel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * سرورساز — مشتری در پنلِ خودش سرورِ مجازی می‌سازد.
 *
 * گام‌ها: کشور/مکان → پلن → سیستم‌عامل یا نرم‌افزارِ آماده → دورهٔ پرداخت →
 * نامِ دلخواه → پیش‌فاکتور → پرداخت → تحویلِ خودکار (CloudProvisioner).
 *
 * ═══ چهار قاعده‌ای که این کنترلر روی آن‌ها بنا شده ═══
 *
 * ۱) **سفیدبرچسبی.** هیچ‌جا نامِ زیرساخت، شناسهٔ پلنِ زیرساخت یا کدِ مکانِ
 *    آن‌ها بیرون نمی‌رود. مشتری فقط «CV-2-4» و «آلمان — فرانکفورت» می‌بیند.
 *    برای همین هرگز روی `provider` پرس‌وجوی نمایشی نمی‌زنیم و به ویو هم
 *    آرایه‌های صریحِ ساخته‌شده می‌دهیم، نه مدلِ سریالایزشده.
 *
 * ۲) **قیمت از دیتابیس، نه از ورودی.** فرم فقط «کدام پلن، کدام دوره» را
 *    می‌گوید؛ مبلغ سمتِ سرور از `cloud_plans.price_irt` خوانده و در لحظهٔ
 *    سفارش روی `services.price` قفل می‌شود. هیچ فیلدِ مبلغی از request
 *    خوانده نمی‌شود.
 *
 * ۳) **دوره‌ها فقط از `config/billing.php`.** یک‌بار در این پروژه دوره‌ها در
 *    ۷ جا تکرار شده بودند و «شش‌ماهه» جا افتاد و کرونِ تمدید هرگز فاکتورش
 *    نکرد. این‌جا از `Service::cycles()/monthsIn()/labelFor()` می‌آید و
 *    هیچ دوره‌ای سخت‌کد نیست.
 *
 * ۴) **فقط گزینه‌های واقعاً قابلِ تحویل.** تحویل روی `bestForSlug()` انجام
 *    می‌شود و `CloudProvisioner` اگر ایمیج روی آن زیرساخت نبود، سراغِ ردیفِ
 *    دیگرِ همان اسلاگ می‌رود. پس «قابلِ تحویل» یعنی: ایمیج روی دستِ‌کم یکی
 *    از زیرساخت‌هایی که همین اسلاگ را می‌فروشند موجود باشد، معماری‌اش با پلن
 *    بخواند و `min_disk_gb` از دیسکِ پلن بیشتر نباشد. گزینه‌ای که تحویل
 *    نمی‌شود، **نباید** به مشتری نشان داده شود — وگرنه پول می‌گیریم و
 *    ساختش شکست می‌خورد.
 */
class CloudStoreController extends Controller
{
    /** بیشینهٔ طولِ نامِ دلخواهِ سرور (نامِ میزبان، نه نامِ نمایشی) */
    public const LABEL_MAX = 32;

    // ═════════════════════════ کاتالوگ ═════════════════════════

    /**
     * دوره‌های خرید — فقط دوره‌های واقعیِ صورت‌حساب.
     *
     * `Service::cycles()` انتهایش «once» را می‌گذارد که برای اشتراکِ سرور
     * معنا ندارد (سررسیدِ بعدی ندارد و سرور بعد از یک ماه باید تمدید شود).
     * فیلتر بر پایهٔ `monthsIn` است، نه فهرستِ سخت‌کد — پس دورهٔ تازه‌ای که
     * فردا در config اضافه شود، خودش این‌جا هم می‌آید.
     *
     * @return list<string>
     */
    public static function cycles(): array
    {
        $cycles = array_values(array_filter(
            Service::cycles(),
            fn ($c) => Service::monthsIn((string) $c) > 0
        ));

        // آخرین سنگر: اگر config نرسیده باشد، فرم بی‌گزینه نماند
        return $cycles !== [] ? $cycles : ['monthly'];
    }

    /** دورهٔ پیش‌فرضِ فرم — از config، با پشتیبانِ اولین دورهٔ موجود */
    public static function defaultCycle(): string
    {
        $cycles = self::cycles();
        $def = (string) config('billing.default_cycle', 'monthly');

        return in_array($def, $cycles, true) ? $def : $cycles[0];
    }

    /**
     * مبلغِ یک دورهٔ کامل به تومان — همان چیزی که روی `services.price` قفل می‌شود.
     *
     * ⚠️ ترتیب عمدی است: ضربِ ماه‌ها و تخفیف روی قیمتِ **خامِ ماهانه** و بعد
     * یک‌بار گردکردن. اگر ماهانه را جدا گرد می‌کردیم و بعد در ۱۲ ضرب،
     * خطای گردکردن هم ×۱۲ می‌شد.
     *
     * گردکردن همیشه **رو به بالا** است (قاعدهٔ پولِ پروژه): گردکردنِ پایین روی
     * حاشیهٔ نازکِ سرورِ ابری یعنی تخفیفِ ناخواسته.
     */
    public static function priceForCycle(CloudPlan $plan, string $cycle, array $addons = []): int
    {
        $months = Service::monthsIn($cycle);

        if ($months <= 0) {
            return 0;
        }

        $discount = (int) (config('billing.cycles.'.$cycle.'.discount_pct') ?? 0);
        $discount = max(0, min(90, $discount));

        $raw = (int) $plan->price_irt * $months * (100 - $discount) / 100;

        // افزودنی‌های پولی (IP اضافه) از یک منبعِ واحد قیمت می‌خورند و همان
        // تخفیفِ دوره را می‌گیرند — تا مشتری در صورت‌حساب دو نرخِ متفاوت نبیند.
        $extra = $addons === [] ? 0 : app(CloudAddons::class)->forCycle($addons, $cycle);

        return Product::roundUpToman($raw) + $extra;
    }

    /** معادلِ ماهانهٔ یک دوره — برای برچسبِ «ماهی X تومان» */
    public static function monthlyEquivalent(CloudPlan $plan, string $cycle): int
    {
        $months = max(1, Service::monthsIn($cycle));

        return (int) ceil(self::priceForCycle($plan, $cycle) / $months);
    }

    /**
     * کلیدِ SSH انتخابی یا تازه — یا خطای اعتبارسنجی.
     *
     * ⚠️ کلیدِ عمومی راز نیست، ولی کلیدِ **خصوصی** هست. اگر مشتری اشتباهی کلیدِ
     * خصوصی‌اش را بچسباند و ما ذخیره کنیم، رازش در دیتابیسِ ما می‌نشیند. پس
     * `CloudSshKey::inspect()` صریح ردش می‌کند و می‌گوید چه چیزی باید بچسباند.
     *
     * @return \App\Models\CloudSshKey|\Illuminate\Http\RedirectResponse|null
     */
    private function resolveSshKey(Customer $customer, array $data)
    {
        // کلیدِ ذخیره‌شدهٔ خودش
        if (filled($data['ssh_key_id'] ?? null)) {
            $key = \App\Models\CloudSshKey::where('customer_id', $customer->id)
                ->whereKey((int) $data['ssh_key_id'])->first();

            if ($key === null) {
                return back()->withInput()->withErrors(['ssh_key_id' => __('ui.cvb_e_ssh_missing')]);
            }

            return $key;
        }

        if (blank($data['ssh_key_new'] ?? null)) {
            return null;                          // کلیدی نخواسته — با رمز جلو می‌رود
        }

        $check = \App\Models\CloudSshKey::inspect((string) $data['ssh_key_new']);

        if (! $check['ok']) {
            return back()->withInput()->withErrors(['ssh_key_new' => $check['message']]);
        }

        // کلیدِ تکراریِ همان مشتری دوباره ساخته نمی‌شود
        $existing = \App\Models\CloudSshKey::where('customer_id', $customer->id)
            ->where('fingerprint', $check['fingerprint'])->first();

        if ($existing !== null) {
            return $existing;
        }

        // نامِ تکراری (یکتا در سطحِ مشتری) را با شماره یکتا کن، وگرنه ذخیره با
        // خطای یکتایی می‌شکند و کلِ سفارش ۵۰۰ می‌شود.
        $base = mb_substr(trim((string) ($data['ssh_key_name'] ?? '')) ?: __('ui.cvb_ssh_my_key'), 0, 55);
        $name = $base;

        for ($i = 2; $i < 50 && \App\Models\CloudSshKey::where('customer_id', $customer->id)
            ->where('name', $name)->exists(); $i++) {
            $name = $base.' '.$i;
        }

        return \App\Models\CloudSshKey::create([
            'customer_id' => $customer->id,
            'name'        => $name,
            'public_key'  => $check['normalized'],
            'fingerprint' => $check['fingerprint'],
            'key_type'    => $check['type'],
        ]);
    }

    /**
     * درصدِ مالیات.
     *
     * از جدولِ داده‌محورِ `tax_rates` می‌آید (ایران ۱۰٪ · خارج ۰٪) و اگر
     * قاعده‌ای ثبت نشده باشد به ۱۰٪ برمی‌گردد. فاکتورِ سرورِ مجازی تومانی و
     * برای مشتریِ ایرانی است، پس کشورِ قاعده «IR» است.
     */
    public static function taxPercent(): int
    {
        try {
            $rate = TaxRate::resolve('IR', null, 'cloud') ?? TaxRate::resolve('IR');

            if ($rate !== null) {
                return (int) round(((int) $rate->rate_bp) / 100);
            }
        } catch (\Throwable) {
            // نبودِ جدول نباید فروشگاه را بخوابانَد
        }

        return (int) config('billing.tax_percent', 10);
    }

    /**
     * مکان‌هایی که همین حالا پلنِ قابلِ فروش دارند، گروه‌بندی‌شده بر اساس کشور.
     *
     * اگر برای کدِ مکانی ردیفِ `cloud_locations` وجود نداشته باشد (سینکِ
     * نیم‌بند)، ردیفِ **ذخیره‌نشده** از خودِ کد ساخته می‌شود تا موجودی بی‌صدا
     * پنهان نشود. ولی مکانی که مدیر عمداً `is_active=false` کرده، پنهان
     * می‌ماند — آن یک تصمیم است، نه نقص.
     *
     * @return array<int, array{country:string,label:string,flag:string,locations:array<int,CloudLocation>}>
     */
    public function locationGroups(): array
    {
        return $this->groupsFor(CloudPlan::query()->sellable()
            ->distinct()->orderBy('location_code')->pluck('location_code')
            ->filter()->unique()->values());
    }

    /**
     * مکان‌هایی که پلن دارند — **حتی اگر همه‌شان الان ناموجود باشند**.
     *
     * فروشگاه از این می‌خوانَد تا کشورِ تمام‌شده خاکستری بماند نه اینکه غیب شود؛
     * `order()` عمداً از `locationGroups()` (فقط فروختنی) می‌خوانَد، چون آن‌جا
     * حرف از «چه چیزی را می‌شود خرید» است نه «چه چیزی را باید دید».
     *
     * @return array<int, array{country:string,label:string,flag:string,locations:array<int,CloudLocation>}>
     */
    public function shelfLocationGroups(): array
    {
        $off = CloudPlan::disabledProviders();

        return $this->groupsFor(CloudPlan::query()
            ->where('is_active', true)->where('admin_disabled', false)
            ->when($off !== [], fn ($q) => $q->whereNotIn('provider', $off))
            ->distinct()->orderBy('location_code')->pluck('location_code')
            ->filter()->unique()->values());
    }

    /**
     * @param  Collection<int,string>  $codes
     * @return array<int, array{country:string,label:string,flag:string,locations:array<int,CloudLocation>}>
     */
    private function groupsFor(Collection $codes): array
    {
        if ($codes->isEmpty()) {
            return [];
        }

        $rows = CloudLocation::query()->whereIn('code', $codes)->get()->keyBy('code');

        /** @var Collection<int, CloudLocation> $usable */
        $usable = collect();

        foreach ($codes as $code) {
            $row = $rows->get($code);

            if ($row !== null) {
                if ((bool) $row->is_active) {
                    $usable->push($row);
                }

                continue;
            }

            // «de-frankfurt» → کشور DE، شهر frankfurt
            $parts = explode('-', (string) $code, 2);
            $usable->push(new CloudLocation([
                'code' => (string) $code,
                'country' => strtoupper($parts[0] ?? ''),
                'city' => str_replace('-', ' ', $parts[1] ?? ''),
                'is_active' => true,
            ]));
        }

        // مشتریِ احرازنشده مکان‌های ایران را نمی‌بیند (گیتِ سختِ سفارش جداست)
        if (\App\Services\Customer\IranSalesGate::blocksIranFor(Auth::guard('customer')->user())) {
            $usable = $usable->reject(
                fn (CloudLocation $l) => strtoupper((string) $l->country) === 'IR'
            )->values();
        }

        // ترتیبِ کشورها از فهرستِ سه‌زبانهٔ خودمان می‌آید (منظم و پایدار)؛
        // کشورِ ناشناخته آخر می‌نشیند تا ترتیب هرگز تصادفی نشود.
        $order = array_keys(CloudLocation::COUNTRIES);

        $groups = $usable
            ->groupBy(fn (CloudLocation $l) => strtoupper((string) $l->country))
            ->sortBy(function ($items, $country) use ($order) {
                $i = array_search($country, $order, true);

                return $i === false ? 999 : $i;
            })
            ->map(fn ($items, $country) => [
                'country' => (string) $country,
                'label' => $items->first()->countryLabel(),
                'flag' => $items->first()->flagEmoji(),
                // پرچمِ تصویری برای ردیفِ کشور؛ اموجی می‌ماند چون خلاصه‌های
                // متنیِ همین صفحه (تیرک و برگه) هنوز از آن می‌خوانند.
                'flag_svg' => $items->first()->flagSvg(),
                // کلیدِ مرکب: اول ترتیبِ دستیِ مدیر، بعد نامِ شهر — تا ترتیب
                // هرگز به ترتیبِ تصادفیِ ردیف‌های دیتابیس نیفتد
                'locations' => $items
                    ->sortBy(fn (CloudLocation $l) => sprintf('%05d-%s', (int) $l->sort, $l->cityLabel()),
                        SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->all(),
            ]);

        return $groups->values()->all();
    }

    /**
     * شهرهای یک کشور، **یکتاشده در سطحِ نمایش**.
     *
     * 🔴 علتِ ریشه‌ایِ «نام شهر تکراری است» — و چرا این‌جا حل می‌شود نه در دیتابیس:
     * ردیف‌ها تکراری نیستند. `cloud_locations.code` یکتا است و `groupsFor()` هم
     * فهرستِ `unique()` می‌گیرد، پس به‌ازای هر کد دقیقاً یک ردیف هست. تکرار را
     * `CloudLocation::cityLabel()` سرِ رندر می‌سازد: هر کدی که `city` نداشته
     * باشد یا واژهٔ ردهٔ محصول («AMD»/«Shared»/«NVMe») در ستونِ شهرش نشسته باشد،
     * نامِ **پایتخت** را چاپ می‌کند. پنج کدِ متفاوت، یک چیپ با یک متن.
     * منبعِ دوم: `slug()` بایت‌محور است، پس `Zürich` و `Zurich` دو کد می‌شوند
     * در حالی که یک شهرند.
     *
     * ⚠️ این یکتاسازی **فقط نمایشی** است:
     *   · هیچ `code` بازنویسی، ادغام یا حذف نمی‌شود؛ خروجی یک «گروه» است با یک
     *     نمایندهٔ مشخص و فهرستِ کاملِ اعضا.
     *   · کدِ بی‌شهر هرگز در کارتِ پایتخت حل نمی‌شود (کلیدِ `#code`).
     *   · نماینده اولین عضوِ **باز** است، نه صرفاً اولین عضو — وگرنه یک عضوِ
     *     تمام‌شده می‌توانست عضوِ فروختنی را پشتِ خودش پنهان کند.
     *   · شهر وقتی «باز» است که **دستِ‌کم یک** عضوش فروختنی باشد؛ تمام‌شده
     *     خاکستری می‌شود، غیب نمی‌شود.
     *
     * @param  array<int,CloudLocation>  $locations
     * @param  array<int,string>  $openCodes
     * @return array<int, array{key:string,label:string,primary:CloudLocation,members:array<int,CloudLocation>,open:bool,n:int}>
     */
    public static function cityBuckets(array $locations, array $openCodes, array $anchors = []): array
    {
        $buckets = [];

        foreach ($locations as $l) {
            $key = strtoupper(trim((string) $l->country)).'|'.$l->cityIdentity();

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['key' => $key, 'members' => [], 'open' => false];
            }

            $buckets[$key]['members'][] = $l;

            if (in_array((string) $l->code, $openCodes, true)) {
                $buckets[$key]['open'] = true;
            }
        }

        $out = [];

        foreach ($buckets as $b) {
            $primary = null;

            foreach ($b['members'] as $m) {
                if (in_array((string) $m->code, $openCodes, true)) {
                    $primary = $m;
                    break;
                }
            }

            $primary ??= $b['members'][0];

            // شهرِ **قابلِ اعتماد** یا هیچ. پایتخت این‌جا هرگز چاپ نمی‌شود.
            $city = self::trustedCityName($primary);

            // لنگرِ قیمت/سقفِ مشخصات از اعضای همین سطل — همان min()ای که
            // لنگرِ کشور و مجموعِ برگه از آن می‌آید، نه یک عددِ موازی.
            $irt = 0;
            $cores = 0;

            foreach ($b['members'] as $m) {
                $a = $anchors[(string) $m->code] ?? null;

                if ($a === null) {
                    continue;
                }

                $irt = $irt === 0 ? (int) $a['irt'] : min($irt, (int) $a['irt']);
                $cores = max($cores, (int) $a['cores']);
            }

            $out[] = [
                'key' => (string) $b['key'],
                'label' => $city ?? '',        // پایین پر می‌شود
                'city' => $city,
                'generic' => $city === null,
                'irt' => $irt,
                'cores' => $cores,
                'primary' => $primary,
                'members' => $b['members'],
                'open' => (bool) $b['open'],
                'n' => count($b['members']),
            ];
        }

        return self::disambiguate($out);
    }

    /**
     * نامِ شهر فقط وقتی چاپ می‌شود که **واقعاً بدانیمش** — وگرنه `null`.
     *
     * 🔴 این جای‌گزینِ فروشگاهیِ `CloudLocation::cityLabel()` است و عمداً آن را
     * دست نمی‌زند: `CloudCountry::served()`، صفحاتِ بازاریابیِ کشور و
     * `CloudCityLabelTest` همان متد را صدا می‌زنند و «برلین»ی که مشتری روی
     * فاکتورش دیده نباید بی‌خبر عوض شود.
     *
     * قاعده یکی است و برای هر سه زبان یکی است: اگر تاشدهٔ نامِ شهر در جدولِ
     * **خودمان** (`CloudLocation::CITIES_FA`) نباشد، ما آن شهر را نمی‌شناسیم و
     * چیزی نمی‌گوییم. این سه دروغِ اندازه‌گیری‌شده را با هم می‌بندد:
     *   · پایتخت به‌جای شهر («برلین» برای ردیفی که ستونِ شهرش «AMD» است)،
     *   · توکنِ خامِ کد به‌جای شهر («ist» وسطِ یک صفحهٔ راست‌به‌چپ)،
     *   · و لاتینِ ترجمه‌نشده در فارسی («Zurich» کنارِ «فرانکفورت»).
     */
    private static function trustedCityName(CloudLocation $l): ?string
    {
        $raw = trim((string) $l->city);

        if ($raw === '') {
            return null;
        }

        $fold = \App\Services\Cloud\CloudNaming::cityFold($raw);

        if (! isset(CloudLocation::CITIES_FA[$fold])) {
            return null;
        }

        return app()->getLocale() === 'fa' ? CloudLocation::CITIES_FA[$fold] : $raw;
    }

    /**
     * هیچ دو کنترلِ شهری در یک کشور نباید متنِ یکسان داشته باشد.
     *
     * 🔴 چرا این‌جا و نه در کلیدِ سطل: کلید از قبل درست بود — تکرار روی محورِ
     * **برچسب** ساخته می‌شد. رندرِ واقعیِ صفحه (نه بازگشتیِ یک تابع) نشان داد
     * آلمان سه «برلین»، هلند سه «آمستردام»، فرانسه دو «پاریس» و ایران دو
     * «تهران» چاپ می‌کند؛ همه با کلیدهای یکتا.
     *
     * ⚠️ هیچ سطلی ادغام نمی‌شود. هر سطل لینکِ خودش را نگه می‌دارد، پس
     * «دسترس‌پذیری» ساختاری است نه وابسته به درستیِ یک هش. نردبانِ تفکیک:
     *   ۱) شهرِ شناخته‌شده → نامِ خودش.
     *   ۲) شهرِ ناشناخته → نامِ جا نمی‌گیرد؛ فقط تفاوت‌های واقعی: «از X تومان ·
     *      تا N هسته». عنوانِ صادقانه‌شان یک بار بالای گروه می‌آید.
     *   ۳) اگر باز هم متن‌ها یکی شد، شمارهٔ ترتیبیِ پایدار.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private static function disambiguate(array $rows): array
    {
        // شهرهای شناخته‌شده اول، «سایر مکان‌ها» آخر — تا عنوانِ گروه یک بار و
        // در یک جا بنشیند و فهرست تکراری به نظر نرسد.
        usort($rows, fn ($a, $b) => ((int) $a['generic']) <=> ((int) $b['generic']));

        foreach ($rows as $i => $r) {
            if ($r['city'] !== null) {
                $rows[$i]['label'] = (string) $r['city'];

                continue;
            }

            $bits = [];

            if ((int) $r['irt'] > 0) {
                $bits[] = __('ui.cvb_from', ['amount' => cloud_price((int) $r['irt'])]);
            }

            if ((int) $r['cores'] > 0) {
                $bits[] = __('ui.cvb_upto_cores', ['n' => fa_num((int) $r['cores'])]);
            }

            $rows[$i]['label'] = $bits === []
                ? __('ui.cvb_city_other_n', ['n' => fa_num($i + 1)])
                : implode(' · ', $bits);
        }

        // پلهٔ آخر: هر متنی که هنوز تکراری است، شمارهٔ ترتیبیِ پایدار می‌گیرد.
        $seen = [];

        foreach ($rows as $r) {
            $seen[$r['label']] = ($seen[$r['label']] ?? 0) + 1;
        }

        $nth = [];

        foreach ($rows as $i => $r) {
            if (($seen[$r['label']] ?? 0) < 2) {
                continue;
            }

            $nth[$r['label']] = ($nth[$r['label']] ?? 0) + 1;
            $rows[$i]['label'] = $r['label'].' ('.fa_num($nth[$r['label']]).')';
        }

        return array_values($rows);
    }

    /**
     * لنگرِ «از چند تومان» و «تا چند هسته» برای هر کدِ مکان — **یک** پرس‌وجوی
     * گروهی، نه یکی به‌ازای هر کشور یا هر شهر.
     *
     * @return array<string, array{irt:int,cores:int}>
     */
    private static function priceAnchors(): array
    {
        $out = [];

        $rows = CloudPlan::query()->sellable()
            ->selectRaw('location_code, MIN(price_irt) as min_irt, MAX(vcpu) as max_vcpu')
            ->groupBy('location_code')->get();

        foreach ($rows as $r) {
            $out[(string) $r->location_code] = [
                'irt' => (int) $r->min_irt,
                'cores' => (int) $r->max_vcpu,
            ];
        }

        return $out;
    }

    /**
     * ناحیهٔ جغرافیاییِ یک کشور — فقط برای گروه‌بندیِ فهرستِ کشورها.
     *
     * ⚠️ سفیدبرچسبی: گروه‌بندی **مطلقاً جغرافیایی** است. هیچ ناحیه‌ای نباید با
     * ردِ پای یک زیرساخت یک‌به‌یک بخوانَد، وگرنه نقشه لباسِ نشتِ نام می‌شود.
     * (امروز هر سه ناحیه چند زیرساخت دارند.)
     *
     * ⚠️ و هیچ عددِ تأخیری این‌جا نیست: فقط `HetznerClient` مختصات می‌دهد و دو
     * درایورِ دیگر `null` برمی‌گردانند، پس هر «ms» ساختگی می‌بود.
     */
    public const REGIONS = [
        // ترتیبِ داخلِ هر ناحیه = ترتیبِ نمایش. ایران اولِ ناحیهٔ خودش است چون
        // مخاطبِ اولِ این صفحه فارسی‌زبان است و نزدیک‌ترین گزینه باید اول بیاید.
        'me' => ['IR', 'TR', 'AE', 'AM', 'GE', 'KZ'],
        'eu' => ['DE', 'NL', 'FI', 'FR', 'GB', 'CH', 'AT', 'SE', 'PL', 'CZ', 'ES', 'IT', 'UA', 'RU'],
    ];

    public static function regionOf(string $iso): string
    {
        $iso = strtoupper($iso);

        foreach (self::REGIONS as $key => $list) {
            if (in_array($iso, $list, true)) {
                return $key;
            }
        }

        return 'other';
    }

    /** رتبهٔ کشور داخلِ ناحیه‌اش؛ ناشناخته آخر می‌نشیند، پس ترتیب هرگز تصادفی نیست. */
    public static function countryRank(string $iso): int
    {
        $list = self::REGIONS[self::regionOf($iso)] ?? [];
        $i = array_search(strtoupper($iso), $list, true);

        return $i === false ? 900 : (int) $i;
    }

    /**
     * آیا این ایمیج برای این عرضه واقعاً قابلِ تحویل است؟
     *
     * @param  array<int,string>  $slugProviders  زیرساخت‌هایی که این اسلاگ را می‌فروشند
     * @param  Collection<int,CloudImage>  $rows  همهٔ ردیف‌های همین کلید (هر زیرساخت یک ردیف)
     */
    private static function deliverable(CloudPlan $offer, array $slugProviders, Collection $rows): bool
    {
        foreach ($rows as $row) {
            if (! in_array((string) $row->provider, $slugProviders, true)) {
                continue;                                 // این زیرساخت این اسلاگ را نمی‌فروشد
            }

            if ((int) $row->min_disk_gb > (int) $offer->disk_gb) {
                continue;                                 // روی این دیسک جا نمی‌شود
            }

            // معماری: ایمیجِ x86 روی پلنِ arm بالا نمی‌آید و برعکس
            if (filled($row->arch) && filled($offer->arch) && (string) $row->arch !== (string) $offer->arch) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * ایمیج‌های قابلِ تحویل برای یک عرضه — کلیدِ یکسان‌شده، بی‌تکرار.
     *
     * @return array<int,string> کلیدها
     */
    public static function imageKeysFor(CloudPlan $offer, string $kind = 'os'): array
    {
        $providers = CloudPlan::query()->sellable()
            ->where('slug', (string) $offer->slug)
            ->pluck('provider')->unique()->values()->all();

        $byKey = CloudImage::query()->usable()->where('kind', $kind)->get()->groupBy('key');

        $out = [];

        foreach (CloudImage::catalog($kind) as $img) {
            $rows = $byKey->get((string) $img->key, collect());

            if (self::deliverable($offer, $providers, $rows)) {
                $out[] = (string) $img->key;
            }
        }

        return $out;
    }

    // ═════════════════════════ نمایش ═════════════════════════

    public function index(Request $request): View
    {
        // نمایش از «قفسه» می‌خوانَد (شاملِ مکانِ تمام‌شده)، ولی «کدام‌ها واقعاً باز
        // است» جدا نگه داشته می‌شود تا کشورِ خاکستری با کشورِ فروختنی قاطی نشود.
        $groups = $this->shelfLocationGroups();

        $openCodes = [];
        foreach ($this->locationGroups() as $g) {
            foreach ($g['locations'] as $l) {
                $openCodes[] = (string) $l->code;
            }
        }

        // مکانِ انتخابی از query می‌آید (لینکِ ساده، بی‌جاوااسکریپت هم کار کند)
        $allCodes = [];
        foreach ($groups as $g) {
            foreach ($g['locations'] as $l) {
                $allCodes[] = (string) $l->code;
            }
        }

        /*
        | کارتِ کشور + شهرهای یکتاشده. یکتاسازی **نمایشی** است: `$allCodes` بالا
        | از همان `$g['locations']`ِ دست‌نخورده ساخته می‌شود، پس هیچ کدی از
        | فهرستِ مجاز بیرون نمی‌افتد و هر عضوِ هر گروه هنوز لینکِ خودش را دارد.
        |
        | ⚠️ شمارِ روی کارتِ کشور = تعدادِ **شهرِ باز**، نه count($g['locations']).
        | آن یکی همان تکرارها را دوباره می‌شمرد («۵ مکان» برای یک برلین) و
        | `CloudCountry::served()` هم شهرهای خام را حساس‌به‌حروف می‌شمارد.
        */
        $anchors = self::priceAnchors();

        foreach ($groups as $i => $g) {
            $cities = self::cityBuckets($g['locations'], $openCodes, $anchors);

            $groups[$i]['cities'] = $cities;
            $groups[$i]['openCities'] = count(array_filter($cities, fn ($c) => $c['open']));
            $groups[$i]['region'] = self::regionOf((string) $g['country']);

            // لنگرِ «از … تومان» روی ردیفِ کشور: کمینهٔ همان قیمت‌هایی که شهرها
            // نشان می‌دهند. یک منبع، پس دو عددِ متناقض ساختاراً ممکن نیست.
            $low = 0;

            foreach ($g['locations'] as $l) {
                $a = $anchors[(string) $l->code] ?? null;

                if ($a === null || (int) $a['irt'] <= 0) {
                    continue;
                }

                $low = $low === 0 ? (int) $a['irt'] : min($low, (int) $a['irt']);
            }

            $groups[$i]['fromIrt'] = $low;

            // نخستین شهرِ **باز** — مقصدِ ردیفِ کشور در مرحلهٔ ۱.
            $entry = null;

            foreach ($cities as $c) {
                if ($c['open'] && $entry === null) {
                    $entry = (string) $c['primary']->code;
                }
            }

            $groups[$i]['entry'] = $entry ?? (string) ($g['locations'][0]->code ?? '');
        }

        // ترتیبِ نمایش: ناحیه، بعد رتبهٔ کشور در همان ناحیه.
        usort($groups, fn ($a, $b) => [array_search($a['region'], ['me', 'eu', 'other'], true),
            self::countryRank((string) $a['country'])]
            <=> [array_search($b['region'], ['me', 'eu', 'other'], true),
                self::countryRank((string) $b['country'])]);

        /*
        | پیش‌فرضِ مکان: نخستین شهری که **نامش را می‌دانیم**، نه صرفاً نخستین کدِ
        | فروختنی. بی‌این، صفحه روی یک ردیفِ «سایر مکان‌ها» باز می‌شد و برگه
        | «آلمان — از ۳۱۸,۰۰۰ تومان» می‌گفت: درست، ولی سردترین ممکن برای کسی که
        | تازه رسیده. پس‌افت دقیقاً رفتارِ دیروز است.
        */
        $namedFirst = null;

        foreach ($groups as $g) {
            foreach ($g['cities'] as $c) {
                if ($c['open'] && ! $c['generic'] && $namedFirst === null) {
                    $namedFirst = (string) $c['primary']->code;
                }
            }
        }

        // `location` نامِ رسمی است — صفحات عمومیِ سایت با همین به این‌جا لینک
        // می‌دهند (`?location=de-frankfurt&plan=…`). `loc` هم پذیرفته می‌شود تا
        // لینکِ قدیمی/کوتاه نشکند.
        //
        // ⚠️ `is_string`: با `?location[]=x` مقدار آرایه می‌شود و cast به string
        // اخطار می‌دهد؛ لاراول اخطار را به استثنا تبدیل می‌کند و صفحه ۵۰۰ می‌شود.
        // یک خزندهٔ بدرفتار نباید صفحهٔ فروش را بخواباند.
        $wanted = $request->query('location') ?? $request->query('loc') ?? '';
        $wanted = is_string($wanted) ? $wanted : '';

        /*
        | 🔴 «هنوز کشوری انتخاب نشده» یک وضعیتِ **واقعی** است، نه یک شاخهٔ مرده.
        |
        | کارفرما خواست مرحلهٔ شهر پیش از انتخابِ کشور بگوید چه لازم دارد. تا
        | دیروز چنین وضعی وجود نداشت چون این خط همیشه یک مکان را از پیش انتخاب
        | می‌کرد — و کامنتِ خودِ Blade هم نوشته بود که جملهٔ راهنما به همین دلیل
        | حذف شد. حالا `?location=` (خالی و صریح) دقیقاً همان وضع را می‌سازد:
        | مرحلهٔ ۱ باز، مرحلهٔ ۲ با حالتِ خالیِ صادق و راهِ بازگشت، و برگه «—».
        | لینکِ «تغییر کشور» روی همین صفحه همین آدرس را می‌سازد، پس شاخه هم
        | رسیدنی است هم تست‌شده — نه یک `if` که هرگز اجرا نمی‌شود.
        |
        | بدونِ پارامتر، همچنان یک مکان از پیش انتخاب می‌شود (وگرنه مرحلهٔ ۳ تا ۵
        | و برگه روی نخستین بازدید خالی می‌مانند) — فقط حالا `$namedFirst`ِ
        | بالا ترجیح دارد، یعنی شهری که نامش را می‌دانیم.
        */
        // ⚠️ `query('location')` این‌جا برای `?location=` مقدارِ **null** می‌دهد،
        // نه رشتهٔ خالی: میان‌افزارِ `ConvertEmptyStringsToNull` پیش از ما رد شده.
        // پس «آمده ولی خالی» را باید از **وجودِ کلید** پرسید، نه از مقدارش —
        // وگرنه این شاخه دقیقاً همان `if`ِ همیشه-غلط می‌شد که قرار بود نباشد.
        $blank = $wanted === ''
            && ($request->query->has('location') || $request->query->has('loc'));

        $code = in_array($wanted, $allCodes, true)
            ? $wanted
            : ($blank ? null : ($namedFirst ?? $openCodes[0] ?? $allCodes[0] ?? null));

        /*
        | 🔴 دو خطِ محصول در یک ویترین قاطی نشوند (گزارشِ کارفرما، شهریور ۱۴۰۵):
        | مشتریِ /gpu بدونِ این جداسازی وسطِ شهرهای VPS می‌افتاد و اسلاگِ GPUاش
        | بی‌صدا به اولین پلنِ VPS می‌غلتید («planMoved»). کشورِ ساختگیِ XX
        | (شبکهٔ توزیع‌شدهٔ GPU) نشانهٔ خطِ محصولِ جداست:
        |   - در حالتِ GPU (مکانِ انتخابی XX است) فقط همان گروه می‌مانَد؛
        |   - در حالتِ عادی گروهِ XX از پیکربند حذف می‌شود — ورودی‌اش صفحهٔ /gpu
        |     است، نه ویترینِ سرورِ مجازی.
        | ⚠️ عمداً بعد از ساختِ $allCodes: کدِ global-gpu همیشه آدرس‌پذیر می‌مانَد.
        */
        $gpuMode = false;
        foreach ($groups as $g) {
            foreach ($g['locations'] as $l) {
                if ((string) $l->code === $code && strtoupper((string) $l->country) === 'XX') {
                    $gpuMode = true;
                }
            }
        }
        $groups = array_values(array_filter($groups,
            fn ($g) => (strtoupper((string) $g['country']) === 'XX') === $gpuMode));

        $location = null;
        foreach ($groups as $g) {
            foreach ($g['locations'] as $l) {
                if ((string) $l->code === $code) {
                    $location = $l;
                }
            }
        }

        // ── پلنِ خواسته‌شده در لینک (صفحات بازاریابی: ?location=…&plan=…) ──
        // زود خوانده می‌شود چون فیلترِ نمایشیِ پایین **نباید** چیزی را حذف کند که
        // یک لینکِ ورودی به آن اشاره دارد.
        $wantedPlan = $request->query('plan', '');
        $wantedPlan = is_string($wantedPlan) ? $wantedPlan : '';

        /** @var Collection<string, CloudPlan> $shelf */
        $shelf = $code ? CloudPlan::shelf($code) : collect();

        // فروختنی‌ها از «فقط گذرا ناموجود»ها جدا می‌شوند: اولی خریدنی است، دومی
        // فقط دیدنی. هیچ‌کدام بی‌صدا غیب نمی‌شود (باگِ صفحات کشور، §۱۰.۵).
        $offers = $shelf->filter(fn (CloudPlan $p) => $p->blockedReason() === null);
        $blocked = $shelf->reject(fn (CloudPlan $p) => $p->blockedReason() === null);

        /*
        | حذفِ پلنِ مغلوب — همان فیلترِ پارتو که صفحهٔ بازاریابی از قبل دارد
        | (`CatalogController`). بی‌آن، صفحهٔ **خرید** آشغالِ بیشتری از بروشور
        | نشان می‌داد: «نصفِ پردازنده، دو برابرِ قیمت» کنارِ گزینهٔ درست.
        |
        | ⚠️ اسلاگی که در لینکِ ورودی آمده هرگز حذف نمی‌شود. یک فیلترِ نمایشی
        | نباید چیزی را که یک لینک به آن اشاره دارد ناپدید کند.
        */
        if ($offers->count() > 1) {
            $keep = $offers->get($wantedPlan);
            $offers = \App\Services\Cloud\CloudDominance::prune($offers->values())->keyBy('slug');

            if ($keep !== null && ! $offers->has($wantedPlan)) {
                $offers = $offers->put($wantedPlan, $keep);
            }

            $offers = $offers->sortBy([['vcpu', 'asc'], ['ram_mb', 'asc'], ['disk_gb', 'asc']]);
        }

        // ── ایمیج‌ها: فهرستِ نمایشیِ یکسان‌شده + نقشهٔ «کدام پلن کدام‌ها را دارد»
        $osCatalog = CloudImage::catalog('os');
        $appCatalog = CloudImage::catalog('app');

        $rowsByKey = CloudImage::query()->usable()->get()->groupBy('key');

        /*
        | یک پرس‌وجو به‌جای دو تا **و** به‌جای N تا.
        |
        | قبلاً `hourlyMap` برای هر کارت یک `bestForSlug()` می‌زد (یک کوئریِ کاملِ
        | sellable برای هر پلن) در حالی که همان ردیف‌ها همین‌جا در دست بودند.
        | ردیف‌های هم‌اسلاگ یک بار خوانده می‌شوند و سه چیز از آن‌ها درمی‌آید:
        | زیرساخت‌ها (برای تحویل‌شدنیِ ایمیج)، نرخِ ساعتی، و توانِ IP اضافه.
        */
        $sellableRows = CloudPlan::query()->sellable()
            ->whereIn('slug', $offers->keys()->all())
            ->orderBy('cost_eur_cents')
            ->get()
            ->groupBy('slug');

        $addonSvc = app(CloudAddons::class);
        $manager = app(\App\Services\Cloud\CloudManager::class);

        $imageMap = [];     // slug => ['os' => [key,…], 'app' => [key,…]]
        $priceMap = [];     // slug => cycle => ['cycle','per','first','save']
        $planCards = [];    // دادهٔ نمایشیِ امن (بی‌هیچ ستونِ زیرساخت)
        $hourlyMap = [];    // slug => ['rate','min']
        $addonMap = [];     // slug => bool  (آیا IP اضافه روی این اسلاگ شدنی است)

        $cycles = self::cycles();
        $taxPct = self::taxPercent();

        foreach ($offers as $slug => $offer) {
            $rows = $sellableRows->get((string) $slug, collect());
            $slugProviders = $rows->pluck('provider')->unique()->values()->all();

            foreach (['os' => $osCatalog, 'app' => $appCatalog] as $kind => $catalog) {
                $keys = [];

                foreach ($catalog as $img) {
                    if (self::deliverable($offer, $slugProviders, $rowsByKey->get((string) $img->key, collect()))) {
                        $keys[] = (string) $img->key;
                    }
                }

                $imageMap[$slug][$kind] = $keys;
            }

            foreach ($cycles as $cy) {
                $price = self::priceForCycle($offer, $cy);
                $priceMap[$slug][$cy] = [
                    'cycle' => $price,
                    'per' => self::monthlyEquivalent($offer, $cy),
                    'first' => $price + (int) round($price * $taxPct / 100),
                    'save' => (int) (config('billing.cycles.'.$cy.'.discount_pct') ?? 0),
                ];
            }

            $planCards[] = [
                'slug' => (string) $slug,
                'name' => (string) $offer->public_name,
                // مدلِ کارتِ گرافیک — بی‌این، دو پلنِ GPU با هسته/رم/دیسکِ یکسان
                // دو کارتِ بایت‌به‌بایت یکسان می‌شوند و مشتری «تکراری» می‌بیند
                'gpu' => trim((string) $offer->gpu_model),
                'vcpu' => (int) $offer->vcpu,
                'ram' => $offer->ramLabel(),
                'disk' => $offer->diskLabel(),
                'traffic' => $offer->trafficLabel(),
                'cpu' => $offer->cpuKindLabel(),
                'cpuKind' => (string) $offer->cpu_kind === 'dedicated' ? 'dedicated' : 'shared',
                /*
                | کلیدهای مرتب‌سازیِ «برگهٔ مقایسه» — عددِ خام و اسکیِ، چون برچسبِ
                | دیداری هرگز قابلِ مرتب‌سازی نیست: `fa_num()` رقم‌ها را فارسی
                | می‌کند و واحدها قاطی‌اند («۵۱۲ MB» کنارِ «۴ GB»).
                |
                | ⚠️ هیچ‌کدام از این‌ها **قیمت** نیست. مرتب‌سازی بر پایهٔ مبلغ در
                | جاوااسکریپت از `D.prices` خوانده می‌شود، پس هیچ عددِ پولی‌ای
                | دو بار در DOM نمی‌نشیند و ادعای «قیمتِ پلنِ ناموجود هیچ‌جا
                | نیست» ساختاری می‌مانَد، نه تصادفی.
                |
                | ⚠️ `trafficGb` فقط وقتی رندر می‌شود که برچسبِ ترافیک در این
                | مکان **یکسان نباشد**. با کلیدِ `cloud_traffic_unlimited` همهٔ
                | برچسب‌ها «نامحدود» می‌شوند و آن‌وقت چاپِ سقفِ واقعی، دو اینچ
                | پایین‌ترِ یک وعدهٔ «نامحدود»، خودش یک دروغ است.
                */
                'ramMb' => (int) $offer->ram_mb,
                'diskGb' => (int) $offer->disk_gb,
                'trafficGb' => (int) $offer->traffic_gb,
            ];

            $hourlyMap[(string) $slug] = [
                'rate' => $offer->hourlyIrt(),
                'min' => $offer->hourlyStartMinIrt(),
            ];

            // ⚠️ به‌ازای **هر اسلاگ**، نه فقط اسلاگِ انتخابی. قبلاً یک بولینِ واحد
            // بود و عوض‌کردنِ پلن، انتخابگرِ IP را روی پلنی جا می‌گذاشت که سرِ
            // ثبتِ سفارش ردش می‌کرد.
            $addonMap[(string) $slug] = $rows->contains(
                fn (CloudPlan $p) => $addonSvc->planSupports($p, ['extra_ipv4' => 1], $manager)
            );
        }

        // کارت‌های «هست ولی الان نمی‌شود خرید» — با دلیلِ صادق، بی‌قیمت.
        // ⚠️ `data-uslug` نه `data-slug`: تستِ گروه‌بندی دقیقاً تعدادِ `data-slug`
        // را می‌شمارد و یک ردیفِ ناموجود نباید آن شمارش را به‌هم بزند.
        $blockedCards = $blocked->map(fn (CloudPlan $p) => [
            'slug' => (string) $p->slug,
            'name' => (string) $p->public_name,
            'gpu' => trim((string) $p->gpu_model),
            'vcpu' => (int) $p->vcpu,
            'ram' => $p->ramLabel(),
            'disk' => $p->diskLabel(),
            'traffic' => $p->trafficLabel(),
            'cpu' => $p->cpuKindLabel(),
            'cpuKind' => (string) $p->cpu_kind === 'dedicated' ? 'dedicated' : 'shared',
            'reason' => (string) $p->blockedReason(),
        ])->values()->all();

        // پلنِ پیش‌انتخاب‌شده (لینکِ «خرید» صفحات عمومی: ?location=…&plan=…)
        $selectedSlug = $wantedPlan;
        $planMoved = false;

        if (! isset($imageMap[$selectedSlug])) {
            // ⚠️ جابه‌جاییِ بی‌صدا ممنوع: اگر لینک/انتخابِ قبلی به اسلاگی اشاره دارد
            // که این‌جا نیست، صفحه باید بگوید — نه اینکه چیزِ دیگری را انتخاب کند و
            // مشتری فکر کند خودش اشتباه کرده.
            $planMoved = $selectedSlug !== '' && $planCards !== [];
            $selectedSlug = (string) ($planCards[0]['slug'] ?? '');
        }

        // ⚠️ ایمیجی که برای **هیچ** پلنِ این مکان قابلِ تحویل نیست، حتی پنهان هم
        // به HTML نمی‌رود. اگر فقط با CSS پنهانش می‌کردیم، در سورس دیده می‌شد و
        // بدتر: با یک دستکاریِ ساده قابلِ ارسال بود.
        $union = collect($imageMap);
        $osCatalog = $osCatalog
            ->whereIn('key', $union->pluck('os')->flatten()->unique()->all())->values();
        $appCatalog = $appCatalog
            ->whereIn('key', $union->pluck('app')->flatten()->unique()->all())->values();

        return view('account.cloud-store', AccountController::shell('store') + [
            'groups' => $groups,
            // لنگرِ قیمت به‌ازای هر کد — ردیفِ کشور، ردیفِ شهر و عضوِ دیتاسنتر
            // همه از همین می‌خوانند، پس دو «از … تومان»ِ متناقض ممکن نیست.
            'anchors' => $anchors,
            'openCodes' => $openCodes,
            'location' => $location,
            'locCode' => $code,
            'planCards' => $planCards,
            'blockedCards' => $blockedCards,
            'planMoved' => $planMoved,
            // خطِ GPU: فرم ساده‌تر — کلیدِ SSH و IPِ اضافه برای کانتینر بی‌معناست
            'gpuMode' => $gpuMode,
            'selectedSlug' => $selectedSlug,
            'osCatalog' => $osCatalog,
            'appCatalog' => $appCatalog,
            'imageMap' => $imageMap,
            'priceMap' => $priceMap,
            'cycles' => $cycles,
            'cycleLabels' => collect($cycles)->mapWithKeys(fn ($c) => [$c => Service::labelFor($c)])->all(),
            // ماه‌های هر دوره — جاوااسکریپت با همین، بهایِ IP اضافه را به مبلغِ
            // دوره اضافه می‌کند؛ وگرنه دکمه یک عدد نشان می‌دهد و فاکتور عددِ دیگر.
            'cycleMonths' => collect($cycles)->mapWithKeys(fn ($c) => [$c => Service::monthsIn($c)])->all(),
            /*
            | دوره و سیستم‌عاملِ آمده در آدرس هم مثلِ `plan` احترام می‌شوند.
            | چیپِ شهر این سه را روی لینکِ خودش سوار می‌کند، پس عوض‌کردنِ شهر
            | دیگر بی‌صدا انتخاب‌های قبلی را دور نمی‌ریزد (GET است و `old()`
            | خالی است، پس بدونِ این، هر تعویضِ مکان یعنی شروع از صفر).
            */
            'defCycle' => (function () use ($request, $cycles) {
                $q = $request->query('cycle');

                return is_string($q) && in_array($q, $cycles, true) ? $q : self::defaultCycle();
            })(),
            'wantImage' => is_string($request->query('image')) ? (string) $request->query('image') : '',
            'taxPct' => $taxPct,
            'autoLabel' => self::serverLabel(null),

            // ── فروشِ ساعتی ──
            // نرخِ ساعتیِ هر پلن + حداقلِ اعتبارِ شروع (۲۴ ساعت) و موجودیِ فعلیِ
            // مشتری، تا صفحه بتواند پیش از ثبتِ سفارش بگوید اعتبار کافی است یا نه.
            'hourlyMap' => $hourlyMap,
            'creditIrt' => (int) (Auth::guard('customer')->user()?->creditBalance('IRT') ?? 0),

            // ── افزودنی‌ها ──
            // به‌ازای هر اسلاگ، نه یک بولینِ سراسری: گزینه‌ای که سرِ ثبتِ سفارش رد
            // می‌شود، بدترین نوعِ رابطِ کاربری است.
            'addonMap' => $addonMap,
            'addonOk' => (bool) ($addonMap[$selectedSlug] ?? false),
            'extraIpPrice' => $addonSvc->extraIpMonthlyToman(),
            'maxExtraIp' => CloudAddons::MAX_EXTRA_IP,
            'sshKeys' => \App\Models\CloudSshKey::where('customer_id', (int) (\Illuminate\Support\Facades\Auth::guard('customer')->id() ?? 0))
                ->orderByDesc('last_used_at')->orderBy('name')->get(),
        ]);
    }

    // ═════════════════════════ ثبتِ سفارش ═════════════════════════

    /**
     * سفارش: سرویسِ pending + پیش‌فاکتور، بعد همان مسیرِ پرداختِ موجود.
     *
     * هیچ جریانِ پرداختِ تازه‌ای ساخته نمی‌شود — دقیقاً مثلِ `StoreController`
     * به `account.invoice` می‌رویم تا مشتری با درگاه/اعتبار/واریز پرداخت کند.
     */
    public function order(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();

        if ($customer === null) {
            abort(403);
        }

        // محدودیتِ نرخ روی **مشتری** (نه IP): سفارشِ سرور یک عملِ سنگین است و
        // هر ردیفِ pending یک پیش‌فاکتورِ واقعی می‌سازد. IP به‌تنهایی هم کاربرِ
        // موبایل/وای‌فای را بی‌دلیل می‌بندد و هم جلوی سیل را نمی‌گیرد.
        $key = 'cloud-order:'.$customer->id;

        if (RateLimiter::tooManyAttempts($key, 6)) {
            return back()->withInput()->withErrors(
                __('ui.cvb_e_rate', ['sec' => fa_num(RateLimiter::availableIn($key))])
            );
        }

        RateLimiter::hit($key, 60);

        $data = $request->validate([
            'location' => ['required', 'string', 'max:32'],
            'plan' => ['required', 'string', 'max:96'],
            'image' => ['required', 'string', 'max:64'],
            'cycle' => ['required', 'string', Rule::in(self::cycles())],
            // نامِ دلخواه: پاک‌سازی می‌شود، پس اعتبارسنجی‌اش سخت‌گیر نیست
            'label' => ['nullable', 'string', 'max:64'],
            // افزودنی‌ها — تعدادِ IP اضافه و کلیدِ SSH
            'extra_ipv4' => ['nullable', 'integer', 'min:0', 'max:'.CloudAddons::MAX_EXTRA_IP],
            'ssh_key_id' => ['nullable', 'integer'],
            'ssh_key_new' => ['nullable', 'string', 'max:6000'],
            'ssh_key_name' => ['nullable', 'string', 'max:60'],
            // فروشِ ساعتی (پیش‌پرداخت از کیفِ پول)
            'billing_mode' => ['nullable', 'string', Rule::in(['cycle', 'hourly'])],
            'on_credit_out' => ['nullable', 'string', Rule::in(['suspend', 'convert', 'terminate'])],
        ], [], [
            'location' => __('ui.cvb_a_location'), 'plan' => __('ui.cvb_a_plan'),
            'image' => __('ui.cvb_a_image'), 'cycle' => __('ui.cvb_a_cycle'),
            'label' => __('ui.cvb_a_label'), 'extra_ipv4' => __('ui.cvb_a_ip'),
        ]);

        // 🔴 دروازهٔ فروشِ ایران — مکانِ ir-* فقط برای مشتریِ احرازشده
        if (\App\Services\Customer\IranSalesGate::blocks($customer, (string) $data['location'])) {
            return back()->withInput()->withErrors(['location' => \App\Services\Customer\IranSalesGate::message()]);
        }

        // ── مکان باید همین حالا موجودی داشته باشد ──
        $codes = [];
        foreach ($this->locationGroups() as $g) {
            foreach ($g['locations'] as $l) {
                $codes[] = (string) $l->code;
            }
        }

        if (! in_array($data['location'], $codes, true)) {
            return back()->withInput()->withErrors(['location' => __('ui.cvb_e_loc')]);
        }

        // ── پلن باید در همین مکان قابلِ فروش باشد ──
        // `offers()` خودش sellable و ارزان‌ترین را می‌دهد؛ پس پلنِ غیرفعال،
        // ناموجود، قیمت‌صفر یا مربوط به مکانِ دیگر این‌جا پیدا نمی‌شود.
        $offer = CloudPlan::offers($data['location'])->get($data['plan']);

        if ($offer === null) {
            return back()->withInput()->withErrors(['plan' => __('ui.cvb_e_plan')]);
        }

        // ── ایمیج باید روی همین پلن قابلِ تحویل باشد ──
        // ورودیِ دلخواهِ کاربر هرگز مستقیم به API نمی‌رود؛ فقط کلیدی که خودمان
        // ساخته‌ایم و دستِ‌کم یک زیرساختِ فروشندهٔ همین اسلاگ داردش.
        $allowed = array_merge(
            self::imageKeysFor($offer, 'os'),
            self::imageKeysFor($offer, 'app'),
        );

        if (! in_array($data['image'], $allowed, true)) {
            return back()->withInput()->withErrors(['image' => __('ui.cvb_e_image')]);
        }

        $image = CloudImage::query()->usable()->where('key', $data['image'])->first();
        $cycle = (string) $data['cycle'];

        // ── افزودنی‌ها ──
        // ⚠️ تعداد از ورودی می‌آید ولی **قیمت هرگز**؛ قیمت را CloudAddons از
        // تنظیمات و نرخِ روز می‌سازد. عددِ منفی/اعشاری/رشته هم در sanitize
        // کران‌دار می‌شود، وگرنه یک `extra_ipv4 = -3` مبلغِ کل را کم می‌کرد.
        $addonSvc = app(CloudAddons::class);
        $addons = $addonSvc->sanitize(['extra_ipv4' => $data['extra_ipv4'] ?? 0]);

        // اگر افزودنیِ پولی خواسته، باید زیرساختی باشد که بتواند تحویلش دهد —
        // وگرنه پول گرفته‌ایم و وعده‌ای داده‌ایم که انجام نمی‌شود.
        if (! $addonSvc->isEmpty($addons)
            && $addonSvc->bestPlanFor((string) $offer->slug, $addons, app(\App\Services\Cloud\CloudManager::class)) === null) {
            return back()->withInput()->withErrors([
                'extra_ipv4' => __('ui.cvb_e_ip'),
            ]);
        }

        // ── کلیدِ SSH ──
        $sshKey = $this->resolveSshKey($customer, $data);

        if ($sshKey instanceof \Illuminate\Http\RedirectResponse) {
            return $sshKey;                        // خطای اعتبارسنجیِ کلید
        }

        // ── مبلغ از دیتابیس، نه از ورودی ──
        $price = self::priceForCycle($offer, $cycle, $addons);

        if ($price <= 0) {
            return back()->withInput()->withErrors(['plan' => __('ui.cvb_e_price')]);
        }

        $taxPct = self::taxPercent();
        $label = self::serverLabel($data['label'] ?? null);

        $location = CloudLocation::where('code', (string) $offer->location_code)->first();
        $locText = $location
            ? trim($location->flagEmoji().' '.$location->label('fa'))
            : (string) $offer->location_code;

        $description = implode("\n", array_filter([
            'مشخصات: '.fa_num((int) $offer->vcpu).' هسته · '.$offer->ramLabel().' رم · '
                .$offer->diskLabel().' · ترافیک '.$offer->trafficLabel('fa'),
            'مکان: '.$locText,
            'سیستم‌عامل: '.($image?->label ?: $data['image']),
            'نامِ سرور: '.$label,
            ($addons['extra_ipv4'] ?? 0) > 0
                ? 'IP اضافه: '.fa_num((int) $addons['extra_ipv4']).' عدد'
                : null,
            $sshKey !== null ? 'ورود با کلیدِ SSH: '.$sshKey->label() : null,
        ]));

        // ── مسیرِ ساعتی: پیش‌پرداخت از کیفِ پول، بی‌فاکتور ──
        if (($data['billing_mode'] ?? 'cycle') === 'hourly') {
            return $this->orderHourly($request, $customer, $offer, $data, $addons, $sshKey, $label, $locText, $description);
        }

        /*
        | 🔴 `$service` باید از تراکنش **برگردد**.
        |
        | قبلاً فقط `$invoice` برمی‌گشت و پایین‌تر `$service` استفاده می‌شد —
        | متغیری که فقط داخلِ closure وجود داشت. نتیجه: «Undefined variable»،
        | بعد `TypeError`، و بعد `catch (\Throwable)` که آن را می‌بلعید. پس
        | **هیچ ردیفِ خریدی در تاریخچهٔ سرویس ثبت نمی‌شد** و صفحهٔ تاریخچه برای
        | هر سرورِ ابری خالی می‌مانْد — با پاسخِ ۲۰۰ و بی‌هیچ خطایی.
        */
        [$service, $invoice] = DB::transaction(function () use ($customer, $offer, $data, $cycle, $price, $taxPct, $label, $description, $addons, $sshKey) {
            $service = Service::create([
                'customer_id' => $customer->id,
                'name' => mb_substr(__('ui.svc_name_vps', ['label' => $label]), 0, 150),
                'description' => $description,
                'currency_code' => 'IRT',
                'price' => $price,
                'tax_percent' => $taxPct,
                'cycle' => $cycle,
                'status' => 'pending',
                // ⚠️ نه `server_id` می‌دهیم نه `provision_status` — سرورِ ابری پیش
                // از خرید وجود ندارد و صفِ تحویل (`provision:run`) هر ردیفِ
                // `provision_status=pending` را می‌سازد **بی‌آنکه** پرداخت را
                // بسنجد. پس گذاشتنِ «pending» در لحظهٔ سفارش یعنی خریدنِ سرورِ
                // واقعی برای سفارشِ پرداخت‌نشده. صف بعد از پرداخت پر می‌شود.
                'cloud_plan_id' => $offer->id,
                'cloud_image_key' => (string) $data['image'],
                'cloud_ssh_key_id' => $sshKey?->id,
                // فقط **تعداد** ذخیره می‌شود، نه قیمت — تا دو منبعِ حقیقت نداشته
                // باشیم. قیمت همیشه از CloudAddons خوانده می‌شود.
                'cloud_addons' => $addons,
                // نامِ عمومیِ پلن (سفیدبرچسب) — به‌کارِ نمایش و پشتیبانی می‌آید
                'plan' => (string) $offer->public_name,
            ]);

            return [$service, $this->issueOrderInvoice($service, $offer, $label)];
        });

        try {
            ActivityLog::forService($service, 'purchase',
                'سفارشِ سرورِ مجازی «'.$label.'» — '.$offer->public_name.' · '.$locText
                .' · '.Service::labelFor($cycle).' توسط مشتری ثبت شد', 'customer', $request);
        } catch (\Throwable $e) {
            // ⚠️ لاگ نباید سفارش را بشکند — ولی **بی‌صدا هم نباید بمیرد**.
            //    همین catchِ خالی بود که باگِ بالا را پنهان نگه داشت.
            \App\Support\ErrorTracker::note('cloud', $e, ['area' => 'order-activity-log']);
        }

        // رویدادِ قیف (ممیزی ۶/شورا — رشد): مسیرِ ابری هم «سفارش ثبت شد» را می‌شمارد، وگرنه
        // قیفِ ابری در checkout_click قطع می‌شد. sid/ref از تحویلِ امضاشده اگر بوده.
        $ho = (array) session('order_handoff', []);
        Funnel::log('order_placed', ['sku' => 'cloud-'.$offer->id, 'cycle' => $cycle, 'sid' => $ho['sid'] ?? '', 'ref' => $ho['ref'] ?? '', 'handoff_cycle' => $ho['cycle'] ?? '', 'line' => 'cloud']);

        /*
        | 🔴 مشتری هم باید خبردار شود.
        |
        | قبلاً فقط `AdminNotifier` صدا زده می‌شد: مشتری یک سرورِ مجازی سفارش
        | می‌داد و **هیچ رسیدی** نمی‌گرفت — نه پیامک، نه بله، نه ایمیل. تنها
        | نشانهٔ سفارش، پیامِ فلشِ صفحه بود که با یک رفرش می‌رفت.
        |
        | ⚠️ و کاتالوگ ادعا می‌کرد `service_ordered` و `invoice` «وصل»اند، چون
        | تستِ پوشش فراخوانِ **مسیرِ هاستِ اشتراکی** را پیدا می‌کرد. یعنی یک
        | رویدادِ وصل می‌تواند روی یکی از دو مسیرِ خرید مرده باشد و هیچ تستی
        | نفهمد — «وصل بودن» به‌ازای هر مسیر معنا دارد، نه یک بار برای همیشه.
        |
        | `Notifier::fire()` هر دو طرف را می‌گیرد، پس دیگر لازم نیست هر مسیر
        | جداگانه یادش باشد مدیر را هم خبر کند.
        */
        $amountText = fa_num(number_format((int) $invoice->total)).' تومان';

        try {
            $notifier = app(\App\Services\Notify\Notifier::class);

            // ⚠️ حتی برای مدیر هم نامِ زیرساخت نمی‌رود — فقط نامِ عمومیِ پلن
            $notifier->fire('service_ordered', $customer, [
                'service' => (string) $offer->public_name,
                'amount'  => $amountText,
            ], 'سفارشِ «'.$offer->public_name.'» ثبت شد. با پرداختِ پیش‌فاکتور، سرور خودکار تحویل می‌شود.',
                [
                    'مکان' => $locText,
                    'سیستم‌عامل' => (string) $data['image'],
                    'دوره' => Service::labelFor($cycle),
                ], url('/admin/customers/'.$customer->id), '🖥️');

            $notifier->fire('invoice', $customer, [
                'number' => (string) $invoice->number,
                'amount' => $amountText,
                'link'   => lroute('account.invoice', $invoice),
            ], 'پیش‌فاکتور '.$invoice->number.' به مبلغ '.$amountText.' صادر شد.');
        } catch (\Throwable $e) {
            // اعلان نباید سفارش را بشکند — ولی سکوت هم نباید بکند
            \App\Support\ErrorTracker::note('notify', $e, ['area' => 'cloud-order']);
        }

        return redirect(lroute('account.invoice', $invoice))
            ->with('ok', __('ui.cvb_ok_ordered'));
    }

    /**
     * سفارشِ **ساعتی** — پیش‌پرداخت از کیفِ پول، بی‌فاکتور.
     *
     * قاعده (تأییدِ کارفرما): حداقلِ **`CloudPlan::HOURLY_START_MIN_HOURS` ساعت**
     * اعتبار برای شروع (امروز ۱۲)، ولی **بدونِ حداقلِ مصرف** — ساعتِ اول همین‌جا
     * کسر می‌شود؛ بقیه را کرونِ `cloud:meter` هر ساعت کم می‌کند و مشتری هر وقت
     * خواست لغو می‌کند (اعتبارِ مانده می‌مانَد).
     *
     * ⚠️ عددِ کف را این‌جا **دوباره حساب نکن**. تا مرداد ۱۴۰۵ همین‌جا یک
     * `$hourly * 24`ِ سخت‌کد بود که تنها چیزی بود که واقعاً خرید را می‌بست، در
     * حالی که صفحهٔ فروشگاه عددِ `CloudPlan::hourlyStartMinIrt()` را نشان می‌داد.
     * دو منبع یعنی دیر یا زود صفحه یک عدد بگوید و تسویه عددِ دیگری بخواهد.
     */
    private function orderHourly(Request $request, Customer $customer, CloudPlan $offer, array $data, array $addons, $sshKey, string $label, string $locText, string $description): RedirectResponse
    {
        $hourly = $offer->hourlyIrt();
        $hourlyEur = $offer->hourlyEurCents();

        if ($hourly <= 0) {
            return back()->withInput()->withErrors(['plan' => __('ui.cvb_e_hourly_price')]);
        }

        // افزودنیِ پولی روی ساعتی فعلاً پشتیبانی نمی‌شود (قیمتش ماهانه است)
        if (! app(CloudAddons::class)->isEmpty($addons)) {
            return back()->withInput()->withErrors(['extra_ipv4' => __('ui.cvb_e_hourly_ip')]);
        }

        /*
        |----------------------------------------------------------------------
        | 🔴 کفِ اعتبار روی **مجموعِ مصرف** است، نه فقط سرورِ تازه
        |----------------------------------------------------------------------
        |
        | کارفرما پرسید: «مشتری دو یا سه سرورِ ساعتی بگیرد، محاسبه چطور است؟
        | ما وسط ضرر نکنیم.» و سؤال درست بود.
        |
        | گیتِ قبلی فقط `hourlyStartMinIrt()`ِ **همین** پلن را می‌سنجید. یعنی
        | مشتری با یک شارژِ بزرگ می‌توانست چند سرور بگیرد که هرکدام جداگانه
        | «۲۴ ساعت اعتبار» داشتند ولی **با هم** خیلی زودتر تمام می‌شدند:
        |
        |     ۳ سرورِ ۱۰٬۰۰۰ تومانی، اعتبار ۲۴۰٬۰۰۰
        |     تکی  : ۲۴ ساعت ✓ (هر سه از گیت رد می‌شوند)
        |     باهم : ۳۰٬۰۰۰ در ساعت ⇒ ۸ ساعت
        |
        | و بعد از تمام‌شدنِ اعتبار، `SUSPEND_GRACE_HOURS` (۲۴ ساعت) روی
        | **هر سه** می‌دود: یعنی ۷۲ ساعتِ زیرساخت که پولش را ما می‌دهیم.
        | مترِ ساعتی هرگز اعتبار را منفی نمی‌کند، پس آن ساعت‌ها هیچ‌وقت وصول
        | نمی‌شوند — خالص ضرر.
        |
        | ⚠️ نرخِ سرورهای موجود از `services.hourly_rate_irt` می‌آید، همان
        | ستونی که خودِ متر هر ساعت از رویش کسر می‌کند. پرس‌وجوی موازی یعنی
        | روزی دو تعریف از «مصرفِ ساعتی» داشته باشیم.
        */
        $existingBurn = (int) Service::query()
            ->where('customer_id', $customer->id)
            ->where('billing_mode', 'hourly')
            ->whereNotIn('status', Service::DEAD_STATUSES)
            ->sum('hourly_rate_irt');

        $minStart = $offer->hourlyStartMinIrt()
            + $existingBurn * CloudPlan::HOURLY_START_MIN_HOURS;

        $balance = $customer->creditBalance('IRT');

        if ($balance < $minStart) {
            return back()->withInput()->withErrors(['billing_mode' => __('ui.cvb_e_hourly_credit', [
                'hours' => fa_num(CloudPlan::HOURLY_START_MIN_HOURS),
                'min'   => cloud_price($minStart),
                'bal'   => cloud_price($balance),
            ])]);
        }

        $onCreditOut = in_array($data['on_credit_out'] ?? 'suspend', ['suspend', 'convert', 'terminate'], true)
            ? (string) ($data['on_credit_out'] ?? 'suspend') : 'suspend';

        // قیمتِ ماهانه را به‌عنوان مرجعِ «تبدیل به ماهانه» ذخیره می‌کنیم
        $monthly = self::priceForCycle($offer, 'monthly');

        $service = DB::transaction(function () use ($customer, $offer, $data, $sshKey, $label, $description, $hourly, $hourlyEur, $monthly, $onCreditOut, $balance) {
            $service = Service::create([
                'customer_id'      => $customer->id,
                'name'             => mb_substr(__('ui.svc_name_vps_hourly', ['label' => $label]), 0, 150),
                'description'      => $description,
                'currency_code'    => 'IRT',
                'price'            => $monthly,        // مرجعِ تبدیل به ماهانه
                'tax_percent'      => 0,
                'cycle'            => 'monthly',
                'billing_mode'     => 'hourly',
                'hourly_rate_irt'  => $hourly,
                'hourly_rate_eur'  => $hourlyEur,
                'on_credit_out'    => $onCreditOut,
                'last_metered_at'  => now(),           // ساعتِ اول همین حالا پرداخت شد
                // پرداخت‌شده از اعتبار → مستقیم به صفِ تحویل (مثلِ سرویسِ پرداخت‌شده)
                'status'           => 'awaiting_provision',
                'provision_status' => 'pending',
                'activated_at'     => now(),
                'cloud_plan_id'    => $offer->id,
                'cloud_image_key'  => (string) $data['image'],
                'cloud_ssh_key_id' => $sshKey?->id,
                'cloud_addons'     => [],
                'plan'             => (string) $offer->public_name,
            ]);

            /*
            | کسرِ ساعتِ اول از کیفِ پول (پرداختِ لحظهٔ خرید).
            |
            | 🔴 **کلیدِ منبع باید `Service` باشد، نه `Customer`.** مسیرِ لغوِ
            | سفارشِ تحویل‌نشده (`Account\ServiceController::cancel()`) مبلغِ
            | بازگشتی را با
            | `CreditEntry::where('source_type', Service::class)->where('source_id', $id)`
            | جمع می‌زند؛ تا مرداد ۱۴۰۵ همین ردیف با کلیدِ `Customer` نوشته
            | می‌شد، پس **تنها چیزی بود که مشتری روی سرورِ هرگز-تحویل‌نشده
            | پس نمی‌گرفت**. سرویس عمداً اول ساخته می‌شود تا شناسه‌اش موجود باشد؛
            | هر دو در یک تراکنش‌اند، پس نیمه‌کاره نمی‌مانَد.
            */
            CreditEntry::create([
                'customer_id'   => $customer->id,
                'currency_code' => 'IRT',
                'amount'        => -$hourly,
                'balance_after' => $balance - $hourly,
                'reason'        => 'cloud_hourly',
                'source_type'   => Service::class,
                'source_id'     => $service->id,
                'note'          => 'ساعتِ اولِ سرورِ ساعتی — '.$offer->public_name,
            ]);

            return $service;
        });

        try {
            ActivityLog::forService($service, 'purchase',
                __('ui.act_hourly_order', [
                    'label' => $label,
                    'loc'   => $locText,
                    'rate'  => invoice_money($hourly),
                ]), 'customer', $request);
        } catch (\Throwable) {
        }

        $ho = (array) session('order_handoff', []);
        Funnel::log('order_placed', ['sku' => 'cloud-'.$offer->id, 'cycle' => 'hourly', 'sid' => $ho['sid'] ?? '', 'ref' => $ho['ref'] ?? '', 'handoff_cycle' => $ho['cycle'] ?? '', 'line' => 'cloud']);

        try {
            app(AdminNotifier::class)->event('سفارشِ سرورِ مجازیِ ساعتی (پرداخت‌شده از اعتبار)', [
                'مشتری' => $customer->displayName().' ('.$customer->code.')',
                'پلن'   => (string) $offer->public_name,
                'مکان'  => $locText,
                'نرخ'   => fa_num(number_format($hourly)).' تومان/ساعت',
            ], null, '⏱️', [[
                ['text' => '👤 پروفایلِ مشتری', 'data' => \App\Services\Bale\Admin\AdminBaleRouter::CB_PREFIX.'c:'.$customer->id],
            ]]);
        } catch (\Throwable) {
        }

        return redirect(lroute('account.services'))->with('ok', __('ui.cvb_ok_hourly'));
    }

    /**
     * نامِ سرور: حروف/رقم/خط تیره. اگر کاربر چیزی نداد، خودکار.
     *
     * نامِ میزبان باید با حرف شروع شود، پس هر نامی که با رقم/خط تیره شروع شود
     * پیشوندِ `vps-` می‌گیرد. (نامِ واقعیِ سرور نزدِ زیرساخت `sn-svc-{id}` است و
     * قطعی می‌مانَد — بنیانِ idempotency تحویل — پس این نام برچسبِ خودِ ماست.)
     */
    public static function serverLabel(?string $raw): string
    {
        $s = strtolower(trim((string) $raw));
        $s = (string) preg_replace('/[^a-z0-9-]+/', '-', $s);
        $s = trim((string) preg_replace('/-{2,}/', '-', $s), '-');
        $s = trim(substr($s, 0, self::LABEL_MAX), '-');

        if ($s === '') {
            return 'vps-'.strtolower(Str::random(6));
        }

        if (preg_match('/^[a-z]/', $s) !== 1) {
            $s = trim(substr('vps-'.$s, 0, self::LABEL_MAX), '-');
        }

        return $s;
    }

    /**
     * پیش‌فاکتورِ اولین دوره.
     *
     * سرورِ مجازی هزینهٔ راه‌اندازی ندارد (ساختش خودکار است)، پس یک سطر بس است.
     * الگو دقیقاً همان `StoreController::issueOrderInvoice` است تا صفحهٔ فاکتور،
     * پرینت A4، پرداخت و `PaymentService` هیچ حالتِ تازه‌ای نبینند.
     */
    private function issueOrderInvoice(Service $service, CloudPlan $offer, string $label): Invoice
    {
        $unitPrice = (int) $service->price;
        $tax = (int) round($unitPrice * $service->tax_percent / 100);

        $invoice = Invoice::create([
            'customer_id' => $service->customer_id,
            'service_id' => $service->id,
            'kind' => 'service',
            'currency_code' => $service->currency_code,
            'subtotal' => $unitPrice,
            'tax' => $tax,
            'total' => $unitPrice + $tax,
            'paid' => 0,
            'status' => 'unpaid',
            'issued_at' => now(),
            'note' => __('ui.svc_name_vps', ['label' => $offer->public_name]),
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'title' => __('ui.svc_name_vps', ['label' => $offer->public_name]).' ('.$service->cycleLabel().')',
            'description' => $label,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice,
            'tax_rate_bp' => (int) $service->tax_percent * 100,
            'tax_amount' => $tax,
        ]);

        return $invoice;
    }
}
