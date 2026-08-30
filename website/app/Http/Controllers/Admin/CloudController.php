<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * پنلِ مدیریتِ زیرساختِ ابری — آزمونِ اتصال، همگام‌سازی و نمایِ کاتالوگ.
 *
 * ⚠️ این تنها جایی در کلِ برنامه است که نامِ ارائه‌دهنده به چشمِ انسان می‌رسد، و
 * فقط برای **مدیر**. هر ویویی که مشتری می‌بیند باید از `CloudPlan::offers()`
 * بخواند که ستونِ `provider` را پنهان می‌کند.
 */
class CloudController extends Controller
{
    public function __construct(private CloudManager $manager) {}

    /** سقفِ ردیف‌های جدول. شمارشِ واقعی جدا نشان داده می‌شود تا بریدگی پنهان نمانَد. */
    private const ROW_LIMIT = 400;

    /**
     * سقفِ ردیف در یک اقدامِ گروهی.
     *
     * ⚠️ عمداً بی‌صدا نمی‌بُرد — بیش از این عدد **رد** می‌شود. بریدنِ خاموشِ
     * فهرست یعنی مدیر ۷۷۰ ردیف انتخاب می‌کند، پیامِ «انجام شد» می‌گیرد و
     * نمی‌فهمد ۳۷۰تایش دست‌نخورده مانده.
     */
    private const BULK_MAX = 1000;

    /**
     * فیلترها و مرتب‌سازیِ مجاز.
     *
     * ⚠️ **فهرستِ سفید** و نه ورودیِ آزاد: نامِ ستون از query می‌آید و اگر
     * مستقیم به `orderBy` برود، هر رشته‌ای قابلِ تزریق است. این‌جا فقط همین
     * کلیدها پذیرفته می‌شوند.
     */
    private const SORTS = [
        'price'    => ['price_irt', 'asc'],
        'price_d'  => ['price_irt', 'desc'],
        'cost'     => ['cost_eur_cents', 'asc'],
        'cost_d'   => ['cost_eur_cents', 'desc'],
        'cpu'      => ['vcpu', 'asc'],
        'cpu_d'    => ['vcpu', 'desc'],
        'ram'      => ['ram_mb', 'asc'],
        'ram_d'    => ['ram_mb', 'desc'],
        'name'     => ['public_name', 'asc'],
    ];

