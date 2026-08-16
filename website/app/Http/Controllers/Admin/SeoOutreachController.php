<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AuditReportMail;
use App\Models\AuditReport;
use App\Models\OutreachContact;
use App\Services\SiteAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * بررسیِ سایت + ارسالِ گزارش — «/admin/seo».
 *
 * دو کار:
 *  ۱) یک گزارش را برای **یک نفر** بفرست (مشتریِ خودمان یا یک سرنخ)
 *  ۲) کمپین: فهرستِ «دامنه + ایمیل» بگیر، همه را بررسی کن، بعد از **تأییدِ
 *     مدیر** ایمیل بفرست
 *
 * 🔴 چرا بررسی و ارسال هرکدام **یکی‌یکی** و از مرورگر رانده می‌شوند:
 * هر بررسی چند ثانیه طول می‌کشد (TLS، چند پرس‌وجوی DNS، دو کاوشِ جانبی). یک
 * درخواستِ وب که ۵۰ سایت را پشتِ‌هم بررسی کند، پشتِ Cloudflare قطع می‌شود و
 * مدیر نمی‌داند کدام‌ها انجام شده‌اند. حلقه در مرورگر است، پس هر درخواست کوتاه
 * می‌مانَد، پیشرفت دیده می‌شود، و قطعِ وسطِ کار فقط یک ردیف را عقب می‌اندازد.
 *
 * 🔴 و چرا کرون نشد: این کار **اعلانِ ناخواسته به آدم‌های واقعی** می‌فرستد.
 * کارِ زمان‌بندی‌شده یعنی روزی بی‌حضورِ کسی راه بیفتد. تصمیمِ فرستادن باید هر
 * بار مالِ یک انسانِ حاضر باشد.
 */
class SeoOutreachController extends Controller
{
    /**
     * سقفِ هر فهرست — تا یک paste اشتباهی هزار سایت را بررسی نکند.
     *
     * ⚠️ ۳۰۰ است نه ۲۰۰، چون تقریباً یک‌پنجمِ سایت‌های یک فهرستِ واقعی اصلاً بالا
     * نمی‌آیند (روی نمونهٔ ۲۳تایی: ۵ تا). کسی که ۲۰۰ **هدفِ قابلِ‌استفاده**
     * می‌خواهد باید حدودِ ۲۵۰ ردیف وارد کند؛ سقفِ ۲۰۰ دقیقاً همان‌جا می‌بُرید.
     */
    public const MAX_LIST = 300;

    /** سقفِ خطِ یک «رکوردِ شرکت». بزرگ‌تر از این دیگر رکورد نیست — پایین را بخوان. */
    private const MAX_BLOCK_LINES = 12;

