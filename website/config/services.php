<?php

return [



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
    | پیامک — آی‌پی‌پنل (پیش‌فرض)
    |----------------------------------------------------------------------
    | برای فعال شدن، هر دو لازم‌اند: توکن و خط فرستنده. تا وقتی نباشند،
    | درایور «log» می‌نشیند — کد در لاگ نوشته می‌شود و جریان ثبت‌نام قابل تست
    | می‌ماند، ولی هیچ پیامک واقعی نمی‌رود و هیچ پولی خرج نمی‌شود.
    |
    | IPPANEL_OTP_PATTERN اختیاری ولی اکیداً توصیه‌شده است: پیام آزاد ممکن است
    | چند دقیقه در صف اپراتور بماند و کد سه‌دقیقه‌ای ما منقضی شود.
    |
    | .env:
    |   SMS_DRIVER=ippanel
    |   IPPANEL_TOKEN=...                 ← از پنل آی‌پی‌پنل، بخش کلید API
    |   IPPANEL_FROM=+983000505           ← خط خدماتی شما
    |   IPPANEL_OTP_PATTERN=abcd1234      ← کد الگوی «کد ورود»
    |   IPPANEL_OTP_VARIABLE=code         ← نام متغیر داخل همان الگو
    */
    'sms' => [
        'driver'      => env('SMS_DRIVER', 'log'),
        'log_channel' => env('SMS_LOG_CHANNEL', 'stack'),

        'ippanel' => [
            'token'        => env('IPPANEL_TOKEN'),
            'from'         => env('IPPANEL_FROM'),
            'otp_pattern'  => env('IPPANEL_OTP_PATTERN'),
            'otp_variable' => env('IPPANEL_OTP_VARIABLE', 'code'),
        ],

        // جایگزین — اگر روزی از آی‌پی‌پنل رفتیم
        'kavenegar' => [
            'key'      => env('KAVENEGAR_API_KEY'),
            'template' => env('KAVENEGAR_OTP_TEMPLATE'),
            'sender'   => env('KAVENEGAR_SENDER'),
        ],
    ],

];