    public function index(Request $request): View
    {
        $ready = Schema::hasTable('cloud_plans');

        $providers = [];
        foreach ($this->manager->all() as $slug => $driver) {
            $providers[$slug] = [
                'configured' => $driver->isConfigured(),
                'plans'      => $ready ? CloudPlan::where('provider', $slug)->where('is_active', true)->count() : 0,
                'caps'       => $driver->capabilities(),
            ];
        }

        // ── فیلترها ──
        // این جدول با ۱۱۴ پلن غیرقابلِ استفاده شده بود. فیلتر روی **ردیف‌های
        // خام** است نه عرضه‌ها، چون مدیر باید ردیفِ هر زیرساخت را جدا ببیند و
        // بتواند خاموشش کند — چیزی که در نمای «عرضه» پنهان می‌شود.
        $f = [
            'provider' => (string) $request->query('provider', ''),
            'country'  => strtoupper((string) $request->query('country', '')),
            'cpu'      => (string) $request->query('cpu', ''),
            'state'    => (string) $request->query('state', ''),
            'q'        => trim((string) $request->query('q', '')),
            'sort'     => (string) $request->query('sort', 'price'),
        ];

        $rows = collect();
        $matched = 0;

        if ($ready) {
            [$col, $dir] = self::SORTS[$f['sort']] ?? self::SORTS['price'];

            $q = CloudPlan::query()
                ->when($f['provider'] !== '', fn ($q) => $q->where('provider', $f['provider']))
                ->when($f['cpu'] !== '', fn ($q) => $q->where('cpu_kind', $f['cpu']))
                ->when($f['q'] !== '', fn ($q) => $q->where(function ($qq) use ($f) {
                    $qq->where('public_name', 'like', '%'.$f['q'].'%')
                        ->orWhere('slug', 'like', '%'.$f['q'].'%');
                }))
                ->when($f['country'] !== '', fn ($q) => $q->whereIn(
                    'location_code',
                    CloudLocation::where('country', $f['country'])->pluck('code')
                ))
                // 🔴 «در حالِ فروش» یعنی واقعاً **قابلِ فروش**، نه فقط
                // `admin_disabled = false`. قبلاً همین بود و نتیجه‌اش این که
                // پلنِ ناموجود، بی‌قیمت، غیرفعال یا متعلق به زیرساختِ خاموش هم
                // «در حالِ فروش» شمرده می‌شد — یعنی فیلتری که جواب می‌داد ولی
                // جوابش با آنچه مشتری می‌بیند نمی‌خواند.
                ->when($f['state'] === 'on', fn ($q) => $q->sellable())
                ->when($f['state'] === 'off', fn ($q) => $q->where('admin_disabled', true))
                ->when($f['state'] === 'oos', fn ($q) => $q->where('in_stock', false))
                ->when($f['state'] === 'noprice', fn ($q) => $q->where('price_irt', 0))
                ->when($f['state'] === 'inactive', fn ($q) => $q->where('is_active', false))
                // «کدام‌ها را قرنطینهٔ خودکار بست؟» — تنها فیلتری که ردیف‌های
                // قابلِ بازکردنِ گروهی را جدا می‌کند. بی‌این، مدیر باید ۲۲۱
                // ردیفِ قرنطینه را از میانِ «بسته‌شده‌ها» با چشم پیدا کند.
                ->when($f['state'] === 'quarantined', fn ($q) => $q
                    ->where('admin_disabled', true)
                    ->where('admin_note', 'like', CloudProvisioner::QUARANTINE_PREFIX.'%'))
                // «چرا نمی‌فروشد؟» — پرسشِ روزمرهٔ همین صفحه. هر ردیفی که
                // `sellable` نیست، با هر علتی.
                ->when($f['state'] === 'unsellable', fn ($q) => $q->where(function ($qq) {
                    $qq->where('is_active', false)
                        ->orWhere('in_stock', false)
                        ->orWhere('price_irt', '<=', 0)
                        ->orWhere('admin_disabled', true)
                        ->orWhereIn('provider', CloudPlan::disabledProviders() ?: ['__none__']);
                }));

            // شمارشِ واقعی پیش از سقف — وگرنه مدیر ۴۰۰ ردیف می‌دید و نمی‌فهمید
            // بقیه هم هستند؛ «همه را دیدم» بدترین برداشتِ ممکن از یک فهرستِ
            // بریده است.
            $matched = (clone $q)->count();
            // `with('location')` فقط برای پرهیز از ۴۰۰ پرس‌وجوی تک‌ردیفی است؛
            // خودِ ویو در هر ردیف `location?->label()` می‌خواهد.
            $rows = $q->with('location')->orderBy($col, $dir)->limit(self::ROW_LIMIT)->get();
        }

        // عرضه‌های عمومی: همان چیزی که مشتری می‌بیند — برای اینکه مدیر بتواند
        // چشمی بررسی کند که سفیدبرچسبی درست کار می‌کند و پلنِ تکراری نیست.
        $offers = $ready ? CloudPlan::offers() : collect();

        return view('admin.cloud', [
            'notReady'  => ! $ready,
            'providers' => $providers,
            // «کدام زیرساخت کجاست» — سؤالِ روزمرهٔ مدیر، حالا در خودِ صفحه
            'byProvider' => $ready ? $this->manager->locationsByProvider() : [],
            // پلن‌هایی که بهایشان گران شده و سرویسِ فعال رویشان است
            'risen' => $ready ? CloudPlan::query()
                ->whereNotNull('previous_cost_eur_cents')
                ->whereColumn('cost_eur_cents', '>', 'previous_cost_eur_cents')
                ->orderByDesc('cost_changed_at')
                ->limit(20)->get() : collect(),
            // کشورهایی که می‌فروشیم ولی صفحهٔ بازاریابی ندارند (شکافِ سئویی)
            'noPage' => $ready ? \App\Services\Cloud\CloudCountry::withoutMarketingPage() : [],
            'offers'    => $offers,
            'locations' => $ready
                ? CloudLocation::orderBy('country')->orderBy('city')->get()
                : collect(),
            'planCount' => $ready ? CloudPlan::where('is_active', true)->count() : 0,
            'rows'      => $rows,
            // ⚠️ نشانه‌های فیلترِ سمتِ مرورگر **در سرور** ساخته می‌شوند.
            // فیلترِ بی‌درنگ نباید معنیِ «در حالِ فروش» را دوباره در جاوااسکریپت
            // پیاده کند: آن‌وقت دو تعریف می‌شود و روزی که `scopeSellable` عوض
            // شود، جدولِ مدیر و فروشگاه دو حرفِ متفاوت می‌زنند.
            'rowMeta'   => $this->rowMeta($rows),
            'matched'   => $matched,
            'rowLimit'  => self::ROW_LIMIT,
            'f'         => $f,
            'sorts'     => array_keys(self::SORTS),
            'countries' => $ready
                ? CloudLocation::whereIn('code', CloudPlan::distinct()->pluck('location_code'))
                    ->get()->groupBy('country')->map->first()->sortBy('country')
                : collect(),
            'offProviders' => CloudPlan::disabledProviders(),
        ]);
    }

