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
    ];

    /** حفاظِ سفیدبرچسبی: این ستون‌ها در هیچ JSONای بیرون نمی‌روند */
    protected $hidden = ['provider', 'provider_ref', 'provider_location', 'cost_eur_cents'];

    protected $casts = [
        'is_active'  => 'bool',
        'in_stock'   => 'bool',
        'synced_at'  => 'datetime',
    ];

    public function location()
    {
        return $this->belongsTo(CloudLocation::class, 'location_code', 'code');
    }

    // ───────────────────────── انتخابِ عرضه ─────────────────────────

    /** پلن‌های قابلِ فروش */
    public function scopeSellable(Builder $q): Builder
    {
        return $q->where('is_active', true)->where('in_stock', true)->where('price_irt', '>', 0);
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
