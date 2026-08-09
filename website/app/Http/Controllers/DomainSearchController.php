<?php

namespace App\Http\Controllers;

use App\Services\Domain\DomainSearch;
use App\Services\Domain\OpenProviderClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * جستجوی دامنه از طریق رسیلری (OpenProvider).
 *
 * جدا از DomainCheckController قدیمی که هنوز روی WHMCS است — تا وقتی این
 * مسیر تثبیت شود، آن یکی دست‌نخورده کار می‌کند.
 */
class DomainSearchController extends Controller
{
    /** صفحهٔ جستجو */
    public function page(): View
    {
        return view('pages.domain-search');
    }

    /** استعلام زنده */
    public function check(Request $request, DomainSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q'    => ['required', 'string', 'max:120'],
            'tlds' => ['nullable', 'array', 'max:12'],
            'tlds.*' => ['string', 'max:24'],
        ]);

        $results = $search->search($data['q'], $data['tlds'] ?? []);

        /*
        | 🔴 `ok` و `lookup_ok` دو چیزِ متفاوت‌اند و قاطی‌کردنشان گران است.
        |
        |   ok        = درخواست سرو شد (گاردِ حمل‌ونقلِ سمتِ مرورگر)
        |   lookup_ok = رجیسترار واقعاً جواب داد
        |
        | تا امروز فقط `ok: true`ِ بی‌قیدوشرط برمی‌گشت، پس صفحهٔ عمومی **هیچ
        | کانالی برای خرابی نداشت**: در یک قطعیِ کاملِ رجیسترار همهٔ ردیف‌ها
        | `unknown` می‌شدند، فیلترِ پیش‌فرض پنهانشان می‌کرد، و تنها جمله‌ای که
        | مشتری می‌دید این بود که «با این فیلترها چیزی نمانده» — یعنی خرابیِ ما
        | به‌عنوانِ اشتباهِ خودِ او گزارش می‌شد.
        |
        | ⚠️ `ok` عمداً `true` مانده: اگر `false` شود، گاردِ موجودِ جاوااسکریپت
        | زودتر `return` می‌کند و ردیف‌ها **اصلاً رندر نمی‌شوند** — همان پنهان‌شدن
        | از درِ دیگر.
        */
        return response()->json([
            'ok'        => true,
            'lookup_ok' => $search->lookupOk(),
            'reason'    => $search->lookupReason(),
            'query'     => $data['q'],
            'results'   => $results,
        ]);
    }

    /**
     * وضعیت اتصال به رسیلری.
     *
     * 🔴 این روت **عمومی و بی‌احراز هویت** است (صفحهٔ جستجو ازش می‌پرسد که آیا
     * جستجو کار می‌کند). پس هر تماسی که این‌جا می‌زنیم، هر بازدیدکننده‌ای
     * می‌تواند بی‌نهایت بار تکرارش کند.
     *
     * تا امروز `token(forceFresh: true)` صدا زده می‌شد — یعنی کش عمداً دور زده
     * می‌شد و **هر بازدید یک تلاشِ ورودِ تازه** به رجیسترار می‌فرستاد. همین
     * الگو (ورودهای پیاپیِ ناموفق) یک بار حسابِ ما را نزدِ اوپن‌پروایدر
     * حساس/قفل کرد. حالا از توکنِ کش‌شده استفاده می‌شود: اولین تماس یک بار
     * وارد می‌شود و شش ساعت کش می‌مانَد، پس تکرارِ این روت هیچ ترافیکی به
     * رجیسترار نمی‌سازد.
     *
     * هرگز اعتبارنامه را برنمی‌گرداند؛ فقط می‌گوید کار می‌کند یا نه و چرا.
     */
    public function status(OpenProviderClient $op): JsonResponse
    {
        if (! $op->enabled()) {
            return response()->json([
                'configured' => false,
                'connected'  => false,
                'reason'     => 'اعتبارنامه در .env تنظیم نشده است',
            ]);
        }

        $token = $op->token();

        if ($token !== null) {
            return response()->json([
                'configured' => true,
                'connected'  => true,
                'reason'     => null,
            ]);
        }

        /*
        | ⚠️ استعلامِ تشخیصی فقط برای **مدیرِ واردشده**.
        |
        | این یک تماسِ واقعیِ API است و روی روتِ عمومی یعنی هر بازدیدکننده
        | می‌تواند حجمِ دلخواهی ترافیک روی حسابِ ما بسازد. مشتری هم به کدِ خطای
        | رجیسترار نیازی ندارد؛ برایش «کار نمی‌کند» کافی است.
        */
        if (! auth('web')->check()) {
            return response()->json([
                'configured' => true,
                'connected'  => false,
                'reason'     => null,
            ]);
        }

        // یک استعلام کوچک تا کد خطای دقیق را ببینیم
        $probe = $op->call('POST', '/domains/check', [
            'domains' => [['name' => 'example', 'extension' => 'com']],
            'with_price' => true,
        ]);

        $code = (int) data_get($probe, 'code', -1);

        return response()->json([
            'configured' => true,
            'connected'  => false,
            'code'       => $code,
            // IP خروجی این سرور — همانی که باید در فهرست مجاز رسیلری باشد
            'server_ip'  => $this->outboundIp(),
            'reason'     => match ($code) {
                196     => 'نام کاربری/رمز رد شد، یا IP این سرور مجاز نیست',
                901     => 'فیلد نام کاربری خالی است',
                -1      => 'سرور OpenProvider پاسخ نداد',
                default => data_get($probe, 'desc', 'خطای ناشناخته'),
            },
        ]);
    }

    /**
     * IP خروجی سرور. برای مجاز کردن در پنل رسیلری لازم است — IP دامنه
     * لزوماً همان IP خروجی نیست (مثلاً وقتی پشت CDN یا NAT باشد).
     */
    private function outboundIp(): ?string
    {
        return \Illuminate\Support\Facades\Cache::remember('server.outbound_ip', now()->addDay(), function () {
            foreach (['https://api.ipify.org', 'https://ifconfig.me/ip'] as $probe) {
                try {
                    $ip = trim(\Illuminate\Support\Facades\Http::timeout(8)->get($probe)->body());
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                } catch (\Throwable) {
                    // منبع بعدی
                }
            }

            return null;
        });
    }
}
