<?php

namespace App\Http\Controllers;

use App\Services\NetworkTools;
use App\Services\WebProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * مجموعه ابزار DNS و شبکه — هر نوع بررسی یک صفحه‌ی سئوشده‌ی مستقل
 * روی /lookup/{type} با محتوای سه‌زبانه از config/lookup.php.
 */
class LookupController extends Controller
{
    public function __construct(
        private NetworkTools $net,
        private WebProbe $probe,
        private \App\Services\CheckHost $checkHost,
    ) {}

    /** پیش‌فرض /lookup → رکورد A */
    public function index(Request $request): View
    {
        return $this->show('a', $request);
    }

    /** صفحه‌ی یک نوع بررسی */
    public function show(string $type, Request $request): View
    {
        $types = config('lookup.types');
        abort_unless(isset($types[$type]), 404);

        $prefill = '';
        if (($types[$type]['input'] ?? '') === 'ip') {
            $prefill = $request->header('CF-Connecting-IP')
                ?? trim(explode(',', (string) $request->header('X-Forwarded-For'))[0])
                ?: $request->ip();
        }

        return view('pages.lookup', [
            'type'    => $type,
            'cfg'     => $types[$type],
            'prefill' => $prefill,
            /*
            | 🔴 canonical می‌گوید صفحه **چیست**، نه از کجا درخواست شده.
            |
            | `index()` همین متد را با `'a'` صدا می‌زند، پس `/lookup` و
            | `/lookup/a` بایت‌به‌بایت یک صفحه‌اند (سنجیده شد: هر دو ۷۳۰۴
            | کاراکتر، همان عنوان). با canonicalِ پیش‌فرضِ لایوت
            | (`url()->current()`) هرکدام **خودش** را canonical اعلام می‌کرد —
            | یعنی دو آدرسِ یکسان که برای یک کوئری با هم رقابت می‌کنند، در هر
            | سه زبان. گوگل یکی را انتخاب می‌کند و ممکن است اشتباه انتخاب کند.
            |
            | چون از روی `$type` ساخته می‌شود نه از روی URL، خودبه‌خود درست
            | می‌مانَد: هر مسیرِ تازه‌ای هم که روزی به همین متد برسد، به صفحهٔ
            | واقعیِ همان ابزار canonical می‌شود.
            */
            'canonical' => lroute('lookup', $type),
        ]);
    }

    /** POST /api/lookup — اجرای بررسی و بازگرداندن JSON */
    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'  => 'required|string|max:20',
            'query' => 'nullable|string|max:255',
            // فقط طول را محدود می‌کنیم. اعتبارسنجی معنایی با parsePorts است که
            // تنها رقم و بازه را بیرون می‌کشد و بقیه را دور می‌ریزد — پس ورودی
            // نامعتبر خطرناک نیست، ولی باید پیام راهنمای خودمان را بگیرد نه
            // خطای خام لاراول که ترجمه هم نمی‌شود.
            'ports' => ['nullable', 'string', 'max:200'],
        ]);

        $types = config('lookup.types');
        $type = $data['type'];
        if (! isset($types[$type])) {
            return response()->json(['ok' => false, 'error' => 'unknown_type'], 422);
        }

        $q = trim($data['query'] ?? '');
        $cfg = $types[$type];

        // برای reverse اگر خالی بود، IP خود کاربر
        if ($q === '' && ($cfg['input'] ?? '') === 'ip') {
            $q = $request->header('CF-Connecting-IP')
                ?? trim(explode(',', (string) $request->header('X-Forwarded-For'))[0])
                ?: $request->ip();
        }
        if ($q === '') {
            return response()->json(['ok' => false, 'error' => 'empty']);
        }

        $result = match ($cfg['kind']) {
            'dns'         => $this->net->dns($q, $cfg['rr']),
            'dnssec'      => $this->net->dnssec($q),
            'propagation' => $this->net->propagation($q, $request->input('rr', 'A')),
            'reverse'     => $this->net->reverse($q),
            'ssl'         => $this->net->ssl($q),
            // فهرست پورت دلخواه؛ خود سرویس اعتبارسنجی و محدود می‌کند
            'ports'       => $this->net->ports($q, (string) $request->input('ports', '')),
            'ping'        => $this->net->ping($q),
            'email'       => $this->net->emailHealth($q),
            'blacklist'   => $this->net->blacklist($q),
            'speed'       => $this->probe->speed($q),
            'headers'     => $this->probe->headers($q),
            'redirects'   => $this->probe->redirects($q),
            'access'      => $this->probe->iranAccess($q),
            'chping'      => $this->checkHost->ping($q),
            'chhttp'      => $this->checkHost->http($q),
            'cwv'         => $this->probe->pagespeed($q),
            default       => ['ok' => false, 'error' => 'unknown_kind'],
        };

        return response()->json($result);
    }

    /** صفحه‌ی ابزار جامع (dns | network) — همه‌ی زیرابزارها در یک صفحه */
    public function hub(string $hub, Request $request): View
    {
        $hubs = config('toolhub');
        abort_unless(isset($hubs[$hub]), 404);

        return view('pages.toolhub', [
            'hub' => $hub,
            'cfg' => $hubs[$hub],
        ]);
    }

    /** POST /api/dns-report — گزارش کامل همه‌ی رکوردهای DNS یک دامنه */
    public function dnsReport(Request $request): JsonResponse
    {
        $data = $request->validate(['query' => 'nullable|string|max:255']);
        $q = trim($data['query'] ?? '');
        if ($q === '') {
            return response()->json(['ok' => false, 'error' => 'empty']);
        }

        return response()->json($this->net->allDns($q));
    }
}
