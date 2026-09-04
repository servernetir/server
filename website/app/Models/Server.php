<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * یک سرورِ تحویل — WHM/cPanel، Plesk، DirectAdmin یا زیرساختِ VPS/اختصاصی.
 *
 * توکن/رمزِ API با cast=encrypted ذخیره می‌شود و هرگز خام روی صفحه نمی‌آید.
 */
class Server extends Model
{
    protected $fillable = [
        'name', 'type', 'country', 'city', 'hostname', 'port', 'username', 'api_token', 'verify_tls',
        'server_ip', 'nameservers', 'status', 'max_accounts', 'active_accounts', 'note', 'meta',
        'monthly_cost', 'cost_currency', 'billing_day', 'vendor',
    ];

    /**
     * 🔴 بهایِ تمام‌شده و نامِ تأمین‌کننده از هر JSON بیرون می‌مانند.
     *
     * همان قاعدهٔ `CloudPlan::$hidden`: سفیدبرچسبیِ این پروژه به آن بند است و
     * یک `toJson()` در جای اشتباه، هم قیمتِ خریدِ ما را لو می‌دهد هم اینکه
     * سرور را از کجا اجاره می‌کنیم.
     */
    protected $hidden = ['api_token', 'monthly_cost', 'vendor'];

    protected function casts(): array
    {
        return [
            'api_token'       => 'encrypted',   // هرگز خام ذخیره نمی‌شود
            'verify_tls'      => 'boolean',
            'port'            => 'integer',
            'max_accounts'    => 'integer',
            'active_accounts' => 'integer',
            'billing_day'     => 'integer',
            // ⚠️ کست به integer مقدارِ null را null نگه می‌دارد؛ همان تمایزِ
            //    «نمی‌دانم» از «رایگان» که مهاجرت رویش تأکید دارد.
            'monthly_cost'    => 'integer',
            'meta'            => 'array',
        ];
    }

    /**
     * نوع‌هایی که تحویلِ خودکار دارند (درایورِ API) در مقابلِ دستی.
     *
     * ⚠️ Plesk عمداً این‌جا **نیست**: درایورش نوشته شده ولی روی هیچ سرورِ
     * Pleskِ واقعی آزمایش نشده. روشن‌کردنش با `provisioning.plesk_auto` است
     * (پایین) — یک تصمیمِ آگاهانه پس از یک خریدِ آزمایشیِ موفق، نه پیش‌فرض.
     * تا آن لحظه سفارشِ Plesk در صفِ دستیِ مدیر می‌نشیند: کندتر، ولی هرگز
     * «تحویل شد»ِ دروغین نمی‌دهد.
     */
    public const AUTO_TYPES = ['whm', 'directadmin', 'hetzner_storage'];

    public const TYPES = ['whm', 'plesk', 'directadmin', 'hetzner_storage', 'vps', 'dedicated', 'generic'];

    /** پورتِ پیش‌فرضِ هر نوع کنترل‌پنل */
    public const DEFAULT_PORTS = [
        'whm' => 2087, 'plesk' => 8443, 'directadmin' => 2222,
    ];

    /**
     * اجارهٔ ماهانه به **تومان**، یا `null` اگر نمی‌دانیم.
     *
     * ⚠️ `null` و `0` عمداً یکی نمی‌شوند: صفر یعنی «رایگان است» و null یعنی
     * «هنوز پر نشده». اگر یکی شوند، هر سرورِ پرنشده به‌عنوان رایگان وارد جمع
     * می‌شود و هزینهٔ کل کم‌تر از واقع می‌آید — دقیقاً همان خوش‌بینی‌ای که این
     * ستون‌ها برای رفعش ساخته شدند.
     *
     * ⚠️ تبدیلِ یورو با نرخِ **امروز** است. هیچ نرخِ تاریخی‌ای در این پروژه
     * ذخیره نمی‌شود، پس عددِ ماهِ گذشته هم با نرخِ امروز حساب می‌شود.
     * فراخوان باید این را به کاربر بگوید.
     */
    public function monthlyCostToman(?int $rate = null): ?int
    {
        if ($this->monthly_cost === null) {
            return null;
        }

        $amount   = (int) $this->monthly_cost;
        $currency = strtoupper((string) $this->cost_currency ?: 'EUR');

        // تومان exponent صفر دارد: خودِ عدد، بی‌تقسیم بر ۱۰۰
        if ($currency === 'IRT') {
            return $amount;
        }

        /*
        | 🔴 نرخ باید نرخِ **همان ارز** باشد.
        |
        | نسخهٔ اول برای هر ارزی `eurToToman()` را صدا می‌زد، پس یک سرورِ
        | دلاری با نرخِ یورو تبدیل می‌شد — حدود ۸ درصد خطا روی هر ماه، بی‌هیچ
        | نشانه‌ای. و `pricing_rate_override` که مدیر برای **یورو** می‌گذارد
        | نباید روی مبلغِ دلاری بنشیند.
        */
        /*
        | ⚠️ `$rate` از بیرون داده می‌شود تا یک صفحه که ده سرور دارد، ده بار
        | نرخ نگیرد. `ExchangeRate::refresh()` روی شکست **هیچ‌چیز کش نمی‌کند**،
        | پس با منبعِ ارزِ خاموش هر فراخوان یک HTTPِ مسدودکنندهٔ ~۲۵ ثانیه‌ای
        | است — ده سرور یعنی چند دقیقه داخلِ یک درخواست.
        |
        | بدتر از کندی: دو پاسِ متفاوت در همان صفحه می‌توانستند دو نرخِ متفاوت
        | بگیرند و جمعِ نمایش‌داده‌شده با هشدارِ پایینِ صفحه نخوانَد.
        */
        if ($rate === null) {
            try {
                $rate = $currency === 'EUR'
                    ? (int) app(\App\Services\Cloud\CloudPricing::class)->eurToToman()
                    : (int) (app(\App\Services\ExchangeRate::class)->toToman($currency) ?: 0);
            } catch (\Throwable) {
                $rate = 0;
            }
        }

        // نرخ نبود ⇒ null، نه صفر: «نتوانستم تبدیل کنم» با «رایگان» یکی نیست
        return $rate > 0 ? (int) round($amount / 100 * $rate) : null;
    }

