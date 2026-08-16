<?php

namespace App\Http\Controllers;

use App\Models\AuditReport;
use App\Services\DomainTools;
use App\Services\SiteAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * ابزارهای رایگان: بررسی سئو/سلامت سایت، Whois، بررسی IP،
 * جلسات آنلاین (پروموت meet.servernet.cloud) و اپ‌ساز.
 */
class ToolController extends Controller
{
    private const PAGES = ['seo', 'whois', 'ip', 'meet', 'app-builder', 'domain-ideas'];

    public function show(string $slug, Request $request): View
    {
        abort_unless(in_array($slug, self::PAGES, true), 404);

        $prefill = null;
        if ($slug === 'ip') {
            $prefill = $request->header('CF-Connecting-IP')
                ?? $request->header('X-Forwarded-For')
                ?? $request->ip();
            $prefill = trim(explode(',', (string) $prefill)[0]);
        }

        return view('pages.tool', [
            'slug'    => $slug,
            'prefill' => $prefill,
            'seo'     => $this->seo($slug),
        ]);
    }

    /**
     * محتوای سئوی صفحهٔ ابزار (مقدمه، گام‌ها، پرسش‌های متداول) در زبان جاری.
     *
     * از `resources/content/tools-seo.php` خوانده می‌شود. اسلاگی که هنوز محتوا
     * ندارد آرایهٔ خالی می‌گیرد و قالب آن بخش را اصلاً رندر نمی‌کند — پس پرکردنِ
     * تدریجیِ صفحات هیچ صفحهٔ دیگری را نمی‌شکند.
     */
    private function seo(string $slug): array
    {
        static $all = null;
        if ($all === null) {
            $file = resource_path('content/tools-seo.php');
            $all = is_file($file) ? (array) require $file : [];
        }

        $entry = $all[$slug][app()->getLocale()] ?? $all[$slug]['fa'] ?? [];

        return [
            'intro' => $entry['intro'] ?? '',
            'steps' => $entry['steps'] ?? [],
            'faq'   => $entry['faq'] ?? [],
        ];
    }

    /** POST /api/audit — بررسی سئو و سلامت سایت */
    public function audit(Request $request, SiteAudit $audit): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|max:255']);

        $result = $audit->run($data['url']);

        /*
         * گزارش ذخیره می‌شود تا نشانیِ اشتراکیِ خودش را داشته باشد.
         *
         * ⚠️ ذخیره‌سازی هرگز نباید خودِ ابزار را بشکند: اگر جدول روی این نصب
         * نباشد یا نوشتن شکست بخورد، بازدیدکننده باید همچنان گزارشش را ببیند.
         * پس نتیجه بی‌`report_url` برمی‌گردد و مرورگر بخشِ اشتراک را اصلاً
         * نشان نمی‌دهد — نه خطا، نه دکمهٔ مرده.
         */
        if (($result['ok'] ?? false) === true) {
            try {
                if (Schema::hasTable('audit_reports')) {
                    $report = AuditReport::fromAudit($result, 'tool');
                    if ($report) {
                        $result['report_url'] = $report->url();
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json($result);
    }

    /**
     * POST /api/domain-ideas — پیشنهاد نام دامنه با AI (fallback محلی).
     *
     * اعتبارسنجی دستی و پاسخ همیشه JSON: این روت سه نسخه‌ی زبان‌دار دارد
     * (/en/api/… و /tr/api/…) که الگوی `api/*` در bootstrap آن‌ها را نمی‌گیرد،
     * پس `$request->validate()` خطایش را به‌جای JSON با ریدایرکت HTML می‌داد.
     */
    public function ideas(Request $request, \App\Services\DomainIdeas $ideas): JsonResponse
    {
        $desc = trim((string) $request->input('description', ''));
        if (mb_strlen($desc) < 10) {
            return response()->json(['ok' => false, 'error' => 'too_short']);
        }

        return response()->json($ideas->suggest(mb_substr($desc, 0, 300)));
    }

    /** POST /api/whois */
    public function whois(Request $request, DomainTools $tools): JsonResponse
    {
        $data = $request->validate(['domain' => 'required|string|max:100']);

        return response()->json($tools->whois($data['domain']));
    }

    /** POST /api/ip */
    public function ip(Request $request, DomainTools $tools): JsonResponse
    {
        $data = $request->validate(['ip' => 'nullable|string|max:100']);
        $ip = $data['ip'] ?? '';
        if ($ip === '') {
            $ip = $request->header('CF-Connecting-IP')
                ?? trim(explode(',', (string) $request->header('X-Forwarded-For'))[0])
                ?: $request->ip();
        }

        return response()->json($tools->ipInfo($ip));
    }
}
