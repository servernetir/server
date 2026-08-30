<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Finance\BusinessLedger;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * اجارهٔ ماهانهٔ سرورها را در دفترِ مالی ثبت می‌کند.
 *
 * ═══ چرا این فرمان وجود دارد ═══
 *
 * درآمد در `business_ledger` **خودکار** ثبت می‌شود (از هر پرداخت)، ولی هزینه
 * تا امروز فقط دستی بود. یعنی «سودِ خالص» در `/admin/finance` این بود:
 *
 *     درآمدِ واقعی  −  هزینه‌ای که یادمان مانده وارد کنیم
 *
 * و همیشه به نفعِ خوش‌بینی خطا می‌داد. روی دادهٔ واقعیِ همین سایت، حاشیهٔ سود
 * ۹۶٫۷٪ نشان داده می‌شد چون کلِ هزینهٔ ماه یک ردیفِ دستی بود.
 *
 * ═══ 🔴 قاعده‌های این فرمان ═══
 *
 * **هیچ عددِ حدسی ثبت نمی‌شود.** سرورِ بی‌مبلغ رد می‌شود، سرورِ رایگان رد
 * می‌شود، و اگر نرخِ ارز در دسترس نباشد آن سرور **رد می‌شود** — نه اینکه صفر
 * ثبت شود. ردیفِ صفر در دفتر یعنی «این ماه رایگان بود»، که دروغ است و بدتر
 * از نبودِ ردیف؛ چون ایندکسِ یکتا بعداً جای همان ماه را اشغال می‌کند و ثبتِ
 * درستش دیگر ممکن نیست.
 *
 * **ماهِ گذشته ثبت نمی‌شود.** فقط ماهِ جاری، و فقط پس از رسیدنِ روزِ
 * صورت‌حساب. اجاره‌ای که هنوز سررسید نشده هزینهٔ این ماه نیست.
 *
 * ⚠️ **نرخِ ارز همین امروز است.** هیچ نرخِ تاریخی‌ای در این پروژه ذخیره
 * نمی‌شود، پس ردیفِ ثبت‌شده نرخِ روزِ ثبت را دارد و `note` صریح می‌گویدش.
 * برای همین هم فرمان **روزانه** می‌دود نه ماهانه: هرچه به روزِ صورت‌حساب
 * نزدیک‌تر، نرخ واقعی‌تر.
 *
 * ⚠️ **دوباره‌اجرا بی‌خطر است.** کلیدِ یکتای (kind, category, period, ref_id)
 * در دیتابیس نشسته؛ تکیه بر «قبلاً چک کردم» در کدِ مالی کافی نیست.
 */
class PostServerRent extends Command
{
    protected $signature = 'servers:post-rent
        {--dry : فقط بگو چه ثبت می‌شد، چیزی ننویس}
        {--month= : ماهِ دلخواه به شکل YYYY-MM (پیش‌فرض: ماهِ جاری)}';

    protected $description = 'ثبتِ اجارهٔ ماهانهٔ سرورها در دفترِ مالی';

    public function handle(BusinessLedger $ledger): int
    {
        if (! Schema::hasTable('servers') || ! Schema::hasColumn('servers', 'monthly_cost')) {
            $this->warn('ستون‌های هزینهٔ سرور هنوز ساخته نشده‌اند؛ کاری انجام نشد.');

            return self::SUCCESS;
        }

        if (! $ledger->supportsPeriods()) {
            $this->warn('دفترِ مالی ستونِ دوره ندارد (مهاجرت اجرا نشده)؛ کاری انجام نشد.');

            return self::SUCCESS;
        }

        /*
        | ⚠️ حافظهٔ نرخ در **هر اجرا** خالی می‌شود.
        |
        | در پروداکشن هر اجرای کرون یک پروسهٔ تازه است و این خط بی‌اثر به‌نظر
        | می‌رسد — ولی Artisan فرمان‌ها را در کانتینر singleton نگه می‌دارد،
        | پس دو فراخوان در یک پروسه (تست، یا `schedule:run`ی که دو بار صدا
        | بزند) نرخِ کهنه را می‌دیدند. یعنی نرخی که موقتاً در دسترس نبود، تا
        | پایانِ آن پروسه صفر می‌مانْد و ماه بی‌دلیل رد می‌شد.
        */
        $this->rates = [];

        $dry = (bool) $this->option('dry');
        $month = (string) ($this->option('month') ?: now()->format('Y-m'));

        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            $this->error('قالبِ ماه باید YYYY-MM باشد.');

            return self::FAILURE;
        }

