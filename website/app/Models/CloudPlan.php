<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * پلنِ سرورِ ابری — هر ردیف «یک پلن نزدِ یک ارائه‌دهنده در یک مکان».
 *
 * ⚠️ ستونِ `provider` **هرگز** نباید به ویوی عمومی برسد. برای همین
 * `$hidden` تنظیم شده تا اگر روزی مدلی به‌صورتِ JSON در پاسخِ API نشست،
 * نامِ ارائه‌دهنده به‌طورِ تصادفی لو نرود.
 *
 * مشتری «عرضه» (offer) می‌بیند نه ردیف: `offers()` ردیف‌های هم‌مشخصات را در یک
 * اسلاگ گروه می‌کند و **ارزان‌ترینِ موجود** را نمایندهٔ گروه می‌گذارد. یعنی اگر
 * هتزنر و آیزا هر دو ۲هسته/۴گیگ در فرانکفورت داشته باشند، یک کارت روی سایت است
 * و ما هر بار از ارزان‌ترین تحویل می‌دهیم.
 */
class CloudPlan extends Model
{
    protected $fillable = [
        'provider', 'provider_ref', 'provider_location', 'location_code',
        'public_name', 'slug', 'vcpu', 'ram_mb', 'disk_gb', 'disk_type',
        'traffic_gb', 'cpu_kind', 'arch', 'cost_eur_cents', 'price_eur_cents',
        'price_irt', 'is_active', 'in_stock', 'sort', 'synced_at',
        'previous_cost_eur_cents', 'cost_changed_at',
        'admin_disabled', 'admin_note',
    ];

    /** حفاظِ سفیدبرچسبی: این ستون‌ها در هیچ JSONای بیرون نمی‌روند */
    protected $hidden = [
        'provider', 'provider_ref', 'provider_location',
        // بهایِ تمام‌شده و تاریخِ تغییرش دادهٔ داخلیِ ما است؛ مشتری نباید
        // بتواند حاشیهٔ سودمان را حساب کند.
        'cost_eur_cents', 'previous_cost_eur_cents', 'cost_changed_at',
    ];

    protected $casts = [
        'is_active'       => 'bool',
        'in_stock'        => 'bool',
        'synced_at'       => 'datetime',
        'cost_changed_at' => 'datetime',
        'admin_disabled'  => 'bool',
    ];

    public function location()
    {
        return $this->belongsTo(CloudLocation::class, 'location_code', 'code');
    }

    // ───────────────────────── انتخابِ عرضه ─────────────────────────

    /**
     * پلن‌های قابلِ فروش.
     *
     * چهار شرط، و هر کدام از یک تصمیم‌گیرندهٔ متفاوت می‌آید — به همین دلیل هم
     * جدا نگه داشته شده‌اند:
     *
     *  `is_active`      واقعیتِ ارائه‌دهنده (سینک می‌نویسد)
     *  `in_stock`       ظرفیتِ ارائه‌دهنده (سینک می‌نویسد)
     *  `price_irt > 0`  قیمت‌گذاری موفق بوده (نرخِ ارز در دسترس بود)
     *  `admin_disabled` تصمیمِ **مدیر** — سینک هرگز لمسش نمی‌کند
     *
     * ⚠️ اگر مدیر روی `is_active` می‌نوشت، کرونِ دو روزه کارش را بی‌صدا
     * برمی‌گرداند: پکیجی که عمداً بسته بود، خودش باز می‌شد و فروخته می‌شد.
     *
     * زیرساختِ خاموش‌شده هم این‌جا فیلتر می‌شود، پس هیچ لایهٔ بالاتری لازم
     * نیست یادش بماند — همان درسِ «فراموش‌شدن در یکی از سه جا».
     */
    public function scopeSellable(Builder $q): Builder
    {
        $off = self::disabledProviders();

        return $q->where('is_active', true)
            ->where('in_stock', true)
            ->where('price_irt', '>', 0)
            ->where('admin_disabled', false)
            ->when($off !== [], fn ($qq) => $qq->whereNotIn('provider', $off));
    }

