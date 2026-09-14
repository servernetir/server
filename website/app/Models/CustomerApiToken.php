<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * توکنِ APIِ مشتری. فقط هش ذخیره می‌شود؛ متنِ خام یک‌بار برگردانده می‌شود.
 *
 * ═══ چرا این کلاس از یک «دارندهٔ هش» به یک محافظ تبدیل شد ═══
 *
 * تا وقتی توکن فقط‌خواندنی بود، لو رفتنش یعنی کسی فهرستِ فاکتورها را دید.
 * با APIِ نمایندگی، همین رشته **پول خرج می‌کند** و روی سرورِ WHMCSِ نماینده
 * می‌نشیند — سروری که ما نه وصله‌اش می‌کنیم، نه لاگش را می‌بینیم، نه از
 * نفوذش خبردار می‌شویم. پس فرضِ پایه همان فرضِ وب‌هوکِ بله است:
 * **توکن روزی لو می‌رود**، و طراحی باید بگوید آن روز چقدر خسارت ممکن است.
 */
class CustomerApiToken extends Model
{
    /** دسترسی‌های شناخته‌شده — رابطِ صدور و مستنداتِ API از همین می‌خوانند. */
    public const ABILITIES = [
        'read'           => 'خواندنِ حساب، سرویس‌ها، فاکتورها و اعتبار',
        'domains:read'   => 'خواندنِ دامنه‌ها، استعلامِ قیمت و موجودی',
        'domains:write'  => 'ثبت و تمدیدِ دامنه — **از اعتبارِ حساب خرج می‌کند**',
        'domains:manage' => 'تغییرِ نام‌سرور و تمدیدِ خودکارِ دامنه‌های موجود',
        'tunnel:read'    => 'خواندنِ اکانت‌های تونلِ WireGuard-روی-TCP سرورِ اکسیت',
        'tunnel:write'   => 'ساخت و حذفِ اکانتِ تونل — کلیدِ خصوصی فقط یک بار برمی‌گردد',
    ];

    /**
     * حداکثر توکنِ فعالِ هم‌زمان برای هر حساب.
     *
     * ⚠️ عدد عمداً این‌جاست و نه در کنترلر: مستنداتِ `/developers` همین را چاپ
     * می‌کند. سقفی که در دو جا نوشته شود، روزی که یکی‌اش عوض شود مستندات را
     * دروغ‌گو می‌کند — و نماینده‌ای که بر اساسش کد نوشته، خرابی‌اش را وقتی
     * می‌بیند که صدورِ توکنِ سرورِ تازه‌اش رد می‌شود.
     */
    public const MAX_ACTIVE = 20;

    /**
     * 🔴 پیشوندِ دامنهٔ AI — یک مرزِ قراردادی، نه یک سیستمِ موازی.
     *
     * هر abilityای با این پیشوند، از مسیرِ متفاوتی می‌گذرد (نگاهِ زیر به
     * `can()`): توکنِ «*» هرگز AI نمی‌خرد — دسترسیِ AI فقط با تیکِ صریح و
     * **پروژهٔ bound** می‌آید. دلیلش مبهم نیست: توکن‌های قدیمی با «*» زنده‌اند
     * و مهاجرتِ M2 حق ندارد ناگهان به‌شان دَرِ AI باز کند.
     */
    public const AI_PREFIX = 'ai:';

    /**
     * نردبانِ دامنه‌های AI — با خانوادهٔ یونیت‌های مصرفِ M1 هم‌خانواده است و
     * آیندهٔ `/v1` روی همین فهرست ساخته می‌شود، بی‌مهاجرتِ churn:
     *
     *   ai:models:read  فهرستِ مدل‌های قابلِ فُروش (رجیستریِ M1)
     *   ai:chat         تکمیلِ چت
     *   ai:messages     گفت‌وگوی چندنوبته
     *   ai:responses    پاسخ‌های stateدار
     *   ai:embeddings   وکتوری‌سازی
     *   ai:images       تولِد تصویر
     *   ai:audio        صدا (تبدیلِ نوشتار و برعکس)
     *
     * 🔴 هیچ «همهٔ AI» در فهرست نیست: هر ability پول خرج می‌کند (به‌جز
     *    خواندنِ مدل‌ها) و باید در رابطِ صدور **انتخاب** شود، نه پیش‌فرض.
     */
    public const AI_ABILITIES = [
        'ai:models:read' => 'خواندنِ فهرستِ مدل‌های قابلِ خریدِ دروازهٔ AI',
        'ai:chat'        => 'تکمیلِ چت — پول خرج می‌کند (سرِ هر توکن)',
        'ai:messages'    => 'گفت‌وگوی چندنوبته — پول خرج می‌کند',
        'ai:responses'   => 'پاسخ‌های stateدار — پول خرج می‌کند',
        'ai:embeddings'  => 'وکتوری‌سازی — پول خرج می‌کند',
        'ai:images'      => 'تولِد تصویر — پول خرج می‌کند',
        'ai:audio'       => 'صدا (تبدیلِ نوشتار و برعکس) — پول خرج می‌کند',
    ];

