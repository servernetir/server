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
                'queue' => new \App\Services\Sms\QueuedSmsSender(
                    array_filter((array) config('services.sms.ippanel.patterns', [])),
                    (string) config('services.sms.ippanel.variable', 'code'),
                ),
                'kavenegar' => new \App\Services\Sms\KavenegarSender(
                    config('services.sms.kavenegar.key'),
                    config('services.sms.kavenegar.template'),
                    config('services.sms.kavenegar.sender'),
                ),
                default => null,
            };

            return $sender?->enabled() ? $sender : new \App\Services\Sms\LogSmsSender();
        });

        // درگاه‌های پرداخت — افزودن درگاه بعدی فقط یک register اینجاست
        $this->app->singleton(\App\Services\Payment\GatewayRegistry::class, function () {
            $registry = new \App\Services\Payment\GatewayRegistry();

            $registry->register(new \App\Services\Payment\ZarinPalGateway(
                config('services.zarinpal.merchant_id'),
                (bool) config('services.zarinpal.sandbox'),
            ));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->defineRateLimiters();

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