        $posted = $skipped = $already = $failed = 0;

        foreach (Server::orderBy('name')->get() as $server) {
            $r = $this->postOne($ledger, $server, $month, $dry);

            match ($r) {
                'posted'  => $posted++,
                'already' => $already++,
                'failed'  => $failed++,
                default   => $skipped++,
            };
        }

        $this->info(($dry ? '[آزمایشی] ' : '')
            ."ثبت‌شده: {$posted} · از قبل بود: {$already} · رد شد: {$skipped}"
            .($failed > 0 ? " · ناموفق: {$failed}" : ''));

        /*
        | 🔴 کدِ خروجیِ ناموفق وقتی ثبتی شکست خورده.
        |
        | بی‌این، خروجیِ «۵ ردیف از قبل بود» و «۵ ردیف اصلاً نوشته نشد» یکسان
        | بودند — و دومی یعنی هزینهٔ آن ماه برای همیشه از دفتر غایب است.
        */
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @var array<string,int> نرخِ هر ارز — یک بار در هر اجرا */
    private array $rates = [];

    /**
     * نرخِ یک ارز، **یک بار** برای کلِ اجرا.
     *
     * 🔴 بی‌این، هر سرور نرخِ خودش را می‌گرفت. و `ExchangeRate::refresh()` روی
     * شکست هیچ‌چیز کش نمی‌کند، پس با منبعِ ارزِ خاموش هر فراخوان یک
     * `Http::timeout(12)->retry(2)` است: ده سرور ≈ ۲۴۵ ثانیه انسدادِ **داخلِ
     * `schedule:run`** — یعنی بقیهٔ کارهای آن دقیقه هم پشتش می‌مانند.
     *
     * دقیقاً همان تله‌ای که docblockِ `Server::monthlyCostToman()` هشدارش را
     * می‌دهد و `BusinessReport::rateFor()` برایش ساخته شد؛ این فراخوان جا
     * انداخته بودش.
     */
    private function rateFor(string $currency): int
    {
        $currency = strtoupper($currency ?: 'EUR');

        if (! array_key_exists($currency, $this->rates)) {
            try {
                $this->rates[$currency] = $currency === 'EUR'
                    ? (int) app(\App\Services\Cloud\CloudPricing::class)->eurToToman()
                    : (int) (app(\App\Services\ExchangeRate::class)->toToman($currency) ?: 0);
            } catch (\Throwable) {
                $this->rates[$currency] = 0;
            }
        }

        return $this->rates[$currency];
    }

