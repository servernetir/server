<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * یک دامنهٔ فروخته‌شده.
 *
 * وضعیتِ «زنده» را این‌جا تعریف می‌کنیم و نه در پرس‌وجوهای پراکنده — همان درسِ
 * `Service::DEAD_STATUSES`: وقتی تعریفِ «مرده» در پنج جا تکرار شود، افزودنِ
 * وضعیتِ ششم یکی‌شان را جا می‌اندازد و آن یکی معمولاً همانی است که پول می‌گیرد.
 */
class Domain extends Model
{
    /** دامنه دیگر مالِ ما نیست یا عمرش تمام شده */
    public const DEAD_STATUSES = ['cancelled', 'transferred_away', 'expired'];

    /**
     * انتقال به ما در جریان است — نه مرده، نه هنوز مالِ ما.
     *
     * ⚠️ عمداً در `DEAD_STATUSES` نیست: مشتری پول داده و باید ردیف را در پنلش
     * ببیند. و عمداً `pending` هم نیست، چون آن یکی یعنی «در صفِ **ثبت**».
     */
    public const STATUS_TRANSFERRING = 'transferring';

    protected $fillable = [
        'customer_id', 'domain', 'sld', 'tld', 'registrar', 'status',
        'order_type', 'transfer_status', 'transfer_submitted_at', 'transfer_checked_at',
        'provision_status', 'provision_tries', 'provision_error',
        'op_id', 'owner_handle', 'period_years', 'auto_renew', 'is_locked',
        'whois_privacy', 'name_servers', 'registered_at', 'expires_at',
        'price_toman', 'renew_toman', 'cost_amount', 'cost_currency',
        'quote_id', 'invoice_id', 'meta',
    ];

    protected $casts = [
        'name_servers'   => 'array',
        'meta'           => 'array',
        'auto_renew'     => 'boolean',
        'is_locked'      => 'boolean',
        'whois_privacy'  => 'boolean',
        'registered_at'  => 'datetime',
        'expires_at'     => 'datetime',
        'transfer_submitted_at' => 'datetime',
        'transfer_checked_at'   => 'datetime',
        'price_toman'    => 'integer',
        'renew_toman'    => 'integer',
        'cost_amount'    => 'integer',
        'period_years'   => 'integer',
    ];

    /**
     * ⚠️ بهایِ تمام‌شده هرگز نباید در JSONی که به مشتری می‌رود ظاهر شود —
     * همان قاعدهٔ `CloudPlan::$hidden`. حاشیهٔ سودِ ما دادهٔ داخلی است.
     */
    protected $hidden = ['cost_amount', 'cost_currency', 'owner_handle', 'op_id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(DomainQuote::class, 'quote_id');
    }

    // ───────────────────────── وضعیت ─────────────────────────

