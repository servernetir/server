<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\NotificationTemplate;
use App\Models\PaymentAccount;
use App\Models\ServiceCost;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * تنظیمات — یک صفحه با **تب**، به‌جای فهرستِ بلندِ قبلی.
 *
 * ═══ چرا تب، و چرا سمتِ سرور ═══
 *
 * تا امروز همهٔ تنظیمات در **یک فرمِ واحد** بودند: حسابِ بانکی، مهر، نرخِ یورو،
 * توکنِ پنج زیرساخت، Cloudflare، Proxmox، گوگل‌کلندر و درصدهای سود، همه پشتِ سرِ
 * هم در یک صفحهٔ ۵۰۰ خطی. پیدا کردنِ یک عدد یعنی اسکرولِ کور، و بدتر: هر بار
 * ذخیره، **همهٔ** آن کلیدها دوباره نوشته می‌شدند.
 *
 * تب‌ها سمتِ سرور انتخاب می‌شوند (`?tab=`)، نه با جاوااسکریپت. سه دلیل:
 *   ۱) فقط فیلدهای همان تب در DOM هستند ⇒ فرمِ سنگین و تودرتو نمی‌شود.
 *      (صفحهٔ قبلی یک بار به‌خاطرِ `<form>`ِ تودرتو **هیچ تنظیمی را ذخیره
 *      نمی‌کرد** — دکمهٔ ذخیره به هیچ فرمی وصل نبود.)
 *   ۲) `back()` بعد از ذخیره به همان تب برمی‌گردد، چون تب در URL است.
 *   ۳) بی‌جاوااسکریپت هم کار می‌کند.
 *
 * 🔴 ═══ خطرِ اصلیِ تب‌بندی، و قفلی که برایش گذاشته شد ═══
 *
 * وقتی فرم به تب‌ها شکسته شود، فیلدهای تب‌های دیگر در درخواست **نیستند**. الگوی
 * قبلیِ نوشتن (`filled($data[$k] ?? null) ? … : null`) آن‌ها را «خالی» می‌دید و
 * `null` می‌نوشت — یعنی ذخیرهٔ تبِ «حساب‌ها» بی‌صدا نرخِ یورو و توکنِ زیرساخت را
 * پاک می‌کرد. بی‌هیچ خطایی.
 *
 * برای همین `FIELDS` **به تفکیکِ تب** تعریف شده و `update()` فقط کلیدهای همان
 * تبی را می‌نویسد که فرستاده شده. تبِ ناشناخته یعنی خطا، نه «همه‌چیز».
 *
 * ═══ راهنمای الگو: تنظیمِ تازه کجا می‌رود ═══
 *
 * ۱) کلید را زیرِ **یک** تب در `FIELDS` بگذار (فقط یکی — تستِ
 *    `AdminSettingsTabsTest` تکراری را رد می‌کند).
 * ۲) نوشتنش را در متدِ `save<Tab>()` همان تب اضافه کن.
 * ۳) فیلدش را در `resources/views/admin/settings/<tab>.blade.php` رندر کن.
 *
 * هر سه لازم است و تست هر سه را می‌سنجد: فیلدی که در ویو باشد و در `FIELDS`
 * نه، **بی‌صدا ذخیره نمی‌شود** — همان دسته باگی که این صفحه یک بار خورد.
 */
class SettingsController extends Controller
{
    /** تب‌ها به همان ترتیبی که در نوار دیده می‌شوند. */
    public const TABS = [
        'general'  => ['t' => 'عمومی',          'icon' => 'i-wrench'],
        'accounts' => ['t' => 'حساب‌ها',         'icon' => 'i-coins'],
        'pricing'  => ['t' => 'نرخ‌گذاری و سود', 'icon' => 'i-trend'],
        'infra'    => ['t' => 'زیرساخت و CDN',   'icon' => 'i-cloud'],
        'costs'    => ['t' => 'هزینه‌ها',         'icon' => 'i-tag'],
        'messages' => ['t' => 'الگوی پیام‌ها',    'icon' => 'i-mail'],
        'bale'     => ['t' => 'رباتِ بله',         'icon' => 'i-bot'],
        'guide'    => ['t' => 'راهنما',           'icon' => 'i-info'],
    ];

