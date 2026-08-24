<?php

namespace App\Services\Domain;

use App\Models\Domain;
use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * انتقالِ دامنه از رجیسترارِ دیگر به ما.
 *
 * ═══ چرا سرویسِ جدا و نه متدی در DomainRegistrar ═══
 *
 * ثبت یک تراکنشِ **همزمان** است: یک تماس، و در همان تماس یا شد یا نشد. انتقال
 * یک **جریانِ چندروزه** است که سمتِ مقابل تصمیم می‌گیرد. یکی‌کردنشان یعنی
 * `provision_status` باید هم‌زمان دو معنی بدهد و قفلِ ۱۵ دقیقه‌ایِ رهاشده — که
 * برای کارِ ثانیه‌ای درست است — روی انتقال به فاجعه تبدیل شود: هر ۱۵ دقیقه یک
 * سفارشِ انتقالِ تازه به رجیسترار.
 *
 * ═══ 🔴 کدِ انتقال هرگز ذخیره نمی‌شود ═══
 *
 * `$authCode` فقط پارامترِ متد است و هیچ‌جا نوشته نمی‌شود — نه در ستون، نه در
 * `meta`، نه در لاگ. کلیدِ مالکیتِ دامنه است و همین پروژه در
 * `panel_only_operations` برای کدِ **خروجی** همین قاعده را دارد. اگر انتقال رد
 * شد، از مشتری دوباره پرسیده می‌شود.
 *
 * ⚠️ به همین دلیل، `submit()` **در همان درخواستی که مشتری کد را وارد می‌کند**
 * صدا زده می‌شود، نه در کرون. کرون کد ندارد و نمی‌تواند داشته باشد.
 *
 * ═══ ترتیبِ پول ═══
 *
 * مبلغ در لحظهٔ سفارش از مشتری گرفته می‌شود (مثلِ ثبت)، ولی انتقالِ **ردشده**
 * باید برگردد. `reject()` این را انجام می‌دهد. اگر پول را تا پایانِ انتقال
 * نگیریم، مشتری می‌تواند وسطِ راه ناپدید شود و هزینهٔ رجیستری پای ماست.
 */
class DomainTransfer
{
    /**
     * وضعیت‌هایی که رجیسترار برای دامنهٔ در حالِ انتقال می‌دهد.
     *
     * ⚠️ فهرست از پاسخِ واقعیِ OpenProvider است و **حساس به بزرگی/کوچکی نیست**:
     * همان API در جاهای مختلف `ACT` و `act` هر دو را برگردانده.
     */
    private const REMOTE_ACTIVE = ['act', 'active'];

    private const REMOTE_PENDING = ['req', 'requested', 'pen', 'pending', 'sch', 'scheduled'];

    private const REMOTE_FAILED = ['fai', 'failed', 'del', 'deleted', 'rej', 'rejected'];

    /**
     * انتقال پس از این مدت بی‌نتیجه، «رد شده» حساب می‌شود.
     *
     * ⚠️ رجیستریِ gTLD پنجرهٔ ۵ روزهٔ تأیید دارد و بعد از آن یا خودکار تأیید
     * می‌شود یا می‌افتد. ۱۴ روز سخاوتمندانه است تا انتقالی که واقعاً در جریان
     * است زودتر از موعد لغو نشود — ولی بی‌سقف هم نمی‌شود رهایش کرد، وگرنه
     * ردیفِ مرده تا ابد در صف می‌مانَد و پولِ مشتری هم برنمی‌گردد.
     */
    public const MAX_WAIT_DAYS = 14;

    public function __construct(
        private OpenProviderClient $op,
        private DomainRegistrar $registrar,
    ) {}

    // ═══════════════════════ گیت‌های پیش از پول ═══════════════════════

