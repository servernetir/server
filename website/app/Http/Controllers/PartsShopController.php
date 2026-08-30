<?php

namespace App\Http\Controllers;

use App\Models\ServerPart;
use App\Services\Shop\PartsCatalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * فروشگاهِ قطعاتِ سرور — هاب، دسته، محصول، صفحهٔ نسل، و مقایسه.
 *
 * جدا از `ServerShopController` که سرورِ **کامل** می‌فروشد. آن‌جا واحدِ فروش
 * یک دستگاه است؛ این‌جا یک قطعه که خریدار می‌خواهد بداند روی کدام نسل
 * می‌نشیند و در برابرِ گزینهٔ کناری‌اش چه فرقی دارد.
 */
class PartsShopController extends Controller
{
    /** سقفِ مقایسه. بیش از این، جدول روی موبایل ناخوانا می‌شود. */
    public const COMPARE_MAX = 4;

    /**
     * سقفِ نمایشِ یک دسته.
     *
     * ⚠️ وقتی سقف می‌خورد، قالب **می‌گوید** چند تا نشان داده نشده.
     * بریدنِ خاموشِ فهرست بدترین حالت است: کاربر «۲۴ قطعه» می‌بیند و نتیجه
     * می‌گیرد همینقدر داریم، در حالی‌که ۹ تای دیگر هم هست.
     *
     * ⚠️ عدد از بزرگ‌ترین دستهٔ امروز (۳۳ پردازنده) بالاتر گرفته شده تا در
     * عمل هیچ‌چیز بریده نشود؛ سقف فقط تورِ ایمنیِ رشدِ آینده است. صفحه‌بندیِ
     * واقعی وقتی ارزش دارد که کاتالوگ از این بگذرد — تا آن‌وقت، یک شبکهٔ
     * کامل بهتر از دو صفحه است.
     */
    private const PER_PAGE = 48;

    public function __construct(private readonly PartsCatalog $catalog) {}

    public function index(): View
    {
        return view('pages.parts-index', [
            'categories'  => $this->catalog->categories(),
            'generations' => $this->catalog->generations(),
            'popular'     => $this->catalog->popular(),
            'available'   => $this->catalog->available(),
        ]);
    }

