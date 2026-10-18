<?php

namespace App\Models;

use App\Services\Dns\DnsConfig;
use App\Support\Rfc1982;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * یک دامنه که پشتِ لبهٔ سرورنت است.
 *
 * دو حالت دارد و تفاوتشان بنیادی است:
 *
 *  • `ns`    — نیم‌سرور مالِ ماست. رکوردها را ما نگه می‌داریم، apex پشتیبانی
 *              می‌شود، و گواهی با DNS-01 بی‌هیچ کاری از مشتری صادر می‌شود.
 *  • `cname` — DNS مالِ خودِ مشتری است و فقط یک زیردامنه به ما CNAME شده.
 *              apex ممکن نیست و صدورِ گواهی به تغییرِ رکوردِ مشتری بند است.
 */
class CdnZone extends Model
{
    /** وضعیت‌های انتشار روی primary (§3.2) */
    public const PUBLISH_STATES = ['pending', 'published', 'failed', 'deleting', 'deleted'];

    /** وضعیت‌های تفویض در parent (§5.3) */
    public const DELEGATION_STATES = ['unknown', 'not_delegated', 'partial', 'delegated', 'lookup_failed'];

    protected $fillable = [
        'customer_id', 'service_id', 'domain', 'mode', 'status',
        'verify_token', 'verified_at', 'verify_error', 'checked_at', 'ownership',
        'synced_at', 'sync_error', 'dirty', 'edge_epoch',
        'cache_enabled', 'default_ttl', 'waf_level', 'waf_action',
        'dnssec', 'always_https', 'plan', 'quota_gb', 'meta',
        // v2 — انتشار، تفویض، مالکیت (مهاجرتِ 000200)
        'desired_version', 'applied_version', 'publish_state', 'publish_attempts',
        'next_publish_at', 'last_publish_error', 'primary_serial', 'published_at',
        'delegation_state', 'delegation_checked_at', 'delegation_ns', 'parent_ds',
        'records_reviewed_at', 'claim_id',
    ];

    /*
    | ⚠️ عمداً `$attributes` پیش‌فرض برای ستون‌های v2 نداریم: پیش‌فرض‌ها در
    | دیتابیس‌اند. مقدارِ پیش‌فرض در مدل یعنی INSERT آن ستون‌ها را هم می‌فرستد و
    | روی نصبی که 000200 هنوز اجرا نشده، ساختنِ هر zone (حتی از مسیرِ v1) می‌شکند.
    | پس از create اگر مقدارشان لازم است `refresh()` کنید.
    */
    protected $casts = [
        'verified_at'   => 'datetime',
        'checked_at'    => 'datetime',
        'synced_at'     => 'datetime',
        'dirty'         => 'bool',
        'cache_enabled' => 'bool',
        'dnssec'        => 'bool',
        'always_https'  => 'bool',
        'meta'          => 'array',
        // v2
        'desired_version'       => 'int',
        'applied_version'       => 'int',
        'publish_attempts'      => 'int',
        'primary_serial'        => 'int',
        'claim_id'              => 'int',
        'next_publish_at'       => 'datetime',
        'published_at'          => 'datetime',
        'delegation_checked_at' => 'datetime',
        'records_reviewed_at'   => 'datetime',
        'delegation_ns'         => 'array',
        'parent_ds'             => 'array',
    ];

