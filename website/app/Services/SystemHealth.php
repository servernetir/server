<?php

namespace App\Services;

use App\Support\ErrorTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * سلامتِ سامانه — یک جا که می‌گوید «همه‌چیز درست است» یا **دقیقاً** چه چیزی نیست.
 *
 * ═══ چرا این لازم شد ═══
 *
 * ردیابِ خطا نشان داد امروز ۱۳ بار `Connection refused` روی MariaDB خورده‌ایم.
 * هرکدام یک دقیقهٔ کرونِ مرده است — و کرون همان چیزی است که سرور تحویل می‌دهد،
 * دامنه ثبت می‌کند و فاکتورِ تمدید می‌فرستد. هیچ‌کس خبردار نشد.
 *
 * الگوی تکرارشوندهٔ این پروژه همین است: کاری که باید خودکار انجام شود، بی‌صدا
 * انجام **نمی‌شود** و ماه‌ها بعد از روی شکایتِ مشتری کشف می‌شود. این کلاس آن
 * سکوت را می‌شکند.
 *
 * ⚠️ هیچ چکی این‌جا استثنا پرتاب نمی‌کند: صفحهٔ سلامت باید دقیقاً وقتی کار کند
 * که چیزی خراب است.
 */
class SystemHealth
{
    /** اگر کرون بیش از این دقیقه ساکت باشد، یعنی نمی‌دود */
    public const CRON_SILENT_MINUTES = 10;

    /** فایلِ ضربان — عمداً **فایل** است نه کش */
    public const HEARTBEAT = 'cron-heartbeat';

    /**
     * بیش از این دقیقه در صف = گیر کرده.
     *
     * ⚠️ از قفلِ کهنهٔ ۱۵ دقیقه‌ای بزرگ‌تر است تا تلاشِ دوبارهٔ عادی هشدار نسازد:
     * پایشگری که برای کارِ سالم زنگ بزند، از دومین هفته نادیده گرفته می‌شود.
     */
    public const STUCK_MINUTES = 30;

    /**
     * @return array<int,array{key:string,ok:bool,level:string,title:string,detail:string}>
     */
    public function checks(): array
    {
        return [
            $this->cron(),
            $this->database(),
            $this->stuckDomains(),
            $this->stuckServices(),
            $this->undeliveredCloud(),
            $this->cloudRelease(),
            $this->recentErrors(),
        ];
    }

    /**
     * بدترین وضعیت در یک مجموعه چک: fail > warn > ok.
     *
     * چک‌ها را **ورودی** می‌گیرد و خودش دوباره صداشان نمی‌زند — وگرنه هر
     * فراخوان یک دورِ کاملِ پرس‌وجو و خواندنِ فایل بود، و بدتر: دو دورِ جدا
     * می‌توانستند نتیجهٔ متفاوت بدهند و متن با شدت نخوانَد.
     *
     * @param  array<int,array<string,mixed>>  $checks
     */
    public static function worst(array $checks): string
    {
        $levels = array_column($checks, 'level');

        return in_array('fail', $levels, true) ? 'fail'
            : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }

    // ───────────────────────── چک‌ها ─────────────────────────

    /**
     * 🔴 مهم‌ترین چک.
     *
     * ⚠️ ضربان از **فایل** خوانده می‌شود نه کش، و این عمدی است: کش روی همان
     * دیتابیسی است که گاهی جواب نمی‌دهد. ضربانی که با مرگِ دیتابیس می‌میرد،
     * نمی‌تواند خبر دهد که دیتابیس مرده — دقیقاً همان لحظه‌ای که به آن نیاز
     * داریم، ساکت می‌شود.
     */
    private function cron(): array
    {
        $at = $this->heartbeatAt();

        if ($at === null) {
            return $this->row('cron', false, 'fail', 'زمان‌بند',
                'هیچ اجرایی ثبت نشده. یا کرونِ سرور تنظیم نیست یا از اولین اجرا خطا داده.');
        }

        $mins = (int) $at->diffInMinutes(now());

        if ($mins > self::CRON_SILENT_MINUTES) {
            return $this->row('cron', false, 'fail', 'زمان‌بند',
                'آخرین اجرا '.fa_num($mins).' دقیقه پیش بود. تحویلِ سرور، ثبتِ دامنه و '
                .'فاکتورِ تمدید همگی روی همین کرون‌اند و الان متوقف‌اند.');
        }

        return $this->row('cron', true, 'ok', 'زمان‌بند',
            'آخرین اجرا '.fa_num($mins).' دقیقه پیش.');
    }