    /**
     * دادهٔ فیلترِ سمتِ مرورگر برای هر ردیف — همان معنیِ فیلترهای سرور.
     *
     * ═══ چرا این‌جا و نه در جاوااسکریپت ═══
     *
     * فیلترِ بی‌درنگ باید **دقیقاً** همان چیزی را بگوید که فیلترِ سرور می‌گوید.
     * اگر «در حالِ فروش» را در JS دوباره پیاده می‌کردیم، دو تعریف می‌شد: روزی
     * که شرطِ پنجمی به `CloudPlan::scopeSellable` اضافه شود، جدولِ مدیر و
     * فروشگاه دو حرفِ متفاوت می‌زدند و هیچ خطایی هم در کار نبود. پس نشانه‌ها
     * (`on` / `off` / `oos` / …) این‌جا از همان ستون‌ها ساخته می‌شوند و
     * جاوااسکریپت فقط رشته‌ها را مقایسه می‌کند.
     *
     * @param  \Illuminate\Support\Collection<int, CloudPlan>|EloquentCollection<int, CloudPlan>  $rows
     * @return array<int, array{state:string, country:string, q:string}>
     */
    private function rowMeta($rows): array
    {
        $off = CloudPlan::disabledProviders();
        $meta = [];

        foreach ($rows as $r) {
            $providerOff = in_array($r->provider, $off, true);

            $sellable = (bool) $r->is_active && (bool) $r->in_stock
                && (int) $r->price_irt > 0 && ! (bool) $r->admin_disabled && ! $providerOff;

            $tokens = [];

            if ($sellable) {
                $tokens[] = 'on';
            } else {
                // «فروخته نمی‌شود — به هر علتی» همان مکملِ بالاست، پس دو نشانه
                // هرگز نمی‌توانند با هم درست باشند و فیلتر تناقض نمی‌گیرد.
                $tokens[] = 'unsellable';
            }

            if ((bool) $r->admin_disabled) {
                $tokens[] = 'off';

                if (str_starts_with((string) $r->admin_note, CloudProvisioner::QUARANTINE_PREFIX)) {
                    $tokens[] = 'quarantined';
                }
            }

            if (! (bool) $r->in_stock) {
                $tokens[] = 'oos';
            }

            // ⚠️ سرور برای «بی‌قیمت» دقیقاً `price_irt = 0` می‌گیرد (نه `<= 0`)؛
            // همان را تکرار می‌کنیم وگرنه فیلترِ بی‌درنگ با فیلترِ سرور
            // یک‌قدم فرق می‌کند و کسی نمی‌فهمد کدام درست است.
            if ((int) $r->price_irt === 0) {
                $tokens[] = 'noprice';
            }

            if (! (bool) $r->is_active) {
                $tokens[] = 'inactive';
            }

            $meta[$r->id] = [
                'state'   => implode(' ', $tokens),
                'country' => strtoupper((string) ($r->location?->country ?? '')),
                'q'       => mb_strtolower($r->public_name.' '.$r->slug),
            ];
        }

        return $meta;
    }

    // ═══════════════════════ اقدامِ گروهی ═══════════════════════

    /*
    | 🔴 چرا این دو روت وجود دارند
    |
    | قرنطینهٔ خودکار یک‌بار **۲۲۱ پلن** را بست و تنها راهِ برگرداندنشان دکمهٔ
    | «باز کن» ردیف‌به‌ردیف بود، روی صفحه‌ای با سقفِ ۴۰۰ ردیف. کارفرما:
    | «دونه دونه مدیریتشون سخته». ولی یک دکمهٔ گروهیِ بی‌محافظ **بدتر** از ۲۲۱
    | بار کلیک است، چون تصمیم‌های انسانی را هم پاک می‌کند.
    */