    /**
     * زیرساخت‌هایی که مدیر خاموش کرده.
     *
     * @return array<int, string>
     */
    public static function disabledProviders(): array
    {
        $raw = (string) (Setting::get('cloud_disabled_providers') ?? '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function providerIsDisabled(string $provider): bool
    {
        return in_array($provider, self::disabledProviders(), true);
    }

    /** خاموش/روشن کردنِ یک زیرساخت */
    public static function setProviderDisabled(string $provider, bool $disabled): void
    {
        $list = self::disabledProviders();

        $list = $disabled
            ? array_values(array_unique([...$list, $provider]))
            : array_values(array_diff($list, [$provider]));

        Setting::put('cloud_disabled_providers', implode(',', $list));
    }

    /**
     * عرضه‌های عمومی: یک ردیف به ازای هر (مشخصات × مکان)، ارزان‌ترینِ موجود.
     *
     * چرا در PHP و نه SQL: `GROUP BY` با «ردیفِ کاملِ کم‌ترین قیمت» در MySQL و
     * SQLite رفتارِ یکسان ندارد (ONLY_FULL_GROUP_BY) و تعدادِ پلن‌ها چندصد است،
     * نه چندصدهزار. سادگی و درست‌بودن مهم‌تر از یک پرس‌وجوی زیرکانه است.
     *
     * @return \Illuminate\Support\Collection<string, CloudPlan>
     */
    public static function offers(?string $locationCode = null)
    {
        return static::query()
            ->sellable()
            ->when($locationCode, fn ($q) => $q->where('location_code', $locationCode))
            ->orderBy('cost_eur_cents')
            ->get()
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->first())      // ارزان‌ترین، چون مرتب‌شده آمد
            ->sortBy([['vcpu', 'asc'], ['ram_mb', 'asc'], ['disk_gb', 'asc']])
            ->values()
            ->keyBy('slug');
    }

    /**
     * چرا این ردیف **الان** فروختنی نیست — یا `null` اگر هست.
     *
     * سه حالت، و تفاوتشان تصمیمِ محصولی است نه فنی:
     *
     *  `off`    مدیر (یا خاموشیِ زیرساخت) عمداً بسته — «کِی برمی‌گردد؟» جواب ندارد
     *  `stock`  ظرفیتِ زیرساخت تمام شده — گذراست، جواب دارد
     *  `price`  نرخِ ارز نرسیده و `price_irt = 0` — گذراست، جواب دارد
     *
     * فروشگاه فقط دو حالتِ گذرا را **صادقانه نشان می‌دهد**؛ حالتِ `off` پنهان
     * می‌مانَد چون نمایشش فقط سروصداست. (قاعدهٔ CLAUDE.md §۱۰.۵: قیمتِ صفر عمدی
     * است و هرگز نباید به‌صورتِ پول نمایش داده شود.)
     */
    public function blockedReason(): ?string
    {
        if (! $this->is_active || $this->admin_disabled || self::providerIsDisabled((string) $this->provider)) {
            return 'off';
        }

        if (! $this->in_stock) {
            return 'stock';
        }

        if ((int) $this->price_irt <= 0) {
            return 'price';
        }

        return null;
    }

    /**
     * «قفسه» — همان گروه‌بندیِ `offers()` ولی شاملِ ردیف‌هایی که فقط **گذرا**
     * فروختنی نیستند (ناموجود یا بی‌قیمت).
     *
     * ⚠️ `scopeSellable` عمداً دست‌نخورده می‌مانَد. چهار تصمیم‌گیرندهٔ دیگر روی آن
     * می‌نویسند و مسیرِ **سفارش** هم از همان می‌خوانَد؛ گشاد کردنش یعنی فروختنِ
     * سروری که نمی‌توانیم تحویل دهیم. این‌جا یک پرس‌وجوی جداست، فقط برای نمایش.
     *
     * برای هر اسلاگ: اگر ردیفِ فروختنی داشت، ارزان‌ترینش؛ وگرنه ارزان‌ترین ردیفِ
     * مانده تا مشتری ببیند این اندازه وجود دارد ولی الان در دسترس نیست.
     *
     * @return \Illuminate\Support\Collection<string, CloudPlan>
     */
    public static function shelf(?string $locationCode = null)
    {
        $off = self::disabledProviders();

        return static::query()
            ->where('is_active', true)
            ->where('admin_disabled', false)
            ->when($off !== [], fn ($qq) => $qq->whereNotIn('provider', $off))
            ->when($locationCode, fn ($q) => $q->where('location_code', $locationCode))
            ->orderBy('cost_eur_cents')
            ->get()
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->first(fn ($r) => $r->blockedReason() === null) ?? $rows->first())
            ->sortBy([['vcpu', 'asc'], ['ram_mb', 'asc'], ['disk_gb', 'asc']])
            ->values()
            ->keyBy('slug');
    }

