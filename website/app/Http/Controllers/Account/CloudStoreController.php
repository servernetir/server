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
                return back()->withInput()->withErrors(['ssh_key_id' => 'کلیدِ انتخابی پیدا نشد.']);
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
        $base = mb_substr(trim((string) ($data['ssh_key_name'] ?? '')) ?: 'کلیدِ من', 0, 55);
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
        $codes = CloudPlan::query()->sellable()
            ->distinct()->orderBy('location_code')->pluck('location_code')
            ->filter()->unique()->values();

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
        $groups = $this->locationGroups();

        // مکانِ انتخابی از query می‌آید (لینکِ ساده، بی‌جاوااسکریپت هم کار کند)
        $allCodes = [];
        foreach ($groups as $g) {
            foreach ($g['locations'] as $l) {
                $allCodes[] = (string) $l->code;
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
        $code = in_array($wanted, $allCodes, true) ? $wanted : ($allCodes[0] ?? null);

        $location = null;
        foreach ($groups as $g) {
            foreach ($g['locations'] as $l) {
                if ((string) $l->code === $code) {
                    $location = $l;
                }
            }
        }

        /** @var Collection<string, CloudPlan> $offers */
        $offers = $code ? CloudPlan::offers($code) : collect();

        // ── ایمیج‌ها: فهرستِ نمایشیِ یکسان‌شده + نقشهٔ «کدام پلن کدام‌ها را دارد»
        $osCatalog = CloudImage::catalog('os');
        $appCatalog = CloudImage::catalog('app');

        $rowsByKey = CloudImage::query()->usable()->get()->groupBy('key');

        $providersBySlug = CloudPlan::query()->sellable()
            ->whereIn('slug', $offers->keys()->all())
            ->get(['slug', 'provider'])
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->pluck('provider')->unique()->values()->all());

        $imageMap = [];     // slug => ['os' => [key,…], 'app' => [key,…]]
        $priceMap = [];     // slug => cycle => ['cycle','per','first','save']
        $planCards = [];    // دادهٔ نمایشیِ امن (بی‌هیچ ستونِ زیرساخت)

        $cycles = self::cycles();
        $taxPct = self::taxPercent();

        foreach ($offers as $slug => $offer) {
            $slugProviders = (array) ($providersBySlug[$slug] ?? []);

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
                'vcpu' => (int) $offer->vcpu,
                'ram' => $offer->ramLabel(),
                'disk' => $offer->diskLabel(),
                'traffic' => $offer->trafficLabel(),
                'cpu' => $offer->cpuKindLabel(),
            ];
        }

        // پلنِ پیش‌انتخاب‌شده (لینکِ «خرید» صفحات عمومی: ?location=…&plan=…)
        $selectedSlug = $request->query('plan', '');
        $selectedSlug = is_string($selectedSlug) ? $selectedSlug : '';

        if (! isset($imageMap[$selectedSlug])) {
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
            'location' => $location,
            'locCode' => $code,
            'planCards' => $planCards,
            'selectedSlug' => $selectedSlug,
            'osCatalog' => $osCatalog,
            'appCatalog' => $appCatalog,
            'imageMap' => $imageMap,
            'priceMap' => $priceMap,
            'cycles' => $cycles,
            'cycleLabels' => collect($cycles)->mapWithKeys(fn ($c) => [$c => Service::labelFor($c)])->all(),
            'defCycle' => self::defaultCycle(),
            'taxPct' => $taxPct,
            'autoLabel' => self::serverLabel(null),

            // ── فروشِ ساعتی ──
            // نرخِ ساعتیِ هر پلن + حداقلِ اعتبارِ شروع (۲۴ ساعت) و موجودیِ فعلیِ
            // مشتری، تا صفحه بتواند پیش از ثبتِ سفارش بگوید اعتبار کافی است یا نه.
            'hourlyMap' => collect($planCards)->mapWithKeys(function ($p) {
                $plan = CloudPlan::bestForSlug((string) $p['slug']);

                return [(string) $p['slug'] => [
                    'rate' => $plan?->hourlyIrt() ?? 0,
                    'min'  => $plan?->hourlyStartMinIrt() ?? 0,
                ]];
            })->all(),
            'creditIrt' => (int) (Auth::guard('customer')->user()?->creditBalance('IRT') ?? 0),

            // ── افزودنی‌ها ──
            // `addonOk` می‌گوید آیا **این مکان** اصلاً IP اضافه دارد؛ اگر نه،
            // کارتش نمایش داده نمی‌شود. نشان‌دادنِ گزینه‌ای که سرِ ثبتِ سفارش رد
            // می‌شود، بدترین نوعِ رابطِ کاربری است.
            'addonOk' => $selectedSlug !== null
                && app(CloudAddons::class)->bestPlanFor(
                    $selectedSlug, ['extra_ipv4' => 1], app(\App\Services\Cloud\CloudManager::class)
                ) !== null,
            'extraIpPrice' => app(CloudAddons::class)->extraIpMonthlyToman(),
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
                'درخواست‌های زیاد. '.fa_num(RateLimiter::availableIn($key)).' ثانیه دیگر تلاش کنید.'
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
            'location' => 'مکانِ سرور', 'plan' => 'پلن',
            'image' => 'سیستم‌عامل', 'cycle' => 'دورهٔ پرداخت',
            'label' => 'نامِ سرور', 'extra_ipv4' => 'تعدادِ IP اضافه',
        ]);

        // ── مکان باید همین حالا موجودی داشته باشد ──
        $codes = [];
        foreach ($this->locationGroups() as $g) {
            foreach ($g['locations'] as $l) {
                $codes[] = (string) $l->code;
            }
        }

        if (! in_array($data['location'], $codes, true)) {
            return back()->withInput()->withErrors(['location' => 'این مکان در دسترس نیست؛ مکانِ دیگری را انتخاب کنید.']);
        }

        // ── پلن باید در همین مکان قابلِ فروش باشد ──
        // `offers()` خودش sellable و ارزان‌ترین را می‌دهد؛ پس پلنِ غیرفعال،
        // ناموجود، قیمت‌صفر یا مربوط به مکانِ دیگر این‌جا پیدا نمی‌شود.
        $offer = CloudPlan::offers($data['location'])->get($data['plan']);

        if ($offer === null) {
            return back()->withInput()->withErrors(['plan' => 'این پلن در این مکان در دسترس نیست؛ پلنِ دیگری را انتخاب کنید.']);
        }

        // ── ایمیج باید روی همین پلن قابلِ تحویل باشد ──
        // ورودیِ دلخواهِ کاربر هرگز مستقیم به API نمی‌رود؛ فقط کلیدی که خودمان
        // ساخته‌ایم و دستِ‌کم یک زیرساختِ فروشندهٔ همین اسلاگ داردش.
        $allowed = array_merge(
            self::imageKeysFor($offer, 'os'),
            self::imageKeysFor($offer, 'app'),
        );

        if (! in_array($data['image'], $allowed, true)) {
            return back()->withInput()->withErrors(['image' => 'این سیستم‌عامل برای پلنِ انتخابی در دسترس نیست.']);
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
                'extra_ipv4' => 'برای این پلن و مکان، IP اضافه در دسترس نیست. می‌توانید بی‌IP اضافه سفارش دهید.',
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
            return back()->withInput()->withErrors(['plan' => 'قیمتِ این پلن در دسترس نیست؛ لطفاً بعداً تلاش کنید.']);
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
                'name' => mb_substr('سرور مجازی '.$label, 0, 150),
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
            ->with('ok', 'سفارشِ سرور ثبت شد. با پرداختِ پیش‌فاکتور، سرور خودکار ساخته و تحویل می‌شود.');
    }

    /**
     * سفارشِ **ساعتی** — پیش‌پرداخت از کیفِ پول، بی‌فاکتور.
     *
     * قاعده (تأییدِ کارفرما): حداقلِ ۲۴ ساعت اعتبار برای شروع، ولی **بدونِ
     * حداقلِ مصرف** — ساعتِ اول همین‌جا کسر می‌شود؛ بقیه را کرونِ `cloud:meter`
     * هر ساعت کم می‌کند و مشتری هر وقت خواست لغو می‌کند (اعتبارِ مانده می‌مانَد).
     */
    private function orderHourly(Request $request, Customer $customer, CloudPlan $offer, array $data, array $addons, $sshKey, string $label, string $locText, string $description): RedirectResponse
    {
        $hourly = $offer->hourlyIrt();
        $hourlyEur = $offer->hourlyEurCents();

        if ($hourly <= 0) {
            return back()->withInput()->withErrors(['plan' => 'قیمتِ ساعتیِ این پلن در دسترس نیست؛ ماهانه سفارش دهید.']);
        }

        // افزودنیِ پولی روی ساعتی فعلاً پشتیبانی نمی‌شود (قیمتش ماهانه است)
        if (! app(CloudAddons::class)->isEmpty($addons)) {
            return back()->withInput()->withErrors(['extra_ipv4' => 'IP اضافه روی سرورِ ساعتی فعلاً در دسترس نیست؛ بی‌IP اضافه یا ماهانه سفارش دهید.']);
        }

        $minStart = $hourly * 24;
        $balance = $customer->creditBalance('IRT');

        if ($balance < $minStart) {
            return back()->withInput()->withErrors(['billing_mode' =>
                'برای شروعِ سرورِ ساعتی حداقل اعتبارِ ۲۴ ساعت لازم است ('.fa_num(number_format($minStart)).' تومان). '
                .'اعتبارِ فعلیِ شما '.fa_num(number_format($balance)).' تومان است — لطفاً کیفِ پول را شارژ کنید.']);
        }

        $onCreditOut = in_array($data['on_credit_out'] ?? 'suspend', ['suspend', 'convert', 'terminate'], true)
            ? (string) ($data['on_credit_out'] ?? 'suspend') : 'suspend';

        // قیمتِ ماهانه را به‌عنوان مرجعِ «تبدیل به ماهانه» ذخیره می‌کنیم
        $monthly = self::priceForCycle($offer, 'monthly');

        $service = DB::transaction(function () use ($customer, $offer, $data, $sshKey, $label, $description, $hourly, $hourlyEur, $monthly, $onCreditOut, $balance) {
            // کسرِ ساعتِ اول از کیفِ پول (پرداختِ لحظهٔ خرید)
            CreditEntry::create([
                'customer_id'   => $customer->id,
                'currency_code' => 'IRT',
                'amount'        => -$hourly,
                'balance_after' => $balance - $hourly,
                'reason'        => 'cloud_hourly',
                'source_type'   => Customer::class,
                'source_id'     => $customer->id,
                'note'          => 'ساعتِ اولِ سرورِ ساعتی — '.$offer->public_name,
            ]);

            return Service::create([
                'customer_id'      => $customer->id,
                'name'             => mb_substr('سرور مجازی '.$label.' (ساعتی)', 0, 150),
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
        });

        try {
            ActivityLog::forService($service, 'purchase',
                'سفارشِ سرورِ ساعتی «'.$label.'» — '.$offer->public_name.' · '.$locText
                .' · '.fa_num(number_format($hourly)).' تومان/ساعت (پرداخت از کیفِ پول)', 'customer', $request);
        } catch (\Throwable) {
        }

        try {
            app(AdminNotifier::class)->event('سفارشِ سرورِ مجازیِ ساعتی (پرداخت‌شده از اعتبار)', [
                'مشتری' => $customer->displayName().' ('.$customer->code.')',
                'پلن'   => (string) $offer->public_name,
                'مکان'  => $locText,
                'نرخ'   => fa_num(number_format($hourly)).' تومان/ساعت',
            ], url('/admin/customers/'.$customer->id), '⏱️');
        } catch (\Throwable) {
        }

        return redirect(lroute('account.services'))->with('ok',
            'سرورِ ساعتی سفارش داده شد و در حالِ ساخت است. هر ساعت از کیفِ پولِ شما کم می‌شود؛ '
            .'هر وقت خواستید می‌توانید لغوش کنید و اعتبارِ استفاده‌نشده در کیفتان می‌مانَد.');
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
            'note' => 'سرور مجازی '.$offer->public_name,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'title' => 'سرور مجازی '.$offer->public_name.' ('.$service->cycleLabel().')',
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