    /**
     * فیلدهای فرمِ اصلیِ تنظیمات، **به تفکیکِ تب**.
     *
     * این آرایه هم‌زمان سه کار می‌کند: قواعدِ اعتبارسنجی، تعیینِ اینکه هر ذخیره
     * چه کلیدهایی را لمس کند، و نقشهٔ «هر تنظیم کجاست». یکی‌بودنشان عمدی است —
     * سه فهرستِ موازی همان چیزی است که «دورهٔ شش‌ماهه در ۷ جا» را ساخت.
     *
     * ⚠️ تب‌های `costs` و `messages` این‌جا فیلدی ندارند: جدولِ خودشان را دارند
     * و به مسیرِ خودشان POST می‌کنند. `guide` هم فقط متن است.
     */
    private const FIELDS = [
        'general' => [
            'stamp'                => ['nullable', 'file', 'mimetypes:image/png,image/jpeg', 'max:2048'],
            'remove_stamp'         => ['nullable', 'boolean'],
            /*
            | نمادِ اعتماد الکترونیکی — کنارِ مهرِ شرکت، چون هر دو «هویتِ رسمیِ
            | شرکت»اند و مدیر هر دو را یک‌جا می‌خواهد.
            |
            | ⚠️ عمداً `putSecret()` **نیست**: این دو مقدار روی هر صفحهٔ سایت
            | داخلِ آدرسِ تصویرِ مهر چاپ می‌شوند. رمزنگاری‌شان فقط توهمِ «راز
            | است» می‌ساخت و بعد کسی با همان فرض جای دیگری هم لوشان می‌داد.
            |
            | ⚠️ الگوی حروف‌وعدد عمدی است: کدِ نماد فقط همین است، و ورودیِ
            | حاوی `<` یا `"` مستقیم داخلِ `href` می‌نشیند.
            */
            'enamad_id'            => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9]*$/'],
            'enamad_code'          => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9]*$/'],
            /*
            | هویتِ حقوقی — نامِ ثبتی، شناسه‌ها و نشانی.
            |
            | 🔴 تا امروز فقط از `.env` خوانده می‌شدند و این غلط بود: برداشتنشان
            | از روزنامهٔ رسمی کارِ **اداری** است نه دیپلوی، و کسی که آن‌ها را
            | دارد لزوماً به `.env` سرور دسترسی ندارد. `.env` راهِ دوم می‌مانَد.
            |
            | ⚠️ برخلافِ نماد، الگوی حروف‌وعدد **ندارند** و این عمدی است: هیچ‌کدام
            | داخلِ `href` یا `src` نمی‌نشیند — فقط متنِ صفحه و مقدارِ JSON-LD
            | می‌شوند و هر دو مسیر خودشان escape می‌کنند. الگوی سخت‌گیرانه فقط
            | نامِ شرکتی با پرانتز یا نشانیِ واقعی با خط‌تیره را رد می‌کرد.
            */
            'company_legal_name'    => ['nullable', 'string', 'max:150'],
            'company_reg_no'        => ['nullable', 'string', 'max:40'],
            'company_national_id'   => ['nullable', 'string', 'max:40'],
            'company_economic_code' => ['nullable', 'string', 'max:40'],
            'company_address'       => ['nullable', 'string', 'max:250'],
            'company_city'          => ['nullable', 'string', 'max:60'],
            'company_province'      => ['nullable', 'string', 'max:60'],
            'company_postcode'      => ['nullable', 'string', 'max:20'],
            'google_client_id'     => ['nullable', 'string', 'max:200'],
            'google_client_secret' => ['nullable', 'string', 'max:200'],
            'google_forget'        => ['nullable', 'boolean'],
            // پیامکِ بین‌المللی (Amazon SNS) — کدِ تأییدِ مشتریِ خارجی
            'aws_sns_key'          => ['nullable', 'string', 'max:128', 'regex:/^[A-Z0-9]*$/'],
            'aws_sns_secret'       => ['nullable', 'string', 'max:128'],
            'aws_sns_region'       => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9-]*$/'],
            'aws_sns_forget'       => ['nullable', 'boolean'],
            // فروشِ محصولاتِ مستقر در ایران به مشتریِ بدونِ احراز هویت
            'iran_sales_open_to_unverified' => ['nullable', 'boolean'],
        ],
        'accounts' => [
            'bank_holder'  => ['nullable', 'string', 'max:120'],
            'bank_name'    => ['nullable', 'string', 'max:80'],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'bank_sheba'   => ['nullable', 'string', 'max:34'],
            'bank_card'    => ['nullable', 'string', 'max:20'],
            'bank_note'    => ['nullable', 'string', 'max:300'],
        ],
        'pricing' => [
            'pricing_baseline_rate' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'pricing_rate_override' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'pricing_usd_rate_override' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'pricing_fx_fee_pct'        => ['nullable', 'numeric', 'min:0', 'max:25'],
            'price_margin_pct'      => ['nullable', 'numeric', 'min:-50', 'max:500'],
            'cloud_margin_pct'      => ['nullable', 'numeric', 'min:0', 'max:500'],
            // صفر مجاز است: فروشِ دامنه به بهای تمام‌شده یک استراتژیِ جذب است
            'domain_margin_pct'     => ['nullable', 'numeric', 'min:0', 'max:500'],
            'cloud_ipv4_eur_cents'  => ['nullable', 'integer', 'min:-1', 'max:10000'],
        ],
        'infra' => [
            'cloudflare_token'   => ['nullable', 'string', 'max:200'],
            'cloudflare_zone_id' => ['nullable', 'string', 'max:64', 'regex:/^[a-f0-9]*$/i'],
            'cloudflare_forget'  => ['nullable', 'boolean'],
            'hetzner_api_token'  => ['nullable', 'string', 'max:300'],
            'hetzner_forget'     => ['nullable', 'boolean'],
            'aeza_api_token'     => ['nullable', 'string', 'max:300'],
            'aeza_forget'        => ['nullable', 'boolean'],
            'arvan_api_token'    => ['nullable', 'string', 'max:400'],
            'arvan_forget'       => ['nullable', 'boolean'],
            'ovh_app_key'        => ['nullable', 'string', 'max:200'],
            'ovh_app_secret'     => ['nullable', 'string', 'max:200'],
            'ovh_consumer_key'   => ['nullable', 'string', 'max:200'],
            'ovh_forget'         => ['nullable', 'boolean'],
            'salad_api_key'      => ['nullable', 'string', 'max:300'],
            'salad_gateway_secret' => ['nullable', 'string', 'max:200'],
            'salad_forget'       => ['nullable', 'boolean'],
            'salad_org'          => ['nullable', 'string', 'max:120'],
            'salad_project'      => ['nullable', 'string', 'max:120'],
            'salad_image'        => ['nullable', 'string', 'max:200'],
            // فقط دامنهٔ پایه (مثلِ servernet.cloud) — نه اسکیم، نه اسلش
            'salad_branded_domain' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9.-]*$/'],
            'salad_priority'     => ['nullable', 'string', 'in:high,medium,low,batch'],
            'salad_vcpu_usd_hour'   => ['nullable', 'numeric', 'min:0', 'max:10'],
            'salad_ram_gb_usd_hour' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'proxmox_token_secret'   => ['nullable', 'string', 'max:200'],
            'proxmox_forget'         => ['nullable', 'boolean'],
            'proxmox_api_url'        => ['nullable', 'string', 'max:200'],
            'proxmox_node'           => ['nullable', 'string', 'max:64'],
            'proxmox_token_id'       => ['nullable', 'string', 'max:120'],
            'proxmox_template_vmid'  => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'proxmox_storage'        => ['nullable', 'string', 'max:64'],
            'proxmox_bridge'         => ['nullable', 'string', 'max:32'],
            'proxmox_gateway'        => ['nullable', 'string', 'max:45'],
            'proxmox_ip_start'       => ['nullable', 'string', 'max:45'],
            'proxmox_exit_countries' => ['nullable', 'string', 'max:200'],
            'agent_pull_token'       => ['nullable', 'string', 'max:200'],
            'agent_forget'           => ['nullable', 'boolean'],
            /*
            | سقفِ روزانهٔ محافظِ سوءاستفاده.
            |
            | 🔴 کفِ ۱ عمدی است: صفر یعنی «همه‌چیز را نگه دار» (فروش می‌خوابد) و
            | خالی یعنی «به پیش‌فرضِ کد برگرد». هیچ‌کدام نباید بی‌صدا محافظ را
            | خاموش کند. سقفِ ۱۰۰ هم عمدی است — بزرگ‌تر عملاً یعنی بی‌محافظ، و
            | آن باید تصمیمِ کدی باشد نه یک تایپ در فرم.
            */
            'cloud_guard_daily_max'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'cloud_traffic_unlimited' => ['nullable', 'boolean'],
            'aeza_include_promo'      => ['nullable', 'boolean'],
            'domain_nameservers'      => ['nullable', 'string', 'max:500'],
        ],
        'costs'    => [],
        'messages' => [],
        'guide'    => [],
    ];

    /** کلیدهای سادهٔ حسابِ بانکی — هم خوانده می‌شوند هم نوشته. */
    private const BANK_KEYS = ['bank_holder', 'bank_name', 'bank_account', 'bank_sheba', 'bank_card', 'bank_note'];

    /** توکنِ تک‌کلیدیِ زیرساخت‌ها: همه یک الگو دارند (`{p}_api_token` + `{p}_forget`). */
    private const CLOUD_PROVIDERS = ['hetzner', 'aeza', 'arvan'];

    /** کلیدهای سادهٔ Proxmox — خالی یعنی «به پیش‌فرضِ درایور برگرد». */
    private const PROXMOX_PLAIN = [
        'proxmox_api_url', 'proxmox_node', 'proxmox_token_id', 'proxmox_template_vmid',
        'proxmox_storage', 'proxmox_bridge', 'proxmox_gateway', 'proxmox_ip_start',
        'proxmox_exit_countries',
    ];

    /**
     * پیکربندیِ سادهٔ زیرساختِ GPU — سرّی نیست، پس رمزنگاری نمی‌شود.
     *
     * ⚠️ دو نرخِ آخر عمداً تنظیماتی‌اند نه سخت‌کد: بهایِ تمام‌شدهٔ آن زیرساخت
     * «قیمتِ GPU + vCPU + رم» است و دو تکهٔ دوم **در API نیستند**. اگر روزی
     * عوض شوند و ما عددِ کهنه را نگه داریم، روی هر ساعت زیرِ قیمتِ خرید
     * می‌فروشیم — بی‌هیچ خطایی. همان تلهٔ `aeza_price_divisor`.
     */
    private const SALAD_PLAIN = [
        'salad_org', 'salad_project', 'salad_image', 'salad_priority', 'salad_branded_domain',
        'salad_vcpu_usd_hour', 'salad_ram_gb_usd_hour',
    ];

    /** فیلدهای اعتبارسنجیِ یک تب — برای تست هم استفاده می‌شود. */
    public static function fieldsFor(string $tab): array
    {
        return self::FIELDS[$tab] ?? [];
    }

    public function edit(Request $request): View
    {
        $ready = Schema::hasTable('settings');
        $tab = (string) $request->query('tab', 'general');
        if (! isset(self::TABS[$tab])) {
            $tab = 'general';
        }

        return view('admin.settings', [
            'tab'      => $tab,
            'tabs'     => self::TABS,
            'notReady' => ! $ready,
        ] + $this->dataFor($tab, $ready));
    }

    /**
     * دادهٔ همان تب — نه بیشتر.
     *
     * ⚠️ عمداً per-tab است: تبِ «حساب‌ها» نباید کاتالوگِ پرداخت‌های رمزارز و
     * جدولِ الگوی پیام‌ها را هم کوئری کند. صفحهٔ قبلی همه‌چیز را همیشه می‌خواند.
     */
    private function dataFor(string $tab, bool $ready): array
    {
        return match ($tab) {
            'general'  => $this->generalData($ready),
            'accounts' => $this->accountsData($ready),
            'pricing'  => $this->pricingData($ready),
            'infra'    => $this->infraData($ready),
            'costs'    => $this->costsData(),
            'bale'     => $this->baleData(),
            'messages' => $this->messagesData(),
            default    => [],
        };
    }

    private function generalData(bool $ready): array
    {
        // پیش‌نمایشِ مهر به‌صورت data-uri — فایل بیرونِ webroot است، پس لینکِ
        // عمومی و symlink لازم نیست.
        $stampData = null;
        if ($ready && ($p = Setting::get('stamp_path')) && Storage::disk('local')->exists($p)) {
            $mime = Setting::get('stamp_mime') ?: 'image/png';
            $stampData = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($p));
        }

        /*
         * گوگل‌کلندر — اعتبارنامهٔ **اپ**، نه حسابِ کسی.
         *
         * ⚠️ Client ID عمداً به فرم برمی‌گردد و Secret نه: اولی عمومی است (در
         * URLِ ورودِ گوگل دیده می‌شود) و مدیر باید ببیند کدام client ثبت شده،
         * ولی دومی مثلِ بقیهٔ رازها یک‌طرفه است.
         */
        $token = $ready ? \App\Models\GoogleCalendarToken::forUser(request()->user()?->id) : null;

        return [
            'stampData' => $stampData,
            /*
             * ⚠️ برخلافِ رازها، هر دو مقدار به فرم **برمی‌گردند**: نمادِ اعتماد
             * روی هر صفحهٔ سایت داخلِ آدرسِ تصویر چاپ می‌شود، پس راز نیست — و
             * مدیر باید ببیند چه چیزی ثبت شده تا با پنلِ enamad مقایسه کند.
             */
            'enamad' => [
                'id'   => $ready ? (string) Setting::get('enamad_id', '') : '',
                'code' => $ready ? (string) Setting::get('enamad_code', '') : '',
            ],
            /*
             * هویتِ حقوقی.
             *
             * ⚠️ `company_value()` و نه `Setting::get()`: اگر مقداری از قبل در
             * `.env` باشد باید در فرم **دیده شود**، وگرنه مدیر فیلد را خالی
             * می‌بیند، پُرش می‌کند و بی‌آنکه بداند یک مقدارِ دوم جای دیگری
             * می‌مانَد که تشخیصِ منبعش بعداً کابوس است.
             */
            'company' => [
                'legal_name'    => $ready ? company_value('legal_name') : '',
                'reg_no'        => $ready ? company_value('registration_no') : '',
                'national_id'   => $ready ? company_value('national_id') : '',
                'economic_code' => $ready ? company_value('economic_code') : '',
                'address'       => $ready ? company_value('address.street') : '',
                'city'          => $ready ? company_value('address.city') : '',
                'province'      => $ready ? company_value('address.province') : '',
                'postcode'      => $ready ? company_value('address.postcode') : '',
            ],
            'google'    => [
                'client_id' => $ready ? (string) Setting::get('google_client_id') : '',
                'ready'     => $ready && filled(Setting::get('google_client_id'))
                    && filled(Setting::getSecret('google_client_secret')),
                'connected'  => $token !== null,
                'email'      => $token?->google_email,
                'last_error' => $token?->last_error,
                'synced_at'  => $token?->synced_at?->diffForHumans(),
            ],
        ];
    }

    private function accountsData(bool $ready): array
    {
        $bank = [];
        foreach (self::BANK_KEYS as $k) {
            $bank[$k] = $ready ? Setting::get($k) : null;
        }

        $paReady = Schema::hasTable('payment_accounts');
        $cwReady = Schema::hasTable('crypto_wallets');
        $hasPayments = $cwReady && Schema::hasTable('crypto_payments');

        return [
            'bank'         => $bank,
            'accounts'     => $paReady ? PaymentAccount::ordered()->get() : collect(),
            'paNotReady'   => ! $paReady,
            'wallets'      => $cwReady ? CryptoWallet::orderBy('chain')->orderBy('id')->get() : collect(),
            'cwNotReady'   => ! $cwReady,
            // پرداخت‌هایی که خودکار تسویه نشدند و منتظرِ چشمِ آدم‌اند
            'review' => $hasPayments
                ? CryptoPayment::whereIn('status', ['manual', 'unmatched'])->latest('id')->limit(50)->get()
                : collect(),
            /*
            | پرداخت‌های **در جریان**.
            |
            | ⚠️ بی‌این فهرست، مدیر هیچ راهی نداشت بفهمد چرا یک آدرس «مشغول»
            | است یا چرا گزینهٔ رمزارز به مشتریِ بعدی نشان داده نمی‌شود. همان
            | سکوتی که یک بار به «قابلیت اصلاً کار نمی‌کند» تعبیر شد.
            |
            | منقضی‌های ۲۴ ساعتِ اخیر هم می‌آیند: پرداختی که نیمه‌کاره رها شده
            | خودش یک خبر است، و آدرسش تا پایانِ دورهٔ خنک‌شدن برنمی‌گردد.
            */
            'inflight' => $hasPayments
                ? CryptoPayment::where(fn ($q) => $q
                    ->whereIn('status', ['pending', 'seen'])
                    ->orWhere(fn ($e) => $e->where('status', 'expired')->where('updated_at', '>=', now()->subDay())))
                    ->latest('id')->limit(50)->get()
                : collect(),
        ];
    }

    private function pricingData(bool $ready): array
    {
        return [
            'pricing' => [
                'pricing_baseline_rate' => $ready ? Setting::get('pricing_baseline_rate') : null,
                'pricing_rate_override' => $ready ? Setting::get('pricing_rate_override') : null,
                'pricing_usd_rate_override' => $ready ? Setting::get('pricing_usd_rate_override') : null,
                'pricing_fx_fee_pct'        => $ready ? Setting::get('pricing_fx_fee_pct') : null,
                'price_margin_pct'      => $ready ? Setting::get('price_margin_pct') : null,
                'cloud_margin_pct'      => $ready ? Setting::get('cloud_margin_pct') : null,
                'domain_margin_pct'     => $ready ? Setting::get('domain_margin_pct') : null,
                'cloud_ipv4_eur_cents'  => $ready ? Setting::get('cloud_ipv4_eur_cents') : null,
            ],
            'liveRate'    => app(\App\Services\ExchangeRate::class)->toToman('EUR'),
            'priceFactor' => $ready ? price_factor() : 1.0,
        ];
    }

    private function infraData(bool $ready): array
    {
        return [
            'cloud' => [
                'cloudflare' => $ready && filled(Setting::getSecret('cloudflare_token')),
                'cf_zone'    => $ready ? Setting::get('cloudflare_zone_id') : null,
                'hetzner'    => $ready && filled(Setting::getSecret('hetzner_api_token')),
                'aeza'       => $ready && filled(Setting::getSecret('aeza_api_token')),
                'arvan'      => $ready && filled(Setting::getSecret('arvan_api_token')),
                // OVH سه‌کلیدی است؛ «تنظیم‌شده» یعنی هر سه هستند، وگرنه امضا
                // ساخته نمی‌شود و هر تماس ۴۰۳ می‌گیرد.
                'ovh' => $ready && filled(Setting::getSecret('ovh_app_key'))
                    && filled(Setting::getSecret('ovh_app_secret'))
                    && filled(Setting::getSecret('ovh_consumer_key')),
                'proxmox'        => $ready && filled(Setting::getSecret('proxmox_token_secret')),
                // ⚠️ «تنظیم‌شده» یعنی کلید **و** نامِ سازمان — هر مسیرِ آن API
                // نامِ سازمان را در خودش دارد، پس کلیدِ تنها هیچ کاری نمی‌کند.
                'salad' => $ready && filled(Setting::getSecret('salad_api_key'))
                    && filled(Setting::get('salad_org')),
                'sl' => [
                    'org'      => $ready ? Setting::get('salad_org') : null,
                    'project'  => $ready ? Setting::get('salad_project') : null,
                    'image'    => $ready ? Setting::get('salad_image') : null,
                    'priority' => $ready ? Setting::get('salad_priority') : null,
                    'branded'  => $ready ? Setting::get('salad_branded_domain') : null,
                    'vcpu'     => $ready ? Setting::get('salad_vcpu_usd_hour') : null,
                    'ram'      => $ready ? Setting::get('salad_ram_gb_usd_hour') : null,
                ],
                'agent'          => $ready && filled(Setting::getSecret('agent_pull_token')),
                'exit_countries' => $ready ? Setting::get('proxmox_exit_countries') : null,
                'guard'          => $ready ? Setting::get('cloud_guard_daily_max') : null,
                'promo'          => $ready && Setting::get('aeza_include_promo') === '1',
                'unlimited'      => $ready && Setting::get('cloud_traffic_unlimited') === '1',
                'dns'            => $ready ? Setting::get('domain_nameservers') : null,
                'plans'          => $ready && Schema::hasTable('cloud_plans')
                    ? \App\Models\CloudPlan::where('is_active', true)->count() : 0,
                'px' => [
                    'api_url'  => $ready ? Setting::get('proxmox_api_url') : null,
                    'node'     => $ready ? Setting::get('proxmox_node') : null,
                    'token_id' => $ready ? Setting::get('proxmox_token_id') : null,
                    'template' => $ready ? Setting::get('proxmox_template_vmid') : null,
                    'storage'  => $ready ? Setting::get('proxmox_storage') : null,
                    'bridge'   => $ready ? Setting::get('proxmox_bridge') : null,
                    'gateway'  => $ready ? Setting::get('proxmox_gateway') : null,
                    'ip_start' => $ready ? Setting::get('proxmox_ip_start') : null,
                ],
            ],
        ];
    }

    /**
     * تبِ رباتِ بله — اتصال، کلیدِ روشن/خاموش، و وضعیتِ وب‌هوک.
     *
     * ⚠️ خودِ کارِ نوشتن (اتصال/قطع/روشن) هنوز به `/admin/bale/*` می‌رود، نه به
     * فرمِ عمومیِ تنظیمات: آن مسیرها throttle و گاردِ `admin` مخصوصِ خودشان را
     * دارند و کدِ اتصال ایمیل می‌فرستد. این تب فقط رابطِ همان‌هاست.
     */
    private function baleData(): array
    {
        $gate = app(\App\Services\Bale\Admin\AdminBaleGate::class);

        return [
            'baleEnabled' => $gate->enabled(),
            'baleUser'    => $gate->boundUser(),
            'baleBind'    => $gate->binding(),
            'balePending' => $gate->pendingHuman(),
            'baleWebhook' => app(\App\Http\Controllers\Admin\BaleAdminController::class)->webhookState(),
        ];
    }

    private function costsData(): array
    {
        $ready = Schema::hasTable('service_costs');

        return [
            'costs'         => $ready ? ServiceCost::orderByDesc('is_system')->orderBy('label')->get() : collect(),
            'costsNotReady' => ! $ready,
        ];
    }

    private function messagesData(): array
    {
        $ready = Schema::hasTable('notification_templates');
        $rows = $ready ? NotificationTemplate::query()->orderBy('group')->orderBy('id')->get() : collect();

        return [
            'groups'    => $rows->groupBy('group'),
            'labels'    => NotificationTemplate::GROUPS,
            'tplNotReady' => ! $ready,
        ];
    }

    // ══════════════════════════ ذخیره ══════════════════════════

    public function update(Request $request): RedirectResponse
    {
        /*
         * 🔴 تب اول اعتبارسنجی می‌شود و بعد **فقط** فیلدهای همان تب.
         *
         * بی‌این، ذخیرهٔ هر تب کلیدهای تب‌های دیگر را — که در درخواست نیستند —
         * `null` می‌نوشت. یعنی ذخیرهٔ شمارهٔ کارت، توکنِ زیرساخت و نرخِ یورو را
         * بی‌صدا پاک می‌کرد. تبِ ناشناخته هم به «همه» تعبیر نمی‌شود.
         */
        $tab = (string) $request->input('tab', '');
        abort_unless(isset(self::TABS[$tab]), 422, 'تبِ ناشناخته');

        $data = $request->validate(self::FIELDS[$tab]);

        match ($tab) {
            'general'  => $this->saveGeneral($request, $data),
            'accounts' => $this->saveAccounts($data),
            'pricing'  => $this->savePricing($data),
            'infra'    => $this->saveInfra($request, $data),
            default    => null,                        // costs/messages/guide فرمِ خودشان را دارند
        };

        return back()->with('ok', 'تنظیمات ذخیره شد.');
    }

    private function saveGeneral(Request $request, array $data): void
    {
        // مهر: بیرونِ webroot در storage؛ روی فاکتور به‌صورت data-uri جاسازی می‌شود.
        if ($request->boolean('remove_stamp')) {
            $this->deleteStamp();
        } elseif ($request->hasFile('stamp') && $request->file('stamp')->isValid()) {
            $this->deleteStamp();
            $file = $request->file('stamp');
            $path = $file->storeAs('company', 'stamp.'.$file->extension(), 'local');
            Setting::put('stamp_path', $path);
            Setting::put('stamp_mime', $file->getClientMimeType());
        }

        /*
         * ⚠️ «فراموش کن» توکنِ **همهٔ کاربران** را هم پاک می‌کند: با رفتنِ
         * اعتبارنامهٔ اپ، آن refresh tokenها دیگر قابلِ تبدیل نیستند و ماندنشان
         * فقط یک ردیفِ مرده است که وانمود می‌کند حساب هنوز وصل است.
         */
        // پیامکِ بین‌المللی — همان الگوی گوگل: شناسه ساده، راز رمزنگاری‌شده
        if ($request->boolean('aws_sns_forget')) {
            Setting::put('aws_sns_key', null);
            Setting::put('aws_sns_region', null);
            Setting::putSecret('aws_sns_secret', null);
        } else {
            foreach (['aws_sns_key', 'aws_sns_region'] as $k) {
                if (filled($data[$k] ?? null)) {
                    Setting::put($k, trim((string) $data[$k]));
                }
            }

            if (filled($data['aws_sns_secret'] ?? null)) {
                Setting::putSecret('aws_sns_secret', trim((string) $data['aws_sns_secret']));
            }
        }

        if ($request->boolean('google_forget')) {
            Setting::put('google_client_id', null);
            Setting::putSecret('google_client_secret', null);

            if (Schema::hasTable('google_calendar_tokens')) {
                \App\Models\GoogleCalendarToken::query()->delete();
            }

            return;
        }

        /*
         * نمادِ اعتماد.
         *
         * ⚠️ برخلافِ اعتبارنامهٔ گوگل، این دو با `filled()` شرط نمی‌شوند: خالی
         * فرستادن باید یعنی **پاک کردن**. اگر مثلِ بالا فقط پرها را بنویسیم،
         * مدیر هرگز نمی‌تواند نمادِ باطل‌شده را بردارد و مهرِ منقضی تا ابد روی
         * فوتر می‌مانَد — که از نبودِ مهر بدتر است.
         *
         * ⚠️ `putSecret()` عمداً نه: هر دو مقدار روی هر صفحهٔ سایت داخلِ آدرسِ
         * تصویر چاپ می‌شوند، پس راز نیستند.
         */
        /*
         * نماد + هویتِ حقوقی — هر دو با همان قاعده: **خالی یعنی پاک کن**.
         *
         * ⚠️ افزودن به `FIELDS` کافی نیست و یک بار همین‌جا گیر کرد: آن آرایه
         * فقط اعتبارسنجی می‌کند و نوشتن در `save*()` صریح است. بی‌این حلقه،
         * فرم بی‌هیچ خطایی ذخیره نمی‌کرد — بدترین حالت، چون «ذخیره شد» هم
         * می‌گفت.
         */
        $plain = [
            'enamad_id', 'enamad_code',
            'company_legal_name', 'company_reg_no', 'company_national_id',
            'company_economic_code', 'company_address', 'company_city',
            'company_province', 'company_postcode',
        ];

        foreach ($plain as $k) {
            if (array_key_exists($k, $data)) {
                Setting::put($k, filled($data[$k]) ? trim((string) $data[$k]) : null);
            }
        }

        if (filled($data['google_client_id'] ?? null)) {
            Setting::put('google_client_id', trim((string) $data['google_client_id']));
        }
        if (filled($data['google_client_secret'] ?? null)) {
            Setting::putSecret('google_client_secret', trim((string) $data['google_client_secret']));
        }
    }

    private function saveAccounts(array $data): void
    {
        foreach (self::BANK_KEYS as $k) {
            Setting::put($k, isset($data[$k]) ? trim((string) $data[$k]) : null);
        }
    }

    private function savePricing(array $data): void
    {
        foreach (array_keys(self::FIELDS['pricing']) as $k) {
            // خالی = «خاموش / پیش‌فرضِ کد»، نه صفر
            Setting::put($k, filled($data[$k] ?? null) ? (string) $data[$k] : null);
        }
    }

    private function saveInfra(Request $request, array $data): void
    {
        // Cloudflare: توکن **رمزنگاری‌شده** و هرگز به فرم برنمی‌گردد. خالی یعنی
        // «دست نزن»؛ برای حذف تیکِ جداگانه هست (مثلِ توکنِ WHM).
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

        foreach (self::CLOUD_PROVIDERS as $p) {
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

        // Proxmox: فقط `token_secret` سرّی است. «فراموش کن» فقط توکن را پاک
        // می‌کند؛ کانفیگِ ساده می‌مانَد تا با توکنِ تازه دوباره کار کند.
        if ($request->boolean('proxmox_forget')) {
            Setting::putSecret('proxmox_token_secret', null);
        } elseif (filled($data['proxmox_token_secret'] ?? null)) {
            Setting::putSecret('proxmox_token_secret', trim((string) $data['proxmox_token_secret']));
        }

        if ($request->boolean('agent_forget')) {
            Setting::putSecret('agent_pull_token', null);
        } elseif (filled($data['agent_pull_token'] ?? null)) {
            Setting::putSecret('agent_pull_token', trim((string) $data['agent_pull_token']));
        }

        // زیرساختِ GPU: فقط کلیدِ API سرّی است. «فراموش کن» فقط کلید را پاک
        // می‌کند؛ نامِ سازمان و پروژه می‌مانند تا با کلیدِ تازه دوباره کار کند.
        if ($request->boolean('salad_forget')) {
            Setting::putSecret('salad_api_key', null);
        } elseif (filled($data['salad_api_key'] ?? null)) {
            Setting::putSecret('salad_api_key', trim((string) $data['salad_api_key']));
        }

        /*
        | دروازهٔ فروشِ ایران — ذخیرهٔ صریحِ '1' یعنی باز؛ هر چیزِ دیگر (از
        | جمله نبودِ ردیف) یعنی بسته. پیش‌فرضِ بسته عمدی است: تنظیمی که گم
        | شود نباید بازار را به روی احرازنشده باز کند.
        */
        Setting::put(\App\Services\Customer\IranSalesGate::SETTING,
            $request->boolean('iran_sales_open_to_unverified') ? '1' : null);

        // رازِ دروازهٔ برندشده — باید بایت‌به‌بایت با GATE_SECRETِ Worker یکی باشد
        if (filled($data['salad_gateway_secret'] ?? null)) {
            Setting::putSecret('salad_gateway_secret', trim((string) $data['salad_gateway_secret']));
        }

        foreach (self::SALAD_PLAIN as $k) {
            Setting::put($k, filled($data[$k] ?? null) ? trim((string) $data[$k]) : null);
        }

        foreach (self::PROXMOX_PLAIN as $k) {
            Setting::put($k, filled($data[$k] ?? null) ? trim((string) $data[$k]) : null);
        }

        foreach (['cloud_guard_daily_max', 'domain_nameservers'] as $k) {
            Setting::put($k, filled($data[$k] ?? null) ? (string) $data[$k] : null);
        }

        Setting::put('aeza_include_promo', $request->boolean('aeza_include_promo') ? '1' : null);

        // ⚠️ نمایشِ «ترافیک نامحدود» یک **وعدهٔ تجاری** است، نه توصیفِ فنی:
        // سقفِ واقعیِ زیرساخت سرِ جایش می‌مانَد و اگر مشتری رد شود هزینه‌اش با
        // ماست. برای همین کلید است و نه سخت‌کد — تا بدونِ دیپلوی خاموش شود.
        Setting::put('cloud_traffic_unlimited', $request->boolean('cloud_traffic_unlimited') ? '1' : null);
    }

    private function deleteStamp(): void
    {
        $old = Setting::get('stamp_path');
        if ($old && Storage::disk('local')->exists($old)) {
            Storage::disk('local')->delete($old);
        }
        Setting::put('stamp_path', null);
        Setting::put('stamp_mime', null);
    }
}
