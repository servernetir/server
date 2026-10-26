<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcPreflight;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VpsTcController extends Controller
{
    public function index()
    {
        return response()->view('admin.cloud-vpstc', [
            'apiOrigin' => (string) Setting::get('cloud_vpstc_api_origin', config('services.vpstc.base_url', '')),
            'hasUsername' => filled(Setting::getSecret('cloud_vpstc_username')),
            'hasPassword' => filled(Setting::getSecret('cloud_vpstc_password')),
        ])->header('Cache-Control', 'private, no-store');
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
            return redirect()->route('admin.cloud.vpstc')->withErrors($validator);
        }
        $data = $validator->validated();
        $origin = trim($data['api_origin']);
        if (! VpsTcApi::validOrigin($origin)) {
            return redirect()->route('admin.cloud.vpstc')->withErrors(['api_origin' => 'نشانی باید فقط مبدأ HTTPS تأییدشدهٔ API نمایندگی باشد؛ مسیر، پارامتر، IP و اطلاعات ورود پذیرفته نیست.']);
        }
        $origin = 'https://'.strtolower((string) parse_url($origin, PHP_URL_HOST));
        $currentOrigin = rtrim((string) Setting::get('cloud_vpstc_api_origin', config('services.vpstc.base_url', '')), '/');
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $hasSavedPair = filled(Setting::getSecret('cloud_vpstc_username')) && filled(Setting::getSecret('cloud_vpstc_password'));
        if (($origin !== $currentOrigin || ! $hasSavedPair) && ($username === '' || $password === '')) {
            return redirect()->route('admin.cloud.vpstc')->withErrors(['credentials' => 'برای اتصال اولیه یا تغییر نشانی، نام کاربری و رمز API را هر دو دوباره وارد کنید.']);
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

        return redirect()->route('admin.cloud.vpstc')->with('success', 'اطلاعات اتصال رمزگذاری و ذخیره شد. اکنون بررسی اتصال را اجرا کنید. فروش هنوز فعال نیست.');
    }

    public function preflight(Request $request, VpsTcPreflight $preflight)
    {
        $validator = Validator::make($request->only(['product_id', 'category_id']), [
            'product_id' => ['nullable', 'string', 'regex:/\A[1-9][0-9]{0,17}\z/D'],
            'category_id' => ['nullable', 'string', 'regex:/\A[1-9][0-9]{0,17}\z/D'],
        ]);
        if ($validator->fails()) {
            return redirect()->route('admin.cloud.vpstc')->withErrors($validator);
        }
        $data = $validator->validated();
        $report = $preflight->inspect(filled($data['product_id'] ?? null) ? (string) $data['product_id'] : null, filled($data['category_id'] ?? null) ? (string) $data['category_id'] : null);

        return redirect()->route('admin.cloud.vpstc')->with('vpstc_report', $report);
    }
}
