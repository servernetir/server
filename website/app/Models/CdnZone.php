<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    protected $fillable = [
        'customer_id', 'service_id', 'domain', 'mode', 'status',
        'verify_token', 'verified_at', 'verify_error', 'checked_at', 'ownership',
        'synced_at', 'sync_error', 'dirty', 'edge_epoch',
        'cache_enabled', 'default_ttl', 'waf_level', 'waf_action',
        'dnssec', 'always_https', 'plan', 'quota_gb', 'meta',
    ];

    protected $casts = [
        'verified_at'   => 'datetime',
        'checked_at'    => 'datetime',
        'synced_at'     => 'datetime',
        'dirty'         => 'bool',
        'cache_enabled' => 'bool',
        'dnssec'        => 'bool',
        'always_https'  => 'bool',
        'meta'          => 'array',
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
     * منبعِ یگانهٔ نیم‌سرورها: تنظیمِ مدیر، وگرنه پیش‌فرضِ config.
     *
     * ⚠️ static و بی‌نیاز به نمونه، چون صفحهٔ فهرست (پیش از ساختِ هر zone)،
     * SOA در `PowerDnsClient` و `DelegationCheck` همه باید **یک** فهرست را
     * ببینند. دو منبع یعنی روزی پنل یک نام بگوید و تأیید نامِ دیگری بخواهد.
     */
    public static function ourNameservers(): array
    {
        $raw = (string) (Setting::get('dns_nameservers') ?? '');
        $list = array_values(array_filter(array_map(
            fn ($n) => mb_strtolower(rtrim(trim($n), '.')),
            preg_split('~[\s,]+~', $raw) ?: []
        )));

        return $list !== [] ? $list : array_map('strtolower', (array) config('edge.nameservers', []));
    }

    /**
     * آیا سرویس برای مشتری باز است؟ — یعنی سرورِ DNS واقعاً پشتِ نیم‌سرورهاست.
     *
     * 🔴 تا وقتی PowerDNS در `/admin/dns` وصل نشده، نیم‌سرورها فقط نام‌اند: روی
     * `servernet.cloud` رکوردِ wildcardِ پروکسی‌شدهٔ Cloudflare هست، پس
     * `derek.servernet.cloud` به IPِ Cloudflare می‌رسد که روی پورتِ ۵۳ جواب
     * نمی‌دهد. مشتری‌ای که در این حالت نیم‌سرورش را عوض کند، سایت و ایمیلش
     * **همان لحظه** می‌میرد. پس نه منو نشان داده می‌شود، نه افزودنِ دامنه.
     *
     * ⚠️ کلید رمزگشایی نمی‌شود (فقط وجودش): این از `shell()`ِ هر صفحهٔ پنل
     * صدا زده می‌شود و Setting از قبل در حافظه است.
     */
    public static function serviceOpen(): bool
    {
        return filled(Setting::get('pdns_api_key'))
            && filled(Setting::get('pdns_api_url') ?: config('edge.pdns.url'));
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
