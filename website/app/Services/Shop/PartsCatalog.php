<?php

namespace App\Services\Shop;

use App\Models\ServerPart;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * دادهٔ مشترکِ فروشگاهِ قطعات — سایدبار، شمارشِ دسته‌ها، و فیلترها.
 *
 * 🔴 چرا کلاسِ جدا و نه متدِ کنترلر:
 *
 * سایدبار در **هر پنج صفحهٔ** فروشگاه رندر می‌شود (هاب، دسته، محصول، نسل،
 * مقایسه). اگر شمارشِ دسته‌ها را هر کنترلر خودش می‌گرفت، پنج پرس‌وجوی تکراری
 * داشتیم و — بدتر — دیر یا زود یکی‌شان با بقیه فرق می‌کرد و کاربر در یک صفحه
 * «۱۲ پردازنده» می‌دید و در صفحهٔ بعد «۹».
 *
 * ⚠️ نبودِ جدول ⇒ مجموعهٔ خالی، نه استثنا. مهاجرت روی پروداکشن دستِ کاربر
 * است (CLAUDE.md) و بینِ دیپلویِ کد و اجرای مهاجرت یک بازهٔ واقعی هست؛ در آن
 * بازه صفحهٔ «به‌زودی» بی‌نهایت بهتر از ۵۰۰ روی صفحهٔ عمومیِ فروشگاه است.
 */
class PartsCatalog
{
    /** ⚠️ کوتاه عمدی: مدیر که قطعه اضافه می‌کند باید تقریباً بلافاصله ببیندش. */
    private const TTL = 600;

    private const KEY = 'parts:catalog:v1';

    public function available(): bool
    {
        return Schema::hasTable('server_parts');
    }

