<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$live='/home/servernetcloud/servernet_app';
require $live.'/vendor/autoload.php';
$app=require $live.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::globalResponseMiddleware(function ($response) {
    $json=json_decode((string)$response->getBody(), true);
    echo json_encode(['bale_api_accepted'=>is_array($json)&&($json['ok']??null)===true],JSON_THROW_ON_ERROR).PHP_EOL;
    return $response;
});
app(App\Services\Notify\AdminNotifier::class)->baleEvent('✅ پایش فروش GPU فعال شد',
    ['پایش'=>'هر دقیقه؛ هشدار توقف و بازگشت فروش',
     'پیگیری'=>'قیمت معتبر، کارت و برنامهٔ قابل سفارش؛ یادآوری قطعی ادامه‌دار هر ۳۰ دقیقه'],
    'https://console.servernet.cloud/admin/cloud');