    /**
     * نشانی‌هایی که دامنه‌شان مالِ خودِ کسب‌وکار نیست، پس سایت را نمی‌شود از
     * رویشان فهمید. فهرست عمداً کوتاه است: هرچه بلندتر شود، احتمالِ اینکه دامنهٔ
     * یک کسب‌وکارِ واقعی را اشتباهاً «رایگان» بخوانیم بیشتر می‌شود.
     */
    private const FREE_MAIL = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'rocketmail.com',
        'yahoo.co.uk', 'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com',
        'msn.com', 'aol.com', 'icloud.com', 'me.com', 'mail.com', 'gmx.com',
        'zoho.com', 'yandex.com', 'yandex.ru', 'mail.ru', 'protonmail.com',
        'proton.me', 'chmail.ir', 'mailfa.com',
    ];

    /** پسوندهایی که دامنه نیستند — تا `logo.png` وسطِ متن «سایت» خوانده نشود. */
    private const NOT_A_TLD = [
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'pdf', 'zip', 'rar',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'mp3', 'mp4',
        'html', 'htm', 'php', 'asp', 'aspx', 'css', 'js', 'json', 'xml',
    ];

    public function index(Request $request): View
    {
        $ready = Schema::hasTable('outreach_contacts') && Schema::hasTable('audit_reports');

        $contacts = $ready
            ? OutreachContact::with('report')->latest('id')->limit(300)->get()
            : collect();

        return view('admin.seo', [
            'ready'    => $ready,
            'contacts' => $contacts,
            'stats'    => [
                'pending' => $contacts->where('status', 'pending')->whereNotNull('audit_report_id')->count(),
                'toScan'  => $contacts->where('status', 'pending')->whereNull('audit_report_id')->count(),
                'sent'    => $contacts->where('status', 'sent')->count(),
                'failed'  => $contacts->where('status', 'failed')->count(),
                'unsub'   => $contacts->whereNotNull('unsubscribed_at')->count(),
            ],
        ]);
    }

    /**
     * ۱) بررسیِ یک سایت و ارسالِ گزارش به یک نشانی — کاربردِ «برای مشتری بفرست».
     *
     * یک بررسی و یک ایمیل، پس در همان درخواست انجام می‌شود.
     */
    public function sendOne(Request $request, SiteAudit $audit): JsonResponse
    {
        $data = $this->check($request, [
            'url'   => 'required|string|max:255',
            'email' => 'required|email|max:190',
            'note'  => 'nullable|string|max:1000',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        abort_unless(Schema::hasTable('audit_reports'), 503);

        if (OutreachContact::isSuppressed($data['email'])) {
            return response()->json(['ok' => false, 'error' => 'unsubscribed'], 422);
        }

        $result = $audit->run($data['url']);
        if (($result['ok'] ?? false) !== true) {
            return response()->json(['ok' => false, 'error' => $result['error'] ?? 'unreachable'], 422);
        }

        $report = AuditReport::fromAudit($result, 'admin', $request->user()?->id);
        if (! $report) {
            return response()->json(['ok' => false, 'error' => 'not_saved'], 500);
        }

        try {
            Mail::to($data['email'])->send(new AuditReportMail(
                report: $report,
                reportUrl: $report->url(),
                outreach: false,
                unsubscribeUrl: null,
                note: $data['note'] ?? null,
                locale: $report->locale,
            ));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => 'mail_failed', 'detail' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok'    => true,
            'host'  => $report->host,
            'score' => $report->score,
            'url'   => $report->url(),
        ]);
    }

    /**
     * ۲‑الف) فهرست را بساز — هیچ تماسِ شبکه‌ای این‌جا نیست.
     *
     * 🔴 علتِ ردشدنِ هر ردیف **ساختاری** برمی‌گردد (`why`+`what`)، نه به‌صورتِ
     * جملهٔ آماده. دو دلیل: متنِ فارسیِ این صفحه همه در `admin-seo.js` است و
     * دوپاره‌کردنش یعنی روزی یکی از دو نیمه ترجمه بماند؛ و مهم‌تر، مرورگر باید
     * بتواند ردشده‌ها را **دسته‌بندی‌شده** نشان دهد. تا امروز فقط تعدادشان چاپ
     * می‌شد و مدیر نمی‌فهمید چرا از ۲۵۰ سطر ۱۶۰ تا وارد شده.
     */
    public function importList(Request $request): JsonResponse
    {
        $data = $this->check($request, ['list' => 'required|string|max:60000']);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        abort_unless(Schema::hasTable('outreach_contacts'), 503);

        [$pairs, $orphans] = $this->parseList($data['list']);

        $batch = Str::lower(Str::random(10));
        $added = 0;
        $over = false;
        $skipped = [];

        foreach ($pairs as [$host, $email]) {
            if ($added >= self::MAX_LIST) {
                $over = true;
                break;
            }

            // کسی که یک بار گفته «نفرست»، دوباره وارد فهرست نمی‌شود
            if (OutreachContact::isSuppressed($email)) {
                $skipped[] = ['why' => 'unsub', 'what' => $email];
                continue;
            }

            // همان دامنه برای همان ایمیل دو بار در فهرست نباشد
            if (OutreachContact::where('host', $host)->where('email', $email)->exists()) {
                $skipped[] = ['why' => 'dup', 'what' => $host];
                continue;
            }

            OutreachContact::create([
                'host'       => $host,
                'email'      => $email,
                'batch'      => $batch,
                'created_by' => $request->user()?->id,
            ]);
            $added++;
        }

        // ایمیلی که سایتش معلوم نشد: نه واردش می‌کنیم نه پنهانش. مدیر می‌تواند
        // سایتش را دستی کنارش بگذارد و همان چند سطر را دوباره بچسباند.
        foreach ($orphans as $email) {
            $skipped[] = ['why' => 'nosite', 'what' => $email];
        }

        return response()->json([
            'ok'           => true,
            'added'        => $added,
            'found'        => count($pairs),
            'over'         => $over,
            'max'          => self::MAX_LIST,
            'skipped'      => array_slice($skipped, 0, 60),
            'skippedTotal' => count($skipped),
        ]);
    }

    /**
     * ۲‑الف‑۲) ساختِ فهرست از **دیتای خودمان** — بهترین منبعی که داریم.
     *
     * 🔴 چرا این از هر فهرستِ بیرونی بهتر است، و چرا اصلاً ساخته شد:
     * کسی که دامنه‌اش را از ما ثبت کرده ولی جای دیگری هاست است، سه چیز دارد که
     * یک غریبه ندارد — ایمیلش را **قانوناً** داریم (مشتریِ خودمان است، نه
     * نشانی‌ای که از جایی برداشته باشیم)، از قبل به ما اعتماد کرده، و پیامِ ما
     * برایش ربط دارد نه مزاحمت: «دامنه‌ات پیشِ ماست، سایتت این ایرادها را دارد».
     *
     * ⚠️ همان محافظ‌های فهرستِ دستی این‌جا هم اجرا می‌شوند (لغوِ اشتراک، تکراری،
     * سقف). نبودشان یعنی مشتری‌ای که یک‌بار گفته «نفرست» از این درِ دیگر دوباره
     * ایمیل بگیرد.
     */
    public function importOwn(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('outreach_contacts'), 503);

        if (! Schema::hasTable('domains') || ! Schema::hasTable('services')) {
            return response()->json(['ok' => false, 'error' => 'no_source'], 422);
        }

        $batch = 'own-'.Str::lower(Str::random(6));
        $added = 0;
        $skipped = [];

        /*
         * «دامنه پیشِ ماست ولی هاستش جای دیگری است» =
         *   دامنهٔ زنده  +  مشتری‌اش هیچ سرویسِ زنده‌ای ندارد.
         *
         * ⚠️ «زنده» از `Service::DEAD_STATUSES` می‌آید نه از فهرستِ دست‌نویس؛
         * وگرنه روزی وضعیتِ تازه‌ای اضافه می‌شود و این پرس‌وجو بی‌صدا کهنه
         * می‌شود — همان تلهٔ ثبت‌شدهٔ «پرس‌وجوی موازی» در SystemHealth.
         */
        $rows = \App\Models\Domain::query()
            ->alive()
            ->with('customer:id,email,status')
            ->whereHas('customer', fn ($q) => $q->whereNotNull('email'))
            ->whereDoesntHave('customer.services', fn ($q) => $q->whereNotIn('status', \App\Models\Service::DEAD_STATUSES))
            ->orderByDesc('id')
            ->limit(self::MAX_LIST * 2)
            ->get();

        $over = false;

        foreach ($rows as $d) {
            if ($added >= self::MAX_LIST) {
                $over = true;
                break;
            }

            $host = Str::lower((string) $d->domain);
            $email = Str::lower(trim((string) ($d->customer->email ?? '')));

            if ($host === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (OutreachContact::isSuppressed($email)) {
                $skipped[] = ['why' => 'unsub', 'what' => $email];
                continue;
            }
            if (OutreachContact::where('host', $host)->where('email', $email)->exists()) {
                $skipped[] = ['why' => 'dup', 'what' => $host];
                continue;
            }

            OutreachContact::create([
                'host' => $host, 'email' => $email,
                'batch' => $batch, 'created_by' => $request->user()?->id,
            ]);
            $added++;
        }

        return response()->json([
            'ok' => true, 'added' => $added, 'candidates' => $rows->count(),
            'over' => $over, 'max' => self::MAX_LIST,
            'skipped' => array_slice($skipped, 0, 60), 'skippedTotal' => count($skipped),
        ]);
    }

    /** ۲‑ب) بررسیِ **یک** ردیفِ بی‌گزارش. مرورگر تا تمام‌شدن صدا می‌زند. */
    public function scanNext(Request $request, SiteAudit $audit): JsonResponse
    {
        abort_unless(Schema::hasTable('outreach_contacts'), 503);

        $contact = OutreachContact::whereNull('audit_report_id')
            ->where('status', 'pending')
            ->orderBy('id')
            ->first();

        if (! $contact) {
            return response()->json(['ok' => true, 'done' => true]);
        }

        $result = $audit->run($contact->host);

        if (($result['ok'] ?? false) !== true) {
            // سایتی که بالا نمی‌آید گزارشی ندارد که بفرستیم — و «۰ از ۱۰۰»
            // فرستادن برای کسی که سایتش فقط لحظه‌ای در دسترس نبوده، توهین است.
            $contact->update([
                'status' => 'failed',
                'error'  => (string) ($result['error'] ?? 'unreachable'),
            ]);

            return response()->json([
                'ok' => true, 'done' => false, 'host' => $contact->host,
                'status' => 'failed', 'error' => $contact->error,
            ]);
        }

        $report = AuditReport::fromAudit($result, 'outreach', $request->user()?->id);
        $contact->update(['audit_report_id' => $report?->id]);

        return response()->json([
            'ok' => true, 'done' => false, 'host' => $contact->host,
            'status' => 'scanned', 'score' => $report?->score, 'issues' => $report?->issueCount(),
        ]);
    }

    /** ۲‑ج) ارسالِ **یک** ردیفِ تأییدشده. */
    public function sendNext(Request $request): JsonResponse
    {
        $data = $this->check($request, ['ids' => 'required|array|max:'.self::MAX_LIST, 'ids.*' => 'integer']);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        abort_unless(Schema::hasTable('outreach_contacts'), 503);

        $contact = OutreachContact::with('report')
            ->whereIn('id', $data['ids'])
            ->where('status', 'pending')
            ->whereNotNull('audit_report_id')
            ->orderBy('id')
            ->first();

        if (! $contact) {
            return response()->json(['ok' => true, 'done' => true]);
        }

        if (! $contact->isSendable()) {
            $contact->update(['status' => 'skipped', 'error' => 'suppressed']);

            return response()->json(['ok' => true, 'done' => false, 'id' => $contact->id,
                'email' => $contact->email, 'status' => 'skipped']);
        }

        try {
            Mail::to($contact->email)->send(new AuditReportMail(
                report: $contact->report,
                reportUrl: $contact->report->url(),
                outreach: true,
                unsubscribeUrl: $contact->unsubscribeUrl(),
                note: null,
                locale: $contact->report->locale,
            ));
            $contact->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
            $status = 'sent';
        } catch (\Throwable $e) {
            report($e);
            $contact->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 400)]);
            $status = 'failed';
        }

        return response()->json(['ok' => true, 'done' => false, 'id' => $contact->id,
            'email' => $contact->email, 'status' => $status, 'error' => $contact->error]);
    }

    /**
     * از متنِ چسبانده‌شده جفت‌های «سایت + ایمیل» را درمی‌آورد.
     *
     * 🔴 **ایمیل هرگز حدس زده نمی‌شود** — نه `info@` می‌سازیم و نه از روی دامنه
     * سرِهم می‌کنیم. نشانیِ ساختگی یعنی نامه به صندوقی که شاید اصلاً مالِ آن
     * کسب‌وکار نباشد.
     *
     * ولی **عکسش** بی‌خطر است و همان چیزی است که این بازنویسی را لازم کرد: وقتی
     * نشانیِ واقعیِ `info@ariansanat.com` را جلوی چشممان گذاشته‌اند، دامنهٔ خودِ
     * همان نشانی قطعاً سایتِ همان کسب‌وکار است. پس ورودیِ کمینه یک **ستونِ
     * ایمیل** است، نه یک CSVِ دوستونیِ دست‌ساز.
     *
     * چرا مهم است: فهرستِ واقعی از صفحهٔ وب **کپی** می‌شود، نه از یک فایلِ تمیز.
     * پارسرِ قبلی هر خطی را که دقیقاً «دامنه، ایمیل» نبود بی‌صدا دور می‌ریخت،
     * یعنی رسیدن به ۲۵۰ ردیف به ۲۵۰ بار ویرایشِ دستی نیاز داشت. سه شکل حالا
     * خوانده می‌شود:
     *   ۱) `example.com, info@example.com`   ← قالبِ قبلی، دست‌نخورده
     *   ۲) `شرکت…<TAB>۰۲۱…<TAB>example.com<TAB>info@example.com`
     *   ۳) رکوردِ چندخطی که با **خطِ خالی** از رکوردِ بعدی جدا شده
     *
     * @return array{0: list<array{0:string,1:string}>, 1: list<string>}
     *         جفت‌ها، و نشانی‌هایی که سایتشان معلوم نشد. اینها **دور ریخته
     *         نمی‌شوند، گزارش می‌شوند** — سکوت یعنی مدیر فکر کند ۲۵۰ ردیف وارد
     *         شده در حالی که ۹۰ تا افتاده.
     */
    private function parseList(string $raw): array
    {
        $pairs = [];
        $orphans = [];
        $seen = [];

        foreach ($this->blocksIn($raw) as $lines) {
            $parsed = [];
            foreach ($lines as $line) {
                $emails = $this->emailsIn($line);
                $parsed[] = [$emails, $this->sitesIn($line, $emails)];
            }

            /*
             * سایتِ پشتیبانِ رکورد — برای وقتی ایمیل روی یک خط است و سایت روی
             * خطِ دیگر (چیدمانِ کارتیِ فهرست‌های شرکتی). چون بلوک با خطِ خالی
             * بسته می‌شود، ترتیبِ «اول سایت» یا «اول ایمیل» فرقی نمی‌کند.
             *
             * 🔴 ولی «بلوک» و «رکوردِ یک شرکت» یکی نیستند، و اشتباه‌گرفتنشان
             * بدترین خرابیِ ممکنِ این صفحه است: سایتِ ردیفِ اول به ایمیلِ رایگانِ
             * ردیف‌های بعدی می‌چسبد و ده‌ها نفر گزارشِ سایتِ یک نفرِ **دیگر** را
             * می‌گیرند — با کدِ ۲۰۰ و بی‌هیچ خطایی.
             *
             * تشخیص از روی **تعدادِ ایمیل** است نه تعدادِ خط: رکوردِ یک شرکت یک
             * نشانی دارد و بقیهٔ خطوطش نام و تلفن و نشانی است؛ فهرستی که چند
             * ایمیل دارد، چند شرکت است حتی اگر کوتاه باشد. (شمارشِ خط به‌تنهایی
             * کافی نبود — یک CSVِ چهارخطی زیرِ هر سقفِ معقولی می‌مانَد.)
             *
             * سقفِ خط به‌عنوانِ محافظِ دوم می‌مانَد: صفحهٔ «تماس با ما»یی که یک
             * ایمیل و یک منویِ پر از لینک دارد، نباید لینکِ اولش سایت خوانده شود.
             */
            $emailCount = array_sum(array_map(fn ($p) => count($p[0]), $parsed));

            $fallback = null;
            if ($emailCount === 1 && count($lines) <= self::MAX_BLOCK_LINES) {
                foreach ($parsed as [, $sites]) {
                    if ($sites) {
                        $fallback = $sites[0];
                        break;
                    }
                }
            }

            foreach ($parsed as [$emails, $sites]) {
                foreach ($emails as $email) {
                    // ترتیب عمدی: سایتِ همان خط ← دامنهٔ خودِ نشانی ← سایتِ رکورد.
                    // «دامنهٔ خودِ نشانی» بالاتر از پشتیبان است تا یک پشتیبانِ
                    // اشتباه نتواند ردیفی را که خودش گویاست خراب کند.
                    $host = $sites[0] ?? $this->hostOfEmail($email) ?? $fallback;

                    if ($host === null) {
                        $orphans[] = $email;
                        continue;
                    }

                    $key = $host.'|'.$email;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $pairs[] = [$host, $email];
                }
            }
        }

        return [$pairs, array_values(array_unique($orphans))];
    }

    /** متن را با خطِ خالی به رکوردها می‌شکند؛ خطِ خالی نداشت، یک بلوکِ بزرگ. */
    private function blocksIn(string $raw): array
    {
        $blocks = [];
        $current = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($current) {
                    $blocks[] = $current;
                    $current = [];
                }

                continue;
            }
            $current[] = $line;
        }
        if ($current) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /** نشانی‌های معتبرِ داخلِ یک متن، یکتا و با حروفِ کوچک. */
    private function emailsIn(string $text): array
    {
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m);

        $out = [];
        foreach ($m[0] as $e) {
            $e = Str::lower($e);
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[$e] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * دامنه‌هایی که در متن آمده‌اند و **بخشی از یک ایمیل نیستند**.
     *
     * ⚠️ نشانی‌ها اول از متن برداشته می‌شوند، وگرنه `example.com`ِ داخلِ
     * `info@example.com` یک بار دیگر به‌عنوانِ سایت شمرده می‌شود و هر خطی که
     * فقط ایمیل دارد ظاهراً سایت هم دارد.
     */
    private function sitesIn(string $text, array $emails): array
    {
        foreach ($emails as $e) {
            $text = str_ireplace($e, ' ', $text);
        }

        preg_match_all(
            '~(?:[A-Za-z][A-Za-z0-9+.\-]*://)?(?:[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?\.)+[A-Za-z]{2,}(?:/\S*)?~',
            $text,
            $m
        );

        $out = [];
        foreach ($m[0] as $raw) {
            $host = $this->cleanHost($raw);
            if ($host !== null) {
                $out[$host] = true;
            }
        }

        return array_keys($out);
    }

    /** `https://www.Example.com/path?x=1` → `example.com`؛ اگر دامنه نبود، `null`. */
    private function cleanHost(string $raw): ?string
    {
        $host = Str::lower(trim($raw));
        $host = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~', '', $host);
        $host = (string) preg_replace('~[/?\#].*$~', '', $host);
        $host = trim($host, '.');
        $host = (string) preg_replace('~^www\.~', '', $host);

        if (! str_contains($host, '.')) {
            return null;
        }

        $tld = Str::afterLast($host, '.');

        return in_array($tld, self::NOT_A_TLD, true) || strlen($tld) < 2
            ? null
            : $host;
    }

    /** دامنهٔ خودِ نشانی — مگر ارائه‌دهندهٔ رایگان که دربارهٔ سایت چیزی نمی‌گوید. */
    private function hostOfEmail(string $email): ?string
    {
        $domain = Str::lower(Str::afterLast($email, '@'));

        return in_array($domain, self::FREE_MAIL, true) ? null : $this->cleanHost($domain);
    }

    /**
     * 🔴 اعتبارسنجیِ دستی — تلهٔ ثبت‌شدهٔ این پروژه.
     *
     * `bootstrap/app.php` می‌گوید JSON فقط برای `api/*`، پس
     * `$request->validate()` روی `/admin/*` یک ۳۰۲ به صفحهٔ قبل می‌دهد نه ۴۲۲،
     * و `fetch` یک صفحهٔ HTML می‌گیرد و `r.json()` می‌ترکد.
     */
    private function check(Request $request, array $rules): array|JsonResponse
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'ok'       => false,
                'error'    => 'validation',
                'messages' => $validator->errors()->all(),
            ], 422);
        }

        return $validator->validated();
    }
}