    /**
     * آیا این دامنه اصلاً قابلِ انتقال است؟
     *
     * 🔴 همهٔ این بررسی‌ها **پیش از گرفتنِ پول** انجام می‌شوند. گیتی که بعد از
     * فاکتور بنشیند فقط جای شکست را عوض می‌کند — همان درسِ `TldGate` که در
     * `Account\DomainController` پیش از ساختِ فاکتور نشست.
     *
     * @return array{ok:bool, reason:string, message:string}
     */
    public function eligibility(string $sld, string $tld): array
    {
        $tld = ltrim(strtolower($tld), '.');

        if (! DomainSearch::sells($tld)) {
            return $this->no('tld_not_sold',
                'پسوندِ «.'.$tld.'» از این مسیر منتقل نمی‌شود.');
        }

        if (TldGate::isBlocked($tld)) {
            return $this->no('tld_blocked',
                'انتقال در پسوندِ «.'.$tld.'» موقتاً مقدور نیست. مبلغی از شما کسر نشد.');
        }

        /*
        | 🔴 دامنه باید **ثبت‌شده** باشد. دامنهٔ آزاد را نمی‌شود منتقل کرد —
        | باید ثبتش کرد. بی‌این بررسی مشتری پولِ انتقال می‌داد، رجیسترار
        | «دامنه وجود ندارد» می‌گفت، و ما باید دستی پس می‌دادیم.
        |
        | ⚠️ مستقیم از رجیسترار پرسیده می‌شود، نه از `DomainSearch::stateOf()`:
        | آن متد یک **ردیفِ ساخته‌شدهٔ جستجو** می‌گیرد نه نامِ دامنه، و
        | ساختنِ آن ردیف یعنی یک مسیرِ قیمت‌گذاریِ کامل که این‌جا لازم نیست.
        |
        | ⚠️ و شکستِ استعلام «آزاد است» خوانده **نمی‌شود**. توکنِ منقضی و قطعیِ
        | گذرا هر دو پاسخِ خالی می‌دهند؛ اگر «آزاد» بخوانیمشان، انتقالِ کاملاً
        | معتبر را رد می‌کنیم و مشتری فکر می‌کند دامنه‌اش مالِ کسی نیست. همان
        | تلهٔ ثبت‌شدهٔ `CloudInventory`.
        */
        $check = $this->op->check([['name' => $sld, 'extension' => $tld]]);

        if (! $check['ok']) {
            return $this->no('lookup_failed',
                'وضعیتِ دامنه را نتوانستیم استعلام کنیم. چند دقیقهٔ دیگر دوباره تلاش کنید.');
        }

        $status = strtolower((string) data_get($check, 'results.0.status', ''));

        if ($status === '') {
            return $this->no('lookup_failed',
                'پاسخِ روشنی از رجیسترار نگرفتیم. چند دقیقهٔ دیگر دوباره تلاش کنید.');
        }

        if ($status === 'free' || $status === 'available') {
            return $this->no('not_registered',
                'این دامنه ثبت نشده است — به‌جای انتقال می‌توانید همین حالا ثبتش کنید.');
        }

        /*
        | دامنه‌ای که از قبل نزدِ خودِ ماست، انتقال نمی‌خواهد.
        |
        | ⚠️ `alive()` لازم است: دامنه‌ای که قبلاً منتقل شده و رفته
        | (`transferred_away`) باید بتواند برگردد.
        */
        $mine = Domain::alive()->where('domain', $sld.'.'.$tld)->first();

        if ($mine !== null) {
            return $this->no('already_ours',
                'این دامنه هم‌اکنون در سامانهٔ ماست. برای تمدید از بخشِ دامنه‌های خود اقدام کنید.');
        }

        return ['ok' => true, 'reason' => '', 'message' => ''];
    }