    /**
     * بهترین ردیف برای تحویلِ یک عرضه — همان که تحویل روی آن انجام می‌شود.
     *
     * اگر ارائه‌دهندهٔ ارزان‌تر موجودی نداشت، خودکار سراغِ بعدی می‌رود؛ مشتری
     * هیچ تفاوتی نمی‌بیند. این همان جایی است که «سفیدبرچسبی» ارزشِ عملی می‌دهد.
     */
    public static function bestForSlug(string $slug): ?self
    {
        return static::query()->sellable()->where('slug', $slug)->orderBy('cost_eur_cents')->first();
    }

    // ───────────────────────── نمایش ─────────────────────────

    public function ramLabel(): string
    {
        return $this->ram_mb >= 1024
            ? rtrim(rtrim(number_format($this->ram_mb / 1024, 1, '.', ''), '0'), '.').' GB'
            : $this->ram_mb.' MB';
    }

    public function diskLabel(): string
    {
        return $this->disk_gb.' GB '.strtoupper((string) $this->disk_type);
    }

    /** ترافیک: ۰ یعنی «منصفانه/نامحدود» */
    public function trafficLabel(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();

        /*
        | نمایشِ «نامحدود» برای ترافیک — تصمیمِ **تجاری**، نه فنی.
        |
        | ⚠️ این یک وعده است، نه یک توصیف: سقفِ واقعیِ زیرساخت سرِ جایش می‌مانَد
        | و اگر مشتری از آن رد شود، هزینه‌اش را ما می‌دهیم. برای همین یک کلید
        | در تنظیمات است و نه سخت‌کد — تا هر وقت لازم شد بدونِ دیپلوی خاموش
        | شود، و تا در کد پیدا باشد که این عدد از کجا آمده.
        |
        | ⚠️ `traffic_gb` دست‌نخورده می‌مانَد؛ فقط **برچسبِ نمایشی** عوض می‌شود.
        | محاسبات، مقایسه‌ها و قاعدهٔ حذفِ پلنِ مغلوب همچنان عددِ واقعی را
        | می‌بینند — وگرنه دو پلن با ترافیکِ متفاوت یکی شمرده می‌شدند.
        */
        if (Setting::get('cloud_traffic_unlimited') === '1') {
            return match ($locale) {
                'en' => 'Unlimited',
                'tr' => 'Sınırsız',
                default => 'نامحدود',
            };
        }

        if ((int) $this->traffic_gb <= 0) {
            return match ($locale) {
                'en' => 'Fair use',
                'tr' => 'Adil kullanım',
                default => 'مصرفِ منصفانه',
            };
        }

        return $this->traffic_gb >= 1024
            ? rtrim(rtrim(number_format($this->traffic_gb / 1024, 1, '.', ''), '0'), '.').' TB'
            : $this->traffic_gb.' GB';
    }

    public function cpuKindLabel(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();
        $dedicated = $this->cpu_kind === 'dedicated';

        return match ($locale) {
            'en' => $dedicated ? 'Dedicated vCPU' : 'Shared vCPU',
            'tr' => $dedicated ? 'Ayrılmış vCPU' : 'Paylaşımlı vCPU',
            default => $dedicated ? 'پردازندهٔ اختصاصی' : 'پردازندهٔ اشتراکی',
        };
    }

    /**
     * آیا بهایِ تمام‌شده از آخرین همگام‌سازی **گران‌تر** شده؟
     *
     * سرویس‌های فعالی که روی این پلن نشسته‌اند با قیمتِ قفل‌شدهٔ قدیم تمدید
     * می‌شوند، پس هر «true» این‌جا یعنی ضررِ در جریان.
     */
    // ───────────────────────── نرخِ ساعتی ─────────────────────────
    // فروشِ ساعتی برای هر سه زیرساخت: قیمتِ ماهانه ÷ ۷۲۰ ساعت (۳۰×۲۴)، گردِ
    // رو به **بالا** — چون ساعتی همیشه گران‌تر از تعهدِ ماهانه است و نباید زیرِ
    // قیمت برویم. ۷۲۰ همان ضریبی است که در تبدیلِ ساعتی→ماهانهٔ آروان به کار رفت.

