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

        // ارائه‌دهندگانِ سرورِ ابری — فقط «تنظیم‌شده یا نه». خودِ توکن هرگز به
        // فرم برنمی‌گردد (مثلِ توکنِ WHM و Cloudflare).
        $cloud = [
            'hetzner' => $ready && filled(Setting::getSecret('hetzner_api_token')),
            'aeza'    => $ready && filled(Setting::getSecret('aeza_api_token')),
            'arvan'   => $ready && filled(Setting::getSecret('arvan_api_token')),
            // OVH سه‌کلیدی است؛ «تنظیم‌شده» یعنی هر سه هستند، وگرنه امضا
            // ساخته نمی‌شود و هر تماس ۴۰۳ می‌گیرد.
            'ovh'     => $ready && filled(Setting::getSecret('ovh_app_key'))
                && filled(Setting::getSecret('ovh_app_secret'))
                && filled(Setting::getSecret('ovh_consumer_key')),
            // Proxmox: فقط توکنِ سرّی رمزنگاری‌شده است؛ «تنظیم‌شده» یعنی همان هست.
            'proxmox' => $ready && filled(Setting::getSecret('proxmox_token_secret')),
            // توکنِ pull-agentِ هاستِ ایران (رمزنگاری‌شده) + فهرستِ کشورهای خروج.
            'agent'          => $ready && filled(Setting::getSecret('agent_pull_token')),
            'exit_countries' => $ready ? Setting::get('proxmox_exit_countries') : null,
            'margin'  => $ready ? Setting::get('cloud_margin_pct') : null,
            // سقفِ روزانهٔ محافظِ سوءاستفاده — خالی یعنی «پیش‌فرضِ کد»
            'guard'   => $ready ? Setting::get('cloud_guard_daily_max') : null,
            'ipv4'    => $ready ? Setting::get('cloud_ipv4_eur_cents') : null,
            // 🔴 «۱ یورو چند روبل» حذف شد: حسابِ زیرساختِ ۲ فقط یورو می‌تواند
            // باشد (پاسخِ کتبیِ پشتیبانی‌شان)، پس هیچ تبدیلِ ارزی در کار نیست.
            // فیلدی که چیزی را عوض نمی‌کند از نبودنش بدتر است.
            'promo'   => $ready && Setting::get('aeza_include_promo') === '1',
            'unlimited' => $ready && Setting::get('cloud_traffic_unlimited') === '1',
            'plans'   => $ready && Schema::hasTable('cloud_plans')
                ? \App\Models\CloudPlan::where('is_active', true)->count() : 0,
            // دامنه — درصد سود و نام‌سرورِ پیش‌فرض
            'dmargin' => $ready ? Setting::get('domain_margin_pct') : null,
            'dns'     => $ready ? Setting::get('domain_nameservers') : null,
            // Proxmox — کانفیگِ ساده (غیرِ سرّی) برای پیش‌پرکردنِ فرم. خالی = پیش‌فرض.
            'px'      => [
                'api_url'  => $ready ? Setting::get('proxmox_api_url') : null,
                'node'     => $ready ? Setting::get('proxmox_node') : null,
                'token_id' => $ready ? Setting::get('proxmox_token_id') : null,
                'template' => $ready ? Setting::get('proxmox_template_vmid') : null,
                'storage'  => $ready ? Setting::get('proxmox_storage') : null,
                'bridge'   => $ready ? Setting::get('proxmox_bridge') : null,
                'gateway'  => $ready ? Setting::get('proxmox_gateway') : null,
                'ip_start' => $ready ? Setting::get('proxmox_ip_start') : null,
            ],
        ];

        /*
         * گوگل‌کلندر — اعتبارنامهٔ **اپ**، نه حسابِ کسی.
         *
         * ⚠️ Client ID عمداً به فرم برمی‌گردد و Secret نه: اولی عمومی است (در
         * URLِ ورودِ گوگل دیده می‌شود) و مدیر باید ببیند کدام client ثبت شده،
         * ولی دومی مثلِ بقیهٔ رازها یک‌طرفه است.
         */
        $googleToken = $ready
            ? \App\Models\GoogleCalendarToken::forUser(request()->user()?->id)
            : null;

        $google = [
            'client_id' => $ready ? (string) Setting::get('google_client_id') : '',
            'ready'     => $ready && filled(Setting::get('google_client_id'))
                && filled(Setting::getSecret('google_client_secret')),
            // اتصالِ **همین کاربر** — نه شرکت. صفحهٔ تقویم دیگر این را نشان نمی‌دهد.
            'connected'  => $googleToken !== null,
            'email'      => $googleToken?->google_email,
            'last_error' => $googleToken?->last_error,
            'synced_at'  => $googleToken?->synced_at?->diffForHumans(),
        ];

        return view('admin.settings', [
            'bank'        => $bank,
            'stampData'   => $stampData,
            'pricing'     => $pricing,
            'liveRate'    => $liveRate,
            'priceFactor' => $ready ? price_factor() : 1.0,
            'cloud'       => $cloud,
            'google'      => $google,
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
            // ارائه‌دهندگانِ سرورِ ابری — توکن را **خودِ مدیر** وارد می‌کند
            'hetzner_api_token'     => ['nullable', 'string', 'max:300'],
            'hetzner_forget'        => ['nullable', 'boolean'],
            'aeza_api_token'        => ['nullable', 'string', 'max:300'],
            'aeza_forget'           => ['nullable', 'boolean'],
            'arvan_api_token'       => ['nullable', 'string', 'max:400'],
            'ovh_app_key'           => ['nullable', 'string', 'max:200'],
            'ovh_app_secret'        => ['nullable', 'string', 'max:200'],
            'ovh_consumer_key'      => ['nullable', 'string', 'max:200'],
            'arvan_forget'          => ['nullable', 'boolean'],
            // Proxmox — فقط token_secret سرّی است؛ بقیه کانفیگِ ساده با پیش‌فرض
            'proxmox_token_secret'  => ['nullable', 'string', 'max:200'],
            'proxmox_forget'        => ['nullable', 'boolean'],
            'proxmox_api_url'       => ['nullable', 'string', 'max:200'],
            'proxmox_node'          => ['nullable', 'string', 'max:64'],
            'proxmox_token_id'      => ['nullable', 'string', 'max:120'],
            'proxmox_template_vmid' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'proxmox_storage'       => ['nullable', 'string', 'max:64'],
            'proxmox_bridge'        => ['nullable', 'string', 'max:32'],
            'proxmox_gateway'       => ['nullable', 'string', 'max:45'],
            'proxmox_ip_start'      => ['nullable', 'string', 'max:45'],
            // کشورهای خروجِ Exit VPS (CSV) و توکنِ pull-agentِ ایران
            'proxmox_exit_countries' => ['nullable', 'string', 'max:200'],
            'agent_pull_token'      => ['nullable', 'string', 'max:200'],
            'agent_forget'          => ['nullable', 'boolean'],
            'cloud_margin_pct'      => ['nullable', 'numeric', 'min:0', 'max:500'],
            /*
            | سقفِ روزانهٔ محافظِ سوءاستفاده — بندِ (ب).
            |
            | 🔴 کفِ ۱ عمدی است: صفر یعنی «همه‌چیز را نگه دار» (فروش می‌خوابد) و
            | خالی یعنی «به پیش‌فرضِ کد برگرد». هیچ‌کدام نباید بی‌صدا محافظ را
            | خاموش کند. سقفِ ۱۰۰ هم عمدی است — عددِ بزرگ‌تر عملاً یعنی محافظ
            | ندارید، و آن باید یک تصمیمِ کدی باشد نه یک تایپ در فرم.
            |
            | ⚠️ این عدد **برای همه** یکسان است. معافیتِ حساب عمداً وجود ندارد؛
            | دلیلش در docblockِ CloudFraudGuard نوشته شده.
            */
            'cloud_guard_daily_max' => ['nullable', 'integer', 'min:1', 'max:100'],
            // درصد سود دامنه — صفر مجاز است (استراتژیِ جذبِ مشتری)
            'domain_margin_pct'     => ['nullable', 'numeric', 'min:0', 'max:500'],
            'domain_nameservers'    => ['nullable', 'string', 'max:500'],
            'cloud_traffic_unlimited' => ['nullable'],
            'cloud_ipv4_eur_cents'  => ['nullable', 'integer', 'min:-1', 'max:10000'],
            // واحدِ عددِ قیمت در API زیرساختِ ۲: ۱۰۰ = سنتِ یورو · ۱ = یورو
            // پلنِ تشویقی: پیش‌فرض کنار می‌رود چون قیمتِ تمدیدش پایدار نیست
            'aeza_include_promo'     => ['nullable', 'boolean'],
            // گوگل‌کلندر — اعتبارنامهٔ OAuth اپ
            'google_client_id'      => ['nullable', 'string', 'max:200'],
            'google_client_secret'  => ['nullable', 'string', 'max:200'],
            'google_forget'         => ['nullable', 'boolean'],
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

        // توکنِ ارائه‌دهندگانِ ابری — همان الگو: رمزنگاری‌شده، هرگز برنمی‌گردد،
        // خالی‌فرستادن یعنی «دست نزن» و برای حذف تیکِ جدا هست.
        foreach (['hetzner', 'aeza', 'arvan'] as $p) {
            if ($request->boolean($p.'_forget')) {
                Setting::putSecret($p.'_api_token', null);
            } elseif (filled($data[$p.'_api_token'] ?? null)) {
                Setting::putSecret($p.'_api_token', trim((string) $data[$p.'_api_token']));
            }
        }

        // OVH سه کلیدِ جدا دارد، پس از حلقهٔ بالا (که یک `_api_token` فرض
        // می‌کند) بیرون است. «فراموش کن» هر سه را با هم پاک می‌کند — دو کلید
        // از سه‌تا یعنی امضای همیشه‌غلط و ۴۰۳ِ بی‌توضیح.
        if ($request->boolean('ovh_forget')) {
            foreach (['ovh_app_key', 'ovh_app_secret', 'ovh_consumer_key'] as $k) {
                Setting::putSecret($k, null);
            }
        } else {
            foreach (['ovh_app_key', 'ovh_app_secret', 'ovh_consumer_key'] as $k) {
                if (filled($data[$k] ?? null)) {
                    Setting::putSecret($k, trim((string) $data[$k]));
                }
            }
        }

        /*
         * گوگل‌کلندر. Client ID ساده ذخیره می‌شود (عمومی است و باید در فرم
         * دیده شود)، Secret رمزنگاری‌شده و «خالی = دست نزن».
         *
         * ⚠️ «فراموش کن» توکنِ **همهٔ کاربران** را هم پاک می‌کند: با رفتنِ
         * اعتبارنامهٔ اپ، آن refresh tokenها دیگر قابلِ تبدیل نیستند و ماندنشان
         * فقط یک ردیفِ مرده است که وانمود می‌کند حساب هنوز وصل است.
         */
        if ($request->boolean('google_forget')) {
            Setting::put('google_client_id', null);
            Setting::putSecret('google_client_secret', null);

            if (Schema::hasTable('google_calendar_tokens')) {
                \App\Models\GoogleCalendarToken::query()->delete();
            }
        } else {
            if (filled($data['google_client_id'] ?? null)) {
                Setting::put('google_client_id', trim((string) $data['google_client_id']));
            }
            if (filled($data['google_client_secret'] ?? null)) {
                Setting::putSecret('google_client_secret', trim((string) $data['google_client_secret']));
            }
        }

        // Proxmox: مثلِ OVH یک بلوکِ جدا چون چندکلیدی است — ولی فقط `token_secret`
        // رمزنگاری‌شده است (بقیه کانفیگِ ساده‌اند). «فراموش کن» فقط توکن را پاک
        // می‌کند؛ کانفیگِ ساده می‌مانَد تا با توکنِ تازه دوباره کار کند.
        if ($request->boolean('proxmox_forget')) {
            Setting::putSecret('proxmox_token_secret', null);
        } elseif (filled($data['proxmox_token_secret'] ?? null)) {
            Setting::putSecret('proxmox_token_secret', trim((string) $data['proxmox_token_secret']));
        }

        // توکنِ pull-agentِ ایران: مثلِ Proxmox رمزنگاری‌شده، هرگز به فرم برنمی‌گردد،
        // خالی یعنی «دست نزن» و برای حذف تیکِ جدا هست.
        if ($request->boolean('agent_forget')) {
            Setting::putSecret('agent_pull_token', null);
        } elseif (filled($data['agent_pull_token'] ?? null)) {
            Setting::putSecret('agent_pull_token', trim((string) $data['agent_pull_token']));
        }

        foreach (['proxmox_api_url', 'proxmox_node', 'proxmox_token_id', 'proxmox_template_vmid',
            'proxmox_storage', 'proxmox_bridge', 'proxmox_gateway', 'proxmox_ip_start',
            'proxmox_exit_countries'] as $k) {
            // خالی = بازگشت به پیش‌فرض (درایور خودش پیش‌فرض دارد)
            Setting::put($k, filled($data[$k] ?? null) ? trim((string) $data[$k]) : null);
        }

        foreach (['cloud_margin_pct', 'cloud_ipv4_eur_cents', 'cloud_guard_daily_max',
            'domain_margin_pct', 'domain_nameservers'] as $k) {
            Setting::put($k, filled($data[$k] ?? null) ? (string) $data[$k] : null);
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

        Setting::put('aeza_include_promo', $request->boolean('aeza_include_promo') ? '1' : null);

        // ⚠️ نمایشِ «ترافیک نامحدود» یک **وعدهٔ تجاری** است، نه توصیفِ فنی:
        // سقفِ واقعیِ زیرساخت سرِ جایش می‌مانَد و اگر مشتری رد شود هزینه‌اش با
        // ماست. برای همین کلید است و نه سخت‌کد — تا بدونِ دیپلوی خاموش شود.
        Setting::put('cloud_traffic_unlimited', $request->boolean('cloud_traffic_unlimited') ? '1' : null);

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
