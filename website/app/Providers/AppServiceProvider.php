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
        //
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

                $localeUrls = [];
                foreach (self::LOCALES as $code => $prefix) {
                    $localeUrls[$code] = route($prefix.$baseRoute, $params);
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
