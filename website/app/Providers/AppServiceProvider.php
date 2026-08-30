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
                /*
                | رلهٔ بله — پیام از راهِ یک گروهِ خصوصیِ بله به n8n در ایران
                | می‌رود و آن‌جا به آی‌پی‌پنل تبدیل می‌شود.
                |
                | ⚠️ چرا درایور و نه سرویسِ مستقل: با این کار **هیچ** فراخوانی
                | در کد عوض نمی‌شود. `SmsDispatcher` همان `event/otp/raw` را
                | صدا می‌زند و فقط مقصد فرق می‌کند — پس هیچ نقطهٔ فراخوانی از
                | قلم نمی‌افتد، که در جایگزینیِ دستیِ ده‌ها نقطه حتمی بود.
                |
                | 🔴 مسیر `services.sms.bale_relay` است نه `services.bale_relay`.
                |    بلوکش در `config/services.php` کنارِ `ippanel` و `kavenegar`
                |    **داخلِ** آرایهٔ `sms` نشسته. یک بار این‌جا مسیرِ سطحِ بالا
                |    نوشته شد و نتیجه‌اش دقیقاً همان خرابیِ خاموشِ همیشگی بود:
                |    `.env` درست، `env()` درست، ولی `config()` خالی ⇒ `enabled()`
                |    کاذب ⇒ بی‌هیچ خطایی سقوط به `LogSmsSender`. یعنی سایت
                |    می‌گفت پیامک فرستادم و هیچ پیامکی نمی‌رفت.
                */
                'bale_relay' => new \App\Services\Sms\BaleRelaySender(
                    config('services.sms.bale_relay.bot_token'),
                    config('services.sms.bale_relay.chat_id'),
                    config('services.sms.bale_relay.secret'),
                    (string) config('services.sms.bale_relay.base', 'https://tapi.bale.ai'),
                ),
                /*
                | ✅ مسیرِ فعال — همان پاکت و همان امضا، ولی مستقیم به وب‌هوکِ
                | n8n. حلقهٔ بله حذف شد چون بله پیامِ ربات را به ربات تحویل
                | نمی‌دهد و آن زنجیره هرگز کامل نمی‌شد.
                */
                'n8n_relay' => new \App\Services\Sms\N8nRelaySender(
                    config('services.sms.n8n_relay.url'),
                    config('services.sms.n8n_relay.secret'),
                ),
                'kavenegar' => new \App\Services\Sms\KavenegarSender(
                    config('services.sms.kavenegar.key'),
                    config('services.sms.kavenegar.template'),
                    config('services.sms.kavenegar.sender'),
                ),
                default => null,
            };

            if ($sender?->enabled()) {
                return $sender;
            }

            /*
            | 🔴 سقوط به «لاگ» باید **رد** بگذارد.
            |
            | یک تایپ در `.env` — مثلاً `SMS_DRIVER=n8n-relay` با خط تیره —
            | باعث می‌شد `match` به `default` بیفتد، `LogSmsSender` بنشیند، و
            | **کدِ ورودِ همهٔ کاربران** فقط در فایلِ لاگ نوشته شود. ثبت‌نام و
            | ورودِ کلِ سایت می‌ایستاد، `/admin/errors` خالی بود، و تنها راهِ
            | فهمیدنش خواندنِ دستیِ لاگ بود.
            |
            | ⚠️ حالتِ عمدی (`SMS_DRIVER=log` یا خالی، مثلِ محیطِ تست) ساکت
            | می‌مانَد — وگرنه هر اجرای تست ردیاب را پر می‌کرد و هشدارِ واقعی
            | زیرِ نویز گم می‌شد.
            */
            if (filled($driver) && $driver !== 'log') {
                $why = $sender === null
                    ? 'درایورِ ناشناخته: '.$driver
                    : 'درایورِ '.$driver.' نیم‌پیکربندی است (مقادیرِ لازم در .env نیستند)';

                \App\Support\ErrorTracker::note('sms', $why.' — پیامک فقط در لاگ نوشته می‌شود');

                \Illuminate\Support\Facades\Cache::put('sms:last_error', [
                    'driver'   => 'log',
                    'template' => '-',
                    'reason'   => $why,
                    'at'       => now()->toIso8601String(),
                ], now()->addDay());
            }

            return new \App\Services\Sms\LogSmsSender();
        });

        // بله — کانال دوم موازی پیامک
        $this->app->singleton(\App\Services\Bale\BaleSender::class, fn () => new \App\Services\Bale\BaleSender(
            config('services.bale.token'),
            (string) config('services.bale.base', 'https://tapi.bale.ai'),
        ));

        /*
        | سفیرِ بله — پیام به **شمارهٔ موبایل**، بی‌نیاز به ورودِ کاربر به ربات.
        |
        | 🔴 چرا مهم است: مسیرِ قدیمی `chat_id` می‌خواست و آن فقط وقتی وجود
        | داشت که کاربر خودش وارد ربات شده باشد. یعنی کانالِ بله برای اکثریتِ
        | مشتری‌ها **بی‌صدا خاموش** بود. با سفیر، بله به مسیرِ دومِ واقعی تبدیل
        | می‌شود — همان چیزی که وقتی پیامک نمی‌رسد، تفاوتِ بینِ ورودِ موفق و
        | مشتریِ پشتِ درِ بسته است.
        |
        | ⚠️ فقط پیام‌های سمتِ مشتری. اعلانِ مدیر و گروهِ داخلی از `BaleSender`
        | می‌روند تا یک خطای اعتبار در سفیر، هشدارهای داخلی را هم نخواباند.
        */
        $this->app->singleton(\App\Services\Bale\BaleSafirSender::class, fn () => new \App\Services\Bale\BaleSafirSender(
            config('services.bale_safir.key'),
            filled(config('services.bale_safir.bot_id')) ? (int) config('services.bale_safir.bot_id') : null,
            (string) config('services.bale_safir.base', 'https://safir.bale.ai'),
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
     *
     * 🔴 و چرا **عکسِ** منو جدا نگه داشته می‌شود: چون همین‌جا روی
     * `servernet.mega` می‌نویسیم، اگر `SiteMenu::mega()` هم از همان کلید بخواند،
     * خروجی‌اش ورودیِ پاسِ بعدی می‌شود و ترنسفورم روی خودش می‌دود (برچسبِ
     * دوباره، لینکِ فراگیرِ تکراری، مکان‌های دوبرابر). پس نسخهٔ دست‌نخورده را
     * **پیش از هر رندر** در کلیدِ جدا می‌گذاریم و `mega()` از آن می‌خواند.
     *
     * ⚠️ اینجا و نه تنبل‌وار داخلِ خودِ `mega()`: boot همیشه قبل از ویوها اجرا
     * می‌شود، پس این عکس قطعاً دست‌نخورده است. عکسِ تنبل، «اولین صدا زدن» را
     * تعیین‌کننده می‌کرد و همان ترتیب‌حساسی از درِ دیگر برمی‌گشت.
     */
    private function syncSiteMenu(): void
    {
        config([\App\Services\SiteMenu::SOURCE => config('servernet.mega')]);

        View::composer('partials.header', function () {
            try {
                /*
                | ⚠️ ترتیب مهم است: اول `SiteMenu` (که گروهِ «موقعیت مکانی» را
                | زنده از کاتالوگ می‌سازد) و **بعد** رویهٔ مدیر. برعکسش یعنی
                | کشورِ تازه‌ای که کاتالوگ اضافه می‌کند از فیلترِ خاموشی/ترتیبِ
                | مدیر رد نمی‌شود و نامش هم قابلِ ویرایش نیست.
                */
                config(['servernet.mega' => app(\App\Services\MenuManager::class)
                    ->mega(app(\App\Services\SiteMenu::class)->mega())]);
            } catch (\Throwable) {
                // منو هرگز نباید صفحه را بشکند؛ در بدترین حالت همان config می‌مانَد
            }
        });
    }

    /**
     * نمای «همه»ی سرویس‌ها (`/account/services`) چهار بخش دارد، ولی کنترلرش
     * فقط `$services` می‌فرستد — و آن فایل مالکِ دیگری دارد و نباید ویرایش شود.
     *
     * 🔴 پس دادهٔ بخش‌ها با یک composer تزریق می‌شود، نه با عوض‌کردنِ کنترلر:
     * این‌طور هر تغییری که آن کنترلر بعداً بگیرد (فهرستِ وضعیت، eager loadها)
     * خودبه‌خود در بخش‌ها هم دیده می‌شود.
     *
     * ⚠️ کلیدها با پیشوندِ `sec` نام‌گذاری شده‌اند تا اگر روزی همان کنترلر
     * چیزی به همین نام‌ها بفرستد، تصادفِ خاموش رخ ندهد؛ composer پس از کنترلر
     * اجرا می‌شود و مقدارِ کنترلر را می‌پوشانَد.
     */
    private function feedTheServicesOverview(): void
    {
        View::composer('account.services', function (ViewInstance $view) {
            $data = $view->getData();

            // اتاق‌های اختصاصی خودشان این داده را می‌سازند؛ دوباره نساز.
            if (array_key_exists('secBuckets', $data)) {
                return;
            }

            $view->with(\App\Support\PanelSections::build(
                \Illuminate\Support\Facades\Auth::guard('customer')->user(),
                collect($data['services'] ?? []),
            ) + ['secKind' => null, 'secLens' => 'all']);
        });
    }

    public function boot(): void
    {
        $this->shareSessionAcrossSubdomains();
        $this->defineRateLimiters();
        $this->syncSiteMenu();
        $this->keepSchedulerOffTheDatabase();
        $this->feedTheServicesOverview();
        $this->purgePageCacheOnContentChanges();
        // ↑ ترتیب مهم است: تنظیمِ دامنهٔ کوکی باید پیش از میدل‌ورِ StartSession
        //   انجام شود، و boot()ِ provider همیشه قبل از میدل‌ورها اجرا می‌شود.

        // متغیرهای مشترک همه‌ی ویوها: زبان جاری، لینک سوییچ زبان‌ها و اطلاعات تماس
        View::composer('*', function (ViewInstance $view) {
            // 🔴 کش باید با **زبان و روت** کلید بخورد، نه یک `static` تک‌مقداری.
            //
            // قبلاً یک `static $shared` بود و فقط یک بار پر می‌شد. زیر php-fpm
            // بی‌ضرر بود (هر درخواست پروسهٔ تازه)، ولی هر جا دو زبان در یک
            // پروسه رندر شوند مقدارِ اولی روی بقیه می‌ماند: ورکرِ صف که ایمیلِ
            // مشتریِ فارسی و بعد انگلیسی را می‌سازد، به دومی هم لینک‌ها و
            // شمارهٔ تماسِ فارسی می‌دهد. (تستِ SupportPhoneLocaleTest دقیقاً
            // همین را گرفت: `/` و بعد `/en` در یک تست.)
            static $cache = [];

            $locale = app()->getLocale();
            $routeName = Route::currentRouteName() ?? 'home';
            $cacheKey = $locale.'|'.$routeName.'|'.md5(serialize(request()->route()?->parameters() ?? []));

            if (! isset($cache[$cacheKey])) {
                $baseRoute = preg_replace('/^(en|tr)\./', '', $routeName);
                $params = request()->route()?->parameters() ?? [];

                // برخی روت‌ها (مثل پنل ادمین) نسخه‌ی زبانی ندارند؛ در آن صورت به خانه fallback می‌شود
                $localeUrls = [];
                foreach (self::LOCALES as $code => $prefix) {
                    $home = url($prefix === '' ? '/' : '/'.rtrim($prefix, '.'));

                    if (! \Illuminate\Support\Facades\Route::has($prefix.$baseRoute)) {
                        $localeUrls[$code] = $home;

                        continue;
                    }

                    /*
                    | 🔴 «روت وجود دارد» تضمین نمی‌کند «پارامترهایش را داریم».
                    |
                    | `Route::currentRouteName()` روی یک **۴۰۴** خالی نمی‌شود؛
                    | مقدارِ آخرین روتِ تطبیق‌یافتهٔ همان پروسه در آن می‌مانَد.
                    | پس زنجیرهٔ زیر واقعی است:
                    |
                    |   درخواستِ ۱ → /account/reseller/module/whmcs   (روتِ پارامتردار)
                    |   درخواستِ ۲ → /account/reseller/module/hacker  (هیچ روتی تطبیق نمی‌کند ⇒ ۴۰۴)
                    |     ↓ نامِ روت هنوز کهنه است، ولی `request()->route()` نال است
                    |     ↓ پس $params خالی می‌شود
                    |   route('account.reseller.module', [])  ⇒ UrlGenerationException
                    |     ↓ صفحهٔ ۴۰۴ می‌ترکد و کاربر **۵۰۰** می‌گیرد
                    |
                    | زیرِ php-fpm پنهان است (هر درخواست پروسهٔ تازه)، ولی در
                    | تست، در ورکرِ صف، و زیرِ Octane قطعی است.
                    |
                    | ⚠️ سوییچرِ زبان یک قابلیتِ **تزئینی** است؛ هیچ‌وقت نباید
                    | صفحه را بخواباند. نبودنِ لینکِ زبان بی‌آزار است، ۵۰۰ نه.
                    */
                    try {
                        $localeUrls[$code] = route($prefix.$baseRoute, $params);
                    } catch (\Throwable) {
                        $localeUrls[$code] = $home;
                    }
                }

                $routePrefix = self::LOCALES[$locale] ?? '';

                $cache[$cacheKey] = [
                    'isFa'        => $locale === 'fa',
                    'localeUrls'  => $localeUrls,
                    'faUrl'       => $localeUrls['fa'],
                    'enUrl'       => $localeUrls['en'],
                    'trUrl'       => $localeUrls['tr'],
                    'routePrefix' => $routePrefix,
                    'homeUrl'     => route($routePrefix.'home'),
                    // ⚠️ `site_contact()` نه `config()` — شماره زبان‌محور است
                    'contact'     => site_contact(),
                    'social'      => site_social(),
                ];
            }

            $view->with($cache[$cacheKey]);
        });
    }

    /**
     * 🔴 قفلِ زمان‌بند روی **فایل** می‌نشیند، نه روی کش (که یعنی دیتابیس).
     *
     * ═══ باگی که این را لازم کرد ═══
     *
     * `CACHE_STORE` پیش‌فرض `database` است، و هر کارِ زمان‌بندی‌شده در این پروژه
     * `withoutOverlapping()` دارد. آن متد قفلش را در **کش** می‌گیرد. پس زنجیره
     * این بود:
     *
     *     یک لحظه قطعیِ MariaDB
     *       → `CacheEventMutex` استثنا می‌دهد
     *       → کلِ `schedule:run` می‌میرد
     *       → آن دقیقه **هیچ** کاری اجرا نمی‌شود
     *
     * یعنی تحویلِ سرور، ثبتِ دامنه، فاکتورِ تمدید و مترِ ساعتی — همه با یک
     * قطعیِ گذرایِ دیتابیس می‌ایستند. ردیابِ خطا در یک روز ۱۳ بار
     * `Connection refused` روی جدولِ `cache` ثبت کرده بود؛ یعنی ۱۳ دقیقهٔ مرده
     * که هیچ‌کس ندید، چون خطا در لاگِ کرون بود نه در سایت.
     *
     * ⚠️ همان دیتابیسی که ممکن است بمیرد، نباید نگهبانِ کاری باشد که قرار است
     * از مرگش خبر دهد. فایل هیچ وابستگیِ شبکه‌ای ندارد و روی cPanel همیشه هست.
     *
     * ⚠️ این جایگزینِ `CACHE_STORE=file` در `.env` **نیست**، بلکه مستقل از آن
     * کار می‌کند: حتی اگر روزی کسی کش را دوباره روی دیتابیس ببرد، زمان‌بند
     * سالم می‌مانَد. عمداً در کد است و نه در env — پیکربندیِ حیاتی که فراموش
     * شود، همان باگ را برمی‌گرداند.
     */
    private function keepSchedulerOffTheDatabase(): void
    {
        $this->callAfterResolving(
            \Illuminate\Console\Scheduling\Schedule::class,
            fn ($schedule) => $schedule->useCache('file'),
        );
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
            /*
            | 🔴 تأیید و ارسالِ دوباره **دو سطلِ جدا** لازم دارند.
            |
            | با یک سطلِ ۶تایی، کاربری که سه بار کد را غلط بزند و سه بار
            | «ارسال دوباره» بزند به سقف می‌خورد و صفحهٔ خامِ انگلیسیِ
            | «Too Many Requests» می‌بیند — نه پیامِ فارسی، نه راهِ برگشت.
            |
            | و بدتر: `OtpService::MAX_ATTEMPTS = 10` که کامنتش می‌گوید «۱۰
            | تلاش، نه ۱۰۰۰۰۰۰»، در عمل **هرگز قابلِ رسیدن نبود** چون throttle
            | زودتر از آن می‌بست. یعنی محافظِ اصلی هیچ‌وقت اجرا نمی‌شد و
            | محافظِ فرعی جایش را گرفته بود — با پیامی که کاربر نمی‌فهمد.
            |
            | `verify` سخاوتمندتر است چون غلط‌تایپ‌کردن عادی است؛ `resend` تنگ‌تر
            | چون هر بارش یک پیامکِ پولی است.
            */
            'otp'    => [15, 10],   // تأییدِ کد — سقفِ واقعی در OtpService است
            'resend' => [4, 10],    // ارسالِ دوباره — هر بار پول خرج می‌کند
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
            // هر اجرای تست سرعت ~۱۵ درخواست است (پینگ×۸ + دانلود×۳ + آپلود×۳)؛
            // ۶۰ در دقیقه یعنی حدوداً ۳ اجرا — کافی برای کاربر، سقف برای پهنای باند
            'speedtest' => [60, 1],
            // رویدادهای قیف (sendBeacon) — سطلِ جدا (شورا/امنیت): نباید با
            // ابزارهای ۴۰تاییِ tools قاطی شود؛ یک صفحهٔ سفارش ۳–۵ رویداد می‌فرستد
            'beacon' => [120, 1],
        ];

        foreach ($buckets as $name => [$max, $minutes]) {
            \Illuminate\Support\Facades\RateLimiter::for(
                $name,
                fn (\Illuminate\Http\Request $request) => \Illuminate\Cache\RateLimiting\Limit::perMinutes($minutes, $max)
                    ->by($name.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            );
        }
    }

    /**
     * ابطالِ کشِ صفحه با هر تغییری در محتوایی که روی صفحه‌های عمومی می‌نشیند.
     *
     * ═══ چرا (ممیزی ۴ — CTO) ═══
     *
     * «کش بدونِ ابطال یک بدهی است، نه دارایی: بعد از تغییرِ قیمت، نسخهٔ HIT
     * تا پایانِ TTL قیمتِ قدیمی را به کاربرِ ناشناس نشان می‌دهد — ریسکِ حقوقی
     * و تجاری، دقیقاً روی ادعای قیمت‌گذاریِ شفاف.» با این هوک‌ها، کهنگی «تا
     * اولین ذخیره» است: هر saved/deleted روی مدل‌های قیمت/محتوا، نسلِ کش را
     * جلو می‌برد و همهٔ صفحه‌های کش‌شده در همان لحظه نامعتبر می‌شوند.
     *
     * ⚠️ فهرستِ مدل‌ها عمداً «هر چیزی که صفحهٔ عمومی می‌سازد» است، نه فقط
     * قیمت: پستِ ویرایش‌شده، پلنِ ابری، سرورِ فیزیکی، تنظیماتِ نرخ. مدلی که
     * این‌جا جا بیفتد یعنی همان «قیمتِ کهنه تا TTL» برای همان موجودیت.
     *
     * ⚠️ `class_exists` برای اینکه autoload نشکسته روی نصبِ ناقص، بوتِ کلِ
     * اپ را نخواباند — همان قاعدهٔ «فوتر حق ندارد سایت را ۵۰۰ کند».
     */
    private function purgePageCacheOnContentChanges(): void
    {
        $models = [
            \App\Models\Product::class,
            \App\Models\CloudPlan::class,
            \App\Models\CloudLocation::class,
            \App\Models\PhysicalServer::class,
            \App\Models\Post::class,
            \App\Models\PostTranslation::class,
            \App\Models\Setting::class,
            \App\Models\StatusIncident::class,
        ];

        $purge = fn () => \App\Http\Middleware\PageCache::purge();

        foreach ($models as $model) {
            if (class_exists($model)) {
                $model::saved($purge);
                $model::deleted($purge);
            }
        }
    }
}
