<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Support\ErrorTracker;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;

/**
 * `/admin/bale` — تنها جایی که کنسولِ بله روشن، متصل، یا قطع می‌شود.
 *
 * پشتِ همان دروازه‌ای است که بقیهٔ پنل: نشستِ کارکنان + نقشِ مدیر + کدِ
 * دومرحله‌ایِ ایمیل. یعنی برای اتصال باید هم پنل را داشته باشی هم صندوقِ
 * ایمیل — و دارندهٔ آدرسِ وب‌هوک هیچ‌کدام را ندارد.
 *
 * ⚠️ کدِ اتصال روی این صفحه **چاپ نمی‌شود** و به ایمیلِ همان مدیر می‌رود. اگر
 * روی صفحه می‌آمد، یک مرورگرِ بازِ رهاشده برای تصاحبِ کنسول کافی بود.
 */
class BaleAdminController extends Controller
{
    /**
     * ⚠️ صفحهٔ اختصاصی حذف شد و به تبِ تنظیمات رفت (خواستهٔ کارفرما).
     *
     * ریدایرکت می‌مانَد و حذف نمی‌شود: نشانیِ `/admin/bale` ممکن است در
     * بوکمارک یا در متنِ یک اعلانِ قدیمی باشد، و ۴۰۴ دادنش یعنی «خراب شد».
     */
    public function index(): RedirectResponse
    {
        return redirect('/admin/settings?tab=bale');
    }

    public function pair(Request $request, AdminBaleGate $gate): RedirectResponse
    {
        $res = $gate->beginPairing($request->user());

        return back()->with($res['ok'] ? 'ok' : 'err', $res['message']);
    }

    public function revoke(AdminBaleGate $gate): RedirectResponse
    {
        $gate->revoke();

        return back()->with('ok', 'اتصالِ ربات قطع و کنسول خاموش شد.');
    }

    public function toggle(Request $request, AdminBaleGate $gate): RedirectResponse
    {
        $on = $request->boolean('on');

        if ($on && $gate->binding() === null) {
            return back()->with('err', 'اول ربات را متصل کنید.');
        }

        $gate->setEnabled($on);

        return back()->with('ok', $on ? 'کنسول روشن شد.' : 'کنسول خاموش شد.');
    }

    /**
     * وب‌هوکِ ربات الان به کجا وصل است؟
     *
     * 🔴 چرا این روی صفحه است و نه در یک سندِ راه‌اندازی: تا مرداد ۱۴۰۵ متنِ
     * خودِ `/system/bale-setup` می‌گفت «وب‌هوک فعلی به n8n وصل است» و هیچ‌کس
     * نمی‌دانست آن جمله هنوز درست است یا کهنه. اگر وب‌هوک به اپِ ما وصل نباشد،
     * **هیچ‌چیز** در این صفحه کار نمی‌کند و نشانه‌اش هم فقط «سکوت» است — یعنی
     * از «هنوز پیامی نفرستاده‌ام» قابلِ تشخیص نیست.
     *
     * فقط **میزبان** نشان داده می‌شود، نه مسیرِ کامل: مسیر شاملِ رشتهٔ مخفیِ
     * وب‌هوک است و این صفحه ممکن است اسکرین‌شات شود.
     *
     * @return array{state:string,host:?string}
     */
    public function webhookState(): array
    {
        $token = (string) config('services.bale.token');

        if ($token === '') {
            return ['state' => 'no_token', 'host' => null];
        }

        try {
            $base = rtrim((string) config('services.bale.base', 'https://tapi.bale.ai'), '/');
            $res  = Http::timeout(6)->get($base.'/bot'.$token.'/getWebhookInfo');
            $url  = (string) $res->json('result.url', '');

            if ($url === '') {
                return ['state' => 'unset', 'host' => null];
            }

            $host = parse_url($url, PHP_URL_HOST) ?: '—';

            /*
            | 🔴 مقایسه با `request()->getHost()` **غلط بود** و هشدارِ کاذب می‌داد.
            |
            | این صفحه روی `console.servernet.cloud` باز می‌شود، ولی وب‌هوک از
            | `/system/bale-setup` ثبت شده که روی دامنهٔ اصلی می‌دود — پس میزبانِ
            | ثبت‌شده `servernet.cloud` است و برابریِ ساده هرگز برقرار نمی‌شد.
            | نتیجه: کارفرما رباتِ کاملاً سالم داشت و صفحه با قرمز می‌گفت
            | «وب‌هوک به جای دیگری وصل است».
            |
            | ⚠️ درسِ کوچک ولی تکراری: چکِ سلامتی که دروغ بگوید، از نبودش بدتر
            | است — یا کارِ سالم را خراب نشان می‌دهد، یا یاد می‌دهد قرمز را
            | نادیده بگیرند.
            |
            | مقایسه حالا روی **دامنهٔ ثبت‌شدنی** است: `console.servernet.cloud`
            | و `servernet.cloud` هر دو `servernet.cloud` می‌شوند.
            */
            $ours = fn (string $h) => implode('.', array_slice(explode('.', strtolower($h)), -2));

            return [
                'state' => str_contains($url, '/bale/webhook/') && $ours($host) === $ours(request()->getHost())
                    ? 'ours' : 'elsewhere',
                'host'  => $host,
            ];
        } catch (\Throwable $e) {
            ErrorTracker::noteOnce('bale-admin', 'خواندنِ وضعیتِ وب‌هوکِ بله شکست خورد', 3600,
                ['err' => mb_substr($e->getMessage(), 0, 120)]);

            return ['state' => 'unknown', 'host' => null];
        }
    }
}
