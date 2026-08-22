<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * یک قطعهٔ سرور در فروشگاه.
 *
 * ⚠️ `attrs` ماشین‌خوان است و `specs` آدم‌خوان. فیلتر و مقایسه از اولی
 * می‌خوانند، جدولِ صفحهٔ محصول از دومی. یکی‌کردنشان یعنی یا فیلتر از دست
 * می‌رود یا خوانایی.
 */
class ServerPart extends Model
{
    protected $fillable = [
        'slug', 'category', 'brand', 'compat_gens', 'condition',
        'price_contact', 'price_irt', 'price_eur',
        'in_stock', 'popular', 'active', 'sort',
        'name', 'tagline', 'summary', 'body', 'specs', 'attrs', 'gallery',
    ];

    protected $casts = [
        'compat_gens'   => 'array',
        'price_contact' => 'boolean',
        'in_stock'      => 'boolean',
        'popular'       => 'boolean',
        'active'        => 'boolean',
        'sort'          => 'integer',
        'price_irt'     => 'integer',
        'price_eur'     => 'integer',
        'name'          => 'array',
        'tagline'       => 'array',
        'summary'       => 'array',
        'body'          => 'array',
        'specs'         => 'array',
        'attrs'         => 'array',
        'gallery'       => 'array',
    ];

    /**
     * دسته‌های فروشگاه — **تنها منبع**.
     *
     * ⚠️ ترتیب عمدی است و همان ترتیبِ سایدبار می‌شود: از چیزی که خریدار
     * بیشتر دنبالش است به کمتر. شاسی اول است چون نقطهٔ شروعِ ساختِ سرور است.
     */
    public const CATEGORIES = [
        'chassis' => ['icon' => 'server', 'fa' => 'شاسی و بربون', 'en' => 'Chassis & barebones', 'tr' => 'Kasa ve barebone'],
        'cpu'     => ['icon' => 'cpu',    'fa' => 'پردازنده',          'en' => 'Processors',                 'tr' => 'İşlemciler'],
        'ram'     => ['icon' => 'zap',    'fa' => 'حافظه (RAM)',       'en' => 'Memory (RAM)',               'tr' => 'Bellek (RAM)'],
        'disk'    => ['icon' => 'db',     'fa' => 'دیسک و ذخیره‌سازی',  'en' => 'Drives & storage',           'tr' => 'Disk ve depolama'],
        'raid'    => ['icon' => 'box',    'fa' => 'کنترلر RAID',       'en' => 'RAID controllers',           'tr' => 'RAID denetleyiciler'],
        'nic'     => ['icon' => 'globe',  'fa' => 'کارت شبکه',         'en' => 'Network cards',              'tr' => 'Ağ kartları'],
        'psu'     => ['icon' => 'zap',    'fa' => 'منبع تغذیه',        'en' => 'Power supplies',             'tr' => 'Güç kaynakları'],
        'gpu'     => ['icon' => 'cpu',    'fa' => 'کارت گرافیک',       'en' => 'GPUs',                       'tr' => 'Ekran kartları'],
        'other'   => ['icon' => 'box',    'fa' => 'سایر قطعات',        'en' => 'Other parts',                'tr' => 'Diğer parçalar'],
    ];

    /**
     * برچسب و واحدِ ویژگی‌های ماشین‌خوان — پایهٔ جدولِ مقایسه.
     *
     * 🔴 بی‌این نگاشت، جدولِ مقایسه کلیدِ خام (`cores`) نشان می‌داد. و بدتر:
     * واحد نداشت، یعنی «۲٫۲» می‌دید و نمی‌فهمید گیگاهرتز است یا ترابایت.
     *
     * ⚠️ `higher_better` برای رنگ‌کردنِ بهترین مقدار در مقایسه است. برای
     * توانِ مصرفی **کمتر** بهتر است — نادیده‌گرفتنش یعنی جدول پرمصرف‌ترین
     * قطعه را برنده نشان می‌داد.
     */
    public const ATTR_LABELS = [
        'cores'     => ['fa' => 'هسته',         'en' => 'Cores',          'tr' => 'Çekirdek',     'unit' => '',     'higher_better' => true],
        'threads'   => ['fa' => 'رشته',         'en' => 'Threads',        'tr' => 'İş parçacığı', 'unit' => '',     'higher_better' => true],
        'ghz'       => ['fa' => 'فرکانس پایه',  'en' => 'Base clock',     'tr' => 'Temel hız',    'unit' => 'GHz',  'higher_better' => true],
        'ghz_turbo' => ['fa' => 'فرکانس توربو', 'en' => 'Turbo clock',    'tr' => 'Turbo hız',    'unit' => 'GHz',  'higher_better' => true],
        'cache_mb'  => ['fa' => 'کش',           'en' => 'Cache',          'tr' => 'Önbellek',     'unit' => 'MB',   'higher_better' => true],
        'tdp'       => ['fa' => 'توان مصرفی',   'en' => 'TDP',            'tr' => 'TDP',          'unit' => 'W',    'higher_better' => false],
        'gb'        => ['fa' => 'ظرفیت',        'en' => 'Capacity',       'tr' => 'Kapasite',     'unit' => 'GB',   'higher_better' => true],
        'speed_mts' => ['fa' => 'سرعت',         'en' => 'Speed',          'tr' => 'Hız',          'unit' => 'MT/s', 'higher_better' => true],
        'iops'      => ['fa' => 'IOPS تخمینی',  'en' => 'Estimated IOPS', 'tr' => 'Tahmini IOPS', 'unit' => '',     'higher_better' => true],
        'ports'     => ['fa' => 'تعداد پورت',   'en' => 'Ports',          'tr' => 'Port sayısı',  'unit' => '',     'higher_better' => true],
        'gbps'      => ['fa' => 'سرعت پورت',    'en' => 'Port speed',     'tr' => 'Port hızı',    'unit' => 'Gb/s', 'higher_better' => true],
        'watt'      => ['fa' => 'توان',         'en' => 'Output',         'tr' => 'Güç',          'unit' => 'W',    'higher_better' => true],
        'bays'      => ['fa' => 'جایگاه دیسک',  'en' => 'Drive bays',     'tr' => 'Disk yuvası',  'unit' => '',     'higher_better' => true],
        'u'         => ['fa' => 'ارتفاع رک',    'en' => 'Rack height',    'tr' => 'Raf yüksekliği', 'unit' => 'U',  'higher_better' => false],
    ];

