<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /** زبان‌های سایت: کد زبان => پیشوند نام روت */
    public const LOCALES = ['fa' => '', 'en' => 'en.', 'tr' => 'tr.'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // خروجی JSON اعداد اعشاری را با کوتاه‌ترین نمایش تولید کن (جلوگیری از 1.69999…)
        ini_set('serialize_precision', '-1');

        // احراز هویت ایرانی — پیاده‌سازی فعلی زحل است
        $this->app->singleton(
            \App\Services\Identity\IdentityProvider::class,
            \App\Services\Identity\ZohalProvider::class,
        );

        // پیامک — درایور انتخابی فقط وقتی می‌نشیند که واقعاً پیکربندی شده باشد.
        // نیم‌پیکربندی (توکن هست، خط فرستنده نیست) بی‌سروصدا به لاگ برمی‌گردد
        // تا ثبت‌نام نشکند؛ دستور `sms:test` این حالت را صریح گزارش می‌کند.
        $this->app->singleton(\App\Services\Sms\SmsSender::class, function () {
            $driver = config('services.sms.driver');

            $sender = match ($driver) {
                'ippanel' => new \App\Services\Sms\IppanelSender(
                    config('services.sms.ippanel.token'),
                    config('services.sms.ippanel.from'),
                    array_filter((array) config('services.sms.ippanel.patterns', [])),
                    (string) config('services.sms.ippanel.variable', 'code'),
                    config('services.sms.relay_url'),
                    config('services.sms.relay_secret'),
                ),
                // صف: سرور ایران خودش می‌آید و خالی‌اش می‌کند.
                // لازم است چون آی‌پی‌پنل به آی‌پی آلمان سرویس نمی‌دهد و سرور
                // ایران هم اتصال ورودی از آلمان را نمی‌پذیرد.
                // بدون فهرست الگو: انتخاب الگو کار سرور ایران است، و
                // نگه داشتن همان فهرست در دو جا یعنی دیر یا زود یکی کهنه
                // می‌شود و پیامک بی‌صدا نمی‌رود.
                'queue' => new \App\Services\Sms\QueuedSmsSender(),
                'kavenegar' => new \App\Services\Sms\KavenegarSender(
                    config('services.sms.kavenegar.key'),
                    config('services.sms.kavenegar.template'),
                    config('services.sms.kavenegar.sender'),
                ),
                default => null,
            };

            return $sender?->enabled() ? $sender : new \App\Services\Sms\LogSmsSender();
        });

        // بله — کانال دوم موازی پیامک
        $this->app->singleton(\App\Services\Bale\BaleSender::class, fn () => new \App\Services\Bale\BaleSender(
            config('services.bale.token'),
            (string) config('services.bale.base', 'https://tapi.bale.ai'),
        ));

        // درگاه‌های پرداخت — افزودن درگاه بعدی فقط یک register اینجاست
        $this->app->singleton(\App\Services\Payment\GatewayRegistry::class, function () {
            $registry = new \App\Services\Payment\GatewayRegistry();

            $registry->register(new \App\Services\Payment\ZarinPalGateway(
                config('services.zarinpal.merchant_id'),
                (bool) config('services.zarinpal.sandbox'),
            ));

            // درگاه بله — از کیف پول بله، برای مشتریانی که بله را وصل کرده‌اند
            $registry->register(new \App\Services\Payment\BaleGateway(
                $this->app->make(\App\Services\Bale\BaleSender::class),
                config('services.bale.wallet'),
            ));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    /**
     * منوی سایت را با کاتالوگِ زنده همگام کن.
     *
     * ⚠️ چرا با بازنویسیِ config و نه ویرایشِ ویو: `partials/header.blade.php`
     * در خطِ اول خودش `config('servernet.mega')` را می‌خواند. اگر متغیرِ مشترک
     * بفرستیم، همان خط رویش را می‌نویسد. پس مقدارِ config را **درست پیش از
     * رندرِ هدر** جایگزین می‌کنیم؛ ویو دست‌نخورده می‌مانَد و منو زنده می‌شود.
     *
     * روی composer و نه boot(): پرس‌وجوی دیتابیس فقط وقتی انجام شود که هدر
     * واقعاً رندر می‌شود — نه در هر درخواستِ API یا فرمانِ کرون.
     */
    private function syncSiteMenu(): void
    {
        View::composer('partials.header', function () {
            try {
                config(['servernet.mega' => app(\App\Services\SiteMenu::class)->mega()]);
            } catch (\Throwable) {
                // منو هرگز نباید صفحه را بشکند؛ در بدترین حالت همان config می‌مانَد
            }
        });
    }

    public function boot(): void
    {
        $this->shareSessionAcrossSubdomains();
        $this->defineRateLimiters();
        $this->syncSiteMenu();
        // ↑ ترتیب مهم است: تنظیمِ دامنهٔ کوکی باید پیش از میدل‌ورِ StartSession
        //   انجام شود، و boot()ِ provider همیشه قبل از میدل‌ورها اجرا می‌شود.

        // متغیرهای مشترک همه‌ی ویوها: زبان جاری، لینک سوییچ زبان‌ها و اطلاعات تماس
        View::composer('*', function (ViewInstance $view) {
            static $shared = null;

            if ($shared === null) {
                $locale = app()->getLocale();
                $routeName = Route::currentRouteName() ?? 'home';
                $baseRoute = preg_replace('/^(en|tr)\./', '', $routeName);
                $params = request()->route()?->parameters() ?? [];

                // برخی روت‌ها (مثل پنل ادمین) نسخه‌ی زبانی ندارند؛ در آن صورت به خانه fallback می‌شود
                $localeUrls = [];
                foreach (self::LOCALES as $code => $prefix) {
                    $localeUrls[$code] = \Illuminate\Support\Facades\Route::has($prefix.$baseRoute)
                        ? route($prefix.$baseRoute, $params)
                        : url($prefix === '' ? '/' : '/'.rtrim($prefix, '.'));
                }

                $routePrefix = self::LOCALES[$locale] ?? '';

                $shared = [
                    'isFa'        => $locale === 'fa',
                    'localeUrls'  => $localeUrls,
                    'faUrl'       => $localeUrls['fa'],
                    'enUrl'       => $localeUrls['en'],
                    'trUrl'       => $localeUrls['tr'],
                    'routePrefix' => $routePrefix,
                    'homeUrl'     => route($routePrefix.'home'),
                    'contact'     => config('servernet.contact'),
                    'social'      => config('servernet.social'),
                ];
            }

            $view->with($shared);
        });
    }

    /**
     * محدودکننده‌های نام‌دار.
     *
     * چرا لازم است: `throttle:5,60` بی‌نام، کلیدش فقط «دامنه|IP» است — نه مسیر.
     * یعنی همهٔ روت‌های throttle‌دار یک شمارنده مشترک دارند و سخت‌گیرترین سقف
     * روی همه اعمال می‌شود. نتیجه‌اش این بود که کاربری که مسیر عادی ثبت‌نام را
     * می‌رفت (ارسال شماره، تأیید کد، یک بار ارسال دوباره، احراز هویت) پیش از
     * رسیدن به مرحلهٔ آخر با ۴۲۹ روبه‌رو می‌شد.
     *
     * با نام دادن و by() صریح، هر گروه سطل جدای خودش را دارد.
     */
    /**
     * نشستِ مشترک بین دامنهٔ اصلی و کنسول.
     *
     * پنل روی `console.servernet.cloud` است و سایت روی `servernet.cloud`؛ اگر
     * کوکیِ نشست host-only بماند، کاربرِ واردشده در پنل روی سایتِ اصلی «مهمان»
     * دیده می‌شود و هدر به‌جای نامش «ورود» نشان می‌دهد.
     *
     * چرا این‌جا و نه فقط با SESSION_DOMAIN در .env: در .env سرور یک خطِ
     * `SESSION_DOMAIN=null` از قالبِ اولیه وجود داشت و phpDotenv **اولین** مقدارِ
     * هر کلید را نگه می‌دارد، پس خطِ دومی که بعداً اضافه شد بی‌اثر بود
     * (تشخیص با /system/whoami: session_domain=null در حالی که config_cached=false).
     * این‌جا در زمانِ اجرا ست می‌شود، پس قطعی است و به .env وابسته نیست.
     *
     * فقط برای میزبان‌های servernet.cloud اعمال می‌شود؛ روی localhost دست نمی‌زند
     * (کوکی با دامنهٔ دیگر روی localhost پذیرفته نمی‌شود و ورودِ محلی می‌شکست).
     */
    private function shareSessionAcrossSubdomains(): void
    {
        if ($this->app->runningInConsole()) {
            return;                                   // کرون/artisan میزبان واقعی ندارد
        }

        if ($domain = self::cookieDomainFor((string) request()->getHost())) {
            config([
                'session.domain' => $domain,
                // نامِ تازه عمداً: مرورگرِ کاربر کوکیِ host-only قدیمی با نامِ قبلی
                // دارد و اگر نام یکی می‌ماند، دو کوکیِ هم‌نام هم‌زمان فرستاده می‌شد و
                // اینکه کدام برنده شود قطعی نبود (ورود «قبول» می‌شد ولی روی دامنهٔ
                // دیگر مهمان می‌ماند). با نامِ نو، کوکی‌های کهنه بی‌اثر می‌شوند و
                // نیازی به پاک‌کردنِ دستیِ کوکی‌ها نیست. هزینه‌اش یک‌بار خروجِ همه است.
                'session.cookie' => 'snet_session',
            ]);
        }
    }

    /**
     * دامنهٔ کوکی برای این میزبان، یا null اگر نباید دست بزنیم.
     *
     * تطبیق سخت‌گیرانه است: فقط خودِ `servernet.cloud` و زیردامنه‌هایش. میزبانی
     * مثل `evil-servernet.cloud` نباید کوکیِ ما را بگیرد، پس با نقطه می‌سنجیم.
     */
    public static function cookieDomainFor(string $host): ?string
    {
        $host = strtolower(trim($host));

        return ($host === 'servernet.cloud' || str_ends_with($host, '.servernet.cloud'))
            ? '.servernet.cloud'
            : null;
    }

    private function defineRateLimiters(): void
    {
        $buckets = [
            // ثبت‌نام و ورود
            'reg'    => [8, 10],    // شروع ثبت‌نام
            'otp'    => [6, 10],    // تأیید و ارسال دوبارهٔ کد
            'kyc'    => [5, 60],    // استعلام پولی هویت
            'signin' => [12, 10],   // ورود
            'bank'   => [6, 60],    // استعلام پولی کارت بانکی
            // پرداخت: سخاوتمندتر از بقیه، چون بازگشت از درگاه هم از همین
            // سطل می‌خورد و کاربری که چند بار refresh کند نباید ۴۲۹ بگیرد
            // درست وقتی می‌خواهد ببیند پولش رسید یا نه
            'pay'    => [30, 10],

            // بقیهٔ سایت
            'tools'  => [40, 1],    // ابزارهای وب‌مستر و DNS
            'forms'  => [6, 10],    // فرم‌های عمومی (نظر، رزومه)
            'ai'     => [12, 1],    // چت و سازندهٔ هوشمند
        ];

        foreach ($buckets as $name => [$max, $minutes]) {
            \Illuminate\Support\Facades\RateLimiter::for(
                $name,
                fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinutes($minutes, $max)
                    ->by($name.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            );
        }
    }
}
