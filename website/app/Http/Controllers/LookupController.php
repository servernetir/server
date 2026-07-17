<?php

namespace App\Http\Controllers;

use App\Services\NetworkTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * مجموعه ابزار DNS و شبکه — هر نوع بررسی یک صفحه‌ی سئوشده‌ی مستقل
 * روی /lookup/{type} با محتوای سه‌زبانه از config/lookup.php.
 */
class LookupController extends Controller
{
    public function __construct(private NetworkTools $net) {}

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
        ]);
    }

    /** POST /api/lookup — اجرای بررسی و بازگرداندن JSON */
    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'  => 'required|string|max:20',
            'query' => 'nullable|string|max:255',
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
            'ports'       => $this->net->ports($q),
            'ping'        => $this->net->ping($q),
            default       => ['ok' => false, 'error' => 'unknown_kind'],
        };

        return response()->json($result);
    }
}