    /**
     * بازکردنِ گروهی — فقط ردیف‌هایی که **قرنطینهٔ خودکار** بسته بود.
     *
     * همان تفکیکی که `cloud:reopen` دارد:
     *  • یادداشتش با `CloudProvisioner::QUARANTINE_PREFIX` شروع نشود ⇒ **دست نزن**.
     *    پلنی که مدیر آگاهانه بست (یا یادداشتی ندارد و نمی‌دانیم کی بست) تصمیمِ
     *    انسانی است؛ «نمی‌دانم» دلیلِ کافی برای بازکردنِ فروش نیست.
     *  • ردیفی که به علتِ **دیگری** نمی‌فروشد (بی‌قیمت، ناموجود، غیرفعال،
     *    زیرساختِ خاموش) هم باز نمی‌شود: بازکردنش فقط نشانِ «بستهٔ من» را
     *    برمی‌دارد و علتِ واقعیِ نفروختن را پنهان می‌کند.
     *
     * و در هر دو حالت **علت گزارش می‌شود**؛ موفقیتِ ساکت روی ردیفی که دست
     * نخورده، از نبودِ دکمه بدتر است.
     */
    public function bulkOpen(Request $request): RedirectResponse
    {
        [$rows, $unknown, $error] = $this->selection($request);

        if ($error !== null) {
            return back()->with('err', $error);
        }

        $open = [];
        $skipped = [];

        foreach ($rows as $r) {
            $why = $this->whyNotReopenable($r);

            if ($why !== null) {
                $skipped[$why] = ($skipped[$why] ?? 0) + 1;

                continue;
            }

            $open[] = $r->id;
        }

        if ($open !== []) {
            CloudPlan::whereIn('id', $open)
                ->update(['admin_disabled' => false, 'admin_note' => null]);

            $this->afterChange();

            \App\Support\ErrorTracker::note('cloud',
                'بازکردنِ گروهیِ '.count($open).' پلنِ قرنطینه‌شده از پنلِ مدیریت');
        }

        $parts = [];

        if ($open !== []) {
            $parts[] = fa_num(count($open)).' پلن باز شد.';
        }

        if ($skipped !== []) {
            $bits = [];

            foreach ($skipped as $why => $n) {
                $bits[] = $why.' ('.fa_num($n).')';
            }

            $parts[] = 'باز نشد — '.implode(' · ', $bits);
        }

        if (isset($skipped[self::SKIP_MANUAL])) {
            // راهِ خروج را بگو، وگرنه مدیر فکر می‌کند دکمه خراب است
            $parts[] = 'بسته‌های دستی عمداً دست نمی‌خورند؛ ردیف‌به‌ردیف بازشان کنید یا «cloud:reopen --all» را بزنید.';
        }

        if ($unknown > 0) {
            $parts[] = fa_num($unknown).' شناسهٔ ناشناخته نادیده گرفته شد.';
        }

        return back()->with($open === [] ? 'err' : 'ok', implode(' | ', $parts));
    }

    /** بستنِ گروهی — با یک یادداشتِ مشترک */
    public function bulkClose(Request $request): RedirectResponse
    {
        [$rows, $unknown, $error] = $this->selection($request);

        if ($error !== null) {
            return back()->with('err', $error);
        }

        $note = mb_substr(trim((string) $request->input('note', '')), 0, 180);

        /*
        | 🔴 یادداشتِ دستی هرگز نباید با پیشوندِ قرنطینهٔ خودکار شروع شود.
        |
        | اگر بشود، «بازکردنِ گروهی» بعداً همین تصمیمِ انسانی را «قرنطینهٔ
        | خودکار» می‌خواند و بازش می‌کند — یعنی همان محافظی که نوشتیم، با یک
        | متنِ تایپ‌شده دور می‌خورد.
        */
        if ($note !== '' && str_starts_with($note, CloudProvisioner::QUARANTINE_PREFIX)) {
            $note = trim(mb_substr($note, mb_strlen(CloudProvisioner::QUARANTINE_PREFIX)));
        }

        $close = $rows->reject(fn (CloudPlan $r) => (bool) $r->admin_disabled)
            ->pluck('id')->all();

        $already = $rows->count() - count($close);

        if ($close !== []) {
            CloudPlan::whereIn('id', $close)->update([
                'admin_disabled' => true,
                'admin_note'     => $note !== '' ? $note : null,
            ]);

            $this->afterChange();
        }

        $parts = [];

        if ($close !== []) {
            $parts[] = fa_num(count($close)).' پلن بسته شد و در فروشگاه نمایش داده نمی‌شود.';
        }

        if ($already > 0) {
            $parts[] = fa_num($already).' ردیف از قبل بسته بود.';
        }

        if ($unknown > 0) {
            $parts[] = fa_num($unknown).' شناسهٔ ناشناخته نادیده گرفته شد.';
        }

        return back()->with($close === [] ? 'err' : 'ok', implode(' | ', $parts));
    }

