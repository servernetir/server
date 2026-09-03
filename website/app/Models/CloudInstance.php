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

    // ─────────────── آدرسی که به مشتری نشان می‌دهیم ───────────────

    /**
     * 🔴 `ipv4` همیشه «آدرسی که مشتری می‌تواند استفاده کند» نیست.
     *
     * هتزنر/آیزا آدرسِ عمومی می‌دهند، ولی ماشینِ Proxmox پشتِ NAT است و
     * `ipv4`ش خصوصی است (`10.10.10.x`). دسترسیِ عمومی‌اش از یک پورت‌فوروارد
     * روی IP مشترک می‌آید که `PullController::portForwards` تخصیص می‌دهد و در
     * `meta.public_port` می‌نشیند.
     *
     * ⚠️ تشخیص عمداً روی **خودِ آدرس** است نه روی نامِ زیرساخت: شرطِ واقعی
     * «این IP از اینترنت قابلِ استفاده هست یا نه» است، و هر زیرساختِ بعدی که
     * پشتِ NAT بیاید بی‌هیچ تغییری درست کار می‌کند.
     */
    public function hasPrivateIp(): bool
    {
        $ip = (string) $this->ipv4;

        if ($ip === '') {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** پورتِ عمومیِ تخصیص‌یافته (۰ یعنی هنوز ساخته نشده). */
    public function publicPort(): int
    {
        return (int) (($this->meta ?? [])['public_port'] ?? 0);
    }

    /**
     * میزبانِ عمومی: ترجیحاً نامِ دامنه (`public_host`)، وگرنه IP عمومی.
     *
     * ⚠️ نام فقط ظاهر را بهتر می‌کند؛ پورت همچنان لازم است. تنها چیزی که
     * پورت را حذف می‌کند یک IPv4 اختصاصی برای همان ماشین است.
     */
    public static function publicHost(): string
    {
        return trim((string) (Setting::get('public_host')
            ?: Setting::get('public_ip')
            ?: config('servernet.exit.public_ip', '')));
    }

    /**
     * 🔴 آدرسی که واقعاً به مشتری داده می‌شود — یا `null` اگر هنوز آدرسِ
     * قابلِ‌استفاده‌ای وجود ندارد.
     *
     * ⚠️ `null` عمدی است و مهم‌ترین بخشِ این متد: ماشینِ پشتِ NAT که هنوز
     * پورت‌فورواردش ساخته نشده **هیچ آدرسِ قابلِ استفاده‌ای ندارد**. پیش از
     * این، پرتال و ایمیلِ تحویل همان `10.10.10.x` را چاپ می‌کردند و مشتری
     * تیکت می‌زد «آی‌پی خصوصی است» — یعنی سیستم چیزی را وعده می‌داد که
     * وجود نداشت. نشان‌ندادن، از نشان‌دادنِ آدرسِ غلط بهتر است.
     *
     * @return array{host:string, port:int}|null
     */
    public function endpoint(): ?array
    {
        if (! $this->hasPrivateIp()) {
            return filled($this->ipv4) ? ['host' => (string) $this->ipv4, 'port' => 22] : null;
        }

        $host = self::publicHost();
        $port = $this->publicPort();

        return ($host !== '' && $port > 0) ? ['host' => $host, 'port' => $port] : null;
    }

    /** «۸۵٫۹٫۱۰۸٫۱۱۸:۲۰۰۰۱» یا صرفاً IP — برای نمایش و ایمیل. */
    public function address(): ?string
    {
        $e = $this->endpoint();

        if ($e === null) {
            return null;
        }

        return $e['port'] === 22 ? $e['host'] : $e['host'].':'.$e['port'];
    }

    /** دستورِ آمادهٔ اتصال، با `-p` فقط وقتی پورت غیرِ استاندارد است. */
    public function sshCommand(): ?string
    {
        $e = $this->endpoint();

        if ($e === null) {
            return null;
        }

        // ⚠️ رشته در PHP ساخته می‌شود نه در Blade: «root» چسبیده به آکولاد با
        //    یک @ برای Blade دستورِ فرار است و به‌جای آدرس، خودِ عبارت چاپ می‌شود.
        $cmd = 'ssh root'.'@'.$e['host'];

        return $e['port'] === 22 ? $cmd : $cmd.' -p '.$e['port'];
    }

    /**
     * نشانیِ وبِ سرور — فقط برای ماشینِ پشتِ NAT که دروازهٔ پروکسی گرفته.
     *
     * ⚠️ با `address()` یکی نیست: آن نشانیِ **SSH** است (IP:پورت) و این
     * نشانیِ **سایت**. مشتری هر دو را لازم دارد و یکی‌گرفتنشان همان
     * سردرگمی‌ای است که تیکتِ «پورت ۸۰» از آن آمد.
     */
    public function webUrl(): ?string
    {
        $host = (($this->meta ?? [])['public_domain'] ?? null);

        return filled($host) ? 'https://'.$host : null;
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
    /**
     * نشانی‌ای که مشتری واقعاً با آن کار می‌کند (خطِ GPU).
     *
     * 🔴 سفیدبرچسبی: نامِ زیرساخت نباید به مشتری برسد، و نشانیِ خامِ دروازه
     * دقیقاً همان نام را دارد. اگر مدیر «دامنهٔ برندشده» را در تنظیمات پر
     * کرده باشد (salad_branded_domain — یک Worker روی Cloudflare همان نگاشت
     * را برمی‌گرداند)، نشانی به g-{label}.{دامنهٔ ما} ترجمه می‌شود؛ وگرنه
     * همان نشانیِ کارا برمی‌گردد — نشانیِ کارایی که برند را لو می‌دهد بهتر
     * از نشانیِ برندی است که کار نمی‌کند.
     *
     * hostnameِ جای‌نگهدار (sn-svc-N، بی‌نقطه) null می‌دهد: هنوز نشانی نداریم.
     */
    public function accessHost(): ?string
    {
        $h = strtolower(trim((string) $this->hostname));

        if ($h === '' || ! str_contains($h, '.')) {
            return null;
        }

        if (! str_ends_with($h, '.salad.cloud')) {
            return $h;
        }

        $base = trim((string) \App\Models\Setting::get('salad_branded_domain', ''), " .	");

        if ($base === '') {
            return $h;
        }

        return 'g-'.substr($h, 0, -strlen('.salad.cloud')).'.'.$base;
    }

    /**
     * توکنِ دسترسیِ دروازهٔ برندشده — HMAC(برچسبِ ماشین، رازِ مشترک با Worker).
     *
     * چرا: هر سه برنامهٔ آماده (Ollama/ComfyUI) خودشان احراز ندارند؛ هر کسی
     * نشانیِ g-… را بداند می‌تواند GPUِ مشتری را مصرف کند و ساعت‌هایش را
     * بسوزاند (حکمِ شورای مدیران: مسدودکنندهٔ انتشار). قطعی و بی‌ستون است —
     * همان راز در Worker (env: GATE_SECRET) نشسته و همین HMAC را می‌سازد؛
     * اگر یکی عوض شد، دیگری هم باید عوض شود (مثلِ accessHost).
     *
     * رازِ خالی ⇒ null ⇒ پنل توکن نشان نمی‌دهد و Worker هم دروازه را باز
     * می‌گذارد — استقرارِ تدریجیِ بی‌شکست.
     */
    public function accessToken(): ?string
    {
        $h = strtolower(trim((string) $this->hostname));

        if (! str_ends_with($h, '.salad.cloud')) {
            return null;
        }

        $secret = (string) \App\Models\Setting::getSecret('salad_gateway_secret');

        if ($secret === '') {
            return null;
        }

        return hash_hmac('sha256', substr($h, 0, -strlen('.salad.cloud')), $secret);
    }

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
