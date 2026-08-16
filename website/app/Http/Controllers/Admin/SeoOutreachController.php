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
    /** سقفِ هر فهرست — تا یک paste اشتباهی هزار سایت را بررسی نکند. */
    public const MAX_LIST = 200;

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

    /** ۲‑الف) فهرست را بساز — هیچ تماسِ شبکه‌ای این‌جا نیست. */
    public function importList(Request $request): JsonResponse
    {
        $data = $this->check($request, ['list' => 'required|string|max:20000']);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        abort_unless(Schema::hasTable('outreach_contacts'), 503);

        $batch = Str::lower(Str::random(10));
        $added = 0;
        $skipped = [];

        foreach ($this->parseList($data['list']) as [$host, $email]) {
            if ($added >= self::MAX_LIST) {
                $skipped[] = __('ui.sx_over_limit', ['max' => self::MAX_LIST]);
                break;
            }

            // کسی که یک بار گفته «نفرست»، دوباره وارد فهرست نمی‌شود
            if (OutreachContact::isSuppressed($email)) {
                $skipped[] = $email.' — '.__('ui.sx_skip_unsub');
                continue;
            }

            // همان دامنه برای همان ایمیل دو بار در فهرست نباشد
            if (OutreachContact::where('host', $host)->where('email', $email)->exists()) {
                $skipped[] = $email.' — '.__('ui.sx_skip_dup');
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

        return response()->json(['ok' => true, 'added' => $added, 'skipped' => $skipped]);
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

        foreach ($rows as $d) {
            if ($added >= self::MAX_LIST) {
                $skipped[] = __('ui.sx_over_limit', ['max' => self::MAX_LIST]);
                break;
            }

            $host = Str::lower((string) $d->domain);
            $email = Str::lower(trim((string) ($d->customer->email ?? '')));

            if ($host === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (OutreachContact::isSuppressed($email)) {
                $skipped[] = $email.' — '.__('ui.sx_skip_unsub');
                continue;
            }
            if (OutreachContact::where('host', $host)->where('email', $email)->exists()) {
                $skipped[] = $host.' — '.__('ui.sx_skip_dup');
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
            'skipped' => array_slice($skipped, 0, 30),
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
     * «دامنه, ایمیل» در هر خط. جداکننده کاما یا سمی‌کالن یا تب.
     *
     * ⚠️ خطی که ایمیل نداشته باشد **رد** می‌شود، نه اینکه با ایمیلِ حدسی
     * (info@دامنه) پر شود. حدس‌زدنِ نشانی یعنی فرستادن به صندوقی که شاید مالِ
     * آن کسب‌وکار نباشد.
     */
    private function parseList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/[,;\t]+/', $line, 2);
            if (count($parts) < 2) {
                continue;
            }

            $host = Str::lower(trim($parts[0]));
            $email = Str::lower(trim($parts[1]));

            $host = preg_replace('#^https?://#i', '', (string) $host);
            $host = trim((string) preg_replace('#/.*$#', '', (string) $host));

            if ($host === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $out[] = [$host, $email];
        }

        return $out;
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