    /**
     * ستون‌هایی که **کارتِ فهرست** لازم دارد — و نه بیشتر.
     *
     * 🔴 چرا: `body` متنِ سئوی سه‌زبانه است و به‌تنهایی حدودِ ۱۲ کیلوبایت در
     * هر ردیف. صفحهٔ نسل تا ۵۰ قطعه می‌آورد و از هرکدام فقط نام و قیمت را
     * نشان می‌دهد — یعنی نیم مگابایت JSON از دیتابیس خوانده و decode می‌شد
     * که هیچ‌جا چاپ نمی‌شد. هیچ خطایی نداشت؛ فقط صفحه کند بود.
     *
     * ⚠️ `id` لازم است (کلیدِ حلقه و مقایسهٔ «به‌جز خودش») و `popular` برای
     * مرتب‌سازی. حذفِ هرکدام یعنی Eloquent موقعِ دسترسی خطای ستونِ غایب بدهد.
     */
    public const CARD_COLUMNS = [
        'id', 'slug', 'category', 'brand', 'compat_gens', 'condition',
        'price_contact', 'price_eur', 'price_irt', 'in_stock', 'popular',
        'sort', 'name', 'tagline', 'attrs', 'gallery',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /**
     * قطعه‌های سازگار با یک نسل.
     *
     * ⚠️ قطعهٔ بدونِ فهرستِ سازگاری «عمومی» است (مثلِ کارتِ شبکهٔ استاندارد) و
     * باید در همهٔ نسل‌ها دیده شود. بی‌شاخهٔ `orWhereNull`، بی‌صدا از فروشگاه
     * غیب می‌شد — نه خطایی، نه ردیفی، فقط نبودن.
     */
    public function scopeForGeneration(Builder $q, string $gen): Builder
    {
        return $q->where(function (Builder $w) use ($gen) {
            $w->whereJsonContains('compat_gens', $gen)
                ->orWhereNull('compat_gens');
        });
    }

    public function label(?string $locale = null): string
    {
        $loc = $locale ?? app()->getLocale();

        return (string) ($this->name[$loc] ?? $this->name['fa'] ?? $this->slug);
    }

    /** برچسبِ دسته در زبانِ جاری. */
    public function categoryLabel(?string $locale = null): string
    {
        $loc = $locale ?? app()->getLocale();

        return (string) (self::CATEGORIES[$this->category][$loc] ?? $this->category);
    }

    /**
     * قیمتِ نمایشی، یا `null` اگر استعلامی است.
     *
     * 🔴 مبنا **یورو** است نه تومان.
     *
     * قطعهٔ سرور از بازارِ جهانی می‌آید و قیمتش یورویی است. اگر عددِ تومانی
     * ذخیره می‌شد، با هر جهشِ ارز کلِ کاتالوگ باید دستی به‌روز می‌شد — که
     * نمی‌شود — و فروشگاه بی‌سروصدا زیرِ قیمتِ خرید می‌فروخت.
     *
     * ⚠️ `price_irt` **override فقط برای فارسی** است، نه قیمتِ دوم: بعضی
     * قطعه‌ها را از بازارِ داخلی می‌خریم و قیمتِ تومانی‌شان ربطی به نرخِ ارز
     * ندارد. صفحهٔ en/tr همچنان `price_eur` را می‌بیند؛ اگر آن خالی باشد،
     * آن‌جا «استعلام کنید» می‌شود — که راست است، چون قیمتِ یوروییِ آن قطعه
     * را واقعاً نداریم.
     *
     * `null` عمداً از صفر جدا است: صفر یعنی «رایگان» و روی قطعهٔ سرور فاجعه
     * است. همان قاعدهٔ `site_price()` که در این پروژه یک بار گران تمام شده.
     */
    public function displayPrice(): ?string
    {
        if ($this->price_contact) {
            return null;
        }

        if (app()->getLocale() === 'fa' && (int) $this->price_irt > 0) {
            return site_price(['irt' => (int) $this->price_irt]);
        }

        return part_price($this->price_eur === null ? null : (int) $this->price_eur);
    }

    /** قیمتِ خام به یورو — برای مرتب‌سازی و schema.org، بی‌قالبِ نمایشی. */
    public function eurAmount(): ?float
    {
        if ($this->price_contact || (int) $this->price_eur <= 0) {
            return null;
        }

        return round((int) $this->price_eur / 100, 2);
    }
}