    /** آیا بهایِ این سرور وارد شده؟ */
    public function hasCost(): bool
    {
        return $this->monthly_cost !== null;
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** آیا این نوع سرور تحویلِ خودکار دارد؟ */
    public function isAutoProvisioned(): bool
    {
        if ($this->type === 'plesk') {
            return (bool) config('provisioning.plesk_auto', false);
        }

        return in_array($this->type, self::AUTO_TYPES, true);
    }

    public function effectivePort(): int
    {
        return $this->port ?: (self::DEFAULT_PORTS[$this->type] ?? 443);
    }

    /** ظرفیت پر شده؟ */
    public function isFull(): bool
    {
        return $this->status === 'full'
            || ($this->max_accounts !== null && $this->active_accounts >= $this->max_accounts);
    }

    public function canAcceptNew(): bool
    {
        return $this->status === 'active' && ! $this->isFull();
    }

    /** @return array{0:string,1:string} برچسب و رنگ */
    public function statusBadge(): array
    {
        return match ($this->status) {
            'active'      => ['فعال', '#34d399'],
            'maintenance' => ['تعمیر', '#fbbf24'],
            'full'        => ['پر', '#ff6b6b'],
            default       => [$this->status, '#96a3ba'],
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'whm'         => 'WHM / cPanel',
            'plesk'       => 'Plesk',
            'directadmin' => 'DirectAdmin',
            'hetzner_storage' => 'فضای بکاپ (Hetzner Storage Box)',
            'vps'         => 'VPS',
            'dedicated'   => 'سرور اختصاصی',
            default       => 'عمومی',
        };
    }

    // ─────────────────────────── مکان (ایران/آلمان) ───────────────────────────

    /** برچسبِ کشور به زبانِ جاری؛ اگر کشور ست نشده باشد خالی */
    public function locationLabel(): string
    {
        if (blank($this->country)) {
            return '';
        }

        $loc = config('billing.locations.'.strtoupper((string) $this->country));
        $label = is_array($loc['label'] ?? null)
            ? ($loc['label'][app()->getLocale()] ?? $loc['label']['fa'] ?? $this->country)
            : (string) $this->country;

        return trim(($loc['flag'] ?? '').' '.$label.($this->city ? ' — '.$this->city : ''));
    }

    /**
     * کشورهایی که همین حالا می‌توانند سرویسِ تازه بپذیرند.
     *
     * فقط سرورهای فعالِ غیرِپر و دارای تحویلِ خودکار حساب می‌شوند — اگر مکانی
     * سرورِ آماده ندارد، نباید در صفحهٔ خرید نمایش داده شود، وگرنه مشتری پول
     * می‌دهد و سرویسش روی هوا می‌ماند.
     *
     * @return list<string>
     */
    public static function availableCountries(): array
    {
        return static::query()
            // فعلاً فقط WHM: پکیج‌های کاتالوگ (sn_<slug>) روی WHM ساخته می‌شوند،
            // پس تبلیغِ مکانی که فقط سرورِ DirectAdmin دارد به شکستِ تحویل می‌رسد.
            ->where('type', 'whm')
            ->where('status', 'active')
            ->whereNotNull('country')
            ->get()
            ->filter(fn (self $s) => $s->canAcceptNew())
            ->map(fn (self $s) => strtoupper((string) $s->country))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * کم‌بارترین سرورِ آمادهٔ یک کشور — مقصدِ تحویلِ این خرید.
     *
     * lockForUpdate عمداً نیست: انتخاب فقط یک پیشنهاد است و خودِ ProvisioningService
     * پیش از ساختِ حساب دوباره وضعیت را می‌سنجد؛ قفلِ بلندمدت روی جدولِ سرورها
     * زیرِ بارِ همزمان بیشتر ضرر داشت.
     */
    public static function pickForCountry(string $country): ?self
    {
        return static::query()
            ->where('type', 'whm')                  // همان قیدِ availableCountries()
            ->where('status', 'active')
            ->whereRaw('UPPER(country) = ?', [strtoupper($country)])
            ->orderBy('active_accounts')
            ->get()
            ->first(fn (self $s) => $s->canAcceptNew());
    }
}