    /**
     * فهرستِ یک دسته با فیلترها.
     *
     * ⚠️ فیلترها با `whereIn` روی **مقدارهای موجود** اعتبارسنجی می‌شوند نه با
     * `$request->validate()`: پارامترِ نامعتبر در URL باید بی‌اثر باشد، نه
     * ۴۲۲. کاربری که لینکِ قدیمی را باز می‌کند باید فهرست ببیند، نه خطا.
     */
    public function category(Request $request, string $category): View
    {
        abort_unless(isset(ServerPart::CATEGORIES[$category]), 404);

        $facets = $this->catalog->facets($category);

        $gen  = $this->pick($request->query('gen'), $facets['gens']);
        $cond = $this->pick($request->query('condition'), $facets['conditions']);
        $sort = $this->pick($request->query('sort'), ['popular', 'price_asc', 'price_desc', 'name'], 'popular');

        // ⚠️ جستجو محدود به ۶۰ کاراکتر: رشتهٔ بلندتر جستجو نیست، تلاش برای
        //    خسته‌کردنِ LIKE است. بریدن بی‌صداست چون نتیجه‌اش عوض نمی‌شود.
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        $maxEur = $this->positiveInt($request->query('max'));

        $parts = collect();
        $total = 0;

        if ($this->catalog->available()) {
            // ⚠️ `$b` نه `$q`: `$q` حالا رشتهٔ جستجوست و هم‌نامی‌شان یعنی
            //    یکی بی‌صدا دیگری را بازنویسی کند.
            $b = ServerPart::query()->active()->where('category', $category);

            if ($gen !== null) {
                $b->forGeneration($gen);
            }
            if ($cond !== null) {
                $b->where('condition', $cond);
            }

            /*
            | جستجوی متنی روی اسلاگ، نام و برند.
            |
            | ⚠️ `name` ستونِ JSON است و با `like` روی متنِ خامِ JSON جستجو
            | می‌شود. این عمداً است: خریدار «2680» یا «Xeon» را می‌زند و باید
            | در هر سه زبان پیدایش کند، بی‌آنکه لازم باشد بدانیم روی MariaDB
            | است یا SQLite. جستجوی دقیق‌ترِ JSON بین این دو موتور فرق دارد.
            */
            if ($q !== '') {
                $b->where(function ($w) use ($q) {
                    $w->where('slug', 'like', '%'.$q.'%')
                        ->orWhere('name', 'like', '%'.$q.'%')
                        ->orWhere('brand', 'like', '%'.$q.'%')
                        ->orWhere('tagline', 'like', '%'.$q.'%');
                });
            }

            /*
            | 🔴 سقفِ قیمت روی `price_eur` اعمال می‌شود، نه روی عددِ تومانی.
            |
            | تومان از نرخِ لحظه‌ای ساخته می‌شود؛ اگر فیلتر رویش بود، همان
            | آدرس فردا نتیجهٔ دیگری می‌داد و لینکِ ذخیره‌شدهٔ کاربر بی‌معنا
            | می‌شد. یورو ثابت است.
            |
            | ⚠️ قطعهٔ استعلامی از فیلترِ قیمت **کنار می‌رود**، نه اینکه صفر
            | حساب شود. «زیر ۵۰ یورو» یعنی قیمتش را می‌دانیم و کمتر است.
            */
            if ($maxEur !== null) {
                $b->where('price_contact', false)->where('price_eur', '<=', $maxEur * 100);
            }

            /*
            | ⚠️ مرتب‌سازی بر اساسِ قیمت **در PHP** انجام می‌شود نه در SQL.
            |
            | قیمتِ نمایشی از `price_eur` می‌آید ولی بعضی ردیف‌ها `price_irt`ِ
            | override دارند و بعضی «استعلامی»‌اند. `ORDER BY price_eur` هر سه
            | را با یک ستون می‌سنجید و ردیف‌های استعلامی (NULL) بسته به موتورِ
            | دیتابیس اولِ فهرست یا آخرش می‌افتادند — یعنی همان فهرست روی
            | SQLiteِ محلی و MariaDBِ پروداکشن دو ترتیبِ متفاوت داشت.
            */
            $parts = $b->orderBy('sort')->orderBy('id')->get(ServerPart::CARD_COLUMNS);

            $parts = match ($sort) {
                'price_asc'  => $this->byPrice($parts, false),
                'price_desc' => $this->byPrice($parts, true),
                'name'       => $parts->sortBy(fn (ServerPart $p) => $p->label())->values(),
                default      => $parts->sortByDesc(fn (ServerPart $p) => $p->popular ? 1 : 0)->values(),
            };

            $total = $parts->count();
            $parts = $parts->take(self::PER_PAGE);
        }

        return view('pages.parts-category', [
            'categories'  => $this->catalog->categories(),
            'generations' => $this->catalog->generations(),
            'category'    => $category,
            'meta'        => ServerPart::CATEGORIES[$category],
            /*
            | 🔴 محتوای اختصاصیِ همین دسته.
            |
            | نسخهٔ اول همهٔ ۹ دسته یک پاراگرافِ معرفیِ یکسان داشتند — ۹ دسته
            | × ۳ زبان = ۲۷ صفحه با متنِ کاملاً یکسان. گوگل این را محتوای
            | تکراری می‌بیند و در بهترین حالت یکی را نگه می‌دارد؛ یعنی هشت
            | دسته عملاً از نتایج حذف می‌شدند، بی‌آنکه چیزی خطا بدهد.
            */
            'content'     => (array) config('parts_content.'.$category, []),
            'parts'       => $parts,
            'total'       => $total,
            'facets'      => $facets,
            'active'      => ['gen' => $gen, 'condition' => $cond, 'sort' => $sort, 'q' => $q, 'max' => $maxEur],
            'priceSteps'  => $this->priceSteps($category),
        ]);
    }

    /**
     * صفحهٔ یک قطعه.
     *
     * 🔴 عدمِ تطابقِ دسته ⇒ ۴۰۴ و نه نمایشِ صفحه. اگر `/parts/ram/xeon-e5-2650`
     * هم کار می‌کرد، هر قطعه با ۹ آدرسِ متفاوت در دسترس بود و گوگل نُه نسخهٔ
     * تکراری می‌دید. یک محصول، یک آدرس.
     */
    public function show(string $category, string $slug): View
    {
        abort_unless(isset(ServerPart::CATEGORIES[$category]), 404);
        abort_unless($this->catalog->available(), 404);

        $part = ServerPart::query()->active()->where('slug', $slug)->first();

        abort_if($part === null || $part->category !== $category, 404);

        // مرتبط‌ها: هم‌دسته و هم‌نسل اولویت دارند، تا ۴ تا، به‌جز خودش
        $related = ServerPart::query()
            ->active()
            ->where('category', $category)
            ->where('id', '!=', $part->id)
            ->orderBy('sort')
            ->limit(12)
            ->get(ServerPart::CARD_COLUMNS)
            ->sortByDesc(fn (ServerPart $p) => count(array_intersect(
                (array) ($p->compat_gens ?? []),
                (array) ($part->compat_gens ?? [])
            )))
            ->take(4)
            ->values();

        return view('pages.part-detail', [
            'categories'  => $this->catalog->categories(),
            'generations' => $this->catalog->generations(),
            'category'    => $category,
            'meta'        => ServerPart::CATEGORIES[$category],
            'part'        => $part,
            'related'     => $related,
        ]);
    }

    /** صفحهٔ نسل: مشخصاتِ نسل + قطعه‌های سازگار، دسته‌بندی‌شده. */
    public function generation(string $gen): View
    {
        $data = $this->catalog->generation($gen);

        abort_if($data === null, 404);

        $byCategory = collect();

        if ($this->catalog->available()) {
            $byCategory = ServerPart::query()
                ->active()
                ->forGeneration($gen)
                ->orderBy('sort')
                ->get(ServerPart::CARD_COLUMNS)
                ->groupBy('category');
        }

        return view('pages.parts-generation', [
            'categories'  => $this->catalog->categories(),
            'generations' => $this->catalog->generations(),
            'gen'         => $gen,
            'data'        => $data,
            'byCategory'  => $byCategory,
        ]);
    }

