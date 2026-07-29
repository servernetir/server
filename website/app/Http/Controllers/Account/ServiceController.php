<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Provisioning\WhmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * سرویس‌های مشتری — سمت خودِ مشتری (پنل کاربری).
 * فقط سرویس‌های خودش را می‌بیند.
 */
class ServiceController extends Controller
{
    public function index(): View
    {
        $customer = Auth::guard('customer')->user();

        // سرویسی که فاکتورش پرداخت نشده هنوز مالِ مشتری نیست و نباید در فهرستِ
        // «سرویس‌های من» بیاید — وگرنه کاربر فکر می‌کند خریدش انجام شده. تا
        // پرداخت، همان پیش‌فاکتور در بخشِ فاکتورها منتظرِ اوست.
        $services = Schema::hasTable('services')
            ? $customer->services()
                ->where('status', '!=', 'pending')
                ->with(['invoices' => fn ($q) => $q->latest('id'), 'server'])
                ->latest('id')->get()
            : collect();

        return view('account.services', AccountController::shell('services') + [
            'services' => $services,
        ]);
    }

    /**
     * ورودِ یک‌کلیکِ مشتری به cPanelِ خودش — WHM یک نشستِ ازپیش‌احرازشده می‌سازد
     * و مشتری مستقیم واردِ کنترل‌پنل می‌شود (بدونِ نیاز به نام‌کاربری/رمز).
     */
    public function cpanel(Request $request, Service $service): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($service->customer_id === $customer->id, 404);

        if ($service->provision_status !== 'done' || ! $service->server || blank($service->username)) {
            return back()->withErrors('ورودِ یک‌کلیک برای این سرویس هنوز در دسترس نیست.');
        }

        // فقط WHM نشستِ ورود دارد؛ بقیه به آدرسِ کنترل‌پنل هدایت می‌شوند
        if ($service->server->type !== 'whm') {
            return $service->panel_url ? redirect()->away($service->panel_url)
                : back()->withErrors('آدرسِ کنترل‌پنل تعیین نشده است.');
        }

        $res = (new WhmClient($service->server))->createUserSession($service->username);
        $url = $res['data']['url'] ?? ($res['raw']['data']['url'] ?? null);

        if (! $res['ok'] || ! $url) {
            return back()->withErrors('ورود به cPanel ناموفق بود: '.($res['reason'] ?? 'نامشخص'));
        }

        // ورودِ عمیق به یک ابزارِ خاصِ cPanel (قالبِ Jupiter؛ اگر مسیر نخورد،
        // روی خانهٔ cPanel می‌نشیند — بی‌خطر)
        $goto = match ($request->query('app')) {
            'files' => '/frontend/jupiter/filemanager/index.html',
            'db'    => '/frontend/jupiter/sql/index.html',
            'email' => '/frontend/jupiter/mail/index.html',
            'php'   => '/frontend/jupiter/software/phpini.html',
            default => null,
        };
        if ($goto) {
            $url .= (str_contains($url, '?') ? '&' : '?').'goto_uri='.rawurlencode($goto);
        }

        return redirect()->away($url);
    }

    /** آمارِ زندهٔ سرویس (فضا/وضعیت) — JSON، با کشِ کوتاه تا WHM را نکوبد */
    public function stats(Request $request, Service $service): JsonResponse
    {
        $customer = Auth::guard('customer')->user();
        abort_unless($service->customer_id === $customer->id, 404);

        if ($service->provision_status !== 'done' || ! $service->server || $service->server->type !== 'whm' || blank($service->username)) {
            return response()->json(['ok' => false]);
        }

        $data = Cache::remember('svc-stats:'.$service->id, now()->addMinutes(3), function () use ($service) {
            $sum = (new WhmClient($service->server))->accountSummary($service->username);
            $acct = $sum['data']['acct'][0] ?? ($sum['raw']['data']['acct'][0] ?? []);

            if (! $sum['ok'] || ! $acct) {
                return ['ok' => false];
            }

            $limit = $acct['disklimit'] ?? 'unlimited';

            return [
                'ok'         => true,
                'disk_used'  => (int) ($acct['diskused'] ?? 0),                          // MB
                'disk_limit' => is_numeric($limit) ? (int) $limit : null,               // MB، null=نامحدود
                'suspended'  => (int) ($acct['suspended'] ?? 0) === 1,
                'ip'         => $acct['ip'] ?? null,
                'plan'       => $acct['plan'] ?? null,
            ];
        });

        return response()->json($data);
    }
}
