<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * یک سرویس فروخته‌شده به مشتری (اشتراک یا خرید یک‌بار).
 */
class Service extends Model
{
    protected $fillable = [
        'customer_id', 'name', 'description', 'currency_code', 'price',
        'tax_percent', 'cycle', 'status', 'next_due_at', 'activated_at',
        'cancelled_at', 'created_by',
        // «چرا حذف کردی؟» — کدِ پایدار + متنِ آزاد، هر دو اختیاری
        'terminate_reason', 'terminate_reason_note',
        // تحویل/فراهم‌سازی
        'server_id', 'plan', 'username', 'domain', 'password', 'panel_url',
        'provision_status', 'provision_error', 'provisioned_at', 'provision_meta',
        // سرورِ ابری — به پلن اشاره می‌کند نه به سرور (پیش از خرید وجود ندارد)
        'cloud_plan_id', 'cloud_image_key', 'cloud_ssh_key_id', 'cloud_addons',
        // چرخهٔ تمدید (یادآوری/تعلیق/مهلت)
        'reminder_stage', 'suspended_at', 'grace_alert_at',
        // فروشِ ساعتیِ سرورِ ابری (پیش‌پرداخت از کیفِ پول)
        'billing_mode', 'hourly_rate_irt', 'hourly_rate_eur', 'last_metered_at', 'on_credit_out',
    ];

    protected function casts(): array
    {
        return [
            'price'          => 'integer',
            'tax_percent'    => 'integer',
            'next_due_at'    => 'date',
            'activated_at'   => 'datetime',
            'cancelled_at'   => 'datetime',
            'password'       => 'encrypted',   // رمزِ کنترل‌پنل — هرگز خام
            'provisioned_at' => 'datetime',
            'provision_meta' => 'array',
            'cloud_addons'   => 'array',
            'reminder_stage' => 'integer',
            'suspended_at'   => 'datetime',
            'grace_alert_at' => 'datetime',
            'hourly_rate_irt' => 'integer',
            'hourly_rate_eur' => 'integer',
            'last_metered_at' => 'datetime',
        ];
    }

    // ─────────────── دلیلِ حذفِ سرور (دادهٔ بازاریابی) ───────────────

    /**
     * **کدِ پایدار ⇒ برچسبِ فارسی.** تنها منبعِ حقیقتِ این فهرست.
     *
     * ═══ چرا کد، نه متن ═══
     *
     * چیزی که در دیتابیس می‌نشیند کدِ سمتِ چپ است، و آن هرگز عوض نمی‌شود. متنِ
     * سمتِ راست فقط نمایش است و می‌شود فردا نرم‌ترش کرد بی‌آنکه آمارِ سالِ
     * گذشته بی‌معنی شود. اگر خودِ جملهٔ فارسی ذخیره می‌شد، یک ویرایشِ ساده
     * تاریخچه را به دو دستهٔ جدا می‌شکست.
     *
     * ⚠️ **ترتیب مهم است** — همان ترتیبی که کارفرما داد، و فرم از همین‌جا
     * ساخته می‌شود. `other` عمداً آخر است.
     *
     * ⚠️ پنلِ مشتری سه‌زبانه است، پس **در فرم** برچسب از `ui.svc_del_reason_*`
     * می‌آید نه از این‌جا؛ پنلِ مدیریت فارسیِ تک‌زبانه است و از این‌جا می‌خوانَد.
     * `ServiceDeleteReasonTest` قفل می‌کند که مقدارهای فارسیِ آن کلیدها **دقیقاً**
     * برابرِ این نقشه بمانند — وگرنه دو فهرستِ واگرا می‌شد و گزارشِ مدیر با
     * چیزی که مشتری دیده بود نمی‌خواند.
     *
     * @var array<string,string>
     */
    public const TERMINATE_REASONS = [
        'no_longer_needed'  => 'دیگر به سرور نیاز ندارم',
        'too_expensive'     => 'هزینه سرویس مناسب نبود',
        'technical_issue'   => 'مشکل فنی یا عملکردی داشتم',
        'support'           => 'از پشتیبانی رضایت نداشتم',
        'switched_provider' => 'به سرویس دیگری منتقل شدم',
        'project_stopped'   => 'پروژه متوقف شده',
        'was_a_test'        => 'این سرور تستی بود',
        'security_privacy'  => 'نگرانی امنیتی/حریم خصوصی داشتم',
        'other'             => 'سایر',
    ];

    /** @return list<string> کدهای مجاز، به ترتیبِ نمایش */
    public static function terminateReasonCodes(): array
    {
        return array_keys(self::TERMINATE_REASONS);
    }