    /**
     * دسته‌ها با شمارشِ قطعاتِ فعال — ترتیبِ `ServerPart::CATEGORIES`.
     *
     * ⚠️ دستهٔ خالی هم برمی‌گردد (با `count = 0`) و حذف نمی‌شود: سایدبارِ
     * فروشگاه باید دامنهٔ کالاها را نشان بدهد، حتی وقتی یک دسته موقتاً
     * موجودی ندارد. تصمیمِ نشان‌دادن یا ندادنش با قالب است، نه با این‌جا.
     *
     * @return array<string, array{key:string, label:string, icon:string, count:int}>
     */
    public function categories(): array
    {
        $counts = $this->counts();
        $out = [];

        foreach (ServerPart::CATEGORIES as $key => $meta) {
            $out[$key] = [
                'key'   => $key,
                'label' => (string) ($meta[app()->getLocale()] ?? $meta['fa']),
                'icon'  => (string) $meta['icon'],
                'count' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $out;
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        if (! $this->available()) {
            return [];
        }

        return Cache::remember(self::KEY.':counts', self::TTL, fn () => ServerPart::query()
            ->active()
            ->selectRaw('category, count(*) as c')
            ->groupBy('category')
            ->pluck('c', 'category')
            ->map(fn ($v) => (int) $v)
            ->all());
    }

    /**
     * نسل‌های HP برای سایدبار و صفحه‌های نسل.
     *
     * منبع `config/hp_generations.php` است نه دیتابیس: نسل یک واقعیتِ ثابتِ
     * صنعتی است، نه کالایی که مدیر اضافه/کم کند.
     *
     * @return array<string, array<string, mixed>>
     */
    public function generations(): array
    {
        return (array) config('hp_generations', []);
    }

    /** یک نسل، یا `null` — تا کنترلر بتواند ۴۰۴ بدهد به‌جای صفحهٔ نیمه‌خالی. */
    public function generation(string $gen): ?array
    {
        $all = $this->generations();

        return isset($all[$gen]) ? (array) $all[$gen] : null;
    }

    /**
     * مقدارهای قابلِ فیلتر در یک دسته — فقط آن‌هایی که **واقعاً** ردیف دارند.
     *
     * 🔴 فیلترِ بی‌نتیجه بدترین نوعِ فیلتر است: کاربر «Gen12» را می‌زند،
     * صفحهٔ خالی می‌بیند و نتیجه می‌گیرد فروشگاه چیزی ندارد. پس گزینه‌ها از
     * خودِ دادهٔ موجود ساخته می‌شوند، نه از فهرستِ آرزویی.
     *
     * @return array{gens: list<string>, conditions: list<string>, brands: list<string>}
     */
    public function facets(?string $category = null): array
    {
        if (! $this->available()) {
            return ['gens' => [], 'conditions' => [], 'brands' => []];
        }

        /*
        | 🔴 آن‌چه کش می‌شود **آرایهٔ نهایی** است، نه ردیف‌های Eloquent.
        |
        | نسخهٔ اول مدل‌ها را کش می‌کرد و در خواندنِ بعدی
        | `__PHP_Incomplete_Class` برمی‌گشت: PHP شیء را باز می‌کرد پیش از
        | آن‌که کلاسِ `ServerPart` لود شده باشد، و هر `$row->condition`
        | هشدار می‌داد و مقدارِ خالی می‌گرفت — یعنی فیلترها بی‌سروصدا خالی
        | می‌شدند، با کدِ ۲۰۰. کشِ آرایهٔ ساده این کلاس از مسئله را حذف می‌کند.
        */
        $facets = Cache::remember(
            self::KEY.':facets:'.($category ?? 'all'),
            self::TTL,
            function () use ($category) {
                $gens = [];
                $conditions = [];
                $brands = [];

                ServerPart::query()
                    ->active()
                    ->when($category, fn ($q) => $q->where('category', $category))
                    ->select(['compat_gens', 'condition', 'brand'])
                    ->each(function (ServerPart $row) use (&$gens, &$conditions, &$brands) {
                        foreach ((array) ($row->compat_gens ?? []) as $g) {
                            $gens[(string) $g] = true;
                        }
                        $conditions[(string) $row->condition] = true;
                        $brands[(string) $row->brand] = true;
                    });

                ksort($conditions);
                ksort($brands);

                return [
                    'gens'       => array_keys($gens),
                    'conditions' => array_keys($conditions),
                    'brands'     => array_keys($brands),
                ];
            }
        );

        // ترتیبِ نسل‌ها را از config می‌گیریم تا gen10 بینِ gen9 و gen11 بنشیند
        $order = array_keys($this->generations());
        $gens = array_values(array_filter($order, fn ($g) => in_array($g, $facets['gens'], true)));
        return [
            'gens'       => $gens,
            'conditions' => $facets['conditions'],
            'brands'     => $facets['brands'],
        ];
    }

    /**
     * قطعه‌های شاخص برای صفحهٔ هاب.
     *
     * @return Collection<int, ServerPart>
     */
    public function popular(int $limit = 8): Collection
    {
        if (! $this->available()) {
            return collect();
        }

        return ServerPart::query()
            ->active()
            ->where('popular', true)
            ->orderBy('sort')
            ->limit($limit)
            ->get(ServerPart::CARD_COLUMNS);
    }

    /**
     * پاک‌کردنِ کشِ فروشگاه — بعدِ هر تغییر در پنلِ مدیریت.
     *
     * ⚠️ کلید عمداً ثابت است و هش‌شده نیست. یک بار در همین پروژه کلیدِ
     * هش‌شده باعث شد `forget()` هیچ‌وقت به کلیدِ واقعی نخورد و کش برای همیشه
     * کهنه بمانَد — ۱۰ دقیقه کهنگی عوض شد با یک نقصِ دائمی.
     */
    public function flush(): void
    {
        Cache::forget(self::KEY.':counts');

        foreach (array_merge([null], array_keys(ServerPart::CATEGORIES)) as $cat) {
            Cache::forget(self::KEY.':facets:'.($cat ?? 'all'));
        }
    }
}
