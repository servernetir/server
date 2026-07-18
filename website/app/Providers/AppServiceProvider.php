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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
}
