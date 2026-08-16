<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * یک پکیجِ فروش که مشتری آنلاین می‌خرد.
 */
class Product extends Model
{
    protected $fillable = [
        'name', 'slug', 'category', 'group', 'server_id', 'locations', 'plan', 'currency_code',
        'price', 'price_eur', 'setup_fee', 'cycle', 'tax_percent', 'specs', 'description',
        'requires_domain', 'requires_server_ip', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price'           => 'integer',
            'price_eur'       => 'integer',   // سنت — یورو exponent=2
            'setup_fee'       => 'integer',
            'tax_percent'     => 'integer',
            'specs'           => 'array',
            'locations'       => 'array',
            'requires_domain'    => 'boolean',
            'requires_server_ip' => 'boolean',
            'is_active'          => 'boolean',
            'sort'            => 'integer',
        ];
    }

    public const CATEGORIES = [
        'shared'      => 'هاست اشتراکی',
        'reseller'    => 'نمایندگی',
        'vps'         => 'سرور مجازی (VPS)',
        'dedicated'   => 'سرور اختصاصی',
        'plesk'       => 'Plesk',
        'directadmin' => 'DirectAdmin',
        'other'       => 'سایر',
    ];

    /** همان نگهبانِ `Service::FRESH_COLUMNS` — دلیلش آن‌جا کامل نوشته شده. */
    private const FRESH_COLUMNS = ['requires_server_ip'];

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            if (blank($p->slug)) {
                $p->slug = Str::slug($p->name) ?: 'pkg-'.Str::random(6);
            }

            foreach (self::FRESH_COLUMNS as $col) {
                if (array_key_exists($col, $p->getAttributes()) && ! self::columnExists($col)) {
                    unset($p->attributes[$col]);
                }
            }
        });
    }

    /** @var array<string,bool> */
    private static array $columnCache = [];

    public static function columnExists(string $column): bool
    {
        return self::$columnCache[$column]
            ??= \Illuminate\Support\Facades\Schema::hasColumn('products', $column);
    }

    /** پاک‌کردنِ کش — دلیلش در `Service::flushColumnCache()` نوشته شده. */
    public static function flushColumnCache(): void
    {
        self::$columnCache = [];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** قیمتِ مؤثرِ دوره — پایهٔ تومان پس از اعمالِ ضریبِ نرخِ ارز */
    public function effectivePrice(): int
    {
        return price_toman($this->price);
    }

    /** هزینهٔ راه‌اندازیِ مؤثر */
    public function effectiveSetup(): int
    {
        return price_toman($this->setup_fee);
    }

    /**
     * مبلغِ یک دورهٔ کامل (پیش از مالیات) با تخفیفِ پیش‌پرداختِ آن دوره و
     * ضریبِ مکان — همان چیزی که روی services.price قفل می‌شود.
     *
     * ⚠️ ترتیبِ عملیات عمدی است: اول ضرب‌ها روی پایهٔ **خام**، بعد یک‌بار
     * price_toman. اگر اول ماهانه را گرد می‌کردیم و بعد در ۱۲ ضرب می‌کردیم،
     * خطای گردکردن هم ×۱۲ می‌شد (تا ۶۰٬۰۰۰ تومان اختلاف در سال). یک ضربِ ارز و
     * یک گردکردن به ۱۰٬۰۰۰ در پایان.
     */
    /**
     * پایهٔ **ماهانه** از قیمتِ ذخیره‌شده.
     *
     * ⚠️ products.price مبلغِ «دورهٔ خودِ پکیج» است، نه همیشه ماهانه. اگر مدیری
     * پکیجی با cycle=yearly و قیمتِ سالانه بسازد و ما آن را ماهانه فرض کنیم،
     * دورهٔ سالانه ۱۲ برابر قیمت می‌خورد. پس اول به ماه تقسیم می‌کنیم.
     */
    private function monthlyBase(): float
    {
        $own = Service::monthsIn((string) $this->cycle);

        return $own > 1 ? $this->price / $own : (float) $this->price;
    }

    public function priceForCycle(string $cycle, ?string $country = null): int
    {
        $months = Service::monthsIn($cycle);

        // «یک‌بار» دوره ندارد؛ مبلغش همان قیمتِ ثابت است
        if ($months <= 0) {
            return $this->effectivePrice();
        }

        $discount = (int) (config('billing.cycles.'.$cycle.'.discount_pct') ?? 0);
        $mult = $country
            ? (float) (config('billing.locations.'.strtoupper($country).'.multiplier') ?? 1.0)
            : 1.0;

        $raw = $this->monthlyBase() * $months * (100 - $discount) / 100 * $mult;

        return price_toman((int) round($raw));
    }

    /**
     * گردکردنِ قیمتِ تومانی **رو به بالا** تا نزدیک‌ترین پله.
     *
     * خواستهٔ کارفرما: بعد از تغییرِ گروهی، عددها باید تمیز بمانند (۲۴۰٬۰۰۰ نه
     * ۲۳۷٬۴۵۰). رو به بالا هم عمدی است: گردکردنِ پایین یعنی تخفیفِ ناخواسته روی
     * کلِ کاتالوگ.
     */
    public static function roundUpToman(int|float $value, int $step = 10000): int
    {
        $step = max(1, $step);

        return (int) (ceil($value / $step) * $step);
    }

    /** گردکردنِ یورو رو به بالا تا نزدیک‌ترین ۱۰ سنت (۴٫۹۰ نه ۴٫۸۷) */
    public static function roundUpEur(int|float $cents, int $step = 10): int
    {
        $step = max(1, $step);

        return (int) (ceil($cents / $step) * $step);
    }

    /** برچسبِ فارسیِ گروه — از کاتالوگِ سایت اصلی می‌آید تا یکی بماند */
    public function groupLabel(): string
    {
        if (blank($this->group)) {
            return '—';
        }

        $cat = config('hosting.products.'.$this->group);

        return $cat ? (lc($cat)['t'] ?? $this->group) : $this->group;
    }

    /**
     * نامِ package در WHM — منبعِ یگانه.
     *
     * ⚠️ این باید با چیزی که createacct می‌فرستد یکی باشد. قبلاً seeder نامِ
     * پلنِ کاتالوگ («WP-5») را می‌نوشت ولی هیچ packageای در WHM با آن نام وجود
     * نداشت، پس هر خریدِ خودکار در مرحلهٔ تحویل شکست می‌خورد.
     */
    public function packageName(): string
    {
        return 'sn_'.substr(preg_replace('/[^a-z0-9]+/i', '_', (string) $this->slug), 0, 40);
    }

    /**
     * آیا خریدِ این پکیج باید یک **حسابِ نمایندگی** بسازد (نه هاستِ معمولی)؟
     *
     * تنها تعریفِ «نمایندگی» در کلِ پروژه. سه مسیرِ فروش (فروشگاهِ مشتری، فروشِ
     * تلفنی، فروشِ مدیر) همین را صدا می‌زنند تا شرطِ دست‌نویسِ موازی نسازند —
     * وگرنه روزی یکی‌شان دسته‌ای تازه را جا می‌اندازد و همان مسیر بی‌صدا
     * cPanelِ ساده تحویل می‌دهد.
     */
    public function isReseller(): bool
    {
        return $this->category === 'reseller';
    }

    /** معادلِ ماهانهٔ یک دوره — برای نمایشِ «ماهی X تومان» در صفحهٔ خرید */
    public function monthlyEquivalent(string $cycle, ?string $country = null): int
    {
        $months = max(1, Service::monthsIn($cycle));

        return (int) round($this->priceForCycle($cycle, $country) / $months);
    }

    /** درصدِ تخفیفِ آن دوره (برای برچسبِ «۱۵٪ ارزان‌تر») */
    public function savingPct(string $cycle): int
    {
        return (int) (config('billing.cycles.'.$cycle.'.discount_pct') ?? 0);
    }

    /**
     * مکان‌هایی که این پکیج واقعاً قابلِ خرید است: اشتراکِ محدودیتِ خودِ پکیج
     * (اگر ست شده) با مکان‌هایی که سرورِ آمادهٔ فعال دارند.
     *
     * @return list<string>
     */
    public function availableCountries(): array
    {
        $available = Server::availableCountries();
        $allowed = array_filter((array) ($this->locations ?? []));

        if ($allowed === []) {
            return $available;                       // null/خالی = هرجا که سرور داریم
        }

        $allowed = array_map('strtoupper', $allowed);

        return array_values(array_intersect($available, $allowed));
    }

    /** مالیاتِ هر دوره (روی قیمتِ مؤثر) */
    public function taxAmount(): int
    {
        return (int) round($this->effectivePrice() * $this->tax_percent / 100);
    }

    /** مبلغِ کلِ اولین صورت‌حساب (دوره + راه‌اندازی + مالیاتِ هردو) */
    public function firstTotal(): int
    {
        $base = $this->effectivePrice() + $this->effectiveSetup();

        return $base + (int) round($base * $this->tax_percent / 100);
    }

    /** مبلغِ دوره‌ایِ بعدی (بدونِ راه‌اندازی) */
    public function recurringTotal(): int
    {
        return $this->effectivePrice() + $this->taxAmount();
    }

    public function cycleLabel(): string
    {
        return Service::labelFor((string) $this->cycle);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
