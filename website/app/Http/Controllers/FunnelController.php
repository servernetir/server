<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Support\Funnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * گیرندهٔ رویدادهای قیف از مرورگر — ممیزی ۶ (مدیر رشد).
 *
 * چرا از مرورگر: صفحاتِ HIT به PHP نمی‌رسند، پس product_page_view و
 * cycle_selected و checkout_click فقط با `navigator.sendBeacon` شمردنی‌اند.
 *
 * ⚠️ هیچ‌چیزِ قابلِ اعتمادی از کلاینت پذیرفته نمی‌شود جز نامِ رویداد و چند
 * شناسهٔ کوتاهِ اعتبارسنجی‌شده. هیچ قیمتی. هیچ متنِ آزادی. (مدیر امنیت)
 * گلوگاهِ `throttle:tools` + CSRF (توکن داخلِ بدنهٔ JSON).
 */
class FunnelController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $event = (string) $request->input('event', '');

        if (! in_array($event, ['product_page_view', 'order_summary_view', 'cycle_selected', 'checkout_click'], true)) {
            return response()->json(['ok' => false], 422);
        }

        $clean = fn (string $key, string $re, int $max = 64) => (function ($v) use ($re, $max) {
            $v = is_scalar($v) ? mb_substr((string) $v, 0, $max) : '';

            return preg_match($re, $v) ? $v : '';
        })($request->input($key));

        $cycle = $clean('cycle', '/^[a-z]{1,16}$/');
        $cycleAt = $clean('cycle_at_click', '/^[a-z]{1,16}$/');

        Funnel::log($event, [
            'sku'             => $clean('sku', '/^[a-z0-9\-]{1,64}$/'),
            'sid'             => $clean('sid', '/^[a-z0-9]{8,32}$/'),
            'ref'             => $clean('ref', '/^[a-z0-9_\-]{1,32}$/'),
            'product_line'    => $clean('product_line', '/^[a-z\-]{1,24}$/'),
            'cycle'           => in_array($cycle, Service::cycles(), true) ? $cycle : '',
            'cycle_at_click'  => in_array($cycleAt, Service::cycles(), true) ? $cycleAt : '',
            'discount_pct'    => (int) $request->input('discount_pct', 0),
            'selection_index' => (int) $request->input('selection_index', 0),
            'time_on_page'    => (int) $request->input('time_on_page', 0),
        ]);

        return response()->json(['ok' => true]);
    }
}
