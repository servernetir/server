<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcPreflight;
use App\Services\Cloud\VpsTcPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VpsTcController extends Controller
{
    public function index()
    {
        return response()->view('admin.cloud-vpstc', $this->viewData())->header('Cache-Control', 'private, no-store');
    }

    /** Local display values only; never returns stored credential contents or calls the provider. */
    public function viewData(): array
    {
        return [
            'apiOrigin' => (string) Setting::get('cloud_vpstc_api_origin', config('services.vpstc.base_url', '')),
            'hasUsername' => filled(Setting::getSecret('cloud_vpstc_username')),
            'hasPassword' => filled(Setting::getSecret('cloud_vpstc_password')),
            'vpstcPricing' => app(VpsTcPricing::class)->values(),
            'vpstcCentralTryRate' => app(\App\Services\ExchangeRate::class)->current('TRY'),
            'pricingConfigured' => app(VpsTcPricing::class)->configured(),
            'vpstcSalesEnabled' => app(\App\Services\Cloud\VpsTcSales::class)->enabled(),
            'vpstcMapped' => app(\App\Services\Cloud\VpsTcCatalog::class)->manifest() !== null,
        ];
    }

    public function save(Request $request)
    {
        $validator = Validator::make($request->only(['api_origin', 'username', 'password']), [
            'api_origin' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:512'],
            'password' => ['nullable', 'string', 'max:4096'],
        ]);
        // Never flash credential input, including custom username fields.
        if ($validator->fails()) {
            return redirect()->to($this->destination($request))->withErrors($validator);
        }
        $data = $validator->validated();
        $origin = trim($data['api_origin']);
        if (! VpsTcApi::validOrigin($origin)) {
            return redirect()->to($this->destination($request))->withErrors(['api_origin' => 'نشانی باید فقط مبدأ HTTPS تأییدشدهٔ API نمایندگی باشد؛ مسیر، پارامتر، IP و اطلاعات ورود پذیرفته نیست.']);
        }
        $origin = 'https://'.strtolower((string) parse_url($origin, PHP_URL_HOST));
        $currentOrigin = rtrim((string) Setting::get('cloud_vpstc_api_origin', config('services.vpstc.base_url', '')), '/');
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $hasSavedPair = filled(Setting::getSecret('cloud_vpstc_username')) && filled(Setting::getSecret('cloud_vpstc_password'));
        if (($origin !== $currentOrigin || ! $hasSavedPair) && ($username === '' || $password === '')) {
            return redirect()->to($this->destination($request))->withErrors(['credentials' => 'برای اتصال اولیه یا تغییر نشانی، نام کاربری و رمز API را هر دو دوباره وارد کنید.']);
        }
        DB::transaction(function () use ($origin, $username, $password) {
            Setting::put('cloud_vpstc_api_origin', $origin);
            if ($username !== '') {
                Setting::putSecret('cloud_vpstc_username', $username);
            }
            if ($password !== '') {
                Setting::putSecret('cloud_vpstc_password', $password);
            }
        });
        $request->session()->forget('vpstc_report');

        return redirect()->to($this->destination($request))->with('success', 'اطلاعات اتصال رمزگذاری و ذخیره شد. اکنون بررسی اتصال را اجرا کنید. فروش هنوز فعال نیست.');
    }

    public function preflight(Request $request, VpsTcPreflight $preflight)
    {
        $validator = Validator::make($request->only(['product_id', 'category_id']), [
            'product_id' => ['nullable', 'string', 'regex:/\A[1-9][0-9]{0,17}\z/D'],
            'category_id' => ['nullable', 'string', 'regex:/\A[1-9][0-9]{0,17}\z/D'],
        ]);
        if ($validator->fails()) {
            return redirect()->to($this->destination($request))->withErrors($validator);
        }
        $data = $validator->validated();
        $connectionStatus = app(\App\Services\Cloud\InfrastructureSettingsStatus::class);
        $testedFingerprint = $connectionStatus->fingerprint('vpstc');
        $report = $preflight->inspect(filled($data['product_id'] ?? null) ? (string) $data['product_id'] : null, filled($data['category_id'] ?? null) ? (string) $data['category_id'] : null);

        $connectionStatus->record('vpstc', ($report['connection_ok'] ?? false) === true, $testedFingerprint);
        return redirect()->to($this->destination($request))->with('vpstc_report', $report);
    }

    public function savePricing(Request $request)
    {
        $validator = Validator::make($request->only(VpsTcPricing::INPUT_KEYS), [
            'vpstc_currency' => ['required', 'string', 'in:TRY,USD,EUR,USDT'],
            'cloud_margin_pct_vpstc' => ['required', 'numeric', 'min:0', 'max:500'],
            'vpstc_supplier_tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'pricing_fx_fee_pct_vpstc' => ['required', 'numeric', 'min:0', 'max:100'],
            'vpstc_fx_rate_toman' => ['nullable', 'numeric', 'min:0.000001', 'max:1000000000'],
        ]);
        if ($validator->fails()) {
            return redirect()->to($this->destination($request))->withErrors($validator);
        }
        $data = $validator->validated();
        DB::transaction(function () use ($data) {
            foreach (VpsTcPricing::INPUT_KEYS as $key) {
                Setting::put($key, isset($data[$key]) ? (string) $data[$key] : '');
            }
        });
        \App\Services\SiteMenu::forget();
        \App\Services\Cloud\CloudCountry::forget();

        return redirect()->to($this->destination($request))->with('success',
            'تنظیمات مستقل قیمت‌گذاری ذخیره شد. برای انتشار قیمت‌های تازه، کاتالوگ را همگام کنید. تا آن زمان فروش این زیرساخت با قیمت قبلی بسته است.');
    }

    public function syncCatalog(Request $request)
    {
        $snapshot = app(\App\Services\Cloud\CloudPricingSnapshot::class);
        $pricing = app(VpsTcPricing::class);
        if (! $snapshot->current() || ! $pricing->configured()
            || app(\App\Services\Cloud\VpsTcCatalog::class)->manifest() === null) {
            return redirect()->to($this->destination($request))->withErrors([
                'catalog' => 'اتصال، قیمت‌گذاری، نرخ معتبر ارز و تطبیق منابع/دوره/ترافیک باید تکمیل شوند.']);
        }
        try {
            $fingerprint = $pricing->fingerprint();
            $rates = $snapshot->rates();
            DB::transaction(function () use ($snapshot, $fingerprint, $rates, $pricing) {
                $report = app(\App\Services\Cloud\CloudCatalogSync::class)->sync('vpstc');
                $result = $report['providers']['vpstc'] ?? [];
                if (! ($result['ok'] ?? false) || ($result['plans'] ?? 0) < 1
                    || $rates !== $snapshot->rates() || $fingerprint !== $pricing->fingerprint()) {
                    throw new \RuntimeException('Unverified catalog or changed pricing inputs.');
                }
                $excluded = array_values(array_diff($snapshot->excludedProviders(), ['vpstc']));
                $snapshot->publish($rates, $excluded, ['vpstc' => $fingerprint]);
            });
        } catch (\Throwable) {
            return redirect()->to($this->destination($request))->withErrors([
                'catalog' => 'همگام‌سازی تأیید نشد؛ فروش یا قیمت‌های ناقص منتشر نشدند.']);
        }
        return redirect()->to($this->destination($request))->with('success', 'کاتالوگ و قیمت ماهانه تازه شد. وضعیت فروش را جداگانه انتخاب کنید.');
    }

    public function saveSales(Request $request)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        if ($request->boolean('enabled')
            && (! app(VpsTcApi::class)->isConfigured() || ! app(VpsTcPricing::class)->configured()
                || app(\App\Services\Cloud\VpsTcCatalog::class)->manifest() === null
                || ! app(\App\Services\Cloud\CloudPricingSnapshot::class)->current('vpstc')
                || ! \App\Models\CloudPlan::where('provider', 'vpstc')->where('is_active', true)
                    ->where('admin_disabled', false)->where('in_stock', true)->where('price_irt', '>', 0)->exists())) {
            return redirect()->to($this->destination($request))->withErrors([
                'sales' => 'فروش پس از اتصال معتبر، تطبیق قرارداد ماهانه و همگام‌سازی قیمت قابل فعال‌سازی است.']);
        }
        Setting::put('vpstc_sales_enabled', $request->boolean('enabled') ? '1' : '0');
        \App\Services\SiteMenu::forget();
        \App\Services\Cloud\CloudCountry::forget();
        return redirect()->to($this->destination($request))->with('success',
            $request->boolean('enabled') ? 'فروش ماهانهٔ این زیرساخت فعال شد.' : 'فروش تازهٔ این زیرساخت بسته شد.');
    }

    private function destination(Request $request): string
    {
        return $request->input('return_to') === 'infra'
            ? '/admin/settings?tab=infra#infra-vpstc'
            : route('admin.cloud.vpstc');
    }
}
