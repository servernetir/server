<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * تنظیمات — فعلاً مشخصات حساب بانکی شرکت برای «واریز به حساب».
 *
 * چرا این‌جا و نه .env: شمارهٔ شبا/حساب داده‌ای است که مدیر عوض می‌کند و ما
 * نباید در کد یا env نگهش داریم. خودش این‌جا واردش می‌کند.
 */
class SettingsController extends Controller
{
    private const BANK_KEYS = ['bank_holder', 'bank_name', 'bank_account', 'bank_sheba', 'bank_card', 'bank_note'];

    public function edit(): View
    {
        $ready = Schema::hasTable('settings');

        $bank = [];
        foreach (self::BANK_KEYS as $k) {
            $bank[$k] = $ready ? Setting::get($k) : null;
        }

        // پیش‌نمایش مهر (data-uri) اگر آپلود شده
        $stampData = null;
        if ($ready && ($p = Setting::get('stamp_path')) && \Illuminate\Support\Facades\Storage::disk('local')->exists($p)) {
            $mime = Setting::get('stamp_mime') ?: 'image/png';
            $stampData = 'data:'.$mime.';base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($p));
        }

        // قیمت‌گذاریِ منعطف — نرخِ مبنا، override دستی، و حاشیهٔ سود
        $pricing = [
            'pricing_baseline_rate' => $ready ? Setting::get('pricing_baseline_rate') : null,
            'pricing_rate_override' => $ready ? Setting::get('pricing_rate_override') : null,
            'price_margin_pct'      => $ready ? Setting::get('price_margin_pct') : null,
        ];
        $liveRate = app(\App\Services\ExchangeRate::class)->toToman('EUR');

        return view('admin.settings', [
            'bank'        => $bank,
            'stampData'   => $stampData,
            'pricing'     => $pricing,
            'liveRate'    => $liveRate,
            'priceFactor' => $ready ? price_factor() : 1.0,
            'notReady'    => ! $ready,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bank_holder'  => ['nullable', 'string', 'max:120'],
            'bank_name'    => ['nullable', 'string', 'max:80'],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'bank_sheba'   => ['nullable', 'string', 'max:34'],
            'bank_card'    => ['nullable', 'string', 'max:20'],
            'bank_note'    => ['nullable', 'string', 'max:300'],
            // مهر شرکت — PNG (شفاف بهتر) یا JPG، تا ۲ مگابایت
            'stamp'        => ['nullable', 'file', 'mimetypes:image/png,image/jpeg', 'max:2048'],
            'remove_stamp' => ['nullable', 'boolean'],
            // قیمت‌گذاری
            'pricing_baseline_rate' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'pricing_rate_override' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_margin_pct'      => ['nullable', 'numeric', 'min:-50', 'max:500'],
            // Cloudflare — برای رکوردِ DNS زیردامنهٔ رایگان
            'cloudflare_token'      => ['nullable', 'string', 'max:200'],
            'cloudflare_zone_id'    => ['nullable', 'string', 'max:64', 'regex:/^[a-f0-9]*$/i'],
            'cloudflare_forget'     => ['nullable', 'boolean'],
        ]);

        foreach (self::BANK_KEYS as $k) {
            Setting::put($k, isset($data[$k]) ? trim((string) $data[$k]) : null);
        }

        foreach (['pricing_baseline_rate', 'pricing_rate_override', 'price_margin_pct'] as $k) {
            Setting::put($k, filled($data[$k] ?? null) ? (string) $data[$k] : null);
        }

        // Cloudflare: توکن **رمزنگاری‌شده** و هرگز به فرم برنمی‌گردد. اگر خالی
        // بفرستد یعنی «دست نزن»؛ برای حذف، تیکِ جداگانه هست (مثلِ توکنِ WHM).
        if ($request->boolean('cloudflare_forget')) {
            Setting::putSecret('cloudflare_token', null);
            Setting::put('cloudflare_zone_id', null);
        } elseif (filled($data['cloudflare_token'] ?? null)) {
            Setting::putSecret('cloudflare_token', trim((string) $data['cloudflare_token']));
            Setting::put('cloudflare_zone_id', null);      // با توکنِ تازه دوباره کشف شود
        }

        if (filled($data['cloudflare_zone_id'] ?? null)) {
            Setting::put('cloudflare_zone_id', trim((string) $data['cloudflare_zone_id']));
        }

        // مهر: بیرون webroot در storage ذخیره می‌شود؛ روی فاکتور به‌صورت
        // data-uri جاسازی می‌شود، پس نه لینک عمومی لازم است نه symlink.
        if ($request->boolean('remove_stamp')) {
            $this->deleteStamp();
        } elseif ($request->hasFile('stamp') && $request->file('stamp')->isValid()) {
            $this->deleteStamp();
            $file = $request->file('stamp');
            $path = $file->storeAs('company', 'stamp.'.$file->extension(), 'local');
            Setting::put('stamp_path', $path);
            Setting::put('stamp_mime', $file->getClientMimeType());
        }

        return back()->with('ok', 'تنظیمات ذخیره شد.');
    }

    private function deleteStamp(): void
    {
        $old = Setting::get('stamp_path');
        if ($old && \Illuminate\Support\Facades\Storage::disk('local')->exists($old)) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($old);
        }
        Setting::put('stamp_path', null);
        Setting::put('stamp_mime', null);
    }
}