    /**
     * برچسبِ فارسیِ یک کد — برای پنلِ مدیریت و لاگِ فعالیت.
     *
     * ⚠️ کدِ ناشناخته **خودش** برگردانده می‌شود، نه رشتهٔ خالی: اگر روزی کدی از
     * فهرست حذف شود، ردیف‌های تاریخیِ آن باید در گزارش دیده شوند نه اینکه بی‌صدا
     * غیب شوند.
     */
    public static function terminateReasonLabel(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return self::TERMINATE_REASONS[$code] ?? $code;
    }

    // ───────────────────────── فروشِ ساعتی ─────────────────────────

    /** آیا این سرویس ساعتی است (متر-محور، نه فاکتور-محور)؟ */
    public function isHourly(): bool
    {
        return $this->billing_mode === 'hourly';
    }

    /**
     * ساعتِ باقی‌مانده بر اساس اعتبارِ فعلیِ مشتری (کفِ عدد).
     * برای نمایشِ «~۴۸ ساعت مانده» در پنل.
     */
    public function hoursLeft(): int
    {
        $rate = (int) $this->hourly_rate_irt;

        if (! $this->isHourly() || $rate <= 0 || $this->customer === null) {
            return 0;
        }

        return intdiv(max(0, $this->customer->creditBalance('IRT')), $rate);
    }

    /**
     * دوره‌های مجاز — از config/billing.php می‌آید تا افزودنِ یک دوره (مثلِ
     * «شش‌ماهه») در یک جا انجام شود، نه پخش در مدل و کنترلر و کرون و Blade.
     * «once» دورهٔ صورت‌حساب نیست (سررسیدِ بعدی ندارد) و همیشه آخر می‌آید.
     *
     * @return list<string>
     */
    public static function cycles(): array
    {
        $fromConfig = array_keys((array) config('billing.cycles', []));

        // اگر config در دسترس نبود، به نقشهٔ پشتیبان برگرد تا اعتبارسنجیِ
        // فرم‌ها و dropdownها خالی نشوند
        return [...($fromConfig ?: array_keys(self::MONTHS_FALLBACK)), 'once'];
    }

