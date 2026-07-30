<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\Cloud\CloudManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * پنلِ مدیریتِ زیرساختِ ابری — آزمونِ اتصال، همگام‌سازی و نمایِ کاتالوگ.
 *
 * ⚠️ این تنها جایی در کلِ برنامه است که نامِ ارائه‌دهنده به چشمِ انسان می‌رسد، و
 * فقط برای **مدیر**. هر ویویی که مشتری می‌بیند باید از `CloudPlan::offers()`
 * بخواند که ستونِ `provider` را پنهان می‌کند.
 */
class CloudController extends Controller
{
    public function __construct(private CloudManager $manager) {}

    public function index(): View
    {
        $ready = Schema::hasTable('cloud_plans');

        $providers = [];
        foreach ($this->manager->all() as $slug => $driver) {
            $providers[$slug] = [
                'configured' => $driver->isConfigured(),
                'plans'      => $ready ? CloudPlan::where('provider', $slug)->where('is_active', true)->count() : 0,
                'caps'       => $driver->capabilities(),
            ];
        }

        // عرضه‌های عمومی: همان چیزی که مشتری می‌بیند — برای اینکه مدیر بتواند
        // چشمی بررسی کند که سفیدبرچسبی درست کار می‌کند و پلنِ تکراری نیست.
        $offers = $ready ? CloudPlan::offers() : collect();

        return view('admin.cloud', [
            'notReady'  => ! $ready,
            'providers' => $providers,
            'offers'    => $offers,
            'locations' => $ready
                ? CloudLocation::orderBy('country')->orderBy('city')->get()
                : collect(),
            'planCount' => $ready ? CloudPlan::where('is_active', true)->count() : 0,
        ]);
    }

    /** آزمونِ اتصال به همهٔ ارائه‌دهندگانِ تنظیم‌شده */
    public function test(): RedirectResponse
    {
        $configured = $this->manager->configured();

        if ($configured === []) {
            return back()->with('err', 'هیچ توکنی ذخیره نشده — اول توکن را وارد و ذخیره کنید.');
        }

        $lines = [];
        $i = 0;

        foreach ($configured as $driver) {
            $i++;
            $r = $driver->testConnection();
            // در پیامِ مدیر هم نامِ ارائه‌دهنده را نمی‌نویسیم؛ «زیرساختِ ۱/۲»
            // همان ترتیبی است که در فرمِ تنظیمات دیده.
            $lines[] = ($r['ok'] ? '✅' : '❌')." زیرساختِ {$i}: ".$r['message'];
        }

        return back()->with($lines === [] ? 'err' : 'ok', implode(' | ', $lines));
    }

    /** همگام‌سازیِ کاتالوگ (یا فقط بازمحاسبهٔ قیمت) */
    public function sync(Request $request, CloudCatalogSync $sync): RedirectResponse
    {
        if (! Schema::hasTable('cloud_plans')) {
            return back()->with('err', 'جدول‌های ابری ساخته نشده‌اند — اول مهاجرت را اجرا کنید.');
        }

        if ($request->boolean('prices_only')) {
            $n = $sync->reprice();

            return back()->with('ok', "قیمتِ {$n} پلن با نرخِ روزِ یورو بازمحاسبه شد.");
        }

        $report = $sync->sync();

        if (isset($report['message'])) {
            return back()->with('err', $report['message']);
        }

        if ($report['providers'] === []) {
            return back()->with('err', 'هیچ زیرساختی تنظیم نشده است.');
        }

        $lines = [];
        $i = 0;

        $sanity = $report['providers']['__sanity'] ?? null;
        unset($report['providers']['__sanity']);

        foreach ($report['providers'] as $r) {
            $i++;
            if (! $r['ok']) {
                $lines[] = "زیرساختِ {$i}: خطا — {$r['message']}";

                continue;
            }

            $line = "زیرساختِ {$i}: {$r['plans']} پلن، {$r['locations']} مکان، {$r['images']} سیستم‌عامل";

            // توضیحِ «چرا صفر» — اگر درایور دلیلی داشت، همان‌جا نشان بده
            if (filled($r['message'] ?? null)) {
                $line .= ' ⚠️ '.$r['message'];
            }

            $lines[] = $line;
        }

        // دامِ «واحدِ قیمت اشتباه» — پیش از هر چیزِ دیگر دیده شود
        if (is_string($sanity) && $sanity !== '') {
            $lines[] = $sanity;
        }

        if (($report['rate'] ?? 0) <= 0) {
            $lines[] = '⚠️ نرخِ یورو در دسترس نبود، پس قیمتِ تومانی ساخته نشد و پلن‌ها در فروشگاه نمی‌آیند.';
        }

        return back()->with('ok', implode(' | ', $lines));
    }

    /**
     * ساختارِ خامِ پاسخِ زیرساختِ دوم — ابزارِ عیب‌یابی.
     *
     * چرا هست: داکیومنتِ آن ارائه‌دهنده نمونهٔ کاملِ JSON نداشت، پس نگاشتِ
     * فیلدها بخشی حدسی است. با این خروجی می‌شود دقیقش کرد.
     */
    public function probe(): View
    {
        $driver = $this->manager->driver('aeza');

        $data = ($driver instanceof \App\Services\Cloud\AezaClient && $driver->isConfigured())
            ? $driver->rawProbe()
            : ['error' => 'توکنِ زیرساختِ ۲ تنظیم نشده است.'];

        return view('admin.cloud-probe', ['data' => $data]);
    }
}