    public function isDead(): bool
    {
        return in_array($this->status, self::DEAD_STATUSES, true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** هنوز ثبت نشده — مشتری پول داده و منتظر است */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function scopeAlive(Builder $q): Builder
    {
        return $q->whereNotIn('status', self::DEAD_STATUSES);
    }

    /** پس از این مدت، قفلِ `running` رهاشده حساب می‌شود */
    public const STALE_LOCK_MINUTES = 15;

    /**
     * دامنه‌هایی که کرونِ تحویل باید بردارد.
     *
     * 🔴 قفلِ رهاشده هم برداشته می‌شود. قفلِ اتمی وضعیت را `running` می‌کند، ولی
     * اگر همان اجرا وسطِ کار بمیرد (پایانِ زمانِ PHP، ری‌استارتِ سرور، کشته‌شدنِ
     * کرون) هیچ‌کس آن را برنمی‌گرداند: دامنه برای همیشه `running` می‌مانَد،
     * هیچ اجرای بعدی برش نمی‌دارد، و مشتری پول داده و دامنه‌ای ندارد — بی‌هیچ
     * خطایی. همان الگوی «خرابیِ خاموش» که این پروژه بارها خورده.
     *
     * ⚠️ پانزده دقیقه سخاوتمندانه است: خودِ ثبت چند ثانیه طول می‌کشد، پس این
     * پنجره فقط اجرایِ واقعاً مرده را برمی‌دارد نه اجرای کُند را — وگرنه دو
     * اجرا هم‌زمان یک دامنه را می‌خریدند.
     */
    public function scopeAwaitingRegistration(Builder $q): Builder
    {
        return $q->where('status', 'pending')
            /*
            | 🔴 ردیفِ انتقال هرگز نباید در صفِ **ثبت** بیفتد.
            |
            | همان درسِ ثبت‌شدهٔ «صفِ تمدید و صفِ ثبت با status از هم جدا
            | می‌شوند». انتقال دو محافظ دارد چون هرکدام یک اشتباهِ متفاوت را
            | می‌گیرد: `status = transferring` اگر کسی روزی `order_type` را
            | فراموش کند، و این شرط اگر کسی روزی مقدارِ `status` را عوض کند.
            |
            | بی‌این، دامنه‌ای که مشتری برای **انتقال** پول داده به
            | `registerDomain()` می‌رفت — یعنی تلاش برای خریدِ نامی که از قبل
            | مالِ شخصِ دیگری است. رجیسترار ردش می‌کند، ولی ردیف `failed`
            | می‌شود و مشتری پیامِ «ثبت ناموفق» می‌گیرد برای کاری که اصلاً
            | ثبت نبود.
            */
            ->where('order_type', 'register')
            ->where(fn ($w) => $w
                ->where('provision_status', 'pending')
                ->orWhere(fn ($s) => $s
                    ->where('provision_status', 'running')
                    ->where('updated_at', '<', now()->subMinutes(self::STALE_LOCK_MINUTES))));
    }

    // ───────────────────────── انتقال ─────────────────────────

    public function isTransfer(): bool
    {
        return $this->order_type === 'transfer';
    }

    /** انتقالی که هنوز به رجیسترار نرفته — کرون باید بردارد */
    public function scopeAwaitingTransferSubmit(Builder $q): Builder
    {
        return $q->where('order_type', 'transfer')
            ->where('transfer_status', 'pending')
            ->where(fn ($w) => $w
                ->where('provision_status', 'pending')
                ->orWhere(fn ($s) => $s
                    ->where('provision_status', 'running')
                    ->where('updated_at', '<', now()->subMinutes(self::STALE_LOCK_MINUTES))));
    }

    /**
     * انتقالی که ثبت شده و منتظرِ سمتِ مقابل است.
     *
     * ⚠️ `transfer_checked_at` فاصله می‌گذارد. این ردیف‌ها روزها زنده می‌مانند
     * و بی‌فاصله، هر اجرای کرون همهٔ آنها را از رجیسترار می‌پرسد — روی حسابی که
     * یک بار به‌خاطرِ تماسِ زیاد علامت خورده است.
     */
    public function scopeAwaitingTransferResult(Builder $q, int $minutes = 180): Builder
    {
        return $q->where('order_type', 'transfer')
            ->where('transfer_status', 'submitted')
            ->where(fn ($w) => $w
                ->whereNull('transfer_checked_at')
                ->orWhere('transfer_checked_at', '<', now()->subMinutes($minutes)));
    }

    /**
     * دامنه‌هایی که تا `$days` روز دیگر منقضی می‌شوند.
     *
     * ⚠️ فقط دامنهٔ **فعال**. دامنهٔ ثبت‌نشده تاریخ انقضا ندارد و دامنهٔ مرده
     * نباید یادآوریِ تمدید بگیرد — مشتری‌ای که دامنه‌اش را منتقل کرده،
     * پیامکِ «دامنه‌ات دارد منقضی می‌شود» دریافت نکند.
     */
    public function scopeExpiringWithin(Builder $q, int $days): Builder
    {
        return $q->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    // ───────────────────────── تمدید ─────────────────────────

    /**
     * چند روز پس از انقضا هنوز «مرده» اعلامش نکنیم.
     *
     * ⚠️ رجیستری‌ها معمولاً یک دورهٔ بازیابی دارند و در آن پنجره دامنه هنوز
     * قابلِ برگرداندن است. اگر همان روزِ انقضا `expired` بزنیم، دامنه از پنلِ
     * مشتری غیب می‌شود (`scopeAlive`) درست وقتی که هنوز می‌شود نجاتش داد — و
     * مشتری فکر می‌کند تمام شده.
     */
    public const EXPIRY_GRACE_DAYS = 30;

    /** چند روز پیش از انقضا فاکتورِ تمدید صادر شود */
    public const RENEW_LEAD_DAYS = 21;

    /**
     * دامنه‌هایی که تمدیدشان **پرداخت شده** و منتظرِ تماس با رجیسترارند.
     *
     * 🔴 چرا امن است که همان ستونِ `provision_status` را قرض بگیریم:
     * `awaitingRegistration` شرطِ `status='pending'` دارد و این‌جا
     * `status='active'` است — دو مجموعه **قطعاً** بی‌اشتراک‌اند. پس کرونِ ثبت
     * هرگز یک تمدید را با یک ثبتِ تازه اشتباه نمی‌گیرد و دامنه دوباره خریده
     * نمی‌شود. اگر روزی این شرط را برداشتی، همان فاجعه برمی‌گردد.
     *
     * قفلِ رهاشدهٔ `running` هم مثلِ ثبت بازپس گرفته می‌شود، وگرنه یک اجرای
     * مرده دامنه را برای همیشه در صف نگه می‌دارد و مشتری پولِ تمدید را داده و
     * دامنه‌اش منقضی می‌شود.
     */
    public function scopeAwaitingRenewal(Builder $q): Builder
    {
        return $q->where('status', 'active')
            ->where(fn ($w) => $w
                ->where('provision_status', 'pending')
                ->orWhere(fn ($s) => $s
                    ->where('provision_status', 'running')
                    ->where('updated_at', '<', now()->subMinutes(self::STALE_LOCK_MINUTES))));
    }

    /** چند سال برای تمدید پرداخت شده (پیش‌فرض ۱) */
    public function renewYears(): int
    {
        return max(1, (int) (($this->meta['renew_years'] ?? 1)));
    }

    /** آخرین مرحلهٔ یادآوریِ انقضا که فرستاده شده — جلوی پیامِ تکراری */
    public function expiryStage(): ?int
    {
        $v = $this->meta['exp_stage'] ?? null;

        return $v === null ? null : (int) $v;
    }

    /**
     * 🔴 آیا واقعاً برای این دامنه پولی گرفته‌ایم؟
     *
     * هر مسیری که دامنه را به صفِ **ثبت** می‌فرستد باید اول این را بپرسد. ثبت
     * یعنی خریدِ واقعی از رجیسترار؛ اگر برای دامنه‌ای بزنیم که فاکتورش پرداخت
     * نشده، پولش را از جیبِ خودمان داده‌ایم.
     *
     * ⚠️ این حالت فرضی نیست: ردیفِ سفارشِ رهاشده `provision_status='none'` و
     * `status='pending'` دارد، و فیلترِ پیش‌فرضِ /admin/domains دقیقاً همان‌ها را
     * نشان می‌دهد. یعنی مدیری که صفِ «نیازمندِ توجه» را با «تلاش دوباره» خالی
     * می‌کند، ناخواسته دامنه‌های پرداخت‌نشده را می‌خرد.
     */
    public function hasPaidInvoice(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('invoices', 'domain_id')) {
            return true;      // نصبِ مهاجرت‌نخورده: سنجش ممکن نیست، راه را نبند
        }

        return \App\Models\Invoice::where('domain_id', $this->id)->where('status', 'paid')->exists();
    }

    /** یک کلید را در `meta` بنویس بی‌آنکه بقیه را پاک کنی */
    public function putMeta(array $pairs): void
    {
        $this->forceFill(['meta' => array_merge((array) $this->meta, $pairs)])->save();
    }

    // ───────────────────────── نمایش ─────────────────────────

    /** روزهای باقی‌مانده تا انقضا؛ منفی یعنی گذشته */
    public function daysLeft(): ?int
    {
        return $this->expires_at ? (int) now()->startOfDay()->diffInDays($this->expires_at, false) : null;
    }

    /** nameserverهای مؤثر — اگر چیزی ست نشده، پیش‌فرضِ شرکت */
    public function effectiveNameServers(): array
    {
        $ns = array_values(array_filter((array) $this->name_servers));

        return $ns !== [] ? $ns : self::defaultNameServers();
    }

    /** @return array<int,string> */
    public static function defaultNameServers(): array
    {
        $ns = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (Setting::get('domain_nameservers') ?? ''))
        )));

        return $ns !== [] ? $ns : (array) config('services.openprovider.nameservers', []);
    }

    /** «example.com» → ['example', 'com'] */
    public static function splitFqdn(string $fqdn): array
    {
        $fqdn = strtolower(trim($fqdn, ". \t\n\r\0\x0B"));
        $dot = strpos($fqdn, '.');

        return $dot === false ? [$fqdn, ''] : [substr($fqdn, 0, $dot), substr($fqdn, $dot + 1)];
    }
}
