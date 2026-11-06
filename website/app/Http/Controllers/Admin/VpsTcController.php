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
        return redirect()->to('/admin/settings?tab=infra#infra-vpstc');
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
        $validator = Validator::make($request->only(['product_id', 'category_id', 'billing_only']), [
            'product_id' => ['nullable', 'string', 'regex:/\A[1-9][0-9]{0,17}\z/D'],
            'category_id' => ['nullable', 'string', 'regex:/\A[1-9][0-9]{0,17}\z/D'],
            'billing_only' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails()) {
            return redirect()->to($this->destination($request))->withErrors($validator);
        }
        $data = $validator->validated();
        $connectionStatus = app(\App\Services\Cloud\InfrastructureSettingsStatus::class);
        $testedFingerprint = $connectionStatus->fingerprint('vpstc');
        $productId = filled($data['product_id'] ?? null) ? (string) $data['product_id'] : null;
        $categoryId = filled($data['category_id'] ?? null) ? (string) $data['category_id'] : null;
        $report = ! empty($data['billing_only'])
            ? $preflight->inspect($productId, $categoryId, true)
            : $preflight->inspect($productId, $categoryId);

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
        $publish = Setting::get('vpstc_sales_enabled') === '1'
            && app(\App\Services\Cloud\VpsTcCatalog::class)->manifest() !== null;
        $previous = app(VpsTcPricing::class)->values();
        $marginOnly = $publish && app(\App\Services\Cloud\CloudPricingSnapshot::class)->current('vpstc');
        foreach (array_diff(VpsTcPricing::INPUT_KEYS, ['cloud_margin_pct_vpstc']) as $key) {
            $marginOnly = $marginOnly && $previous[$key] === (string) ($data[$key] ?? '');
        }
        try {
            $apply = function () use ($data, $publish, $marginOnly, $previous) {
                DB::transaction(function () use ($data, $publish, $marginOnly, $previous) {
                    Setting::whereIn('key', [...VpsTcPricing::INPUT_KEYS, \App\Services\Cloud\CloudPricingSnapshot::KEY])->lockForUpdate()->get();
                    \Illuminate\Support\Facades\Cache::forget('settings.all');
                    app()->forgetInstance('settings.request-memo');
                    if ($previous !== app(VpsTcPricing::class)->values()
                        || ($marginOnly && ! app(\App\Services\Cloud\CloudPricingSnapshot::class)->current('vpstc'))) {
                        throw new \RuntimeException('Pricing changed concurrently.');
                    }
                    foreach (VpsTcPricing::INPUT_KEYS as $key) {
                        Setting::put($key, isset($data[$key]) ? (string) $data[$key] : '');
                    }
                    if ($marginOnly) {
                        $this->publishMargin();
                    } elseif ($publish) {
                        $this->publishCatalog();
                    }
                });
            };
            if ($marginOnly) {
                app(\App\Services\Cloud\CatalogRefreshRequests::class)->exclusive($apply);
            } else {
                $apply();
            }
        } catch (\Throwable $e) {
            // Setting::put invalidates a shared cache before the transaction commits.
            \Illuminate\Support\Facades\Cache::forget('settings.all');
            app()->forgetInstance('settings.request-memo');
            return redirect()->to($this->destination($request))->withErrors([
                'pricing' => 'قیمت تازه تأیید نشد؛ تغییر تنظیمات و قیمت‌ها برگشت داده شد. دوباره تلاش کنید.',
            ]);
        }
        \App\Services\SiteMenu::forget();
        \App\Services\Cloud\CloudCountry::forget();

        return redirect()->to($this->destination($request))->with('success',
            $publish ? 'تنظیمات و قیمت‌های تازه منتشر شدند؛ وضعیت فروش حفظ شد.' : 'قیمت‌گذاری ذخیره شد. کاتالوگ را همگام کنید و سپس فروش را فعال کنید.');
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
            $this->publishCatalog();
        } catch (\Throwable) {
            return redirect()->to($this->destination($request))->withErrors([
                'catalog' => 'همگام‌سازی تأیید نشد؛ فروش یا قیمت‌های ناقص منتشر نشدند.']);
        }
        return redirect()->to($this->destination($request))->with('success', 'کاتالوگ و قیمت ماهانه تازه شد. وضعیت فروش را جداگانه انتخاب کنید.');
    }

    /** Profit alone changes no supplier cost: reuse only a current, verified cost snapshot. */
    private function publishMargin(): void
    {
        $snapshot = app(\App\Services\Cloud\CloudPricingSnapshot::class);
        $rates = $snapshot->rates();
        $excluded = array_values(array_diff($snapshot->excludedProviders(), ['vpstc']));
        $fingerprint = app(VpsTcPricing::class)->fingerprint();
        $plans = \App\Models\CloudPlan::where('provider', 'vpstc')->where('is_active', true)
            ->where('cost_eur_cents', '>', 0)->lockForUpdate()->get();
        if ($plans->isEmpty()) {
            throw new \RuntimeException('No verified costs.');
        }
        foreach ($plans as $plan) {
            $price = app(\App\Services\Cloud\CloudPricing::class)->priceFor($plan->cost_eur_cents, 'vpstc');
            if (($price['irt'] ?? 0) <= 0 || ($price['eur_cents'] ?? 0) <= 0) {
                throw new \RuntimeException('Invalid refreshed price.');
            }
            $plan->update(['price_irt' => $price['irt'], 'price_eur_cents' => $price['eur_cents']]);
        }
        $snapshot->publish($rates, $excluded, ['vpstc' => $fingerprint]);
    }

    /** The settings and all verified supplier prices commit together. No purchase is made. */
    private function publishCatalog(): void
    {
        $snapshot = app(\App\Services\Cloud\CloudPricingSnapshot::class);
        $pricing = app(VpsTcPricing::class);
        $fingerprint = $pricing->fingerprint();
        $rates = $snapshot->rates();
        if (! $snapshot->current() || ! $pricing->configured()
            || app(\App\Services\Cloud\VpsTcCatalog::class)->manifest() === null) {
            throw new \RuntimeException('Unverified pricing or mapping.');
        }
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
        return '/admin/settings?tab=infra#infra-vpstc';
    }
}