    /** @return array{ok:false, reason:string, message:string} */
    private function no(string $reason, string $message): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message];
    }

    // ═══════════════════════ ثبتِ درخواست ═══════════════════════

    /**
     * درخواستِ انتقال را به رجیسترار می‌فرستد.
     *
     * ⚠️ `$authCode` را نگه ندار و لاگ نکن.
     *
     * @return array{ok:bool, message:string, manual:bool}
     */
    public function submit(Domain $domain, string $authCode): array
    {
        // قفلِ اتمی — همان الگوی ثبت. بی‌آن، کلیکِ دوبارهٔ مشتری دو سفارشِ
        // انتقال می‌فرستد و رجیسترار هر دو را هزینه می‌کند.
        $claimed = DB::table('domains')
            ->where('id', $domain->id)
            ->where('order_type', 'transfer')
            ->where('transfer_status', 'pending')
            ->where(fn ($w) => $w
                ->where('provision_status', 'pending')
                ->orWhere(fn ($s) => $s
                    ->where('provision_status', 'running')
                    ->where('updated_at', '<', now()->subMinutes(Domain::STALE_LOCK_MINUTES))))
            ->update(['provision_status' => 'running', 'updated_at' => now()]);

        if ($claimed === 0) {
            return ['ok' => false, 'manual' => false, 'message' => 'این درخواست هم‌اکنون در حالِ پردازش است.'];
        }

        $domain->refresh();

        try {
            return $this->doSubmit($domain, $authCode);
        } catch (\Throwable $e) {
            // ⚠️ پیامِ استثنا ممکن است بدنهٔ درخواست را داشته باشد و بدنه
            //    کدِ انتقال دارد. فقط کلاس و خطِ خطا ثبت می‌شود.
            Log::error('domain transfer crashed', [
                'domain' => $domain->domain,
                'type'   => $e::class,
            ]);

            return $this->failSubmit($domain, 'خطای غیرمنتظره در ثبتِ درخواستِ انتقال.');
        }
    }

    /** @return array{ok:bool, message:string, manual:bool} */
    private function doSubmit(Domain $domain, string $authCode): array
    {
        if (! $this->op->enabled()) {
            return $this->failSubmit($domain, 'اتصالِ رجیسترار پیکربندی نشده است.', manual: true);
        }

        if (trim($authCode) === '') {
            return $this->failSubmit($domain, 'کدِ انتقال (EPP) وارد نشده است.', manual: true);
        }

        $profile = $domain->customer?->defaultProfile();

        if (! $profile) {
            return $this->failSubmit($domain,
                'مشتری پروفایلِ مالک ندارد — بدونِ آن انتقالِ دامنه ممکن نیست.', manual: true);
        }

        $handle = $this->registrar->handleFor($profile);

        if (! $handle['ok']) {
            return $this->failSubmit($domain, $handle['message'], manual: true);
        }

        /*
        | 🔴 اگر انتقال از قبل نزدِ رجیسترار ثبت شده، دوباره سفارش نده.
        |
        | همان درسِ `zhina.shop`: تلاشِ قبلی ممکن است timeout خورده باشد در
        | حالی که آن طرف پذیرفته. بی‌این استعلام، سفارشِ دوم می‌رود و هزینهٔ
        | دوم هم پای ماست.
        */
        $found = $this->op->findDomain($domain->sld, $domain->tld);

        if ($found['ok'] && ($found['found'] ?? false)) {
            return $this->accept($domain, (array) ($found['data'] ?? []), $handle['handle']);
        }

        /*
        | نام‌سرور اختیاری است و عمداً خالی می‌رود اگر مشتری چیزی نداده باشد.
        |
        | ⚠️ برخلافِ **ثبت**، این‌جا نام‌سرورِ نداشتن فاجعه نیست: دامنه از قبل
        | زنده است و نام‌سرورِ فعلی‌اش کار می‌کند. تحمیلِ نام‌سرورِ ما یعنی
        | سایتِ مشتری در لحظهٔ انتقال **پایین می‌آید** — دقیقاً همان چیزی که
        | مشتری از انتقال نمی‌خواهد.
        */
        $ns = (array) ($domain->name_servers ?? []);

        $res = $this->op->transferDomain(
            name: $domain->sld,
            extension: $domain->tld,
            handle: $handle['handle'],
            authCode: $authCode,
            nameServers: count($ns) >= 2 ? $ns : [],
            period: max(1, (int) $domain->period_years),
        );

        if (! $res['ok']) {
            if (DomainRegistrar::isUnsignedContract((int) $res['code'])) {
                TldGate::block((string) $domain->tld, 'قراردادِ رجیستری امضا نشده است.');

                return $this->failSubmit($domain,
                    'قراردادِ رجیستریِ پسوندِ «.'.$domain->tld.'» امضا نشده است؛ '
                    .'تا امضا نشود انتقال ثبت نمی‌شود.', manual: true);
            }

            return $this->failSubmit($domain, $res['message'] ?: 'ثبتِ درخواستِ انتقال ناموفق بود.', manual: true);
        }

        $domain->forceFill([
            'op_id'                 => $res['id'] ?: $domain->op_id,
            'owner_handle'          => $handle['handle'],
            'transfer_status'       => 'submitted',
            'transfer_submitted_at' => now(),
            'transfer_checked_at'   => null,
            'provision_status'      => 'done',
            'provision_error'       => null,
            'status'                => Domain::STATUS_TRANSFERRING,
        ])->save();

        $this->tell($domain, 'domain_transfer_submitted',
            'درخواستِ انتقالِ «'.$domain->domain.'» ثبت شد. '
            .'تأییدِ رجیسترارِ فعلی معمولاً تا ۵ روزِ کاری طول می‌کشد و نتیجه را به شما اطلاع می‌دهیم.');

        return ['ok' => true, 'manual' => false, 'message' => ''];
    }

    // ═══════════════════════ پیگیری ═══════════════════════

    /**
     * وضعیتِ یک انتقالِ ثبت‌شده را از رجیسترار می‌پرسد.
     *
     * @return array{ok:bool, state:string, message:string}
     */
    public function poll(Domain $domain): array
    {
        // مهرِ زمان **پیش از** تماس زده می‌شود، نه بعدش.
        //
        // ⚠️ اگر بعد از تماس بزنیم، هر تماسِ شکست‌خورده مهر را جا می‌اندازد و
        // همان ردیف در اجرای بعدیِ کرون دوباره برداشته می‌شود — یعنی رجیسترارِ
        // خاموش باعثِ طوفانِ تماس می‌شود، دقیقاً وقتی نباید.
        $domain->forceFill(['transfer_checked_at' => now()])->save();

        if (! $this->op->enabled()) {
            return ['ok' => false, 'state' => 'submitted', 'message' => 'اتصالِ رجیسترار پیکربندی نشده است.'];
        }

        $found = $this->op->findDomain($domain->sld, $domain->tld);

        if (! $found['ok']) {
            // شکستِ خواندن هرگز «رد شد» خوانده نمی‌شود — همان قاعدهٔ ثبت‌شده
            return ['ok' => false, 'state' => 'submitted', 'message' => $found['message'] ?? 'استعلام ناموفق بود.'];
        }

        $remote = (array) ($found['data'] ?? []);
        $state  = strtolower(trim((string) (data_get($remote, 'status') ?: '')));

        if (($found['found'] ?? false) && in_array($state, self::REMOTE_ACTIVE, true)) {
            $this->accept($domain, $remote, (string) $domain->owner_handle);

            return ['ok' => true, 'state' => 'completed', 'message' => ''];
        }

        if (in_array($state, self::REMOTE_FAILED, true)) {
            $this->reject($domain, 'رجیسترارِ فعلی یا رجیستری، انتقال را نپذیرفت.');

            return ['ok' => true, 'state' => 'rejected', 'message' => ''];
        }

        // هنوز در جریان — ولی نه برای همیشه
        $since = $domain->transfer_submitted_at;

        if ($since instanceof Carbon && $since->lt(now()->subDays(self::MAX_WAIT_DAYS))) {
            $this->reject($domain,
                'انتقال در مهلتِ '.self::MAX_WAIT_DAYS.' روزه تأیید نشد.');

            return ['ok' => true, 'state' => 'rejected', 'message' => ''];
        }

        if ($state !== '' && ! in_array($state, self::REMOTE_PENDING, true)) {
            // وضعیتی که نمی‌شناسیم — نه موفق بخوانش نه ناموفق، فقط خبر بده
            ErrorTracker::noteOnce('domain', 'وضعیتِ ناشناختهٔ انتقال: '.$domain->domain, 3600, [
                'domain' => $domain->domain,
                'state'  => mb_substr($state, 0, 40),
            ]);
        }

        return ['ok' => true, 'state' => 'submitted', 'message' => ''];
    }

    // ═══════════════════════ پایان‌ها ═══════════════════════

    /**
     * انتقال کامل شد — دامنه حالا مالِ ماست.
     *
     * @param  array<string,mixed>  $remote
     * @return array{ok:bool, message:string, manual:bool}
     */
    private function accept(Domain $domain, array $remote, string $handle): array
    {
        TldGate::clear((string) $domain->tld);

        $expires = data_get($remote, 'expiration_date') ?: data_get($remote, 'expiration_date_time');

        /*
        | ⚠️ `registered_at` تاریخِ ثبتِ **اصلی** دامنه است، نه امروز.
        |
        | دامنهٔ منتقل‌شده سال‌ها عمر دارد و نوشتنِ `now()` رویش، سنِ دامنه را —
        | که یک سیگنالِ سئوی واقعی است — از دیدِ پنلِ مشتری پاک می‌کند. اگر
        | رجیسترار تاریخ نداد، `null` می‌مانَد؛ «نمی‌دانیم» بهتر از تاریخِ غلط.
        */
        $created = $this->parseDate(data_get($remote, 'creation_date') ?: data_get($remote, 'creation_date_time'));

        $domain->forceFill([
            'status'           => 'active',
            'transfer_status'  => 'completed',
            'provision_status' => 'done',
            'provision_error'  => null,
            'op_id'            => data_get($remote, 'id') ?: $domain->op_id,
            'owner_handle'     => $handle ?: $domain->owner_handle,
            'registered_at'    => $domain->registered_at ?: $created,
            'expires_at'       => $this->parseDate($expires) ?: $domain->expires_at,
        ])->save();

        $this->tell($domain, 'domain_transfer_completed',
            'انتقالِ دامنهٔ «'.$domain->domain.'» با موفقیت انجام شد و از این پس در پنلِ شما مدیریت می‌شود.');

        return ['ok' => true, 'manual' => false, 'message' => ''];
    }

    /**
     * انتقال نشد — پول باید برگردد.
     *
     * 🔴 برگرداندنِ اعتبار **بی‌قیدوشرط** است و پیش از هر اعلانی انجام می‌شود.
     * اگر اعلان شکست بخورد نباید پولِ مشتری پیشِ ما بماند؛ عکسش قابلِ تحمل است.
     */
    public function reject(Domain $domain, string $why): void
    {
        $domain->forceFill([
            'transfer_status'  => 'rejected',
            'status'           => 'cancelled',
            'provision_status' => 'none',
            'provision_error'  => mb_substr($why, 0, 500),
        ])->save();

        $refunded = $this->refund($domain, $why);

        $this->tell($domain, 'domain_transfer_rejected',
            'انتقالِ دامنهٔ «'.$domain->domain.'» انجام نشد. '.$why
            .($refunded ? ' مبلغِ پرداختی به اعتبارِ حسابِ شما بازگردانده شد.' : ''));

        try {
            app(\App\Services\Notify\AdminNotifier::class)->event(
                'انتقالِ دامنه انجام نشد',
                ['دامنه' => $domain->domain, 'علت' => mb_substr($why, 0, 160),
                 'بازگشتِ وجه' => $refunded ? 'انجام شد' : 'انجام نشد'],
                url('/admin/domains'),
                '🌐',
            );
        } catch (\Throwable $e) {
            Log::warning('اعلانِ ردِ انتقال نرفت', ['err' => $e->getMessage()]);
        }
    }

    /**
     * بازگرداندنِ مبلغ به اعتبارِ مشتری.
     *
     * 🔴 مبلغ از **فاکتورِ واقعاً پرداخت‌شده** می‌آید، نه از `price_toman`:
     * آن ستون مالیات ندارد و برگرداندنش یعنی هر انتقالِ ردشده دقیقاً به‌اندازهٔ
     * مالیات کم‌رفاند می‌شد — همان اختلافی که ممیزی بینِ دو مسیرِ رفاند پیدا
     * کرد. `price_toman` فقط پشتیبانِ ردیفِ دستیِ بی‌فاکتور است.
     *
     * ⚠️ فقط وقتی واقعاً پولی گرفته شده باشد؛ ردیفِ اعتبارِ صفر فقط دفتر را
     * شلوغ می‌کند.
     */
    private function refund(Domain $domain, string $why): bool
    {
        $invoice = \App\Models\Invoice::where('domain_id', $domain->id)
            ->where('kind', 'domain')
            ->where('paid', '>', 0)
            ->orderByDesc('id')
            ->first();

        $amount = (int) ($invoice?->paid ?? $domain->price_toman);

        if ($amount <= 0 || ! $domain->customer) {
            return false;
        }

        try {
            /*
            | ⚠️ `balance_after` باید **همان لحظه** از جمعِ دفتر خوانده شود، نه
            | از یک ستونِ موجودی: موجودی در این پروژه جمعِ سطرهاست و ستونِ
            | جداگانه‌ای ندارد. قفلِ ردیفِ مشتری هم لازم است تا دو بازگشتِ
            | هم‌زمان دو `balance_after`ِ یکسان ننویسند.
            */
            DB::transaction(function () use ($domain, $invoice, $amount, $why) {
                \App\Models\Customer::whereKey($domain->customer_id)->lockForUpdate()->first();

                /*
                | ⚠️ محافظِ «دو بار برنگردان» — `reject()` عمومی است و حالا
                | `domains:resolve-stuck` هم صدایش می‌زند؛ بی‌این، هر مسیرِ
                | دوم یک اعتبارِ تازه می‌ساخت.
                */
                $already = \App\Models\CreditEntry::where('customer_id', $domain->customer_id)
                    ->where('reason', 'domain_transfer_refund')
                    ->where('source_type', Domain::class)
                    ->where('source_id', $domain->id)
                    ->exists();

                if ($already) {
                    return;
                }

                $balance = (int) \App\Models\CreditEntry::where('customer_id', $domain->customer_id)
                    ->where('currency_code', 'IRT')->sum('amount');

                \App\Models\CreditEntry::create([
                    'customer_id'   => $domain->customer_id,
                    'currency_code' => 'IRT',
                    'amount'        => $amount,
                    'balance_after' => $balance + $amount,
                    'reason'        => 'domain_transfer_refund',
                    'source_type'   => Domain::class,
                    'source_id'     => $domain->id,
                    'note'          => 'بازگشتِ وجهِ انتقالِ ناموفقِ '.$domain->domain.' — '.mb_substr($why, 0, 120),
                ]);

                // ⚠️ فاکتور حذف نمی‌شود: سابقهٔ مالی و مالیاتی باید بماند.
                $invoice?->forceFill(['status' => 'refunded'])->save();
            });

            return true;
        } catch (\Throwable $e) {
            /*
            | 🔴 شکستِ بازگشتِ وجه هرگز بی‌صدا نمی‌مانَد. مشتری پول داده و
            | چیزی نگرفته؛ اگر این خطا گم شود، تنها کسی که خبردار می‌شود
            | خودِ مشتری است — و آن‌وقت دیگر بحثِ اعتماد است نه بحثِ باگ.
            */
            ErrorTracker::note('domain', $e, [
                'domain' => $domain->domain,
                'action' => 'transfer_refund',
                'amount' => $amount,
            ]);

            return false;
        }
    }

    private function failSubmit(Domain $domain, string $message, bool $manual = false): array
    {
        $domain->forceFill([
            'transfer_status'  => $manual ? 'failed' : 'pending',
            'provision_status' => $manual ? 'manual' : 'pending',
            'provision_tries'  => (int) $domain->provision_tries + 1,
            'provision_error'  => mb_substr($message, 0, 500),
        ])->save();

        if ($manual) {
            try {
                ErrorTracker::noteOnce('domain', 'درخواستِ انتقال ثبت نشد: '.$domain->domain, 900, [
                    'domain' => $domain->domain,
                    'reason' => mb_substr($message, 0, 160),
                ]);
            } catch (\Throwable) {
                // ردیاب هرگز مسیرِ اصلی را نمی‌شکند
            }
        }

        return ['ok' => false, 'manual' => $manual, 'message' => $message];
    }

    private function tell(Domain $domain, string $event, string $text): void
    {
        try {
            app(\App\Services\Notify\Notifier::class)->fire(
                $event,
                $domain->customer,
                ['domain' => $domain->domain, 'until' => sdate($domain->fresh()?->expires_at) ?: '—'],
                $text,
                [],
                url('/admin/domains'),
                '🌐',
            );
        } catch (\Throwable $e) {
            ErrorTracker::note('notify', $e, ['event' => $event, 'domain' => $domain->domain]);
        }
    }

    private function parseDate(mixed $v): ?Carbon
    {
        if (blank($v)) {
            return null;
        }

        try {
            return Carbon::parse((string) $v);
        } catch (\Throwable) {
            return null;
        }
    }
}