    private function database(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (\Throwable $e) {
            return $this->row('db', false, 'fail', 'دیتابیس',
                'پاسخ نمی‌دهد: '.mb_substr($e->getMessage(), 0, 120));
        }

        // ⚠️ «وصل است» کافی نیست: قطعیِ گذرا هم کرون را می‌کشد و در ردیاب
        // می‌ماند. پس تاریخچه هم دیده می‌شود.
        $recent = $this->countErrors(fn ($e) => str_contains((string) ($e['class'] ?? ''), 'QueryException'), 24);

        if ($recent > 0) {
            return $this->row('db', false, 'warn', 'دیتابیس',
                'الان وصل است، ولی در ۲۴ ساعت گذشته '.fa_num($recent).' بار قطع شده. '
                .'هر قطعی یعنی یک دقیقهٔ کرونِ ازدست‌رفته.');
        }

        return $this->row('db', true, 'ok', 'دیتابیس', 'وصل و پاسخ‌گو.');
    }

    /**
     * دامنه‌ای که پول گرفته‌ایم و ثبت نشده.
     *
     * ⚠️ از **همان اسکوپی** می‌پرسد که کرون برمی‌دارد (`awaitingRegistration`).
     * اگر شرط را این‌جا دست‌نویس تکرار می‌کردیم، روزی که تعریفِ صف عوض شود این
     * چک بی‌صدا کهنه می‌شد و می‌گفت «چیزی گیر نکرده» در حالی که صف پر بود —
     * یعنی یک پایشگرِ دروغ‌گو، که از نبودِ پایش بدتر است.
     *
     * ⚠️ `provision_status='none'` هم عمداً بیرون است: ردیفِ دامنهٔ
     * **پرداخت‌نشده** همان است و در صفِ تحویل نیست.
     */
    private function stuckDomains(): array
    {
        if (! Schema::hasTable('domains')) {
            return $this->row('domains', true, 'ok', 'صفِ دامنه', 'جدولِ دامنه هنوز ساخته نشده.');
        }

        $manual = \App\Models\Domain::where('provision_status', 'manual')->count();
        $old = \App\Models\Domain::query()->awaitingRegistration()
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->count();

        // 🔴 انقضا هم این‌جاست، و نبودنش یک کوریِ واقعی بود: ممیزی نشان داد
        //    هیچ چیزی در سامانه انقضای دامنه را **هُل** نمی‌داد؛ فقط یک تبِ
        //    خاموش در پنلِ مدیر بود که کسی باید سراغش می‌رفت. دامنه‌ای که
        //    ۷ روز دیگر می‌میرد باید خودش داد بزند.
        $soon = \App\Models\Domain::query()->expiringWithin(7)->count();

        if ($manual > 0 || $old > 0 || $soon > 0) {
            $level = ($old > 0 || $soon > 0) ? 'fail' : 'warn';

            return $this->row('domains', false, $level, 'صفِ دامنه',
                ($old > 0 ? fa_num($old).' دامنهٔ پرداخت‌شده بیش از نیم‌ساعت ثبت نشده — یعنی صف پیش نمی‌رود. ' : '')
                .($soon > 0 ? fa_num($soon).' دامنه تا ۷ روز دیگر منقضی می‌شود. ' : '')
                .($manual > 0 ? fa_num($manual).' دامنه منتظرِ بررسیِ دستیِ شماست.' : ''));
        }

        return $this->row('domains', true, 'ok', 'صفِ دامنه', 'چیزی گیر نکرده.');
    }

