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
    | DeepSeek — درگاه سازگار با OpenAI. برای ترجمه‌ها استفاده می‌شود چون
    | به‌مراتب ارزان‌تر است و روی این سرور تحریم نیست.
    */
    'deepseek' => [
        'key'   => env('DEEPSEEK_API_KEY'),
        'base'  => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
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
    ],


    /*
    |----------------------------------------------------------------------
    | OpenProvider — اولین رسیلری دامنه
    |----------------------------------------------------------------------
    | اعتبارنامه فقط در .env سرور. margin درصد سود روی قیمت خرید است و
    | می‌تواند per-TLD تنظیم شود.
    */
    'openprovider' => [
        'base_url' => env('OPENPROVIDER_BASE', 'https://api.openprovider.eu/v1beta'),
        'username' => env('OPENPROVIDER_USERNAME'),
        'password' => env('OPENPROVIDER_PASSWORD'),
        'suggest_tlds' => ['com', 'net', 'org', 'info', 'shop', 'site'],
        'margin' => [
            'default' => (float) env('DOMAIN_MARGIN_PCT', 25),
        ],
    ],

];