    protected $fillable = [
        'customer_id', 'name', 'token_hash', 'abilities', 'allowed_cidrs',
        'expires_at', 'revoked_at', 'daily_spend_cap_irt', 'ai_project_id',
        'last_used_at', 'last_used_ip',
    ];

    /**
     * ⚠️ هرگز در JSON نرود. `token_hash` قابلِ برگرداندن نیست ولی برای حملهٔ
     * دیکشنری روی توکنِ کوتاه ارزش دارد، و ما هیچ دلیلی برای نشان‌دادنش نداریم.
     */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities'     => 'array',
            'allowed_cidrs' => 'array',
            'last_used_at'  => 'datetime',
            'expires_at'    => 'datetime',
            'revoked_at'    => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ───────────────────────── اعتبار ─────────────────────────

    /**
     * چرا این توکن قابلِ استفاده **نیست** — یا `null` اگر سالم است.
     *
     * 🔴 عمداً یک **علت** برمی‌گرداند و نه یک بولین: میدل‌ور باید بتواند به
     * تماس‌گیرنده بگوید «منقضی شده» در برابر «باطل شده» در برابر «IP مجاز
     * نیست». نمایندهٔ ما یک برنامه است، نه انسان؛ پیامِ «invalid_token» برای
     * توکنی که فقط تاریخش گذشته یعنی ساعت‌ها گشتنِ بی‌هدف دنبالِ یک اشتباهِ
     * تایپی که وجود ندارد.
     */
    public function unusableReason(): ?string
    {
        if ($this->revoked_at !== null) {
            return 'token_revoked';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'token_expired';
        }

        return null;
    }

    /**
     * آیا این IP اجازهٔ استفاده از این توکن را دارد؟
     *
     * ⚠️ نال و آرایهٔ خالی هر دو «بی‌محدودیت»اند و این عمدی است: اگر آرایهٔ
     * خالی را «هیچ IPای مجاز نیست» بخوانیم، یک ویرایشِ ناقص توکنِ زندهٔ
     * نماینده را بی‌صدا می‌کُشد و او علتش را هرگز نمی‌فهمد. جهتِ خطا باید به
     * سمتِ «کار کن» باشد وقتی مقصودِ کاربر مبهم است — و به سمتِ «رد کن» فقط
     * وقتی صریح گفته باشد.
     *
     * ⚠️ ورودیِ نال (وقتی IP در دسترس نیست) با فهرستِ پرشده **رد** می‌شود:
     * محدودیتی که با نبودِ داده دور زده شود، محدودیت نیست.
     */
    public function allowsIp(?string $ip): bool
    {
        $cidrs = array_values(array_filter((array) $this->allowed_cidrs));

        if ($cidrs === []) {
            return true;
        }

        if ($ip === null || $ip === '') {
            return false;
        }

        return IpUtils::checkIp($ip, $cidrs);
    }

    public function can(string $ability): bool
    {
        /*
        | 🔴 مرزِ AI — پیش از همهٔ منطقِ وایلدکارد. دامنهٔ «ai:*» از «*»
        | نمی‌آید و از سلسله‌مراتبِ domains هم نمی‌آید: توکنی که روزی «*»
        | گرفته بود، با مهاجرتِ M2 ناگهان دَرِ AI را باز نبیند. AI فقط با
        | **تیکِ صریحِ همان ability** و **پروژهٔ bound** می‌آید.
        */
        if (str_starts_with($ability, self::AI_PREFIX)) {
            $a = (array) ($this->abilities ?? []);

            return in_array($ability, $a, true) && $this->ai_project_id !== null;
        }

        $a = (array) ($this->abilities ?? []);

        if (in_array('*', $a, true) || in_array($ability, $a, true)) {
            return true;
        }

        /*
        | «domains:write» شاملِ «domains:read» است، وگرنه هر نماینده مجبور بود
        | هر دو را تیک بزند و اولین کسی که یادش می‌رفت، یک ۴۰۳ِ بی‌معنا
        | می‌گرفت روی مسیری که کارِ سنگین‌ترش را از قبل داشت.
        |
        | ⚠️ در جهتِ عکس **هرگز**: توکنِ خواندنی نباید بنویسد.
        */
        if ($ability === 'domains:read') {
            return in_array('domains:write', $a, true) || in_array('domains:manage', $a, true);
        }

        return false;
    }

