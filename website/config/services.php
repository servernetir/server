<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // وب‌هوک دستیار هوشمند در n8n (flow.servernet.cloud)
    'n8n' => [
        'chat_webhook' => env('N8N_CHAT_WEBHOOK_URL'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pagespeed' => [
        'key' => env('PAGESPEED_API_KEY'),
    ],

    // سازنده سایت با هوش مصنوعی (GapGPT، سازگار با OpenAI)
    // نام مدل اینجا ثابت است چون در اکانت GapGPT فقط claude-fable-5 تأمین شده؛
    // key/base از .env می‌آیند تا رمز در کد نباشد.
    'gapgpt' => [
        'key'       => env('GAPGPT_API_KEY'),
        'base'      => env('GAPGPT_BASE_URL', 'https://api.gapgpt.app/v1'),
        'model'     => 'claude-fable-5',
        'model_pro' => 'claude-fable-5',
    ],

    /*
    | DeepSeek — درگاه سازگار با OpenAI. برای ترجمه و نگارش استفاده می‌شود چون
    | به‌مراتب ارزان‌تر است و روی این سرور تحریم نیست.
    |
    | دیپ‌سیک هر دو شکل base را می‌پذیرد (با /v1 و بدون آن)، پس اگر در .env
    | یکی را گذاشتید هر دو کار می‌کنند.
    */
    'deepseek' => [
        'key'   => env('DEEPSEEK_API_KEY'),
        'base'  => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
    ],

    /*
    | کدام ارائه‌دهنده برای کدام کار.
    |
    |   پنل مدیریت (نگارش، ترجمه، سئو) و داوری کامنت  → DeepSeek
    |   دستیار چت سایت                                → ورک‌فلوی n8n (در ChatController)
    |   سایت‌ساز هوشمند                                → GapGPT (در AiBuilderController)
    |
    | اگر کلید ارائه‌دهنده‌ی انتخابی تنظیم نشده باشد، خودکار به gapgpt برمی‌گردد
    | تا هیچ بخشی به‌خاطر نبودن کلید از کار نیفتد.
    */
    'ai_routing' => [
        'translate' => env('AI_PROVIDER_TRANSLATE', 'deepseek'),
        'article'   => env('AI_PROVIDER_ARTICLE', 'deepseek'),
        'comments'  => env('AI_PROVIDER_COMMENTS', 'deepseek'),
        'seo'       => env('AI_PROVIDER_SEO', 'deepseek'),
        'outreach'  => env('AI_PROVIDER_OUTREACH', 'deepseek'),
    ],

    /*
    |----------------------------------------------------------------------
    | زحل — احراز هویت ایرانی
    |----------------------------------------------------------------------
    | مسیرها در خود ZohalProvider ثابت‌اند چون از API رسمی استخراج شده‌اند.
    | فقط توکن لازم است.
    */
    'zohal' => [
        'base_url' => env('ZOHAL_BASE_URL', 'https://service.zohal.io'),
        'token'    => env('ZOHAL_TOKEN'),
    ],

    /*
    |----------------------------------------------------------------------
    | پیامک — آی‌پی‌پنل
    |----------------------------------------------------------------------
    | برای فعال شدن دو چیز لازم است: کلید و خط فرستنده. تا وقتی نباشند درایور
    | «log» می‌نشیند — پیام در لاگ نوشته می‌شود، هیچ پیامکی نمی‌رود و هیچ پولی
    | خرج نمی‌شود.
    |
    | «patterns» مهم‌ترین بخش است: پیام آزاد ممکن است دقایقی در صف اپراتور
    | بماند، ولی پیام الگو مسیر خدماتی دارد و فوری می‌رسد. هر رویدادی که کاربر
    | منتظرش است باید الگوی خودش را داشته باشد. کد الگو را در پنل آی‌پی‌پنل
    | می‌سازید و همان‌جا نام متغیرها را تعیین می‌کنید.
    |
    | .env:
    |   SMS_DRIVER=ippanel
    |   IPPANEL_KEY=...                    ← کلید API از پنل
    |   IPPANEL_FROM=+983000505            ← خط خدماتی شما
    |   IPPANEL_PATTERN_OTP=abcd1234       ← «کد ورود شما %code% است»
    |   IPPANEL_PATTERN_WELCOME=...        ← خوش‌آمد بعد از ثبت‌نام
    |   IPPANEL_PATTERN_INVOICE=...        ← صدور فاکتور
    |   IPPANEL_PATTERN_PAID=...           ← تأیید پرداخت
    |   IPPANEL_PATTERN_SERVICE_READY=...  ← تحویل سرویس
    |   IPPANEL_PATTERN_EXPIRING=...       ← هشدار انقضا
    |   IPPANEL_PATTERN_TICKET_REPLY=...   ← پاسخ پشتیبانی
    */
    'sms' => [
        'driver'      => env('SMS_DRIVER', 'log'),
        'log_channel' => env('SMS_LOG_CHANNEL', 'stack'),

        /*
        | شماره‌های آزمایشی — با کاما جدا. برای این شماره‌ها پیامکی فرستاده
        | نمی‌شود و کد روی صفحه نشان داده می‌شود.
        |
        | عمداً فهرست شماره است نه یک کلید بولین: اگر بولین روی تولید جا
        | بماند، کد ورودِ هر کاربری روی صفحه می‌افتد. با فهرست، بدترین حالت
        | این است که چند شمارهٔ خودمان بی‌اثر بمانند.
        |
        |   OTP_TEST_NUMBERS=09121234567,09339876543
        */
        'test_numbers' => env('OTP_TEST_NUMBERS', ''),

        /*
        | رابط سرور ایران.
        |
        | آی‌پی‌پنل به آی‌پی خارج از ایران سرویس نمی‌دهد و سرور اصلی ما در
        | آلمان است. با پر کردن این دو، درخواست پیامک به‌جای مسیر مستقیم —
        | که همیشه ۵۰۲ می‌گیرد — از سرور ایران رد می‌شود.
        |
        |   SMS_RELAY_URL=https://servernet.ir/sms-relay.php
        |   SMS_RELAY_SECRET=«همان رشته‌ای که در sms-relay-secret.php گذاشتید»
        */
        'relay_url'    => env('SMS_RELAY_URL'),
        'relay_secret' => env('SMS_RELAY_SECRET'),


        'ippanel' => [
            // IPPANEL_KEY نامی است که در .env استفاده شده؛ IPPANEL_TOKEN هم
            // پذیرفته می‌شود تا اگر جایی نام دیگری گذاشته شد از کار نیفتد.
            'token' => env('IPPANEL_KEY', env('IPPANEL_TOKEN')),
            'from'  => env('IPPANEL_FROM'),

            // نام متغیر پیش‌فرض داخل الگوها. اگر در پنل نام دیگری گذاشتید،
            // اینجا عوضش کنید — یا برای هر الگو جداگانه در patterns.
            'variable' => env('IPPANEL_PATTERN_VARIABLE', 'code'),

            'patterns' => [
                'otp'           => env('IPPANEL_PATTERN_OTP'),
                'welcome'       => env('IPPANEL_PATTERN_WELCOME'),
                'invoice'       => env('IPPANEL_PATTERN_INVOICE'),
                'paid'          => env('IPPANEL_PATTERN_PAID'),
                'service_ready' => env('IPPANEL_PATTERN_SERVICE_READY'),
                'expiring'      => env('IPPANEL_PATTERN_EXPIRING'),
                'ticket_reply'  => env('IPPANEL_PATTERN_TICKET_REPLY'),

                // کدِ حذفِ سرور. ⚠️ درایورِ فعال `n8n_relay` است و کدِ این
                // الگو در `relay/n8n/verify-and-map-template.js` نشسته — تنها
                // منبعش. این خط فقط برای مسیرِ **مستقیمِ** آی‌پی‌پنل است؛ اگر
                // خالی بماند، آن مسیر به الگوی عمومیِ `otp` برمی‌گردد.
                'otp_service_delete' => env('IPPANEL_PATTERN_OTP_SERVICE_DELETE'),
            ],
        ],

        /*
        |------------------------------------------------------------------
        | رلهٔ پیامک از راهِ بله
        |------------------------------------------------------------------
        |
        | مسیر: این پروژه → رباتِ فرستنده → گروهِ خصوصی → رباتِ گیرنده
        |        → وب‌هوکِ n8n → آی‌پی‌پنل
        |
        | ⚠️ کلیدِ آی‌پی‌پنل و کدهای الگو **این‌جا نیستند و نباید باشند**؛
        |    سمتِ n8n نگهشان می‌دارد. این‌جا فقط نامِ منطقی (`otp`, `paid`, …)
        |    فرستاده می‌شود.
        |
        | ⚠️ `SMS_DRIVER=bale_relay` تا این مسیر فعال شود.
        */
        /*
        | ⚠️ نامِ متغیرها **همان‌هایی است که از قبل در .env سرور بود**
        | (`BALE_OTP_*`)، نه نام‌های تازه. دلیلش یک درسِ ثبت‌شدهٔ همین پروژه
        | است: کارفرما یک بار `SESSION_DOMAIN` را در .env گذاشت و اثر نکرد،
        | چون phpDotenv **اولین** مقدارِ هر کلید را نگه می‌دارد و خطِ قدیمی
        | بالاتر بود. هر نامِ موازیِ تازه، همان تله را از درِ دیگر برمی‌گرداند:
        | مقدار در یک کلید است و کد کلیدِ دیگری را می‌خواند، و نتیجه‌اش پیامکی
        | است که بی‌صدا نمی‌رود.
        |
        | نام‌های `BALE_SMS_RELAY_*` به‌عنوان پشتیبان می‌مانند تا اگر روزی
        | نصبِ تازه‌ای با آن نام‌ها ساخته شد هم کار کند.
        */
        'bale_relay' => [
            'bot_token' => env('BALE_OTP_SENDER_BOT_TOKEN', env('BALE_SMS_RELAY_BOT_TOKEN')),
            'chat_id'   => env('BALE_OTP_RELAY_CHAT_ID', env('BALE_SMS_RELAY_CHAT_ID')),
            'secret'    => env('BALE_OTP_RELAY_SECRET', env('BALE_SMS_RELAY_SECRET')),
            'base'      => env('BALE_BASE', 'https://tapi.bale.ai'),
        ],

        /*
        |------------------------------------------------------------------
        | رلهٔ پیامک — مستقیم به n8n  ✅ مسیرِ فعال
        |------------------------------------------------------------------
        |
        | 🔴 چرا جایگزینِ `bale_relay` شد: بله (مثلِ تلگرام) پیامِ یک ربات را
        |    به رباتِ دیگر تحویل نمی‌دهد، پس حلقهٔ «رباتِ گیرنده» هرگز بسته
        |    نمی‌شد و **هیچ پیامکی نمی‌رفت** — بی‌هیچ خطایی، چون از دیدِ ما
        |    پیام با موفقیت در گروه نوشته شده بود.
        |
        | ⚠️ `SMS_RELAY_SECRET_N8N` باید **دقیقاً** برابرِ `relaySecret` در گرهٔ
        |    `Relay Config` ورک‌فلوی n8n باشد. ناهماهنگی یعنی `bad_signature`:
        |    n8n کدِ ۲۰۰ می‌دهد ولی پیامک نمی‌رود. درایور همین را می‌گیرد و در
        |    `/system/sms-status → last_error` می‌گذارد.
        |
        | ⚠️ پیش‌فرضِ راز عمداً همان رازِ بله است تا نصبِ فعلی با یک تغییرِ
        |    `SMS_DRIVER` کار کند؛ اگر روزی جدا شد، کلیدِ خودش را بگذار.
        */
        'n8n_relay' => [
            'url'    => env('SMS_RELAY_N8N_URL', 'https://flow.servernet.cloud/webhook/servernet-sms-relay'),
            'secret' => env('SMS_RELAY_N8N_SECRET', env('BALE_OTP_RELAY_SECRET', env('BALE_SMS_RELAY_SECRET'))),
        ],

        // جایگزین — اگر روزی از آی‌پی‌پنل رفتیم
        'kavenegar' => [
            'key'      => env('KAVENEGAR_API_KEY'),
            'template' => env('KAVENEGAR_OTP_TEMPLATE'),
            'sender'   => env('KAVENEGAR_SENDER'),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | بله — پیام‌رسان (کانال دوم، موازی پیامک)
    |----------------------------------------------------------------------
    | هر پیامکی که می‌رود، اگر chat_id بلهٔ کاربر را داشته باشیم، هم‌زمان در
    | بله هم فرستاده می‌شود. اول از سرور آلمان مستقیم؛ اگر نشد، از سرور ایران.
    |
    | ربات فقط به کسی می‌تواند پیام دهد که اول ربات را استارت کرده و شماره‌اش
    | را share کرده باشد — پس chat_id از وب‌هوک به دست می‌آید، نه از شماره.
    |
    | .env:
    |   BALE_BOT_TOKEN=...        ← توکن ربات از @botfather بله
    |   BALE_BOT_USERNAME=...     ← نام کاربری ربات (برای لینک t.me مانند)
    */
    /*
    |--------------------------------------------------------------------------
    | سفیرِ بله — پیام به شمارهٔ موبایل، بی‌نیاز به ورودِ کاربر به ربات
    |--------------------------------------------------------------------------
    |
    | 🔴 فقط پیام‌های **سمتِ مشتری**. اعلانِ مدیر و گروهِ داخلی از `bale.token`
    |    می‌روند: آن‌ها chat_idِ پایدار دارند، هزینهٔ سفیر ندارند، و قاطی‌کردنشان
    |    یعنی یک خطای اعتبار در سفیر، هشدارهای داخلی را هم می‌خواباند.
    |
    | ⚠️ `bot_id` عددِ شناسهٔ ربات است، نه توکن.
    */
    'bale_safir' => [
        'key'    => env('BALE_SAFIR_KEY'),
        'bot_id' => env('BALE_SAFIR_BOT_ID'),
        'base'   => env('BALE_SAFIR_BASE', 'https://safir.bale.ai'),
    ],

    'bale' => [
        'token'    => env('BALE_BOT_TOKEN'),
        'username' => env('BALE_BOT_USERNAME'),
        'base'     => env('BALE_BASE_URL', 'https://tapi.bale.ai'),

        // توکن پرداخت کیف پول — جدا از توکن ربات، از @botfather بخش پرداخت.
        // نام env همان است که کارفرما گذاشت: BALE_BOT_WALLET
        // برای تست: WALLET-TEST-1111111111111111
        'wallet' => env('BALE_BOT_WALLET', env('BALE_PROVIDER_TOKEN')),
    ],

    /*
    |----------------------------------------------------------------------
    | زرین‌پال — درگاه پرداخت ریالی
    |----------------------------------------------------------------------
    | «merchant_id» یک UUID ۳۶ نویسه‌ای است که زرین‌پال می‌دهد.
    |
    | ⚠ مبلغ در API زرین‌پال بر حسب **ریال** است، ولی همهٔ قیمت‌های ما تومان
    | ذخیره می‌شوند. تبدیل فقط در یک جا (ZarinPalGateway) انجام می‌شود تا
    | هیچ‌وقت ضریب ۱۰ جا نیفتد یا دوبار اعمال نشود.
    |
    | .env:
    |   ZARINPAL_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
    |   ZARINPAL_SANDBOX=false
    */
    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_KEY', env('ZARINPAL_MERCHANT_ID')),
        'sandbox'     => (bool) env('ZARINPAL_SANDBOX', false),
    ],

    /*
    |----------------------------------------------------------------------
    | OpenProvider — رجیستری دامنه (ریسلر)
    |----------------------------------------------------------------------
    | نامِ ورود، نه RID. API فقط از IPهای allowlist‌شده جواب می‌دهد؛ IP خروجیِ
    | سرور را در پنل اوپن‌پروایدر ثبت کنید وگرنه کد ۱۹۶ (رد) می‌گیرید.
    |   OPENPROVIDER_USERNAME=ایمیل ورود
    |   OPENPROVIDER_PASSWORD=رمز
    |   DOMAIN_MARGIN_PCT=۲۵ (درصد سود پیش‌فرض)
    */
    'openprovider' => [
        'base_url'     => env('OPENPROVIDER_BASE_URL', 'https://api.openprovider.eu/v1beta'),
        'username'     => env('OPENPROVIDER_USERNAME'),
        'password'     => env('OPENPROVIDER_PASSWORD'),
        'suggest_tlds' => ['com', 'net', 'org', 'ir'],

        /*
        | 🔴 نام‌سرورِ پیش‌فرضِ شرکت. تا امروز این کلید **وجود نداشت** و
        | `Domain::defaultNameServers()` آرایهٔ خالی برمی‌گرداند — یعنی دامنه با
        | صفر نام‌سرور ثبت می‌شد. رجیسترار قبولش می‌کند ولی نتیجه‌اش دامنه‌ای
        | است که به هیچ‌جا اشاره نمی‌کند: مشتری پول داده، دامنه «فعال» است، و
        | سایتش بالا نمی‌آید.
        |
        | مدیر می‌تواند از تنظیمات (`domain_nameservers`) بازنویسی‌شان کند.
        */
        'nameservers' => array_values(array_filter(array_map('trim', explode(',',
            (string) env('DOMAIN_NAMESERVERS', 'ns1.servernet.cloud,ns2.servernet.cloud'))))),

        /*
        | ⚠️ پیش‌فرض **صفر** است، نه ۲۵.
        |
        | پیش‌فرضِ قبلی ۲۵٪ بود و چون فقط از `.env` می‌آمد، مدیر نه می‌دیدش نه
        | می‌توانست عوضش کند: روی یک دامنهٔ ۲ میلیون تومانی، نیم میلیون تومان
        | اضافه می‌شد بی‌آنکه کسی آن تصمیم را گرفته باشد. حالا درصدِ واقعی از
        | `/admin/settings` می‌آید و این فقط پشتیبانِ آخر است.
        */
        /*
        | مهلتِ ماندن در صفِ دستی، پیش از لغو و بازگشتِ خودکارِ وجه.
        |
        | 🔴 قاعدهٔ کارفرما: «هیچ کاری در صفِ ثبتِ دستی نمونه — یا کنسل بشه
        | پولش برگرده، یا ثبت بشه.» پس `manual` یک حالتِ **گذرا** است، نه
        | پایانی.
        |
        | ⚠️ چرا ۲۴ ساعت و نه کمتر: مانعِ رایج «مشخصاتِ مالک ناقص» است و
        | مشتری باید فرصتِ دیدنِ پیام و کامل‌کردنش را داشته باشد. مهلتِ کوتاه
        | یعنی خریدی که با یک شب خواب لغو می‌شود.
        |
        | ⚠️ و چرا بی‌نهایت نه: پولِ گرفته‌شده‌ای که نه دامنه شده نه برگشته،
        | بدترین حالتِ ممکن است — مشتری نه چیزی دارد نه پولش را.
        */
        'manual_grace_hours' => (int) env('DOMAIN_MANUAL_GRACE_HOURS', 24),

        'margin'       => [
            'default' => (float) env('DOMAIN_MARGIN_PCT', 0),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | ترون — دریافت رمزارز (فقط خواندنی)
    |----------------------------------------------------------------------
    | ⚠ اینجا هرگز کلید خصوصی یا seed نمی‌نشیند. فقط xpub که با آن می‌شود
    | آدرس ساخت ولی نمی‌شود خرج کرد. برداشت وجه دستی و آفلاین انجام می‌شود.
    |
    | .env:
    |   TRONGRID_API_KEY=...
    |   TRON_XPUB=xpub...                  ← از کیف پول آفلاین شما
    |   TRON_USDT_CONTRACT=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t
    */
    'tron' => [
        'api_key'       => env('TRONGRID_API_KEY'),
        'base_url'      => env('TRONGRID_BASE_URL', 'https://api.trongrid.io'),
        'xpub'          => env('TRON_XPUB'),
        'usdt_contract' => env('TRON_USDT_CONTRACT', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'),
    ],

];