    /** سرویسی که پول گرفته‌ایم و تحویل نشده — همان پرس‌وجوی `provision:run` */
    private function stuckServices(): array
    {
        if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'provision_status')) {
            return $this->row('services', true, 'ok', 'صفِ تحویل', 'ستونِ تحویل هنوز ساخته نشده.');
        }

        $manual = \App\Models\Service::where('provision_status', 'manual')->count();

        /*
        | 🔴 سن از `created_at` سنجیده می‌شود، نه `updated_at`.
        |
        | قفلِ اتمیِ تحویل یک `Builder::update()` است و لاراول خودش
        | `updated_at` را می‌نویسد. یعنی **هر دقیقه** که کرون سرویسِ گیرکرده را
        | برمی‌داشت، ساعتش صفر می‌شد و هرگز به آستانهٔ نیم‌ساعت نمی‌رسید.
        |
        | نتیجه روی یک اتفاقِ کاملاً روزمره (ناموجودیِ موقتِ یک پلن): مشتری پول
        | داده، سروری ندارد، صف بسته، و این صفحه **سبز** — دقیقاً همان کوری‌ای
        | که این لایه برای شکستنش ساخته شد، از درِ دیگر.
        |
        | ⚠️ سرویسِ مرده کنار می‌رود: سفارشِ لغوشده نباید تا ابد قرمز نگه دارد.
        |    امضای نگهبان شاملِ کلیدِ چکِ خراب است، پس یک قرمزِ دائمی یعنی
        |    خرابیِ **واقعیِ بعدی** هیچ اعلانی تولید نمی‌کند.
        */
        $old = \App\Models\Service::whereNotIn('status', \App\Models\Service::DEAD_STATUSES)
            ->whereIn('provision_status', ['pending', 'running'])
            ->where('created_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->count();

        /*
        | 🔴 `failed` هم شمرده می‌شود، و نبودنش بدترین کوریِ این چک بود.
        |
        | `provision:run` فقط `pending` و `running`ِ کهنه را برمی‌دارد، پس یک
        | سرویسِ `failed` **هرگز** خودبه‌خود دوباره تلاش نمی‌شود. تا حالا این
        | صفحه با خیالِ راحت می‌گفت «چیزی گیر نکرده» در حالی که مشتری پول داده
        | بود و سرورش هرگز ساخته نمی‌شد.
        |
        | ⚠️ عمداً «تلاشِ خودکارِ دوباره» اضافه **نشد**: نقطهٔ شکست ممکن است
        | بعد از خریدِ واقعیِ سرور باشد و تلاشِ کور یعنی دو بار خریدن. راهِ درست
        | این است که آدم ببیندش و تصمیم بگیرد — پس فقط بلندش می‌کنیم.
        */
        // ⚠️ سرویسِ مرده کنار می‌رود، به همان دلیلِ بالا: لغوِ وجه‌برگشته یک
        //    ردیفِ `failed` باقی می‌گذارد و بی‌این شرط، این چک برای همیشه قرمز
        //    می‌مانْد و امضای نگهبان دیگر هرگز عوض نمی‌شد.
        $failed = \App\Models\Service::whereNotIn('status', \App\Models\Service::DEAD_STATUSES)
            ->where('provision_status', 'failed')->count();

        if ($manual > 0 || $old > 0 || $failed > 0) {
            $level = ($old > 0 || $failed > 0) ? 'fail' : 'warn';

            return $this->row('services', false, $level, 'صفِ تحویل',
                ($old > 0 ? fa_num($old).' سرویس بیش از نیم‌ساعت در صف مانده — مشتری پول داده و سرور ندارد. ' : '')
                .($failed > 0 ? fa_num($failed).' سرویس در تحویل شکست خورده و هیچ کرونی دوباره تلاش نمی‌کند — خودتان «تلاش دوباره» بزنید. ' : '')
                .($manual > 0 ? fa_num($manual).' سرویس منتظرِ تحویلِ دستیِ شماست.' : ''));
        }

        return $this->row('services', true, 'ok', 'صفِ تحویل', 'چیزی گیر نکرده.');
    }

    /**
     * 🔴 سرورِ ابریِ پول‌داده که واقعاً به دستِ مشتری نرسیده.
     *
     * ═══ چرا این چک لازم شد ═══
     *
     * `stuckServices()` بالا از `provision_status` می‌پرسد. ولی
     * `CloudProvisioner::finalize()` همان لحظه‌ای که زیرساخت **سفارش** را
     * می‌پذیرد `done` می‌نویسد — پیش از اینکه شناسهٔ سرور، IP یا ایمیلی وجود
     * داشته باشد. پس یک تحویلِ کاملاً ناتمام، از دیدِ آن چک `done` است و صف
     * **سبز** می‌مانَد.
     *
     * دقیقاً همین رخ داد: مشتری سرورِ ساعتی خرید، پول رفت، ماشین در پنلِ
     * زیرساخت ساخته شد و اجاره‌اش از حسابِ ما کم می‌شود — و در پنلِ ما نه سروری
     * تحویل شد نه ایمیلی رفت نه **یک خط خطا** ثبت شد. کارفرما فقط چون خودش
     * پنلِ زیرساخت را باز کرد فهمید.
     *
     * ⚠️ این چک عمداً از `provision_status` نمی‌پرسد. از همان چیزی می‌پرسد که
     * مشتری می‌بیند: شناسهٔ واقعیِ سرور، IP، و ایمیلِ رفته. برچسبِ داخلی هرچه
     * باشد بی‌ربط است — همان درسِ `whereNotNull('server_id')` در CLAUDE.md:
     * پرس‌وجوی ناظر باید خودِ خرابی را ببیند، نه همسایه‌اش.
     */
    private function undeliveredCloud(): array
    {
        try {
            $stalled = \App\Services\Cloud\CloudDeliveryWatch::stalled();
        } catch (\Throwable $e) {
            // ⚠️ «نتوانستم بپرسم» با «چیزی نیست» یکی نیست. سبز برگرداندن این‌جا
            //    یعنی همان سکوتی که این چک برای شکستنش ساخته شد.
            return $this->row('cloud_delivery', false, 'warn', 'تحویلِ سرورِ ابری',
                'وضعیتِ تحویل خوانده نشد: '.mb_substr($e->getMessage(), 0, 120));
        }

        if ($stalled->isEmpty()) {
            return $this->row('cloud_delivery', true, 'ok', 'تحویلِ سرورِ ابری',
                'هر سرورِ پرداخت‌شده‌ای تحویل شده.');
        }

        $reasons = [];
        foreach ($stalled as $s) {
            $why = \App\Services\Cloud\CloudDeliveryWatch::reasonFor($s) ?? '—';
            $reasons[$why] = ($reasons[$why] ?? 0) + 1;
        }

        $detail = [];
        foreach ($reasons as $why => $n) {
            $detail[] = fa_num($n).'× '.$why;
        }

        return $this->row('cloud_delivery', false, 'fail', 'تحویلِ سرورِ ابری',
            fa_num($stalled->count()).' سرویسِ ابری پول گرفته و تحویل نشده (سرویسِ '
            .implode('، ', $stalled->pluck('id')->take(5)->map(fn ($i) => '#'.fa_num($i))->all())
            .'). '.implode(' · ', $detail)
            .' — ⚠️ ممکن است ماشینش نزدِ زیرساخت ساخته شده و اجاره‌اش از حسابِ ما برود.');
    }

    /**
     * 🔴 «مشتری بسته، ماشین شاید هنوز زنده است.»
     *
     * این تنها چکی است که هزینهٔ **ما** را می‌بیند، نه تجربهٔ مشتری را: سرویسی که
     * صورت‌حسابش بسته شده (وضعیتِ مرده) ولی زیرساخت حذفش را تأیید نکرده. تا
     * مرداد ۱۴۰۵ تنها ردِ چنین ردیفی یک ستونِ `last_error` بود؛ `CloudInventory`
     * هم آن را «متصل» می‌شمرد نه «یتیم»، پس هیچ صفحه‌ای چیزی غیرعادی نشان
     * نمی‌داد و اولین خبر، صورت‌حسابِ ماهانهٔ زیرساخت بود که فقط جمعِ کل را
     * می‌گوید.
     *
     * ⚠️ شناسهٔ سرویس‌ها در متن می‌آید تا **امضای وضعیت عوض شود**: چکِ همیشه‌قرمز
     * با متنِ ثابت یعنی وقتی ردیفِ تازه‌ای اضافه شود هیچ اعلانی نمی‌رود (اعلان
     * فقط روی تغییرِ وضعیت است) — همان توهمِ پایش که بدتر از نبودِ هشدار است.
     */
    private function cloudRelease(): array
    {
        try {
            if (! Schema::hasTable('services') || ! Schema::hasColumn('services', 'provision_status')) {
                return $this->row('cloud_release', true, 'ok', 'آزادسازیِ سرور', 'روی این نصب فعال نیست.');
            }

            $rows = \App\Models\Service::query()->awaitingRelease()
                ->orderBy('id')->limit(50)->get(['id', 'name']);
        } catch (\Throwable $e) {
            // «نتوانستم بپرسم» با «چیزی نیست» یکی نیست.
            return $this->row('cloud_release', false, 'warn', 'آزادسازیِ سرور',
                'صفِ آزادسازی خوانده نشد: '.mb_substr($e->getMessage(), 0, 120));
        }

        if ($rows->isEmpty()) {
            return $this->row('cloud_release', true, 'ok', 'آزادسازیِ سرور',
                'هر سرویسِ بسته‌شده‌ای نزدِ زیرساخت هم آزاد شده.');
        }

        return $this->row('cloud_release', false, 'fail', 'آزادسازیِ سرور',
            fa_num($rows->count()).' سرویسِ بسته‌شده هنوز نزدِ زیرساخت آزاد نشده (سرویسِ '
            .$rows->pluck('id')->take(10)->map(fn ($i) => '#'.fa_num($i))->implode('، ')
            .'). هزینه‌اش پای ماست: ماشین ممکن است زنده باشد و مشتری دیگر پولی نمی‌دهد. '
            .'کرونِ cloud:release-retry هر ساعت دوباره تلاش می‌کند؛ اگر ماند، در پنلِ زیرساخت دستی پاکش کنید.');
    }

    private function recentErrors(): array
    {
        $n = $this->countErrors(fn ($e) => ($e['type'] ?? '') === 'error', 24);

        if ($n > 50) {
            return $this->row('errors', false, 'fail', 'خطاها',
                fa_num($n).' خطا در ۲۴ ساعت گذشته.');
        }

        if ($n > 0) {
            return $this->row('errors', false, 'warn', 'خطاها',
                fa_num($n).' خطا در ۲۴ ساعت گذشته.');
        }

        return $this->row('errors', true, 'ok', 'خطاها', 'چیزی ثبت نشده.');
    }

    // ───────────────────────── کمکی ─────────────────────────

    public function heartbeatAt(): ?\Illuminate\Support\Carbon
    {
        try {
            $path = storage_path('app/'.self::HEARTBEAT);

            if (! File::exists($path)) {
                return null;
            }

            return \Illuminate\Support\Carbon::parse(trim(File::get($path)));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param callable(array):bool $match */
    private function countErrors(callable $match, int $hours): int
    {
        try {
            $since = now()->subHours($hours);
            $n = 0;

            foreach (ErrorTracker::recent(400, 'error') as $e) {
                if (! $match($e)) {
                    continue;
                }
                if (! empty($e['at']) && \Illuminate\Support\Carbon::parse($e['at'])->lt($since)) {
                    continue;
                }
                $n++;
            }

            return $n;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function row(string $key, bool $ok, string $level, string $title, string $detail): array
    {
        return compact('key', 'ok', 'level', 'title', 'detail');
    }
}