    /** علتِ ثابتِ «دستی بسته شده» — چون پیام هم بر اساسش ساخته می‌شود */
    private const SKIP_MANUAL = 'با تصمیمِ دستی بسته شده';

    /**
     * چرا این ردیف را نباید گروهی باز کرد؟ `null` یعنی می‌شود.
     *
     * ⚠️ شرط‌های «قابلِ فروش» این‌جا **صریح** تکرار شده‌اند و نه با
     * `scopeSellable`، چون هر شرط باید یک **علتِ خواندنی** بدهد و اسکوپ فقط
     * بله/خیر می‌دهد. تستِ
     * `test_every_plan_that_bulk_open_reopened_is_actually_sellable` این
     * تکرار را قفل می‌کند: اگر روزی شرطِ پنجمی به اسکوپ اضافه شود و این‌جا
     * جا بیفتد، آن تست می‌شکند.
     */
    private function whyNotReopenable(CloudPlan $r): ?string
    {
        if (! (bool) $r->admin_disabled) {
            return 'از قبل باز بود';
        }

        if (! str_starts_with((string) $r->admin_note, CloudProvisioner::QUARANTINE_PREFIX)) {
            return self::SKIP_MANUAL;
        }

        if (! (bool) $r->is_active) {
            return 'در آخرین همگام‌سازی غیرفعال بود';
        }

        if (! (bool) $r->in_stock) {
            return 'نزدِ زیرساخت ناموجود است';
        }

        if ((int) $r->price_irt <= 0) {
            return 'قیمتِ تومانی ندارد';
        }

        if (CloudPlan::providerIsDisabled($r->provider)) {
            return 'زیرساختش خاموش است';
        }

        return null;
    }

    /**
     * ردیف‌های واقعیِ انتخاب‌شده از فهرستِ ارسالی.
     *
     * ⚠️ **به فهرستِ POST اعتماد نمی‌شود.** ورودی یک رشتهٔ کاماجداست که از DOM
     * می‌آید؛ هر شناسه‌ای که پلنِ واقعی نباشد کنار می‌رود و **شمرده** می‌شود تا
     * در پیام بیاید. بی‌آن شمارش، یک اختلافِ ۵۰ ردیفی بین «انتخاب کردم» و
     * «انجام شد» بی‌صدا می‌مانْد.
     *
     * @return array{0: EloquentCollection<int, CloudPlan>, 1: int, 2: string|null}
     */
    private function selection(Request $request): array
    {
        $empty = new EloquentCollection;

        if (! Schema::hasTable('cloud_plans')) {
            return [$empty, 0, 'جدول‌های ابری ساخته نشده‌اند — اول مهاجرت را اجرا کنید.'];
        }

        $ids = collect(explode(',', (string) $request->input('ids', '')))
            ->map(fn ($x) => (int) trim($x))
            ->filter(fn (int $x) => $x > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [$empty, 0, 'هیچ ردیفی انتخاب نشده بود — چیزی عوض نشد.'];
        }

        if ($ids->count() > self::BULK_MAX) {
            return [$empty, 0, 'بیش از '.fa_num(self::BULK_MAX)
                .' ردیف در یک اقدام پذیرفته نمی‌شود — فیلتر را باریک‌تر کنید.'];
        }

        $rows = CloudPlan::whereIn('id', $ids->all())->get();

        if ($rows->isEmpty()) {
            return [$empty, $ids->count(), 'هیچ‌کدام از شناسه‌های ارسالی پلنِ واقعی نبود — چیزی عوض نشد.'];
        }

        return [$rows, $ids->count() - $rows->count(), null];
    }