    /** @return 'posted'|'already'|'skipped'|'failed' */
    private function postOne(BusinessLedger $ledger, Server $server, string $month, bool $dry): string
    {
        // مبلغ وارد نشده ⇒ «نمی‌دانم». حدس نمی‌زنیم.
        if (! $server->hasCost()) {
            $this->line("— {$server->name}: مبلغ وارد نشده");

            return 'skipped';
        }

        // صفر ⇒ واقعاً رایگان. ردیفِ صفر در دفتر معنایی ندارد.
        if ((int) $server->monthly_cost === 0) {
            return 'skipped';
        }

        [$y, $m] = array_map('intval', explode('-', $month));
        $day = $server->billing_day !== null && $server->billing_day >= 1 && $server->billing_day <= 28
            ? (int) $server->billing_day
            : 1;

        $due = Carbon::create($y, $m, $day, 0, 0, 0);

        /*
        | 🔴 اجاره‌ای که هنوز سررسید نشده، هزینهٔ این ماه نیست.
        |
        | بی‌این شرط، فرمان در روزِ اولِ ماه اجارهٔ کلِ ماه را ثبت می‌کرد و
        | «سودِ این ماه» تا روزِ صورت‌حساب مصنوعاً پایین می‌مانْد.
        */
        if ($due->isFuture()) {
            return 'skipped';
        }

        // ⚠️ نرخِ **صفر** پاس داده می‌شود نه null: null یعنی «خودت بگیر» و
        //    همان بود که به‌ازای هر سرور یک درخواستِ مسدودکننده می‌ساخت.
        $toman = $server->monthlyCostToman($this->rateFor((string) $server->cost_currency));

        /*
        | 🔴 نرخ نبود ⇒ **رد**، نه صفر.
        |
        | ردیفِ صفر یعنی «این ماه رایگان بود» — دروغ. و بدتر: ایندکسِ یکتا جای
        | همان ماه را اشغال می‌کند و اجرای بعدی که نرخ دارد دیگر نمی‌تواند
        | عددِ درست را بنشاند. رد کردن برگشت‌پذیر است، ثبتِ غلط نه.
        */
        if ($toman === null || $toman <= 0) {
            $this->warn("⚠️ {$server->name}: نرخِ ارز در دسترس نبود؛ رد شد");

            /*
            | ⚠️ فقط در اجرای واقعی. `--dry` قول داده «چیزی ننویس»، و
            | `noteOnce` هم ردیفِ خطا می‌نویسد هم گلوگاهِ یک‌ساعته را می‌سوزاند
            | — یعنی یک پیش‌نمایشِ ساعتِ ۶:۲۰ می‌توانست تنها هشدارِ ماندگارِ
            | اجرای ۶:۴۰ را خفه کند.
            |
            | امضا شاملِ **کدام** سرور است؛ وگرنه سرورِ دومی که همین مشکل را
            | بگیرد پشتِ گلوگاهِ اولی پنهان می‌مانْد.
            */
            if (! $dry) {
                ErrorTracker::noteOnce('finance',
                    'اجارهٔ سرور «'.$server->name.'» برای '.$month.' ثبت نشد: نرخِ ارز در دسترس نبود.',
                    3600, ['server' => $server->id, 'month' => $month]);
            }

            return 'skipped';
        }

        if ($dry) {
            /*
            | پیش‌نمایش باید همان چیزی را بگوید که اجرای واقعی می‌کند.
            | نسخهٔ اول همیشه «ثبت می‌شود» می‌گفت، حتی وقتی ماه از قبل ثبت شده
            | بود — پس دقیقاً همان سؤالی که مدیر با `--dry` می‌پرسد («این ماه
            | نوشته شده یا نه؟») بی‌جواب می‌مانْد. این پرس‌وجو فقط می‌خوانَد.
            */
            $exists = \App\Models\BusinessEntry::where('kind', 'expense')
                ->where('category', 'server')->where('period', $month)
                ->where('ref_id', $server->id)->exists();

            $this->line(($exists ? '= ' : '✓ ')."{$server->name}: "
                .fa_num(number_format($toman)).' ت ('.$month.')');

            return $exists ? 'already' : 'posted';
        }

        $currency = strtoupper((string) ($server->cost_currency ?: 'EUR'));
        $note = 'اجارهٔ ماهانهٔ سرور «'.$server->name.'» — '.$month
            .($currency !== 'IRT'
                ? ' · '.number_format($server->monthly_cost / 100, 2).' '.$currency.' با نرخِ روزِ ثبت'
                : '');

        $entry = $ledger->recordServerRent((int) $server->id, $month, $toman, $due, $note);

        /*
        | 🔴 `null` این‌جا یعنی **ثبت نشد**، نه «از قبل بود».
        |
        | همهٔ راه‌های بی‌خطرِ null بالاتر گرفته شده‌اند (مبلغ، دوره، جدول)، و
        | خودِ متد روی برخوردِ یکتایی ردیفِ موجود را برمی‌گردانَد. پس رسیدن به
        | این‌جا یعنی نوشتن واقعاً شکست خورده — و باید صدا کند، نه اینکه شبیهِ
        | یک ماهِ سالم گزارش شود.
        */
        if ($entry === null) {
            $this->error("✗ {$server->name}: ثبت نشد (جزئیات در /admin/errors)");

            return 'failed';
        }

        // `wasRecentlyCreated` تنها راهِ تشخیصِ «تازه ساخته شد» از «از قبل بود»
        if (! $entry->wasRecentlyCreated) {
            return 'already';
        }

        $this->line("✓ {$server->name}: ".fa_num(number_format($toman)).' ت');

        return 'posted';
    }
}