    /* ───────────────────────── روابط ───────────────────────── */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(CdnRecord::class);
    }

    public function origins(): HasMany
    {
        return $this->hasMany(CdnOrigin::class);
    }

    public function cacheRules(): HasMany
    {
        return $this->hasMany(CdnCacheRule::class)->orderBy('position');
    }

    public function wafRules(): HasMany
    {
        return $this->hasMany(CdnWafRule::class)->orderBy('position');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(CdnCertificate::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(CdnUsage::class);
    }

    /** وضعیتِ این zone روی هر نیم‌سرورِ عمومی */
    public function nodes(): HasMany
    {
        return $this->hasMany(DnsZoneNode::class, 'cdn_zone_id');
    }

    /** ادعایی که مالکیت را ثابت کرد (اگر از مسیرِ TXT/ثبت آمده) */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(DnsOwnershipClaim::class, 'claim_id');
    }

    /* ───────────────────────── اسکوپ ───────────────────────── */

    /**
     * zoneهایی که واقعاً باید در snapshot بروند.
     *
     * 🔴 `suspended` عمداً بیرون است: تعلیق باید ترافیک را قطع کند وگرنه
     * مشتریِ بدهکار همان سرویسی را می‌گیرد که پولش را نداده. ولی ردیف پاک
     * نمی‌شود، پس رفعِ تعلیق فقط یک تغییرِ وضعیت است.
     */
    public function scopeServing(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function scopeForCustomer(Builder $q, $customerId): Builder
    {
        return $q->where('customer_id', $customerId);
    }

    /* ───────────────────────── رفتار ───────────────────────── */

    public function isNsMode(): bool
    {
        return $this->mode === 'ns';
    }

    /** نیم‌سرورهایی که مشتری باید در رجیسترارش ثبت کند */
    public function nameservers(): array
    {
        return self::ourNameservers();
    }

    /**
     * نام‌های نیم‌سرورها (کوچک، بی‌نقطهٔ پایانی).
     *
     * ⚠️ v2: فقط واگذاری به `DnsConfig` — همان یک منبعی که انتشار، تأییدِ
     * تفویض و پنل می‌خوانند. دیگر `config('edge.nameservers')` خوانده نمی‌شود و
     * تا مدیر فهرست را تنظیم نکند خالی است (نامِ حدسی به مشتری داده نمی‌شود).
     *
     * @return array<int,string>
     */
    public static function ourNameservers(): array
    {
        return DnsConfig::nameserverHosts();
    }

    /**
     * آیا سرویس برای مشتری باز است؟ — یعنی سرورِ DNS واقعاً پشتِ نیم‌سرورهاست.
     *
     * 🔴 تا وقتی PowerDNS وصل نشده، نیم‌سرورها فقط نام‌اند: روی `servernet.cloud`
     * رکوردِ wildcardِ پروکسی‌شدهٔ Cloudflare هست، پس هر نامِ زیرش به IPِ
     * Cloudflare می‌رسد که روی پورتِ ۵۳ جواب نمی‌دهد. مشتری‌ای که در این حالت
     * نیم‌سرورش را عوض کند، سایت و ایمیلش **همان لحظه** می‌میرد. پس نه منو
     * نشان داده می‌شود، نه افزودنِ دامنه. شرط‌ها در `DnsConfig::serviceOpen`.
     */
    public static function serviceOpen(): bool
    {
        return DnsConfig::serviceOpen();
    }

    /**
     * نسخهٔ مطلوب را یک پله بالا می‌برد و zone را «در انتظارِ انتشار» می‌کند.
     *
     * 🔴 `UPDATE … SET desired_version = desired_version + 1` روی خودِ ردیف، نه
     * `$this->desired_version + 1`: دو درخواستِ هم‌زمان با دو نمونهٔ کهنه باید
     * +۲ بسازند. با جمعِ درون‌حافظه هر دو «۲» می‌نوشتند و ناشر نسخهٔ دوم را
     * «قبلاً منتشرشده» می‌دید — تغییرِ مشتری بی‌صدا هرگز به DNS نمی‌رسید.
     *
     * عددِ برگشتی دوباره از دیتابیس خوانده می‌شود (نه حدس از حافظه). داخلِ
     * تراکنش (قراردادِ §4.1) قفلِ ردیف تا commit می‌مانَد، پس این دقیقاً نسخهٔ
     * همین تغییر است.
     *
     * ⚠️ zoneِ `status=deleting` همان `publish_state=deleting` می‌ماند (§4.4).
     * CASE وضعیتِ **ذخیره‌شده** را می‌بیند؛ پس ترتیبِ درست «وضعیت را save کن،
     * بعد bump» یا «bump، بعد وضعیت و save» است.
     *
     * 🔴 تغییرِ ذخیره‌نشده روی ستون‌هایی که این متد بازنویسی می‌کند استثنا
     * می‌دهد: ترتیبِ «در حافظه deleting ← bump ← save» قبلاً `publish_state`
     * را بی‌صدا `pending` می‌کرد و zoneِ در حالِ حذف دوباره منتشر می‌شد.
     *
     * 🔴 ردیفِ ناموجود (حذفِ هم‌زمان در §4.4) استثنا می‌دهد، نه «۰»: فراخوان
     * داخلِ `DB::transaction` است و فقط استثنا آن را برمی‌گرداند؛ وگرنه
     * رکوردهای یتیمِ zoneی پاک‌شده commit می‌شدند.
     *
     * ⚠️ `dirty` (پرچمِ v1) عمداً نوشته **نمی‌شود**: `dns:reconcile`ِ v1 هنوز
     * zoneهای dirty را با کلاینتِ v1 می‌سازد (Native، بی‌catalog، expireِ ۷روزه)
     * و تنها کدِ v2 که آن را می‌نوشت همین‌جا بود.
     */
    public function bumpVersion(): int
    {
        if (! $this->exists) {
            throw new \LogicException('bumpVersion() on an unsaved zone');
        }

        $unsaved = array_values(array_intersect(
            array_keys($this->getDirty()),
            ['status', 'publish_state', 'desired_version', 'next_publish_at']
        ));

        if ($unsaved !== []) {
            throw new \LogicException('bumpVersion() would discard unsaved ['.implode(', ', $unsaved).']; save() first');
        }

        $q = static::query()->whereKey($this->getKey());

        $affected = $q->increment('desired_version', 1, [
            'publish_state'   => $q->getQuery()->raw("CASE WHEN status = 'deleting' THEN 'deleting' ELSE 'pending' END"),
            'next_publish_at' => null,
        ]);

        $row = $affected > 0
            ? static::query()->whereKey($this->getKey())->toBase()->first(['desired_version', 'publish_state'])
            : null;

        if ($row === null) {
            throw (new ModelNotFoundException)->setModel(static::class, [$this->getKey()]);
        }

        $v = (int) $row->desired_version;

        $this->forceFill([
            'desired_version' => $v,
            'publish_state'   => (string) $row->publish_state,
            'next_publish_at' => null,
        ])->syncOriginalAttributes(['desired_version', 'publish_state', 'next_publish_at']);

        return $v;
    }

    /**
     * آیا آخرین نسخه روی **همهٔ** نیم‌سرورهای پیکربندی‌شده دیده می‌شود؟
     *
     * 🔴 این تنها مبنای نمایشِ «منتشر شد» به مشتری است (§4.3). شرط‌ها:
     * `publish_state=published`، سریالِ primary معلوم، و برای **هر** نامِ
     * `DnsConfig::nameserverHosts()` یک ردیفِ `in_sync` با سریالِ ≥ سریالِ
     * primary طبقِ RFC 1982 (سریال پس از 2^32−1 به صفر می‌پیچد). ردیفِ نودی که
     * دیگر در فهرست نیست حساب نمی‌شود؛ فهرستِ خالی ⇒ نه.
     */
    public function isPublishedEverywhere(): bool
    {
        if ($this->publish_state !== 'published' || $this->primary_serial === null) {
            return false;
        }

        $hosts = DnsConfig::nameserverHosts();

        if ($hosts === []) {
            return false;
        }

        try {
            $rows = $this->relationLoaded('nodes') ? $this->nodes : $this->nodes()->get();
        } catch (\Throwable) {
            return false;                       // جدولِ نودها هنوز نیست ⇒ ادعای انتشار نه
        }

        $byNode = [];
        foreach ($rows as $r) {
            $byNode[rtrim(strtolower((string) $r->node), '.')] = $r;
        }

        foreach ($hosts as $h) {
            $r = $byNode[$h] ?? null;

            if ($r === null || $r->state !== DnsZoneNode::IN_SYNC || $r->serial === null
                || ! Rfc1982::atLeast((int) $r->serial, (int) $this->primary_serial)) {
                return false;
            }
        }

        return true;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** مالکیت اثبات شده؟ (نه فقط ادعا) */
    public function ownershipProven(): bool
    {
        return in_array($this->ownership, ['registered', 'txt', 'delegated'], true);
    }

    /** تاریخی که zoneِ در انتظار، اگر تفویض نشود، پاک می‌شود */
    public function pendingExpiresAt(): ?\Illuminate\Support\Carbon
    {
        if (! $this->isPending() || $this->ownershipProven()) {
            return null;
        }

        return $this->created_at?->copy()->addDays((int) config('edge.pending_days', 14));
    }

    /** یک تغییر ثبت شد که هنوز به سرورِ DNS نرسیده */
    public function markDirty(): void
    {
        $this->forceFill(['dirty' => true])->save();
    }

    /**
     * «دامنهٔ قابلِ ثبت» یک نام: `shop.example.co.ir` → `example.co.ir`.
     *
     * فهرستِ پسوندهای چندبخشی در `config('edge.multi_label_suffixes')` است.
     * کامل نیست و نمی‌تواند باشد (فهرستِ عمومیِ پسوندها هزاران ردیف است)،
     * ولی خطایش ایمن است: پسوندِ ناشناخته فقط یعنی مشتری نمی‌تواند zone
     * بسازد و به پشتیبانی می‌آید — نه اینکه zoneِ دامنهٔ کسِ دیگری را بسازد.
     */
    public static function registrable(string $domain): ?string
    {
        $labels = explode('.', $domain);
        $n = count($labels);

        if ($n < 2) {
            return null;
        }

        $last2 = $labels[$n - 2].'.'.$labels[$n - 1];

        if (in_array($last2, (array) config('edge.multi_label_suffixes', []), true)) {
            return $n >= 3 ? $labels[$n - 3].'.'.$last2 : null;
        }

        return $last2;
    }

    /**
     * مقصدِ CNAME در حالتِ «فقط CDN».
     *
     * توکن یکتاست تا دو مشتری یک مقصد نگیرند — هم برای مسیریابی لازم است و
     * هم جلوی «هرکسی دامنه‌اش را به میزبانِ عمومی ما CNAME کند» را می‌گیرد.
     */
    public function edgeHost(): string
    {
        return $this->verify_token.'.'.config('edge.edge_suffix');
    }

    /** رکوردِ TXT ای که مالکیت را ثابت می‌کند */
    public function verifyRecord(): array
    {
        return [
            'name'    => '_servernet-verify.'.$this->domain,
            'type'    => 'TXT',
            'content' => 'servernet-verify='.$this->verify_token,
        ];
    }

    /**
     * پرجِ کامل.
     *
     * 🔴 چرا یک عدد و نه فراخوانِ نودها: کلیدِ کش با همین `edge_epoch` شروع
     * می‌شود، پس افزایشش تمامِ ورودی‌های قبلی را در یک لحظه بی‌اثر می‌کند —
     * روی همهٔ نودها هم‌زمان، بدونِ هیچ درخواستِ شبکه‌ای، و بدونِ حالتِ
     * «روی یکی موفق، روی دیگری ناموفق» که همیشه سخت‌ترین بخشِ پرج است.
     *
     * ⚠️ `increment` مستقیم روی دیتابیس است نه `$this->edge_epoch + 1`:
     * دو مدیر که هم‌زمان پرج بزنند نباید یکی عددِ دیگری را بازنویسی کند.
     */
    public function purgeAll(): int
    {
        $this->increment('edge_epoch');

        return (int) $this->refresh()->edge_epoch;
    }

    public static function freshToken(): string
    {
        return Str::lower(Str::random(24));
    }

    /**
     * نامِ دامنه را به شکلی که در دیتابیس می‌نشیند نرمال می‌کند.
     *
     * ⚠️ نقطهٔ پایانی و `www.` و بزرگی‌وکوچکی همه یک دامنه‌اند. بی‌نرمال‌سازی،
     * یک مشتری می‌توانست `Example.com` را ثبت کند در حالی که `example.com`
     * مالِ کسِ دیگری است — و ایندکسِ یکتا هم جلویش را نمی‌گرفت.
     */
    public static function normalizeDomain(string $raw): string
    {
        $d = mb_strtolower(trim($raw));
        $d = preg_replace('~^https?://~', '', $d);
        $d = explode('/', $d)[0];
        $d = rtrim($d, '.');

        return preg_replace('~^www\.~', '', $d) ?? $d;
    }
}