    /** آزمونِ اتصال به همهٔ ارائه‌دهندگانِ تنظیم‌شده */
    public function test(): RedirectResponse
    {
        $configured = $this->manager->configured();

        if ($configured === []) {
            return back()->with('err', 'هیچ توکنی ذخیره نشده — اول توکن را وارد و ذخیره کنید.');
        }

        $lines = [];
        $i = 0;

        foreach ($configured as $driver) {
            $i++;
            $r = $driver->testConnection();
            // در پیامِ مدیر هم نامِ ارائه‌دهنده را نمی‌نویسیم؛ «زیرساختِ ۱/۲»
            // همان ترتیبی است که در فرمِ تنظیمات دیده.
            $lines[] = ($r['ok'] ? '✅' : '❌')." زیرساختِ {$i}: ".$r['message'];
        }

        return back()->with($lines === [] ? 'err' : 'ok', implode(' | ', $lines));
    }

    /** همگام‌سازیِ کاتالوگ (یا فقط بازمحاسبهٔ قیمت) */
    public function sync(Request $request, CloudCatalogSync $sync): RedirectResponse
    {
        if (! Schema::hasTable('cloud_plans')) {
            return back()->with('err', 'جدول‌های ابری ساخته نشده‌اند — اول مهاجرت را اجرا کنید.');
        }

        if ($request->boolean('prices_only')) {
            $n = $sync->reprice();

            return back()->with('ok', "قیمتِ {$n} پلن با نرخِ روزِ یورو بازمحاسبه شد.");
        }

        $report = $sync->sync();

        if (isset($report['message'])) {
            return back()->with('err', $report['message']);
        }

        if ($report['providers'] === []) {
            return back()->with('err', 'هیچ زیرساختی تنظیم نشده است.');
        }

        $lines = [];
        $i = 0;

        $sanity = $report['providers']['__sanity'] ?? null;
        $costAlert = $report['providers']['__cost'] ?? null;
        unset($report['providers']['__sanity'], $report['providers']['__cost']);

        foreach ($report['providers'] as $r) {
            $i++;
            if (! $r['ok']) {
                $lines[] = "زیرساختِ {$i}: خطا — {$r['message']}";

                continue;
            }

            $line = "زیرساختِ {$i}: {$r['plans']} پلن، {$r['locations']} مکان، {$r['images']} سیستم‌عامل";

            // توضیحِ «چرا صفر» — اگر درایور دلیلی داشت، همان‌جا نشان بده
            if (filled($r['message'] ?? null)) {
                $line .= ' ⚠️ '.$r['message'];
            }

            $lines[] = $line;
        }

        // گران‌شدنِ بها اولِ همه — چون ضررِ در جریان است، نه یک تذکر
        if (is_string($costAlert) && $costAlert !== '') {
            array_unshift($lines, $costAlert);
        }

        // دامِ «واحدِ قیمت اشتباه»
        if (is_string($sanity) && $sanity !== '') {
            $lines[] = $sanity;
        }

        if (($report['rate'] ?? 0) <= 0) {
            $lines[] = '⚠️ نرخِ یورو در دسترس نبود، پس قیمتِ تومانی ساخته نشد و پلن‌ها در فروشگاه نمی‌آیند.';
        }

        return back()->with('ok', implode(' | ', $lines));
    }

    /**
     * خاموش/روشنِ یک پلن.
     *
     * روی `admin_disabled` می‌نویسد و **نه** `is_active` — وگرنه اجرای بعدیِ
     * کرون تصمیم را بی‌صدا برمی‌گرداند.
     */
    public function togglePlan(Request $request, int $plan): RedirectResponse
    {
        $row = CloudPlan::find($plan);

        if ($row === null) {
            return back()->with('err', 'پلن پیدا نشد.');
        }

        $off = ! $row->admin_disabled;

        $row->update([
            'admin_disabled' => $off,
            'admin_note'     => $off ? mb_substr(trim((string) $request->input('note', '')), 0, 180) : null,
        ]);

        $this->afterChange();

        return back()->with('ok', $off
            ? 'پلن «'.$row->public_name.'» بسته شد و در فروشگاه نمایش داده نمی‌شود.'
            : 'پلن «'.$row->public_name.'» باز شد.');
    }