    /** نرخِ ساعتی به تومان (گردِ رو به بالا به نزدیک‌ترین ۱۰۰ تومان برای ظاهرِ تمیز). */
    public function hourlyIrt(): int
    {
        $monthly = (int) $this->price_irt;

        if ($monthly <= 0) {
            return 0;
        }

        return (int) (ceil(ceil($monthly / 720) / 100) * 100);
    }

    /** نرخِ ساعتی به سنتِ یورو (برای نمایشِ en/tr). */
    public function hourlyEurCents(): int
    {
        $monthly = (int) $this->price_eur_cents;

        return $monthly > 0 ? (int) ceil($monthly / 720) : 0;
    }

    /**
     * 🔴 **تنها منبعِ کفِ اعتبارِ شروعِ ساعتی.** (تصمیمِ کارفرما، مرداد ۱۴۰۵:
     * «۱۲ ساعت حداقل پولشو داشته باشه رو پنلش.»)
     *
     * ⚠️ این عدد پیش‌تر **دو بار** نوشته شده بود: یکی این‌جا (که فقط *نمایش* را
     * تغذیه می‌کرد) و یکی سخت‌کد در `CloudStoreController::orderHourly()` که
     * تنها چیزی بود که واقعاً خرید را می‌بست. عوض‌کردنِ یکی و نه دیگری یعنی یا
     * مشتری‌ای که دقیقاً همان‌قدر که صفحه خواسته شارژ کرده رد می‌شود، یا هشدارِ
     * «اعتبار کم است» روی خریدی که موفق خواهد شد روشن می‌مانَد.
     *
     * ⚠️ رشته‌های سه‌زبانه هم عدد را چاپ می‌کنند؛ جای‌نگهدارِ `:hours` از همین
     * ثابت پر می‌شود تا متن هرگز از عدد جدا نیفتد.
     *
     * ⚠️ ترتیبِ عملیات: این کف **پیش** از کسرِ ساعتِ اول سنجیده می‌شود، پس
     * «۲۴ ساعت اعتبار در لحظهٔ خرید» است نه «۲۴ ساعت دوامِ پس از خرید»
     * (خریدِ دقیقاً ۲۴× نرخ، ۲۳ ساعت دوام باقی می‌گذارد). اگر روزی معنیِ دوم
     * خواسته شد، فقط همین عدد ۲۵ می‌شود.
     *
     * 🔴 چرا ۲۴ و نه ۱۲: این عدد باید با `CloudMeterHourly::SUSPEND_GRACE_HOURS`
     * برابر بمانَد. آن مهلت، ساعت‌هایی است که پس از تمام‌شدنِ اعتبار ماشین را
     * تعلیق‌شده نگه می‌داریم و اجاره‌اش را **ما** می‌دهیم. اگر کف از مهلت کمتر
     * شود، هر مشتری می‌تواند کمتر از آنچه رایگان نگه می‌داریم پرداخت کند و
     * تفاوتش از جیبِ ما می‌رود؛ اگر بیشتر شود، مشتری پولی می‌دهد که بابتش
     * سرویسی نمی‌گیرد. برابری، تنها نقطه‌ای است که هیچ‌کدام ضرر نمی‌کنند.
     * **این دو عدد را همیشه با هم عوض کن.**
     */
    public const HOURLY_START_MIN_HOURS = 24;

    /** حداقلِ اعتبارِ لازم برای شروعِ ساعتی = ۲۴ ساعت (تومان). */
    public function hourlyStartMinIrt(): int
    {
        return $this->hourlyIrt() * self::HOURLY_START_MIN_HOURS;
    }

    public function costRose(): bool
    {
        return $this->previous_cost_eur_cents !== null
            && (int) $this->cost_eur_cents > (int) $this->previous_cost_eur_cents;
    }

    /** درصدِ تغییرِ بها — برای پیامِ هشدار */
    public function costChangePct(): int
    {
        $old = (int) $this->previous_cost_eur_cents;

        if ($old <= 0) {
            return 0;
        }

        return (int) round((((int) $this->cost_eur_cents - $old) / $old) * 100);
    }

    /** قیمت در ارزِ زبانِ جاری */
    public function priceLabel(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();

        if ($locale === 'fa') {
            return fa_num(number_format($this->price_irt)).' تومان';
        }

        return '€'.number_format($this->price_eur_cents / 100, 2);
    }
}
