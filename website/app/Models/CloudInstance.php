<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * سرورِ ابریِ واقعیِ یک مشتری — ۱:۱ با `services`.
 *
 * رمزِ root فقط **رمزنگاری‌شده** ذخیره می‌شود و پرچمِ `password_seen` نگه می‌دارد
 * که یک‌بار به مشتری نشان داده شده یا نه؛ بعد از آن در پنل پنهان می‌ماند و مشتری
 * باید «رمزِ تازه بساز» بزند. دلیل: نگه‌داشتنِ رمزِ خواندنی در صفحهٔ همیشه‌باز،
 * با یک نشستِ رهاشده روی لپ‌تاپِ عمومی، سرور را می‌دهد به رهگذر.
 */
class CloudInstance extends Model
{
    protected $fillable = [
        'service_id', 'provider', 'provider_ref', 'location_code', 'image_key',
        'hostname', 'ipv4', 'ipv6', 'root_password_enc', 'password_seen',
        'status', 'last_error', 'specs', 'meta', 'synced_at', 'ready_notified_at',
    ];

    /** نامِ ارائه‌دهنده و رمز هرگز در JSON بیرون نمی‌روند */
    protected $hidden = ['provider', 'provider_ref', 'root_password_enc'];

    protected $casts = [
        'specs'             => 'array',
        'meta'              => 'array',
        'password_seen'     => 'bool',
        'synced_at'         => 'datetime',
        'ready_notified_at' => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    // ───────────────────────── رمز ─────────────────────────

    public function setPassword(?string $plain): void
    {
        $this->root_password_enc = filled($plain) ? Crypt::encryptString($plain) : null;
        $this->password_seen = false;
    }

    /** رمزِ خام؛ null اگر نبود یا APP_KEY عوض شده بود */
    public function password(): ?string
    {
        if (blank($this->root_password_enc)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->root_password_enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasPassword(): bool
    {
        return filled($this->root_password_enc);
    }

    // ───────────────────────── آمادگیِ واقعی ─────────────────────────

    /*
    | ═══ 🔴 چرا «آماده» یک متدِ مدل است و نه یک `if` در ویو ═══
    |
    | کارفرما گزارش داد: «پنلِ ما می‌گوید ساخته شد، ولی زیرساخت می‌گوید
    | activating.» علتِ ریشه‌ای این بود که «آماده» سه جای مختلف سه تعریفِ
    | متفاوت داشت: ویو `status === 'building'` را وارونه می‌کرد، ایمیل هیچ
    | شرطی نداشت، و کرون `running` را کافی می‌دانست.
    |
    | حالا یک تعریف است و هر سه از همین می‌پرسند. نکتهٔ ظریفش: پیش‌فرضِ
    | «نمی‌دانم» باید **ناآماده** باشد. نگاشتِ وضعیتِ زیرساخت هر رشتهٔ
    | ناشناخته را `unknown` می‌کند، و `unknown` هرگز نباید آماده شمرده شود —
    | وگرنه اضافه‌شدنِ یک رشتهٔ تازه از سمتِ آنها، بی‌هیچ خطایی همان باگ را
    | برمی‌گرداند.
    */

    /** رشته‌هایی که زیرساخت با آنها می‌گوید «این ماشین واقعاً بالا است» */
    public const READY_STATUSES = ['running'];

    /** وضعیت‌هایی که یعنی ماشین وجود دارد و قابلِ استفاده است (روشن یا خاموش) */
    public const LIVE_STATUSES = ['running', 'off'];

    /**
     * آیا ایمیل/پیامِ «سرورت آماده شد» مجاز است؟
     *
     * ⚠️ دو شرط، و **هر دو** لازم است:
     *   • زیرساخت بگوید بالا است (`active` → `running`)
     *   • IPv4 داشته باشیم
     *
     * شرطِ دوم عمداً مستقل است، چون کلِ خرابیِ گزارش‌شده همین بود: ایمیلی که
     * `IP: —` داشت. اگر ماشین `active` شد ولی IP نرسیده، یک دقیقهٔ دیگر صبر
     * می‌کنیم — یک دقیقه تأخیر، در برابرِ ایمیلی که مشتری با آن هیچ کاری
     * نمی‌تواند بکند.
     */
    public function readyForNotice(): bool
    {
        return in_array($this->status, self::READY_STATUSES, true) && filled($this->ipv4);
    }

    /**
     * آیا صفحهٔ پنل باید بخشِ «دسترسی و کنترل» را نشان دهد؟
     *
     * ⚠️ چرا `LIVE_STATUSES` و نه `READY_STATUSES`: سرورِ **خاموشِ** مشتری هم
     * تحویل‌شده است. اگر این‌جا فقط `running` را بپذیریم، کسی که سرورش را خاموش
     * می‌کند یک‌باره صفحهٔ «در حالِ ساخت» می‌بیند — یک باگِ تازه به‌جای باگِ قبلی.
     */
    public function isDelivered(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true) && filled($this->ipv4);
    }

    /** آیا ایمیلِ تحویل هنوز بدهیِ ما است؟ */
    public function owesReadyNotice(): bool
    {
        return $this->ready_notified_at === null;
    }

    /**
     * مرحلهٔ واقعیِ ساخت — برای نوارِ زندهٔ صفحهٔ مشتری.
     *
     * 🔴 **هیچ درصدِ ساختگی.** همان قاعدهٔ `/status` در این پروژه: عددِ من‌درآوردی
     * از نبودِ عدد بدتر است، چون مشتری‌ای که روی ۷۰٪ گیر کرده نتیجه می‌گیرد سایت
     * خراب است. پس چهار مرحلهٔ **گسسته**، و هر مرحله از یک واقعیتِ قابلِ اثبات
     * می‌آید:
     *
     *   ordered   ردیف ساخته شده ولی سفارش هنوز نزدِ زیرساخت ثبت نشده
     *   building  سفارش پذیرفته شده و ماشین ساخته می‌شود (شاملِ `activating`)
     *   finishing زیرساخت می‌گوید بالا است ولی IP هنوز نرسیده
     *   ready     بالا است و IP دارد
     *
     * ⚠️ مرزِ `ordered`/`building` روی **وجودِ شناسه** است، نه روی حسِ ما.
     * ردیفِ نمونه عمداً **پیش** از تماسِ API ساخته می‌شود (لایهٔ سومِ محافظِ
     * «دو بار نخر»)، پس ردیفِ بی‌شناسه یعنی سفارش هنوز ثبت نشده و گفتنِ
     * «سفارش ثبت شد ✓» در آن لحظه دروغ است. به‌محضِ آمدنِ شناسه — حتی شناسهٔ
     * نیمه‌کارهٔ `order:…` — سفارش واقعاً پذیرفته شده و آن مرحله تیک می‌خورد.
     *
     * @return 'ordered'|'building'|'finishing'|'ready'
     */
    public function stage(): string
    {
        if ($this->isDelivered()) {
            return 'ready';
        }

        if (blank($this->provider_ref)) {
            return 'ordered';
        }

        // بالا آمده ولی IP نرسیده — واقعاً مرحلهٔ آخرِ آماده‌سازی است
        if (in_array($this->status, self::LIVE_STATUSES, true)) {
            return 'finishing';
        }

        // شاملِ سفارشِ نیمه‌کارهٔ `order:…`، وضعیتِ `building` و `unknown`
        return 'building';
    }

    /** شمارهٔ مرحله (۰..۳) — برای «انجام‌شده / درجریان / نرسیده» در ویو */
    public function stageIndex(): int
    {
        return match ($this->stage()) {
            'ordered'   => 0,
            'building'  => 1,
            'finishing' => 2,
            default     => 3,
        };
    }

    // ───────────────────────── نمایش ─────────────────────────

    public function statusLabel(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();

        $map = [
            'fa' => [
                'running' => 'روشن', 'off' => 'خاموش', 'building' => 'در حالِ آماده‌سازی',
                'error' => 'خطا', 'deleted' => 'حذف‌شده', 'unknown' => 'نامشخص',
            ],
            'en' => [
                'running' => 'Running', 'off' => 'Powered off', 'building' => 'Building',
                'error' => 'Error', 'deleted' => 'Deleted', 'unknown' => 'Unknown',
            ],
            'tr' => [
                'running' => 'Çalışıyor', 'off' => 'Kapalı', 'building' => 'Hazırlanıyor',
                'error' => 'Hata', 'deleted' => 'Silindi', 'unknown' => 'Bilinmiyor',
            ],
        ];

        return $map[$locale][$this->status] ?? $map['fa'][$this->status] ?? (string) $this->status;
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'running'  => '#34d399',
            'off'      => '#94a3b8',
            'building' => '#fbbf24',
            'error'    => '#ff6b6b',
            default    => '#64748b',
        };
    }

    /** آیا در حالتی است که عملیاتِ کاربر پذیرفته می‌شود؟ */
    public function isActionable(): bool
    {
        return in_array($this->status, ['running', 'off'], true) && filled($this->provider_ref);
    }

    public function location(): ?CloudLocation
    {
        return $this->location_code
            ? CloudLocation::where('code', $this->location_code)->first()
            : null;
    }

    // ───────────────────────── اکسیتِ کشوری ─────────────────────────

    /*
    | ═══ چرا override در meta و نه در location_code ═══
    |
    | `location_code` مرجعِ **کاتالوگ/قیمت** است (`exit-de` یعنی «محصولِ اکسیتِ
    | آلمان»). ولی کارفرما می‌خواهد کشورِ خروجِ **هر** ماشین را ادهاک از پنل عوض
    | کند — حتی ماشینی که اکسیت نخریده. اگر برای این کار location_code را دست
    | بزنیم، مرجعِ قیمت/محصول را خراب کرده‌ایم. پس یک override سبک در
    | `meta['exit_country']` می‌گذاریم که بر location_code مقدم است و هر دو جهت را
    | می‌پوشاند: هم افزودنِ اکسیت به ماشینِ عادی، هم برداشتنِ اکسیت از ماشینِ اکسیت.
    |
    | مقدارِ ویژهٔ `ir`/`none`/`''` در override یعنی «خروجِ عادیِ ایران» — حتی اگر
    | location_code هنوز `exit-<cc>` باشد. نبودِ کلید یعنی «چیزی override نشده،
    | از location_code بخوان».
    */

    /**
     * کدِ کشورِ خروجِ **مؤثر** (lowercase مثلِ `de`)، یا `null` اگر خروجِ عادیِ
     * ایران است. `meta['exit_country']` بر `location_code` مقدم است.
     */
    public function exitCountryCode(): ?string
    {
        $meta = $this->meta ?? [];

        if (array_key_exists('exit_country', $meta)) {
            $cc = strtolower(trim((string) $meta['exit_country']));

            return ($cc === '' || $cc === 'ir' || $cc === 'none') ? null : $cc;
        }

        if (is_string($this->location_code) && str_starts_with($this->location_code, 'exit-')) {
            $cc = strtolower(substr($this->location_code, 5));

            return ($cc === '' || $cc === 'ir') ? null : $cc;
        }

        return null;
    }

    /** آیا خروجِ این ماشین از کشوری غیرِ ایران است؟ */
    public function hasCountryExit(): bool
    {
        return $this->exitCountryCode() !== null;
    }

    /** آیا کشورِ خروج با override دستی تعیین شده (نه از روی location_code)؟ */
    public function exitCountryIsOverride(): bool
    {
        return array_key_exists('exit_country', $this->meta ?? []);
    }
}