    /** خاموش/روشنِ یک مکان — همهٔ پلن‌هایش با هم */
    public function toggleLocation(string $code): RedirectResponse
    {
        $loc = CloudLocation::where('code', $code)->first();

        if ($loc === null) {
            return back()->with('err', 'مکان پیدا نشد.');
        }

        $loc->update(['is_active' => ! $loc->is_active]);
        $this->afterChange();

        return back()->with('ok', $loc->label('fa').($loc->is_active ? ' باز شد.' : ' بسته شد.'));
    }

    /**
     * خاموش/روشنِ یک **کشور** — همهٔ مکان‌هایش.
     *
     * چرا لازم است: «آلمان را نفروش» یک تصمیمِ واحد است، ولی آلمان چند شهر
     * دارد. بی‌این، مدیر باید یکی‌یکی ببندد و یکی جا می‌افتد.
     */
    public function toggleCountry(string $iso): RedirectResponse
    {
        $iso = strtoupper($iso);
        $locs = CloudLocation::where('country', $iso)->get();

        if ($locs->isEmpty()) {
            return back()->with('err', 'کشور پیدا نشد.');
        }

        // اگر همه باز است ⇒ همه را ببند؛ وگرنه همه را باز کن
        $allOn = $locs->every(fn ($l) => (bool) $l->is_active);

        CloudLocation::where('country', $iso)->update(['is_active' => ! $allOn]);
        $this->afterChange();

        $name = CloudLocation::COUNTRIES[$iso]['fa'] ?? $iso;

        return back()->with('ok', $name.($allOn ? ' بسته شد ('.fa_num($locs->count()).' مکان).' : ' باز شد.'));
    }

    /** خاموش/روشنِ یک زیرساخت */
    public function toggleProvider(string $provider): RedirectResponse
    {
        if (! array_key_exists($provider, CloudManager::DRIVERS)) {
            return back()->with('err', 'زیرساخت ناشناخته.');
        }

        $off = ! CloudPlan::providerIsDisabled($provider);
        CloudPlan::setProviderDisabled($provider, $off);
        $this->afterChange();

        return back()->with('ok', $this->manager->realLabel($provider)
            .($off ? ' خاموش شد — پلن‌هایش فروخته نمی‌شوند.' : ' روشن شد.'));
    }

    /**
     * بعد از هر تغییرِ دسترسی، کشِ منو و کشورها باید دور ریخته شود.
     *
     * بی‌این، پکیجی که بسته‌اید تا ۱۰ دقیقه در منو و صفحات می‌مانَد و مدیر فکر
     * می‌کند دکمه کار نکرده.
     */
    private function afterChange(): void
    {
        \App\Services\SiteMenu::forget();
        \App\Services\Cloud\CloudCountry::forget();
    }

    /**
     * ساختارِ خامِ پاسخِ زیرساختِ دوم — ابزارِ عیب‌یابی.
     *
     * چرا هست: داکیومنتِ آن ارائه‌دهنده نمونهٔ کاملِ JSON نداشت، پس نگاشتِ
     * فیلدها بخشی حدسی است. با این خروجی می‌شود دقیقش کرد.
     */
    public function probe(): View
    {
        /*
        | ?provider= هر درایوری که rawProbe دارد (aeza، ovh، hetzner-robot…).
        | پیش‌فرض همان aeza می‌مانَد تا نشانی‌های قدیمی نشکنند.
        |
        | ⚠️ فقط از فهرستِ DRIVERS — ورودیِ آزادِ کوئری هرگز نامِ کلاس نمی‌شود.
        */
        $slug = (string) request()->query('provider', 'aeza');

        $driver = array_key_exists($slug, \App\Services\Cloud\CloudManager::DRIVERS)
            ? $this->manager->driver($slug)
            : null;

        if ($driver === null || ! method_exists($driver, 'rawProbe')) {
            $data = ['error' => 'این زیرساخت probe ندارد.'];
        } elseif (! $driver->isConfigured()) {
            $data = ['error' => 'توکنِ این زیرساخت تنظیم نشده است.'];
        } else {
            $data = $driver->rawProbe();
        }

        return view('admin.cloud-probe', ['data' => $data]);
    }
}
