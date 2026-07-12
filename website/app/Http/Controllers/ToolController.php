<?php

namespace App\Http\Controllers;

use App\Services\DomainTools;
use App\Services\SiteAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ابزارهای رایگان: بررسی سئو/سلامت سایت، Whois، بررسی IP،
 * جلسات آنلاین (پروموت meet.servernet.cloud) و اپ‌ساز.
 */
class ToolController extends Controller
{
    private const PAGES = ['seo', 'whois', 'ip', 'meet', 'app-builder'];

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
        ]);
    }

    /** POST /api/audit — بررسی سئو و سلامت سایت */
    public function audit(Request $request, SiteAudit $audit): JsonResponse
    {
        $data = $request->validate(['url' => 'required|string|max:255']);

        return response()->json($audit->run($data['url']));
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