    /** آیا این توکن قلمرو AI دارد (تیکِ صریحِ هر ability ساخته‌شده از نردبان)؟ */
    public function isAiKey(): bool
    {
        $a = (array) ($this->abilities ?? []);

        foreach ($a as $ab) {
            if (is_string($ab) && str_starts_with($ab, self::AI_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * پروژهٔ boundِ توکنِ AI — نال در «غیرِ AI» و در «AIِ بی‌پروژه»؛ هر دو
     * ردِ admission. رابطهٔ واقعی (نه find در هر سطر) تا صفحهٔ امنیت با
     * `with('aiProject')` بسته شود و N+1 نماند.
     */
    public function aiProject(): BelongsTo
    {
        return $this->belongsTo(AiProject::class, 'ai_project_id');
    }

    /** توکن‌هایی که هنوز زنده‌اند */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * ابطالِ **نرم**.
     *
     * 🔴 حذفِ فیزیکی غلط است: درست در لحظه‌ای که مشتری می‌گوید «این توکن لو
     * رفته»، تنها چیزی که می‌گفت آن توکن چه کرده هم پاک می‌شود
     * (`reseller_api_logs.token_id` به نال می‌افتد). حسابرسیِ حادثه بدونِ
     * ردیفِ توکن ممکن نیست.
     */
    public function revoke(): void
    {
        if ($this->revoked_at === null) {
            $this->forceFill(['revoked_at' => now()])->save();
        }
    }

    /** سقفِ خرجِ روزانه — سقفِ توکن، وگرنه سقفِ مشتری، وگرنه پیش‌فرضِ config */
    public function dailySpendCap(): int
    {
        $own = (int) ($this->daily_spend_cap_irt ?? 0);

        if ($own > 0) {
            return $own;
        }

        $customer = (int) ($this->customer?->reseller_daily_cap_irt ?? 0);

        return $customer > 0
            ? $customer
            : (int) config('domain_reseller.limits.daily_spend_irt', 0);
    }

    // ───────────────────────── صدور و یافتن ─────────────────────────

    /**
     * صدور توکنِ تازه. خروجی: [مدل، متنِ خام]. متنِ خام فقط همین‌جا در دسترس
     * است و دیگر بازیابی نمی‌شود.
     *
     * `$aiProjectId` **افزودنی** است: نال = توکنِ عادیِ همیشه‌بوده؛ پر = کلیدِ
     * AI گره‌خورده با پروژه. صدورِ AI بدونِ پروژه از پیش ممنوع می‌شود (در
     * کنترلر تهی چک می‌شود و در `isAiKey()` هم بی‌پروژه بی‌اثر است).
     *
     * @param  array<int,string>  $abilities
     * @param  array<int,string>  $cidrs
     * @return array{0:self,1:string}
     */
    public static function issue(
        int $customerId,
        string $name,
        array $abilities = ['read'],
        array $cidrs = [],
        ?\DateTimeInterface $expiresAt = null,
        ?int $aiProjectId = null,
    ): array {
        $plain = 'sn_'.bin2hex(random_bytes(24));   // پیشوندِ برند + ۴۸ رقمِ hex

        $token = static::create([
            'customer_id'   => $customerId,
            'name'          => $name,
            'token_hash'    => hash('sha256', $plain),
            'abilities'     => array_values(array_unique($abilities)),
            'allowed_cidrs' => array_values(array_filter($cidrs)),
            'expires_at'    => $expiresAt,
            'ai_project_id' => $aiProjectId,
        ]);

        return [$token, $plain];
    }

    /**
     * ⚠️ توکنِ باطل/منقضی هم برگردانده می‌شود — تشخیصِ علت کارِ فراخوان است.
     * اگر این‌جا فیلترشان کنیم، میدل‌ور نمی‌تواند «منقضی» را از «اصلاً وجود
     * ندارد» تفکیک کند و هر دو یک پیامِ گمراه‌کننده می‌گیرند.
     */
    public static function findByPlain(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        return static::where('token_hash', hash('sha256', $plain))->first();
    }
}