    /**
     * پشتیبانِ سخت‌کد برای طولِ دوره‌ها.
     *
     * ⚠️ اگر فقط به config تکیه کنیم، یک غلطِ تایپی یا فایلِ آپلودنشدهٔ config
     * باعث می‌شود monthsIn صفر برگردد، next_due_at نال شود و کرونِ تمدید
     * (که whereNotNull('next_due_at') دارد) آن اشتراک را **برای همیشه** بی‌صدا
     * رها کند. این نقشه آخرین سنگر است.
     */
    public const MONTHS_FALLBACK = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'yearly' => 12];

    /** تعدادِ ماهِ یک دوره؛ 0 برای «یک‌بار» */
    public static function monthsIn(string $cycle): int
    {
        $months = (int) (config('billing.cycles.'.$cycle.'.months') ?? 0);

        return $months > 0 ? $months : (self::MONTHS_FALLBACK[$cycle] ?? 0);
    }

    /** برچسبِ دوره به زبانِ جاری */
    public static function labelFor(string $cycle): string
    {
        $label = config('billing.cycles.'.$cycle.'.label');

        return is_array($label)
            ? ($label[app()->getLocale()] ?? $label['fa'] ?? $cycle)
            : (string) ($label ?? __('ui.cycle_once'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** پلنِ ابریِ سفارش‌شده (اگر این سرویس سرورِ مجازی باشد) */
    public function cloudPlan(): BelongsTo
    {
        return $this->belongsTo(CloudPlan::class, 'cloud_plan_id');
    }

    /** سرورِ ابریِ ساخته‌شده — ۱:۱ */
    public function cloudInstance()
    {
        return $this->hasOne(CloudInstance::class, 'service_id');
    }

    /** آیا این سرویس سرورِ ابری است؟ */
    public function isCloud(): bool
    {
        return filled($this->cloud_plan_id);
    }

    /**
     * سرورِ ابری‌ای که پولش گرفته شده ولی ماشینش هنوز واقعاً تحویل نشده.
     *
     * ═══ 🔴 چرا این متد لازم شد ═══
     *
     * `finalize()` در لحظهٔ **پذیرشِ سفارش** می‌نویسد `status='active'` و
     * `provision_status='done'` — پیش از آنکه IPای وجود داشته باشد. در آن حالت
     * پنلِ مشتری هم‌زمان دو چیز نشان می‌داد: فهرستِ «در حالِ ساخت» و دکمهٔ
     * **قرمزِ حذف**. و آن دکمه بی‌صدا هیچ کاری نمی‌کرد، چون
     * `CloudProvisioner::terminate()` وقتی شناسهٔ ماشین را ندارد `true`
     * برمی‌گرداند و فراخوان «موفق» ثبتش می‌کند. دکمهٔ لغو (تنها راهِ پس‌گرفتنِ
     * پول) هم در همان حالت پنهان بود.
     *
     * ⚠️ تعریفِ «تحویل‌شده» تکرار نمی‌شود؛ از `CloudInstance::isDelivered()`
     * می‌آید — همان یگانه تعریفی که پنل، صفحهٔ سرور و مترِ ساعتی از آن
     * می‌پرسند. تعریفِ دومِ محلی، همان‌جایی است که «پنل می‌گوید آماده، صفحه
     * می‌گوید در حالِ ساخت» از آن درآمد.
     *
     * ⚠️ ویو و کنترلر **هر دو** همین را صدا می‌زنند. اگر یکی شرطِ دست‌نویسِ
     * خودش را بگذارد، دکمه‌ای رندر می‌شود که سرور ردش می‌کند.
     */
    public function cloudUndelivered(): bool
    {
        return $this->isCloud() && ! (bool) $this->cloudInstance?->isDelivered();
    }

    // ─────────────── نوعِ سرویس — پایهٔ «چهار اتاق»ِ پنل ───────────────

    /**
     * وضعیت‌هایی که در فهرستِ «سرویس‌های من» دیده می‌شوند.
     *
     * 🔴 فهرستِ **سفید** است نه سیاه: وضعیتِ تازه‌ای که روزی اضافه شود تا وقتی
     * صریحاً این‌جا نیاید به مشتری نشان داده نمی‌شود. تا امروز همین فهرست
     * به‌صورتِ رشتهٔ خام داخلِ `ServiceController::index()` بود؛ اتاق‌های تازهٔ
     * پنل باید **همان** مجموعه را ببینند، وگرنه دو فهرستِ واگرا می‌شد و یکی‌شان
     * روزی وضعیتی را جا می‌انداخت.
     *
     * @var list<string>
     */
    public const PANEL_STATUSES = ['active', 'suspended', 'awaiting_provision', 'provision_failed'];

    /**
     * این سرویس در کدام «اتاق» پنل می‌نشیند: هاست، سرور، یا خدمات.
     *
     * ⚠️ روی ردیفِ `services` هیچ ستونِ `product_id` یا `kind` نیست، پس فقط دو
     * تشخیص‌دهنده داریم و هر دو این‌جا جمع شده‌اند:
     *
     *  • `cloud_plan_id` — در لحظهٔ **سفارش** نوشته می‌شود (نه تحویل)، پس سرورِ
     *    هنوز-در-حالِ-ساخت هم از همان اول در اتاقِ «سرور» دیده می‌شود.
     *  • `server.type` — هاست‌های کنترل‌پنلی از VPS/اختصاصیِ دستی جدا می‌شوند.
     *
     * 🔴 «خدمات» عمداً سطلِ **پیش‌فرض** است: ردیفی که نوعش تشخیص داده نشود
     * بدجا می‌افتد ولی **ناپدید نمی‌شود**. جمعِ سه اتاق همیشه برابرِ کلِ فهرست
     * است و یک تست همین را قفل می‌کند.
     *
     * @return 'hosting'|'server'|'other'
     */
    public function kind(): string
    {
        if ($this->isCloud()) {
            return 'server';
        }

        if ($this->server_id !== null) {
            return self::kindOfServerType($this->server?->type);
        }

        return 'other';
    }

    /** @return 'hosting'|'server'|'other' */
    public static function kindOfServerType(?string $type): string
    {
        return match ($type) {
            'whm', 'plesk', 'directadmin' => 'hosting',
            'vps', 'dedicated'            => 'server',
            default                       => 'other',
        };
    }

    /**
     * آیا ویجتِ مصرفِ زنده برای این سرویس معنا دارد؟
     *
     * 🔴 دقیقاً همان شرطی که `ServiceController::stats()` دارد
     * (`whm` + `done` + نام‌کاربری). تا امروز ویو فقط `provision_status==='done'`
     * را می‌سنجید، پس یک ردیفِ DirectAdmin کارتی می‌ساخت که **هرگز** پر نمی‌شد و
     * در ضمن یکی از ۶۰ درخواستِ سقف‌دارِ دقیقه را می‌سوزاند.
     */
    public function hasLiveUsage(): bool
    {
        return $this->provision_status === 'done'
            && $this->server?->type === 'whm'
            && filled($this->username);
    }

    /** سرویسی که باید روی سروری تحویل شود (نه یک خدمتِ صرفاً مالی مثل پشتیبانی) */
    public function needsProvisioning(): bool
    {
        return $this->server_id !== null && $this->provision_status !== 'done';
    }

    /** برچسبِ وضعیتِ تحویل برای نمایش @return array{0:string,1:string} */
    public function provisionBadge(): array
    {
        return match ($this->provision_status) {
            'done'    => ['تحویل شد', '#34d399'],
            'running' => ['در حال ساخت', '#22d3ee'],
            'pending' => ['در صف تحویل', '#fbbf24'],
            'manual'  => ['در انتظار تحویل دستی', '#fbbf24'],
            'failed'  => ['خطا در تحویل', '#ff6b6b'],
            // بسته شده، ولی زیرساخت هنوز حذف را تأیید نکرده — هزینه‌اش پای ماست
            self::PROVISION_RELEASING => ['در حال آزادسازی', '#f59e0b'],
            default   => ['—', '#96a3ba'],
        };
    }

    /** مالیات این دوره بر حسب واحد فرعی */
    public function taxAmount(): int
    {
        return (int) round($this->price * $this->tax_percent / 100);
    }

    /** مبلغ کل هر دوره (خدمت + مالیات) */
    public function total(): int
    {
        return $this->price + $this->taxAmount();
    }

    /**
     * آیا این سرویس قابلِ حذفِ کامل است؟
     *
     * کارفرما: «سرویس و سرور و هاستِ لغوشده‌ای که کلاً ایجاد نشده‌اند قابلیتِ
     * حذف داشته باشند، عینِ فاکتور.»
     *
     * سه شرط، و هر سه لازم‌اند:
     *
     * ۱) **مرده باشد** — سرویسِ زنده حذف نمی‌شود، خاتمه داده می‌شود. حذفِ
     *    سرویسِ فعال یعنی ماشینی که کسی دیگر پیگیرش نیست و اجاره‌اش پای ماست.
     *
     * ۲) **هرگز تحویل نشده باشد** — اگر روزی ماشینی ساخته شده، ردیفش سندِ
     *    وجودِ آن است. حذفش یعنی سرورِ یتیم بدونِ هیچ ردی؛ دقیقاً همان چیزی
     *    که `CloudInventory` برای پیدا کردنش نوشته شد.
     *
     * ۳) **هیچ پرداختی رویش ننشسته باشد** — همان قاعدهٔ `Invoice::isDeletable()`.
     *    سابقهٔ مالی و مالیاتی هرگز پاک نمی‌شود. فاکتورِ پرداخت‌نشده اشکالی
     *    ندارد؛ فاکتورِ پرداخت‌شده یعنی این ردیف بخشی از دفتر است.
     *
     * ⚠️ عمداً محافظه‌کار: هر تردیدی ⇒ `false`. حذف بازگشت‌ناپذیر است و
     * اشتباهش از یک ردیفِ اضافه در فهرست خیلی گران‌تر است.
     */
    public function isDeletable(): bool
    {
        if (! in_array($this->status, self::DEAD_STATUSES, true)) {
            return false;
        }

        // تحویل شده یا در حالِ تحویل است ⇒ ردیفش سندِ وجودِ یک ماشین است
        if (in_array((string) $this->provision_status, ['done', 'running', 'releasing'], true)) {
            return false;
        }

        /*
         * ⚠️ پرس‌وجوی مستقیم و نه یک رابطهٔ تازه: `cloud_instances` روی نصبِ
         * مهاجرت‌نخورده ممکن است اصلاً وجود نداشته باشد، و یک رابطهٔ نبود،
         * این صفحه را ۵۰۰ می‌کرد. `hasTable` جلویش را می‌گیرد.
         */
        if (\Illuminate\Support\Facades\Schema::hasTable('cloud_instances')
            && \App\Models\CloudInstance::where('service_id', $this->id)->exists()) {
            return false;
        }

        return ! $this->invoices()->where('paid', '>', 0)->exists();
    }

    public function isRecurring(): bool
    {
        return $this->cycle !== 'once';
    }

    /**
     * سررسید بعدی از یک مبدأ، بر اساس دوره.
     * برای «یک‌بار» سررسیدی نیست (null).
     */
    public function nextDueFrom(Carbon $from): ?Carbon
    {
        $months = self::monthsIn((string) $this->cycle);

        // NoOverflow لازم است: ۳۱ فروردین + ۱ ماه نباید به دو ماه بعد بپرد.
        return $months > 0 ? $from->copy()->addMonthsNoOverflow($months) : null;
    }

    public function cycleLabel(): string
    {
        return self::labelFor((string) $this->cycle);
    }

    /** @return array{0:string,1:string} برچسب و رنگ */
    /**
     * وضعیت‌هایی که یعنی «این سرویس مرده است؛ زنده‌اش نکن».
     *
     * 🔴 چرا ثابت و نه رشتهٔ `'cancelled'`ِ پراکنده: دو وضعیتِ پایانی داریم —
     * `cancelled` (مدیر بست) و `terminated` (مشتری خودش با کدِ یک‌بارمصرف حذف
     * کرد). هر جای کد که فقط `cancelled` را می‌سنجید، `terminated` بی‌صدا از
     * کنارش رد می‌شد. بدترین نمونه‌اش `PaymentService::applyPaid` بود: فاکتورِ
     * تمدیدِ بازِ یک سرویسِ حذف‌شده هنوز قابلِ پرداخت است، و پرداختش سرویسی را
     * «دوباره فعال» می‌کرد که سرورش واقعاً نزدِ زیرساخت پاک شده بود — مشتری پول
     * می‌داد و چیزی تحویل نمی‌گرفت.
     *
     * @var array<int,string>
     */
    public const DEAD_STATUSES = ['cancelled', 'terminated'];

    /** «هیچ صفی این را نمی‌خواهد» — پرونده نزدِ زیرساخت بسته است. */
    public const PROVISION_NONE = 'none';

    /**
     * 🔴 «مشتری تمام شده، ماشین هنوز تأییدنشده پاک نشده.»
     *
     * تنها مقدارِ تازه‌ای که این تغییر اضافه کرد. معنایش دقیقاً یک چیز است:
     * صورت‌حسابِ مشتری در همان لحظهٔ درخواستِ حذف بسته شد (پس دیگر شارژ نمی‌شود)،
     * ولی زیرساخت حذف را تأیید نکرده — هزینه‌اش تا تأیید **پای ماست** و باید
     * بلند و پیگیری‌شدنی باشد، نه یک ستونِ `last_error` که کسی نمی‌خوانَد.
     *
     * ⚠️ **عمداً روی `provision_status` است، نه `services.status`.** هر درِ
     * تحویلِ دوباره — `provision:run`، `ProvisioningService::provision()`،
     * `CloudProvisioner::provision()`، دکمهٔ «تلاشِ دوباره»ی مدیر و مهم‌تر از همه
     * `PaymentService::applyPaid` — روی `status` قفل می‌شود؛ پس وضعیتِ مرده باید
     * فوراً نوشته و دست‌نخورده بمانَد. یک «حالتِ میانی» روی `status` همهٔ آن درها
     * را دوباره باز می‌کرد و نتیجه‌اش خریدِ **سرورِ دوم** بود.
     *
     * ⚠️ ستون `string(16)` است و این مقدار ۹ نویسه — بی‌نیاز از مهاجرت. (درسِ
     * `awaiting_provision` با ۱۸ نویسه روی ستونِ ۱۲تایی: «Data too long» کلِ
     * تراکنشِ پرداخت را برگرداند.)
     */
    public const PROVISION_RELEASING = 'releasing';

    /** ردیف‌هایی که حذفشان نزدِ زیرساخت تأیید نشده و باید دوباره تلاش شود. */
    public function scopeAwaitingRelease($q)
    {
        return $q->whereIn('status', self::DEAD_STATUSES)
            ->where('provision_status', self::PROVISION_RELEASING);
    }

    public function isDead(): bool
    {
        return in_array((string) $this->status, self::DEAD_STATUSES, true);
    }

    public function statusBadge(): array
    {
        return match ($this->status) {
            'active'             => ['فعال', '#34d399'],
            'pending'            => ['منتظر پرداخت', '#fbbf24'],
            'awaiting_provision' => ['در حال آماده‌سازی', '#22d3ee'],
            'provision_failed'   => ['خطا در تحویل', '#ff6b6b'],
            'suspended'          => ['معلق', '#ff6b6b'],
            'cancelled'          => ['لغو شده', '#5f6c82'],
            'terminated'         => ['حذف شده', '#5f6c82'],
            'expired'            => ['منقضی', '#96a3ba'],
            default              => [$this->status, '#96a3ba'],
        };
    }
}