    /**
     * مقایسهٔ چند قطعه.
     *
     * ⚠️ اسلاگ‌ها از query می‌آیند (`?parts=a,b,c`) نه از مسیر: کاربر انتخاب را
     * در صفحهٔ دسته می‌سازد و لینکِ حاصل باید قابلِ اشتراک باشد. مسیرِ ثابت
     * یعنی هر ترکیب یک روت.
     */
    public function compare(Request $request): View
    {
        $slugs = collect(explode(',', (string) $request->query('parts', '')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->unique()
            ->take(self::COMPARE_MAX);

        $parts = collect();

        if ($slugs->isNotEmpty() && $this->catalog->available()) {
            $rows = ServerPart::query()->active()->whereIn('slug', $slugs->all())->get();

            // ترتیبِ URL حفظ می‌شود — کاربر ستون‌ها را همان‌طور می‌خواهد که چید
            $parts = $slugs->map(fn ($s) => $rows->firstWhere('slug', $s))->filter()->values();
        }

        /*
        | ردیف‌های جدول: فقط ویژگی‌هایی که **دستِ‌کم یک** ستون دارد.
        |
        | 🔴 بی‌این فیلتر، مقایسهٔ دو پردازنده ۱۴ ردیف داشت که ۸ تایش برای هر
        | دو خالی بود («ظرفیت»، «تعداد پورت»، …) و جدول بی‌مصرف می‌شد.
        */
        $rows = [];
        foreach (ServerPart::ATTR_LABELS as $key => $label) {
            $values = $parts->map(fn (ServerPart $p) => $p->attrs[$key] ?? null);

            if ($values->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
                continue;
            }

            $rows[$key] = ['label' => $label, 'values' => $values->all()];
        }

        return view('pages.parts-compare', [
            'categories'  => $this->catalog->categories(),
            'generations' => $this->catalog->generations(),
            'parts'       => $parts,
            'rows'        => $rows,
        ]);
    }

    /** عددِ مثبت از query، یا `null` — بی‌استثنا برای ورودیِ بی‌معنا. */
    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * پله‌های پیشنهادیِ سقفِ قیمت — از **دادهٔ واقعیِ همان دسته**.
     *
     * 🔴 پلهٔ ثابت (۵۰/۱۰۰/۲۰۰ یورو) در دسته‌ای که گران‌ترین قطعه‌اش ۳۰ یورو
     * است، سه فیلتر می‌ساخت که هر سه همان نتیجه را می‌دادند — و در دستهٔ
     * پردازنده، سقفِ ۲۰۰ یورو نصفِ کاتالوگ را کنار می‌گذاشت. پله باید از
     * توزیعِ واقعیِ قیمت‌ها بیاید.
     *
     * @return list<int>  سقف‌ها به یورو
     */
    private function priceSteps(string $category): array
    {
        if (! $this->catalog->available()) {
            return [];
        }

        $max = ServerPart::query()
            ->active()
            ->where('category', $category)
            ->where('price_contact', false)
            ->max('price_eur');

        if (! $max) {
            return [];
        }

        $max = (int) ceil($max / 100);

        // سه پله در یک‌سوم، دو‌سوم و کلِ بازه — گردشده تا عددِ خوانا بدهد
        $round = fn (int $v) => $v >= 200 ? (int) (round($v / 50) * 50) : (int) (round($v / 10) * 10);

        $steps = array_values(array_unique(array_filter([
            $round((int) ($max / 3)),
            $round((int) ($max * 2 / 3)),
            $round($max),
        ])));

        sort($steps);

        // ⚠️ کمتر از دو پله یعنی فیلتر بی‌معناست؛ اصلاً نشانش نمی‌دهیم
        return count($steps) >= 2 ? $steps : [];
    }

    /** @param  list<string>  $allowed */
    private function pick(mixed $value, array $allowed, ?string $default = null): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * مرتب‌سازی بر اساسِ قیمت — **استعلامی‌ها همیشه آخر**.
     *
     * ⚠️ حتی در حالتِ «گران به ارزان». قطعهٔ بی‌قیمت گران‌ترین نیست؛ نامعلوم
     * است، و صدرِ فهرست جای چیزی نیست که خریدار نمی‌تواند بخرد.
     */
    private function byPrice(\Illuminate\Support\Collection $parts, bool $desc): \Illuminate\Support\Collection
    {
        [$priced, $ask] = $parts->partition(fn (ServerPart $p) => $p->eurAmount() !== null);

        $priced = $desc
            ? $priced->sortByDesc(fn (ServerPart $p) => $p->eurAmount())
            : $priced->sortBy(fn (ServerPart $p) => $p->eurAmount());

        return $priced->values()->concat($ask->values())->values();
    }
}
